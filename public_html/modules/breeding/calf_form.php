<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'veterinarian']);
requireFarmScope();
requireModule('breeding');

$db = getDB();
$br_id = (int)($_GET['br_id'] ?? $_POST['br_id'] ?? 0);

if ($br_id <= 0) {
    flashMessage('error', 'Breeding record is required.');
    redirect('/modules/breeding/index.php');
}

$stmt = $db->prepare(
    "SELECT br.*, c.tag_number AS mother_tag, c.breed
     FROM breeding_records br
     JOIN cows c ON c.id = br.cow_id
     WHERE br.id = ? AND " . farmFilter('br')
);
$stmt->execute([$br_id]);
$breeding = $stmt->fetch();
if (!$breeding) {
    flashMessage('error', 'Breeding record not found.');
    redirect('/modules/breeding/index.php');
}

$errors = [];
$form = [
    'calf_tag_number' => '',
    'birth_date'      => $breeding['actual_calving_date'] ?: date('Y-m-d'),
    'birth_weight'    => '',
    'gender'          => 'female',
    'status'          => 'alive',
    'notes'           => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect("/modules/breeding/calf_form.php?br_id={$br_id}");
    }

    $form['calf_tag_number'] = sanitize($_POST['calf_tag_number'] ?? '');
    $form['birth_date']      = trim($_POST['birth_date'] ?? '');
    $form['birth_weight']    = trim($_POST['birth_weight'] ?? '');
    $form['gender']          = sanitize($_POST['gender'] ?? '');
    $form['status']          = sanitize($_POST['status'] ?? '');
    $form['notes']           = sanitize($_POST['notes'] ?? '');

    $birth_weight = $form['birth_weight'] !== '' ? (float)$form['birth_weight'] : null;
    $valid_genders = ['male', 'female'];
    $valid_statuses = ['alive', 'deceased', 'sold'];

    if ($form['calf_tag_number'] === '') $errors[] = 'Calf tag number is required.';
    if ($form['birth_date'] === '' || !strtotime($form['birth_date'])) $errors[] = 'Birth date is required.';
    if ($birth_weight !== null && $birth_weight < 0) $errors[] = 'Birth weight cannot be negative.';
    if (!in_array($form['gender'], $valid_genders, true)) $errors[] = 'Invalid gender selected.';
    if (!in_array($form['status'], $valid_statuses, true)) $errors[] = 'Invalid calf status selected.';

    if (empty($errors)) {
        $chk = $db->prepare("SELECT id FROM calf_records WHERE calf_tag_number = ? AND " . farmFilter());
        $chk->execute([$form['calf_tag_number']]);
        if ($chk->fetch()) $errors[] = 'That calf tag number is already in use.';
    }

    if (empty($errors)) {
        $user_id = (int)$_SESSION['user_id'];
        $notes = $form['notes'] !== '' ? $form['notes'] : null;

        $db->prepare(
            "INSERT INTO calf_records
             (farm_id, breeding_record_id, mother_cow_id, calf_tag_number, birth_date, birth_weight, gender, status, notes)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            fid(),
            $br_id,
            (int)$breeding['cow_id'],
            $form['calf_tag_number'],
            $form['birth_date'],
            $birth_weight,
            $form['gender'],
            $form['status'],
            $notes,
        ]);
        $calf_id = (int)$db->lastInsertId();

        $db->prepare(
            "UPDATE breeding_records SET status='calved', actual_calving_date=? WHERE id=?"
        )->execute([$form['birth_date'], $br_id]);
        $db->prepare(
            "UPDATE cows SET is_pregnant=0, status=IF(status IN ('sold','deceased'), status, 'lactating') WHERE id=?"
        )->execute([(int)$breeding['cow_id']]);

        auditLog($user_id, 'CREATE_CALF_RECORD', 'calf_records', $calf_id, null, $form);
        auditLog($user_id, 'MARK_BREEDING_CALVED', 'breeding_records', $br_id, $breeding, ['actual_calving_date' => $form['birth_date']]);

        flashMessage('success', "Calf #{$form['calf_tag_number']} recorded for Cow #{$breeding['mother_tag']}.");
        redirect('/modules/breeding/index.php?status=calved');
    }
}

$page_title = 'Record Calving';
$active_nav = 'breeding';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Record Calving</h2>
        <p class="text-sm text-muted">Mother Cow #<?= e($breeding['mother_tag']) ?> - <?= e($breeding['breed']) ?></p>
    </div>
    <a href="/modules/breeding/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/breeding/calf_form.php" novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="br_id" value="<?= $br_id ?>">

    <div class="card" style="max-width:680px;margin-bottom:1.25rem">
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="calf_tag_number">Calf Tag Number <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="calf_tag_number" name="calf_tag_number" class="form-control" value="<?= e($form['calf_tag_number']) ?>" maxlength="50" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="birth_date">Birth Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="birth_date" name="birth_date" class="form-control" value="<?= e($form['birth_date']) ?>" required>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="birth_weight">Birth Weight</label>
                    <input type="number" id="birth_weight" name="birth_weight" class="form-control" value="<?= e($form['birth_weight']) ?>" min="0" step="0.01" placeholder="kg">
                </div>
                <div class="form-group">
                    <label class="form-label" for="gender">Gender</label>
                    <select id="gender" name="gender" class="form-control">
                        <option value="female" <?= $form['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="male" <?= $form['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="alive" <?= $form['status'] === 'alive' ? 'selected' : '' ?>>Alive</option>
                        <option value="deceased" <?= $form['status'] === 'deceased' ? 'selected' : '' ?>>Deceased</option>
                        <option value="sold" <?= $form['status'] === 'sold' ? 'selected' : '' ?>>Sold</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Delivery details, calf condition, follow-up care"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">Save Calf Record</button>
        <a href="/modules/breeding/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
