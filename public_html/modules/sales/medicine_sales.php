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

// Inline migration for new columns
$ms_cols = array_column($db->query("SHOW COLUMNS FROM medicine_sales")->fetchAll(), 'Field');
if (!in_array('buyer_phone', $ms_cols))    $db->exec("ALTER TABLE medicine_sales ADD COLUMN buyer_phone VARCHAR(30) DEFAULT NULL AFTER buyer_name");
if (!in_array('buyer_address', $ms_cols))  $db->exec("ALTER TABLE medicine_sales ADD COLUMN buyer_address VARCHAR(255) DEFAULT NULL AFTER buyer_phone");
if (!in_array('payment_method', $ms_cols)) $db->exec("ALTER TABLE medicine_sales ADD COLUMN payment_method ENUM('cash','bank','mobile_banking','credit','other') NOT NULL DEFAULT 'cash'");
if (!in_array('payment_status', $ms_cols)) $db->exec("ALTER TABLE medicine_sales ADD COLUMN payment_status ENUM('paid','pending','partial') NOT NULL DEFAULT 'paid'");
if (!in_array('amount_paid', $ms_cols))    $db->exec("ALTER TABLE medicine_sales ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00");
if (!in_array('cost_price', $ms_cols))     $db->exec("ALTER TABLE medicine_sales ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00");
if (!in_array('profit', $ms_cols))         $db->exec("ALTER TABLE medicine_sales ADD COLUMN profit DECIMAL(12,2) NOT NULL DEFAULT 0.00");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/sales/medicine_sales.php');
    }
    $action = $_POST['action'] ?? 'add';
    if ($action === 'delete') {
        $db->prepare("DELETE FROM medicine_sales WHERE id = ? AND farm_id = ?")->execute([(int)$_POST['sale_id'], fid()]);
        flashMessage('success', 'Sale record deleted.');
        redirect('/modules/sales/medicine_sales.php');
    }

    $med_id      = (int)($_POST['medicine_item_id'] ?? 0) ?: null;
    $item_name   = sanitize($_POST['item_name'] ?? '');
    $qty         = (float)($_POST['quantity'] ?? 0);
    $unit        = sanitize($_POST['unit'] ?? 'unit');
    $ppu         = (float)($_POST['price_per_unit'] ?? 0);
    $total       = round($qty * $ppu, 2);
    $buyer       = sanitize($_POST['buyer_name'] ?? '');
    $buyer_phone = sanitize($_POST['buyer_phone'] ?? '');
    $buyer_addr  = sanitize($_POST['buyer_address'] ?? '');
    $pay_method  = sanitize($_POST['payment_method'] ?? 'cash');
    $pay_status  = sanitize($_POST['payment_status'] ?? 'paid');
    $amount_paid = (float)($_POST['amount_paid'] ?? $total);
    $sale_date   = trim($_POST['sale_date'] ?? '');
    $notes       = sanitize($_POST['notes'] ?? '');
    if (!in_array($pay_method, ['cash','bank','mobile_banking','credit','other'], true)) $pay_method = 'cash';
    if (!in_array($pay_status, ['paid','pending','partial'], true)) $pay_status = 'paid';
    if ($pay_status === 'paid') $amount_paid = $total;
    if ($pay_status === 'pending') $amount_paid = 0;

    if ($med_id) {
        $mn = $db->prepare("SELECT item_name, unit, quantity, cost_per_unit FROM medicine_inventory WHERE id = ? AND farm_id = ?");
        $mn->execute([$med_id, fid()]);
        $mn_row = $mn->fetch();
        if ($mn_row) { if ($item_name === '') $item_name = $mn_row['item_name']; if ($unit === 'unit') $unit = $mn_row['unit']; }
    }

    if ($item_name === '') $errors[] = 'Item name is required.';
    if ($qty <= 0)          $errors[] = 'Quantity must be greater than zero.';
    if ($ppu <= 0)          $errors[] = 'Price per unit must be greater than zero.';
    if ($sale_date === '')  $errors[] = 'Sale date is required.';

    if ($med_id && empty($errors)) {
        $stock_check = $db->prepare("SELECT quantity FROM medicine_inventory WHERE id = ? AND farm_id = ?");
        $stock_check->execute([$med_id, fid()]);
        $stock = $stock_check->fetchColumn();
        if ($stock !== false && $qty > (float)$stock) $errors[] = "Insufficient stock. Available: {$stock} {$unit}.";
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $cost   = (isset($mn_row) && (float)($mn_row['cost_per_unit'] ?? 0) > 0) ? round($qty * (float)$mn_row['cost_per_unit'], 2) : 0.0;
            $profit = round($total - $cost, 2);
            $db->prepare("INSERT INTO medicine_sales (farm_id,medicine_item_id,item_name,quantity,unit,price_per_unit,total_amount,buyer_name,buyer_phone,buyer_address,payment_method,payment_status,amount_paid,cost_price,profit,sale_date,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $med_id, $item_name, $qty, $unit, $ppu, $total, $buyer ?: null, $buyer_phone ?: null, $buyer_addr ?: null, $pay_method, $pay_status, $amount_paid, $cost, $profit, $sale_date, $notes ?: null, $uid]);
            $sale_id = (int)$db->lastInsertId();
            if ($med_id) {
                $db->prepare("UPDATE medicine_inventory SET quantity = quantity - ? WHERE id = ? AND farm_id = ?")->execute([$qty, $med_id, fid()]);
            }
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'income', 'Medicine Sales', $total, 'medicine', $sale_id, $sale_date, $uid, "{$qty} {$unit} of {$item_name}" . ($buyer ? " — {$buyer}" : '')]);
            auditLog($uid, 'MED_SALE', 'medicine_sales', $sale_id, [], ['item' => $item_name, 'qty' => $qty]);
            $db->commit();
            flashMessage('success', "Medicine sale recorded — " . number_format($total, 2) . " BDT.");
            redirect('/modules/sales/medicine_sales.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[medicine_sales] ' . $e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$med_items = $db->prepare("SELECT id, item_name, quantity, unit FROM medicine_inventory WHERE farm_id = ? AND quantity > 0 ORDER BY item_name");
$med_items->execute([fid()]);
$med_items = $med_items->fetchAll();

$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;
$sales = $db->prepare("SELECT ms.*, u.name AS recorder FROM medicine_sales ms LEFT JOIN users u ON u.id=ms.recorded_by WHERE ms.farm_id=? AND ms.sale_date BETWEEN ? AND ? ORDER BY ms.sale_date DESC");
$sales->execute([fid(), $filter_from, $filter_to]);
$sales = $sales->fetchAll();
$total_rev = array_sum(array_column($sales, 'total_amount'));

$page_title = 'Medicine Sales';
$active_nav = 'medicine_sales';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Medicine Sales</h1><p class="page-subtitle">Sell unused medicine from inventory</p></div>
    <a href="/modules/feed_medicine/index.php" class="btn btn-secondary">← Medicine Stock</a>
</div>
<?php if (!empty($errors)): ?><div class="alert alert-danger" style="margin-bottom:1rem"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:1.5rem;align-items:start">
    <div class="card">
        <div class="card-header"><span class="card-title">Record Sale</span></div>
        <div class="card-body">
            <form method="POST" id="medSaleForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="form-label">From Inventory (optional)</label>
                    <select name="medicine_item_id" id="med_sel" class="form-control">
                        <option value="">— Select from inventory —</option>
                        <?php foreach ($med_items as $m): ?>
                        <option value="<?= $m['id'] ?>" data-name="<?= e($m['item_name']) ?>" data-unit="<?= e($m['unit']) ?>" data-stock="<?= $m['quantity'] ?>">
                            <?= e($m['item_name']) ?> (<?= number_format($m['quantity'],1) ?> <?= e($m['unit']) ?> available)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Item Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="item_name" id="med_name" class="form-control" required maxlength="150" value="<?= e($_POST['item_name'] ?? '') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Quantity <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="quantity" id="med_qty" class="form-control" step="0.1" min="0.01" required value="<?= e($_POST['quantity'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit</label>
                        <input type="text" name="unit" id="med_unit" class="form-control" value="<?= e($_POST['unit'] ?? 'unit') ?>" maxlength="20">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Price / Unit (BDT) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="price_per_unit" id="med_ppu" class="form-control" step="0.01" min="0.01" required value="<?= e($_POST['price_per_unit'] ?? '') ?>">
                </div>
                <div class="form-group" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.6rem .75rem;font-weight:600;color:#166534" id="med_total">Total: —</div>
                <div class="form-group">
                    <label class="form-label">Buyer Name</label>
                    <input type="text" name="buyer_name" class="form-control" maxlength="150" value="<?= e($_POST['buyer_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Buyer Phone</label>
                    <input type="text" name="buyer_phone" class="form-control" maxlength="30" value="<?= e($_POST['buyer_phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Buyer Address</label>
                    <input type="text" name="buyer_address" class="form-control" maxlength="255" value="<?= e($_POST['buyer_address'] ?? '') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" class="form-control">
                            <?php foreach (['cash'=>'Cash','bank'=>'Bank Transfer','mobile_banking'=>'Mobile Banking','credit'=>'Credit','other'=>'Other'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($_POST['payment_method']??'cash')===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Status</label>
                        <select name="payment_status" class="form-control">
                            <?php foreach (['paid'=>'Paid','pending'=>'Pending','partial'=>'Partial'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($_POST['payment_status']??'paid')===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
            <span style="font-size:.82rem;color:#6b7280;margin-left:auto">Revenue: <strong><?= number_format($total_rev,2) ?> BDT</strong></span>
            <form method="GET" style="display:flex;gap:.4rem">
                <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:125px">
                <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:125px">
                <button class="btn btn-secondary btn-sm">Filter</button>
            </form>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($sales)): ?><p style="text-align:center;padding:2rem;color:#9ca3af">No medicine sales found.</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead><tr style="background:#f9fafb">
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Date</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Item</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Qty</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Price/U</th>
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
    const sel=document.getElementById('med_sel'),name=document.getElementById('med_name'),unit=document.getElementById('med_unit'),qty=document.getElementById('med_qty'),ppu=document.getElementById('med_ppu'),tot=document.getElementById('med_total');
    sel.addEventListener('change',function(){const o=this.options[this.selectedIndex];if(o.value){name.value=o.dataset.name;unit.value=o.dataset.unit;}calc();});
    function calc(){const q=parseFloat(qty.value)||0,p=parseFloat(ppu.value)||0;tot.textContent='Total: '+(q&&p?(q*p).toFixed(2)+' BDT':'—');}
    qty.addEventListener('input',calc);ppu.addEventListener('input',calc);
})();
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
