<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireModule('milk');
requireRole(['admin', 'manager', 'accountant']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Auto-migrate table ────────────────────────────────────────────────────────
(function (PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS `milk_customers` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `farm_id` INT UNSIGNED NOT NULL,
      `name` VARCHAR(150) NOT NULL,
      `phone` VARCHAR(30) DEFAULT NULL,
      `address` VARCHAR(255) DEFAULT NULL,
      `price_per_liter` DECIMAL(10,2) DEFAULT NULL,
      `payment_terms` ENUM('daily','weekly','monthly','on_delivery') NOT NULL DEFAULT 'daily',
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `notes` TEXT DEFAULT NULL,
      `created_by` INT UNSIGNED DEFAULT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_farm_active` (`farm_id`, `is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
})($db);

$errors  = [];
$action  = $_POST['action'] ?? '';
$edit_id = (int)($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/milk/customers.php');
    }

    $name     = sanitize($_POST['name'] ?? '');
    $phone    = sanitize($_POST['phone'] ?? '');
    $address  = sanitize($_POST['address'] ?? '');
    $ppl      = (float)($_POST['price_per_liter'] ?? 0);
    $terms    = in_array($_POST['payment_terms'] ?? '', ['daily','weekly','monthly','on_delivery'], true)
                ? $_POST['payment_terms'] : 'daily';
    $notes    = sanitize($_POST['notes'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $cid      = (int)($_POST['customer_id'] ?? 0);

    if ($name === '') $errors[] = 'Customer name is required.';

    if ($action === 'delete' && $cid > 0) {
        $db->prepare("DELETE FROM milk_customers WHERE id = ? AND farm_id = ?")->execute([$cid, fid()]);
        flashMessage('success', 'Customer deleted.');
        redirect('/modules/milk/customers.php');
    }

    if (empty($errors)) {
        if ($action === 'edit' && $cid > 0) {
            $db->prepare("UPDATE milk_customers SET name=?,phone=?,address=?,price_per_liter=?,payment_terms=?,is_active=?,notes=? WHERE id=? AND farm_id=?")
               ->execute([$name, $phone ?: null, $address ?: null, $ppl ?: null, $terms, $is_active, $notes ?: null, $cid, fid()]);
            flashMessage('success', "Customer '{$name}' updated.");
        } else {
            $db->prepare("INSERT INTO milk_customers (farm_id,name,phone,address,price_per_liter,payment_terms,is_active,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $name, $phone ?: null, $address ?: null, $ppl ?: null, $terms, 1, $notes ?: null, $uid]);
            flashMessage('success', "Customer '{$name}' added.");
        }
        redirect('/modules/milk/customers.php');
    }
}

$editing = null;
if ($edit_id > 0) {
    $sel = $db->prepare("SELECT * FROM milk_customers WHERE id = ? AND farm_id = ?");
    $sel->execute([$edit_id, fid()]);
    $editing = $sel->fetch();
}

$customers = $db->prepare("SELECT * FROM milk_customers WHERE farm_id = ? ORDER BY is_active DESC, name ASC");
$customers->execute([fid()]);
$customers = $customers->fetchAll();

$page_title = 'Milk Customers';
$active_nav = 'milk_customers';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Milk Customers</h1><p class="page-subtitle">Manage regular milk buyers</p></div>
    <a href="/modules/milk/sales.php" class="btn btn-primary">+ New Milk Sale</a>
</div>

<?php $flash = getFlashMessage(); if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?>" style="margin-bottom:1rem"><?= e($flash['message']) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.5rem;align-items:start">
    <!-- Form -->
    <div class="card">
        <div class="card-header"><span class="card-title"><?= $editing ? 'Edit Customer' : 'Add Customer' ?></span></div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" style="margin-bottom:1rem"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
                <?php if ($editing): ?><input type="hidden" name="customer_id" value="<?= $editing['id'] ?>"><?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= e($editing['name'] ?? '') ?>" required maxlength="150">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($editing['phone'] ?? '') ?>" maxlength="30">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" value="<?= e($editing['address'] ?? '') ?>" maxlength="255">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Default Price/Liter</label>
                        <input type="number" name="price_per_liter" class="form-control" value="<?= e($editing['price_per_liter'] ?? '') ?>" step="0.01" min="0" placeholder="BDT">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Terms</label>
                        <select name="payment_terms" class="form-control">
                            <?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','on_delivery'=>'On Delivery'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($editing['payment_terms'] ?? 'daily') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($editing['notes'] ?? '') ?></textarea>
                </div>
                <?php if ($editing): ?>
                <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
                    <input type="checkbox" name="is_active" id="is_active" value="1" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
                    <label for="is_active" class="form-label" style="margin:0">Active</label>
                </div>
                <?php endif; ?>
                <div style="display:flex;gap:.5rem">
                    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Add Customer' ?></button>
                    <?php if ($editing): ?><a href="/modules/milk/customers.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- List -->
    <div class="card">
        <div class="card-header"><span class="card-title">All Customers (<?= count($customers) ?>)</span></div>
        <div class="card-body" style="padding:0">
            <?php if (empty($customers)): ?>
            <p style="text-align:center;padding:2rem;color:#9ca3af">No customers yet.</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <thead><tr style="background:#f9fafb">
                    <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Name</th>
                    <th style="padding:.6rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Phone</th>
                    <th style="padding:.6rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Price/L</th>
                    <th style="padding:.6rem .75rem;text-align:center;border-bottom:2px solid #e5e7eb">Status</th>
                    <th style="padding:.6rem .75rem;border-bottom:2px solid #e5e7eb"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                <tr style="border-bottom:1px solid #f3f4f6">
                    <td style="padding:.55rem .75rem;font-weight:500"><?= e($c['name']) ?></td>
                    <td style="padding:.55rem .75rem;color:#6b7280"><?= e($c['phone'] ?? '—') ?></td>
                    <td style="padding:.55rem .75rem;text-align:right"><?= $c['price_per_liter'] ? number_format($c['price_per_liter'], 2) : '—' ?></td>
                    <td style="padding:.55rem .75rem;text-align:center">
                        <span style="padding:.15rem .5rem;border-radius:999px;font-size:.7rem;font-weight:600;<?= $c['is_active'] ? 'background:#dcfce7;color:#166534' : 'background:#f3f4f6;color:#6b7280' ?>">
                            <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td style="padding:.55rem .75rem;white-space:nowrap">
                        <a href="?edit=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete <?= e(addslashes($c['name'])) ?>?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
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
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
