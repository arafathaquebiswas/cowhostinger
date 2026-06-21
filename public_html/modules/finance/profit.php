<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'accountant']);
requireFarmScope();
requireModule('finance');

if (!canAccess('finance.view')) requireAccess('finance.view');

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── POST: Add other income / misc expenses ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/finance/profit.php');
    }
    requireNotBlocked();

    $action = $_POST['action'] ?? '';

    if ($action === 'add_income') {
        $cat    = sanitize($_POST['category']    ?? 'Other');
        $amount = (float)($_POST['amount']        ?? 0);
        $date   = trim($_POST['income_date']      ?? '');
        $notes  = sanitize($_POST['notes']        ?? '');

        if ($amount <= 0)         flashMessage('error', 'Amount must be greater than 0.');
        elseif (!strtotime($date)) flashMessage('error', 'Invalid date.');
        else {
            $db->prepare("INSERT INTO other_income (farm_id,category,amount,income_date,notes,recorded_by) VALUES (?,?,?,?,?,?)")
               ->execute([fid(), $cat ?: 'Other', $amount, $date, $notes ?: null, $uid]);
            auditLog($uid, 'ADD_OTHER_INCOME', 'other_income', (int)$db->lastInsertId(), null, compact('amount','cat'));
            flashMessage('success', 'Income recorded.');
        }

    } elseif ($action === 'add_expense') {
        $cat    = sanitize($_POST['category']    ?? 'Miscellaneous');
        $amount = (float)($_POST['amount']        ?? 0);
        $date   = trim($_POST['expense_date']     ?? '');
        $notes  = sanitize($_POST['notes']        ?? '');

        if ($amount <= 0)         flashMessage('error', 'Amount must be greater than 0.');
        elseif (!strtotime($date)) flashMessage('error', 'Invalid date.');
        else {
            $db->prepare("INSERT INTO misc_expenses (farm_id,category,amount,expense_date,notes,recorded_by) VALUES (?,?,?,?,?,?)")
               ->execute([fid(), $cat ?: 'Miscellaneous', $amount, $date, $notes ?: null, $uid]);
            auditLog($uid, 'ADD_MISC_EXPENSE', 'misc_expenses', (int)$db->lastInsertId(), null, compact('amount','cat'));
            flashMessage('success', 'Misc expense recorded.');
        }

    } elseif ($action === 'delete_income' && hasRole(['admin'])) {
        $row_id = (int)($_POST['row_id'] ?? 0);
        $r = $db->prepare("SELECT * FROM other_income WHERE id=? AND " . farmFilter());
        $r->execute([$row_id]);
        if ($row = $r->fetch()) {
            $db->prepare("DELETE FROM other_income WHERE id=? AND " . farmFilter())->execute([$row_id]);
            auditLog($uid, 'DELETE_OTHER_INCOME', 'other_income', $row_id, $row, null);
            flashMessage('success', 'Record deleted.');
        }

    } elseif ($action === 'delete_expense' && hasRole(['admin'])) {
        $row_id = (int)($_POST['row_id'] ?? 0);
        $r = $db->prepare("SELECT * FROM misc_expenses WHERE id=? AND " . farmFilter());
        $r->execute([$row_id]);
        if ($row = $r->fetch()) {
            $db->prepare("DELETE FROM misc_expenses WHERE id=? AND " . farmFilter())->execute([$row_id]);
            auditLog($uid, 'DELETE_MISC_EXPENSE', 'misc_expenses', $row_id, $row, null);
            flashMessage('success', 'Record deleted.');
        }
    }

    redirect('/modules/finance/profit.php?' . http_build_query(array_filter([
        'range'     => $_POST['f_range']     ?? '',
        'date_from' => $_POST['f_date_from'] ?? '',
        'date_to'   => $_POST['f_date_to']   ?? '',
    ])));
}

// ── Date range ────────────────────────────────────────────────────────────────
$range = in_array($_GET['range'] ?? '', ['month','last_month','year','custom'], true)
    ? $_GET['range'] : 'month';
$today = date('Y-m-d');

switch ($range) {
    case 'last_month':
        $from = date('Y-m-01', strtotime('first day of last month'));
        $to   = date('Y-m-t',  strtotime('last day of last month'));
        $range_label = 'Last Month (' . date('M Y', strtotime('last month')) . ')';
        break;
    case 'year':
        $from = date('Y-01-01');
        $to   = $today;
        $range_label = 'This Year (' . date('Y') . ')';
        break;
    case 'custom':
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : $today;
        if ($from > $to) [$from, $to] = [$to, $from];
        $range_label = formatDate($from) . ' – ' . formatDate($to);
        break;
    default:
        $from = date('Y-m-01');
        $to   = $today;
        $range_label = 'This Month (' . date('F Y') . ')';
}

// ── Revenue ───────────────────────────────────────────────────────────────────
$q = $db->prepare("SELECT COALESCE(SUM(milk_value),0) FROM milk_records WHERE " . farmFilter() . " AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ?");
$q->execute([$from, $to]);
$rev_milk = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(sale_price),0) FROM cow_sales WHERE " . farmFilter() . " AND sale_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$rev_cow_sales = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(total_revenue),0) FROM meat_sales WHERE " . farmFilter() . " AND sale_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$rev_meat = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM other_income WHERE " . farmFilter() . " AND income_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$rev_other = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE " . farmFilter() . " AND type='income' AND transaction_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$rev_manual = (float)$q->fetchColumn();

$total_revenue = $rev_milk + $rev_cow_sales + $rev_meat + $rev_other + $rev_manual;

// ── Expenses ──────────────────────────────────────────────────────────────────
$q = $db->prepare("SELECT COALESCE(SUM(total_cost),0) FROM feed_logs WHERE " . farmFilter() . " AND feed_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$exp_feed = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(total_cost),0) FROM medicine_logs WHERE " . farmFilter() . " AND administered_at BETWEEN ? AND ?");
$q->execute([$from, $to]);
$exp_medicine = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(cost),0) FROM treatments WHERE " . farmFilter() . " AND treatment_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$exp_treatment = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM vet_visits WHERE " . farmFilter() . " AND visit_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$exp_vet = (float)$q->fetchColumn();

// Worker salary prorated by days active within the date range
$q = $db->prepare(
    "SELECT COALESCE(SUM(
        GREATEST(0, DATEDIFF(
            LEAST(IFNULL(termination_date, ?), ?),
            GREATEST(hire_date, ?)
        ) + 1) * salary / 30.4375
    ), 0)
    FROM workers
    WHERE " . farmFilter() . "
    AND hire_date <= ?
    AND (termination_date IS NULL OR termination_date >= ?)"
);
$q->execute([$to, $to, $from, $to, $from]);
$exp_workers = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(cost),0) FROM maintenance_logs WHERE " . farmFilter() . " AND completed_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$exp_maintenance = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM misc_expenses WHERE " . farmFilter() . " AND expense_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$exp_misc = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE " . farmFilter() . " AND type='expense' AND transaction_date BETWEEN ? AND ?");
$q->execute([$from, $to]);
$exp_manual = (float)$q->fetchColumn();

$exp_health      = $exp_medicine + $exp_treatment + $exp_vet;
$total_expenses  = $exp_feed + $exp_health + $exp_workers + $exp_maintenance + $exp_misc + $exp_manual;
$gross_profit    = $total_revenue - $exp_feed - $exp_health;
$net_profit      = $total_revenue - $total_expenses;
$profit_margin   = $total_revenue > 0 ? ($net_profit / $total_revenue * 100) : 0;

// ── 12-Month Trend ────────────────────────────────────────────────────────────
$trend = [];
for ($i = 11; $i >= 0; $i--) {
    $ms = date('Y-m-01', strtotime("-{$i} months"));
    $me = date('Y-m-t',  strtotime("-{$i} months"));
    $ml = date('M Y',    strtotime("-{$i} months"));

    $qr = $db->prepare(
        "SELECT
            (SELECT COALESCE(SUM(milk_value),0)     FROM milk_records       WHERE " . farmFilter()       . " AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(sale_price),0)     FROM cow_sales          WHERE " . farmFilter()       . " AND sale_date BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(total_revenue),0)  FROM meat_sales         WHERE " . farmFilter()       . " AND sale_date BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(amount),0)         FROM other_income       WHERE " . farmFilter()       . " AND income_date BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(amount),0)         FROM finance_transactions WHERE " . farmFilter()     . " AND type='income' AND transaction_date BETWEEN ? AND ?)"
    );
    $qr->execute([$ms,$me, $ms,$me, $ms,$me, $ms,$me, $ms,$me]);
    $m_rev = (float)$qr->fetchColumn();

    $qe = $db->prepare(
        "SELECT
            (SELECT COALESCE(SUM(total_cost),0) FROM feed_logs       WHERE " . farmFilter() . " AND feed_date BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(total_cost),0) FROM medicine_logs   WHERE " . farmFilter() . " AND administered_at BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(cost),0)       FROM treatments      WHERE " . farmFilter() . " AND treatment_date BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(amount),0)     FROM vet_visits      WHERE " . farmFilter() . " AND visit_date BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(GREATEST(0, DATEDIFF(LEAST(IFNULL(termination_date,?),?),GREATEST(hire_date,?))+1)*salary/30.4375),0)
                FROM workers WHERE " . farmFilter() . " AND hire_date<=? AND (termination_date IS NULL OR termination_date>=?)) +
            (SELECT COALESCE(SUM(cost),0)       FROM maintenance_logs WHERE " . farmFilter() . " AND completed_date BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(amount),0)     FROM misc_expenses   WHERE " . farmFilter() . " AND expense_date BETWEEN ? AND ?) +
            (SELECT COALESCE(SUM(amount),0)     FROM finance_transactions WHERE " . farmFilter() . " AND type='expense' AND transaction_date BETWEEN ? AND ?)"
    );
    $qe->execute([
        $ms,$me,
        $ms,$me,
        $ms,$me,
        $ms,$me,
        $me,$me,$ms,$me,$ms,
        $ms,$me,
        $ms,$me,
        $ms,$me,
    ]);
    $m_exp = (float)$qe->fetchColumn();

    $trend[] = ['label' => $ml, 'rev' => $m_rev, 'exp' => $m_exp, 'profit' => $m_rev - $m_exp];
}

// ── Per-cow profitability ─────────────────────────────────────────────────────
$cow_stmt = $db->prepare(
    "SELECT
        c.id, c.tag_number, c.name AS cow_name,
        COALESCE(SUM(mr.milk_value), 0) AS milk_rev,
        COALESCE((SELECT SUM(fl.total_cost) FROM feed_logs fl
            WHERE fl.cow_id = c.id AND " . farmFilter('fl') . " AND fl.feed_date BETWEEN ? AND ?), 0) AS feed_cost,
        COALESCE((SELECT SUM(ml.total_cost) FROM medicine_logs ml
            WHERE ml.cow_id = c.id AND " . farmFilter('ml') . " AND ml.administered_at BETWEEN ? AND ?), 0) AS med_cost,
        COALESCE((SELECT SUM(t.cost) FROM treatments t
            WHERE t.cow_id = c.id AND " . farmFilter('t') . " AND t.treatment_date BETWEEN ? AND ?), 0) AS treat_cost,
        COALESCE((SELECT SUM(vv.amount) FROM vet_visits vv
            WHERE vv.cow_id = c.id AND " . farmFilter('vv') . " AND vv.visit_date BETWEEN ? AND ?), 0) AS vet_cost
    FROM cows c
    LEFT JOIN milk_records mr
        ON mr.cow_id = c.id AND " . farmFilter('mr') . " AND mr.contamination_flag=0 AND DATE(mr.recorded_at) BETWEEN ? AND ?
    WHERE " . farmFilter('c') . "
    GROUP BY c.id, c.tag_number, c.name
    HAVING milk_rev>0 OR feed_cost>0 OR med_cost>0 OR treat_cost>0 OR vet_cost>0
    ORDER BY (milk_rev - feed_cost - med_cost - treat_cost - vet_cost) DESC
    LIMIT 30"
);
$cow_stmt->execute([$from,$to, $from,$to, $from,$to, $from,$to, $from,$to]);
$cow_rows = $cow_stmt->fetchAll();

// ── Recent entries for sidebar lists ─────────────────────────────────────────
$oi_rows = $db->prepare("SELECT * FROM other_income  WHERE " . farmFilter() . " ORDER BY income_date  DESC LIMIT 10");
$oi_rows->execute();
$oi_rows = $oi_rows->fetchAll();

$me_rows = $db->prepare("SELECT * FROM misc_expenses WHERE " . farmFilter() . " ORDER BY expense_date DESC LIMIT 10");
$me_rows->execute();
$me_rows = $me_rows->fetchAll();

// ── Chart data (JSON for JS) ──────────────────────────────────────────────────
$chart_labels  = json_encode(array_column($trend, 'label'));
$chart_rev     = json_encode(array_map(fn($r) => round($r['rev'],   2), $trend));
$chart_exp     = json_encode(array_map(fn($r) => round($r['exp'],   2), $trend));
$chart_profit  = json_encode(array_map(fn($r) => round($r['profit'],2), $trend));

// ── Smart insights ────────────────────────────────────────────────────────────
$best_cow = null;
$worst_cow = null;
if (!empty($cow_rows)) {
    $cow_with_profit = array_map(function($r) {
        $r['net'] = $r['milk_rev'] - $r['feed_cost'] - $r['med_cost'] - $r['treat_cost'] - $r['vet_cost'];
        return $r;
    }, $cow_rows);
    usort($cow_with_profit, fn($a,$b) => $b['net'] <=> $a['net']);
    $best_cow  = $cow_with_profit[0];
    $worst_cow = end($cow_with_profit);
}

$page_title = 'Financial Intelligence';
$active_nav = 'profit_engine';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

function fmt(float $v): string {
    return '৳' . number_format(abs($v), 2);
}
function pct_bar(float $part, float $total): string {
    if ($total <= 0) return '';
    $w = min(100, round(abs($part) / $total * 100));
    return '<div style="height:4px;border-radius:2px;background:var(--border);margin-top:3px"><div style="height:4px;border-radius:2px;background:currentColor;width:' . $w . '%"></div></div>';
}
?>

<div class="page-header">
    <div>
        <h2>Financial Intelligence</h2>
        <p class="text-muted text-sm"><?= e($range_label) ?></p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="?range=month"      class="btn btn-sm <?= $range==='month'      ? 'btn-primary' : 'btn-secondary' ?>">This Month</a>
        <a href="?range=last_month" class="btn btn-sm <?= $range==='last_month' ? 'btn-primary' : 'btn-secondary' ?>">Last Month</a>
        <a href="?range=year"       class="btn btn-sm <?= $range==='year'       ? 'btn-primary' : 'btn-secondary' ?>">This Year</a>
        <button class="btn btn-sm btn-secondary" onclick="document.getElementById('custom-range').style.display='block'">Custom</button>
    </div>
</div>

<!-- Custom date range form -->
<div id="custom-range" style="display:<?= $range==='custom'?'block':'none' ?>;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:1.25rem;max-width:480px">
    <form method="GET">
        <input type="hidden" name="range" value="custom">
        <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0;flex:1;min-width:140px">
                <label class="form-label">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:140px">
                <label class="form-label">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($to) ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </div>
    </form>
</div>

<!-- ── Smart Insights ──────────────────────────────────────────────────────── -->
<?php if ($best_cow || $net_profit != 0): ?>
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem">
    <?php if ($net_profit >= 0): ?>
    <div class="alert" style="flex:1;min-width:220px;margin:0;background:#f0fdf4;border-color:#86efac;color:#15803d;display:flex;align-items:center;gap:.75rem">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
        <div><strong>Profitable Period</strong><br><span class="text-sm">Net profit: <?= fmt($net_profit) ?> | Margin: <?= number_format($profit_margin, 1) ?>%</span></div>
    </div>
    <?php else: ?>
    <div class="alert alert-danger" style="flex:1;min-width:220px;margin:0;display:flex;align-items:center;gap:.75rem">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>
        <div><strong>Loss Period</strong><br><span class="text-sm">Net loss: <?= fmt($net_profit) ?> | Expenses exceed revenue by <?= number_format(abs($profit_margin), 1) ?>%</span></div>
    </div>
    <?php endif; ?>
    <?php if ($best_cow): ?>
    <div class="alert" style="flex:1;min-width:220px;margin:0;background:#eff6ff;border-color:#93c5fd;color:#1d4ed8;display:flex;align-items:center;gap:.75rem">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
        <div><strong>Top Cow: <?= e($best_cow['cow_name'] ?: $best_cow['tag_number']) ?></strong><br><span class="text-sm">Net contribution: <?= fmt($best_cow['net']) ?></span></div>
    </div>
    <?php endif; ?>
    <?php if ($worst_cow && $worst_cow['id'] !== ($best_cow['id'] ?? null)): ?>
    <div class="alert" style="flex:1;min-width:220px;margin:0;background:#fff7ed;border-color:#fdba74;color:#c2410c;display:flex;align-items:center;gap:.75rem">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><strong>High-Cost Cow: <?= e($worst_cow['cow_name'] ?: $worst_cow['tag_number']) ?></strong><br><span class="text-sm">Net contribution: <?= fmt($worst_cow['net']) ?></span></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Revenue + Expense cards ────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.25rem;margin-bottom:1.25rem">

    <!-- REVENUE -->
    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #16a34a">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <h3 style="margin:0;color:#15803d">Revenue</h3>
                <span style="font-size:1.4rem;font-weight:700;color:#15803d"><?= fmt($total_revenue) ?></span>
            </div>
        </div>
        <div class="card-body" style="padding:.75rem 1rem">
            <?php
            $rev_items = [
                ['Milk Revenue',    $rev_milk,      '#16a34a'],
                ['Cow Sales',       $rev_cow_sales, '#0284c7'],
                ['Meat Sales',      $rev_meat,      '#7c3aed'],
                ['Other Income',    $rev_other,     '#d97706'],
                ['Manual Entries',  $rev_manual,    '#6b7280'],
            ];
            foreach ($rev_items as [$label, $val, $color]):
                if ($val == 0 && $label !== 'Milk Revenue') continue;
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--border)">
                <span style="font-size:.85rem;color:var(--text-secondary)"><?= $label ?></span>
                <span style="font-weight:600;color:<?= $color ?>"><?= fmt($val) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- EXPENSES -->
    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #dc2626">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <h3 style="margin:0;color:#b91c1c">Expenses</h3>
                <span style="font-size:1.4rem;font-weight:700;color:#b91c1c"><?= fmt($total_expenses) ?></span>
            </div>
        </div>
        <div class="card-body" style="padding:.75rem 1rem">
            <?php
            $exp_items = [
                ['Feed',            $exp_feed,        '#d97706'],
                ['Medicine',        $exp_medicine,    '#7c3aed'],
                ['Treatments',      $exp_treatment,   '#0369a1'],
                ['Vet Visits',      $exp_vet,         '#0284c7'],
                ['Worker Salaries', $exp_workers,     '#16a34a'],
                ['Maintenance',     $exp_maintenance, '#6b7280'],
                ['Misc Expenses',   $exp_misc,        '#9ca3af'],
                ['Manual Entries',  $exp_manual,      '#6b7280'],
            ];
            foreach ($exp_items as [$label, $val, $color]):
                if ($val == 0) continue;
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--border)">
                <div style="flex:1">
                    <span style="font-size:.85rem;color:var(--text-secondary)"><?= $label ?></span>
                    <?php if ($total_expenses > 0): ?>
                    <div style="height:3px;border-radius:2px;background:var(--border);margin-top:2px">
                        <div style="height:3px;border-radius:2px;background:<?= $color ?>;width:<?= min(100,round($val/$total_expenses*100)) ?>%"></div>
                    </div>
                    <?php endif; ?>
                </div>
                <span style="font-weight:600;color:<?= $color ?>;margin-left:.75rem"><?= fmt($val) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($total_expenses == 0): ?>
            <p class="text-muted text-sm" style="text-align:center;padding:.75rem 0">No expenses recorded for this period.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Profit Summary ─────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">
    <?php
    $kpis = [
        ['Total Revenue',    $total_revenue,  '#15803d', '#f0fdf4'],
        ['Total Expenses',   $total_expenses, '#b91c1c', '#fef2f2'],
        ['Gross Profit',     $gross_profit,   $gross_profit>=0?'#0369a1':'#b91c1c', $gross_profit>=0?'#eff6ff':'#fef2f2'],
        ['Net Profit',       $net_profit,     $net_profit>=0?'#15803d':'#b91c1c',   $net_profit>=0?'#f0fdf4':'#fef2f2'],
    ];
    foreach ($kpis as [$label,$val,$color,$bg]):
    ?>
    <div class="card" style="border-top:3px solid <?= $color ?>;background:<?= $bg ?>">
        <div class="card-body" style="padding:.85rem 1rem">
            <div style="font-size:.75rem;color:var(--text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:.05em"><?= $label ?></div>
            <div style="font-size:1.35rem;font-weight:700;color:<?= $color ?>;margin-top:.25rem"><?= ($val < 0 ? '-' : '') . fmt($val) ?></div>
            <?php if ($label === 'Net Profit' && $total_revenue > 0): ?>
            <div style="font-size:.78rem;color:var(--text-secondary);margin-top:.2rem">
                Margin: <?= number_format($profit_margin, 1) ?>%
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="card" style="border-top:3px solid #7c3aed;background:#f5f3ff">
        <div class="card-body" style="padding:.85rem 1rem">
            <div style="font-size:.75rem;color:var(--text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:.05em">Health Cost</div>
            <div style="font-size:1.35rem;font-weight:700;color:#7c3aed;margin-top:.25rem"><?= fmt($exp_health) ?></div>
            <div style="font-size:.78rem;color:var(--text-secondary);margin-top:.2rem">Medicine + Vet</div>
        </div>
    </div>
</div>

<!-- ── 12-Month Trend ─────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <h3>12-Month Trend</h3>
    </div>
    <div style="padding:1rem 1rem 0">
        <canvas id="trendChart" height="80"></canvas>
    </div>
    <div style="overflow-x:auto;padding:.5rem 0">
        <table class="table" style="font-size:.82rem;min-width:650px">
            <thead>
                <tr>
                    <th>Month</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-right">Expenses</th>
                    <th class="text-right">Profit / Loss</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_reverse($trend) as $tm): ?>
            <tr>
                <td><?= e($tm['label']) ?></td>
                <td class="text-right" style="color:#15803d"><?= fmt($tm['rev']) ?></td>
                <td class="text-right" style="color:#b91c1c"><?= fmt($tm['exp']) ?></td>
                <td class="text-right" style="font-weight:600;color:<?= $tm['profit']>=0?'#15803d':'#b91c1c' ?>">
                    <?= ($tm['profit']<0?'-':'') . fmt($tm['profit']) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Per-Cow Profitability ──────────────────────────────────────────────── -->
<?php if (!empty($cow_rows)): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <h3>Per-Cow Profitability</h3>
        <span class="text-muted text-sm"><?= e($range_label) ?></span>
    </div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.83rem">
            <thead>
                <tr>
                    <th>Cow</th>
                    <th class="text-right">Milk Rev.</th>
                    <th class="text-right">Feed</th>
                    <th class="text-right">Medicine</th>
                    <th class="text-right">Vet</th>
                    <th class="text-right">Net</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cow_rows as $cr):
                $cr_net   = $cr['milk_rev'] - $cr['feed_cost'] - $cr['med_cost'] - $cr['treat_cost'] - $cr['vet_cost'];
                $is_profit = $cr_net >= 0;
            ?>
            <tr>
                <td>
                    <a href="/modules/cows/profile.php?id=<?= $cr['id'] ?>">
                        <strong><?= e($cr['cow_name'] ?: '—') ?></strong>
                        <span class="text-muted text-xs">#<?= e($cr['tag_number']) ?></span>
                    </a>
                </td>
                <td class="text-right" style="color:#15803d"><?= fmt($cr['milk_rev']) ?></td>
                <td class="text-right" style="color:#d97706"><?= fmt($cr['feed_cost']) ?></td>
                <td class="text-right" style="color:#7c3aed"><?= fmt($cr['med_cost'] + $cr['treat_cost']) ?></td>
                <td class="text-right" style="color:#0284c7"><?= fmt($cr['vet_cost']) ?></td>
                <td class="text-right" style="font-weight:700;color:<?= $is_profit?'#15803d':'#b91c1c' ?>">
                    <?= ($cr_net<0?'-':'') . fmt($cr_net) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Quick Add Forms + Log ──────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1.25rem;margin-bottom:1.5rem">

    <!-- Other Income -->
    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #16a34a">
            <h3 style="margin:0">+ Other Income</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action"      value="add_income">
                <input type="hidden" name="f_range"     value="<?= e($range) ?>">
                <input type="hidden" name="f_date_from" value="<?= e($from) ?>">
                <input type="hidden" name="f_date_to"   value="<?= e($to) ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control form-control-sm">
                            <option>Manure Sale</option>
                            <option>Skin Sale</option>
                            <option>Milk Byproduct</option>
                            <option>Subsidy</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Amount (৳)</label>
                        <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Date</label>
                        <input type="date" name="income_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional">
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-success" style="margin-top:.65rem;width:100%">Record Income</button>
            </form>
            <?php if (!empty($oi_rows)): ?>
            <div style="margin-top:1rem;border-top:1px solid var(--border);padding-top:.75rem">
                <div class="text-sm text-muted" style="margin-bottom:.4rem">Recent entries</div>
                <?php foreach ($oi_rows as $oi): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:.82rem;padding:.25rem 0;border-bottom:1px solid var(--border)">
                    <div>
                        <span><?= e($oi['category']) ?></span>
                        <span class="text-muted text-xs" style="margin-left:.4rem"><?= e(formatDate($oi['income_date'])) ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <span style="color:#15803d;font-weight:600"><?= fmt((float)$oi['amount']) ?></span>
                        <?php if (hasRole(['admin'])): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this record?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"      value="delete_income">
                            <input type="hidden" name="row_id"      value="<?= $oi['id'] ?>">
                            <input type="hidden" name="f_range"     value="<?= e($range) ?>">
                            <input type="hidden" name="f_date_from" value="<?= e($from) ?>">
                            <input type="hidden" name="f_date_to"   value="<?= e($to) ?>">
                            <button type="submit" class="btn btn-xs btn-danger">✕</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Misc Expenses -->
    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #dc2626">
            <h3 style="margin:0">+ Misc Expense</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action"      value="add_expense">
                <input type="hidden" name="f_range"     value="<?= e($range) ?>">
                <input type="hidden" name="f_date_from" value="<?= e($from) ?>">
                <input type="hidden" name="f_date_to"   value="<?= e($to) ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-control form-control-sm">
                            <option>Utility Bill</option>
                            <option>Transportation</option>
                            <option>Equipment Repair</option>
                            <option>Land Lease</option>
                            <option>Insurance</option>
                            <option>Miscellaneous</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Amount (৳)</label>
                        <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Date</label>
                        <input type="date" name="expense_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional">
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-danger" style="margin-top:.65rem;width:100%">Record Expense</button>
            </form>
            <?php if (!empty($me_rows)): ?>
            <div style="margin-top:1rem;border-top:1px solid var(--border);padding-top:.75rem">
                <div class="text-sm text-muted" style="margin-bottom:.4rem">Recent entries</div>
                <?php foreach ($me_rows as $me): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:.82rem;padding:.25rem 0;border-bottom:1px solid var(--border)">
                    <div>
                        <span><?= e($me['category']) ?></span>
                        <span class="text-muted text-xs" style="margin-left:.4rem"><?= e(formatDate($me['expense_date'])) ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <span style="color:#b91c1c;font-weight:600"><?= fmt((float)$me['amount']) ?></span>
                        <?php if (hasRole(['admin'])): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this record?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"      value="delete_expense">
                            <input type="hidden" name="row_id"      value="<?= $me['id'] ?>">
                            <input type="hidden" name="f_range"     value="<?= e($range) ?>">
                            <input type="hidden" name="f_date_from" value="<?= e($from) ?>">
                            <input type="hidden" name="f_date_to"   value="<?= e($to) ?>">
                            <button type="submit" class="btn btn-xs btn-danger">✕</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Chart.js ──────────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    const labels  = <?= $chart_labels ?>;
    const revData = <?= $chart_rev ?>;
    const expData = <?= $chart_exp ?>;
    const profData= <?= $chart_profit ?>;

    const ctx = document.getElementById('trendChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Revenue',
                    data: revData,
                    backgroundColor: 'rgba(22,163,74,.7)',
                    borderColor: '#16a34a',
                    borderWidth: 1,
                    borderRadius: 3,
                },
                {
                    label: 'Expenses',
                    data: expData,
                    backgroundColor: 'rgba(220,38,38,.6)',
                    borderColor: '#dc2626',
                    borderWidth: 1,
                    borderRadius: 3,
                },
                {
                    label: 'Net Profit',
                    data: profData,
                    type: 'line',
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124,58,237,.1)',
                    borderWidth: 2,
                    pointRadius: 3,
                    tension: 0.3,
                    fill: true,
                    yAxisID: 'y',
                },
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ৳' + Number(ctx.raw).toLocaleString('en', {minimumFractionDigits:2})
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: v => '৳' + Number(v).toLocaleString()
                    }
                }
            }
        }
    });
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
