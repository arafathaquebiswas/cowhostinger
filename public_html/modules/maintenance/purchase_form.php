<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'accountant']);
requireFarmScope();
requireNotBlocked();
requireModule('maintenance');

$db          = getDB();
$purchase_id = (int)($_GET['id'] ?? 0);
$is_edit     = $purchase_id > 0;
$existing    = null;

$errors = [];
$form = [
    'area_id'       => '',
    'item'          => '',
    'cost'          => '',
    'purchase_date' => date('Y-m-d'),
    'notes'         => '',
];

$al_stmt = $db->prepare("SELECT id, name, type FROM farm_areas WHERE " . farmFilter() . " ORDER BY name ASC");
$al_stmt->execute();
$area_list = $al_stmt->fetchAll();

if ($is_edit) {
    $sel = $db->prepare("SELECT ap.* FROM area_purchases ap JOIN farm_areas fa ON fa.id=ap.area_id WHERE ap.id = ? AND " . farmFilter('fa'));
    $sel->execute([$purchase_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Purchase record not found.');
        redirect('/modules/maintenance/index.php?tab=purchases');
    }
    $form = [
        'area_id'       => $existing['area_id'],
        'item'          => $existing['item'],
        'cost'          => $existing['cost'],
        'purchase_date' => $existing['purchase_date'],
        'notes'         => $existing['notes'] ?? '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/maintenance/purchase_form.php' . ($is_edit ? "?id={$purchase_id}" : ''));
    }

    $form['area_id']       = trim($_POST['area_id']       ?? '');
    $form['item']          = sanitize($_POST['item']          ?? '');
    $form['cost']          = trim($_POST['cost']          ?? '');
    $form['purchase_date'] = trim($_POST['purchase_date'] ?? '');
    $form['notes']         = sanitize($_POST['notes']         ?? '');

    $area_id_val = $form['area_id'] !== '' ? (int)$form['area_id'] : null;
    if (!$area_id_val) $errors[] = 'Please select a farm area.';

    if ($form['item'] === '') $errors[] = 'Item description is required.';
    if (strlen($form['item']) > 200) $errors[] = 'Item description is too long.';

    $cost = $form['cost'] !== '' ? (float)$form['cost'] : null;
    if ($cost === null || $cost <= 0) $errors[] = 'Cost must be greater than 0.';

    if ($form['purchase_date'] === '' || !strtotime($form['purchase_date'])) {
        $errors[] = 'A valid purchase date is required.';
    } elseif (strtotime($form['purchase_date']) > strtotime('today')) {
        $errors[] = 'Purchase date cannot be in the future.';
    }

    // Verify area exists and belongs to this farm
    if ($area_id_val) {
        $chk = $db->prepare("SELECT id FROM farm_areas WHERE id = ? AND " . farmFilter());
        $chk->execute([$area_id_val]);
        if (!$chk->fetch()) { $errors[] = 'Invalid farm area.'; $area_id_val = null; }
    }

    if (empty($errors)) {
        $user_id   = (int)$_SESSION['user_id'];
        $notes_val = $form['notes'] !== '' ? $form['notes'] : null;

        if ($is_edit) {
            $db->prepare(
                "UPDATE area_purchases SET area_id=?, item=?, cost=?, purchase_date=?, notes=? WHERE id=?"
            )->execute([$area_id_val, $form['item'], $cost, $form['purchase_date'], $notes_val, $purchase_id]);
            auditLog($user_id, 'UPDATE_AREA_PURCHASE', 'area_purchases', $purchase_id, $existing, $form);
            flashMessage('success', 'Purchase record updated.');
        } else {
            $db->prepare(
                "INSERT INTO area_purchases (farm_id, area_id, item, cost, purchase_date, notes) VALUES (?,?,?,?,?,?)"
            )->execute([fid(), $area_id_val, $form['item'], $cost, $form['purchase_date'], $notes_val]);
            $new_id = (int)$db->lastInsertId();

            // Auto-create finance transaction
            $fn = "Area purchase: {$form['item']}";
            $db->prepare(
                "INSERT INTO finance_transactions (farm_id, type, category, amount, related_module, reference_id, transaction_date, recorded_by, approved_by, notes)
                 VALUES (?, 'expense','Maintenance Cost',?,'area_purchases',?,?,?,?,?)"
            )->execute([fid(), $cost, $new_id, $form['purchase_date'], $user_id, $user_id, $fn]);

            auditLog($user_id, 'CREATE_AREA_PURCHASE', 'area_purchases', $new_id, null, $form);
            flashMessage('success', 'Area purchase recorded.');
        }
        redirect('/modules/maintenance/index.php?tab=purchases');
    }
}

$page_title = $is_edit ? 'Edit Area Purchase' : 'Record Area Purchase';
$active_nav = 'maintenance';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2><?= $is_edit ? 'Edit Area Purchase' : 'Record Area Purchase' ?></h2></div>
    <a href="/modules/maintenance/index.php?tab=purchases" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (empty($area_list)): ?>
<div class="alert" style="background:var(--warning-soft,#FFFBEB);border:1px solid #D97706;color:#92400E;border-radius:var(--radius);padding:.75rem 1rem;margin-bottom:1.25rem">
    No farm areas exist yet. <a href="/modules/maintenance/area_form.php" style="color:inherit;font-weight:600">Add a farm area first →</a>
</div>
<?php endif; ?>

<form method="POST" action="/modules/maintenance/purchase_form.php<?= $is_edit ? "?id={$purchase_id}" : '' ?>" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:520px;margin-bottom:1.25rem">
        <div class="card-body">

            <div class="form-group">
                <label class="form-label" for="area_id">Farm Area <span style="color:var(--danger)">*</span></label>
                <select id="area_id" name="area_id" class="form-control" required>
                    <option value="">— Select area —</option>
                    <?php foreach ($area_list as $ar): ?>
                    <option value="<?= $ar['id'] ?>" <?= (string)$ar['id']===(string)$form['area_id']?'selected':'' ?>>
                        <?= e($ar['name']) ?> (<?= e(ucfirst(str_replace('_',' ',$ar['type']))) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="item">Item / Description <span style="color:var(--danger)">*</span></label>
                <input type="text" id="item" name="item" class="form-control"
                       value="<?= e($form['item']) ?>" maxlength="200"
                       placeholder="e.g. Roof repair materials, Feed storage bin" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="cost">Cost (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="cost" name="cost" class="form-control"
                           value="<?= e($form['cost']) ?>" step="0.01" min="0.01" placeholder="0.00" required>
                    <?php if (!$is_edit): ?>
                    <span class="form-hint">Auto-creates a finance expense entry.</span>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="purchase_date">Purchase Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="purchase_date" name="purchase_date" class="form-control"
                           value="<?= e($form['purchase_date']) ?>"
                           max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="Vendor, invoice number, purpose…"><?= e($form['notes']) ?></textarea>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary" <?= empty($area_list) ? 'disabled' : '' ?>>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Record Purchase' ?>
        </button>
        <a href="/modules/maintenance/index.php?tab=purchases" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
