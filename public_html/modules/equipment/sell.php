<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin']);
requireModule('equipment');

$db    = getDB();
$eq_id = (int)($_GET['id'] ?? 0);
if ($eq_id <= 0) { redirect('/modules/equipment/index.php'); }

$sel = $db->prepare("SELECT * FROM equipment WHERE id = ?");
$sel->execute([$eq_id]);
$equipment = $sel->fetch();

if (!$equipment) {
    flashMessage('error', 'Equipment not found.');
    redirect('/modules/equipment/index.php');
}
if ($equipment['status'] === 'sold') {
    flashMessage('error', "'{$equipment['name']}' is already sold.");
    redirect('/modules/equipment/index.php');
}

$errors = [];
$form = [
    'sale_price' => '',
    'buyer_name' => '',
    'sale_date'  => date('Y-m-d'),
    'notes'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect("/modules/equipment/sell.php?id={$eq_id}");
    }

    $form['sale_price'] = trim($_POST['sale_price'] ?? '');
    $form['buyer_name'] = sanitize($_POST['buyer_name'] ?? '');
    $form['sale_date']  = trim($_POST['sale_date'] ?? '');
    $form['notes']      = sanitize($_POST['notes'] ?? '');

    $sale_price = $form['sale_price'] !== '' ? (float)$form['sale_price'] : 0;
    if ($sale_price <= 0) $errors[] = 'Sale price must be greater than 0.';
    if ($form['sale_date'] === '' || !strtotime($form['sale_date'])) $errors[] = 'A valid sale date is required.';

    if (empty($errors)) {
        $user_id        = (int)$_SESSION['user_id'];
        $purchase_price = (float)($equipment['purchase_price'] ?? 0);
        $profit_loss    = $sale_price - $purchase_price;

        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO equipment_sales (equipment_id, sale_price, buyer_name, sale_date, profit_loss, recorded_by, notes)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([
                $eq_id, $sale_price,
                $form['buyer_name'] ?: null,
                $form['sale_date'],
                $profit_loss,
                $user_id,
                $form['notes'] ?: null,
            ]);
            $sale_id = (int)$db->lastInsertId();

            $db->prepare("UPDATE equipment SET status='sold', current_value=? WHERE id=?")
               ->execute([$sale_price, $eq_id]);

            $pl_note = $profit_loss >= 0
                ? 'profit: ' . number_format($profit_loss, 2)
                : 'loss: '   . number_format(abs($profit_loss), 2);
            $db->prepare(
                "INSERT INTO finance_transactions
                 (type, category, amount, related_module, reference_id, transaction_date, recorded_by, notes)
                 VALUES ('income', 'Equipment Sale', ?, 'equipment', ?, ?, ?, ?)"
            )->execute([
                $sale_price, $eq_id, $form['sale_date'], $user_id,
                "Equipment sold: {$equipment['name']}" .
                ($form['buyer_name'] ? " to {$form['buyer_name']}" : '') .
                " ({$pl_note})",
            ]);

            auditLog($user_id, 'SELL_EQUIPMENT', 'equipment_sales', $sale_id, null, [
                'equipment_id' => $eq_id,
                'name'         => $equipment['name'],
                'sale_price'   => $sale_price,
                'profit_loss'  => $profit_loss,
            ]);

            $db->commit();
            $pl_str = ($profit_loss >= 0 ? '+' : '') . formatCurrency(abs($profit_loss));
            flashMessage('success', "'{$equipment['name']}' sold for " . formatCurrency($sale_price) . ". P/L: {$pl_str}.");
        } catch (PDOException $e) {
            $db->rollBack();
            flashMessage('error', 'Failed to record sale. Please try again.');
        }
        redirect('/modules/equipment/index.php');
    }
}

$page_title = "Sell Equipment — {$equipment['name']}";
$active_nav = 'equipment';
$purchase_price_js = (float)($equipment['purchase_price'] ?? 0);
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Sell Equipment</h2>
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

<div class="card" style="max-width:560px">
    <div class="card-header"><span class="card-title">Sale Details</span></div>
    <div class="card-body">

        <div style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.875rem">
            <div style="font-weight:600;margin-bottom:.25rem"><?= e($equipment['name']) ?></div>
            <?php if ($equipment['category']): ?>
            <div class="text-muted">Category: <?= e($equipment['category']) ?></div>
            <?php endif; ?>
            <?php if ($equipment['purchase_price']): ?>
            <div class="text-muted">Purchase Price: <strong><?= e(formatCurrency((float)$equipment['purchase_price'])) ?></strong></div>
            <?php endif; ?>
            <?php if ($equipment['purchase_date']): ?>
            <div class="text-muted">Purchased: <?= e(formatDate($equipment['purchase_date'])) ?></div>
            <?php endif; ?>
        </div>

        <form method="POST" action="/modules/equipment/sell.php?id=<?= $eq_id ?>" novalidate>
            <?= csrfField() ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="sale_price">Sale Price (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="sale_price" name="sale_price" class="form-control"
                           value="<?= e($form['sale_price']) ?>" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="sale_date">Sale Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="sale_date" name="sale_date" class="form-control"
                           value="<?= e($form['sale_date']) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="buyer_name">Buyer Name</label>
                <input type="text" id="buyer_name" name="buyer_name" class="form-control"
                       value="<?= e($form['buyer_name']) ?>" maxlength="150" placeholder="Optional">
            </div>
            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="Optional…"><?= e($form['notes']) ?></textarea>
            </div>

            <?php if ($purchase_price_js > 0): ?>
            <div id="profitCalc" style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:var(--radius);padding:.6rem 1rem;margin-bottom:1rem;font-size:.875rem">
                Estimated Profit / Loss: <strong id="profitVal">Enter a price above</strong>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-primary"
                        onclick="return confirm('Confirm sale of <?= e(addslashes($equipment['name'])) ?>? This cannot be undone.')">
                    Record Sale
                </button>
                <a href="/modules/equipment/index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php
if ($purchase_price_js > 0):
$inline_js = <<<JS
document.getElementById('sale_price').addEventListener('input', function() {
    var sp  = parseFloat(this.value) || 0;
    var pp  = {$purchase_price_js};
    var pl  = sp - pp;
    var el  = document.getElementById('profitVal');
    var box = document.getElementById('profitCalc');
    if (!el) return;
    el.textContent = (pl >= 0 ? '+' : '') + '৳ ' + pl.toFixed(2);
    el.style.color    = pl >= 0 ? 'var(--success)' : 'var(--danger)';
    box.style.background  = pl >= 0 ? '#F0FDF4' : '#FEF2F2';
    box.style.borderColor = pl >= 0 ? '#BBF7D0' : '#FECACA';
});
JS;
endif;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
