<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireModule('sales');
requireRole(['admin', 'manager', 'accountant']);

$db    = getDB();
$today = date('Y-m-d');
$fid   = fid();

$type       = $_GET['type'] ?? 'all';
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;

$types = [
    'all'        => 'All Sales',
    'cow'        => 'Cow Sales',
    'meat'       => 'Meat Sales',
    'byproduct'  => 'Byproducts (Skin/Dung/etc)',
    'milk'       => 'Milk Sales',
    'feed'       => 'Feed Sales',
    'medicine'   => 'Medicine Sales',
    'equipment'  => 'Equipment Sales',
    'family'     => 'Family Transfers',
    'family_milk'=> 'Family Milk Consumption',
];

// ── Fetch records based on type ───────────────────────────────────────────────
$rows = [];

function addRows(array &$rows, string $cat, array $data): void {
    foreach ($data as $r) { $r['_category'] = $cat; $rows[] = $r; }
}

if (in_array($type, ['all','cow'])) {
    $s = $db->prepare("SELECT 'cow_sale' AS _type, sale_date AS date, buyer_name AS party, sale_price AS amount, notes FROM cow_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? ORDER BY sale_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Cow Sale', $s->fetchAll());
}
if (in_array($type, ['all','meat'])) {
    $s = $db->prepare("SELECT 'meat_sale' AS _type, sale_date AS date, CONCAT(kg_sold,' kg @ ',price_per_kg) AS party, total_revenue AS amount, notes FROM meat_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? ORDER BY sale_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Meat Sale', $s->fetchAll());
}
if (in_array($type, ['all','byproduct'])) {
    $s = $db->prepare("SELECT CONCAT('byp_',sale_type) AS _type, sale_date AS date, COALESCE(buyer_name,'—') AS party, total_amount AS amount, CONCAT(sale_type,' — ',COALESCE(description,'')) AS notes FROM cow_byproduct_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? ORDER BY sale_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Byproduct Sale', $s->fetchAll());
}
$milk_exists = !empty($db->query("SHOW TABLES LIKE 'milk_sales'")->fetchAll());
if ($milk_exists && in_array($type, ['all','milk'])) {
    $s = $db->prepare("SELECT 'milk_sale' AS _type, sale_date AS date, customer_name AS party, total_amount AS amount, CONCAT(liters_sold,'L @ ',price_per_liter,'/L') AS notes FROM milk_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? ORDER BY sale_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Milk Sale', $s->fetchAll());
}
$feed_exists = !empty($db->query("SHOW TABLES LIKE 'feed_sales'")->fetchAll());
if ($feed_exists && in_array($type, ['all','feed'])) {
    $s = $db->prepare("SELECT 'feed_sale' AS _type, sale_date AS date, COALESCE(buyer_name,'—') AS party, total_amount AS amount, item_name AS notes FROM feed_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? ORDER BY sale_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Feed Sale', $s->fetchAll());
}
$med_exists = !empty($db->query("SHOW TABLES LIKE 'medicine_sales'")->fetchAll());
if ($med_exists && in_array($type, ['all','medicine'])) {
    $s = $db->prepare("SELECT 'med_sale' AS _type, sale_date AS date, COALESCE(buyer_name,'—') AS party, total_amount AS amount, item_name AS notes FROM medicine_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? ORDER BY sale_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Medicine Sale', $s->fetchAll());
}
if (in_array($type, ['all','equipment'])) {
    $s = $db->prepare("SELECT 'eq_sale' AS _type, sale_date AS date, COALESCE(buyer_name,'—') AS party, sale_price AS amount, notes FROM equipment_sales WHERE farm_id=? AND sale_date BETWEEN ? AND ? ORDER BY sale_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Equipment Sale', $s->fetchAll());
}
$cft_exists = !empty($db->query("SHOW TABLES LIKE 'cow_family_transfers'")->fetchAll());
if ($cft_exists && in_array($type, ['all','family'])) {
    $s = $db->prepare("SELECT 'family_tr' AS _type, transfer_date AS date, COALESCE(recipient_name,'Family') AS party, estimated_value AS amount, CONCAT(transfer_type) AS notes FROM cow_family_transfers WHERE farm_id=? AND transfer_date BETWEEN ? AND ? ORDER BY transfer_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Family Transfer', $s->fetchAll());
}
$fc_exists = !empty($db->query("SHOW TABLES LIKE 'family_consumption'")->fetchAll());
if ($fc_exists && in_array($type, ['all','family_milk'])) {
    $s = $db->prepare("SELECT 'family_cons' AS _type, consumption_date AS date, item_type AS party, estimated_value AS amount, CONCAT(quantity,' ',unit) AS notes FROM family_consumption WHERE farm_id=? AND consumption_date BETWEEN ? AND ? ORDER BY consumption_date DESC");
    $s->execute([$fid, $filter_from, $filter_to]);
    addRows($rows, 'Family Consumption', $s->fetchAll());
}

// Sort all rows by date desc
usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));
$grand_total = array_sum(array_column($rows, 'amount'));

$page_title = 'Sales Reports';
$active_nav = 'sales_reports';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div><h1 class="page-title">Sales Reports</h1><p class="page-subtitle">All sales across every category</p></div>
    <a href="/modules/business/revenue.php" class="btn btn-secondary">Revenue Dashboard</a>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-body" style="padding:.75rem 1rem">
        <form method="GET" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
            <select name="type" class="form-control" style="width:200px">
                <?php foreach ($types as $v => $l): ?>
                <option value="<?= $v ?>" <?= $type===$v ? 'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:130px">
            <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:130px">
            <button class="btn btn-primary btn-sm">Apply Filter</button>
            <a href="?type=all&from=<?= date('Y-01-01') ?>&to=<?= $today ?>" class="btn btn-secondary btn-sm">This Year</a>
            <a href="?type=all&from=<?= date('Y-m-01') ?>&to=<?= $today ?>" class="btn btn-secondary btn-sm">This Month</a>
        </form>
    </div>
</div>

<?php if (!empty($rows)): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.75rem 1.25rem;margin-bottom:1rem;display:flex;gap:2rem;align-items:center;flex-wrap:wrap">
    <span style="font-weight:600;color:#166534;font-size:1rem"><?= count($rows) ?> records found</span>
    <span style="font-size:1.1rem;font-weight:700;color:#166534">Total: <?= number_format($grand_total, 2) ?> ৳</span>
    <span style="font-size:.85rem;color:#6b7280;margin-left:auto"><?= e(date('d M Y', strtotime($filter_from))) ?> — <?= e(date('d M Y', strtotime($filter_to))) ?></span>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (empty($rows)): ?>
        <p style="text-align:center;padding:2.5rem;color:#9ca3af">No records found for the selected filters.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Category</th>
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Party / Details</th>
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Notes</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Amount (৳)</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;white-space:nowrap"><?= e(formatDate($r['date'])) ?></td>
                <td style="padding:.5rem .75rem">
                    <span style="background:#eff6ff;color:#1e40af;padding:.15rem .5rem;border-radius:999px;font-size:.72rem;font-weight:600"><?= e($r['_category']) ?></span>
                </td>
                <td style="padding:.5rem .75rem;font-weight:500"><?= e($r['party']) ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['notes'] ?? '—') ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:<?= (float)$r['amount'] > 0 ? '#166534' : '#6b7280' ?>"><?= number_format((float)$r['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#f9fafb;font-weight:700">
                    <td colspan="4" style="padding:.6rem .75rem;border-top:2px solid #e5e7eb">Grand Total</td>
                    <td style="padding:.6rem .75rem;text-align:right;border-top:2px solid #e5e7eb;color:#166534;font-size:1rem"><?= number_format($grand_total, 2) ?> ৳</td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
