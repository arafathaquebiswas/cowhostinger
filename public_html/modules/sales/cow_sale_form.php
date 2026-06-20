<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin', 'accountant']);
requireModule('sales');

$db = getDB();

$errors = [];
$form = [
    'cow_id'     => '',
    'buyer_name' => '',
    'sale_price' => '',
    'sale_date'  => date('Y-m-d'),
    'notes'      => '',
];

// Cows ready for sale
$ready_cows = $db->query(
    "SELECT id, tag_number, breed, purchase_price
     FROM cows
     WHERE status = 'ready_for_sale'
     ORDER BY tag_number ASC"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/sales/cow_sale_form.php');
    }

    $form['cow_id']     = trim($_POST['cow_id']     ?? '');
    $form['buyer_name'] = sanitize($_POST['buyer_name'] ?? '');
    $form['sale_price'] = trim($_POST['sale_price'] ?? '');
    $form['sale_date']  = trim($_POST['sale_date']  ?? '');
    $form['notes']      = sanitize($_POST['notes']  ?? '');

    $cow_id = (int)$form['cow_id'];
    if ($cow_id <= 0) $errors[] = 'Please select a cow.';

    if ($form['buyer_name'] === '') $errors[] = 'Buyer name is required.';
    if (strlen($form['buyer_name']) > 150) $errors[] = 'Buyer name is too long.';

    $sale_price = $form['sale_price'] !== '' ? (float)$form['sale_price'] : null;
    if ($sale_price === null || $sale_price <= 0) $errors[] = 'Sale price must be greater than 0.';

    if ($form['sale_date'] === '' || !strtotime($form['sale_date'])) {
        $errors[] = 'A valid sale date is required.';
    } elseif (strtotime($form['sale_date']) > strtotime('today')) {
        $errors[] = 'Sale date cannot be in the future.';
    }

    // Verify cow still ready_for_sale (race condition guard)
    $cow = null;
    if (empty($errors)) {
        $sel = $db->prepare("SELECT id, tag_number, breed, purchase_price, status FROM cows WHERE id = ? AND status = 'ready_for_sale'");
        $sel->execute([$cow_id]);
        $cow = $sel->fetch();
        if (!$cow) $errors[] = 'Selected cow is no longer available for sale.';
    }

    if (empty($errors)) {
        $user_id    = (int)$_SESSION['user_id'];
        $purchase   = $cow['purchase_price'] !== null ? (float)$cow['purchase_price'] : 0.0;
        $profit_loss = $sale_price - $purchase;
        $notes_val  = $form['notes'] !== '' ? $form['notes'] : null;

        $db->beginTransaction();
        try {
            // Insert cow sale
            $db->prepare(
                "INSERT INTO cow_sales (cow_id, buyer_name, sale_price, sale_date, profit_loss, approved_by, notes)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$cow_id, $form['buyer_name'], $sale_price, $form['sale_date'], $profit_loss, $user_id, $notes_val]);
            $sale_id = (int)$db->lastInsertId();

            // Mark cow as sold
            $db->prepare("UPDATE cows SET status = 'sold' WHERE id = ?")->execute([$cow_id]);

            // Auto-create finance transaction
            $finance_notes = "Cow #{$cow['tag_number']} sold to {$form['buyer_name']}";
            $db->prepare(
                "INSERT INTO finance_transactions (type, category, amount, related_module, reference_id, transaction_date, recorded_by, approved_by, notes)
                 VALUES ('income','Cow Sales',?,?,?,?,?,?,?)"
            )->execute([$sale_price, 'sales', $sale_id, $form['sale_date'], $user_id, $user_id, $finance_notes]);

            auditLog($user_id, 'CREATE_COW_SALE', 'cow_sales', $sale_id, null, [
                'cow_id'      => $cow_id,
                'sale_price'  => $sale_price,
                'profit_loss' => $profit_loss,
            ]);

            $db->commit();
            flashMessage('success', "Cow #{$cow['tag_number']} sold to {$form['buyer_name']}. Profit/Loss: " . formatCurrency($profit_loss));
            redirect('/modules/sales/index.php?tab=cow');
        } catch (PDOException $e) {
            $db->rollBack();
            $errors[] = 'Failed to save sale record. Please try again.';
        }
    }
}

$page_title = 'Record Cow Sale';
$active_nav = 'sales';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2>Record Cow Sale</h2></div>
    <a href="/modules/sales/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (empty($ready_cows)): ?>
<div class="alert" style="background:var(--warning-soft,#FFFBEB);border:1px solid #D97706;color:#92400E;border-radius:var(--radius);padding:.75rem 1rem;margin-bottom:1.25rem">
    No cows are currently marked as <strong>Ready for Sale</strong>.
    <a href="/modules/cows/index.php" style="color:inherit;font-weight:600;margin-left:.5rem">Manage cows →</a>
</div>
<?php endif; ?>

<form method="POST" action="/modules/sales/cow_sale_form.php" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:580px;margin-bottom:1.25rem">
        <div class="card-body">

            <div class="form-group">
                <label class="form-label" for="cow_id">Cow <span style="color:var(--danger)">*</span></label>
                <select id="cow_id" name="cow_id" class="form-control" onchange="updatePurchasePrice(this)" required>
                    <option value="">— Select cow —</option>
                    <?php foreach ($ready_cows as $c): ?>
                    <option value="<?= $c['id'] ?>"
                            data-purchase="<?= e($c['purchase_price'] ?? '0') ?>"
                            <?= (string)$c['id'] === $form['cow_id'] ? 'selected' : '' ?>>
                        #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                        (Purchased: <?= $c['purchase_price'] ? e(formatCurrency((float)$c['purchase_price'])) : 'N/A' ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- P&L preview -->
            <div id="pl_preview" style="display:none;background:var(--bg-muted);border:1px solid var(--border);border-radius:var(--radius);padding:.65rem 1rem;margin-bottom:1rem;font-size:.875rem">
                <span>Purchase price: <strong id="pp_display">—</strong></span>
                <span style="margin:0 .75rem">|</span>
                <span>Estimated P&amp;L: <strong id="pl_display" style="color:var(--success)">—</strong></span>
            </div>

            <div class="form-group">
                <label class="form-label" for="buyer_name">Buyer Name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="buyer_name" name="buyer_name" class="form-control"
                       value="<?= e($form['buyer_name']) ?>" maxlength="150"
                       placeholder="Full name or company" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="sale_price">Sale Price (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="sale_price" name="sale_price" class="form-control"
                           value="<?= e($form['sale_price']) ?>"
                           step="0.01" min="0.01" placeholder="0.00"
                           oninput="calcPL()" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="sale_date">Sale Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="sale_date" name="sale_date" class="form-control"
                           value="<?= e($form['sale_date']) ?>"
                           max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="Transportation, condition, contract number…"><?= e($form['notes']) ?></textarea>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary" <?= empty($ready_cows) ? 'disabled' : '' ?>>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Record Sale
        </button>
        <a href="/modules/sales/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php
$inline_js = <<<'JS'
var purchasePrices = {};
document.querySelectorAll('#cow_id option[data-purchase]').forEach(function(opt) {
    purchasePrices[opt.value] = parseFloat(opt.getAttribute('data-purchase')) || 0;
});

function updatePurchasePrice(sel) {
    calcPL();
}

function calcPL() {
    var cowSel    = document.getElementById('cow_id');
    var saleInput = document.getElementById('sale_price');
    var preview   = document.getElementById('pl_preview');
    var ppDisplay = document.getElementById('pp_display');
    var plDisplay = document.getElementById('pl_display');

    var cowId = cowSel.value;
    if (!cowId) { preview.style.display = 'none'; return; }

    preview.style.display = 'flex';
    preview.style.flexWrap = 'wrap';
    preview.style.gap = '.25rem';

    var pp      = purchasePrices[cowId] || 0;
    var sale    = parseFloat(saleInput.value) || 0;
    var pl      = sale - pp;
    var fmtPP   = '৳' + pp.toLocaleString('en-BD', {minimumFractionDigits:2, maximumFractionDigits:2});
    var fmtPL   = (pl >= 0 ? '+৳' : '-৳') + Math.abs(pl).toLocaleString('en-BD', {minimumFractionDigits:2, maximumFractionDigits:2});

    ppDisplay.textContent  = fmtPP;
    plDisplay.textContent  = fmtPL;
    plDisplay.style.color  = pl >= 0 ? 'var(--success)' : 'var(--danger)';
}
document.addEventListener('DOMContentLoaded', calcPL);
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
