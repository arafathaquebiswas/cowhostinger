<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'accountant']);
requireFarmScope();
requireNotBlocked();

$page_title = 'Sellers';
$active_nav = 'sellers';
$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/sales/sellers.php');
    }

    $action   = $_POST['action']    ?? '';
    $seller_id = (int)($_POST['seller_id'] ?? 0);
    $name     = trim($_POST['name']    ?? '');
    $phone    = trim($_POST['phone']   ?? '');
    $nid      = trim($_POST['nid']     ?? '');
    $address  = trim($_POST['address'] ?? '');
    $notes    = trim($_POST['notes']   ?? '');

    if ($action === 'save_seller') {
        if ($name === '') {
            flashMessage('error', 'Name is required.');
        } elseif ($seller_id > 0) {
            $chk = $db->prepare("SELECT id FROM sellers WHERE id=? AND farm_id=?");
            $chk->execute([$seller_id, fid()]);
            if ($chk->fetch()) {
                $db->prepare(
                    "UPDATE sellers SET name=?,phone=?,nid=?,address=?,notes=? WHERE id=? AND farm_id=?"
                )->execute([$name, $phone ?: null, $nid ?: null, $address ?: null, $notes ?: null, $seller_id, fid()]);
                auditLog($uid, 'UPDATE_SELLER', 'sellers', $seller_id);
                flashMessage('success', 'Seller updated.');
            }
        } else {
            $db->prepare(
                "INSERT INTO sellers (farm_id,name,phone,nid,address,notes) VALUES (?,?,?,?,?,?)"
            )->execute([fid(), $name, $phone ?: null, $nid ?: null, $address ?: null, $notes ?: null]);
            $new_id = (int)$db->lastInsertId();
            auditLog($uid, 'CREATE_SELLER', 'sellers', $new_id, null, ['name' => $name]);
            flashMessage('success', "Seller \"{$name}\" added.");
        }
        redirect('/modules/sales/sellers.php');
    }

    if ($action === 'delete_seller') {
        $linked = $db->prepare("SELECT COUNT(*) FROM cow_purchases WHERE seller_id=? AND farm_id=?");
        $linked->execute([$seller_id, fid()]);
        if ($linked->fetchColumn() > 0) {
            flashMessage('error', 'Cannot delete seller linked to purchase records.');
        } else {
            $db->prepare("DELETE FROM sellers WHERE id=? AND farm_id=?")->execute([$seller_id, fid()]);
            auditLog($uid, 'DELETE_SELLER', 'sellers', $seller_id);
            flashMessage('success', 'Seller deleted.');
        }
        redirect('/modules/sales/sellers.php');
    }
}

$edit_seller = null;
if (isset($_GET['edit'])) {
    $es = $db->prepare("SELECT * FROM sellers WHERE id=? AND farm_id=?");
    $es->execute([(int)$_GET['edit'], fid()]);
    $edit_seller = $es->fetch() ?: null;
}

$sellers = $db->prepare(
    "SELECT s.*,
       (SELECT COUNT(*) FROM cow_purchases WHERE seller_id=s.id AND farm_id=s.farm_id) AS purchase_count
     FROM sellers s
     WHERE s.farm_id = ?
     ORDER BY s.name ASC"
);
$sellers->execute([fid()]);
$sellers = $sellers->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Sellers</h2>
        <p class="text-sm text-muted">Reusable seller profiles linked to cow purchases</p>
    </div>
    <a href="/modules/sales/index.php" class="btn btn-secondary">Sales</a>
</div>

<div class="card" style="max-width:640px;margin-bottom:1.5rem">
    <div class="card-header">
        <span class="card-title"><?= $edit_seller ? 'Edit Seller' : 'Add Seller' ?></span>
        <?php if ($edit_seller): ?>
        <a href="/modules/sales/sellers.php" class="btn btn-secondary btn-sm">Cancel</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" action="/modules/sales/sellers.php" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action"    value="save_seller">
            <input type="hidden" name="seller_id" value="<?= $edit_seller ? $edit_seller['id'] : 0 ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group">
                    <label class="form-label">Full Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="150"
                           value="<?= e($edit_seller['name'] ?? '') ?>" placeholder="Seller name">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="tel" name="phone" class="form-control" maxlength="30"
                           value="<?= e($edit_seller['phone'] ?? '') ?>" placeholder="01700-000000">
                </div>
                <div class="form-group">
                    <label class="form-label">NID / ID</label>
                    <input type="text" name="nid" class="form-control" maxlength="30"
                           value="<?= e($edit_seller['nid'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" maxlength="300"
                           value="<?= e($edit_seller['address'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control" maxlength="500"
                       value="<?= e($edit_seller['notes'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><?= $edit_seller ? 'Update' : 'Add Seller' ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">All Sellers</span>
        <span style="font-size:.78rem;color:var(--text-muted)"><?= count($sellers) ?> registered</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead><tr><th>Name</th><th>Phone</th><th>NID</th><th>Address</th><th>Purchases</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($sellers)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem">No sellers added yet.</td></tr>
            <?php else: ?>
                <?php foreach ($sellers as $s): ?>
                <tr>
                    <td><strong><?= e($s['name']) ?></strong></td>
                    <td><?= e($s['phone'] ?? '—') ?></td>
                    <td style="font-size:.82rem"><?= e($s['nid'] ?? '—') ?></td>
                    <td style="font-size:.82rem"><?= e($s['address'] ?? '—') ?></td>
                    <td><?= $s['purchase_count'] ?></td>
                    <td style="display:flex;gap:.4rem">
                        <a href="/modules/sales/sellers.php?edit=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                        <?php if ($s['purchase_count'] === 0): ?>
                        <form method="POST" onsubmit="return confirm('Delete <?= e(addslashes($s['name'])) ?>?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"    value="delete_seller">
                            <input type="hidden" name="seller_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
