<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireModule('sales');
requireRole(['admin', 'manager', 'accountant']);

$db  = getDB();
$fid = fid();

$type_filter = $_GET['sale_type'] ?? '';
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? date('Y-m-d');

$type_labels = ['skin'=>'Skin','bones'=>'Bones','fat'=>'Fat/Tallow','organs'=>'Organs','dung'=>'Manure/Dung','semen'=>'Semen','breeding_service'=>'Breeding Service','other'=>'Other'];

$q = "SELECT bps.*, c.tag_number FROM cow_byproduct_sales bps JOIN cows c ON c.id=bps.cow_id WHERE bps.farm_id=? AND bps.sale_date BETWEEN ? AND ?";
$params = [$fid, $filter_from, $filter_to];
if ($type_filter !== '') { $q .= " AND bps.sale_type = ?"; $params[] = $type_filter; }
$q .= " ORDER BY bps.sale_date DESC";
$stmt = $db->prepare($q);
$stmt->execute($params);
$sales = $stmt->fetchAll();
$total = array_sum(array_column($sales, 'total_amount'));

$page_title = 'Byproduct Sales';
$active_nav = 'byproduct_sales';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div><h1 class="page-title">Byproduct Sales</h1><p class="page-subtitle">Skin, manure, bones, fat, reproductive services</p></div>
    <a href="/modules/business/reports.php?type=byproduct" class="btn btn-secondary">Full Report</a>
</div>

<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <a href="?from=<?= $filter_from ?>&to=<?= $filter_to ?>" class="btn <?= $type_filter==='' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">All</a>
    <?php foreach ($type_labels as $v => $l): ?>
    <a href="?sale_type=<?= $v ?>&from=<?= $filter_from ?>&to=<?= $filter_to ?>" class="btn <?= $type_filter===$v ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.75rem 1.25rem;margin-bottom:1rem;display:flex;gap:2rem;align-items:center;flex-wrap:wrap">
    <span><?= count($sales) ?> records</span>
    <strong style="color:#166534">Total: <?= number_format($total, 2) ?> ৳</strong>
    <form method="GET" style="display:flex;gap:.4rem;margin-left:auto">
        <?php if ($type_filter): ?><input type="hidden" name="sale_type" value="<?= e($type_filter) ?>"><?php endif; ?>
        <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:130px">
        <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:130px">
        <button class="btn btn-secondary btn-sm">Filter</button>
    </form>
</div>

<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (empty($sales)): ?><p style="text-align:center;padding:2rem;color:#9ca3af">No byproduct sales found. Visit a cow's page and click "Sell Options" to record one.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Type</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Cow</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb;text-align:right">Qty</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb;text-align:right">Price/U</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb;text-align:right">Total (৳)</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Buyer</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sales as $s): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem"><?= e(formatDate($s['sale_date'])) ?></td>
                <td style="padding:.5rem .75rem"><span style="background:#eff6ff;color:#1e40af;padding:.1rem .45rem;border-radius:999px;font-size:.72rem;font-weight:600"><?= e($type_labels[$s['sale_type']] ?? $s['sale_type']) ?></span></td>
                <td style="padding:.5rem .75rem"><a href="/modules/cows/view.php?id=<?= $s['cow_id'] ?>">#<?= e($s['tag_number']) ?></a></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format($s['quantity'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format($s['price_per_unit'],2) ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:#166534"><?= number_format($s['total_amount'],2) ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280"><?= e($s['buyer_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
