<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin']);
requireModule('equipment');

$db    = getDB();
$eq_id = (int)($_GET['id'] ?? 0);
if ($eq_id <= 0) { redirect('/modules/equipment/index.php'); }

$sel = $db->prepare("SELECT id, name, status FROM equipment WHERE id = ?");
$sel->execute([$eq_id]);
$equipment = $sel->fetch();
if (!$equipment) {
    flashMessage('error', 'Equipment not found.');
    redirect('/modules/equipment/index.php');
}

$errors = [];
$form = [
    'cost_type' => 'maintenance',
    'amount'    => '',
    'cost_date' => date('Y-m-d'),
    'notes'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect("/modules/equipment/log_cost.php?id={$eq_id}");
    }

    $form['cost_type'] = sanitize($_POST['cost_type'] ?? 'maintenance');
    $form['amount']    = trim($_POST['amount'] ?? '');
    $form['cost_date'] = trim($_POST['cost_date'] ?? '');
    $form['notes']     = sanitize($_POST['notes'] ?? '');

    $valid_types = ['maintenance', 'repair'];
    if (!in_array($form['cost_type'], $valid_types, true)) $errors[] = 'Invalid cost type.';

    $amount = $form['amount'] !== '' ? (float)$form['amount'] : 0;
    if ($amount <= 0) $errors[] = 'Amount must be greater than 0.';
    if ($form['cost_date'] === '' || !strtotime($form['cost_date'])) $errors[] = 'A valid date is required.';

    if (empty($errors)) {
        $user_id  = (int)$_SESSION['user_id'];
        $category = $form['cost_type'] === 'repair' ? 'Equipment Repair' : 'Equipment Maintenance';

        $db->prepare(
            "INSERT INTO finance_transactions
             (type, category, amount, related_module, reference_id, transaction_date, recorded_by, notes)
             VALUES ('expense', ?, ?, 'equipment', ?, ?, ?, ?)"
        )->execute([
            $category, $amount, $eq_id, $form['cost_date'], $user_id,
            $form['notes'] ?: "{$category}: {$equipment['name']}",
        ]);
        $txn_id = (int)$db->lastInsertId();

        if ($form['cost_type'] === 'maintenance') {
            $db->prepare("UPDATE equipment SET last_maintenance_date = ? WHERE id = ?")
               ->execute([$form['cost_date'], $eq_id]);
        }

        auditLog($user_id, 'LOG_EQUIPMENT_COST', 'finance_transactions', $txn_id, null, [
            'equipment_id' => $eq_id,
            'name'         => $equipment['name'],
            'cost_type'    => $form['cost_type'],
            'amount'       => $amount,
        ]);

        flashMessage('success', "{$category} cost of " . formatCurrency($amount) . " logged for '{$equipment['name']}'.");
        redirect('/modules/equipment/index.php');
    }
}

$page_title = "Log Cost — {$equipment['name']}";
$active_nav = 'equipment';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Log Equipment Cost</h2>
        <p class="text-sm text-muted"><?= e($equipment['name']) ?></p>
    </div>
    <a href="/modules/equipment/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <ul style="margin:0 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card" style="max-width:480px">
    <div class="card-header"><span class="card-title">Cost Entry</span></div>
    <div class="card-body">
        <form method="POST" action="/modules/equipment/log_cost.php?id=<?= $eq_id ?>" novalidate>
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label" for="cost_type">Cost Type <span style="color:var(--danger)">*</span></label>
                <select id="cost_type" name="cost_type" class="form-control" required>
                    <option value="maintenance" <?= $form['cost_type'] === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    <option value="repair"      <?= $form['cost_type'] === 'repair'      ? 'selected' : '' ?>>Repair</option>
                </select>
                <span class="form-hint">Maintenance also updates the Last Maintenance Date on this equipment.</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="amount">Amount (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="amount" name="amount" class="form-control"
                           value="<?= e($form['amount']) ?>" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cost_date">Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="cost_date" name="cost_date" class="form-control"
                           value="<?= e($form['cost_date']) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="What work was performed?"><?= e($form['notes']) ?></textarea>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary">Log Cost</button>
                <a href="/modules/equipment/index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
