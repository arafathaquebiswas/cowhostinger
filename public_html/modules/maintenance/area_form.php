<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin']);
requireFarmScope();
requireNotBlocked();
requireModule('maintenance');

$db      = getDB();
$area_id = (int)($_GET['id'] ?? 0);
$is_edit = $area_id > 0;
$existing = null;

$errors = [];
$form = [
    'name'     => '',
    'type'     => 'other',
    'capacity' => '',
    'notes'    => '',
];

$type_options = [
    'barn'         => 'Barn',
    'storage'      => 'Storage',
    'milking_shed' => 'Milking Shed',
    'medical'      => 'Medical / Vet Area',
    'office'       => 'Office',
    'other'        => 'Other',
];

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM farm_areas WHERE id = ? AND " . farmFilter());
    $sel->execute([$area_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Farm area not found.');
        redirect('/modules/maintenance/index.php?tab=areas');
    }
    $form = [
        'name'     => $existing['name'],
        'type'     => $existing['type'],
        'capacity' => $existing['capacity'] ?? '',
        'notes'    => $existing['notes']    ?? '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/maintenance/area_form.php' . ($is_edit ? "?id={$area_id}" : ''));
    }

    $form['name']     = sanitize($_POST['name']     ?? '');
    $form['type']     = sanitize($_POST['type']     ?? 'other');
    $form['capacity'] = trim($_POST['capacity'] ?? '');
    $form['notes']    = sanitize($_POST['notes']    ?? '');

    if ($form['name'] === '') $errors[] = 'Area name is required.';
    if (strlen($form['name']) > 150) $errors[] = 'Name is too long.';
    if (!array_key_exists($form['type'], $type_options)) $errors[] = 'Invalid area type.';

    $capacity = $form['capacity'] !== '' ? (int)$form['capacity'] : null;
    if ($capacity !== null && $capacity < 0) $errors[] = 'Capacity cannot be negative.';

    if (empty($errors)) {
        $user_id   = (int)$_SESSION['user_id'];
        $notes_val = $form['notes'] !== '' ? $form['notes'] : null;

        if ($is_edit) {
            $db->prepare(
                "UPDATE farm_areas SET name=?, type=?, capacity=?, notes=? WHERE id=? AND " . farmFilter()
            )->execute([$form['name'], $form['type'], $capacity, $notes_val, $area_id]);
            auditLog($user_id, 'UPDATE_FARM_AREA', 'farm_areas', $area_id, $existing, $form);
            flashMessage('success', "Farm area '{$form['name']}' updated.");
        } else {
            $db->prepare(
                "INSERT INTO farm_areas (farm_id, name, type, capacity, notes) VALUES (?,?,?,?,?)"
            )->execute([fid(), $form['name'], $form['type'], $capacity, $notes_val]);
            $new_id = (int)$db->lastInsertId();
            auditLog($user_id, 'CREATE_FARM_AREA', 'farm_areas', $new_id, null, $form);
            flashMessage('success', "Farm area '{$form['name']}' added.");
        }
        redirect('/modules/maintenance/index.php?tab=areas');
    }
}

$page_title = $is_edit ? "Edit Area — {$form['name']}" : 'Add Farm Area';
$active_nav = 'maintenance';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2><?= $is_edit ? 'Edit Farm Area' : 'Add Farm Area' ?></h2></div>
    <a href="/modules/maintenance/index.php?tab=areas" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/maintenance/area_form.php<?= $is_edit ? "?id={$area_id}" : '' ?>" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:480px;margin-bottom:1.25rem">
        <div class="card-body">

            <div class="form-group">
                <label class="form-label" for="name">Area Name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="name" name="name" class="form-control"
                       value="<?= e($form['name']) ?>" maxlength="150"
                       placeholder="e.g. Main Barn, Milking Shed A" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="type">Type</label>
                    <select id="type" name="type" class="form-control">
                        <?php foreach ($type_options as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $form['type'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="capacity">Capacity (head)</label>
                    <input type="number" id="capacity" name="capacity" class="form-control"
                           value="<?= e($form['capacity']) ?>" min="0" placeholder="e.g. 50">
                    <span class="form-hint">Max animals this area holds.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3"
                          placeholder="Location, description, special requirements…"><?= e($form['notes']) ?></textarea>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Add Area' ?>
        </button>
        <a href="/modules/maintenance/index.php?tab=areas" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
