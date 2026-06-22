<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireModule('feed_medicine');
requireRole(['admin', 'manager', 'accountant', 'veterinarian']);

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$fid   = fid();
$today = date('Y-m-d');
$errors = [];

// ── Inline Migrations ─────────────────────────────────────────────────────────
$mi_cols = array_column($db->query("SHOW COLUMNS FROM medicine_inventory")->fetchAll(), 'Field');
if (!in_array('category', $mi_cols))
    $db->exec("ALTER TABLE medicine_inventory ADD COLUMN category VARCHAR(100) DEFAULT NULL AFTER item_name");
if (!in_array('batch_number', $mi_cols))
    $db->exec("ALTER TABLE medicine_inventory ADD COLUMN batch_number VARCHAR(100) DEFAULT NULL AFTER category");
if (!in_array('selling_price', $mi_cols))
    $db->exec("ALTER TABLE medicine_inventory ADD COLUMN selling_price DECIMAL(10,2) DEFAULT NULL AFTER cost_per_unit");
if (!in_array('supplier', $mi_cols))
    $db->exec("ALTER TABLE medicine_inventory ADD COLUMN supplier VARCHAR(150) DEFAULT NULL");
if (!in_array('purchase_date', $mi_cols))
    $db->exec("ALTER TABLE medicine_inventory ADD COLUMN purchase_date DATE DEFAULT NULL");
if (!in_array('created_by', $mi_cols))
    $db->exec("ALTER TABLE medicine_inventory ADD COLUMN created_by INT UNSIGNED DEFAULT NULL");
if (!in_array('updated_by', $mi_cols))
    $db->exec("ALTER TABLE medicine_inventory ADD COLUMN updated_by INT UNSIGNED DEFAULT NULL");

$ms_cols = array_column($db->query("SHOW COLUMNS FROM medicine_sales")->fetchAll(), 'Field');
if (!in_array('buyer_phone', $ms_cols))
    $db->exec("ALTER TABLE medicine_sales ADD COLUMN buyer_phone VARCHAR(30) DEFAULT NULL AFTER buyer_name");
if (!in_array('buyer_address', $ms_cols))
    $db->exec("ALTER TABLE medicine_sales ADD COLUMN buyer_address VARCHAR(255) DEFAULT NULL AFTER buyer_phone");
if (!in_array('payment_method', $ms_cols))
    $db->exec("ALTER TABLE medicine_sales ADD COLUMN payment_method ENUM('cash','bank','mobile_banking','credit','other') NOT NULL DEFAULT 'cash'");
if (!in_array('payment_status', $ms_cols))
    $db->exec("ALTER TABLE medicine_sales ADD COLUMN payment_status ENUM('paid','pending','partial') NOT NULL DEFAULT 'paid'");
if (!in_array('amount_paid', $ms_cols))
    $db->exec("ALTER TABLE medicine_sales ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00");
if (!in_array('cost_price', $ms_cols))
    $db->exec("ALTER TABLE medicine_sales ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00");
if (!in_array('profit', $ms_cols))
    $db->exec("ALTER TABLE medicine_sales ADD COLUMN profit DECIMAL(12,2) NOT NULL DEFAULT 0.00");

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

$tab = $_GET['tab'] ?? 'stock';

// ── POST Handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/inventory/medicine.php');
    }
    $action = $_POST['action'] ?? '';

    // ── Add / Edit Item ───────────────────────────────────────────────────
    if (in_array($action, ['add_item', 'edit_item'], true)) {
        $edit_id     = (int)($_POST['item_id'] ?? 0);
        $item_name   = sanitize($_POST['item_name'] ?? '');
        $category    = sanitize($_POST['category'] ?? '');
        $batch_num   = sanitize($_POST['batch_number'] ?? '');
        $supplier    = sanitize($_POST['supplier'] ?? '');
        $qty         = (float)($_POST['quantity'] ?? 0);
        $unit        = sanitize($_POST['unit'] ?? 'unit');
        $cost        = (float)($_POST['cost_per_unit'] ?? 0);
        $sell_price  = ($_POST['selling_price'] ?? '') !== '' ? (float)$_POST['selling_price'] : null;
        $reorder     = (float)($_POST['reorder_threshold'] ?? 0);
        $expiry      = trim($_POST['expiry_date'] ?? '') ?: null;
        $pur_date    = trim($_POST['purchase_date'] ?? '') ?: null;

        if ($item_name === '') $errors[] = 'Item name is required.';
        if ($qty < 0)          $errors[] = 'Quantity cannot be negative.';

        if (empty($errors)) {
            if ($action === 'add_item') {
                $db->prepare("INSERT INTO medicine_inventory (farm_id, item_name, category, batch_number, quantity, unit, cost_per_unit, selling_price, supplier, purchase_date, expiry_date, reorder_threshold, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$fid, $item_name, $category ?: null, $batch_num ?: null, $qty, $unit, $cost ?: null, $sell_price, $supplier ?: null, $pur_date, $expiry, $reorder, $uid]);
                $new_id = (int)$db->lastInsertId();
                if ($qty > 0) {
                    $db->prepare("INSERT INTO inventory_transactions (farm_id,item_type,item_id,item_name,transaction_type,quantity,unit,unit_cost,total_value,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$fid, 'medicine', $new_id, $item_name, 'purchase', $qty, $unit, $cost ?: null, $cost > 0 ? round($qty * $cost, 2) : null, 'Initial stock', $uid]);
                    if ($cost > 0) {
                        $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                           ->execute([$fid, 'expense', 'Medicine Purchase', round($qty * $cost, 2), 'medicine', $new_id, $pur_date ?? $today, $uid, "Initial stock: {$qty} {$unit} of {$item_name}"]);
                    }
                }
                auditLog($uid, 'ADD_MED_ITEM', 'medicine_inventory', $new_id, [], ['name' => $item_name, 'qty' => $qty]);
                flashMessage('success', "'{$item_name}' added to medicine stock.");
            } else {
                $old = $db->prepare("SELECT * FROM medicine_inventory WHERE id = ? AND farm_id = ?");
                $old->execute([$edit_id, $fid]);
                $old_row = $old->fetch();
                if ($old_row) {
                    $db->prepare("UPDATE medicine_inventory SET item_name=?,category=?,batch_number=?,supplier=?,unit=?,cost_per_unit=?,selling_price=?,reorder_threshold=?,expiry_date=?,purchase_date=?,updated_by=? WHERE id=? AND farm_id=?")
                       ->execute([$item_name, $category ?: null, $batch_num ?: null, $supplier ?: null, $unit, $cost ?: null, $sell_price, $reorder, $expiry, $pur_date, $uid, $edit_id, $fid]);
                    auditLog($uid, 'EDIT_MED_ITEM', 'medicine_inventory', $edit_id, $old_row, ['name' => $item_name]);
                    flashMessage('success', "'{$item_name}' updated.");
                } else {
                    $errors[] = 'Item not found.';
                }
            }
            if (empty($errors)) redirect('/modules/inventory/medicine.php?tab=stock');
        }
        $tab = $action === 'add_item' ? 'add' : 'edit';
    }

    // ── Purchase (add stock) ──────────────────────────────────────────────
    if ($action === 'purchase') {
        $item_id   = (int)($_POST['item_id'] ?? 0);
        $qty       = (float)($_POST['quantity'] ?? 0);
        $cost      = (float)($_POST['cost_per_unit'] ?? 0);
        $batch_num = sanitize($_POST['batch_number'] ?? '');
        $supplier  = sanitize($_POST['supplier'] ?? '');
        $pur_date  = trim($_POST['purchase_date'] ?? $today);
        $expiry    = trim($_POST['expiry_date'] ?? '') ?: null;
        $notes_txt = sanitize($_POST['notes'] ?? '');

        if ($item_id <= 0) $errors[] = 'Please select a medicine item.';
        if ($qty <= 0)      $errors[] = 'Quantity must be greater than zero.';

        $item = null;
        if (empty($errors)) {
            $s = $db->prepare("SELECT * FROM medicine_inventory WHERE id = ? AND farm_id = ?");
            $s->execute([$item_id, $fid]);
            $item = $s->fetch();
            if (!$item) $errors[] = 'Item not found.';
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();
                $db->prepare("UPDATE medicine_inventory SET quantity = quantity + ?, updated_by = ? WHERE id = ? AND farm_id = ?")
                   ->execute([$qty, $uid, $item_id, $fid]);
                if ($expiry) $db->prepare("UPDATE medicine_inventory SET expiry_date=? WHERE id=? AND farm_id=?")->execute([$expiry, $item_id, $fid]);
                if ($batch_num) $db->prepare("UPDATE medicine_inventory SET batch_number=? WHERE id=? AND farm_id=?")->execute([$batch_num, $item_id, $fid]);
                if ($supplier) $db->prepare("UPDATE medicine_inventory SET supplier=? WHERE id=? AND farm_id=?")->execute([$supplier, $item_id, $fid]);
                $db->prepare("INSERT INTO inventory_transactions (farm_id,item_type,item_id,item_name,transaction_type,quantity,unit,unit_cost,total_value,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$fid, 'medicine', $item_id, $item['item_name'], 'purchase', $qty, $item['unit'], $cost ?: null, $cost > 0 ? round($qty * $cost, 2) : null, $notes_txt ?: null, $uid]);
                if ($cost > 0) {
                    $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$fid, 'expense', 'Medicine Purchase', round($qty * $cost, 2), 'medicine', $item_id, $pur_date, $uid, "Purchased {$qty} {$item['unit']} of {$item['item_name']}" . ($supplier ? " from {$supplier}" : '')]);
                }
                $db->commit();
                flashMessage('success', "Added {$qty} {$item['unit']} to {$item['item_name']} stock.");
                redirect('/modules/inventory/medicine.php?tab=stock');
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[inv_med_purchase] ' . $e->getMessage());
                $errors[] = 'Database error. Please try again.';
            }
        }
        $tab = 'purchase';
    }

    // ── Sell Stock ────────────────────────────────────────────────────────
    if ($action === 'sell_stock') {
        $item_id     = (int)($_POST['item_id'] ?? 0);
        $qty         = (float)($_POST['quantity'] ?? 0);
        $ppu         = (float)($_POST['price_per_unit'] ?? 0);
        $buyer_name  = sanitize($_POST['buyer_name'] ?? '');
        $buyer_phone = sanitize($_POST['buyer_phone'] ?? '');
        $buyer_addr  = sanitize($_POST['buyer_address'] ?? '');
        $pay_method  = sanitize($_POST['payment_method'] ?? 'cash');
        $pay_status  = sanitize($_POST['payment_status'] ?? 'paid');
        $amount_paid = (float)($_POST['amount_paid'] ?? 0);
        $sale_date   = trim($_POST['sale_date'] ?? $today);
        $notes_txt   = sanitize($_POST['notes'] ?? '');

        if (!in_array($pay_method, ['cash','bank','mobile_banking','credit','other'], true)) $pay_method = 'cash';
        if (!in_array($pay_status, ['paid','pending','partial'], true)) $pay_status = 'paid';

        if ($item_id <= 0) $errors[] = 'Please select a medicine item.';
        if ($qty <= 0)      $errors[] = 'Quantity must be greater than zero.';
        if ($ppu <= 0)      $errors[] = 'Price per unit must be greater than zero.';
        if ($sale_date === '') $errors[] = 'Sale date is required.';

        $item = null;
        if (empty($errors)) {
            $s = $db->prepare("SELECT * FROM medicine_inventory WHERE id = ? AND farm_id = ?");
            $s->execute([$item_id, $fid]);
            $item = $s->fetch();
            if (!$item) {
                $errors[] = 'Item not found.';
            } elseif ($qty > (float)$item['quantity']) {
                $errors[] = "Insufficient stock. Available: {$item['quantity']} {$item['unit']}.";
            }
        }

        if (empty($errors)) {
            $total  = round($qty * $ppu, 2);
            $cost   = (float)($item['cost_per_unit'] ?? 0) > 0 ? round($qty * (float)$item['cost_per_unit'], 2) : 0.0;
            $profit = round($total - $cost, 2);
            if ($pay_status === 'paid') $amount_paid = $total;
            if ($pay_status === 'pending') $amount_paid = 0;

            try {
                $db->beginTransaction();
                $db->prepare("INSERT INTO medicine_sales (farm_id,medicine_item_id,item_name,quantity,unit,price_per_unit,total_amount,buyer_name,buyer_phone,buyer_address,payment_method,payment_status,amount_paid,cost_price,profit,sale_date,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$fid, $item_id, $item['item_name'], $qty, $item['unit'], $ppu, $total, $buyer_name ?: null, $buyer_phone ?: null, $buyer_addr ?: null, $pay_method, $pay_status, $amount_paid, $cost, $profit, $sale_date, $notes_txt ?: null, $uid]);
                $sale_id = (int)$db->lastInsertId();
                $db->prepare("UPDATE medicine_inventory SET quantity = quantity - ?, updated_by = ? WHERE id = ? AND farm_id = ?")
                   ->execute([$qty, $uid, $item_id, $fid]);
                $db->prepare("INSERT INTO inventory_transactions (farm_id,item_type,item_id,item_name,transaction_type,quantity,unit,unit_cost,total_value,reference_type,reference_id,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$fid, 'medicine', $item_id, $item['item_name'], 'sale', $qty, $item['unit'], $ppu, $total, 'medicine_sale', $sale_id, $buyer_name ? "Buyer: {$buyer_name}" : null, $uid]);
                $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$fid, 'income', 'Medicine Sales', $total, 'medicine', $sale_id, $sale_date, $uid, "{$qty} {$item['unit']} of {$item['item_name']}" . ($buyer_name ? " to {$buyer_name}" : '')]);
                auditLog($uid, 'MED_SALE', 'medicine_sales', $sale_id, [], ['item' => $item['item_name'], 'qty' => $qty, 'total' => $total]);
                $db->commit();
                flashMessage('success', "Sold {$qty} {$item['unit']} of {$item['item_name']} for " . number_format($total, 2) . " BDT.");
                redirect('/modules/inventory/medicine.php?tab=stock');
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[inv_med_sell] ' . $e->getMessage());
                $errors[] = 'Database error. Please try again.';
            }
        }
        $tab = 'sell';
    }

    if ($action === 'delete_item') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $chk = $db->prepare("SELECT quantity FROM medicine_inventory WHERE id = ? AND farm_id = ?");
        $chk->execute([$item_id, $fid]);
        $row = $chk->fetch();
        if ($row && (float)$row['quantity'] > 0) {
            flashMessage('error', 'Cannot delete item with stock remaining.');
        } else {
            $db->prepare("DELETE FROM medicine_inventory WHERE id = ? AND farm_id = ?")->execute([$item_id, $fid]);
            flashMessage('success', 'Item removed.');
        }
        redirect('/modules/inventory/medicine.php?tab=stock');
    }
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$stock_q = $db->prepare("SELECT * FROM medicine_inventory WHERE farm_id = ? ORDER BY item_name");
$stock_q->execute([$fid]);
$all_stock = $stock_q->fetchAll();

$total_cost_val = 0; $total_sell_val = 0; $low_count = 0; $expiring_count = 0; $expired_count = 0;
foreach ($all_stock as $s) {
    $total_cost_val += (float)$s['quantity'] * (float)($s['cost_per_unit'] ?? 0);
    $total_sell_val += (float)$s['quantity'] * (float)($s['selling_price'] ?? $s['cost_per_unit'] ?? 0);
    if ((float)$s['reorder_threshold'] > 0 && (float)$s['quantity'] <= (float)$s['reorder_threshold']) $low_count++;
    if (!empty($s['expiry_date'])) {
        if ($s['expiry_date'] < $today) $expired_count++;
        elseif ($s['expiry_date'] <= date('Y-m-d', strtotime('+30 days'))) $expiring_count++;
    }
}

$history_q = $db->prepare("SELECT it.*, u.name AS recorder FROM inventory_transactions it LEFT JOIN users u ON u.id=it.recorded_by WHERE it.farm_id=? AND it.item_type='medicine' ORDER BY it.created_at DESC LIMIT 100");
$history_q->execute([$fid]);
$history_rows = $history_q->fetchAll();

$sales_q = $db->prepare("SELECT ms.*, u.name AS recorder FROM medicine_sales ms LEFT JOIN users u ON u.id=ms.recorded_by WHERE ms.farm_id=? ORDER BY ms.sale_date DESC, ms.id DESC LIMIT 50");
$sales_q->execute([$fid]);
$recent_sales = $sales_q->fetchAll();

$edit_item = null;
if ($tab === 'edit' && isset($_GET['item_id'])) {
    $ei = $db->prepare("SELECT * FROM medicine_inventory WHERE id = ? AND farm_id = ?");
    $ei->execute([(int)$_GET['item_id'], $fid]);
    $edit_item = $ei->fetch();
    if (!$edit_item) $tab = 'stock';
}

$pre_item = null;
if (($tab === 'sell' || $tab === 'purchase') && isset($_GET['item_id'])) {
    $pi = $db->prepare("SELECT * FROM medicine_inventory WHERE id = ? AND farm_id = ?");
    $pi->execute([(int)$_GET['item_id'], $fid]);
    $pre_item = $pi->fetch() ?: null;
}

$page_title = 'Medicine Stock';
$active_nav = 'inv_medicine';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

$tabs = ['stock' => 'Current Stock', 'add' => 'Add Item', 'purchase' => 'Purchase Stock', 'sell' => 'Sell Stock', 'history' => 'Transaction History'];
?>

<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div>
        <h1 class="page-title">Medicine Stock</h1>
        <p class="page-subtitle">Manage medicine inventory — track stock, expiry, purchase, and sell</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="?tab=add" class="btn btn-primary btn-sm">+ Add Item</a>
        <a href="?tab=purchase" class="btn btn-secondary btn-sm">Purchase Stock</a>
        <a href="?tab=sell" class="btn btn-secondary btn-sm">Sell Stock</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.25rem">
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:#1e40af"><?= count($all_stock) ?></div>
        <div style="font-size:.78rem;color:#6b7280">Medicine Items</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.2rem;font-weight:700;color:#166534"><?= number_format($total_cost_val, 0) ?> ৳</div>
        <div style="font-size:.78rem;color:#6b7280">Stock Cost Value</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.2rem;font-weight:700;color:#0369a1"><?= number_format($total_sell_val, 0) ?> ৳</div>
        <div style="font-size:.78rem;color:#6b7280">Sellable Value</div>
    </div>
    <?php if ($low_count > 0): ?>
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:#c2410c"><?= $low_count ?></div>
        <div style="font-size:.78rem;color:#c2410c">Low Stock</div>
    </div>
    <?php endif; ?>
    <?php if ($expiring_count > 0): ?>
    <div style="background:#fff7ed;border:1px solid #fde68a;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:#d97706"><?= $expiring_count ?></div>
        <div style="font-size:.78rem;color:#d97706">Expiring &lt; 30d</div>
    </div>
    <?php endif; ?>
    <?php if ($expired_count > 0): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:1rem;text-align:center">
        <div style="font-size:1.5rem;font-weight:700;color:#dc2626"><?= $expired_count ?></div>
        <div style="font-size:.78rem;color:#dc2626">Expired!</div>
    </div>
    <?php endif; ?>
</div>

<!-- Tab Nav -->
<div style="display:flex;gap:.25rem;flex-wrap:wrap;border-bottom:2px solid #e5e7eb;margin-bottom:1.25rem">
    <?php foreach ($tabs as $k => $label): ?>
    <a href="?tab=<?= $k ?>" style="padding:.5rem 1rem;font-size:.85rem;font-weight:600;color:<?= $tab===$k ? '#2563eb' : '#6b7280' ?>;border-bottom:<?= $tab===$k ? '2px solid #2563eb' : '2px solid transparent' ?>;margin-bottom:-2px;text-decoration:none"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'stock'): ?>
<div class="card">
    <div class="card-body" style="padding:0">
        <?php if (empty($all_stock)): ?>
        <p style="text-align:center;padding:2.5rem;color:#9ca3af">No medicine items yet. <a href="?tab=add">Add your first item.</a></p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.84rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Item</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Batch</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Stock</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Cost/Unit</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Sell/Unit</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Expiry</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Supplier</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($all_stock as $s):
                $is_expired  = !empty($s['expiry_date']) && $s['expiry_date'] < $today;
                $is_expiring = !empty($s['expiry_date']) && !$is_expired && $s['expiry_date'] <= date('Y-m-d', strtotime('+30 days'));
                $is_low      = (float)$s['reorder_threshold'] > 0 && (float)$s['quantity'] <= (float)$s['reorder_threshold'];
                $row_bg      = $is_expired ? '#fef2f2' : ($is_expiring ? '#fff7ed' : ($is_low ? '#fffbeb' : ''));
            ?>
            <tr style="border-bottom:1px solid #f3f4f6;background:<?= $row_bg ?>">
                <td style="padding:.5rem .75rem;font-weight:600">
                    <?= e($s['item_name']) ?>
                    <?php if ($s['category']): ?><br><span style="font-size:.72rem;color:#6b7280"><?= e($s['category']) ?></span><?php endif; ?>
                    <?php if ($is_low): ?><span style="background:#fed7aa;color:#9a3412;font-size:.65rem;font-weight:700;padding:.1rem .35rem;border-radius:4px;display:inline-block;margin-top:.2rem">LOW</span><?php endif; ?>
                </td>
                <td style="padding:.5rem .75rem;color:#6b7280;font-size:.8rem"><?= e($s['batch_number'] ?? '—') ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:<?= (float)$s['quantity'] <= 0 ? '#dc2626' : '#111827' ?>"><?= number_format((float)$s['quantity'], 1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= $s['cost_per_unit'] ? number_format((float)$s['cost_per_unit'], 2) : '—' ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= $s['selling_price'] ? number_format((float)$s['selling_price'], 2) : '—' ?></td>
                <td style="padding:.5rem .75rem">
                    <?php if ($s['expiry_date']): ?>
                    <span style="color:<?= $is_expired ? '#dc2626' : ($is_expiring ? '#d97706' : '#6b7280') ?>;font-size:.82rem">
                        <?= e(formatDate($s['expiry_date'])) ?>
                        <?= $is_expired ? ' ⚠ Expired' : ($is_expiring ? ' ⚠ Soon' : '') ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td style="padding:.5rem .75rem;color:#6b7280;font-size:.8rem"><?= e($s['supplier'] ?? '—') ?></td>
                <td style="padding:.5rem .75rem">
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <a href="?tab=purchase&item_id=<?= $s['id'] ?>" class="btn btn-secondary btn-sm" style="font-size:.72rem;padding:.2rem .5rem">+ Stock</a>
                        <a href="?tab=sell&item_id=<?= $s['id'] ?>" class="btn btn-primary btn-sm" style="font-size:.72rem;padding:.2rem .5rem">Sell</a>
                        <a href="?tab=edit&item_id=<?= $s['id'] ?>" class="btn btn-secondary btn-sm" style="font-size:.72rem;padding:.2rem .5rem">Edit</a>
                        <?php if ((float)$s['quantity'] <= 0): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Remove?')">
                            <?= csrfField() ?><input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="<?= $s['id'] ?>">
                            <button class="btn btn-danger btn-sm" style="font-size:.72rem;padding:.2rem .5rem">Del</button>
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
</div>

<?php elseif ($tab === 'add' || $tab === 'edit'): ?>
<div class="card" style="max-width:700px">
    <div class="card-header"><span class="card-title"><?= $tab === 'edit' ? 'Edit Medicine Item' : 'Add New Medicine Item' ?></span></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $tab === 'edit' ? 'edit_item' : 'add_item' ?>">
            <?php if ($tab === 'edit'): ?><input type="hidden" name="item_id" value="<?= (int)($_GET['item_id'] ?? 0) ?>"><?php endif; ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Medicine Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="item_name" class="form-control" required maxlength="150" value="<?= e($edit_item['item_name'] ?? $_POST['item_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-control">
                        <option value="">— Select —</option>
                        <?php foreach (['Antibiotic', 'Vaccine', 'Vitamin & Supplement', 'Antiparasitic', 'Anti-inflammatory', 'Hormonal', 'Disinfectant', 'Other'] as $cat):
                            $sel = ($edit_item['category'] ?? $_POST['category'] ?? '') === $cat ? 'selected' : ''; ?>
                        <option value="<?= $cat ?>" <?= $sel ?>><?= $cat ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Batch Number</label>
                    <input type="text" name="batch_number" class="form-control" maxlength="100" value="<?= e($edit_item['batch_number'] ?? $_POST['batch_number'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <input type="text" name="supplier" class="form-control" maxlength="150" value="<?= e($edit_item['supplier'] ?? $_POST['supplier'] ?? '') ?>">
                </div>
                <?php if ($tab === 'add'): ?>
                <div class="form-group">
                    <label class="form-label">Initial Quantity</label>
                    <input type="number" name="quantity" class="form-control" step="0.1" min="0" value="<?= e($_POST['quantity'] ?? '0') ?>">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <select name="unit" class="form-control">
                        <?php foreach (['unit', 'ml', 'L', 'mg', 'g', 'kg', 'vial', 'tablet', 'capsule', 'bottle', 'sachet'] as $u):
                            $sel = ($edit_item['unit'] ?? $_POST['unit'] ?? 'unit') === $u ? 'selected' : ''; ?>
                        <option value="<?= $u ?>" <?= $sel ?>><?= $u ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Cost Price / Unit (৳)</label>
                    <input type="number" name="cost_per_unit" class="form-control" step="0.01" min="0" value="<?= e($edit_item['cost_per_unit'] ?? $_POST['cost_per_unit'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Selling Price / Unit (৳)</label>
                    <input type="number" name="selling_price" class="form-control" step="0.01" min="0" value="<?= e($edit_item['selling_price'] ?? $_POST['selling_price'] ?? '') ?>" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label class="form-label">Reorder Threshold</label>
                    <input type="number" name="reorder_threshold" class="form-control" step="0.1" min="0" value="<?= e($edit_item['reorder_threshold'] ?? $_POST['reorder_threshold'] ?? '0') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control" value="<?= e($edit_item['purchase_date'] ?? $_POST['purchase_date'] ?? $today) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expiry_date" class="form-control" value="<?= e($edit_item['expiry_date'] ?? $_POST['expiry_date'] ?? '') ?>">
                </div>
            </div>
            <div style="display:flex;gap:.5rem;margin-top:.5rem">
                <button type="submit" class="btn btn-primary"><?= $tab === 'edit' ? 'Save Changes' : 'Add to Inventory' ?></button>
                <a href="?tab=stock" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php elseif ($tab === 'purchase'): ?>
<div class="card" style="max-width:580px">
    <div class="card-header"><span class="card-title">Purchase / Add Stock</span></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="purchase">
            <div class="form-group">
                <label class="form-label">Medicine Item <span style="color:var(--danger)">*</span></label>
                <select name="item_id" id="pur_item" class="form-control" required>
                    <option value="">— Select Medicine —</option>
                    <?php foreach ($all_stock as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($pre_item && $pre_item['id'] == $s['id']) ? 'selected' : '' ?> data-unit="<?= e($s['unit']) ?>">
                        <?= e($s['item_name']) ?> (<?= number_format((float)$s['quantity'], 1) ?> <?= e($s['unit']) ?> in stock)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group">
                    <label class="form-label">Quantity <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="quantity" id="pur_qty" class="form-control" step="0.1" min="0.01" required value="<?= e($_POST['quantity'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Cost / Unit (৳)</label>
                    <input type="number" name="cost_per_unit" id="pur_cost" class="form-control" step="0.01" min="0" value="<?= e($_POST['cost_per_unit'] ?? '') ?>">
                </div>
            </div>
            <div id="pur_total_box" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.6rem .75rem;font-weight:600;color:#991b1b;margin-bottom:.75rem">Total Cost: —</div>
            <div class="form-group">
                <label class="form-label">Batch Number</label>
                <input type="text" name="batch_number" class="form-control" maxlength="100" value="<?= e($_POST['batch_number'] ?? $pre_item['batch_number'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Supplier</label>
                <input type="text" name="supplier" class="form-control" maxlength="150" value="<?= e($_POST['supplier'] ?? $pre_item['supplier'] ?? '') ?>">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control" value="<?= e($_POST['purchase_date'] ?? $today) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expiry_date" class="form-control" value="<?= e($_POST['expiry_date'] ?? $pre_item['expiry_date'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Add to Stock</button>
                <a href="?tab=stock" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php elseif ($tab === 'sell'): ?>
<div class="card" style="max-width:640px">
    <div class="card-header"><span class="card-title">Sell Medicine Stock</span></div>
    <div class="card-body">
        <form method="POST" id="sellForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell_stock">
            <div class="form-group">
                <label class="form-label">Medicine Item <span style="color:var(--danger)">*</span></label>
                <select name="item_id" id="sell_item" class="form-control" required>
                    <option value="">— Select Medicine —</option>
                    <?php foreach ($all_stock as $s): if ((float)$s['quantity'] <= 0) continue; ?>
                    <option value="<?= $s['id'] ?>"
                        <?= ($pre_item && $pre_item['id'] == $s['id']) ? 'selected' : '' ?>
                        data-unit="<?= e($s['unit']) ?>"
                        data-stock="<?= $s['quantity'] ?>"
                        data-cost="<?= (float)($s['cost_per_unit'] ?? 0) ?>"
                        data-sell="<?= (float)($s['selling_price'] ?? $s['cost_per_unit'] ?? 0) ?>">
                        <?= e($s['item_name']) ?> (<?= number_format((float)$s['quantity'],1) ?> <?= e($s['unit']) ?> avail.)
                    </option>
                    <?php endforeach; ?>
                </select>
                <small id="sell_stock_info" style="color:#6b7280;font-size:.78rem"></small>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group">
                    <label class="form-label">Quantity <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="quantity" id="sell_qty" class="form-control" step="0.1" min="0.01" required value="<?= e($_POST['quantity'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Price / Unit (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="price_per_unit" id="sell_ppu" class="form-control" step="0.01" min="0.01" required value="<?= e($_POST['price_per_unit'] ?? '') ?>">
                </div>
            </div>
            <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.6rem .75rem;margin-bottom:.75rem;font-size:.875rem" id="sell_calc">
                <span id="sell_total_txt" style="font-weight:700;color:#166534">Total: —</span>
                <span id="sell_profit_txt" style="margin-left:1rem;color:#6b7280"></span>
            </div>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;margin-bottom:.75rem">
                <div style="font-size:.8rem;font-weight:700;color:#374151;margin-bottom:.5rem;text-transform:uppercase;letter-spacing:.04em">Buyer Information</div>
                <div class="form-group">
                    <label class="form-label">Buyer Name</label>
                    <input type="text" name="buyer_name" class="form-control" maxlength="150" value="<?= e($_POST['buyer_name'] ?? '') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Buyer Phone</label>
                        <input type="text" name="buyer_phone" class="form-control" maxlength="30" value="<?= e($_POST['buyer_phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sale Date <span style="color:var(--danger)">*</span></label>
                        <input type="date" name="sale_date" class="form-control" value="<?= e($_POST['sale_date'] ?? $today) ?>" required>
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
                        <option value="<?= $v ?>" <?= ($_POST['payment_method'] ?? 'cash')===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status" id="sell_pay_status" class="form-control" onchange="toggleAmtPaid()">
                        <?php foreach (['paid'=>'Paid','pending'=>'Pending','partial'=>'Partial'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($_POST['payment_status'] ?? 'paid')===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group" id="amt_paid_box" style="display:none">
                <label class="form-label">Amount Paid (৳)</label>
                <input type="number" name="amount_paid" class="form-control" step="0.01" min="0" value="<?= e($_POST['amount_paid'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Record Sale</button>
                <a href="?tab=stock" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php elseif ($tab === 'history'): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title">Stock Movements</span></div>
    <div class="card-body" style="padding:0">
        <?php if (empty($history_rows)): ?>
        <p style="text-align:center;padding:2rem;color:#9ca3af">No transactions recorded yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Item</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Type</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Qty</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Value</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">By</th>
            </tr></thead>
            <tbody>
            <?php foreach ($history_rows as $h):
                $is_in = in_array($h['transaction_type'], ['purchase','adjustment_add'], true);
                $type_badges = ['purchase'=>['#dcfce7','#166534','Purchase'],'sale'=>['#fef2f2','#dc2626','Sale'],'adjustment_add'=>['#dbeafe','#1e40af','+Adjust'],'adjustment_remove'=>['#fef3c7','#92400e','-Adjust'],'use'=>['#f3e8ff','#7c3aed','Used'],'waste'=>['#fee2e2','#991b1b','Waste']];
                [$bg,$col,$lbl] = $type_badges[$h['transaction_type']] ?? ['#f3f4f6','#374151',$h['transaction_type']];
            ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;font-size:.8rem;white-space:nowrap"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td>
                <td style="padding:.5rem .75rem;font-weight:500"><?= e($h['item_name']) ?></td>
                <td style="padding:.5rem .75rem"><span style="background:<?= $bg ?>;color:<?= $col ?>;font-size:.7rem;font-weight:700;padding:.15rem .45rem;border-radius:4px"><?= $lbl ?></span></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:<?= $is_in ? '#166534' : '#dc2626' ?>"><?= $is_in ? '+' : '-' ?><?= number_format((float)$h['quantity'], 1) ?> <?= e($h['unit']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= $h['total_value'] ? number_format((float)$h['total_value'], 0) . ' ৳' : '—' ?></td>
                <td style="padding:.5rem .75rem;color:#6b7280;font-size:.8rem"><?= e($h['recorder'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<div class="card">
    <div class="card-header"><span class="card-title">Recent Sales</span></div>
    <div class="card-body" style="padding:0">
        <?php if (empty($recent_sales)): ?>
        <p style="text-align:center;padding:2rem;color:#9ca3af">No medicine sales yet.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Item</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Qty</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Total</th>
                <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Profit</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Buyer</th>
                <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb">Payment</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent_sales as $s): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem"><?= e(formatDate($s['sale_date'])) ?></td>
                <td style="padding:.5rem .75rem;font-weight:500"><?= e($s['item_name']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format((float)$s['quantity'],1) ?> <?= e($s['unit']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:#166534"><?= number_format((float)$s['total_amount'],0) ?> ৳</td>
                <td style="padding:.5rem .75rem;text-align:right;color:<?= (float)($s['profit']??0)>=0?'#166534':'#dc2626' ?>">
                    <?= isset($s['profit']) ? ((float)$s['profit']>=0?'+':'').number_format((float)$s['profit'],0).' ৳' : '—' ?>
                </td>
                <td style="padding:.5rem .75rem">
                    <?= e($s['buyer_name'] ?? '—') ?>
                    <?php if (!empty($s['buyer_phone'])): ?><br><small style="color:#6b7280"><?= e($s['buyer_phone']) ?></small><?php endif; ?>
                </td>
                <td style="padding:.5rem .75rem">
                    <span style="font-size:.72rem;font-weight:700;padding:.1rem .4rem;border-radius:4px;background:<?= ($s['payment_status']??'paid')==='paid'?'#dcfce7':(($s['payment_status']??'')==='pending'?'#fef3c7':'#dbeafe') ?>;color:<?= ($s['payment_status']??'paid')==='paid'?'#166534':(($s['payment_status']??'')==='pending'?'#92400e':'#1e40af') ?>">
                        <?= strtoupper($s['payment_status'] ?? 'paid') ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
(function(){
    var pur_item = document.getElementById('pur_item');
    var pur_qty  = document.getElementById('pur_qty');
    var pur_cost = document.getElementById('pur_cost');
    var pur_box  = document.getElementById('pur_total_box');
    if (pur_item) {
        function calcPur(){var q=parseFloat(pur_qty?pur_qty.value:0)||0,c=parseFloat(pur_cost?pur_cost.value:0)||0;if(pur_box){if(q&&c){pur_box.textContent='Total Cost: ৳'+(q*c).toFixed(2);pur_box.style.display='block';}else pur_box.style.display='none';}}
        if (pur_qty) pur_qty.addEventListener('input', calcPur);
        if (pur_cost) pur_cost.addEventListener('input', calcPur);
    }
    var sell_item   = document.getElementById('sell_item');
    var sell_qty    = document.getElementById('sell_qty');
    var sell_ppu    = document.getElementById('sell_ppu');
    var sell_total  = document.getElementById('sell_total_txt');
    var sell_profit = document.getElementById('sell_profit_txt');
    var sell_info   = document.getElementById('sell_stock_info');
    if (sell_item) {
        sell_item.addEventListener('change',function(){var o=this.options[this.selectedIndex];if(sell_info&&o.value){sell_info.textContent='Available: '+o.dataset.stock+' '+o.dataset.unit;}if(sell_ppu&&o.dataset.sell){sell_ppu.value=o.dataset.sell;}calcSell();});
        function calcSell(){var o=sell_item.options[sell_item.selectedIndex];var q=parseFloat(sell_qty?sell_qty.value:0)||0,p=parseFloat(sell_ppu?sell_ppu.value:0)||0,c=parseFloat(o.dataset.cost)||0;if(sell_total)sell_total.textContent='Total: '+(q&&p?'৳'+(q*p).toFixed(2):'—');if(sell_profit&&q&&p&&c){var pr=(p-c)*q;sell_profit.textContent='Est. Profit: '+(pr>=0?'+':'')+'৳'+pr.toFixed(2);sell_profit.style.color=pr>=0?'#166534':'#dc2626';}else if(sell_profit)sell_profit.textContent='';}
        if (sell_qty) sell_qty.addEventListener('input', calcSell);
        if (sell_ppu) sell_ppu.addEventListener('input', calcSell);
    }
})();
function toggleAmtPaid(){var s=document.getElementById('sell_pay_status'),b=document.getElementById('amt_paid_box');if(b)b.style.display=(s&&s.value==='partial')?'block':'none';}
toggleAmtPaid();
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
