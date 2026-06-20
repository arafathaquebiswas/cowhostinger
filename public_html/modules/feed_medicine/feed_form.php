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
    'unit'              => 'kg',
    'reorder_threshold' => '',
    'purchase_price'    => '',
    'supplier'          => '',
    'purchase_date'     => '',
];

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM feed_inventory WHERE id = ?");
    $sel->execute([$item_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Feed item not found.');
        redirect('/modules/feed_medicine/index.php?tab=feed');
    }
    $form = array_merge($form, [
        'item_name'         => $existing['item_name'],
        'quantity'          => $existing['quantity'],
        'unit'              => $existing['unit'],
        'reorder_threshold' => $existing['reorder_threshold'],
        'purchase_price'    => $existing['purchase_price']    ?? '',
        'supplier'          => $existing['supplier']          ?? '',
        'purchase_date'     => $existing['purchase_date']     ?? '',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/feed_medicine/feed_form.php' . ($is_edit ? "?id={$item_id}" : ''));
    }

    $form['item_name']         = sanitize($_POST['item_name']         ?? '');
    $form['unit']              = sanitize($_POST['unit']              ?? 'kg');
    $form['quantity']          = trim($_POST['quantity']              ?? '');
    $form['reorder_threshold'] = trim($_POST['reorder_threshold']     ?? '');
    $form['purchase_price']    = trim($_POST['purchase_price']        ?? '');
    $form['supplier']          = sanitize($_POST['supplier']          ?? '');
    $form['purchase_date']     = trim($_POST['purchase_date']         ?? '');

    if ($form['item_name'] === '')  $errors[] = 'Item name is required.';
    if (strlen($form['item_name']) > 150) $errors[] = 'Item name is too long.';
    if ($form['unit'] === '')       $errors[] = 'Unit is required.';

    $qty = $form['quantity'] !== '' ? (float)$form['quantity'] : null;
    if ($qty === null || $qty < 0)  $errors[] = 'Quantity must be 0 or greater.';

    $threshold = $form['reorder_threshold'] !== '' ? (float)$form['reorder_threshold'] : 0.0;
    if ($threshold < 0) $errors[] = 'Reorder threshold cannot be negative.';

    $purchase_price_val = $form['purchase_price'] !== '' ? (float)$form['purchase_price'] : null;
    if ($purchase_price_val !== null && $purchase_price_val < 0) $errors[] = 'Purchase price cannot be negative.';
    if ($form['purchase_date'] !== '' && !strtotime($form['purchase_date'])) $errors[] = 'Invalid purchase date.';

    if (empty($errors)) {
        $user_id      = (int)$_SESSION['user_id'];
        $pdate_val    = $form['purchase_date'] !== '' ? $form['purchase_date'] : null;
        $supplier_val = $form['supplier'] !== '' ? $form['supplier'] : null;

        if ($is_edit) {
            $db->prepare(
                "UPDATE feed_inventory
                 SET item_name=?, quantity=?, unit=?, reorder_threshold=?,
                     purchase_price=?, supplier=?, purchase_date=?
                 WHERE id=?"
            )->execute([
                $form['item_name'], $qty, $form['unit'], $threshold,
                $purchase_price_val, $supplier_val, $pdate_val,
                $item_id,
            ]);
            auditLog($user_id, 'UPDATE_FEED_ITEM', 'feed_inventory', $item_id, $existing, $form);
            flashMessage('success', "Feed item '{$form['item_name']}' updated.");
        } else {
            $db->prepare(
                "INSERT INTO feed_inventory (item_name, quantity, unit, reorder_threshold, purchase_price, supplier, purchase_date)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $form['item_name'], $qty, $form['unit'], $threshold,
                $purchase_price_val, $supplier_val, $pdate_val,
            ]);
            $new_id = (int)$db->lastInsertId();

            // Auto-create finance expense when purchase price is provided
            if ($purchase_price_val !== null && $purchase_price_val > 0 && $qty > 0) {
                $total_cost = $purchase_price_val * $qty;
                $db->prepare(
                    "INSERT INTO finance_transactions
                     (type, category, amount, related_module, reference_id, transaction_date, recorded_by, notes)
                     VALUES ('expense', 'Feed Purchase', ?, 'feed_medicine', ?, ?, ?, ?)"
                )->execute([
                    $total_cost, $new_id,
                    $pdate_val ?? date('Y-m-d'),
                    $user_id,
                    "Feed purchase: {$form['item_name']} ({$qty} {$form['unit']} @ " . number_format($purchase_price_val, 2) . "/unit)" .
                    ($supplier_val ? " from {$supplier_val}" : ''),
                ]);
            }

            auditLog($user_id, 'CREATE_FEED_ITEM', 'feed_inventory', $new_id, null, $form);
            flashMessage('success', "Feed item '{$form['item_name']}' added.");
        }
        redirect('/modules/feed_medicine/index.php?tab=feed');
    }
}

$page_title = $is_edit ? "Edit Feed — {$form['item_name']}" : 'Add Feed Item';
$active_nav = 'feed_medicine';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2><?= $is_edit ? 'Edit Feed Item' : 'Add Feed Item' ?></h2></div>
    <a href="/modules/feed_medicine/index.php?tab=feed" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/feed_medicine/feed_form.php<?= $is_edit ? "?id={$item_id}" : '' ?>" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:520px;margin-bottom:1.25rem">
        <div class="card-body">
            <div class="form-group">
                <label class="form-label" for="item_name">Item Name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="item_name" name="item_name" class="form-control"
                       value="<?= e($form['item_name']) ?>" maxlength="150"
                       placeholder="e.g. Maize, Silage, Hay" required>
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
                           maxlength="50" placeholder="kg, bags, L…" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="reorder_threshold">Reorder Threshold</label>
                <input type="number" id="reorder_threshold" name="reorder_threshold" class="form-control"
                       value="<?= e($form['reorder_threshold']) ?>"
                       step="0.01" min="0" placeholder="0.00">
                <span class="form-hint">A low-stock alert is generated when quantity falls to or below this value.</span>
            </div>

            <div style="border-top:1px solid var(--border);margin:1rem 0 .75rem;padding-top:.75rem">
                <div style="font-weight:600;font-size:.82rem;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin-bottom:.75rem">
                    Purchase Information
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="purchase_price">Price per Unit (৳)</label>
                    <input type="number" id="purchase_price" name="purchase_price" class="form-control"
                           value="<?= e($form['purchase_price'] ?? '') ?>"
                           step="0.01" min="0" placeholder="0.00">
                    <span class="form-hint">On create, auto-logs total cost as a Finance expense.</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="purchase_date">Purchase Date</label>
                    <input type="date" id="purchase_date" name="purchase_date" class="form-control"
                           value="<?= e($form['purchase_date'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="supplier">Supplier</label>
                <input type="text" id="supplier" name="supplier" class="form-control"
                       value="<?= e($form['supplier'] ?? '') ?>" maxlength="150"
                       placeholder="Supplier name (optional)">
            </div>
        </div>
    </div>
    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Add Item' ?>
        </button>
        <a href="/modules/feed_medicine/index.php?tab=feed" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
