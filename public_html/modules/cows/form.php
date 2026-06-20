<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireRole(['admin', 'veterinarian']);
requireModule('cows');

$db      = getDB();
$cow_id  = (int)($_GET['id'] ?? 0);
$is_edit = $cow_id > 0;

// Only admin can add new cows
if (!$is_edit && !hasRole(['admin'])) {
    flashMessage('error', 'Only administrators can add new cows.');
    redirect('/modules/cows/index.php');
}

$errors = [];
$cow = [
    'id'             => 0,
    'tag_number'     => '',
    'breed'          => '',
    'birth_date'     => '',
    'purchase_price' => '',
    'purchase_date'  => '',
    'current_weight' => '',
    'health_status'  => 'healthy',
    'is_pregnant'    => 0,
    'status'         => 'active',
    'photo_url'      => null,
    'notes'          => '',
];

$existing = null;
if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM cows WHERE id = ? AND " . farmFilter());
    $sel->execute([$cow_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Cow not found.');
        redirect('/modules/cows/index.php');
    }
    $cow = array_merge($cow, $existing);
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token. Please try again.');
        redirect('/modules/cows/form.php' . ($is_edit ? "?id={$cow_id}" : ''));
    }

    // Collect input
    $cow['tag_number']     = sanitize($_POST['tag_number']     ?? '');
    $cow['breed']          = sanitize($_POST['breed']          ?? '');
    $cow['birth_date']     = sanitize($_POST['birth_date']     ?? '');
    $cow['purchase_date']  = sanitize($_POST['purchase_date']  ?? '');
    $cow['health_status']  = sanitize($_POST['health_status']  ?? 'healthy');
    $cow['notes']          = sanitize($_POST['notes']          ?? '');
    $cow['is_pregnant']    = isset($_POST['is_pregnant']) ? 1 : 0;
    $cow['status']         = sanitize($_POST['status'] ?? 'active');

    $raw_price  = trim($_POST['purchase_price'] ?? '');
    $raw_weight = trim($_POST['current_weight'] ?? '');
    $cow['purchase_price'] = $raw_price  !== '' ? (float)$raw_price  : null;
    $cow['current_weight'] = $raw_weight !== '' ? (float)$raw_weight : null;

    // Validation
    if ($cow['tag_number'] === '') {
        $errors[] = 'Tag number is required.';
    } elseif (strlen($cow['tag_number']) > 50) {
        $errors[] = 'Tag number must be 50 characters or fewer.';
    }
    if ($cow['breed'] === '') {
        $errors[] = 'Breed is required.';
    } elseif (strlen($cow['breed']) > 100) {
        $errors[] = 'Breed must be 100 characters or fewer.';
    }

    $valid_statuses = ['active','pregnant','lactating','dry','sick','quarantine','ready_for_sale','sold','deceased'];
    if (!in_array($cow['status'], $valid_statuses, true)) {
        $errors[] = 'Invalid status selected.';
    }

    if ($cow['purchase_price'] !== null && $cow['purchase_price'] < 0) {
        $errors[] = 'Purchase price cannot be negative.';
    }
    if ($cow['current_weight'] !== null && ($cow['current_weight'] <= 0 || $cow['current_weight'] > 9999)) {
        $errors[] = 'Weight must be between 0.01 and 9999 kg.';
    }
    if ($cow['birth_date'] !== '' && strtotime($cow['birth_date']) > time()) {
        $errors[] = 'Birth date cannot be in the future.';
    }

    // Tag number uniqueness — scoped to this farm
    if (empty($errors) && $cow['tag_number'] !== '') {
        $chk = $db->prepare("SELECT id FROM cows WHERE tag_number = ? AND id != ? AND " . farmFilter());
        $chk->execute([$cow['tag_number'], $cow_id]);
        if ($chk->fetch()) {
            $errors[] = "Tag number '{$cow['tag_number']}' is already assigned to another cow.";
        }
    }

    // Photo upload
    $photo_url = $cow['photo_url'];
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = uploadImage($_FILES['photo'], 'cows');
        if ($uploaded === false) {
            $errors[] = 'Photo upload failed. Allowed types: JPG, PNG, WebP. Max size: 5 MB.';
        } else {
            $photo_url = $uploaded;
        }
    }

    if (empty($errors)) {
        $user_id = (int)$_SESSION['user_id'];

        $tag    = $cow['tag_number'];
        $breed  = $cow['breed'];
        $bdate  = $cow['birth_date']  !== '' ? $cow['birth_date']  : null;
        $pdate  = $cow['purchase_date'] !== '' ? $cow['purchase_date'] : null;
        $price  = $cow['purchase_price'];
        $weight = $cow['current_weight'];
        $health = $cow['health_status'] !== '' ? $cow['health_status'] : 'healthy';
        $preg   = $cow['is_pregnant'];
        $status = $cow['status'];
        $notes  = $cow['notes'] !== '' ? $cow['notes'] : null;

        if ($is_edit) {
            $upd = $db->prepare(
                "UPDATE cows
                 SET tag_number=?, breed=?, birth_date=?, purchase_price=?,
                     purchase_date=?, current_weight=?, health_status=?,
                     is_pregnant=?, status=?, photo_url=?, notes=?
                 WHERE id=?"
            );
            $upd->execute([$tag, $breed, $bdate, $price, $pdate, $weight, $health, $preg, $status, $photo_url, $notes, $cow_id]);

            // Auto-log weight if it changed
            $prev_weight = $existing['current_weight'] !== null ? (float)$existing['current_weight'] : null;
            if ($weight !== null && $weight !== $prev_weight) {
                $db->prepare(
                    "INSERT INTO cow_weight_logs (farm_id, cow_id, weight, recorded_by) VALUES (?,?,?,?)"
                )->execute([fid(), $cow_id, $weight, $user_id]);
            }

            auditLog($user_id, 'UPDATE_COW', 'cows', $cow_id, $existing, array_merge($cow, ['photo_url' => $photo_url]));
            flashMessage('success', "Cow #{$tag} updated successfully.");
            redirect("/modules/cows/view.php?id={$cow_id}");
        } else {
            // Subscription limit check
            if (!farmCanAddCow()) {
                $limit = farmCowLimit();
                flashMessage('error', "Your plan allows a maximum of {$limit} active cows. Please upgrade to add more.");
                redirect('/modules/cows/index.php');
            }
            $ins = $db->prepare(
                "INSERT INTO cows
                     (farm_id, tag_number, breed, birth_date, purchase_price, purchase_date,
                      current_weight, health_status, is_pregnant, status, photo_url, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->execute([fid(), $tag, $breed, $bdate, $price, $pdate, $weight, $health, $preg, $status, $photo_url, $notes]);
            $new_id = (int)$db->lastInsertId();

            if ($weight !== null) {
                $db->prepare(
                    "INSERT INTO cow_weight_logs (farm_id, cow_id, weight, recorded_by) VALUES (?,?,?,?)"
                )->execute([fid(), $new_id, $weight, $user_id]);
            }

            auditLog($user_id, 'CREATE_COW', 'cows', $new_id, null, $cow);
            flashMessage('success', "Cow #{$tag} added successfully.");
            redirect("/modules/cows/view.php?id={$new_id}");
        }
    }
}

$page_title = $is_edit ? "Edit Cow #{$cow['tag_number']}" : 'Add New Cow';
$active_nav = 'cows';

$status_options = [
    'active'         => 'Active',
    'pregnant'       => 'Pregnant',
    'lactating'      => 'Lactating',
    'dry'            => 'Dry',
    'sick'           => 'Sick',
    'quarantine'     => 'Quarantine',
    'ready_for_sale' => 'Ready for Sale',
    'sold'           => 'Sold',
    'deceased'       => 'Deceased',
];

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2><?= $is_edit ? 'Edit Cow' : 'Add New Cow' ?></h2>
        <?php if ($is_edit): ?>
        <p class="text-sm text-muted">Editing #<?= e($cow['tag_number']) ?></p>
        <?php endif; ?>
    </div>
    <a href="<?= $is_edit ? "/modules/cows/view.php?id={$cow_id}" : '/modules/cows/index.php' ?>"
       class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following errors:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST"
      action="/modules/cows/form.php<?= $is_edit ? "?id={$cow_id}" : '' ?>"
      enctype="multipart/form-data"
      novalidate>
    <?= csrfField() ?>

    <!-- Basic Information -->
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-header">
            <span class="card-title">Basic Information</span>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">

                <div class="form-group">
                    <label class="form-label" for="tag_number">Tag Number <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="tag_number" name="tag_number" class="form-control"
                           value="<?= e($cow['tag_number']) ?>"
                           placeholder="e.g. COW-001" maxlength="50" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="breed">Breed <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="breed" name="breed" class="form-control"
                           value="<?= e($cow['breed']) ?>"
                           placeholder="e.g. Holstein, Jersey" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="birth_date">Birth Date</label>
                    <input type="date" id="birth_date" name="birth_date" class="form-control"
                           value="<?= e($cow['birth_date'] ?? '') ?>"
                           max="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="status">Status <span style="color:var(--danger)">*</span></label>
                    <select id="status" name="status" class="form-control" required>
                        <?php foreach ($status_options as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $cow['status'] === $val ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
        </div>
    </div>

    <!-- Health & Weight -->
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-header">
            <span class="card-title">Health &amp; Weight</span>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">

                <div class="form-group">
                    <label class="form-label" for="health_status">Health Status</label>
                    <input type="text" id="health_status" name="health_status" class="form-control"
                           value="<?= e($cow['health_status']) ?>"
                           placeholder="e.g. healthy, under observation" maxlength="100">
                </div>

                <div class="form-group">
                    <label class="form-label" for="current_weight">Current Weight (kg)</label>
                    <input type="number" id="current_weight" name="current_weight" class="form-control"
                           value="<?= e($cow['current_weight'] ?? '') ?>"
                           step="0.01" min="0.01" max="9999" placeholder="e.g. 450.00">
                    <?php if ($is_edit && $cow['current_weight'] !== null): ?>
                    <span class="form-hint">Changing weight will add a new weight log entry.</span>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="display:flex;align-items:center;gap:.75rem;padding-top:1.65rem">
                    <input type="checkbox" id="is_pregnant" name="is_pregnant" value="1"
                           <?= $cow['is_pregnant'] ? 'checked' : '' ?>
                           style="width:18px;height:18px;cursor:pointer">
                    <label for="is_pregnant" class="form-label" style="margin:0;cursor:pointer">Currently Pregnant</label>
                </div>

            </div>
        </div>
    </div>

    <!-- Purchase Details -->
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-header">
            <span class="card-title">Purchase Details</span>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem">

                <div class="form-group">
                    <label class="form-label" for="purchase_price">Purchase Price (৳)</label>
                    <input type="number" id="purchase_price" name="purchase_price" class="form-control"
                           value="<?= e($cow['purchase_price'] ?? '') ?>"
                           step="0.01" min="0" placeholder="e.g. 75000.00">
                </div>

                <div class="form-group">
                    <label class="form-label" for="purchase_date">Purchase Date</label>
                    <input type="date" id="purchase_date" name="purchase_date" class="form-control"
                           value="<?= e($cow['purchase_date'] ?? '') ?>">
                </div>

            </div>
        </div>
    </div>

    <!-- Photo & Notes -->
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-header">
            <span class="card-title">Photo &amp; Notes</span>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

                <div class="form-group">
                    <label class="form-label" for="photo">Cow Photo</label>
                    <input type="file" id="photo" name="photo" class="form-control"
                           accept="image/jpeg,image/png,image/webp">
                    <span class="form-hint">JPG, PNG, or WebP. Max 5 MB.</span>
                    <div class="photo-preview-wrap" id="photoPreviewWrap">
                        <?php if ($cow['photo_url']): ?>
                        <img src="<?= e($cow['photo_url']) ?>" alt="Current photo" id="currentPhoto">
                        <p class="form-hint">Current photo — upload a new file to replace it.</p>
                        <?php endif; ?>
                        <img src="" alt="New photo preview" class="photo-preview-new" id="newPhotoPreview">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="5"
                              placeholder="Any additional notes about this cow…"><?= e($cow['notes'] ?? '') ?></textarea>
                </div>

            </div>
        </div>
    </div>

    <div style="display:flex;gap:.75rem;align-items:center">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Add Cow' ?>
        </button>
        <a href="<?= $is_edit ? "/modules/cows/view.php?id={$cow_id}" : '/modules/cows/index.php' ?>"
           class="btn btn-secondary">Cancel</a>
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
