<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager']);
requireFarmScope();
requireModule('equipment');

$db    = getDB();
$eq_id = (int)($_GET['id'] ?? 0);
if ($eq_id <= 0) { redirect('/modules/equipment/index.php'); }

// Inline migration: ensure quantity_sold and unit_sale_price exist
$es_cols = array_column($db->query("SHOW COLUMNS FROM equipment_sales")->fetchAll(), 'Field');
if (!in_array('buyer_phone',    $es_cols)) $db->exec("ALTER TABLE equipment_sales ADD COLUMN buyer_phone VARCHAR(30) DEFAULT NULL AFTER buyer_name");
if (!in_array('buyer_address',  $es_cols)) $db->exec("ALTER TABLE equipment_sales ADD COLUMN buyer_address VARCHAR(255) DEFAULT NULL AFTER buyer_phone");
if (!in_array('payment_method', $es_cols)) $db->exec("ALTER TABLE equipment_sales ADD COLUMN payment_method ENUM('cash','bank','mobile_banking','credit','other') NOT NULL DEFAULT 'cash'");
if (!in_array('payment_status', $es_cols)) $db->exec("ALTER TABLE equipment_sales ADD COLUMN payment_status ENUM('paid','pending','partial') NOT NULL DEFAULT 'paid'");
if (!in_array('quantity_sold',  $es_cols)) $db->exec("ALTER TABLE equipment_sales ADD COLUMN quantity_sold INT UNSIGNED NOT NULL DEFAULT 1 AFTER sale_price");
if (!in_array('unit_sale_price',$es_cols)) $db->exec("ALTER TABLE equipment_sales ADD COLUMN unit_sale_price DECIMAL(12,2) DEFAULT NULL AFTER quantity_sold");

// Inline migration: ensure quantity column exists on equipment
$eq_cols = array_column($db->query("SHOW COLUMNS FROM equipment")->fetchAll(), 'Field');
if (!in_array('quantity', $eq_cols)) $db->exec("ALTER TABLE equipment ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER serial_number");

$sel = $db->prepare("SELECT * FROM equipment WHERE id = ? AND " . farmFilter());
$sel->execute([$eq_id]);
$equipment = $sel->fetch();

if (!$equipment) {
    flashMessage('error', 'Equipment not found.');
    redirect('/modules/equipment/index.php');
}

$current_qty = (int)($equipment['quantity'] ?? 1);

if ($equipment['status'] === 'sold' || $current_qty <= 0) {
    flashMessage('error', "'{$equipment['name']}' is fully sold — no stock remaining.");
    redirect('/modules/equipment/index.php');
}

$errors = [];
$form = [
    'quantity_sold'  => '1',
    'unit_sale_price'=> '',
    'buyer_name'     => '',
    'buyer_phone'    => '',
    'buyer_address'  => '',
    'payment_method' => 'cash',
    'payment_status' => 'paid',
    'sale_date'      => date('Y-m-d'),
    'notes'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect("/modules/equipment/sell.php?id={$eq_id}");
    }

    $form['quantity_sold']   = trim($_POST['quantity_sold']   ?? '1');
    $form['unit_sale_price'] = trim($_POST['unit_sale_price'] ?? '');
    $form['buyer_name']      = sanitize($_POST['buyer_name']      ?? '');
    $form['buyer_phone']     = sanitize($_POST['buyer_phone']     ?? '');
    $form['buyer_address']   = sanitize($_POST['buyer_address']   ?? '');
    $form['payment_method']  = sanitize($_POST['payment_method']  ?? 'cash');
    $form['payment_status']  = sanitize($_POST['payment_status']  ?? 'paid');
    $form['sale_date']       = trim($_POST['sale_date'] ?? '');
    $form['notes']           = sanitize($_POST['notes'] ?? '');

    if (!in_array($form['payment_method'], ['cash','bank','mobile_banking','credit','other'], true)) $form['payment_method'] = 'cash';
    if (!in_array($form['payment_status'], ['paid','pending','partial'], true)) $form['payment_status'] = 'paid';

    $qty_sold        = (int)$form['quantity_sold'];
    $unit_sale_price = $form['unit_sale_price'] !== '' ? (float)$form['unit_sale_price'] : 0.0;

    if ($qty_sold < 1)             $errors[] = 'Quantity to sell must be at least 1.';
    if ($qty_sold > $current_qty)  $errors[] = "Cannot sell {$qty_sold} — only {$current_qty} in stock.";
    if ($unit_sale_price <= 0)     $errors[] = 'Unit sale price must be greater than 0.';
    if ($form['sale_date'] === '' || !strtotime($form['sale_date'])) $errors[] = 'A valid sale date is required.';

    if (empty($errors)) {
        $user_id        = (int)$_SESSION['user_id'];
        $purchase_price = (float)($equipment['purchase_price'] ?? 0);
        $total_sale     = round($qty_sold * $unit_sale_price, 2);
        $profit_loss    = round(($unit_sale_price - $purchase_price) * $qty_sold, 2);
        $new_qty        = $current_qty - $qty_sold;
        $new_status     = $new_qty <= 0 ? 'sold' : $equipment['status'];

        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO equipment_sales
                 (farm_id, equipment_id, quantity_sold, unit_sale_price, sale_price,
                  buyer_name, buyer_phone, buyer_address, payment_method, payment_status,
                  sale_date, profit_loss, recorded_by, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                fid(), $eq_id,
                $qty_sold, $unit_sale_price, $total_sale,
                $form['buyer_name']    ?: null,
                $form['buyer_phone']   ?: null,
                $form['buyer_address'] ?: null,
                $form['payment_method'],
                $form['payment_status'],
                $form['sale_date'],
                $profit_loss,
                $user_id,
                $form['notes'] ?: null,
            ]);
            $sale_id = (int)$db->lastInsertId();

            $db->prepare(
                "UPDATE equipment SET quantity = ?, status = ? WHERE id = ? AND " . farmFilter()
            )->execute([$new_qty, $new_status, $eq_id]);

            $pl_note = $profit_loss >= 0
                ? 'profit: ৳' . number_format($profit_loss, 2)
                : 'loss: ৳'   . number_format(abs($profit_loss), 2);
            $sale_desc = ($qty_sold > 1 ? "{$qty_sold}× " : '') . "{$equipment['name']}"
                . ($form['buyer_name'] ? " to {$form['buyer_name']}" : '')
                . " ({$pl_note})";

            $db->prepare(
                "INSERT INTO finance_transactions
                 (farm_id, type, category, amount, related_module, reference_id, transaction_date, recorded_by, notes)
                 VALUES (?, 'income', 'Equipment Sale', ?, 'equipment', ?, ?, ?, ?)"
            )->execute([fid(), $total_sale, $eq_id, $form['sale_date'], $user_id, "Equipment sold: {$sale_desc}"]);

            // Record in inventory_transactions if table exists
            $it_check = $db->query("SHOW TABLES LIKE 'inventory_transactions'")->fetchColumn();
            if ($it_check) {
                $db->prepare(
                    "INSERT INTO inventory_transactions
                     (farm_id, item_type, item_id, item_name, transaction_type, quantity, unit_cost, total_value, reference_type, reference_id, notes, recorded_by)
                     VALUES (?, 'equipment', ?, ?, 'sale', ?, ?, ?, 'equipment_sale', ?, ?, ?)"
                )->execute([
                    fid(), $eq_id, $equipment['name'],
                    $qty_sold, $unit_sale_price, $total_sale,
                    $sale_id,
                    $form['buyer_name'] ? "Buyer: {$form['buyer_name']}" : null,
                    $user_id,
                ]);
            }

            auditLog($user_id, 'SELL_EQUIPMENT', 'equipment_sales', $sale_id, null, [
                'equipment_id'   => $eq_id,
                'name'           => $equipment['name'],
                'quantity_sold'  => $qty_sold,
                'unit_sale_price'=> $unit_sale_price,
                'total_sale'     => $total_sale,
                'profit_loss'    => $profit_loss,
                'remaining_qty'  => $new_qty,
            ]);

            $db->commit();

            $pl_str  = ($profit_loss >= 0 ? '+' : '-') . '৳' . number_format(abs($profit_loss), 2);
            $qty_str = $qty_sold > 1 ? "{$qty_sold}× " : '';
            $rem_str = $new_qty > 0 ? " Remaining: {$new_qty}." : ' Fully sold out.';
            flashMessage('success', "{$qty_str}'{$equipment['name']}' sold for ৳" . number_format($total_sale, 2) . ". P/L: {$pl_str}.{$rem_str}");
        } catch (PDOException $e) {
            $db->rollBack();
            flashMessage('error', 'Failed to record sale. Please try again.');
        }
        redirect('/modules/equipment/index.php');
    }
}

$page_title     = "Sell Equipment — {$equipment['name']}";
$active_nav     = 'equipment';
$purchase_price = (float)($equipment['purchase_price'] ?? 0);

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Sell Equipment</h2>
        <p class="text-sm text-muted"><?= e($equipment['name']) ?></p>
    </div>
    <a href="/modules/equipment/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <ul style="margin:0 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card" style="max-width:580px">
    <div class="card-header"><span class="card-title">Sale Details</span></div>
    <div class="card-body">

        <!-- Equipment info panel -->
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.875rem">
            <div style="font-weight:600;margin-bottom:.35rem"><?= e($equipment['name']) ?></div>
            <div style="display:flex;flex-wrap:wrap;gap:.35rem 1.5rem">
                <?php if ($equipment['category'] ?? ''): ?>
                <span class="text-muted">Category: <?= e($equipment['category']) ?></span>
                <?php endif; ?>
                <span>In Stock: <strong style="color:var(--primary)"><?= $current_qty ?></strong> unit<?= $current_qty !== 1 ? 's' : '' ?></span>
                <?php if ($purchase_price > 0): ?>
                <span class="text-muted">Purchase Price/Unit: <strong>৳<?= number_format($purchase_price, 2) ?></strong></span>
                <?php endif; ?>
                <?php if ($equipment['purchase_date'] ?? ''): ?>
                <span class="text-muted">Purchased: <?= e(formatDate($equipment['purchase_date'])) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="/modules/equipment/sell.php?id=<?= $eq_id ?>" novalidate>
            <?= csrfField() ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="quantity_sold">
                        Quantity to Sell <span style="color:var(--danger)">*</span>
                    </label>
                    <input type="number" id="quantity_sold" name="quantity_sold" class="form-control"
                           value="<?= e($form['quantity_sold']) ?>"
                           min="1" max="<?= $current_qty ?>" required>
                    <span class="form-hint">Max: <?= $current_qty ?></span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="unit_sale_price">
                        Unit Sale Price (৳) <span style="color:var(--danger)">*</span>
                    </label>
                    <input type="number" id="unit_sale_price" name="unit_sale_price" class="form-control"
                           value="<?= e($form['unit_sale_price']) ?>"
                           step="0.01" min="0.01" required>
                </div>
            </div>

            <!-- Live calculation panel -->
            <div id="saleCalc" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.875rem">
                <div style="display:flex;flex-wrap:wrap;gap:.5rem 2rem">
                    <span>Total Sale: <strong id="calcTotal" style="color:#166534">—</strong></span>
                    <?php if ($purchase_price > 0): ?>
                    <span>Profit / Loss: <strong id="calcPL">—</strong></span>
                    <?php endif; ?>
                    <span class="text-muted">Remaining after sale: <strong id="calcRemaining">—</strong></span>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="sale_date">Sale Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="sale_date" name="sale_date" class="form-control"
                           value="<?= e($form['sale_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="buyer_name">Buyer Name</label>
                    <input type="text" id="buyer_name" name="buyer_name" class="form-control"
                           value="<?= e($form['buyer_name']) ?>" maxlength="150" placeholder="Optional">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="buyer_phone">Buyer Phone</label>
                    <input type="text" id="buyer_phone" name="buyer_phone" class="form-control"
                           value="<?= e($form['buyer_phone']) ?>" maxlength="30" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <?php foreach (['cash'=>'Cash','bank'=>'Bank Transfer','mobile_banking'=>'Mobile Banking','credit'=>'Credit','other'=>'Other'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= $form['payment_method']===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="buyer_address">Buyer Address</label>
                <input type="text" id="buyer_address" name="buyer_address" class="form-control"
                       value="<?= e($form['buyer_address']) ?>" maxlength="255" placeholder="Optional">
            </div>

            <div class="form-group">
                <label class="form-label">Payment Status</label>
                <select name="payment_status" class="form-control">
                    <?php foreach (['paid'=>'Paid','pending'=>'Pending','partial'=>'Partial'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $form['payment_status']===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="Optional…"><?= e($form['notes']) ?></textarea>
            </div>

            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary"
                        onclick="return confirmSell()">Record Sale</button>
                <a href="/modules/equipment/index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php
$pp_js      = $purchase_price;
$qty_js     = $current_qty;
$eq_name_js = json_encode($equipment['name']);
$inline_js = <<<JS
var _pp  = {$pp_js};
var _max = {$qty_js};
function updateCalc() {
    var qty = parseInt(document.getElementById('quantity_sold').value) || 0;
    var usp = parseFloat(document.getElementById('unit_sale_price').value) || 0;
    var total   = qty * usp;
    var rem     = _max - qty;
    var tEl     = document.getElementById('calcTotal');
    var plEl    = document.getElementById('calcPL');
    var remEl   = document.getElementById('calcRemaining');
    var box     = document.getElementById('saleCalc');
    tEl.textContent  = total > 0 ? '৳' + total.toFixed(2) : '—';
    if (remEl) remEl.textContent = qty > 0 && qty <= _max ? rem + ' unit' + (rem !== 1 ? 's' : '') : '—';
    if (plEl && _pp > 0) {
        var pl = (usp - _pp) * qty;
        plEl.textContent = pl !== 0 ? (pl > 0 ? '+' : '') + '৳' + pl.toFixed(2) : '৳0.00';
        plEl.style.color = pl >= 0 ? '#166534' : '#991b1b';
        box.style.background  = pl < 0 ? '#fef2f2' : '#f0fdf4';
        box.style.borderColor = pl < 0 ? '#fecaca' : '#86efac';
    }
}
function confirmSell() {
    var qty = parseInt(document.getElementById('quantity_sold').value) || 0;
    var usp = parseFloat(document.getElementById('unit_sale_price').value) || 0;
    if (qty < 1 || usp <= 0) return true;
    var name  = {$eq_name_js};
    var total = (qty * usp).toFixed(2);
    var rem   = _max - qty;
    var msg   = 'Confirm sale of ' + qty + '× "' + name + '" for ৳' + total + '?';
    if (rem > 0) msg += '\nRemaining stock: ' + rem;
    else         msg += '\nThis is the last of the stock.';
    return confirm(msg);
}
document.getElementById('quantity_sold').addEventListener('input', updateCalc);
document.getElementById('unit_sale_price').addEventListener('input', updateCalc);
updateCalc();
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
