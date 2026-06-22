<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager']);
requireFarmScope();
requireModule('equipment');

$db     = getDB();
$eq_id  = (int)($_GET['id'] ?? 0);
$is_edit = $eq_id > 0;
$existing = null;

if (!$is_edit && !canAccess('equipment.create')) {
    $lim = resourceUsage('equipment');
    flashMessage('error', "Equipment limit reached ({$lim['current']}/{$lim['max']}). Upgrade your plan to add more equipment.");
    redirect('/modules/equipment/index.php');
}

// Inline migration: ensure quantity column exists
$eq_cols = array_column($db->query("SHOW COLUMNS FROM equipment")->fetchAll(), 'Field');
if (!in_array('quantity', $eq_cols))
    $db->exec("ALTER TABLE equipment ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER serial_number");
if (!in_array('serial_number', $eq_cols))
    $db->exec("ALTER TABLE equipment ADD COLUMN serial_number VARCHAR(100) DEFAULT NULL AFTER name");

$errors = [];
$form = [
    'name'                  => '',
    'serial_number'         => '',
    'category'              => '',
    'quantity'              => '1',
    'purchase_date'         => '',
    'purchase_price'        => '',
    'acquisition_type'      => 'purchased',
    'gifted_by'             => '',
    'current_value'         => '',
    'status'                => 'operational',
    'lifespan_months'       => '',
    'last_maintenance_date' => '',
    'notes'                 => '',
    'photo_url'             => null,
];

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM equipment WHERE id = ? AND " . farmFilter());
    $sel->execute([$eq_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Equipment not found.');
        redirect('/modules/equipment/index.php');
    }
    $form = array_merge($form, [
        'name'                  => $existing['name'],
        'serial_number'         => $existing['serial_number']         ?? '',
        'category'              => $existing['category']              ?? '',
        'quantity'              => (string)($existing['quantity']      ?? 1),
        'purchase_date'         => $existing['purchase_date']         ?? '',
        'purchase_price'        => $existing['purchase_price']        ?? '',
        'acquisition_type'      => $existing['acquisition_type']      ?? 'purchased',
        'gifted_by'             => $existing['gifted_by']             ?? '',
        'current_value'         => $existing['current_value']         ?? '',
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
    $form['serial_number']         = sanitize($_POST['serial_number']         ?? '');
    $form['category']              = sanitize($_POST['category']              ?? '');
    $form['quantity']              = trim($_POST['quantity']                   ?? '1');
    $form['purchase_date']         = trim($_POST['purchase_date']             ?? '');
    $form['purchase_price']        = trim($_POST['purchase_price']            ?? '');
    $form['acquisition_type']      = sanitize($_POST['acquisition_type']      ?? 'purchased');
    $form['gifted_by']             = sanitize($_POST['gifted_by']             ?? '');
    $form['current_value']         = trim($_POST['current_value']             ?? '');
    $form['status']                = sanitize($_POST['status']                ?? 'operational');
    $form['lifespan_months']       = trim($_POST['lifespan_months']           ?? '');
    $form['last_maintenance_date'] = trim($_POST['last_maintenance_date']     ?? '');
    $form['notes']                 = sanitize($_POST['notes']                 ?? '');

    // Validation
    if ($form['name'] === '') $errors[] = 'Equipment name is required.';
    if (strlen($form['name']) > 150) $errors[] = 'Name is too long.';

    $qty_val = (int)$form['quantity'];
    if ($qty_val < 1) $errors[] = 'Quantity must be at least 1.';
    if ($qty_val > 100000) $errors[] = 'Quantity cannot exceed 100,000.';

    $valid_statuses = ['operational', 'maintenance', 'damaged', 'sold', 'disposed'];
    if (!in_array($form['status'], $valid_statuses, true)) $errors[] = 'Invalid status.';

    $valid_acq = ['purchased', 'gifted', 'personal'];
    if (!in_array($form['acquisition_type'], $valid_acq, true)) $errors[] = 'Invalid acquisition type.';
    if ($form['acquisition_type'] === 'gifted' && $form['gifted_by'] === '') $errors[] = 'Please enter who gifted this equipment.';

    $purchase_price_val = $form['purchase_price'] !== '' ? (float)$form['purchase_price'] : null;
    if ($purchase_price_val !== null && $purchase_price_val < 0) $errors[] = 'Purchase price cannot be negative.';
    $current_value_val = $form['current_value'] !== '' ? (float)$form['current_value'] : null;
    if ($current_value_val !== null && $current_value_val < 0) $errors[] = 'Current value cannot be negative.';

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
        $user_id    = (int)$_SESSION['user_id'];
        $pdate      = $form['purchase_date']         !== '' ? $form['purchase_date']         : null;
        $lmdate     = $form['last_maintenance_date'] !== '' ? $form['last_maintenance_date'] : null;
        $notes_val  = $form['notes'] !== '' ? $form['notes'] : null;
        $cat_val    = $form['category'] !== '' ? $form['category'] : null;
        $sn_val     = $form['serial_number'] !== '' ? $form['serial_number'] : null;
        $gifted_val = ($form['acquisition_type'] === 'gifted' && $form['gifted_by'] !== '') ? $form['gifted_by'] : null;

        if ($is_edit) {
            // On edit: if qty is changed, update quantity directly (admin responsible for accuracy)
            $db->prepare(
                "UPDATE equipment SET name=?, serial_number=?, category=?, quantity=?, purchase_date=?, purchase_price=?,
                 acquisition_type=?, gifted_by=?, current_value=?, status=?, lifespan_months=?,
                 last_maintenance_date=?, photo_url=?, notes=? WHERE id=? AND " . farmFilter()
            )->execute([
                $form['name'], $sn_val, $cat_val, $qty_val, $pdate, $purchase_price_val,
                $form['acquisition_type'], $gifted_val, $current_value_val,
                $form['status'], $lifespan, $lmdate, $photo_url, $notes_val, $eq_id,
            ]);
            auditLog($user_id, 'UPDATE_EQUIPMENT', 'equipment', $eq_id, $existing, $form);
            flashMessage('success', "Equipment '{$form['name']}' updated.");
        } else {
            $db->prepare(
                "INSERT INTO equipment (farm_id, name, serial_number, category, quantity, purchase_date, purchase_price, acquisition_type, gifted_by, current_value, status, lifespan_months, last_maintenance_date, photo_url, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                fid(), $form['name'], $sn_val, $cat_val, $qty_val, $pdate, $purchase_price_val,
                $form['acquisition_type'], $gifted_val, $current_value_val,
                $form['status'], $lifespan, $lmdate, $photo_url, $notes_val,
            ]);
            $new_id = (int)$db->lastInsertId();

            // Record purchase in finance if price given
            if ($purchase_price_val > 0) {
                $total_cost = round($qty_val * $purchase_price_val, 2);
                $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([fid(), 'expense', 'Equipment Purchase', $total_cost, 'equipment', $new_id, $pdate ?? date('Y-m-d'), $user_id, "{$qty_val}× {$form['name']} @ " . number_format($purchase_price_val, 2) . " each"]);
            }

            auditLog($user_id, 'CREATE_EQUIPMENT', 'equipment', $new_id, null, $form);
            flashMessage('success', "Equipment '{$form['name']}'" . ($qty_val > 1 ? " (×{$qty_val})" : '') . " added.");
        }
        redirect('/modules/equipment/index.php');
    }
}

$page_title = $is_edit ? "Edit Equipment — {$form['name']}" : 'Add Equipment';
$active_nav = 'equipment';

$status_options = [
    'operational' => 'Operational',
    'maintenance' => 'In Maintenance',
    'damaged'     => 'Damaged',
    'sold'        => 'Sold',
    'disposed'    => 'Disposed',
];

$equipment_categories = [
    'Milking Equipment', 'Feeding Equipment', 'Barn / Housing', 'Transport',
    'Health / Veterinary', 'Farm Tools', 'Irrigation', 'Power / Generator', 'Other',
];

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

    <div class="card" style="margin-bottom:1.25rem;max-width:680px">
        <div class="card-body">

            <!-- Name + Serial -->
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="name">Equipment Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="name" name="name" class="form-control"
                           value="<?= e($form['name']) ?>" maxlength="150"
                           placeholder="e.g. Feed Bucket, Milking Machine, Generator" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="serial_number">Serial Number</label>
                    <input type="text" id="serial_number" name="serial_number" class="form-control"
                           value="<?= e($form['serial_number'] ?? '') ?>" maxlength="100"
                           placeholder="Optional">
                    <span class="form-hint">For individual/unique items.</span>
                </div>
            </div>

            <!-- Category + Quantity + Acquisition -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="category">Category</label>
                    <select id="category" name="category" class="form-control">
                        <option value="">— Select —</option>
                        <?php foreach ($equipment_categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $form['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="quantity">Quantity <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="quantity" name="quantity" class="form-control"
                           value="<?= e($form['quantity']) ?>" min="1" max="100000" required>
                    <span class="form-hint">Use 1 for unique items (tractor, generator).</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="acquisition_type">Acquisition Type</label>
                    <select id="acquisition_type" name="acquisition_type" class="form-control" onchange="toggleGiftedBy(this.value)">
                        <option value="purchased" <?= $form['acquisition_type'] === 'purchased' ? 'selected' : '' ?>>Purchased</option>
                        <option value="gifted"    <?= $form['acquisition_type'] === 'gifted'    ? 'selected' : '' ?>>Gifted / Donated</option>
                        <option value="personal"  <?= $form['acquisition_type'] === 'personal'  ? 'selected' : '' ?>>Personal / Owner-Supplied</option>
                    </select>
                </div>
            </div>

            <div id="gifted_by_row" class="form-group" style="display:<?= $form['acquisition_type'] === 'gifted' ? 'block' : 'none' ?>">
                <label class="form-label" for="gifted_by">Gifted / Donated By <span style="color:var(--danger)">*</span></label>
                <input type="text" id="gifted_by" name="gifted_by" class="form-control"
                       value="<?= e($form['gifted_by']) ?>" maxlength="150" placeholder="Name of donor or organisation">
            </div>

            <!-- Pricing per unit -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="purchase_price">Purchase Price / Unit (৳)</label>
                    <input type="number" id="purchase_price" name="purchase_price" class="form-control"
                           value="<?= e($form['purchase_price'] ?? '') ?>" step="0.01" min="0" placeholder="0.00"
                           oninput="calcTotals()">
                </div>
                <div class="form-group">
                    <label class="form-label" for="current_value">Current Value / Unit (৳)</label>
                    <input type="number" id="current_value" name="current_value" class="form-control"
                           value="<?= e($form['current_value'] ?? '') ?>" step="0.01" min="0" placeholder="0.00"
                           oninput="calcTotals()">
                    <span class="form-hint">Estimated present worth per unit.</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <?php foreach ($status_options as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $form['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Cost summary (auto-calculated) -->
            <div id="cost_summary" style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.875rem;display:none">
                <div style="display:flex;gap:2rem;flex-wrap:wrap">
                    <span>Total Purchase Value: <strong id="total_purchase" style="color:#166534">—</strong></span>
                    <span>Total Current Value: <strong id="total_current" style="color:#0369a1">—</strong></span>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
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
                              placeholder="Model number, location, condition details…"><?= e($form['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <?php if ($is_edit && (int)($existing['quantity'] ?? 1) > 1): ?>
            <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:.65rem 1rem;font-size:.84rem;color:#92400e">
                <strong>Note:</strong> Current stock: <strong><?= (int)$existing['quantity'] ?></strong> units.
                Editing quantity here directly adjusts the stock level — use the Sell page to record sales and reduce stock properly.
            </div>
            <?php endif; ?>
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
function toggleGiftedBy(val) {
    var row = document.getElementById('gifted_by_row');
    if (row) row.style.display = val === 'gifted' ? 'block' : 'none';
}
function calcTotals() {
    var qty = parseInt(document.getElementById('quantity').value) || 0;
    var pp  = parseFloat(document.getElementById('purchase_price').value) || 0;
    var cv  = parseFloat(document.getElementById('current_value').value) || 0;
    var box = document.getElementById('cost_summary');
    var tp  = document.getElementById('total_purchase');
    var tc  = document.getElementById('total_current');
    if (qty > 1 && (pp > 0 || cv > 0)) {
        box.style.display = 'block';
        tp.textContent = pp > 0 ? '৳' + (qty * pp).toLocaleString('en-BD', {minimumFractionDigits:2}) : '—';
        tc.textContent = cv > 0 ? '৳' + (qty * cv).toLocaleString('en-BD', {minimumFractionDigits:2}) : '—';
    } else {
        box.style.display = 'none';
    }
}
document.getElementById('quantity').addEventListener('input', calcTotals);
document.getElementById('photo').addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var preview = document.getElementById('newPhotoPreview');
    var reader  = new FileReader();
    reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(file);
});
// Trigger on load for edit mode
calcTotals();
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
