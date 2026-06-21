<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireModule('finance');

$db     = getDB();
$txn_id = (int)($_GET['id'] ?? 0);
$is_edit = $txn_id > 0;
$existing = null;

$errors = [];
$form = [
    'type'             => 'income',
    'category'         => '',
    'amount'           => '',
    'transaction_date' => date('Y-m-d'),
    'notes'            => '',
];

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM finance_transactions WHERE id = ? AND " . farmFilter());
    $sel->execute([$txn_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Transaction not found.');
        redirect('/modules/finance/index.php');
    }
    // Accountant can only edit their own manual entries (not auto-generated from sales)
    if (!hasRole(['admin']) && ($existing['related_module'] !== null || (int)$existing['recorded_by'] !== (int)$_SESSION['user_id'])) {
        flashMessage('error', 'You can only edit your own manually created transactions.');
        redirect('/modules/finance/index.php');
    }
    $form = [
        'type'             => $existing['type'],
        'category'         => $existing['category'],
        'amount'           => $existing['amount'],
        'transaction_date' => $existing['transaction_date'],
        'notes'            => $existing['notes'] ?? '',
    ];
}

$income_categories  = ['Milk Sales','Cow Sales','Meat Sales','Government Subsidy','Dairy Product Sales','Other Income'];
$expense_categories = ['Feed Purchase','Medicine Purchase','Equipment Purchase','Worker Salary','Maintenance Cost','Utility Bills','Veterinary Services','Transportation','Other Expense'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/finance/form.php' . ($is_edit ? "?id={$txn_id}" : ''));
    }

    $form['type']             = in_array($_POST['type'] ?? '', ['income','expense'], true) ? $_POST['type'] : 'income';
    $form['category']         = sanitize($_POST['category'] ?? '');
    $form['amount']           = trim($_POST['amount'] ?? '');
    $form['transaction_date'] = trim($_POST['transaction_date'] ?? '');
    $form['notes']            = sanitize($_POST['notes'] ?? '');

    if ($form['category'] === '') $errors[] = 'Category is required.';
    if (strlen($form['category']) > 100) $errors[] = 'Category is too long.';

    $amount = $form['amount'] !== '' ? (float)$form['amount'] : null;
    if ($amount === null || $amount <= 0) $errors[] = 'Amount must be greater than 0.';

    if ($form['transaction_date'] === '' || !strtotime($form['transaction_date'])) {
        $errors[] = 'A valid transaction date is required.';
    } elseif (strtotime($form['transaction_date']) > strtotime('today')) {
        $errors[] = 'Transaction date cannot be in the future.';
    }

    if (empty($errors)) {
        $user_id  = (int)$_SESSION['user_id'];
        $notes_val = $form['notes'] !== '' ? $form['notes'] : null;

        if ($is_edit) {
            $db->prepare(
                "UPDATE finance_transactions SET type=?, category=?, amount=?, transaction_date=?, notes=? WHERE id=? AND " . farmFilter()
            )->execute([$form['type'], $form['category'], $amount, $form['transaction_date'], $notes_val, $txn_id]);
            auditLog($user_id, 'UPDATE_FINANCE_TXN', 'finance_transactions', $txn_id, $existing, $form);
            flashMessage('success', 'Transaction updated.');
        } else {
            $db->prepare(
                "INSERT INTO finance_transactions (farm_id, type, category, amount, transaction_date, recorded_by, approved_by, notes)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([fid(), $form['type'], $form['category'], $amount, $form['transaction_date'], $user_id, $user_id, $notes_val]);
            $new_id = (int)$db->lastInsertId();
            auditLog($user_id, 'CREATE_FINANCE_TXN', 'finance_transactions', $new_id, null, $form);
            flashMessage('success', 'Transaction recorded.');
        }
        redirect('/modules/finance/index.php');
    }
}

$page_title = $is_edit ? 'Edit Transaction' : 'Add Transaction';
$active_nav = 'finance';

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2><?= $is_edit ? 'Edit Transaction' : 'Add Transaction' ?></h2></div>
    <a href="/modules/finance/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($is_edit && $existing['related_module']): ?>
<div class="alert" style="background:var(--warning-soft,#FFFBEB);border:1px solid #D97706;color:#92400E;border-radius:var(--radius);padding:.7rem 1rem;margin-bottom:1.25rem;font-size:.875rem">
    This transaction was auto-generated from a <strong><?= e(ucfirst($existing['related_module'])) ?></strong> record. Editing may cause inconsistencies.
</div>
<?php endif; ?>

<form method="POST" action="/modules/finance/form.php<?= $is_edit ? "?id={$txn_id}" : '' ?>" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:540px;margin-bottom:1.25rem">
        <div class="card-body">

            <div class="form-group">
                <label class="form-label">Type <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:.75rem">
                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:500">
                        <input type="radio" name="type" value="income"  id="type_income"
                               <?= $form['type'] === 'income' ? 'checked' : '' ?> onchange="syncCats()">
                        <span style="color:var(--success)">&#11044;</span> Income
                    </label>
                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:500">
                        <input type="radio" name="type" value="expense" id="type_expense"
                               <?= $form['type'] === 'expense' ? 'checked' : '' ?> onchange="syncCats()">
                        <span style="color:var(--danger)">&#11044;</span> Expense
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="category">Category <span style="color:var(--danger)">*</span></label>
                <input type="text" id="category" name="category" class="form-control"
                       value="<?= e($form['category']) ?>" maxlength="100"
                       placeholder="Select or type a category" list="cat_list" required autocomplete="off">
                <datalist id="cat_list_income">
                    <?php foreach ($income_categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                </datalist>
                <datalist id="cat_list_expense">
                    <?php foreach ($expense_categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                </datalist>
                <datalist id="cat_list">
                    <?php foreach ($income_categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                    <?php foreach ($expense_categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                </datalist>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="amount">Amount (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="amount" name="amount" class="form-control"
                           value="<?= e($form['amount']) ?>"
                           step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="transaction_date">Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="transaction_date" name="transaction_date" class="form-control"
                           value="<?= e($form['transaction_date']) ?>"
                           max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="3"
                          placeholder="Reference number, description…"><?= e($form['notes']) ?></textarea>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Record Transaction' ?>
        </button>
        <a href="/modules/finance/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php
$init_type = e($form['type']);
$inline_js = <<<JSEOF
function syncCats() {
    var type = document.querySelector('input[name="type"]:checked').value;
    var dl   = document.getElementById('cat_list');
    var src  = type === 'income'
        ? document.getElementById('cat_list_income').innerHTML
        : document.getElementById('cat_list_expense').innerHTML;
    dl.innerHTML = src;
}
document.addEventListener('DOMContentLoaded', syncCats);
JSEOF;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
