<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin', 'accountant']);
requireModule('reports');

$db   = getDB();
$type = sanitize($_GET['type'] ?? '');

$allowed_types = ['cows', 'milk', 'finance', 'sales', 'breeding', 'workers'];
if (!in_array($type, $allowed_types, true)) {
    flashMessage('error', 'Invalid report type.');
    redirect('/modules/reports/index.php');
}

// Workers report: admin only
if ($type === 'workers' && !hasRole(['admin'])) {
    flashMessage('error', 'Insufficient permissions.');
    redirect('/modules/reports/index.php');
}

// ---- Helper: sanitise a date filter ----
$clean_date = static function (string $key): ?string {
    $v = trim($_GET[$key] ?? '');
    return ($v !== '' && strtotime($v)) ? $v : null;
};

$date_from = $clean_date('date_from');
$date_to   = $clean_date('date_to');

// ---- Helper: output CSV ----
$csv_row = static function (array $row): string {
    return implode(',', array_map(static function ($cell) {
        $cell = (string)($cell ?? '');
        if (str_contains($cell, ',') || str_contains($cell, '"') || str_contains($cell, "\n")) {
            return '"' . str_replace('"', '""', $cell) . '"';
        }
        return $cell;
    }, $row)) . "\r\n";
};

// ---- Build query + headers per type ----
switch ($type) {

    // ================================================================
    case 'cows':
        $status_filter = sanitize($_GET['status'] ?? '');
        $valid_statuses = ['active','pregnant','lactating','dry','sick','quarantine','ready_for_sale','sold','deceased'];

        $where  = ['1=1'];
        $params = [];
        if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
            $where[] = 'c.status = ?'; $params[] = $status_filter;
        }
        if ($date_from) { $where[] = 'DATE(c.created_at) >= ?'; $params[] = $date_from; }
        if ($date_to)   { $where[] = 'DATE(c.created_at) <= ?'; $params[] = $date_to; }

        $stmt = $db->prepare(
            "SELECT c.id, c.tag_number, c.breed,
                    c.birth_date,
                    TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) AS age_years,
                    c.status, c.health_status, c.is_pregnant, c.current_weight,
                    c.purchase_price, c.purchase_date, c.notes,
                    DATE(c.created_at) AS created_date
             FROM cows c
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.tag_number ASC"
        );
        $stmt->execute($params);

        $filename = 'cows_' . date('Y-m-d') . '.csv';
        $headers  = ['ID','Tag Number','Breed','Birth Date','Age (Years)','Status','Health Status',
                     'Pregnant','Weight (kg)','Purchase Price','Purchase Date','Notes','Created Date'];
        $map = static fn(array $r): array => [
            $r['id'], $r['tag_number'], $r['breed'], $r['birth_date'] ?? '',
            $r['age_years'] ?? '', $r['status'], $r['health_status'],
            $r['is_pregnant'] ? 'Yes' : 'No', $r['current_weight'] ?? '',
            $r['purchase_price'] ?? '', $r['purchase_date'] ?? '', $r['notes'] ?? '',
            $r['created_date'],
        ];
        break;

    // ================================================================
    case 'milk':
        $contam = $_GET['contamination'] ?? '';

        $where  = ['1=1'];
        $params = [];
        if ($date_from) { $where[] = 'DATE(mr.recorded_at) >= ?'; $params[] = $date_from; }
        if ($date_to)   { $where[] = 'DATE(mr.recorded_at) <= ?'; $params[] = $date_to; }
        if ($contam === '0') { $where[] = 'mr.contamination_flag = 0'; }
        if ($contam === '1') { $where[] = 'mr.contamination_flag = 1'; }

        $stmt = $db->prepare(
            "SELECT mr.id,
                    DATE(mr.recorded_at) AS date,
                    TIME(mr.recorded_at) AS time,
                    c.tag_number, c.breed,
                    mr.liters, mr.fat_percentage, mr.contamination_flag,
                    u.name AS recorded_by
             FROM milk_records mr
             JOIN cows  c ON c.id = mr.cow_id
             JOIN users u ON u.id = mr.recorded_by
             WHERE " . implode(' AND ', $where) . "
             ORDER BY mr.recorded_at DESC"
        );
        $stmt->execute($params);

        $filename = 'milk_production_' . date('Y-m-d') . '.csv';
        $headers  = ['ID','Date','Time','Cow Tag','Breed','Liters','Fat %','Contaminated','Recorded By'];
        $map = static fn(array $r): array => [
            $r['id'], $r['date'], $r['time'], $r['tag_number'], $r['breed'],
            $r['liters'], $r['fat_percentage'] ?? '', $r['contamination_flag'] ? 'Yes' : 'No',
            $r['recorded_by'],
        ];
        break;

    // ================================================================
    case 'finance':
        $txn_type = sanitize($_GET['txn_type'] ?? '');

        $where  = ['1=1'];
        $params = [];
        if ($date_from) { $where[] = 'ft.transaction_date >= ?'; $params[] = $date_from; }
        if ($date_to)   { $where[] = 'ft.transaction_date <= ?'; $params[] = $date_to; }
        if (in_array($txn_type, ['income','expense'], true)) {
            $where[] = 'ft.type = ?'; $params[] = $txn_type;
        }

        $stmt = $db->prepare(
            "SELECT ft.id, ft.transaction_date, ft.type, ft.category, ft.amount,
                    ft.related_module, ft.notes,
                    u.name AS recorded_by
             FROM finance_transactions ft
             JOIN users u ON u.id = ft.recorded_by
             WHERE " . implode(' AND ', $where) . "
             ORDER BY ft.transaction_date DESC, ft.created_at DESC"
        );
        $stmt->execute($params);

        $filename = 'finance_' . date('Y-m-d') . '.csv';
        $headers  = ['ID','Date','Type','Category','Amount','Source Module','Notes','Recorded By'];
        $map = static fn(array $r): array => [
            $r['id'], $r['transaction_date'], $r['type'], $r['category'],
            $r['amount'], $r['related_module'] ?? '', $r['notes'] ?? '', $r['recorded_by'],
        ];
        break;

    // ================================================================
    case 'sales':
        $sale_type = sanitize($_GET['sale_type'] ?? 'both');

        $rows = [];

        if ($sale_type !== 'meat') {
            $where  = ['1=1'];
            $params = [];
            if ($date_from) { $where[] = 'cs.sale_date >= ?'; $params[] = $date_from; }
            if ($date_to)   { $where[] = 'cs.sale_date <= ?'; $params[] = $date_to; }

            $s = $db->prepare(
                "SELECT cs.id, 'Cow Sale' AS sale_type, cs.sale_date,
                        c.tag_number, c.breed,
                        cs.buyer_name, cs.sale_price, c.purchase_price, cs.profit_loss,
                        NULL AS kg_sold, NULL AS price_per_kg, NULL AS event_type,
                        cs.notes
                 FROM cow_sales cs JOIN cows c ON c.id = cs.cow_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY cs.sale_date DESC"
            );
            $s->execute($params);
            $rows = array_merge($rows, $s->fetchAll());
        }

        if ($sale_type !== 'cow') {
            $where  = ['1=1'];
            $params = [];
            if ($date_from) { $where[] = 'ms.sale_date >= ?'; $params[] = $date_from; }
            if ($date_to)   { $where[] = 'ms.sale_date <= ?'; $params[] = $date_to; }

            $s = $db->prepare(
                "SELECT ms.id, 'Meat Sale' AS sale_type, ms.sale_date,
                        c.tag_number, c.breed,
                        NULL AS buyer_name, ms.total_revenue AS sale_price,
                        NULL AS purchase_price, NULL AS profit_loss,
                        ms.kg_sold, ms.price_per_kg, ms.event_type,
                        ms.notes
                 FROM meat_sales ms JOIN cows c ON c.id = ms.cow_id
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY ms.sale_date DESC"
            );
            $s->execute($params);
            $rows = array_merge($rows, $s->fetchAll());
        }

        // Sort combined result by date desc
        usort($rows, static fn($a, $b) => strcmp($b['sale_date'], $a['sale_date']));

        $filename = 'sales_' . date('Y-m-d') . '.csv';
        $headers  = ['ID','Sale Type','Date','Cow Tag','Breed','Buyer','Sale Price',
                     'Purchase Price','Profit/Loss','Kg Sold','Price/Kg','Event Type','Notes'];
        $map = static fn(array $r): array => [
            $r['id'], $r['sale_type'], $r['sale_date'], $r['tag_number'], $r['breed'],
            $r['buyer_name'] ?? '', $r['sale_price'] ?? '', $r['purchase_price'] ?? '',
            $r['profit_loss'] ?? '', $r['kg_sold'] ?? '', $r['price_per_kg'] ?? '',
            $r['event_type'] ?? '', $r['notes'] ?? '',
        ];

        // For sales, $stmt is not used — $rows is pre-fetched
        $stmt = null;
        break;

    // ================================================================
    case 'breeding':
        $br_status = sanitize($_GET['br_status'] ?? '');
        $valid_br_statuses = ['heat','inseminated','pregnant','calved','failed'];

        $where  = ['1=1'];
        $params = [];
        if ($br_status !== '' && in_array($br_status, $valid_br_statuses, true)) {
            $where[] = 'br.status = ?'; $params[] = $br_status;
        }
        if ($date_from) { $where[] = 'DATE(br.created_at) >= ?'; $params[] = $date_from; }
        if ($date_to)   { $where[] = 'DATE(br.created_at) <= ?'; $params[] = $date_to; }

        $stmt = $db->prepare(
            "SELECT br.id, c.tag_number, c.breed,
                    br.status, br.heat_cycle_date, br.insemination_date, br.breeding_date,
                    br.expected_calving_date, br.actual_calving_date,
                    DATEDIFF(br.expected_calving_date, CURDATE()) AS days_until_calving,
                    (SELECT COUNT(*) FROM calf_records cr WHERE cr.breeding_record_id = br.id) AS calf_count,
                    u.name AS recorded_by,
                    br.notes, DATE(br.created_at) AS created_date
             FROM breeding_records br
             JOIN cows  c ON c.id = br.cow_id
             JOIN users u ON u.id = br.recorded_by
             WHERE " . implode(' AND ', $where) . "
             ORDER BY br.created_at DESC"
        );
        $stmt->execute($params);

        $filename = 'breeding_records_' . date('Y-m-d') . '.csv';
        $headers  = ['ID','Cow Tag','Breed','Status','Heat Date','Insemination Date','Breeding Date',
                     'Expected Calving','Actual Calving','Days Until Calving','Calves','Recorded By',
                     'Notes','Created Date'];
        $map = static fn(array $r): array => [
            $r['id'], $r['tag_number'], $r['breed'], $r['status'],
            $r['heat_cycle_date'] ?? '', $r['insemination_date'] ?? '', $r['breeding_date'] ?? '',
            $r['expected_calving_date'] ?? '', $r['actual_calving_date'] ?? '',
            $r['days_until_calving'] ?? '', $r['calf_count'],
            $r['recorded_by'], $r['notes'] ?? '', $r['created_date'],
        ];
        break;

    // ================================================================
    case 'workers':
        $w_status = sanitize($_GET['worker_status'] ?? '');
        $valid_w  = ['active','inactive','terminated'];

        $where  = ['1=1'];
        $params = [];
        if ($w_status !== '' && in_array($w_status, $valid_w, true)) {
            $where[] = 'w.status = ?'; $params[] = $w_status;
        }

        $stmt = $db->prepare(
            "SELECT w.id, u.name, u.email, u.role,
                    w.salary, w.hire_date, w.termination_date, w.status,
                    (SELECT COUNT(*) FROM worker_tasks wt WHERE wt.worker_id = w.id)                               AS total_tasks,
                    (SELECT COUNT(*) FROM worker_tasks wt WHERE wt.worker_id = w.id AND wt.status='completed')    AS completed_tasks,
                    (SELECT COUNT(*) FROM worker_tasks wt WHERE wt.worker_id = w.id AND wt.status='overdue')      AS overdue_tasks
             FROM workers w
             JOIN users u ON u.id = w.user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY w.status, u.name ASC"
        );
        $stmt->execute($params);

        $filename = 'workers_' . date('Y-m-d') . '.csv';
        $headers  = ['ID','Name','Email','Role','Salary','Hire Date','Termination Date','Status',
                     'Total Tasks','Completed Tasks','Overdue Tasks'];
        $map = static fn(array $r): array => [
            $r['id'], $r['name'], $r['email'], $r['role'],
            $r['salary'], $r['hire_date'], $r['termination_date'] ?? '', $r['status'],
            $r['total_tasks'], $r['completed_tasks'], $r['overdue_tasks'],
        ];
        break;

    default:
        flashMessage('error', 'Invalid report type.');
        redirect('/modules/reports/index.php');
}

// ---- Send CSV ----
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, $headers);

if ($type === 'sales' && isset($rows)) {
    foreach ($rows as $row) {
        fputcsv($out, $map($row));
    }
} else {
    while ($row = $stmt->fetch()) {
        fputcsv($out, $map($row));
    }
}

fclose($out);
exit;
