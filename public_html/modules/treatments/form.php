<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'veterinarian']);
requireFarmScope();
requireNotBlocked();
requireModule('cows');

$db      = getDB();
$user_id = (int)$_SESSION['user_id'];
$edit_id = (int)($_GET['id'] ?? 0);
$is_edit = $edit_id > 0;

// Prefill cow_id from query string (e.g., coming from cow view)
$prefill_cow_id = (int)($_GET['cow_id'] ?? 0);

$treatment = null;
if ($is_edit) {
    $s = $db->prepare(
        "SELECT t.*, c.tag_number
         FROM treatments t JOIN cows c ON c.id = t.cow_id
         WHERE t.id = ? AND " . farmFilter('t')
    );
    $s->execute([$edit_id]);
    $treatment = $s->fetch();
    if (!$treatment) {
        flashMessage('error', 'Treatment record not found.');
        redirect('/modules/treatments/index.php');
    }
}

// Form defaults
$form = [
    'cow_id'         => $treatment['cow_id']       ?? $prefill_cow_id,
    'medicine_id'    => $treatment['medicine_id']   ?? '',
    'dosage'         => $treatment['dosage']        ?? '',
    'cost'           => $treatment['cost']          ?? '',
    'treatment_date' => $treatment['treatment_date'] ?? date('Y-m-d'),
    'notes'          => $treatment['notes']         ?? '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect($is_edit ? "/modules/treatments/form.php?id={$edit_id}" : '/modules/treatments/form.php');
    }

    $form['cow_id']         = (int)($_POST['cow_id']      ?? 0);
    $form['medicine_id']    = (int)($_POST['medicine_id'] ?? 0);
    $form['dosage']         = sanitize($_POST['dosage']   ?? '');
    $form['cost']           = trim($_POST['cost']          ?? '');
    $form['treatment_date'] = trim($_POST['treatment_date'] ?? '');
    $form['notes']          = sanitize($_POST['notes']     ?? '');

    $medicine_id = $form['medicine_id'] > 0 ? $form['medicine_id'] : null;
    $cost = $form['cost'] !== '' ? (float)$form['cost'] : null;
    $dosage = $form['dosage'] !== '' ? $form['dosage'] : null;
    $notes  = $form['notes'] !== '' ? $form['notes'] : null;

    // Validate
    if ($form['cow_id'] <= 0) $errors[] = 'Please select a cow.';
    if ($form['treatment_date'] === '' || !strtotime($form['treatment_date'])) {
        $errors[] = 'Treatment date is required.';
    } elseif ($form['treatment_date'] > date('Y-m-d')) {
        $errors[] = 'Treatment date cannot be in the future.';
    }
    if ($cost !== null && $cost < 0) $errors[] = 'Cost cannot be negative.';

    // Verify cow exists
    if ($form['cow_id'] > 0 && empty($errors)) {
        $cow_chk = $db->prepare("SELECT id, tag_number FROM cows WHERE id = ? AND " . farmFilter());
        $cow_chk->execute([$form['cow_id']]);
        $cow_row = $cow_chk->fetch();
        if (!$cow_row) $errors[] = 'Selected cow not found.';
    }

    // Verify medicine exists if provided
    if ($medicine_id !== null && empty($errors)) {
        $med_chk = $db->prepare("SELECT id, item_name FROM medicine_inventory WHERE id = ? AND " . farmFilter());
        $med_chk->execute([$medicine_id]);
        if (!$med_chk->fetch()) $errors[] = 'Selected medicine not found.';
    }

    // Handle photo upload
    $photo_url = $treatment['photo_url'] ?? null;
    if (!empty($_FILES['photo']['tmp_name'])) {
        $uploaded = uploadImage($_FILES['photo'], 'treatments');
        if ($uploaded === false) {
            $errors[] = 'Photo upload failed. Allowed types: JPG, PNG, WebP (max 5 MB).';
        } else {
            $photo_url = $uploaded;
        }
    }

    if (empty($errors)) {
        $administered_by = $user_id;

        if ($is_edit) {
            $old = $treatment;
            $new_data = [
                'cow_id'         => $form['cow_id'],
                'medicine_id'    => $medicine_id,
                'administered_by'=> $administered_by,
                'dosage'         => $dosage,
                'cost'           => $cost,
                'treatment_date' => $form['treatment_date'],
                'notes'          => $notes,
                'photo_url'      => $photo_url,
            ];
            $db->prepare(
                "UPDATE treatments SET cow_id=?, medicine_id=?, administered_by=?, dosage=?,
                        cost=?, treatment_date=?, notes=?, photo_url=?
                 WHERE id=? AND " . farmFilter()
            )->execute([
                $form['cow_id'], $medicine_id, $administered_by, $dosage,
                $cost, $form['treatment_date'], $notes, $photo_url,
                $edit_id,
            ]);
            auditLog($user_id, 'EDIT_TREATMENT', 'treatments', $edit_id, $old, $new_data);
            flashMessage('success', 'Treatment record updated.');
        } else {
            $db->prepare(
                "INSERT INTO treatments (farm_id, cow_id, medicine_id, administered_by, dosage, cost, treatment_date, notes, photo_url)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                fid(), $form['cow_id'], $medicine_id, $administered_by, $dosage,
                $cost, $form['treatment_date'], $notes, $photo_url,
            ]);
            $new_id = (int)$db->lastInsertId();

            // Auto-create finance expense if cost provided
            if ($cost !== null && $cost > 0) {
                $cow_tag = $cow_row['tag_number'] ?? '?';
                $fin_notes = "Veterinary treatment - Cow #{$cow_tag}"
                           . ($dosage ? " ({$dosage})" : '');
                $db->prepare(
                    "INSERT INTO finance_transactions (farm_id, type, category, amount, related_module, transaction_date, notes, recorded_by)
                     VALUES (?, 'expense', 'Veterinary Treatment', ?, 'cows', ?, ?, ?)"
                )->execute([fid(), $cost, $form['treatment_date'], $fin_notes, $user_id]);
            }

            auditLog($user_id, 'CREATE_TREATMENT', 'treatments', $new_id, null, [
                'cow_id' => $form['cow_id'], 'medicine_id' => $medicine_id,
                'cost'   => $cost, 'treatment_date' => $form['treatment_date'],
            ]);
            flashMessage('success', 'Treatment recorded successfully.');
        }

        // Redirect: back to cow view if we came from there, else global list
        if ($prefill_cow_id > 0 || $form['cow_id'] > 0) {
            redirect("/modules/cows/view.php?id={$form['cow_id']}&tab=treatments");
        }
        redirect('/modules/treatments/index.php');
    }
}

// Dropdowns
$cows = $db->prepare("SELECT id, tag_number, breed FROM cows WHERE " . farmFilter() . " AND status NOT IN ('sold','deceased') ORDER BY tag_number ASC");
$cows->execute();
$cows = $cows->fetchAll();
$medicines = $db->prepare("SELECT id, item_name, quantity, unit FROM medicine_inventory WHERE " . farmFilter() . " ORDER BY item_name ASC");
$medicines->execute();
$medicines = $medicines->fetchAll();

$page_title = $is_edit ? 'Edit Treatment' : 'Add Treatment';
$active_nav = 'treatments';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2><?= $is_edit ? 'Edit Treatment' : 'Record Treatment' ?></h2>
        <?php if ($is_edit): ?>
        <p class="text-sm text-muted">Cow #<?= e($treatment['tag_number']) ?></p>
        <?php endif; ?>
    </div>
    <a href="/modules/treatments/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/treatments/form.php<?= $is_edit ? "?id={$edit_id}" : '' ?>"
      enctype="multipart/form-data" novalidate>
    <?= csrfField() ?>

    <div style="display:grid;grid-template-columns:1fr 340px;gap:1.25rem;align-items:start">

        <!-- Left column -->
        <div>
            <div class="card" style="margin-bottom:1.25rem">
                <div class="card-header"><h4 class="card-title">Treatment Details</h4></div>
                <div class="card-body">

                    <!-- Cow -->
                    <div class="form-group">
                        <label class="form-label" for="cow_id">Cow <span style="color:var(--danger)">*</span></label>
                        <select id="cow_id" name="cow_id" class="form-control" required>
                            <option value="">Select a cow...</option>
                            <?php foreach ($cows as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (int)$form['cow_id']===(int)$c['id']?'selected':'' ?>>
                                #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Medicine -->
                    <div class="form-group">
                        <label class="form-label" for="medicine_id">Medicine Used</label>
                        <select id="medicine_id" name="medicine_id" class="form-control">
                            <option value="">None / Not Recorded</option>
                            <?php foreach ($medicines as $m): ?>
                            <option value="<?= $m['id'] ?>"
                                    data-stock="<?= (float)$m['quantity'] ?>"
                                    data-unit="<?= e($m['unit']) ?>"
                                    <?= (int)$form['medicine_id']===(int)$m['id']?'selected':'' ?>>
                                <?= e($m['item_name']) ?>
                                (<?= number_format((float)$m['quantity'], 2) ?> <?= e($m['unit']) ?> in stock)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="stock-info" class="text-muted" style="font-size:.79rem;margin-top:.25rem"></div>
                    </div>

                    <!-- Date + Dosage -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                        <div class="form-group">
                            <label class="form-label" for="treatment_date">Treatment Date <span style="color:var(--danger)">*</span></label>
                            <input type="date" id="treatment_date" name="treatment_date"
                                   class="form-control" value="<?= e($form['treatment_date']) ?>"
                                   max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="dosage">Dosage</label>
                            <input type="text" id="dosage" name="dosage" class="form-control"
                                   value="<?= e($form['dosage']) ?>"
                                   placeholder="e.g. 500mg, 2 tablets" maxlength="100">
                        </div>
                    </div>

                    <!-- Cost -->
                    <div class="form-group">
                        <label class="form-label" for="cost">Cost (৳)</label>
                        <input type="number" id="cost" name="cost" class="form-control"
                               value="<?= e($form['cost']) ?>"
                               min="0" step="0.01" placeholder="Leave blank if no cost">
                        <?php if (!$is_edit): ?>
                        <div class="text-muted" style="font-size:.78rem;margin-top:.25rem">
                            If cost &gt; 0, a Finance expense entry will be created automatically.
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Notes -->
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea id="notes" name="notes" class="form-control" rows="3"
                                  placeholder="Symptoms treated, outcome, follow-up plan..."><?= e($form['notes']) ?></textarea>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">
                    <?= $is_edit ? 'Update Treatment' : 'Save Treatment' ?>
                </button>
                <a href="/modules/treatments/index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Right: Photo -->
        <div class="card">
            <div class="card-header"><h4 class="card-title">Photo</h4></div>
            <div class="card-body">
                <?php if ($is_edit && $treatment['photo_url']): ?>
                <div style="margin-bottom:.75rem">
                    <img src="<?= e('/uploads/treatments/' . basename($treatment['photo_url'])) ?>"
                         alt="Treatment photo" style="width:100%;border-radius:var(--radius);object-fit:cover;max-height:180px">
                </div>
                <?php endif; ?>
                <div class="form-group" style="margin:0">
                    <label class="form-label" for="photo">
                        <?= ($is_edit && $treatment['photo_url']) ? 'Replace Photo' : 'Upload Photo' ?>
                    </label>
                    <input type="file" id="photo" name="photo" class="form-control"
                           accept="image/jpeg,image/png,image/webp">
                    <div class="text-muted" style="font-size:.79rem;margin-top:.3rem">JPG, PNG, WebP — max 5 MB</div>
                </div>
            </div>
        </div>

    </div>
</form>

<?php
$inline_js = <<<'JS'
(function () {
    var sel   = document.getElementById('medicine_id');
    var info  = document.getElementById('stock-info');
    function updateStock() {
        var opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset.stock !== undefined) {
            var qty  = parseFloat(opt.dataset.stock);
            var unit = opt.dataset.unit || '';
            info.textContent = 'Current stock: ' + qty.toFixed(2) + ' ' + unit;
            info.style.color = qty <= 0 ? '#DC2626' : '#6B7280';
        } else {
            info.textContent = '';
        }
    }
    sel.addEventListener('change', updateStock);
    updateStock();
})();
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
