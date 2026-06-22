<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireModule('feed_medicine');
requireRole(['admin', 'manager', 'accountant']);

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/sales/feed_sales.php');
    }
    $action = $_POST['action'] ?? 'add';
    if ($action === 'delete') {
        $db->prepare("DELETE FROM feed_sales WHERE id = ? AND farm_id = ?")->execute([(int)$_POST['sale_id'], fid()]);
        flashMessage('success', 'Sale record deleted.');
        redirect('/modules/sales/feed_sales.php');
    }

    $feed_id   = (int)($_POST['feed_item_id'] ?? 0) ?: null;
    $item_name = sanitize($_POST['item_name'] ?? '');
    $qty       = (float)($_POST['quantity'] ?? 0);
    $unit      = sanitize($_POST['unit'] ?? 'kg');
    $ppu       = (float)($_POST['price_per_unit'] ?? 0);
    $total     = round($qty * $ppu, 2);
    $buyer     = sanitize($_POST['buyer_name'] ?? '');
    $sale_date = trim($_POST['sale_date'] ?? '');
    $notes     = sanitize($_POST['notes'] ?? '');

    // Auto-fill name from selected inventory item
    if ($feed_id) {
        $fn = $db->prepare("SELECT item_name, unit, quantity FROM feed_inventory WHERE id = ? AND farm_id = ?");
        $fn->execute([$feed_id, fid()]);
        $fn_row = $fn->fetch();
        if ($fn_row) {
            if ($item_name === '') $item_name = $fn_row['item_name'];
            if ($unit === 'kg') $unit = $fn_row['unit'];
        }
    }

    if ($item_name === '') $errors[] = 'Item name is required.';
    if ($qty <= 0)          $errors[] = 'Quantity must be greater than zero.';
    if ($ppu <= 0)          $errors[] = 'Price per unit must be greater than zero.';
    if ($sale_date === '')  $errors[] = 'Sale date is required.';

    // Stock check
    if ($feed_id && empty($errors)) {
        $stock_check = $db->prepare("SELECT quantity FROM feed_inventory WHERE id = ? AND farm_id = ?");
        $stock_check->execute([$feed_id, fid()]);
        $stock = $stock_check->fetchColumn();
        if ($stock !== false && $qty > (float)$stock) {
            $errors[] = "Insufficient stock. Available: {$stock} {$unit}.";
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO feed_sales (farm_id,feed_item_id,item_name,quantity,unit,price_per_unit,total_amount,buyer_name,sale_date,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $feed_id, $item_name, $qty, $unit, $ppu, $total, $buyer ?: null, $sale_date, $notes ?: null, $uid]);
            $sale_id = (int)$db->lastInsertId();

            // Reduce stock
            if ($feed_id) {
                $db->prepare("UPDATE feed_inventory SET quantity = quantity - ? WHERE id = ? AND farm_id = ?")->execute([$qty, $feed_id, fid()]);
            }

            // Finance record
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'income', 'Feed Sales', $total, 'feed', $sale_id, $sale_date, $uid, "{$qty} {$unit} of {$item_name}" . ($buyer ? " — {$buyer}" : '')]);

            auditLog($uid, 'FEED_SALE', 'feed_sales', $sale_id, [], ['item' => $item_name, 'qty' => $qty, 'total' => $total]);
            $db->commit();
            flashMessage('success', "Feed sale of {$qty} {$unit} {$item_name} recorded for " . number_format($total, 2) . " BDT.");
            redirect('/modules/sales/feed_sales.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[feed_sales] ' . $e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$feed_items = $db->prepare("SELECT id, item_name, quantity, unit FROM feed_inventory WHERE farm_id = ? AND quantity > 0 ORDER BY item_name");
$feed_items->execute([fid()]);
$feed_items = $feed_items->fetchAll();

$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;
$sales = $db->prepare("SELECT fs.*, u.name AS recorder FROM feed_sales fs LEFT JOIN users u ON u.id=fs.recorded_by WHERE fs.farm_id=? AND fs.sale_date BETWEEN ? AND ? ORDER BY fs.sale_date DESC");
$sales->execute([fid(), $filter_from, $filter_to]);
$sales = $sales->fetchAll();
$total_rev = array_sum(array_column($sales, 'total_amount'));

$page_title = 'Feed Sales';
$active_nav = 'feed_sales';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Feed Sales</h1><p class="page-subtitle">Sell excess feed inventory</p></div>
    <a href="/modules/feed_medicine/index.php" class="btn btn-secondary">← Feed Stock</a>
</div>
<?php if (!empty($errors)): ?><div class="alert alert-danger" style="margin-bottom:1rem"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:1.5rem;align-items:start">
    <div class="card">
        <div class="card-header"><span class="card-title">Record Sale</span></div>
        <div class="card-body">
            <form method="POST" id="feedSaleForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="form-label">From Stock (optional)</label>
                    <select name="feed_item_id" id="feed_sel" class="form-control">
                        <option value="">— Select from inventory —</option>
                        <?php foreach ($feed_items as $f): ?>
                        <option value="<?= $f['id'] ?>" data-name="<?= e($f['item_name']) ?>" data-unit="<?= e($f['unit']) ?>" data-stock="<?= $f['quantity'] ?>">
                            <?= e($f['item_name']) ?> (<?= number_format($f['quantity'],1) ?> <?= e($f['unit']) ?> available)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Item Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="item_name" id="feed_name" class="form-control" required maxlength="150" value="<?= e($_POST['item_name'] ?? '') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Quantity <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="quantity" id="feed_qty" class="form-control" step="0.1" min="0.01" required value="<?= e($_POST['quantity'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit</label>
                        <input type="text" name="unit" id="feed_unit" class="form-control" value="<?= e($_POST['unit'] ?? 'kg') ?>" maxlength="20">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Price / Unit (BDT) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="price_per_unit" id="feed_ppu" class="form-control" step="0.01" min="0.01" required value="<?= e($_POST['price_per_unit'] ?? '') ?>">
                </div>
                <div class="form-group" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.6rem .75rem;font-weight:600;color:#166534" id="feed_total">Total: —</div>
                <div class="form-group">
                    <label class="form-label">Buyer Name</label>
                    <input type="text" name="buyer_name" class="form-control" maxlength="150" value="<?= e($_POST['buyer_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Sale Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="sale_date" class="form-control" value="<?= e($_POST['sale_date'] ?? $today) ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Record Sale</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:.5rem">
            <span class="card-title">Sales History</span>
            <span style="font-size:.82rem;color:#6b7280;margin-left:auto">Period revenue: <strong><?= number_format($total_rev, 2) ?> BDT</strong></span>
            <form method="GET" style="display:flex;gap:.4rem">
                <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:125px">
                <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:125px">
                <button class="btn btn-secondary btn-sm">Filter</button>
            </form>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($sales)): ?><p style="text-align:center;padding:2rem;color:#9ca3af">No feed sales found.</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead><tr style="background:#f9fafb">
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Date</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Item</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Qty</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Price/Unit</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Total</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Buyer</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($sales as $s): ?>
                <tr style="border-bottom:1px solid #f3f4f6">
                    <td style="padding:.5rem .75rem"><?= e(formatDate($s['sale_date'])) ?></td>
                    <td style="padding:.5rem .75rem;font-weight:500"><?= e($s['item_name']) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right"><?= number_format($s['quantity'],1) ?> <?= e($s['unit']) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right"><?= number_format($s['price_per_unit'],2) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right;font-weight:600"><?= number_format($s['total_amount'],2) ?></td>
                    <td style="padding:.5rem .75rem;color:#6b7280"><?= e($s['buyer_name'] ?? '—') ?></td>
                    <td style="padding:.5rem .75rem">
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
                            <button class="btn btn-danger btn-sm">Del</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function(){
    const sel  = document.getElementById('feed_sel');
    const name = document.getElementById('feed_name');
    const unit = document.getElementById('feed_unit');
    const qty  = document.getElementById('feed_qty');
    const ppu  = document.getElementById('feed_ppu');
    const tot  = document.getElementById('feed_total');
    sel.addEventListener('change', function(){
        const o = this.options[this.selectedIndex];
        if(o.value){ name.value=o.dataset.name; unit.value=o.dataset.unit; }
        calc();
    });
    function calc(){ const q=parseFloat(qty.value)||0,p=parseFloat(ppu.value)||0; tot.textContent='Total: '+(q&&p?(q*p).toFixed(2)+' BDT':'—'); }
    qty.addEventListener('input',calc); ppu.addEventListener('input',calc);
})();
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
