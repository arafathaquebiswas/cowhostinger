<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireAuth();
requireFarmScope();
requireModule('milk');

$page_title = 'Milk Production';
$active_nav = 'milk';
$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request. Please try again.');
        redirect('/modules/milk/index.php');
    }

    if ($_POST['action'] === 'delete' && hasRole(['admin'])) {
        $del_id = (int)($_POST['record_id'] ?? 0);
        if ($del_id > 0) {
            $sel = $db->prepare("SELECT id, cow_id, liters, recorded_at FROM milk_records WHERE id = ? AND " . farmFilter());
            $sel->execute([$del_id]);
            $rec = $sel->fetch();
            if ($rec) {
                $db->prepare("DELETE FROM milk_records WHERE id = ? AND " . farmFilter())->execute([$del_id]);
                auditLog((int)$_SESSION['user_id'], 'DELETE_MILK_RECORD', 'milk_records', $del_id, $rec, null);
                flashMessage('success', 'Milk record deleted.');
            }
        }
    }
    redirect('/modules/milk/index.php?' . http_build_query(array_filter([
        'date_from'     => $_POST['f_date_from']     ?? '',
        'date_to'       => $_POST['f_date_to']       ?? '',
        'cow_id'        => $_POST['f_cow_id']        ?? '',
        'contamination' => $_POST['f_contamination'] ?? '',
    ])));
}

// Filters
$date_from     = trim($_GET['date_from']     ?? '');
$date_to       = trim($_GET['date_to']       ?? '');
$filter_cow    = (int)($_GET['cow_id']       ?? 0);
$contamination = $_GET['contamination']      ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 30;

// Build WHERE
$where  = [farmFilter('mr')];
$params = [];
if ($date_from !== '' && strtotime($date_from)) {
    $where[]  = 'mr.recorded_at >= ?';
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to !== '' && strtotime($date_to)) {
    $where[]  = 'mr.recorded_at <= ?';
    $params[] = $date_to . ' 23:59:59';
}
if ($filter_cow > 0) {
    $where[]  = 'mr.cow_id = ?';
    $params[] = $filter_cow;
}
if ($contamination === '1') {
    $where[]  = 'mr.contamination_flag = 1';
} elseif ($contamination === '0') {
    $where[]  = 'mr.contamination_flag = 0';
}
$where_sql = implode(' AND ', $where);

// Summary stats (always from full table)
$today_row = $db->prepare(
    "SELECT COALESCE(SUM(liters),0) AS ltr, COUNT(*) AS cnt
     FROM milk_records WHERE " . farmFilter() . " AND DATE(recorded_at) = CURDATE()"
);
$today_row->execute();
$today_row = $today_row->fetch();
$week_row = $db->prepare(
    "SELECT COALESCE(SUM(liters),0) AS ltr
     FROM milk_records WHERE " . farmFilter() . " AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
);
$week_row->execute();
$week_row = $week_row->fetch();
$total_row = $db->prepare("SELECT COALESCE(SUM(liters),0) AS ltr, COUNT(*) AS cnt FROM milk_records WHERE " . farmFilter());
$total_row->execute();
$total_row = $total_row->fetch();
$price_row = $db->query(
    "SELECT price_per_liter FROM milk_price_history
     WHERE effective_date <= CURDATE()
     ORDER BY effective_date DESC LIMIT 1"
)->fetch();
$current_price = $price_row ? (float)$price_row['price_per_liter'] : 0.0;
$today_revenue = (float)$today_row['ltr'] * $current_price;

// Count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM milk_records mr WHERE {$where_sql}");
$count_stmt->execute($params);
$total  = (int)$count_stmt->fetchColumn();
$pager  = paginate($total, $per_page, $page);

// Fetch records
$fetch_params = array_merge($params, [$per_page, $pager['offset']]);
$stmt = $db->prepare(
    "SELECT mr.id, mr.cow_id, mr.liters, mr.fat_percentage, mr.contamination_flag, mr.recorded_at,
            c.tag_number, c.breed,
            u.name AS recorded_by_name
     FROM milk_records mr
     JOIN cows  c ON c.id = mr.cow_id
     JOIN users u ON u.id = mr.recorded_by
     WHERE {$where_sql}
     ORDER BY mr.recorded_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute($fetch_params);
$records = $stmt->fetchAll();

// Cows for filter dropdown (all non-deceased)
$cows_list = $db->prepare(
    "SELECT id, tag_number, breed FROM cows
     WHERE " . farmFilter() . " AND status NOT IN ('sold','deceased')
     ORDER BY tag_number ASC"
);
$cows_list->execute();
$cows_list = $cows_list->fetchAll();

$top_producers = $db->prepare(
    "SELECT c.id, c.tag_number, c.breed,
            COALESCE(SUM(mr.liters),0) AS total_liters,
            COUNT(mr.id) AS record_count,
            ROUND(AVG(mr.liters), 2) AS avg_liters
     FROM milk_records mr
     JOIN cows c ON c.id = mr.cow_id
     WHERE " . farmFilter('mr') . " AND mr.recorded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY c.id, c.tag_number, c.breed
     ORDER BY total_liters DESC
     LIMIT 5"
);
$top_producers->execute();
$top_producers = $top_producers->fetchAll();

$qs = static fn(array $p): string =>
    '/modules/milk/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null && $v !== 0));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Milk Production</h2>
        <p class="text-sm text-muted">
            Daily milk records
            <?= $current_price > 0 ? ' · Current price: ' . e(formatCurrency($current_price)) . '/L' : '' ?>
        </p>
    </div>
    <?php if (hasRole(['admin', 'worker', 'veterinarian'])): ?>
    <a href="/modules/milk/record.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Record Milk
    </a>
    <?php endif; ?>
</div>

<!-- Summary KPIs -->
<div class="kpi-grid" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF;grid-template-columns:repeat(auto-fill,minmax(175px,1fr))">
    <div class="kpi-card">
        <div class="kpi-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 2h10M5 6h14M9 2v4M15 2v4"/><path d="M4 6l1 14h14l1-14"/></svg>
        </div>
        <div class="kpi-label">Today's Total</div>
        <div class="kpi-value"><?= e(number_format((float)$today_row['ltr'], 1)) ?> L</div>
        <div class="kpi-label" style="margin-top:-.25rem"><?= $today_row['cnt'] ?> record<?= $today_row['cnt'] != 1 ? 's' : '' ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
        </div>
        <div class="kpi-label">Today's Revenue</div>
        <div class="kpi-value"><?= e(formatCurrency($today_revenue)) ?></div>
        <div class="kpi-label" style="margin-top:-.25rem"><?= $current_price > 0 ? '@ ' . e(formatCurrency($current_price)) . '/L' : 'No price set' ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div class="kpi-label">Last 7 Days</div>
        <div class="kpi-value"><?= e(number_format((float)$week_row['ltr'], 1)) ?> L</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        </div>
        <div class="kpi-label">Total Records</div>
        <div class="kpi-value"><?= number_format((int)$total_row['cnt']) ?></div>
        <div class="kpi-label" style="margin-top:-.25rem"><?= e(number_format((float)$total_row['ltr'], 0)) ?> L all-time</div>
    </div>
</div>

<!-- Filters -->
<form method="GET" action="/modules/milk/index.php"
      style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem">
    <div class="form-group" style="margin:0;min-width:130px">
        <label class="form-label" style="font-size:.78rem">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:130px">
        <label class="form-label" style="font-size:.78rem">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
    </div>
    <div class="form-group" style="margin:0;min-width:160px">
        <label class="form-label" style="font-size:.78rem">Cow</label>
        <select name="cow_id" class="form-control">
            <option value="">All Cows</option>
            <?php foreach ($cows_list as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filter_cow === (int)$c['id'] ? 'selected' : '' ?>>
                #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0;min-width:140px">
        <label class="form-label" style="font-size:.78rem">Quality</label>
        <select name="contamination" class="form-control">
            <option value="">All</option>
            <option value="0" <?= $contamination === '0' ? 'selected' : '' ?>>Normal</option>
            <option value="1" <?= $contamination === '1' ? 'selected' : '' ?>>Contaminated</option>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($date_from || $date_to || $filter_cow || $contamination !== ''): ?>
    <a href="/modules/milk/index.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
    <span class="text-sm text-muted" style="align-self:center;margin-left:auto">
        <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
    </span>
</form>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <span class="card-title">Top-Producing Cows - Last 30 Days</span>
    </div>
    <?php if (empty($top_producers)): ?>
    <div class="empty-state" style="padding:1.5rem">
        <h3>No production ranking yet</h3>
        <p>Record milk entries to populate the top-producing cows view.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Cow</th>
                <th>Total Liters</th>
                <th>Average / Entry</th>
                <th>Records</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($top_producers as $idx => $cow): ?>
        <tr>
            <td>
                <span class="badge badge-blue" style="margin-right:.45rem">#<?= $idx + 1 ?></span>
                <a href="/modules/cows/view.php?id=<?= $cow['id'] ?>" style="font-weight:600;color:var(--primary)">
                    #<?= e($cow['tag_number']) ?>
                </a>
                <span class="text-muted" style="font-size:.82rem"><?= e($cow['breed']) ?></span>
            </td>
            <td><strong><?= e(number_format((float)$cow['total_liters'], 2)) ?> L</strong></td>
            <td><?= e(number_format((float)$cow['avg_liters'], 2)) ?> L</td>
            <td><?= (int)$cow['record_count'] ?></td>
            <td><a href="/modules/milk/index.php?cow_id=<?= $cow['id'] ?>" class="btn btn-sm btn-secondary">View Records</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($records)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M7 2h10M5 6h14M9 2v4M15 2v4"/><path d="M4 6l1 14h14l1-14"/>
        </svg>
        <h3>No milk records found</h3>
        <p><?= hasRole(['admin','worker','veterinarian'])
            ? '<a href="/modules/milk/record.php">Record today\'s milk</a> to get started.'
            : 'No records match the current filters.' ?>
        </p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Date &amp; Time</th>
                <th>Cow</th>
                <th>Liters</th>
                <th>Fat %</th>
                <th>Quality</th>
                <th>Recorded By</th>
                <?php if (hasRole(['admin'])): ?><th style="width:60px"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
            <td style="white-space:nowrap"><?= e(formatDateTime($r['recorded_at'])) ?></td>
            <td>
                <a href="/modules/cows/view.php?id=<?= $r['cow_id'] ?? 0 ?>"
                   style="color:var(--primary);font-weight:600">#<?= e($r['tag_number']) ?></a>
                <span class="text-muted" style="font-size:.82rem"> <?= e($r['breed']) ?></span>
            </td>
            <td><strong><?= e(number_format((float)$r['liters'], 2)) ?> L</strong></td>
            <td><?= $r['fat_percentage'] !== null ? e(number_format((float)$r['fat_percentage'], 1)) . '%' : '—' ?></td>
            <td>
                <?php if ($r['contamination_flag']): ?>
                <span class="badge badge-red">Contaminated</span>
                <?php else: ?>
                <span class="badge badge-green">Normal</span>
                <?php endif; ?>
            </td>
            <td><?= e($r['recorded_by_name']) ?></td>
            <?php if (hasRole(['admin'])): ?>
            <td>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Delete this milk record?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"       value="delete">
                    <input type="hidden" name="record_id"    value="<?= $r['id'] ?>">
                    <input type="hidden" name="f_date_from"  value="<?= e($date_from) ?>">
                    <input type="hidden" name="f_date_to"    value="<?= e($date_to) ?>">
                    <input type="hidden" name="f_cow_id"     value="<?= $filter_cow ?: '' ?>">
                    <input type="hidden" name="f_contamination" value="<?= e($contamination) ?>">
                    <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                    </button>
                </form>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="<?= e($qs(['date_from'=>$date_from,'date_to'=>$date_to,'cow_id'=>$filter_cow?:null,'contamination'=>$contamination,'page'=>$pager['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p = max(1,$pager['current_page']-2); $p <= min($pager['total_pages'],$pager['current_page']+2); $p++): ?>
    <a href="<?= e($qs(['date_from'=>$date_from,'date_to'=>$date_to,'cow_id'=>$filter_cow?:null,'contamination'=>$contamination,'page'=>$p])) ?>"
       class="page-btn <?= $p === $pager['current_page'] ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($qs(['date_from'=>$date_from,'date_to'=>$date_to,'cow_id'=>$filter_cow?:null,'contamination'=>$contamination,'page'=>$pager['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
