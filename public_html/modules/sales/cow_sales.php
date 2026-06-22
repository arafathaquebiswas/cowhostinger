<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireModule('sales');
requireRole(['admin', 'manager', 'accountant']);

$db  = getDB();
$fid = fid();

$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? date('Y-m-d');

$sales = $db->prepare("SELECT cs.*, c.tag_number, c.breed FROM cow_sales cs JOIN cows c ON c.id=cs.cow_id WHERE cs.farm_id=? AND cs.sale_date BETWEEN ? AND ? ORDER BY cs.sale_date DESC");
$sales->execute([$fid, $filter_from, $filter_to]);
$sales = $sales->fetchAll();
$total = array_sum(array_column($sales, 'sale_price'));

$page_title = 'Cow Sales';
$active_nav = 'cow_sales_list';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div><h1 class="page-title">Cow Sales</h1><p class="page-subtitle">History of all live cow sales</p></div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/cows/index.php" class="btn btn-primary">Go to Cows → Sell</a>
        <a href="/modules/business/reports.php?type=cow" class="btn btn-secondary">Full Report</a>
    </div>
</div>

<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:.75rem 1.25rem;margin-bottom:1rem;display:flex;gap:2rem;align-items:center">
    <span><?= count($sales) ?> sales found</span>
    <strong style="color:#166534">Total Revenue: <?= number_format($total, 2) ?> ৳</strong>
    <form method="GET" style="display:flex;gap:.4rem;margin-left:auto">
        <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:130px">
        <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:130px">
        <button class="btn btn-secondary btn-sm">Filter</button>
    </form>
</div>

<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (empty($sales)): ?><p style="text-align:center;padding:2rem;color:#9ca3af">No cow sales in this period. <a href="/modules/cows/index.php">Go to a cow's page</a> and click "Sell Options" to record a sale.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb;text-align:left">Date</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Cow</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Buyer</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb;text-align:right">Weight</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb;text-align:right">Price (৳)</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Notes</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sales as $s): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem"><?= e(formatDate($s['sale_date'])) ?></td>
                <td style="padding:.5rem .75rem"><a href="/modules/cows/view.php?id=<?= $s['cow_id'] ?>">#<?= e($s['tag_number']) ?> <span style="color:#6b7280"><?= e($s['breed']) ?></span></a></td>
                <td style="padding:.5rem .75rem;font-weight:500"><?= e($s['buyer_name']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= $s['weight_at_sale'] ? e($s['weight_at_sale']) . ' kg' : '—' ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:#166534"><?= number_format($s['sale_price'], 2) ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280"><?= e($s['notes'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
