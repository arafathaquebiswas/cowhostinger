<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();
// Redirect to the dedicated treatments module
$qs = isset($_GET['cow_id']) ? '?cow_id=' . (int)$_GET['cow_id'] : '';
redirect('/modules/treatments/form.php' . $qs);

$db = getDB();
$errors = [];
$prefill_cow = (int)($_GET['cow_id'] ?? 0);

$form = [
    'cow_id'         => $prefill_cow > 0 ? (string)$prefill_cow : '',
    'medicine_id'    => '',
    'dosage'         => '',
    'cost'           => '',
    'treatment_date' => date('Y-m-d'),
    'notes'          => '',
];

$cows = $db->query(
    "SELECT id, tag_number, breed FROM cows WHERE status NOT IN ('sold','deceased') ORDER BY tag_number ASC"
)->fetchAll();
$medicines = $db->query(
    "SELECT id, item_name, quantity, unit FROM medicine_inventory ORDER BY item_name ASC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/feed_medicine/treatment_form.php');
    }

    $form['cow_id']         = trim($_POST['cow_id'] ?? '');
    $form['medicine_id']    = trim($_POST['medicine_id'] ?? '');
    $form['dosage']         = sanitize($_POST['dosage'] ?? '');
    $form['cost']           = trim($_POST['cost'] ?? '');
    $form['treatment_date'] = trim($_POST['treatment_date'] ?? '');
    $form['notes']          = sanitize($_POST['notes'] ?? '');

    $cow_id = (int)$form['cow_id'];
    $medicine_id = $form['medicine_id'] !== '' ? (int)$form['medicine_id'] : null;
    $cost = $form['cost'] !== '' ? (float)$form['cost'] : null;

    if ($cow_id <= 0) $errors[] = 'Please select a cow.';
    if ($form['treatment_date'] === '' || !strtotime($form['treatment_date'])) $errors[] = 'Treatment date is required.';
    if ($cost !== null && $cost < 0) $errors[] = 'Cost cannot be negative.';

    $cow = null;
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id, tag_number FROM cows WHERE id = ? AND status NOT IN ('sold','deceased')");
        $stmt->execute([$cow_id]);
        $cow = $stmt->fetch();
        if (!$cow) $errors[] = 'Selected cow is not available.';
    }

    if ($medicine_id !== null) {
        $stmt = $db->prepare("SELECT id FROM medicine_inventory WHERE id = ?");
        $stmt->execute([$medicine_id]);
        if (!$stmt->fetch()) $errors[] = 'Selected medicine is invalid.';
    }

    $photo_url = null;
    if (empty($errors) && !empty($_FILES['photo']['name'])) {
        $uploaded = uploadImage($_FILES['photo'], 'treatments');
        if ($uploaded === false) {
            $errors[] = 'Treatment photo must be JPG, PNG, or WebP and no larger than 5MB.';
        } else {
            $photo_url = $uploaded;
        }
    }

    if (empty($errors)) {
        $user_id = (int)$_SESSION['user_id'];
        $notes = $form['notes'] !== '' ? $form['notes'] : null;
        $dosage = $form['dosage'] !== '' ? $form['dosage'] : null;

        $db->prepare(
            "INSERT INTO treatments (cow_id, medicine_id, administered_by, dosage, cost, treatment_date, notes, photo_url)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$cow_id, $medicine_id, $user_id, $dosage, $cost, $form['treatment_date'], $notes, $photo_url]);
        $new_id = (int)$db->lastInsertId();

        if ($cost !== null && $cost > 0) {
            $db->prepare(
                "INSERT INTO finance_transactions (type, category, amount, related_module, reference_id, transaction_date, recorded_by, approved_by, notes)
                 VALUES ('expense', 'Treatment', ?, 'treatments', ?, ?, ?, ?, ?)"
            )->execute([$cost, $new_id, $form['treatment_date'], $user_id, $user_id, "Treatment cost for Cow #{$cow['tag_number']}"]);
        }

        auditLog($user_id, 'CREATE_TREATMENT', 'treatments', $new_id, null, $form);
        flashMessage('success', "Treatment recorded for Cow #{$cow['tag_number']}.");
        redirect("/modules/cows/view.php?id={$cow_id}&tab=treatments");
    }
}

$page_title = 'Record Treatment';
$active_nav = 'feed_medicine';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Record Treatment</h2>
        <p class="text-sm text-muted">Medicine administered, dosage, cost, and evidence photo</p>
    </div>
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

<form method="POST" action="/modules/feed_medicine/treatment_form.php" enctype="multipart/form-data" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:720px;margin-bottom:1.25rem">
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="cow_id">Cow <span style="color:var(--danger)">*</span></label>
                    <select id="cow_id" name="cow_id" class="form-control" required>
                        <option value="">Select cow</option>
                        <?php foreach ($cows as $cow): ?>
                        <option value="<?= $cow['id'] ?>" <?= (string)$cow['id'] === $form['cow_id'] ? 'selected' : '' ?>>
                            #<?= e($cow['tag_number']) ?> - <?= e($cow['breed']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="medicine_id">Medicine</label>
                    <select id="medicine_id" name="medicine_id" class="form-control">
                        <option value="">No medicine selected</option>
                        <?php foreach ($medicines as $medicine): ?>
                        <option value="<?= $medicine['id'] ?>" <?= (string)$medicine['id'] === $form['medicine_id'] ? 'selected' : '' ?>>
                            <?= e($medicine['item_name']) ?> (<?= e(number_format((float)$medicine['quantity'], 2)) ?> <?= e($medicine['unit']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="dosage">Dosage</label>
                    <input type="text" id="dosage" name="dosage" class="form-control" value="<?= e($form['dosage']) ?>" maxlength="100" placeholder="e.g. 10 ml twice daily">
                </div>
                <div class="form-group">
                    <label class="form-label" for="cost">Cost</label>
                    <input type="number" id="cost" name="cost" class="form-control" value="<?= e($form['cost']) ?>" min="0" step="0.01" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label" for="treatment_date">Treatment Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="treatment_date" name="treatment_date" class="form-control" value="<?= e($form['treatment_date']) ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="photo">Evidence Photo</label>
                <input type="file" id="photo" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp">
                <span class="form-hint">JPG, PNG, or WebP. Maximum 5MB.</span>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Symptoms, response, follow-up instructions"><?= e($form['notes']) ?></textarea>
            </div>
        </div>
    </div>
    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">Save Treatment</button>
        <a href="/modules/feed_medicine/index.php?tab=medicine" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
