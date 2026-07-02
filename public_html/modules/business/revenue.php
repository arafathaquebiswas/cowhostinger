<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireModule('sales');
requireRole(['admin', 'manager', 'accountant']);

$db    = getDB();
$today = date('Y-m-d');

$period = $_GET['period'] ?? 'month';
switch ($period) {
    case 'week':  $from = date('Y-m-d', strtotime('monday this week')); $to = $today; break;
    case 'year':  $from = date('Y-01-01'); $to = $today; break;
    case 'custom':$from = $_GET['from'] ?? date('Y-m-01'); $to = $_GET['to'] ?? $today; break;
    default:      $from = date('Y-m-01'); $to = $today; $period = 'month';
}

// ── Revenue by source ─────────────────────────────────────────────────────────
function rev(PDO $db, string $sql, int $fid, string $from, string $to): float {
    $s = $db->prepare($sql);
    $s->execute([$fid, $from, $to]);
    return (float)($s->fetchColumn() ?: 0);
}
$fid = fid();

$rev_cow        = rev($db, "SELECT COALESCE(SUM(sale_price),0) FROM cow_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ?", $fid, $from, $to);
$rev_meat       = rev($db, "SELECT COALESCE(SUM(total_revenue),0) FROM meat_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ?", $fid, $from, $to);
$rev_byproduct  = rev($db, "SELECT COALESCE(SUM(total_amount),0) FROM cow_byproduct_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ?", $fid, $from, $to);
$rev_skin       = rev($db, "SELECT COALESCE(SUM(total_amount),0) FROM cow_byproduct_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? AND sale_type='skin'", $fid, $from, $to);
$rev_manure     = rev($db, "SELECT COALESCE(SUM(total_amount),0) FROM cow_byproduct_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? AND sale_type='dung'", $fid, $from, $to);
$rev_repro      = rev($db, "SELECT COALESCE(SUM(total_amount),0) FROM cow_byproduct_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? AND sale_type IN ('semen','breeding_service')", $fid, $from, $to);

// Milk sales — from dedicated table if exists, else from finance
$milk_table_exists = !empty($db->query("SHOW TABLES LIKE 'milk_sales'")->fetchAll());
$rev_milk = $milk_table_exists
    ? rev($db, "SELECT COALESCE(SUM(total_amount),0) FROM milk_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ?", $fid, $from, $to)
    : rev($db, "SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE farm_id=? AND category='Milk Sales' AND transaction_date BETWEEN ? AND ?", $fid, $from, $to);

$feed_table_exists = !empty($db->query("SHOW TABLES LIKE 'feed_sales'")->fetchAll());
$rev_feed = $feed_table_exists
    ? rev($db, "SELECT COALESCE(SUM(total_amount),0) FROM feed_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ?", $fid, $from, $to)
    : 0;

$med_table_exists = !empty($db->query("SHOW TABLES LIKE 'medicine_sales'")->fetchAll());
$rev_medicine = $med_table_exists
    ? rev($db, "SELECT COALESCE(SUM(total_amount),0) FROM medicine_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ?", $fid, $from, $to)
    : 0;

$rev_equipment = rev($db, "SELECT COALESCE(SUM(sale_price),0) FROM equipment_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ?", $fid, $from, $to);

// Family consumption (non-revenue)
$fc_table_exists = !empty($db->query("SHOW TABLES LIKE 'family_consumption'")->fetchAll());
$fc_milk = $fc_table_exists
    ? rev($db, "SELECT COALESCE(SUM(estimated_value),0) FROM family_consumption WHERE farm_id=? AND item_type='milk' AND consumption_date BETWEEN ? AND ?", $fid, $from, $to)
    : 0;
$fc_total = $fc_table_exists
    ? rev($db, "SELECT COALESCE(SUM(estimated_value),0) FROM family_consumption WHERE farm_id=? AND consumption_date BETWEEN ? AND ?", $fid, $from, $to)
    : 0;

$total_revenue = $rev_cow + $rev_meat + $rev_byproduct + $rev_milk + $rev_feed + $rev_medicine + $rev_equipment;

// Operating expenses — Equipment Purchase excluded (capital asset, not P&L expense)
$total_expense = rev($db, "SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE farm_id=? AND type='expense' AND category != 'Equipment Purchase' AND transaction_date BETWEEN ? AND ?", $fid, $from, $to);
$net_profit    = $total_revenue - $total_expense;

// Monthly trend (last 6 months)
$trend_rows = [];
for ($i = 5; $i >= 0; $i--) {
    $m_from = date('Y-m-01', strtotime("-{$i} months"));
    $m_to   = date('Y-m-t',  strtotime("-{$i} months"));
    $m_rev  = rev($db, "SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE farm_id=? AND type='income' AND transaction_date BETWEEN ? AND ?", $fid, $m_from, $m_to);
    $m_exp  = rev($db, "SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE farm_id=? AND type='expense' AND category != 'Equipment Purchase' AND transaction_date BETWEEN ? AND ?", $fid, $m_from, $m_to);
    $trend_rows[] = ['label' => date('M Y', strtotime($m_from)), 'revenue' => $m_rev, 'expense' => $m_exp];
}

$page_title = 'Revenue Dashboard';
$active_nav = 'revenue_dashboard';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.rev-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1rem 1.25rem}
.rev-card .val{font-size:1.4rem;font-weight:700;color:#111827;margin:.15rem 0 0}
.rev-card .lbl{font-size:.78rem;color:#6b7280;font-weight:500}
.rev-card .sub{font-size:.75rem;color:#9ca3af;margin-top:.15rem}
.rev-card.accent-green{border-left:4px solid #22c55e}
.rev-card.accent-blue{border-left:4px solid #3b82f6}
.rev-card.accent-amber{border-left:4px solid #f59e0b}
.rev-card.accent-purple{border-left:4px solid #a855f7}
.rev-card.accent-red{border-left:4px solid #ef4444}
.rev-card.accent-teal{border-left:4px solid #14b8a6}
.rev-card.accent-orange{border-left:4px solid #f97316}
.rev-card.accent-pink{border-left:4px solid #ec4899}
.section-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin:1.5rem 0 .75rem}
.kpi-bar{background:#f3f4f6;border-radius:4px;height:8px;overflow:hidden;margin-top:.4rem}
.kpi-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#22c55e,#16a34a)}
.trend-table{width:100%;border-collapse:collapse;font-size:.85rem}
.trend-table th{background:#f9fafb;padding:.5rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb;font-weight:600}
.trend-table th:first-child{text-align:left}
.trend-table td{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:right}
.trend-table td:first-child{text-align:left;color:#374151}
</style>

<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div><h1 class="page-title">Revenue Dashboard</h1><p class="page-subtitle">Farm-wide income overview</p></div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <?php foreach (['week'=>'This Week','month'=>'This Month','year'=>'This Year','custom'=>'Custom'] as $p=>$l): ?>
        <a href="?period=<?= $p ?><?= $p==='custom' ? '&from='.$from.'&to='.$to : '' ?>"
           class="btn <?= $period===$p ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= $l ?></a>
        <?php endforeach; ?>
        <?php if ($period === 'custom'): ?>
        <form method="GET" style="display:flex;gap:.4rem;align-items:center">
            <input type="hidden" name="period" value="custom">
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control" style="width:130px">
            <input type="date" name="to"   value="<?= e($to) ?>"   class="form-control" style="width:130px">
            <button class="btn btn-secondary btn-sm">Apply</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<p style="color:#6b7280;font-size:.875rem;margin-bottom:1.25rem">
    Showing: <strong><?= e(date('d M Y', strtotime($from))) ?></strong> — <strong><?= e(date('d M Y', strtotime($to))) ?></strong>
</p>

<!-- Top KPIs -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem">
    <div class="rev-card" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-color:#86efac">
        <div class="lbl">Total Revenue</div>
        <div class="val" style="font-size:2rem;color:#166534"><?= number_format($total_revenue, 0) ?> ৳</div>
        <div class="sub">All income sources combined</div>
    </div>
    <div class="rev-card" style="background:linear-gradient(135deg,#fef2f2,#fee2e2);border-color:#fca5a5">
        <div class="lbl">Total Expenses</div>
        <div class="val" style="font-size:2rem;color:#991b1b"><?= number_format($total_expense, 0) ?> ৳</div>
        <div class="sub">All recorded costs</div>
    </div>
    <div class="rev-card" style="background:linear-gradient(135deg,<?= $net_profit >= 0 ? '#eff6ff,#dbeafe' : '#fff7ed,#fed7aa' ?>);border-color:<?= $net_profit >= 0 ? '#93c5fd' : '#fdba74' ?>">
        <div class="lbl">Net Profit / Loss</div>
        <div class="val" style="font-size:2rem;color:<?= $net_profit >= 0 ? '#1d4ed8' : '#c2410c' ?>"><?= ($net_profit >= 0 ? '+' : '') . number_format($net_profit, 0) ?> ৳</div>
        <div class="sub">Revenue minus expenses</div>
    </div>
</div>

<!-- Revenue Breakdown -->
<div class="section-title">Revenue by Source</div>
<?php
$sources = [
    ['label'=>'Cow Sales (Live)',    'val'=>$rev_cow,       'pct'=>$total_revenue > 0 ? $rev_cow/$total_revenue*100 : 0,       'class'=>'accent-green',  'link'=>'/modules/sales/cow_sales.php'],
    ['label'=>'Meat Sales',          'val'=>$rev_meat,      'pct'=>$total_revenue > 0 ? $rev_meat/$total_revenue*100 : 0,      'class'=>'accent-red',    'link'=>'/modules/sales/meat_sales.php'],
    ['label'=>'Milk Sales',          'val'=>$rev_milk,      'pct'=>$total_revenue > 0 ? $rev_milk/$total_revenue*100 : 0,      'class'=>'accent-blue',   'link'=>'/modules/milk/sales.php'],
    ['label'=>'Skin Sales',          'val'=>$rev_skin,      'pct'=>$total_revenue > 0 ? $rev_skin/$total_revenue*100 : 0,      'class'=>'accent-amber',  'link'=>'/modules/sales/byproduct_sales.php'],
    ['label'=>'Manure Sales',        'val'=>$rev_manure,    'pct'=>$total_revenue > 0 ? $rev_manure/$total_revenue*100 : 0,    'class'=>'accent-orange', 'link'=>'/modules/sales/byproduct_sales.php'],
    ['label'=>'Reproductive Services','val'=>$rev_repro,    'pct'=>$total_revenue > 0 ? $rev_repro/$total_revenue*100 : 0,    'class'=>'accent-pink',   'link'=>'/modules/cows/sell.php'],
    ['label'=>'Feed Sales',          'val'=>$rev_feed,      'pct'=>$total_revenue > 0 ? $rev_feed/$total_revenue*100 : 0,     'class'=>'accent-teal',   'link'=>'/modules/sales/feed_sales.php'],
    ['label'=>'Medicine Sales',      'val'=>$rev_medicine,  'pct'=>$total_revenue > 0 ? $rev_medicine/$total_revenue*100 : 0, 'class'=>'accent-purple', 'link'=>'/modules/sales/medicine_sales.php'],
    ['label'=>'Equipment Sales',     'val'=>$rev_equipment, 'pct'=>$total_revenue > 0 ? $rev_equipment/$total_revenue*100 : 0,'class'=>'accent-blue',   'link'=>'/modules/equipment/index.php'],
];
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem">
    <?php foreach ($sources as $src): ?>
    <a href="<?= $src['link'] ?>" style="text-decoration:none">
        <div class="rev-card <?= $src['class'] ?>">
            <div class="lbl"><?= $src['label'] ?></div>
            <div class="val"><?= number_format($src['val'], 0) ?> ৳</div>
            <div class="kpi-bar"><div class="kpi-fill" style="width:<?= min(100, round($src['pct'])) ?>%;background:var(--primary)"></div></div>
            <div class="sub"><?= number_format($src['pct'], 1) ?>% of total revenue</div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<!-- Family Consumption -->
<?php if ($fc_table_exists && $fc_total > 0): ?>
<div class="section-title">Family Consumption (non-revenue)</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="rev-card" style="border-left:4px solid #6b7280">
        <div class="lbl">Family Milk Consumption</div>
        <div class="val"><?= number_format($fc_milk, 0) ?> ৳</div>
        <div class="sub">Estimated value, not counted as revenue</div>
    </div>
    <div class="rev-card" style="border-left:4px solid #6b7280">
        <div class="lbl">Total Family Consumption</div>
        <div class="val"><?= number_format($fc_total, 0) ?> ৳</div>
        <div class="sub">All family/internal use</div>
    </div>
</div>
<?php endif; ?>

<!-- Monthly Trend -->
<div class="section-title">Last 6 Months Trend</div>
<div class="card">
    <div class="card-body" style="padding:0">
        <table class="trend-table">
            <thead><tr>
                <th style="text-align:left">Month</th>
                <th>Revenue (৳)</th>
                <th>Expenses (৳)</th>
                <th>Profit/Loss (৳)</th>
            </tr></thead>
            <tbody>
            <?php foreach ($trend_rows as $row): $pl = $row['revenue'] - $row['expense']; ?>
            <tr>
                <td><?= e($row['label']) ?></td>
                <td style="color:#166534;font-weight:500"><?= number_format($row['revenue'], 0) ?></td>
                <td style="color:#991b1b"><?= number_format($row['expense'], 0) ?></td>
                <td style="font-weight:700;color:<?= $pl >= 0 ? '#1d4ed8' : '#c2410c' ?>">
                    <?= ($pl >= 0 ? '+' : '') . number_format($pl, 0) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top:1.5rem;display:flex;gap:.75rem;flex-wrap:wrap">
    <a href="/modules/finance/profit.php" class="btn btn-secondary">Financial Overview →</a>
    <a href="/modules/business/reports.php" class="btn btn-secondary">Sales Reports →</a>
    <a href="/modules/finance/index.php" class="btn btn-secondary">Finance Ledger →</a>
</div>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
