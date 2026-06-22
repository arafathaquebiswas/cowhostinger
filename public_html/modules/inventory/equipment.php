<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireModule('equipment');
requireRole(['admin', 'manager', 'accountant']);

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$fid   = fid();
$today = date('Y-m-d');
$errors = [];

// ── Inline Migrations ─────────────────────────────────────────────────────────
$eq_cols = array_column($db->query("SHOW COLUMNS FROM equipment")->fetchAll(), 'Field');
if (!in_array('serial_number', $eq_cols))
    $db->exec("ALTER TABLE equipment ADD COLUMN serial_number VARCHAR(100) DEFAULT NULL AFTER name");
if (!in_array('created_by', $eq_cols))
    $db->exec("ALTER TABLE equipment ADD COLUMN created_by INT UNSIGNED DEFAULT NULL");
if (!in_array('updated_by', $eq_cols))
    $db->exec("ALTER TABLE equipment ADD COLUMN updated_by INT UNSIGNED DEFAULT NULL");

$es_cols = array_column($db->query("SHOW COLUMNS FROM equipment_sales")->fetchAll(), 'Field');
if (!in_array('buyer_phone', $es_cols))
    $db->exec("ALTER TABLE equipment_sales ADD COLUMN buyer_phone VARCHAR(30) DEFAULT NULL AFTER buyer_name");
if (!in_array('buyer_address', $es_cols))
    $db->exec("ALTER TABLE equipment_sales ADD COLUMN buyer_address VARCHAR(255) DEFAULT NULL AFTER buyer_phone");
if (!in_array('payment_method', $es_cols))
    $db->exec("ALTER TABLE equipment_sales ADD COLUMN payment_method ENUM('cash','bank','mobile_banking','credit','other') NOT NULL DEFAULT 'cash'");
if (!in_array('payment_status', $es_cols))
    $db->exec("ALTER TABLE equipment_sales ADD COLUMN payment_status ENUM('paid','pending','partial') NOT NULL DEFAULT 'paid'");

$db->exec("CREATE TABLE IF NOT EXISTS inventory_transactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  farm_id INT UNSIGNED NOT NULL,
  item_type ENUM('feed','medicine','equipment') NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  item_name VARCHAR(150) NOT NULL,
  transaction_type ENUM('purchase','sale','adjustment_add','adjustment_remove','use','waste') NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'unit',
  unit_cost DECIMAL(10,2) DEFAULT NULL,
  total_value DECIMAL(12,2) DEFAULT NULL,
  reference_type VARCHAR(50) DEFAULT NULL,
  reference_id INT UNSIGNED DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  recorded_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_farm_type (farm_id, item_type),
  KEY idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['cat'] ?? '';

// ── POST: Quick Sell from this page ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/inventory/equipment.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'sell_equipment') {
        $eq_id       = (int)($_POST['equipment_id'] ?? 0);
        $sale_price  = (float)($_POST['sale_price'] ?? 0);
        $buyer_name  = sanitize($_POST['buyer_name'] ?? '');
        $buyer_phone = sanitize($_POST['buyer_phone'] ?? '');
        $buyer_addr  = sanitize($_POST['buyer_address'] ?? '');
        $pay_method  = sanitize($_POST['payment_method'] ?? 'cash');
        $pay_status  = sanitize($_POST['payment_status'] ?? 'paid');
        $sale_date   = trim($_POST['sale_date'] ?? $today);
        $notes_txt   = sanitize($_POST['notes'] ?? '');

        if (!in_array($pay_method, ['cash','bank','mobile_banking','credit','other'], true)) $pay_method = 'cash';
        if (!in_array($pay_status, ['paid','pending','partial'], true)) $pay_status = 'paid';

        if ($eq_id <= 0)      $errors[] = 'Invalid equipment.';
        if ($sale_price <= 0) $errors[] = 'Sale price must be greater than zero.';
        if ($sale_date === '') $errors[] = 'Sale date is required.';

        $equip = null;
        if (empty($errors)) {
            $s = $db->prepare("SELECT * FROM equipment WHERE id = ? AND farm_id = ?");
            $s->execute([$eq_id, $fid]);
            $equip = $s->fetch();
            if (!$equip) $errors[] = 'Equipment not found.';
            elseif ($equip['status'] === 'sold') $errors[] = "This equipment is already sold.";
        }

        if (empty($errors)) {
            $purchase_price = (float)($equip['purchase_price'] ?? 0);
            $profit_loss    = $sale_price - $purchase_price;

            try {
                $db->beginTransaction();
                $db->prepare("INSERT INTO equipment_sales (farm_id,equipment_id,sale_price,buyer_name,buyer_phone,buyer_address,payment_method,payment_status,sale_date,profit_loss,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$fid, $eq_id, $sale_price, $buyer_name ?: null, $buyer_phone ?: null, $buyer_addr ?: null, $pay_method, $pay_status, $sale_date, $profit_loss, $uid, $notes_txt ?: null]);
                $sale_id = (int)$db->lastInsertId();
                $db->prepare("UPDATE equipment SET status='sold', current_value=?, updated_by=? WHERE id=? AND farm_id=?")
                   ->execute([$sale_price, $uid, $eq_id, $fid]);
                $db->prepare("INSERT INTO inventory_transactions (farm_id,item_type,item_id,item_name,transaction_type,quantity,unit,unit_cost,total_value,reference_type,reference_id,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$fid, 'equipment', $eq_id, $equip['name'], 'sale', 1, 'unit', $sale_price, $sale_price, 'equipment_sale', $sale_id, $buyer_name ? "Buyer: {$buyer_name}" : null, $uid]);
                $pl_note = $profit_loss >= 0 ? 'Profit: ' . number_format($profit_loss, 2) : 'Loss: ' . number_format(abs($profit_loss), 2);
                $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$fid, 'income', 'Equipment Sale', $sale_price, 'equipment', $sale_id, $sale_date, $uid, "Sold: {$equip['name']}" . ($buyer_name ? " to {$buyer_name}" : '') . " — {$pl_note}"]);
                auditLog($uid, 'SELL_EQUIPMENT', 'equipment_sales', $sale_id, null, ['equipment' => $equip['name'], 'price' => $sale_price, 'profit_loss' => $profit_loss]);
                $db->commit();
                flashMessage('success', "'{$equip['name']}' sold for " . number_format($sale_price, 2) . " BDT.");
                redirect('/modules/inventory/equipment.php');
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[inv_eq_sell] ' . $e->getMessage());
                $errors[] = 'Database error. Please try again.';
            }
        }
    }
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$where = "WHERE farm_id = {$fid}";
if ($status_filter && in_array($status_filter, ['operational','maintenance','damaged','sold','disposed'], true)) {
    $where .= " AND status = " . $db->quote($status_filter);
}
if ($category_filter) {
    $where .= " AND category = " . $db->quote($category_filter);
}

$equipment_list = $db->query("SELECT * FROM equipment {$where} ORDER BY status='sold', status='disposed', name")->fetchAll();

$categories = $db->query("SELECT DISTINCT category FROM equipment WHERE farm_id = {$fid} AND category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$status_counts = [];
$total_asset_value = 0;
$total_purchase_value = 0;
$all_eq = $db->query("SELECT status, current_value, purchase_price FROM equipment WHERE farm_id = {$fid}")->fetchAll();
foreach ($all_eq as $e) {
    $status_counts[$e['status']] = ($status_counts[$e['status']] ?? 0) + 1;
    if (!in_array($e['status'], ['sold','disposed'], true)) {
        $total_asset_value    += (float)($e['current_value'] ?? $e['purchase_price'] ?? 0);
        $total_purchase_value += (float)($e['purchase_price'] ?? 0);
    }
}

$recent_sales_q = $db->query("SELECT es.*, e.name AS equipment_name, u.name AS recorder FROM equipment_sales es JOIN equipment e ON e.id=es.equipment_id LEFT JOIN users u ON u.id=es.recorded_by WHERE es.farm_id={$fid} ORDER BY es.sale_date DESC, es.id DESC LIMIT 20");
$recent_sales = $recent_sales_q->fetchAll();

$sell_target = isset($_GET['sell']) ? (int)$_GET['sell'] : 0;
$sell_eq = null;
if ($sell_target > 0) {
    $s = $db->prepare("SELECT * FROM equipment WHERE id = ? AND farm_id = ? AND status != 'sold'");
    $s->execute([$sell_target, $fid]);
    $sell_eq = $s->fetch() ?: null;
}

$page_title = 'Equipment & Machinery';
$active_nav = 'inv_equipment';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

$status_labels = ['operational' => ['Operational', '#dcfce7', '#166534'], 'maintenance' => ['In Maintenance', '#fef3c7', '#92400e'], 'damaged' => ['Damaged', '#fee2e2', '#991b1b'], 'sold' => ['Sold', '#f3f4f6', '#6b7280'], 'disposed' => ['Disposed', '#f3f4f6', '#6b7280']];
?>

<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div>
        <h1 class="page-title">Equipment &amp; Machinery</h1>
        <p class="page-subtitle">Track assets, values, and sales</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/equipment/form.php" class="btn btn-primary btn-sm">+ Add Equipment</a>
        <a href="/modules/equipment/index.php" class="btn btn-secondary btn-sm">Full Management</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.25rem">
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:#1e40af"><?= $status_counts['operational'] ?? 0 ?></div>
        <div style="font-size:.78rem;color:#6b7280">Operational</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.2rem;font-weight:700;color:#166534"><?= number_format($total_asset_value, 0) ?> ৳</div>
        <div style="font-size:.78rem;color:#6b7280">Current Asset Value</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.2rem;font-weight:700;color:#6b7280"><?= number_format($total_purchase_value, 0) ?> ৳</div>
        <div style="font-size:.78rem;color:#6b7280">Total Cost (Active)</div>
    </div>
    <?php if (!empty($status_counts['maintenance'])): ?>
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:#d97706"><?= $status_counts['maintenance'] ?></div>
        <div style="font-size:.78rem;color:#d97706">In Maintenance</div>
    </div>
    <?php endif; ?>
    <?php if (!empty($status_counts['damaged'])): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:#dc2626"><?= $status_counts['damaged'] ?></div>
        <div style="font-size:.78rem;color:#dc2626">Damaged</div>
    </div>
    <?php endif; ?>
</div>

<!-- Sell Modal (inline form shown when ?sell=ID) -->
<?php if ($sell_eq): ?>
<div class="card" style="border:2px solid #2563eb;margin-bottom:1.5rem;max-width:600px">
    <div class="card-header" style="background:#eff6ff">
        <span class="card-title" style="color:#1e40af">Sell Equipment: <?= e($sell_eq['name']) ?></span>
        <a href="/modules/inventory/equipment.php" class="btn btn-secondary btn-sm" style="margin-left:auto">Cancel</a>
    </div>
    <div class="card-body">
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;margin-bottom:1rem;font-size:.875rem">
            <strong><?= e($sell_eq['name']) ?></strong>
            <?php if ($sell_eq['category']): ?><span style="color:#6b7280"> — <?= e($sell_eq['category']) ?></span><?php endif; ?>
            <?php if ($sell_eq['serial_number']): ?><br><span style="color:#6b7280">S/N: <?= e($sell_eq['serial_number']) ?></span><?php endif; ?>
            <?php if ($sell_eq['purchase_price']): ?><br>Purchase Price: <strong><?= number_format((float)$sell_eq['purchase_price'], 2) ?> ৳</strong><?php endif; ?>
            <?php if ($sell_eq['current_value']): ?><br>Current Value: <strong><?= number_format((float)$sell_eq['current_value'], 2) ?> ৳</strong><?php endif; ?>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell_equipment">
            <input type="hidden" name="equipment_id" value="<?= $sell_eq['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group">
                    <label class="form-label">Sale Price (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="sale_price" id="eq_sale_price" class="form-control" step="0.01" min="0.01" required value="<?= e($_POST['sale_price'] ?? $sell_eq['current_value'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Sale Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="sale_date" class="form-control" value="<?= e($_POST['sale_date'] ?? $today) ?>" required>
                </div>
            </div>
            <div id="eq_pl_box" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.5rem .75rem;margin-bottom:.75rem;font-size:.875rem;display:none">
                <span id="eq_pl_txt" style="font-weight:700"></span>
            </div>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;margin-bottom:.75rem">
                <div style="font-size:.8rem;font-weight:700;color:#374151;margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.04em">Buyer Information</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Buyer Name</label>
                        <input type="text" name="buyer_name" class="form-control" maxlength="150" value="<?= e($_POST['buyer_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Buyer Phone</label>
                        <input type="text" name="buyer_phone" class="form-control" maxlength="30" value="<?= e($_POST['buyer_phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Buyer Address</label>
                    <input type="text" name="buyer_address" class="form-control" maxlength="255" value="<?= e($_POST['buyer_address'] ?? '') ?>">
                </div>
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
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary" onclick="return confirm('Confirm sale of <?= e(addslashes($sell_eq['name'])) ?>?')">Confirm Sale</button>
        </form>
    </div>
</div>
<script>
(function(){
    var pp = <?= (float)($sell_eq['purchase_price'] ?? 0) ?>;
    var sp = document.getElementById('eq_sale_price');
    var box = document.getElementById('eq_pl_box');
    var txt = document.getElementById('eq_pl_txt');
    if (sp && pp > 0) {
        sp.addEventListener('input', function(){
            var s = parseFloat(this.value)||0;
            var pl = s - pp;
            box.style.display = 'block';
            txt.textContent = (pl>=0?'Profit: +':'Loss: ') + '৳' + Math.abs(pl).toFixed(2);
            txt.style.color = pl>=0?'#166534':'#dc2626';
            box.style.borderColor = pl>=0?'#86efac':'#fca5a5';
            box.style.background = pl>=0?'#f0fdf4':'#fef2f2';
        });
    }
})();
</script>
<?php endif; ?>

<!-- Filters -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;align-items:center">
    <a href="?" class="btn <?= !$status_filter && !$category_filter ? 'btn-primary' : 'btn-secondary' ?> btn-sm">All</a>
    <a href="?status=operational" class="btn <?= $status_filter==='operational' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Operational (<?= $status_counts['operational'] ?? 0 ?>)</a>
    <a href="?status=maintenance" class="btn <?= $status_filter==='maintenance' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Maintenance (<?= $status_counts['maintenance'] ?? 0 ?>)</a>
    <a href="?status=damaged" class="btn <?= $status_filter==='damaged' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Damaged (<?= $status_counts['damaged'] ?? 0 ?>)</a>
    <a href="?status=sold" class="btn <?= $status_filter==='sold' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">Sold (<?= $status_counts['sold'] ?? 0 ?>)</a>
    <?php if (!empty($categories)): ?>
    <span style="color:#6b7280;font-size:.85rem;margin-left:.5rem">Category:</span>
    <?php foreach ($categories as $cat): ?>
    <a href="?cat=<?= urlencode($cat) ?>" class="btn <?= $category_filter===$cat ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= e($cat) ?></a>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Equipment List -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-body" style="padding:0">
        <?php if (empty($equipment_list)): ?>
        <p style="text-align:center;padding:2.5rem;color:#9ca3af">No equipment found. <a href="/modules/equipment/form.php">Add your first piece of equipment.</a></p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Name</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Category</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Serial #</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Status</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Purchase Price</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Current Value</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Purchased</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($equipment_list as $eq):
                [$slbl, $sbg, $scol] = $status_labels[$eq['status']] ?? [$eq['status'], '#f3f4f6', '#374151'];
                $depreciation = (float)($eq['purchase_price'] ?? 0) - (float)($eq['current_value'] ?? $eq['purchase_price'] ?? 0);
            ?>
            <tr style="border-bottom:1px solid #f3f4f6;opacity:<?= in_array($eq['status'], ['sold','disposed'],true) ? '0.65' : '1' ?>">
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($eq['name']) ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280"><?= e($eq['category'] ?? '—') ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280;font-size:.8rem"><?= e($eq['serial_number'] ?? '—') ?></td>
                <td style="padding:.5rem .75rem">
                    <span style="background:<?= $sbg ?>;color:<?= $scol ?>;font-size:.72rem;font-weight:700;padding:.15rem .5rem;border-radius:4px"><?= $slbl ?></span>
                </td>
                <td style="padding:.5rem .75rem;text-align:right"><?= $eq['purchase_price'] ? number_format((float)$eq['purchase_price'], 0) . ' ৳' : '—' ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:600;color:<?= $depreciation > 0 ? '#d97706' : '#166534' ?>"><?= $eq['current_value'] ? number_format((float)$eq['current_value'], 0) . ' ৳' : '—' ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280;font-size:.82rem"><?= $eq['purchase_date'] ? e(formatDate($eq['purchase_date'])) : '—' ?></td>
                <td style="padding:.5rem .75rem">
                    <?php if (!in_array($eq['status'], ['sold','disposed'], true)): ?>
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <a href="?sell=<?= $eq['id'] ?>" class="btn btn-success btn-sm" style="font-size:.72rem;padding:.2rem .5rem">Sell</a>
                        <a href="/modules/equipment/form.php?id=<?= $eq['id'] ?>" class="btn btn-secondary btn-sm" style="font-size:.72rem;padding:.2rem .5rem">Edit</a>
                        <a href="/modules/equipment/log_cost.php?id=<?= $eq['id'] ?>" class="btn btn-secondary btn-sm" style="font-size:.72rem;padding:.2rem .5rem">Maint.</a>
                    </div>
                    <?php else: ?>
                    <span style="color:#9ca3af;font-size:.8rem"><?= ucfirst($eq['status']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Equipment Sales -->
<?php if (!empty($recent_sales)): ?>
<div class="card">
    <div class="card-header"><span class="card-title">Recent Equipment Sales</span></div>
    <div class="card-body" style="padding:0">
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Equipment</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Buyer</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Sale Price</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Profit/Loss</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Payment</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent_sales as $s): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem"><?= e(formatDate($s['sale_date'])) ?></td>
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($s['equipment_name']) ?></td>
                <td style="padding:.5rem .75rem">
                    <?= e($s['buyer_name'] ?? '—') ?>
                    <?php if (!empty($s['buyer_phone'])): ?><br><small style="color:#6b7280"><?= e($s['buyer_phone']) ?></small><?php endif; ?>
                </td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:#166534"><?= number_format((float)$s['sale_price'], 0) ?> ৳</td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:600;color:<?= (float)($s['profit_loss']??0)>=0?'#166534':'#dc2626' ?>">
                    <?= $s['profit_loss'] !== null ? ((float)$s['profit_loss']>=0?'+':'').number_format((float)$s['profit_loss'],0).' ৳' : '—' ?>
                </td>
                <td style="padding:.5rem .75rem">
                    <span style="font-size:.72rem;font-weight:700;padding:.1rem .4rem;border-radius:4px;background:<?= ($s['payment_status']??'paid')==='paid'?'#dcfce7':'#fef3c7' ?>;color:<?= ($s['payment_status']??'paid')==='paid'?'#166534':'#92400e' ?>">
                        <?= strtoupper($s['payment_status'] ?? 'paid') ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
