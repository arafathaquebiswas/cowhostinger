<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin']);
requireModule('maintenance');

$db     = getDB();
$log_id = (int)($_GET['id'] ?? 0);
$is_edit = $log_id > 0;
$existing = null;

$errors = [];
$form = [
    'equipment_id'   => '',
    'area_id'        => '',
    'description'    => '',
    'cost'           => '',
    'scheduled_date' => '',
    'completed_date' => '',
    'notes'          => '',
    'photo_url'      => null,
];

$equipment_list = $db->query("SELECT id, name FROM equipment ORDER BY name ASC")->fetchAll();
$area_list      = $db->query("SELECT id, name FROM farm_areas ORDER BY name ASC")->fetchAll();

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM maintenance_logs WHERE id = ?");
    $sel->execute([$log_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Maintenance log not found.');
        redirect('/modules/maintenance/index.php?tab=logs');
    }
    $form = [
        'equipment_id'   => $existing['equipment_id'] ?? '',
        'area_id'        => $existing['area_id']      ?? '',
        'description'    => $existing['description'],
        'cost'           => $existing['cost']           ?? '',
        'scheduled_date' => $existing['scheduled_date'] ?? '',
        'completed_date' => $existing['completed_date'] ?? '',
        'notes'          => '',
        'photo_url'      => $existing['photo_url'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/maintenance/log_form.php' . ($is_edit ? "?id={$log_id}" : ''));
    }

    $form['equipment_id']   = trim($_POST['equipment_id']   ?? '');
    $form['area_id']        = trim($_POST['area_id']        ?? '');
    $form['description']    = sanitize($_POST['description']    ?? '');
    $form['cost']           = trim($_POST['cost']           ?? '');
    $form['scheduled_date'] = trim($_POST['scheduled_date'] ?? '');
    $form['completed_date'] = trim($_POST['completed_date'] ?? '');

    $equipment_id = $form['equipment_id'] !== '' ? (int)$form['equipment_id'] : null;
    $area_id      = $form['area_id']      !== '' ? (int)$form['area_id']      : null;

    if ($form['description'] === '') $errors[] = 'Description is required.';

    $cost = $form['cost'] !== '' ? (float)$form['cost'] : null;
    if ($cost !== null && $cost < 0) $errors[] = 'Cost cannot be negative.';

    if ($form['scheduled_date'] !== '' && !strtotime($form['scheduled_date'])) $errors[] = 'Invalid scheduled date.';
    if ($form['completed_date'] !== '' && !strtotime($form['completed_date'])) $errors[] = 'Invalid completed date.';

    if ($form['scheduled_date'] !== '' && $form['completed_date'] !== ''
        && strtotime($form['completed_date']) < strtotime($form['scheduled_date'])) {
        $errors[] = 'Completed date cannot be before scheduled date.';
    }

    if ($equipment_id !== null) {
        $chk = $db->prepare("SELECT id FROM equipment WHERE id = ?");
        $chk->execute([$equipment_id]);
        if (!$chk->fetch()) { $errors[] = 'Invalid equipment selected.'; $equipment_id = null; }
    }
    if ($area_id !== null) {
        $chk = $db->prepare("SELECT id FROM farm_areas WHERE id = ?");
        $chk->execute([$area_id]);
        if (!$chk->fetch()) { $errors[] = 'Invalid farm area selected.'; $area_id = null; }
    }

    // Photo upload
    $photo_url = $form['photo_url'];
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = uploadImage($_FILES['photo'], 'maintenance');
        if ($uploaded === false) {
            $errors[] = 'Photo upload failed. Allowed: JPG, PNG, WebP. Max 5 MB.';
        } else {
            $photo_url = $uploaded;
        }
    }

    if (empty($errors)) {
        $user_id     = (int)$_SESSION['user_id'];
        $sched_val   = $form['scheduled_date'] !== '' ? $form['scheduled_date'] : null;
        $compl_val   = $form['completed_date'] !== '' ? $form['completed_date'] : null;

        if ($is_edit) {
            $db->prepare(
                "UPDATE maintenance_logs SET equipment_id=?, area_id=?, description=?, cost=?,
                 scheduled_date=?, completed_date=?, photo_url=? WHERE id=?"
            )->execute([$equipment_id, $area_id, $form['description'], $cost, $sched_val, $compl_val, $photo_url, $log_id]);
            auditLog($user_id, 'UPDATE_MAINTENANCE_LOG', 'maintenance_logs', $log_id, $existing, $form);
            flashMessage('success', 'Maintenance log updated.');
        } else {
            $db->prepare(
                "INSERT INTO maintenance_logs (equipment_id, area_id, description, cost, scheduled_date, completed_date, photo_url)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$equipment_id, $area_id, $form['description'], $cost, $sched_val, $compl_val, $photo_url]);
            $new_id = (int)$db->lastInsertId();

            // Auto-create finance transaction if cost > 0
            if ($cost > 0) {
                $target = $equipment_id ? 'equipment' : ($area_id ? 'area' : 'general');
                $fn = "Maintenance cost ({$target})";
                $db->prepare(
                    "INSERT INTO finance_transactions (type, category, amount, related_module, reference_id, transaction_date, recorded_by, approved_by, notes)
                     VALUES ('expense','Maintenance Cost',?,?,?,?,?,?,?)"
                )->execute([$cost, 'maintenance', $new_id, date('Y-m-d'), $user_id, $user_id, $fn]);
            }

            auditLog($user_id, 'CREATE_MAINTENANCE_LOG', 'maintenance_logs', $new_id, null, $form);
            flashMessage('success', 'Maintenance log added.');
        }
        redirect('/modules/maintenance/index.php?tab=logs');
    }
}

$page_title = $is_edit ? 'Edit Maintenance Log' : 'Add Maintenance Log';
$active_nav = 'maintenance';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2><?= $is_edit ? 'Edit Maintenance Log' : 'Add Maintenance Log' ?></h2></div>
    <a href="/modules/maintenance/index.php?tab=logs" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/maintenance/log_form.php<?= $is_edit ? "?id={$log_id}" : '' ?>"
      enctype="multipart/form-data" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:640px;margin-bottom:1.25rem">
        <div class="card-body">

            <p class="text-sm text-muted" style="margin-bottom:1rem">Link this log to an equipment item, a farm area, or leave both blank for a general maintenance entry.</p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="equipment_id">Equipment (optional)</label>
                    <select id="equipment_id" name="equipment_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($equipment_list as $eq): ?>
                        <option value="<?= $eq['id'] ?>" <?= (string)$eq['id']===$form['equipment_id']?'selected':'' ?>>
                            <?= e($eq['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="area_id">Farm Area (optional)</label>
                    <select id="area_id" name="area_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($area_list as $ar): ?>
                        <option value="<?= $ar['id'] ?>" <?= (string)$ar['id']===$form['area_id']?'selected':'' ?>>
                            <?= e($ar['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="description">Description <span style="color:var(--danger)">*</span></label>
                <textarea id="description" name="description" class="form-control" rows="3"
                          placeholder="What maintenance was performed or is scheduled?" required><?= e($form['description']) ?></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="cost">Cost (৳)</label>
                    <input type="number" id="cost" name="cost" class="form-control"
                           value="<?= e($form['cost']) ?>" step="0.01" min="0" placeholder="0.00">
                    <?php if (!$is_edit): ?>
                    <span class="form-hint">Auto-creates a finance expense entry.</span>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="scheduled_date">Scheduled Date</label>
                    <input type="date" id="scheduled_date" name="scheduled_date" class="form-control"
                           value="<?= e($form['scheduled_date'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="completed_date">Completed Date</label>
                    <input type="date" id="completed_date" name="completed_date" class="form-control"
                           value="<?= e($form['completed_date'] ?? '') ?>">
                    <span class="form-hint">Leave blank if pending.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="photo">Photo / Evidence</label>
                <input type="file" id="photo" name="photo" class="form-control"
                       accept="image/jpeg,image/png,image/webp">
                <span class="form-hint">JPG, PNG, or WebP. Max 5 MB.</span>
                <div class="photo-preview-wrap">
                    <?php if ($form['photo_url']): ?>
                    <img src="<?= e($form['photo_url']) ?>" alt="Current photo" style="max-height:120px;border-radius:var(--radius)">
                    <span class="form-hint">Current photo. Upload new to replace.</span>
                    <?php endif; ?>
                    <img src="" class="photo-preview-new" id="newPhotoPreview" alt="">
                </div>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Add Log' ?>
        </button>
        <a href="/modules/maintenance/index.php?tab=logs" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php
$inline_js = <<<'JS'
document.getElementById('photo').addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var preview = document.getElementById('newPhotoPreview');
    var reader  = new FileReader();
    reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(file);
});
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
