<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'accountant']);
requireFarmScope();
requireModule('sales');

$db = getDB();

$errors = [];
$form = [
    'cow_id'       => '',
    'kg_sold'      => '',
    'price_per_kg' => '',
    'event_type'   => 'regular',
    'sale_date'    => date('Y-m-d'),
    'notes'        => '',
];

// All cows except already fully sold ones (sold-for-live-animal), allow deceased too
$cows_stmt = $db->prepare(
    "SELECT id, tag_number, breed, status
     FROM cows
     WHERE status NOT IN ('sold') AND " . farmFilter() . "
     ORDER BY tag_number ASC"
);
$cows_stmt->execute();
$cows = $cows_stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/sales/meat_sale_form.php');
    }

    $form['cow_id']       = trim($_POST['cow_id']       ?? '');
    $form['kg_sold']      = trim($_POST['kg_sold']       ?? '');
    $form['price_per_kg'] = trim($_POST['price_per_kg'] ?? '');
    $form['event_type']   = sanitize($_POST['event_type']   ?? 'regular');
    $form['sale_date']    = trim($_POST['sale_date']    ?? '');
    $form['notes']        = sanitize($_POST['notes']    ?? '');

    $cow_id = (int)$form['cow_id'];
    if ($cow_id <= 0) $errors[] = 'Please select a cow.';

    $kg_sold = $form['kg_sold'] !== '' ? (float)$form['kg_sold'] : null;
    if ($kg_sold === null || $kg_sold <= 0) $errors[] = 'Kg sold must be greater than 0.';
    if ($kg_sold !== null && $kg_sold > 2000) $errors[] = 'Kg sold seems too high (max 2000 kg).';

    $price_per_kg = $form['price_per_kg'] !== '' ? (float)$form['price_per_kg'] : null;
    if ($price_per_kg === null || $price_per_kg <= 0) $errors[] = 'Price per kg must be greater than 0.';

    $valid_events = ['regular', 'eid', 'gift'];
    if (!in_array($form['event_type'], $valid_events, true)) $errors[] = 'Invalid event type.';

    if ($form['sale_date'] === '' || !strtotime($form['sale_date'])) {
        $errors[] = 'A valid sale date is required.';
    } elseif (strtotime($form['sale_date']) > strtotime('today')) {
        $errors[] = 'Sale date cannot be in the future.';
    }

    // Verify cow exists (excluding sold ones)
    $cow = null;
    if (empty($errors)) {
        $sel = $db->prepare("SELECT id, tag_number, breed FROM cows WHERE id = ? AND status != 'sold' AND " . farmFilter());
        $sel->execute([$cow_id]);
        $cow = $sel->fetch();
        if (!$cow) $errors[] = 'Selected cow was not found or is already sold.';
    }

    if (empty($errors)) {
        $user_id       = (int)$_SESSION['user_id'];
        $total_revenue = round($kg_sold * $price_per_kg, 2);
        $notes_val     = $form['notes'] !== '' ? $form['notes'] : null;

        $db->beginTransaction();
        try {
            // Insert meat sale
            $db->prepare(
                "INSERT INTO meat_sales (farm_id, cow_id, kg_sold, price_per_kg, total_revenue, event_type, sale_date, notes)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([fid(), $cow_id, $kg_sold, $price_per_kg, $total_revenue, $form['event_type'], $form['sale_date'], $notes_val]);
            $sale_id = (int)$db->lastInsertId();

            // Auto-create finance transaction
            $event_label = match($form['event_type']) {
                'eid'   => 'Eid',
                'gift'  => 'Gift',
                default => 'Regular',
            };
            $finance_notes = "Meat sale — Cow #{$cow['tag_number']}, {$kg_sold} kg ({$event_label})";
            $db->prepare(
                "INSERT INTO finance_transactions (farm_id, type, category, amount, related_module, reference_id, transaction_date, recorded_by, approved_by, notes)
                 VALUES (?, 'income','Meat Sales',?,?,?,?,?,?,?)"
            )->execute([fid(), $total_revenue, 'meat_sales', $sale_id, $form['sale_date'], $user_id, $user_id, $finance_notes]);

            auditLog($user_id, 'CREATE_MEAT_SALE', 'meat_sales', $sale_id, null, [
                'cow_id'        => $cow_id,
                'kg_sold'       => $kg_sold,
                'price_per_kg'  => $price_per_kg,
                'total_revenue' => $total_revenue,
                'event_type'    => $form['event_type'],
            ]);

            $db->commit();
            flashMessage('success', "Meat sale recorded — {$kg_sold} kg × " . formatCurrency($price_per_kg) . "/kg = " . formatCurrency($total_revenue));
            redirect('/modules/sales/index.php?tab=meat');
        } catch (PDOException $e) {
            $db->rollBack();
            $errors[] = 'Failed to save meat sale record. Please try again.';
        }
    }
}

$page_title = 'Record Meat Sale';
$active_nav = 'sales';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2>Record Meat Sale</h2></div>
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

<form method="POST" action="/modules/sales/meat_sale_form.php" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:580px;margin-bottom:1.25rem">
        <div class="card-body">

            <div class="form-group">
                <label class="form-label" for="cow_id">Cow <span style="color:var(--danger)">*</span></label>
                <select id="cow_id" name="cow_id" class="form-control" required>
                    <option value="">— Select cow —</option>
                    <?php foreach ($cows as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (string)$c['id'] === $form['cow_id'] ? 'selected' : '' ?>>
                        #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                        (<?= e(ucfirst(str_replace('_',' ',$c['status']))) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="event_type">Event Type <span style="color:var(--danger)">*</span></label>
                <select id="event_type" name="event_type" class="form-control">
                    <option value="regular" <?= $form['event_type'] === 'regular' ? 'selected' : '' ?>>Regular Sale</option>
                    <option value="eid"     <?= $form['event_type'] === 'eid'     ? 'selected' : '' ?>>Eid (Qurbani)</option>
                    <option value="gift"    <?= $form['event_type'] === 'gift'    ? 'selected' : '' ?>>Gift / Donation</option>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="kg_sold">Kg Sold <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="kg_sold" name="kg_sold" class="form-control"
                           value="<?= e($form['kg_sold']) ?>"
                           step="0.01" min="0.01" max="2000" placeholder="0.00"
                           oninput="calcRevenue()" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="price_per_kg">Price per Kg (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="price_per_kg" name="price_per_kg" class="form-control"
                           value="<?= e($form['price_per_kg']) ?>"
                           step="0.01" min="0.01" placeholder="0.00"
                           oninput="calcRevenue()" required>
                </div>
            </div>

            <!-- Revenue preview -->
            <div id="revenue_preview" style="display:none;background:var(--bg-muted);border:1px solid var(--border);border-radius:var(--radius);padding:.65rem 1rem;margin-bottom:1rem;font-size:.875rem">
                Total Revenue: <strong id="rev_display" style="color:var(--success)">—</strong>
            </div>

            <div class="form-group">
                <label class="form-label" for="sale_date">Sale Date <span style="color:var(--danger)">*</span></label>
                <input type="date" id="sale_date" name="sale_date" class="form-control"
                       value="<?= e($form['sale_date']) ?>"
                       max="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="Buyer details, delivery, remarks…"><?= e($form['notes']) ?></textarea>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Record Sale
        </button>
        <a href="/modules/sales/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php
$inline_js = <<<'JS'
function calcRevenue() {
    var kg      = parseFloat(document.getElementById('kg_sold').value)      || 0;
    var ppkg    = parseFloat(document.getElementById('price_per_kg').value) || 0;
    var preview = document.getElementById('revenue_preview');
    var display = document.getElementById('rev_display');

    if (kg > 0 && ppkg > 0) {
        var total = kg * ppkg;
        display.textContent = '৳' + total.toLocaleString('en-BD', {minimumFractionDigits:2, maximumFractionDigits:2});
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
