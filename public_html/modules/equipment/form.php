<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin']);
requireModule('equipment');

$db     = getDB();
$eq_id  = (int)($_GET['id'] ?? 0);
$is_edit = $eq_id > 0;
$existing = null;

$errors = [];
$form = [
    'name'                  => '',
    'purchase_date'         => '',
    'status'                => 'operational',
    'lifespan_months'       => '',
    'last_maintenance_date' => '',
    'notes'                 => '',
    'photo_url'             => null,
];

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM equipment WHERE id = ?");
    $sel->execute([$eq_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Equipment not found.');
        redirect('/modules/equipment/index.php');
    }
    $form = array_merge($form, [
        'name'                  => $existing['name'],
        'purchase_date'         => $existing['purchase_date']         ?? '',
        'status'                => $existing['status'],
        'lifespan_months'       => $existing['lifespan_months']       ?? '',
        'last_maintenance_date' => $existing['last_maintenance_date'] ?? '',
        'notes'                 => $existing['notes']                 ?? '',
        'photo_url'             => $existing['photo_url'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/equipment/form.php' . ($is_edit ? "?id={$eq_id}" : ''));
    }

    $form['name']                  = sanitize($_POST['name']                  ?? '');
    $form['purchase_date']         = trim($_POST['purchase_date']         ?? '');
    $form['status']                = sanitize($_POST['status']                ?? 'operational');
    $form['lifespan_months']       = trim($_POST['lifespan_months']       ?? '');
    $form['last_maintenance_date'] = trim($_POST['last_maintenance_date'] ?? '');
    $form['notes']                 = sanitize($_POST['notes']                 ?? '');

    // Validation
    if ($form['name'] === '') $errors[] = 'Equipment name is required.';
    if (strlen($form['name']) > 150) $errors[] = 'Name is too long.';

    $valid_statuses = ['operational', 'maintenance', 'damaged'];
    if (!in_array($form['status'], $valid_statuses, true)) $errors[] = 'Invalid status.';

    $lifespan = $form['lifespan_months'] !== '' ? (int)$form['lifespan_months'] : null;
    if ($lifespan !== null && ($lifespan < 1 || $lifespan > 600)) $errors[] = 'Lifespan must be between 1 and 600 months.';

    if ($form['purchase_date'] !== '' && !strtotime($form['purchase_date'])) $errors[] = 'Invalid purchase date.';
    if ($form['last_maintenance_date'] !== '' && !strtotime($form['last_maintenance_date'])) $errors[] = 'Invalid maintenance date.';

    // Photo upload
    $photo_url = $form['photo_url'];
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = uploadImage($_FILES['photo'], 'equipment');
        if ($uploaded === false) {
            $errors[] = 'Photo upload failed. Allowed: JPG, PNG, WebP. Max: 5 MB.';
        } else {
            $photo_url = $uploaded;
        }
    }

    if (empty($errors)) {
        $user_id   = (int)$_SESSION['user_id'];
        $pdate     = $form['purchase_date']         !== '' ? $form['purchase_date']         : null;
        $lmdate    = $form['last_maintenance_date'] !== '' ? $form['last_maintenance_date'] : null;
        $notes_val = $form['notes'] !== '' ? $form['notes'] : null;

        if ($is_edit) {
            $db->prepare(
                "UPDATE equipment SET name=?, purchase_date=?, status=?, lifespan_months=?,
                 last_maintenance_date=?, photo_url=?, notes=? WHERE id=?"
            )->execute([$form['name'], $pdate, $form['status'], $lifespan, $lmdate, $photo_url, $notes_val, $eq_id]);
            auditLog($user_id, 'UPDATE_EQUIPMENT', 'equipment', $eq_id, $existing, $form);
            flashMessage('success', "Equipment '{$form['name']}' updated.");
        } else {
            $db->prepare(
                "INSERT INTO equipment (name, purchase_date, status, lifespan_months, last_maintenance_date, photo_url, notes)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$form['name'], $pdate, $form['status'], $lifespan, $lmdate, $photo_url, $notes_val]);
            $new_id = (int)$db->lastInsertId();
            auditLog($user_id, 'CREATE_EQUIPMENT', 'equipment', $new_id, null, $form);
            flashMessage('success', "Equipment '{$form['name']}' added.");
        }
        redirect('/modules/equipment/index.php');
    }
}

$page_title = $is_edit ? "Edit Equipment — {$form['name']}" : 'Add Equipment';
$active_nav = 'equipment';

$status_options = ['operational' => 'Operational', 'maintenance' => 'In Maintenance', 'damaged' => 'Damaged'];

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2><?= $is_edit ? 'Edit Equipment' : 'Add Equipment' ?></h2></div>
    <a href="/modules/equipment/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/equipment/form.php<?= $is_edit ? "?id={$eq_id}" : '' ?>"
      enctype="multipart/form-data" novalidate>
    <?= csrfField() ?>

    <div class="card" style="margin-bottom:1.25rem;max-width:640px">
        <div class="card-body">
            <div class="form-group">
                <label class="form-label" for="name">Equipment Name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="name" name="name" class="form-control"
                       value="<?= e($form['name']) ?>" maxlength="150"
                       placeholder="e.g. Milking Machine #1, Feed Mixer" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <?php foreach ($status_options as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $form['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="purchase_date">Purchase Date</label>
                    <input type="date" id="purchase_date" name="purchase_date" class="form-control"
                           value="<?= e($form['purchase_date'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="lifespan_months">Expected Lifespan (months)</label>
                    <input type="number" id="lifespan_months" name="lifespan_months" class="form-control"
                           value="<?= e($form['lifespan_months'] ?? '') ?>"
                           min="1" max="600" placeholder="e.g. 60">
                    <span class="form-hint">Used to predict maintenance schedules.</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="last_maintenance_date">Last Maintenance Date</label>
                    <input type="date" id="last_maintenance_date" name="last_maintenance_date" class="form-control"
                           value="<?= e($form['last_maintenance_date'] ?? '') ?>">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="photo">Photo</label>
                    <input type="file" id="photo" name="photo" class="form-control"
                           accept="image/jpeg,image/png,image/webp">
                    <span class="form-hint">JPG, PNG, or WebP. Max 5 MB.</span>
                    <div class="photo-preview-wrap">
                        <?php if ($form['photo_url']): ?>
                        <img src="<?= e($form['photo_url']) ?>" alt="Current photo">
                        <span class="form-hint">Current photo. Upload new to replace.</span>
                        <?php endif; ?>
                        <img src="" class="photo-preview-new" id="newPhotoPreview" alt="">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="5"
                              placeholder="Model number, serial, location…"><?= e($form['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Add Equipment' ?>
        </button>
        <a href="/modules/equipment/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php
$inline_js = <<<'JS'
document.getElementById('photo').addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var preview = document.getElementById('newPhotoPreview');
    var reader  = new FileReader();
    reader.onload = function(e) {
        preview.src = e.target.result;
        preview.style.display = 'block';
    };
    reader.readAsDataURL(file);
});
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
