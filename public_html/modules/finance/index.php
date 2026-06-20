<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'accountant']);
requireFarmScope();
requireModule('finance');

$page_title = 'Finance';
$active_nav = 'finance';
$db = getDB();

// Handle POST (delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/finance/index.php');
    }
    if ($_POST['action'] === 'delete' && hasRole(['admin'])) {
        $del_id = (int)($_POST['txn_id'] ?? 0);
        if ($del_id > 0) {
            $sel = $db->prepare("SELECT * FROM finance_transactions WHERE id = ? AND " . farmFilter());
            $sel->execute([$del_id]);
            $txn = $sel->fetch();
            if ($txn) {
                $db->prepare("DELETE FROM finance_transactions WHERE id = ? AND " . farmFilter())->execute([$del_id]);
                auditLog((int)$_SESSION['user_id'], 'DELETE_FINANCE_TXN', 'finance_transactions', $del_id, $txn, null);
                flashMessage('success', 'Transaction deleted.');
            }
        }
    }
    redirect('/modules/finance/index.php?' . http_build_query(array_filter([
        'date_from' => $_POST['f_date_from'] ?? '',
        'date_to'   => $_POST['f_date_to']   ?? '',
        'type'      => $_POST['f_type']       ?? '',
        'category'  => $_POST['f_category']   ?? '',
    ])));
}

// Filters
$date_from    = trim($_GET['date_from']  ?? '');
$date_to      = trim($_GET['date_to']    ?? '');
$filter_type  = in_array($_GET['type'] ?? '', ['income','expense'], true) ? $_GET['type'] : '';
$filter_cat   = trim($_GET['category']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 25;

$where  = [farmFilter('ft')];
$params = [];
if ($date_from !== '' && strtotime($date_from)) { $where[] = 'ft.transaction_date >= ?'; $params[] = $date_from; }
if ($date_to   !== '' && strtotime($date_to))   { $where[] = 'ft.transaction_date <= ?'; $params[] = $date_to; }
if ($filter_type !== '') { $where[] = 'ft.type = ?'; $params[] = $filter_type; }
if ($filter_cat  !== '') { $where[] = 'ft.category LIKE ?'; $params[] = "%{$filter_cat}%"; }
$where_sql = implode(' AND ', $where);

// Month summary (always current month regardless of filters)
$month_row = $db->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) AS income,
       COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense
     FROM finance_transactions
     WHERE " . farmFilter() . "
       AND MONTH(transaction_date) = MONTH(CURDATE())
       AND YEAR(transaction_date)  = YEAR(CURDATE())"
);
$month_row->execute();
$month_row = $month_row->fetch();

$alltime_row = $db->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) AS income,
       COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense
     FROM finance_transactions
     WHERE " . farmFilter()
);
$alltime_row->execute();
$alltime_row = $alltime_row->fetch();

$month_net   = (float)$month_row['income']   - (float)$month_row['expense'];
$alltime_net = (float)$alltime_row['income'] - (float)$alltime_row['expense'];

// Previous month summary
$prev_month_row = $db->prepare(
    "SELECT
       COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END), 0) AS income,
       COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense
     FROM finance_transactions
     WHERE " . farmFilter() . "
       AND MONTH(transaction_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
       AND YEAR(transaction_date)  = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))"
);
$prev_month_row->execute();
$prev_month_row = $prev_month_row->fetch();
$prev_month_net = (float)$prev_month_row['income'] - (float)$prev_month_row['expense'];

// Category breakdowns for charts (current month)
$expense_cats_raw = $db->prepare(
    "SELECT category, COALESCE(SUM(amount),0) AS total
     FROM finance_transactions
     WHERE " . farmFilter() . " AND type='expense'
       AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())
     GROUP BY category ORDER BY total DESC LIMIT 8"
);
$expense_cats_raw->execute();
$expense_cats_raw = $expense_cats_raw->fetchAll();

$income_cats_raw = $db->prepare(
    "SELECT category, COALESCE(SUM(amount),0) AS total
     FROM finance_transactions
     WHERE " . farmFilter() . " AND type='income'
       AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())
     GROUP BY category ORDER BY total DESC LIMIT 8"
);
$income_cats_raw->execute();
$income_cats_raw = $income_cats_raw->fetchAll();

// Count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM finance_transactions ft WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pager = paginate($total, $per_page, $page);

// Fetch
$fetch_params = array_merge($params, [$per_page, $pager['offset']]);
$stmt = $db->prepare(
    "SELECT ft.id, ft.type, ft.category, ft.amount, ft.related_module,
            ft.transaction_date, ft.notes,
            u.name AS recorded_by_name
     FROM finance_transactions ft
     JOIN users u ON u.id = ft.recorded_by
     WHERE {$where_sql}
     ORDER BY ft.transaction_date DESC, ft.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute($fetch_params);
$transactions = $stmt->fetchAll();

// Distinct categories for filter datalist
$cats_stmt = $db->prepare("SELECT DISTINCT category FROM finance_transactions WHERE " . farmFilter() . " ORDER BY category ASC");
$cats_stmt->execute();
$cats = $cats_stmt->fetchAll(PDO::FETCH_COLUMN);

$qs = static fn(array $p): string =>
    '/modules/finance/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Finance</h2>
        <p class="text-sm text-muted">Income &amp; expense tracking</p>
    </div>
    <a href="/modules/finance/form.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Transaction
    </a>
</div>

<!-- KPI bar -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(175px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">This Month Income</div>
        <div class="kpi-value" style="font-size:1.4rem"><?= e(formatCurrency((float)$month_row['income'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">This Month Expense</div>
        <div class="kpi-value" style="font-size:1.4rem"><?= e(formatCurrency((float)$month_row['expense'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $month_net >= 0 ? '#16A34A' : '#DC2626' ?>;--kpi-soft:<?= $month_net >= 0 ? '#F0FDF4' : '#FEF2F2' ?>">
        <div class="kpi-label">This Month Net</div>
        <div class="kpi-value" style="font-size:1.4rem;color:<?= $month_net >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
            <?= ($month_net >= 0 ? '+' : '') . e(formatCurrency(abs($month_net))) ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $alltime_net >= 0 ? '#2563EB' : '#DC2626' ?>;--kpi-soft:<?= $alltime_net >= 0 ? '#EFF6FF' : '#FEF2F2' ?>">
        <div class="kpi-label">All-Time Net Profit</div>
        <div class="kpi-value" style="font-size:1.4rem;color:<?= $alltime_net >= 0 ? 'var(--primary)' : 'var(--danger)' ?>">
            <?= ($alltime_net >= 0 ? '+' : '') . e(formatCurrency(abs($alltime_net))) ?>
        </div>
    </div>
</div>

<!-- Previous Month Comparison -->
<?php
$prev_label = date('M Y', strtotime('first day of last month'));
$inc_diff   = (float)$month_row['income']   - (float)$prev_month_row['income'];
$exp_diff   = (float)$month_row['expense']  - (float)$prev_month_row['expense'];
$net_diff   = $month_net - $prev_month_net;
function _mom_arrow(float $diff, bool $inverse = false): string {
    if ($diff == 0) return '<span style="color:#6B7280">— No change</span>';
    $up   = $diff > 0;
    $good = $inverse ? !$up : $up;
    $pct  = ($diff > 0 ? '+' : '') . number_format($diff, 0);
    $color = $good ? 'var(--success)' : 'var(--danger)';
    $arrow = $up ? '▲' : '▼';
    return "<span style=\"color:{$color}\">{$arrow} ৳ {$pct}</span>";
}
?>
<div style="margin-bottom:1.25rem">
    <div style="font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.6rem">
        vs <?= $prev_label ?>
    </div>
    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(175px,1fr))">
        <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4;opacity:.85">
            <div class="kpi-label">Prev Month Income</div>
            <div class="kpi-value" style="font-size:1.2rem"><?= e(formatCurrency((float)$prev_month_row['income'])) ?></div>
            <div style="font-size:.78rem;margin-top:.25rem"><?= _mom_arrow($inc_diff) ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2;opacity:.85">
            <div class="kpi-label">Prev Month Expense</div>
            <div class="kpi-value" style="font-size:1.2rem"><?= e(formatCurrency((float)$prev_month_row['expense'])) ?></div>
            <div style="font-size:.78rem;margin-top:.25rem"><?= _mom_arrow($exp_diff, true) ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:<?= $prev_month_net >= 0 ? '#16A34A' : '#DC2626' ?>;--kpi-soft:<?= $prev_month_net >= 0 ? '#F0FDF4' : '#FEF2F2' ?>;opacity:.85">
            <div class="kpi-label">Prev Month Net</div>
            <div class="kpi-value" style="font-size:1.2rem;color:<?= $prev_month_net >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                <?= ($prev_month_net >= 0 ? '+' : '') . e(formatCurrency(abs($prev_month_net))) ?>
            </div>
            <div style="font-size:.78rem;margin-top:.25rem"><?= _mom_arrow($net_diff) ?></div>
        </div>
    </div>
</div>

<!-- Category Breakdown Charts -->
<?php if (!empty($expense_cats_raw) || !empty($income_cats_raw)): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
    <?php if (!empty($expense_cats_raw)): ?>
    <div class="card">
        <div class="card-header"><span class="card-title">Expense Categories (This Month)</span></div>
        <div style="position:relative;height:220px;padding:.75rem">
            <canvas id="expenseCatChart"></canvas>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($income_cats_raw)): ?>
    <div class="card">
        <div class="card-header"><span class="card-title">Income Sources (This Month)</span></div>
        <div style="position:relative;height:220px;padding:.75rem">
            <canvas id="incomeCatChart"></canvas>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<form method="GET" action="/modules/finance/index.php"
      style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem">
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">Type</label>
        <select name="type" class="form-control">
            <option value="">All</option>
            <option value="income"  <?= $filter_type === 'income'  ? 'selected' : '' ?>>Income</option>
            <option value="expense" <?= $filter_type === 'expense' ? 'selected' : '' ?>>Expense</option>
        </select>
    </div>
    <div class="form-group" style="margin:0;min-width:170px">
        <label class="form-label" style="font-size:.78rem">Category</label>
        <input type="text" name="category" class="form-control" list="cat_list"
               value="<?= e($filter_cat) ?>" placeholder="All categories">
        <datalist id="cat_list">
            <?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
        </datalist>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($date_from || $date_to || $filter_type || $filter_cat): ?>
    <a href="/modules/finance/index.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
    <span class="text-sm text-muted" style="align-self:center;margin-left:auto">
        <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
    </span>
</form>

<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($transactions)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>
        </svg>
        <h3>No transactions found</h3>
        <p><a href="/modules/finance/form.php">Add the first transaction</a> or adjust the filters.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Category</th>
                <th>Amount</th>
                <th>Source</th>
                <th>Recorded By</th>
                <th>Notes</th>
                <th style="width:90px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($transactions as $txn): ?>
        <tr>
            <td style="white-space:nowrap"><?= e(formatDate($txn['transaction_date'])) ?></td>
            <td>
                <?php if ($txn['type'] === 'income'): ?>
                <span class="badge badge-green">Income</span>
                <?php else: ?>
                <span class="badge badge-red">Expense</span>
                <?php endif; ?>
            </td>
            <td><?= e($txn['category']) ?></td>
            <td style="font-weight:700;color:<?= $txn['type'] === 'income' ? 'var(--success)' : 'var(--danger)' ?>">
                <?= $txn['type'] === 'income' ? '+' : '-' ?><?= e(formatCurrency((float)$txn['amount'])) ?>
            </td>
            <td>
                <?php if ($txn['related_module']): ?>
                <span class="badge badge-gray" style="font-size:.72rem"><?= e(ucfirst($txn['related_module'])) ?></span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:.85rem"><?= e($txn['recorded_by_name']) ?></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem">
                <?= $txn['notes'] ? e($txn['notes']) : '—' ?>
            </td>
            <td>
                <div style="display:flex;gap:.35rem">
                    <a href="/modules/finance/form.php?id=<?= $txn['id'] ?>"
                       class="btn btn-sm btn-secondary" title="Edit">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </a>
                    <?php if (hasRole(['admin'])): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Delete this transaction?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"       value="delete">
                        <input type="hidden" name="txn_id"       value="<?= $txn['id'] ?>">
                        <input type="hidden" name="f_date_from"  value="<?= e($date_from) ?>">
                        <input type="hidden" name="f_date_to"    value="<?= e($date_to) ?>">
                        <input type="hidden" name="f_type"       value="<?= e($filter_type) ?>">
                        <input type="hidden" name="f_category"   value="<?= e($filter_cat) ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="<?= e($qs(['date_from'=>$date_from,'date_to'=>$date_to,'type'=>$filter_type,'category'=>$filter_cat,'page'=>$pager['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p=max(1,$pager['current_page']-2); $p<=min($pager['total_pages'],$pager['current_page']+2); $p++): ?>
    <a href="<?= e($qs(['date_from'=>$date_from,'date_to'=>$date_to,'type'=>$filter_type,'category'=>$filter_cat,'page'=>$p])) ?>"
       class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($qs(['date_from'=>$date_from,'date_to'=>$date_to,'type'=>$filter_type,'category'=>$filter_cat,'page'=>$pager['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$extra_js = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'];

$exp_labels = json_encode(array_column($expense_cats_raw, 'category'));
$exp_data   = json_encode(array_map(fn($r) => (float)$r['total'], $expense_cats_raw));
$inc_labels = json_encode(array_column($income_cats_raw, 'category'));
$inc_data   = json_encode(array_map(fn($r) => (float)$r['total'], $income_cats_raw));

$inline_js = <<<JS
(function() {
    var PIE_COLORS = ['#2563EB','#16A34A','#DC2626','#D97706','#7C3AED','#0891B2','#DB2777','#9CA3AF'];
    function makePie(id, labels, data) {
        var ctx = document.getElementById(id);
        if (!ctx || !labels.length) return;
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: data, backgroundColor: PIE_COLORS, borderWidth: 2, borderColor: '#fff', hoverOffset: 4 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '55%',
                plugins: {
                    legend: { position: 'right', labels: { padding: 12, font: { size: 11 }, boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                                var pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                                return ' ৳ ' + ctx.parsed.toLocaleString() + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
    makePie('expenseCatChart', {$exp_labels}, {$exp_data});
    makePie('incomeCatChart',  {$inc_labels}, {$inc_data});
})();
JS;

require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
