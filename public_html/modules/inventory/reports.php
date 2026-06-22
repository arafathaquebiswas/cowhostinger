<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireRole(['admin', 'manager', 'accountant']);

$db    = getDB();
$fid   = fid();
$today = date('Y-m-d');

$report_type = $_GET['report'] ?? 'stock';
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;

$report_types = [
    'stock'     => 'Stock Remaining',
    'low_stock' => 'Low Stock Alert',
    'expiry'    => 'Expiring Medicines',
    'movement'  => 'Inventory Movement',
    'sales'     => 'Sales Report',
    'profit'    => 'Profit & Loss',
    'buyers'    => 'Top Buyers',
];

// ── Inventory_transactions table existence check ──────────────────────────────
$inv_tx_exists = !empty($db->query("SHOW TABLES LIKE 'inventory_transactions'")->fetchAll());

$page_title = 'Inventory Reports';
$active_nav = 'inv_reports';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div>
        <h1 class="page-title">Inventory Reports</h1>
        <p class="page-subtitle">Feed, medicine, and equipment analytics</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/inventory/feed.php" class="btn btn-secondary btn-sm">Feed Stock</a>
        <a href="/modules/inventory/medicine.php" class="btn btn-secondary btn-sm">Medicine Stock</a>
        <a href="/modules/inventory/equipment.php" class="btn btn-secondary btn-sm">Equipment</a>
    </div>
</div>

<!-- Report Type Nav -->
<div style="display:flex;gap:.25rem;flex-wrap:wrap;border-bottom:2px solid #e5e7eb;margin-bottom:1.25rem">
    <?php foreach ($report_types as $k => $label): ?>
    <a href="?report=<?= $k ?>&from=<?= $filter_from ?>&to=<?= $filter_to ?>" style="padding:.5rem 1rem;font-size:.84rem;font-weight:600;color:<?= $report_type===$k?'#2563eb':'#6b7280' ?>;border-bottom:<?= $report_type===$k?'2px solid #2563eb':'2px solid transparent' ?>;margin-bottom:-2px;text-decoration:none"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<?php if (in_array($report_type, ['movement','sales','profit','buyers'], true)): ?>
<!-- Date Filter -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-body" style="padding:.7rem 1rem">
        <form method="GET" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="report" value="<?= e($report_type) ?>">
            <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:130px">
            <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:130px">
            <button class="btn btn-primary btn-sm">Apply</button>
            <a href="?report=<?= e($report_type) ?>&from=<?= date('Y-m-01') ?>&to=<?= $today ?>" class="btn btn-secondary btn-sm">This Month</a>
            <a href="?report=<?= e($report_type) ?>&from=<?= date('Y-01-01') ?>&to=<?= $today ?>" class="btn btn-secondary btn-sm">This Year</a>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($report_type === 'stock'): ?>
<!-- ── STOCK REMAINING ───────────────────────────────────────────────────────── -->

<!-- Feed Stock -->
<?php
$feed_stock = $db->query("SELECT * FROM feed_inventory WHERE farm_id={$fid} ORDER BY item_name")->fetchAll();
$feed_total_cost = array_sum(array_map(fn($r) => (float)$r['quantity'] * (float)($r['purchase_price']??0), $feed_stock));
$feed_total_sell = array_sum(array_map(fn($r) => (float)$r['quantity'] * (float)($r['selling_price']??$r['purchase_price']??0), $feed_stock));
?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header" style="justify-content:space-between">
        <span class="card-title">Feed Stock</span>
        <span style="font-size:.85rem;color:#6b7280">Cost Value: <strong><?= number_format($feed_total_cost,0) ?> ৳</strong> | Sell Value: <strong><?= number_format($feed_total_sell,0) ?> ৳</strong></span>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($feed_stock)): ?><p style="text-align:center;padding:1.5rem;color:#9ca3af">No feed items.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.5rem .75rem;text-align:left;border-bottom:1px solid #e5e7eb">Item</th>
                <th style="padding:.5rem .75rem;border-bottom:1px solid #e5e7eb">Category</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Stock</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Cost/Unit</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Stock Value</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Reorder At</th>
                <th style="padding:.5rem .75rem;border-bottom:1px solid #e5e7eb">Expiry</th>
            </tr></thead>
            <tbody>
            <?php foreach ($feed_stock as $s):
                $is_low = (float)$s['reorder_threshold']>0 && (float)$s['quantity']<=(float)$s['reorder_threshold'];
            ?>
            <tr style="border-bottom:1px solid #f3f4f6;background:<?= $is_low?'#fffbeb':'' ?>">
                <td style="padding:.45rem .75rem;font-weight:600"><?= e($s['item_name']) ?><?= $is_low?' <span style="background:#fed7aa;color:#9a3412;font-size:.65rem;font-weight:700;padding:.1rem .3rem;border-radius:3px">LOW</span>':'' ?></td>
                <td style="padding:.45rem .75rem;color:#6b7280"><?= e($s['category']??'—') ?></td>
                <td style="padding:.45rem .75rem;text-align:right;font-weight:700;color:<?= (float)$s['quantity']<=0?'#dc2626':'#111827' ?>"><?= number_format((float)$s['quantity'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.45rem .75rem;text-align:right"><?= $s['purchase_price']?number_format((float)$s['purchase_price'],2):'—' ?></td>
                <td style="padding:.45rem .75rem;text-align:right;font-weight:600"><?= $s['purchase_price']?number_format((float)$s['quantity']*(float)$s['purchase_price'],0).' ৳':'—' ?></td>
                <td style="padding:.45rem .75rem;text-align:right;color:#6b7280"><?= (float)$s['reorder_threshold']>0?number_format((float)$s['reorder_threshold'],1).' '.$s['unit']:'—' ?></td>
                <td style="padding:.45rem .75rem;color:<?= !empty($s['expiry_date'])&&$s['expiry_date']<$today?'#dc2626':'#6b7280' ?>"><?= $s['expiry_date']?e(formatDate($s['expiry_date'])):'—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Medicine Stock -->
<?php
$med_stock = $db->query("SELECT * FROM medicine_inventory WHERE farm_id={$fid} ORDER BY item_name")->fetchAll();
$med_total_cost = array_sum(array_map(fn($r) => (float)$r['quantity']*(float)($r['cost_per_unit']??0), $med_stock));
$med_total_sell = array_sum(array_map(fn($r) => (float)$r['quantity']*(float)($r['selling_price']??$r['cost_per_unit']??0), $med_stock));
?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header" style="justify-content:space-between">
        <span class="card-title">Medicine Stock</span>
        <span style="font-size:.85rem;color:#6b7280">Cost Value: <strong><?= number_format($med_total_cost,0) ?> ৳</strong> | Sell Value: <strong><?= number_format($med_total_sell,0) ?> ৳</strong></span>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($med_stock)): ?><p style="text-align:center;padding:1.5rem;color:#9ca3af">No medicine items.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.5rem .75rem;text-align:left;border-bottom:1px solid #e5e7eb">Item</th>
                <th style="padding:.5rem .75rem;border-bottom:1px solid #e5e7eb">Batch</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Stock</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Cost/Unit</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Stock Value</th>
                <th style="padding:.5rem .75rem;border-bottom:1px solid #e5e7eb">Expiry</th>
            </tr></thead>
            <tbody>
            <?php foreach ($med_stock as $s):
                $is_expired = !empty($s['expiry_date']) && $s['expiry_date']<$today;
                $is_exp_soon = !$is_expired && !empty($s['expiry_date']) && $s['expiry_date']<=date('Y-m-d',strtotime('+30 days'));
                $is_low = (float)$s['reorder_threshold']>0 && (float)$s['quantity']<=(float)$s['reorder_threshold'];
            ?>
            <tr style="border-bottom:1px solid #f3f4f6;background:<?= $is_expired?'#fef2f2':($is_exp_soon?'#fff7ed':($is_low?'#fffbeb':'')) ?>">
                <td style="padding:.45rem .75rem;font-weight:600"><?= e($s['item_name']) ?><?= $is_low?' <span style="background:#fed7aa;color:#9a3412;font-size:.65rem;font-weight:700;padding:.1rem .3rem;border-radius:3px">LOW</span>':'' ?></td>
                <td style="padding:.45rem .75rem;color:#6b7280;font-size:.8rem"><?= e($s['batch_number']??'—') ?></td>
                <td style="padding:.45rem .75rem;text-align:right;font-weight:700;color:<?= (float)$s['quantity']<=0?'#dc2626':'#111827' ?>"><?= number_format((float)$s['quantity'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.45rem .75rem;text-align:right"><?= $s['cost_per_unit']?number_format((float)$s['cost_per_unit'],2):'—' ?></td>
                <td style="padding:.45rem .75rem;text-align:right;font-weight:600"><?= $s['cost_per_unit']?number_format((float)$s['quantity']*(float)$s['cost_per_unit'],0).' ৳':'—' ?></td>
                <td style="padding:.45rem .75rem;color:<?= $is_expired?'#dc2626':($is_exp_soon?'#d97706':'#6b7280') ?>;font-weight:<?= $is_expired||$is_exp_soon?'600':'400' ?>"><?= $s['expiry_date']?e(formatDate($s['expiry_date'])).($is_expired?' ⚠ EXPIRED':($is_exp_soon?' ⚠ Soon':'')):'—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Equipment Value Summary -->
<?php
$eq_summary = $db->query("SELECT category, COUNT(*) AS cnt, SUM(current_value) AS curr_val, SUM(purchase_price) AS pur_val FROM equipment WHERE farm_id={$fid} AND status NOT IN ('sold','disposed') GROUP BY category ORDER BY curr_val DESC")->fetchAll();
$eq_total = $db->query("SELECT COUNT(*) AS cnt, SUM(current_value) AS curr, SUM(purchase_price) AS pur FROM equipment WHERE farm_id={$fid} AND status NOT IN ('sold','disposed')")->fetch();
?>
<div class="card">
    <div class="card-header"><span class="card-title">Equipment Asset Summary (Active)</span></div>
    <div class="card-body" style="padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.5rem .75rem;text-align:left;border-bottom:1px solid #e5e7eb">Category</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Count</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Purchase Cost</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Current Value</th>
                <th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Depreciation</th>
            </tr></thead>
            <tbody>
            <?php foreach ($eq_summary as $e): $depr = (float)($e['pur_val']??0) - (float)($e['curr_val']??0); ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.45rem .75rem;font-weight:500"><?= e($e['category']??'Uncategorized') ?></td>
                <td style="padding:.45rem .75rem;text-align:right"><?= $e['cnt'] ?></td>
                <td style="padding:.45rem .75rem;text-align:right"><?= $e['pur_val']?number_format((float)$e['pur_val'],0).' ৳':'—' ?></td>
                <td style="padding:.45rem .75rem;text-align:right;font-weight:600"><?= $e['curr_val']?number_format((float)$e['curr_val'],0).' ৳':'—' ?></td>
                <td style="padding:.45rem .75rem;text-align:right;color:<?= $depr>0?'#dc2626':'#6b7280' ?>"><?= $depr>0?number_format($depr,0).' ৳':'—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="background:#f9fafb;font-weight:700">
                <td style="padding:.5rem .75rem;border-top:2px solid #e5e7eb">Total (<?= $eq_total['cnt'] ?> items)</td>
                <td style="padding:.5rem .75rem;border-top:2px solid #e5e7eb"></td>
                <td style="padding:.5rem .75rem;text-align:right;border-top:2px solid #e5e7eb"><?= number_format((float)($eq_total['pur']??0),0) ?> ৳</td>
                <td style="padding:.5rem .75rem;text-align:right;border-top:2px solid #e5e7eb;color:#166534"><?= number_format((float)($eq_total['curr']??0),0) ?> ৳</td>
                <td style="padding:.5rem .75rem;text-align:right;border-top:2px solid #e5e7eb;color:#dc2626"><?= number_format(max(0,(float)($eq_total['pur']??0)-(float)($eq_total['curr']??0)),0) ?> ৳</td>
            </tr></tfoot>
        </table>
    </div>
</div>

<?php elseif ($report_type === 'low_stock'): ?>
<!-- ── LOW STOCK ALERT ────────────────────────────────────────────────────────── -->
<?php
$low_feed = $db->query("SELECT * FROM feed_inventory WHERE farm_id={$fid} AND reorder_threshold>0 AND quantity<=reorder_threshold ORDER BY (quantity/reorder_threshold)")->fetchAll();
$low_med  = $db->query("SELECT * FROM medicine_inventory WHERE farm_id={$fid} AND reorder_threshold>0 AND quantity<=reorder_threshold ORDER BY (quantity/reorder_threshold)")->fetchAll();
?>
<?php if (empty($low_feed) && empty($low_med)): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:2rem;text-align:center;color:#166534;font-size:1rem;font-weight:600">
    All stock levels are above reorder thresholds.
</div>
<?php else: ?>

<?php if (!empty($low_feed)): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title" style="color:#c2410c">Low Feed Stock (<?= count($low_feed) ?> items)</span></div>
    <div class="card-body" style="padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#fff7ed"><th style="padding:.5rem .75rem;border-bottom:1px solid #fed7aa">Item</th><th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #fed7aa">Current Stock</th><th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #fed7aa">Reorder At</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fed7aa">Shortage</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fed7aa">Action</th></tr></thead>
            <tbody><?php foreach ($low_feed as $s): $shortage = (float)$s['reorder_threshold'] - (float)$s['quantity']; ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($s['item_name']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right;color:#dc2626;font-weight:700"><?= number_format((float)$s['quantity'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right;color:#6b7280"><?= number_format((float)$s['reorder_threshold'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.5rem .75rem;color:#c2410c;font-weight:600"><?= number_format($shortage,1) ?> <?= e($s['unit']) ?> needed</td>
                <td style="padding:.5rem .75rem"><a href="/modules/inventory/feed.php?tab=purchase&item_id=<?= $s['id'] ?>" class="btn btn-primary btn-sm" style="font-size:.78rem">Purchase Stock</a></td>
            </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($low_med)): ?>
<div class="card">
    <div class="card-header"><span class="card-title" style="color:#c2410c">Low Medicine Stock (<?= count($low_med) ?> items)</span></div>
    <div class="card-body" style="padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#fff7ed"><th style="padding:.5rem .75rem;border-bottom:1px solid #fed7aa">Item</th><th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #fed7aa">Current Stock</th><th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #fed7aa">Reorder At</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fed7aa">Shortage</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fed7aa">Action</th></tr></thead>
            <tbody><?php foreach ($low_med as $s): $shortage = (float)$s['reorder_threshold'] - (float)$s['quantity']; ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($s['item_name']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right;color:#dc2626;font-weight:700"><?= number_format((float)$s['quantity'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right;color:#6b7280"><?= number_format((float)$s['reorder_threshold'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.5rem .75rem;color:#c2410c;font-weight:600"><?= number_format($shortage,1) ?> <?= e($s['unit']) ?> needed</td>
                <td style="padding:.5rem .75rem"><a href="/modules/inventory/medicine.php?tab=purchase&item_id=<?= $s['id'] ?>" class="btn btn-primary btn-sm" style="font-size:.78rem">Purchase Stock</a></td>
            </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($report_type === 'expiry'): ?>
<!-- ── EXPIRING MEDICINES ──────────────────────────────────────────────────────── -->
<?php
$expired = $db->query("SELECT * FROM medicine_inventory WHERE farm_id={$fid} AND expiry_date IS NOT NULL AND expiry_date < '{$today}' ORDER BY expiry_date")->fetchAll();
$exp_30  = $db->query("SELECT * FROM medicine_inventory WHERE farm_id={$fid} AND expiry_date IS NOT NULL AND expiry_date BETWEEN '{$today}' AND '".date('Y-m-d',strtotime('+30 days'))."' ORDER BY expiry_date")->fetchAll();
$exp_90  = $db->query("SELECT * FROM medicine_inventory WHERE farm_id={$fid} AND expiry_date IS NOT NULL AND expiry_date BETWEEN '".date('Y-m-d',strtotime('+31 days'))."' AND '".date('Y-m-d',strtotime('+90 days'))."' ORDER BY expiry_date")->fetchAll();
?>

<?php if (!empty($expired)): ?>
<div class="card" style="margin-bottom:1.5rem;border-color:#fca5a5">
    <div class="card-header" style="background:#fef2f2"><span class="card-title" style="color:#dc2626">Expired Medicines (<?= count($expired) ?>)</span></div>
    <div class="card-body" style="padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#fef2f2"><th style="padding:.5rem .75rem;border-bottom:1px solid #fca5a5">Item</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fca5a5">Batch</th><th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #fca5a5">Qty</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fca5a5">Expired On</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fca5a5">Days Ago</th></tr></thead>
            <tbody><?php foreach ($expired as $m): $days = (int)((strtotime($today)-strtotime($m['expiry_date']))/86400); ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($m['item_name']) ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280"><?= e($m['batch_number']??'—') ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format((float)$m['quantity'],1) ?> <?= e($m['unit']) ?></td>
                <td style="padding:.5rem .75rem;color:#dc2626;font-weight:600"><?= e(formatDate($m['expiry_date'])) ?></td>
                <td style="padding:.5rem .75rem;color:#dc2626"><?= $days ?> days ago</td>
            </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($exp_30)): ?>
<div class="card" style="margin-bottom:1.5rem;border-color:#fde68a">
    <div class="card-header" style="background:#fff7ed"><span class="card-title" style="color:#d97706">Expiring Within 30 Days (<?= count($exp_30) ?>)</span></div>
    <div class="card-body" style="padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#fff7ed"><th style="padding:.5rem .75rem;border-bottom:1px solid #fde68a">Item</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fde68a">Batch</th><th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #fde68a">Qty</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fde68a">Expires</th><th style="padding:.5rem .75rem;border-bottom:1px solid #fde68a">Days Left</th></tr></thead>
            <tbody><?php foreach ($exp_30 as $m): $days = (int)((strtotime($m['expiry_date'])-strtotime($today))/86400); ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($m['item_name']) ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280"><?= e($m['batch_number']??'—') ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format((float)$m['quantity'],1) ?> <?= e($m['unit']) ?></td>
                <td style="padding:.5rem .75rem;color:#d97706;font-weight:600"><?= e(formatDate($m['expiry_date'])) ?></td>
                <td style="padding:.5rem .75rem;color:#d97706"><?= $days ?> days</td>
            </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($exp_90)): ?>
<div class="card">
    <div class="card-header"><span class="card-title">Expiring 31–90 Days (<?= count($exp_90) ?>)</span></div>
    <div class="card-body" style="padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb"><th style="padding:.5rem .75rem;border-bottom:1px solid #e5e7eb">Item</th><th style="padding:.5rem .75rem;border-bottom:1px solid #e5e7eb">Batch</th><th style="padding:.5rem .75rem;text-align:right;border-bottom:1px solid #e5e7eb">Qty</th><th style="padding:.5rem .75rem;border-bottom:1px solid #e5e7eb">Expires</th><th style="padding:.5rem .75rem;border-bottom:1px solid #e5e7eb">Days Left</th></tr></thead>
            <tbody><?php foreach ($exp_90 as $m): $days = (int)((strtotime($m['expiry_date'])-strtotime($today))/86400); ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($m['item_name']) ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280"><?= e($m['batch_number']??'—') ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format((float)$m['quantity'],1) ?> <?= e($m['unit']) ?></td>
                <td style="padding:.5rem .75rem"><?= e(formatDate($m['expiry_date'])) ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280"><?= $days ?> days</td>
            </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($expired) && empty($exp_30) && empty($exp_90)): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:2rem;text-align:center;color:#166534;font-weight:600">
    No expiring or expired medicines found.
</div>
<?php endif; ?>

<?php elseif ($report_type === 'movement' && $inv_tx_exists): ?>
<!-- ── INVENTORY MOVEMENT ─────────────────────────────────────────────────────── -->
<?php
$type_filter = $_GET['item_type'] ?? '';
$where = "WHERE it.farm_id={$fid} AND it.created_at BETWEEN '{$filter_from} 00:00:00' AND '{$filter_to} 23:59:59'";
if ($type_filter && in_array($type_filter, ['feed','medicine','equipment'], true)) {
    $where .= " AND it.item_type = " . $db->quote($type_filter);
}
$movements = $db->query("SELECT it.*, u.name AS recorder FROM inventory_transactions it LEFT JOIN users u ON u.id=it.recorded_by {$where} ORDER BY it.created_at DESC LIMIT 200")->fetchAll();
?>
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <a href="?report=movement&from=<?= $filter_from ?>&to=<?= $filter_to ?>" class="btn <?= !$type_filter?'btn-primary':'btn-secondary' ?> btn-sm">All Types</a>
    <a href="?report=movement&item_type=feed&from=<?= $filter_from ?>&to=<?= $filter_to ?>" class="btn <?= $type_filter==='feed'?'btn-primary':'btn-secondary' ?> btn-sm">Feed</a>
    <a href="?report=movement&item_type=medicine&from=<?= $filter_from ?>&to=<?= $filter_to ?>" class="btn <?= $type_filter==='medicine'?'btn-primary':'btn-secondary' ?> btn-sm">Medicine</a>
    <a href="?report=movement&item_type=equipment&from=<?= $filter_from ?>&to=<?= $filter_to ?>" class="btn <?= $type_filter==='equipment'?'btn-primary':'btn-secondary' ?> btn-sm">Equipment</a>
</div>
<div class="card">
    <div class="card-header"><span class="card-title">Inventory Movements</span><span style="font-size:.82rem;color:#6b7280;margin-left:auto"><?= count($movements) ?> records</span></div>
    <div class="card-body" style="padding:0">
        <?php if (empty($movements)): ?><p style="text-align:center;padding:2rem;color:#9ca3af">No movements recorded in this period.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.84rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Type</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Item</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Action</th>
                <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Qty</th>
                <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Value</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">By</th>
            </tr></thead>
            <tbody><?php foreach ($movements as $m):
                $is_in = in_array($m['transaction_type'],['purchase','adjustment_add'],true);
                $type_badges=['purchase'=>['#dcfce7','#166534','Purchase'],'sale'=>['#fef2f2','#dc2626','Sale'],'adjustment_add'=>['#dbeafe','#1e40af','+Adj'],'adjustment_remove'=>['#fef3c7','#92400e','-Adj'],'use'=>['#f3e8ff','#7c3aed','Used'],'waste'=>['#fee2e2','#991b1b','Waste']];
                [$bg,$col,$lbl]=$type_badges[$m['transaction_type']]??['#f3f4f6','#374151',$m['transaction_type']];
                $it_labels=['feed'=>'Feed','medicine'=>'Medicine','equipment'=>'Equipment'];
            ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;font-size:.8rem;white-space:nowrap"><?= e(date('d M Y H:i',strtotime($m['created_at']))) ?></td>
                <td style="padding:.5rem .75rem"><span style="font-size:.7rem;font-weight:600;padding:.1rem .4rem;border-radius:4px;background:#f3f4f6;color:#374151"><?= $it_labels[$m['item_type']]??$m['item_type'] ?></span></td>
                <td style="padding:.5rem .75rem;font-weight:500"><?= e($m['item_name']) ?></td>
                <td style="padding:.5rem .75rem"><span style="background:<?= $bg ?>;color:<?= $col ?>;font-size:.7rem;font-weight:700;padding:.15rem .45rem;border-radius:4px"><?= $lbl ?></span></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:<?= $is_in?'#166534':'#dc2626' ?>"><?= $is_in?'+':'-' ?><?= number_format((float)$m['quantity'],1) ?> <?= e($m['unit']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= $m['total_value']?number_format((float)$m['total_value'],0).' ৳':'—' ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280;font-size:.8rem"><?= e($m['recorder']??'—') ?></td>
            </tr>
            <?php endforeach; ?></tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($report_type === 'sales'): ?>
<!-- ── SALES REPORT ───────────────────────────────────────────────────────────── -->
<?php
$feed_sales = $db->query("SELECT 'feed' AS type, fs.item_name, fs.quantity, fs.unit, fs.price_per_unit, fs.total_amount, fs.buyer_name, fs.buyer_phone, fs.payment_method, fs.payment_status, fs.sale_date FROM feed_sales fs WHERE fs.farm_id={$fid} AND fs.sale_date BETWEEN '{$filter_from}' AND '{$filter_to}'")->fetchAll();
$med_sales  = $db->query("SELECT 'medicine' AS type, ms.item_name, ms.quantity, ms.unit, ms.price_per_unit, ms.total_amount, ms.buyer_name, ms.buyer_phone, ms.payment_method, ms.payment_status, ms.sale_date FROM medicine_sales ms WHERE ms.farm_id={$fid} AND ms.sale_date BETWEEN '{$filter_from}' AND '{$filter_to}'")->fetchAll();
$eq_sales   = $db->query("SELECT 'equipment' AS type, e.name AS item_name, 1 AS quantity, 'unit' AS unit, es.sale_price AS price_per_unit, es.sale_price AS total_amount, es.buyer_name, es.buyer_phone, es.payment_method, es.payment_status, es.sale_date FROM equipment_sales es JOIN equipment e ON e.id=es.equipment_id WHERE es.farm_id={$fid} AND es.sale_date BETWEEN '{$filter_from}' AND '{$filter_to}'")->fetchAll();
$all_sales = array_merge($feed_sales, $med_sales, $eq_sales);
usort($all_sales, fn($a,$b) => strcmp($b['sale_date'],$a['sale_date']));
$grand_total = array_sum(array_column($all_sales,'total_amount'));
$type_labels = ['feed'=>'Feed','medicine'=>'Medicine','equipment'=>'Equipment'];
$type_colors = ['feed'=>['#f0fdf4','#166534'],'medicine'=>['#eff6ff','#1e40af'],'equipment'=>['#fef3c7','#92400e']];
?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.75rem 1.25rem;margin-bottom:1rem;display:flex;gap:2rem;align-items:center;flex-wrap:wrap">
    <span><?= count($all_sales) ?> records</span>
    <strong style="color:#166534;font-size:1rem">Total: <?= number_format($grand_total,2) ?> ৳</strong>
    <span style="color:#6b7280;font-size:.85rem;margin-left:auto"><?= e(date('d M Y',strtotime($filter_from))) ?> — <?= e(date('d M Y',strtotime($filter_to))) ?></span>
</div>
<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (empty($all_sales)): ?><p style="text-align:center;padding:2rem;color:#9ca3af">No inventory sales in this period.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.84rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Type</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Item</th>
                <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Qty</th>
                <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Total</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Buyer</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Payment</th>
            </tr></thead>
            <tbody><?php foreach ($all_sales as $s): [$tbg,$tcol]=$type_colors[$s['type']]??['#f3f4f6','#374151']; ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem"><?= e(formatDate($s['sale_date'])) ?></td>
                <td style="padding:.5rem .75rem"><span style="background:<?= $tbg ?>;color:<?= $tcol ?>;font-size:.7rem;font-weight:700;padding:.15rem .45rem;border-radius:4px"><?= $type_labels[$s['type']]??$s['type'] ?></span></td>
                <td style="padding:.5rem .75rem;font-weight:500"><?= e($s['item_name']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format((float)$s['quantity'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:#166534"><?= number_format((float)$s['total_amount'],0) ?> ৳</td>
                <td style="padding:.5rem .75rem">
                    <?= e($s['buyer_name']??'—') ?>
                    <?php if(!empty($s['buyer_phone'])): ?><br><small style="color:#6b7280"><?= e($s['buyer_phone']) ?></small><?php endif; ?>
                </td>
                <td style="padding:.5rem .75rem">
                    <?php $ps=$s['payment_status']??'paid'; ?>
                    <span style="font-size:.72rem;font-weight:700;padding:.1rem .4rem;border-radius:4px;background:<?= $ps==='paid'?'#dcfce7':($ps==='pending'?'#fef3c7':'#dbeafe') ?>;color:<?= $ps==='paid'?'#166534':($ps==='pending'?'#92400e':'#1e40af') ?>"><?= strtoupper($ps) ?></span>
                </td>
            </tr>
            <?php endforeach; ?></tbody>
            <tfoot><tr style="background:#f9fafb;font-weight:700"><td colspan="4" style="padding:.5rem .75rem;border-top:2px solid #e5e7eb">Grand Total</td><td style="padding:.5rem .75rem;text-align:right;border-top:2px solid #e5e7eb;color:#166534;font-size:1rem"><?= number_format($grand_total,2) ?> ৳</td><td colspan="2" style="border-top:2px solid #e5e7eb"></td></tr></tfoot>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($report_type === 'profit'): ?>
<!-- ── PROFIT & LOSS ──────────────────────────────────────────────────────────── -->
<?php
$cols_fs = array_column($db->query("SHOW COLUMNS FROM feed_sales")->fetchAll(),'Field');
$cols_ms = array_column($db->query("SHOW COLUMNS FROM medicine_sales")->fetchAll(),'Field');
$feed_profit_total = 0; $med_profit_total = 0;
if (in_array('profit',$cols_fs)) {
    $r = $db->query("SELECT COALESCE(SUM(profit),0) AS p, COALESCE(SUM(total_amount),0) AS rev FROM feed_sales WHERE farm_id={$fid} AND sale_date BETWEEN '{$filter_from}' AND '{$filter_to}'")->fetch();
    $feed_profit_total = (float)$r['p']; $feed_rev = (float)$r['rev'];
} else { $feed_rev = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM feed_sales WHERE farm_id={$fid} AND sale_date BETWEEN '{$filter_from}' AND '{$filter_to}'")->fetchColumn(); }
if (in_array('profit',$cols_ms)) {
    $r = $db->query("SELECT COALESCE(SUM(profit),0) AS p, COALESCE(SUM(total_amount),0) AS rev FROM medicine_sales WHERE farm_id={$fid} AND sale_date BETWEEN '{$filter_from}' AND '{$filter_to}'")->fetch();
    $med_profit_total = (float)$r['p']; $med_rev = (float)$r['rev'];
} else { $med_rev = (float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM medicine_sales WHERE farm_id={$fid} AND sale_date BETWEEN '{$filter_from}' AND '{$filter_to}'")->fetchColumn(); }
$r = $db->query("SELECT COALESCE(SUM(sale_price),0) AS rev, COALESCE(SUM(profit_loss),0) AS p FROM equipment_sales WHERE farm_id={$fid} AND sale_date BETWEEN '{$filter_from}' AND '{$filter_to}'")->fetch();
$eq_rev = (float)$r['rev']; $eq_profit = (float)$r['p'];
$total_rev = ($feed_rev??0) + ($med_rev??0) + $eq_rev;
$total_profit = $feed_profit_total + $med_profit_total + $eq_profit;
?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1.25rem;text-align:center">
        <div style="font-size:1.3rem;font-weight:700;color:#166534"><?= number_format($total_rev,0) ?> ৳</div>
        <div style="font-size:.8rem;color:#6b7280">Total Revenue</div>
    </div>
    <div style="background:<?= $total_profit>=0?'#f0fdf4':'#fef2f2' ?>;border:1px solid <?= $total_profit>=0?'#86efac':'#fca5a5' ?>;border-radius:10px;padding:1.25rem;text-align:center">
        <div style="font-size:1.3rem;font-weight:700;color:<?= $total_profit>=0?'#166534':'#dc2626' ?>"><?= ($total_profit>=0?'+':'').number_format($total_profit,0) ?> ৳</div>
        <div style="font-size:.8rem;color:#6b7280">Net Profit/Loss</div>
    </div>
</div>
<div class="card">
    <div class="card-header"><span class="card-title">Profit Breakdown</span></div>
    <div class="card-body" style="padding:0">
        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
            <thead><tr style="background:#f9fafb"><th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Category</th><th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Revenue</th><th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Profit / Loss</th><th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Margin</th></tr></thead>
            <tbody>
            <?php foreach ([['Feed Sales', $feed_rev??0, $feed_profit_total], ['Medicine Sales', $med_rev??0, $med_profit_total], ['Equipment Sales', $eq_rev, $eq_profit]] as [$lbl,$rev,$prof]): $margin = $rev>0?round($prof/$rev*100,1):0; ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.6rem .75rem;font-weight:500"><?= $lbl ?></td>
                <td style="padding:.6rem .75rem;text-align:right"><?= number_format($rev,0) ?> ৳</td>
                <td style="padding:.6rem .75rem;text-align:right;font-weight:700;color:<?= $prof>=0?'#166534':'#dc2626' ?>"><?= ($prof>=0?'+':'').number_format($prof,0) ?> ৳</td>
                <td style="padding:.6rem .75rem;text-align:right;color:<?= $margin>=0?'#166534':'#dc2626' ?>"><?= $margin ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="background:#f9fafb;font-weight:700"><td style="padding:.6rem .75rem;border-top:2px solid #e5e7eb">Total</td><td style="padding:.6rem .75rem;text-align:right;border-top:2px solid #e5e7eb"><?= number_format($total_rev,0) ?> ৳</td><td style="padding:.6rem .75rem;text-align:right;border-top:2px solid #e5e7eb;color:<?= $total_profit>=0?'#166534':'#dc2626' ?>"><?= ($total_profit>=0?'+':'').number_format($total_profit,0) ?> ৳</td><td style="border-top:2px solid #e5e7eb"></td></tr></tfoot>
        </table>
    </div>
</div>

<?php elseif ($report_type === 'buyers'): ?>
<!-- ── TOP BUYERS ─────────────────────────────────────────────────────────────── -->
<?php
$buyers = $db->query("
    SELECT buyer_name, buyer_phone, 'Feed' AS source, SUM(total_amount) AS total, COUNT(*) AS transactions
    FROM feed_sales WHERE farm_id={$fid} AND buyer_name IS NOT NULL AND sale_date BETWEEN '{$filter_from}' AND '{$filter_to}' GROUP BY buyer_name, buyer_phone
    UNION ALL
    SELECT buyer_name, buyer_phone, 'Medicine', SUM(total_amount), COUNT(*)
    FROM medicine_sales WHERE farm_id={$fid} AND buyer_name IS NOT NULL AND sale_date BETWEEN '{$filter_from}' AND '{$filter_to}' GROUP BY buyer_name, buyer_phone
    UNION ALL
    SELECT buyer_name, buyer_phone, 'Equipment', SUM(sale_price), COUNT(*)
    FROM equipment_sales WHERE farm_id={$fid} AND buyer_name IS NOT NULL AND sale_date BETWEEN '{$filter_from}' AND '{$filter_to}' GROUP BY buyer_name, buyer_phone
    ORDER BY total DESC
    LIMIT 50
")->fetchAll();
?>
<div class="card">
    <div class="card-header"><span class="card-title">Top Buyers</span></div>
    <div class="card-body" style="padding:0">
        <?php if (empty($buyers)): ?><p style="text-align:center;padding:2rem;color:#9ca3af">No buyer data in this period.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb"><th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Buyer</th><th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Category</th><th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Transactions</th><th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Total Spent</th></tr></thead>
            <tbody><?php foreach ($buyers as $b): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($b['buyer_name']) ?><?php if($b['buyer_phone']): ?><br><small style="color:#6b7280;font-weight:400"><?= e($b['buyer_phone']) ?></small><?php endif; ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280"><?= e($b['source']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= $b['transactions'] ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:#166534"><?= number_format((float)$b['total'],0) ?> ৳</td>
            </tr>
            <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
