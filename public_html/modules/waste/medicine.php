<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant', 'veterinarian', 'worker']);
requireFarmScope();
requireNotBlocked();

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

// ── Inline migration ──────────────────────────────────────────────────────────
(function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `waste_records` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `farm_id` INT UNSIGNED NOT NULL,
      `waste_date` DATE NOT NULL,
      `category` ENUM('milk','feed','medicine','animal','equipment','other') NOT NULL DEFAULT 'other',
      `item_name` VARCHAR(200) NOT NULL,
      `quantity` DECIMAL(10,3) DEFAULT NULL,
      `unit` VARCHAR(50) DEFAULT NULL,
      `unit_price` DECIMAL(12,2) DEFAULT NULL,
      `total_loss` DECIMAL(12,2) NOT NULL,
      `reason` TEXT DEFAULT NULL,
      `finance_transaction_id` INT UNSIGNED DEFAULT NULL,
      `recorded_by` INT UNSIGNED NOT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_farm_date` (`farm_id`, `waste_date`),
      KEY `idx_category` (`farm_id`, `category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $cols = array_column($db->query("SHOW COLUMNS FROM waste_records")->fetchAll(), 'Field');
    if (!in_array('medicine_inv_id', $cols)) {
        $db->exec("ALTER TABLE waste_records ADD COLUMN medicine_inv_id INT UNSIGNED DEFAULT NULL AFTER finance_transaction_id");
    }
    if (!in_array('waste_batch_number', $cols)) {
        $db->exec("ALTER TABLE waste_records ADD COLUMN waste_batch_number VARCHAR(100) DEFAULT NULL AFTER medicine_inv_id");
    }
})($db);

$errors      = [];
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;

// ── Medicine inventory list ───────────────────────────────────────────────────
$med_items = $db->prepare("SELECT id, item_name, quantity, unit, cost_per_unit, batch_number FROM medicine_inventory WHERE farm_id=? ORDER BY item_name");
$med_items->execute([fid()]);
$med_items = $med_items->fetchAll();

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/waste/medicine.php');
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete' && hasRole(['admin', 'manager', 'accountant'])) {
        $del_id = (int)($_POST['waste_id'] ?? 0);
        $sel = $db->prepare("SELECT * FROM waste_records WHERE id=? AND farm_id=? AND category='medicine'");
        $sel->execute([$del_id, fid()]);
        $rec = $sel->fetch();
        if ($rec) {
            try {
                $db->beginTransaction();
                if ($rec['medicine_inv_id'] && $rec['quantity']) {
                    $db->prepare("UPDATE medicine_inventory SET quantity = quantity + ? WHERE id=? AND farm_id=?")
                       ->execute([$rec['quantity'], $rec['medicine_inv_id'], fid()]);
                }
                if ($rec['finance_transaction_id']) {
                    $db->prepare("DELETE FROM finance_transactions WHERE id=? AND farm_id=?")
                       ->execute([$rec['finance_transaction_id'], fid()]);
                }
                $db->prepare("DELETE FROM waste_records WHERE id=? AND farm_id=?")->execute([$del_id, fid()]);
                $db->commit();
                flashMessage('success', 'Medicine waste record deleted and stock restored.');
            } catch (Throwable $e) {
                $db->rollBack();
                flashMessage('error', 'Delete failed.');
            }
        }
        redirect('/modules/waste/medicine.php');
    }

    $waste_date      = trim($_POST['waste_date'] ?? '');
    $med_inv_id      = (int)($_POST['medicine_inv_id'] ?? 0);
    $item_name       = sanitize($_POST['item_name'] ?? '');
    $batch_number    = sanitize($_POST['batch_number'] ?? '');
    $quantity        = (float)($_POST['quantity'] ?? 0);
    $unit            = sanitize($_POST['unit'] ?? 'pcs');
    $unit_price      = (float)($_POST['unit_price'] ?? 0);
    $reason          = sanitize($_POST['reason'] ?? '');
    $total_loss      = round($quantity * $unit_price, 2);

    if ($waste_date === '')                           $errors[] = 'Date is required.';
    elseif (strtotime($waste_date) > time() + 86400) $errors[] = 'Date cannot be in the future.';
    if ($item_name === '')  $errors[] = 'Medicine name is required.';
    if ($quantity <= 0)     $errors[] = 'Quantity must be greater than zero.';
    if ($unit_price <= 0)   $errors[] = 'Cost per unit must be greater than zero.';
    if ($reason === '')     $errors[] = 'Reason is required.';

    if (empty($errors)) {
        $label = "Medicine Waste — {$item_name}";
        $note  = "[Medicine Waste] {$item_name}" . ($batch_number ? " (Batch: {$batch_number})" : '') . " {$quantity}{$unit} @ ৳{$unit_price} — {$reason}";
        try {
            $db->beginTransaction();
            if ($med_inv_id > 0) {
                $chk = $db->prepare("SELECT quantity FROM medicine_inventory WHERE id=? AND farm_id=?");
                $chk->execute([$med_inv_id, fid()]);
                $inv = $chk->fetch();
                if (!$inv) { throw new RuntimeException('Medicine item not found.'); }
                $new_qty = max(0, $inv['quantity'] - $quantity);
                $db->prepare("UPDATE medicine_inventory SET quantity=? WHERE id=? AND farm_id=?")
                   ->execute([$new_qty, $med_inv_id, fid()]);
            }
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'expense', 'Waste Loss', $total_loss, 'waste', 0, $waste_date, $uid, $note]);
            $ft_id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO waste_records (farm_id,waste_date,category,item_name,quantity,unit,unit_price,total_loss,reason,finance_transaction_id,medicine_inv_id,waste_batch_number,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $waste_date, 'medicine', $label, $quantity, $unit, $unit_price, $total_loss, $reason, $ft_id, $med_inv_id ?: null, $batch_number ?: null, $uid]);
            $wr_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE finance_transactions SET reference_id=? WHERE id=?")->execute([$wr_id, $ft_id]);
            $db->commit();
            flashMessage('success', "Medicine waste recorded: {$quantity}{$unit} — ৳" . number_format($total_loss, 2) . ' loss.');
            redirect('/modules/waste/medicine.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[med_waste_add] ' . $e->getMessage());
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$stmt = $db->prepare("SELECT wr.*, u.name AS recorder FROM waste_records wr LEFT JOIN users u ON u.id=wr.recorded_by WHERE wr.farm_id=? AND wr.category='medicine' AND wr.waste_date BETWEEN ? AND ? ORDER BY wr.waste_date DESC, wr.id DESC");
$stmt->execute([fid(), $filter_from, $filter_to]);
$records = $stmt->fetchAll();

$tq = $db->prepare("SELECT COALESCE(SUM(total_loss),0) AS total_loss, COALESCE(SUM(quantity),0) AS qty FROM waste_records WHERE farm_id=? AND category='medicine' AND waste_date BETWEEN ? AND ?");
$tq->execute([fid(), $filter_from, $filter_to]);
$totals = $tq->fetch();

$page_title = 'Medicine Waste';
$active_nav = 'waste_medicine';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.sub-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.sub-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:.9rem 1.1rem; position:relative; }
.sub-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:10px 10px 0 0; background:var(--sc, #8b5cf6); }
.sub-card .sc-val { font-size:1.35rem; font-weight:800; color:#111; }
.sub-card .sc-lbl { font-size:.72rem; color:#6b7280; margin-top:.1rem; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">💊 Medicine Waste</h1>
        <p class="page-subtitle">Track expired or damaged medicines — automatically reduces stock and logs to finance</p>
    </div>
    <a href="/modules/waste/index.php" class="btn btn-secondary">← All Waste</a>
</div>

<div class="sub-cards">
    <div class="sub-card" style="--sc:#8b5cf6">
        <div class="sc-val">৳<?= number_format($totals['total_loss'], 2) ?></div>
        <div class="sc-lbl">Total Medicine Loss</div>
    </div>
    <div class="sub-card" style="--sc:#a78bfa">
        <div class="sc-val"><?= number_format($totals['qty'], 1) ?></div>
        <div class="sc-lbl">Units Wasted (Period)</div>
    </div>
    <div class="sub-card" style="--sc:#c4b5fd">
        <div class="sc-val"><?= count($records) ?></div>
        <div class="sc-lbl">Records (Period)</div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem">
    <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title">Record Medicine Waste</span></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:1rem">
                <div class="form-group">
                    <label class="form-label">Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="waste_date" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Medicine Item</label>
                    <select name="medicine_inv_id" id="medSel" class="form-control" onchange="fillMedDetails(this)">
                        <option value="">— Manual entry —</option>
                        <?php foreach ($med_items as $m): ?>
                        <option value="<?= $m['id'] ?>" data-name="<?= e($m['item_name']) ?>" data-unit="<?= e($m['unit']) ?>" data-price="<?= $m['cost_per_unit'] ?>" data-batch="<?= e($m['batch_number'] ?? '') ?>">
                            <?= e($m['item_name']) ?> (Stock: <?= number_format($m['quantity'], 0) ?> <?= e($m['unit']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Medicine Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="item_name" id="medName" class="form-control" placeholder="e.g. Oxytetracycline" maxlength="200" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Batch Number</label>
                    <input type="text" name="batch_number" id="medBatch" class="form-control" placeholder="e.g. B2024-01" maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Qty Wasted <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="quantity" id="medQty" class="form-control" step="1" min="1" placeholder="e.g. 10" oninput="calcMedLoss()" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" id="medUnit" class="form-control" value="pcs" maxlength="30">
                </div>
                <div class="form-group">
                    <label class="form-label">Cost per Unit (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="unit_price" id="medUpr" class="form-control" step="0.01" min="0.01" placeholder="e.g. 150.00" oninput="calcMedLoss()" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Total Loss (৳)</label>
                    <input type="number" name="total_loss" id="medTotal" class="form-control" step="0.01" readonly style="background:#f9fafb;font-weight:700;color:#dc2626" placeholder="Auto-calculated">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Reason <span style="color:var(--danger)">*</span></label>
                    <select name="reason" class="form-control" required>
                        <option value="">— Select reason —</option>
                        <option>Expired</option>
                        <option>Broken Bottle / Container</option>
                        <option>Damaged Package</option>
                        <option>Contamination</option>
                        <option>Storage Failure</option>
                        <option>Other</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:.75rem;text-align:right">
                <button type="submit" class="btn btn-danger">Record Medicine Waste</button>
            </div>
        </form>
    </div>
</div>

<form method="GET" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem">
    <div class="form-group" style="margin:0;flex:1;min-width:130px">
        <label class="form-label">From</label>
        <input type="date" name="from" class="form-control" value="<?= e($filter_from) ?>">
    </div>
    <div class="form-group" style="margin:0;flex:1;min-width:130px">
        <label class="form-label">To</label>
        <input type="date" name="to" class="form-control" value="<?= e($filter_to) ?>">
    </div>
    <button type="submit" class="btn btn-secondary">Filter</button>
</form>

<div class="card">
    <div class="card-header">
        <span class="card-title">Medicine Waste Records</span>
        <span style="font-size:.8rem;color:#6b7280"><?= count($records) ?> records</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Medicine</th><th>Batch</th><th>Qty</th><th>Cost/Unit</th><th>Total Loss</th><th>Reason</th><th>Recorded By</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:2rem">No medicine waste records in this period.</td></tr>
            <?php else: ?>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($r['waste_date'])) ?></td>
                    <td><?= e($r['item_name']) ?></td>
                    <td style="color:#6b7280;font-size:.85rem"><?= e($r['waste_batch_number'] ?? '—') ?></td>
                    <td><?= $r['quantity'] ? number_format($r['quantity'], 0) . ' ' . e($r['unit'] ?? '') : '—' ?></td>
                    <td><?= $r['unit_price'] ? '৳' . number_format($r['unit_price'], 2) : '—' ?></td>
                    <td style="color:#dc2626;font-weight:700">৳<?= number_format($r['total_loss'], 2) ?></td>
                    <td><?= e($r['reason'] ?? '—') ?></td>
                    <td style="color:#6b7280;font-size:.85rem"><?= e($r['recorder'] ?? '—') ?></td>
                    <td>
                        <?php if (hasRole(['admin', 'manager', 'accountant'])): ?>
                        <form method="POST" onsubmit="return confirm('Delete this medicine waste record? Stock will be restored.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="waste_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Del</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function fillMedDetails(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    document.getElementById('medName').value  = opt.dataset.name  || '';
    document.getElementById('medUnit').value  = opt.dataset.unit  || 'pcs';
    document.getElementById('medUpr').value   = opt.dataset.price || '';
    document.getElementById('medBatch').value = opt.dataset.batch || '';
    calcMedLoss();
}
function calcMedLoss() {
    const q = parseFloat(document.getElementById('medQty').value) || 0;
    const p = parseFloat(document.getElementById('medUpr').value) || 0;
    document.getElementById('medTotal').value = (q > 0 && p > 0) ? (q * p).toFixed(2) : '';
}
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
