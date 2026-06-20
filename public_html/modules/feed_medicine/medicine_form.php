<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin']);
requireModule('feed_medicine');

$db      = getDB();
$item_id = (int)($_GET['id'] ?? 0);
$is_edit = $item_id > 0;
$existing = null;

$errors = [];
$form = [
    'item_name'         => '',
    'quantity'          => '',
    'unit'              => 'units',
    'expiry_date'       => '',
    'reorder_threshold' => '',
];

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM medicine_inventory WHERE id = ?");
    $sel->execute([$item_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Medicine item not found.');
        redirect('/modules/feed_medicine/index.php?tab=medicine');
    }
    $form = array_merge($form, [
        'item_name'         => $existing['item_name'],
        'quantity'          => $existing['quantity'],
        'unit'              => $existing['unit'],
        'expiry_date'       => $existing['expiry_date'] ?? '',
        'reorder_threshold' => $existing['reorder_threshold'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/feed_medicine/medicine_form.php' . ($is_edit ? "?id={$item_id}" : ''));
    }

    $form['item_name']         = sanitize($_POST['item_name']         ?? '');
    $form['unit']              = sanitize($_POST['unit']              ?? 'units');
    $form['quantity']          = trim($_POST['quantity']          ?? '');
    $form['expiry_date']       = trim($_POST['expiry_date']       ?? '');
    $form['reorder_threshold'] = trim($_POST['reorder_threshold'] ?? '');

    if ($form['item_name'] === '') $errors[] = 'Medicine name is required.';
    if (strlen($form['item_name']) > 150) $errors[] = 'Name is too long.';
    if ($form['unit'] === '')      $errors[] = 'Unit is required.';

    $qty = $form['quantity'] !== '' ? (float)$form['quantity'] : null;
    if ($qty === null || $qty < 0) $errors[] = 'Quantity must be 0 or greater.';

    $threshold = $form['reorder_threshold'] !== '' ? (float)$form['reorder_threshold'] : 0.0;
    if ($threshold < 0) $errors[] = 'Reorder threshold cannot be negative.';

    $expiry = $form['expiry_date'] !== '' ? $form['expiry_date'] : null;
    if ($expiry !== null && !strtotime($expiry)) $errors[] = 'Invalid expiry date.';

    if (empty($errors)) {
        $user_id = (int)$_SESSION['user_id'];
        if ($is_edit) {
            $db->prepare(
                "UPDATE medicine_inventory SET item_name=?, quantity=?, unit=?, expiry_date=?, reorder_threshold=? WHERE id=?"
            )->execute([$form['item_name'], $qty, $form['unit'], $expiry, $threshold, $item_id]);
            auditLog($user_id, 'UPDATE_MEDICINE_ITEM', 'medicine_inventory', $item_id, $existing, $form);
            flashMessage('success', "Medicine '{$form['item_name']}' updated.");
        } else {
            $db->prepare(
                "INSERT INTO medicine_inventory (item_name, quantity, unit, expiry_date, reorder_threshold) VALUES (?,?,?,?,?)"
            )->execute([$form['item_name'], $qty, $form['unit'], $expiry, $threshold]);
            $new_id = (int)$db->lastInsertId();
            auditLog($user_id, 'CREATE_MEDICINE_ITEM', 'medicine_inventory', $new_id, null, $form);
            flashMessage('success', "Medicine '{$form['item_name']}' added.");
        }
        redirect('/modules/feed_medicine/index.php?tab=medicine');
    }
}

$page_title = $is_edit ? "Edit Medicine — {$form['item_name']}" : 'Add Medicine';
$active_nav = 'feed_medicine';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2><?= $is_edit ? 'Edit Medicine' : 'Add Medicine' ?></h2></div>
    <a href="/modules/feed_medicine/index.php?tab=medicine" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/feed_medicine/medicine_form.php<?= $is_edit ? "?id={$item_id}" : '' ?>" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:540px;margin-bottom:1.25rem">
        <div class="card-body">
            <div class="form-group">
                <label class="form-label" for="item_name">Medicine Name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="item_name" name="item_name" class="form-control"
                       value="<?= e($form['item_name']) ?>" maxlength="150"
                       placeholder="e.g. Oxytetracycline, Vitamin B12" required>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="quantity">Current Quantity <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="quantity" name="quantity" class="form-control"
                           value="<?= e($form['quantity']) ?>"
                           step="0.01" min="0" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="unit">Unit <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="unit" name="unit" class="form-control"
                           value="<?= e($form['unit']) ?>"
                           maxlength="50" placeholder="bottles, vials, mg…" required>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="expiry_date">Expiry Date</label>
                    <input type="date" id="expiry_date" name="expiry_date" class="form-control"
                           value="<?= e($form['expiry_date'] ?? '') ?>">
                    <span class="form-hint">Alerts will be sent 30 days before expiry.</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="reorder_threshold">Reorder Threshold</label>
                    <input type="number" id="reorder_threshold" name="reorder_threshold" class="form-control"
                           value="<?= e($form['reorder_threshold']) ?>"
                           step="0.01" min="0" placeholder="0.00">
                    <span class="form-hint">Alert triggered at or below this level.</span>
                </div>
            </div>
        </div>
    </div>
    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Add Medicine' ?>
        </button>
        <a href="/modules/feed_medicine/index.php?tab=medicine" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
