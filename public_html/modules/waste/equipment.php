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
    if (!in_array('waste_equipment_id', $cols)) {
        $db->exec("ALTER TABLE waste_records ADD COLUMN waste_equipment_id INT UNSIGNED DEFAULT NULL AFTER finance_transaction_id");
    }
    if (!in_array('damage_status', $cols)) {
        $db->exec("ALTER TABLE waste_records ADD COLUMN damage_status VARCHAR(50) DEFAULT NULL AFTER waste_equipment_id");
    }
    if (!in_array('repair_cost', $cols)) {
        $db->exec("ALTER TABLE waste_records ADD COLUMN repair_cost DECIMAL(12,2) DEFAULT NULL AFTER damage_status");
    }
})($db);

$errors      = [];
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;

// ── Equipment list ────────────────────────────────────────────────────────────
$eq_stmt = $db->prepare("SELECT id, name, current_value, status FROM equipment WHERE " . farmFilter() . " AND status NOT IN ('sold','disposed') ORDER BY name");
$eq_stmt->execute();
$eq_list = $eq_stmt->fetchAll();

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/waste/equipment.php');
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete' && hasRole(['admin', 'manager', 'accountant'])) {
        $del_id = (int)($_POST['waste_id'] ?? 0);
        $sel = $db->prepare("SELECT * FROM waste_records WHERE id=? AND farm_id=? AND category='equipment'");
        $sel->execute([$del_id, fid()]);
        $rec = $sel->fetch();
        if ($rec) {
            try {
                $db->beginTransaction();
                if ($rec['finance_transaction_id']) {
                    $db->prepare("DELETE FROM finance_transactions WHERE id=? AND farm_id=?")
                       ->execute([$rec['finance_transaction_id'], fid()]);
                }
                $db->prepare("DELETE FROM waste_records WHERE id=? AND farm_id=?")->execute([$del_id, fid()]);
                $db->commit();
                flashMessage('success', 'Equipment damage record deleted.');
            } catch (Throwable $e) {
                $db->rollBack();
                flashMessage('error', 'Delete failed.');
            }
        }
        redirect('/modules/waste/equipment.php');
    }

    $waste_date   = trim($_POST['waste_date'] ?? '');
    $eq_id        = (int)($_POST['equipment_id'] ?? 0);
    $eq_name      = sanitize($_POST['equipment_name'] ?? '');
    $damage_value = abs((float)($_POST['damage_value'] ?? 0));
    $repair_cost  = abs((float)($_POST['repair_cost'] ?? 0));
    $dam_status   = sanitize($_POST['damage_status'] ?? '');
    $notes        = sanitize($_POST['notes'] ?? '');
    $total_loss   = $damage_value + $repair_cost;

    if ($waste_date === '')                           $errors[] = 'Date is required.';
    elseif (strtotime($waste_date) > time() + 86400) $errors[] = 'Date cannot be in the future.';
    if ($eq_id === 0 && $eq_name === '')             $errors[] = 'Select equipment or enter a name.';
    if ($damage_value <= 0 && $repair_cost <= 0)     $errors[] = 'Damage value or repair cost must be greater than zero.';
    if (!in_array($dam_status, ['repairable', 'partially_damaged', 'total_loss'], true)) $errors[] = 'Damage status is required.';

    if (empty($errors)) {
        if ($eq_id > 0 && $eq_name === '') {
            $er = $db->prepare("SELECT name FROM equipment WHERE id=? AND " . farmFilter());
            $er->execute([$eq_id]);
            $eo = $er->fetch();
            $eq_name = $eo ? $eo['name'] : "Equipment #{$eq_id}";
        }
        $item_name = "Equipment Damage — {$eq_name}";
        $note      = "[Equipment Damage] {$eq_name} | Status: {$dam_status} | Damage: ৳{$damage_value} Repair: ৳{$repair_cost}" . ($notes ? " — {$notes}" : '');

        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'expense', 'Waste Loss', $total_loss, 'waste', 0, $waste_date, $uid, $note]);
            $ft_id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO waste_records (farm_id,waste_date,category,item_name,quantity,unit,unit_price,total_loss,reason,finance_transaction_id,waste_equipment_id,damage_status,repair_cost,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $waste_date, 'equipment', $item_name, 1, 'unit', $damage_value, $total_loss, $dam_status . ($notes ? " — {$notes}" : ''), $ft_id, $eq_id ?: null, $dam_status, $repair_cost, $uid]);
            $wr_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE finance_transactions SET reference_id=? WHERE id=?")->execute([$wr_id, $ft_id]);
            $db->commit();
            flashMessage('success', "Equipment damage recorded: {$eq_name} — ৳" . number_format($total_loss, 2) . ' total loss.');
            redirect('/modules/waste/equipment.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[equip_waste_add] ' . $e->getMessage());
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$stmt = $db->prepare("SELECT wr.*, u.name AS recorder FROM waste_records wr LEFT JOIN users u ON u.id=wr.recorded_by WHERE wr.farm_id=? AND wr.category='equipment' AND wr.waste_date BETWEEN ? AND ? ORDER BY wr.waste_date DESC, wr.id DESC");
$stmt->execute([fid(), $filter_from, $filter_to]);
$records = $stmt->fetchAll();

$tq = $db->prepare("SELECT COALESCE(SUM(total_loss),0) AS total_loss, COALESCE(SUM(repair_cost),0) AS total_repair, COUNT(*) AS cnt FROM waste_records WHERE farm_id=? AND category='equipment' AND waste_date BETWEEN ? AND ?");
$tq->execute([fid(), $filter_from, $filter_to]);
$totals = $tq->fetch();

$status_labels = ['repairable' => ['Repairable', '#d97706', '#fef3c7'], 'partially_damaged' => ['Partially Damaged', '#ea580c', '#ffedd5'], 'total_loss' => ['Total Loss', '#dc2626', '#fee2e2']];

$page_title = 'Equipment Damage';
$active_nav = 'waste_equipment';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.sub-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.sub-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:.9rem 1.1rem; position:relative; }
.sub-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:10px 10px 0 0; background:var(--sc, #6b7280); }
.sub-card .sc-val { font-size:1.35rem; font-weight:800; color:#111; }
.sub-card .sc-lbl { font-size:.72rem; color:#6b7280; margin-top:.1rem; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">⚙️ Equipment Damage</h1>
        <p class="page-subtitle">Record damaged or destroyed equipment — tracks damage value + repair costs</p>
    </div>
    <a href="/modules/waste/index.php" class="btn btn-secondary">← All Waste</a>
</div>

<div class="sub-cards">
    <div class="sub-card" style="--sc:#6b7280">
        <div class="sc-val">৳<?= number_format($totals['total_loss'], 2) ?></div>
        <div class="sc-lbl">Total Equipment Loss</div>
    </div>
    <div class="sub-card" style="--sc:#9ca3af">
        <div class="sc-val">৳<?= number_format($totals['total_repair'], 2) ?></div>
        <div class="sc-lbl">Repair Costs (Period)</div>
    </div>
    <div class="sub-card" style="--sc:#d1d5db">
        <div class="sc-val"><?= $totals['cnt'] ?></div>
        <div class="sc-lbl">Incidents (Period)</div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem">
    <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title">Record Equipment Damage</span></div>
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
                    <label class="form-label">Select Equipment</label>
                    <select name="equipment_id" id="eqSel" class="form-control" onchange="fillEqName(this)">
                        <option value="">— Manual entry —</option>
                        <?php foreach ($eq_list as $eq): ?>
                        <option value="<?= $eq['id'] ?>" data-name="<?= e($eq['name']) ?>" data-val="<?= $eq['current_value'] ?>">
                            <?= e($eq['name']) ?> (Value: ৳<?= number_format($eq['current_value'] ?? 0, 0) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Equipment Name</label>
                    <input type="text" name="equipment_name" id="eqName" class="form-control" placeholder="e.g. Milk Pump #2" maxlength="200">
                </div>
                <div class="form-group">
                    <label class="form-label">Damage Status <span style="color:var(--danger)">*</span></label>
                    <select name="damage_status" class="form-control" required>
                        <option value="">— Select status —</option>
                        <option value="repairable">Repairable</option>
                        <option value="partially_damaged">Partially Damaged</option>
                        <option value="total_loss">Total Loss</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Damage Value (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="damage_value" id="eqDmg" class="form-control" step="0.01" min="0" placeholder="e.g. 5000" oninput="calcEqTotal()" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Repair Cost (৳)</label>
                    <input type="number" name="repair_cost" id="eqRep" class="form-control" step="0.01" min="0" placeholder="e.g. 2000" oninput="calcEqTotal()" value="0">
                </div>
                <div class="form-group">
                    <label class="form-label">Total Loss (৳)</label>
                    <input type="number" id="eqTotal" class="form-control" step="0.01" readonly style="background:#f9fafb;font-weight:700;color:#dc2626" placeholder="Damage + Repair">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="What happened? How was it damaged?"></textarea>
                </div>
            </div>
            <div style="margin-top:.75rem;text-align:right">
                <button type="submit" class="btn btn-danger">Record Equipment Damage</button>
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
        <span class="card-title">Equipment Damage Records</span>
        <span style="font-size:.8rem;color:#6b7280"><?= count($records) ?> records</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Equipment</th><th>Status</th><th>Damage Value</th><th>Repair Cost</th><th>Total Loss</th><th>Notes</th><th>By</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:2rem">No equipment damage records in this period.</td></tr>
            <?php else: ?>
            <?php foreach ($records as $r):
                $ds = $r['damage_status'] ?? '';
                $sl = $status_labels[$ds] ?? ['Unknown', '#6b7280', '#f3f4f6'];
            ?>
                <tr>
                    <td><?= date('d M Y', strtotime($r['waste_date'])) ?></td>
                    <td><?= e($r['item_name']) ?></td>
                    <td><span style="background:<?= $sl[2] ?>;color:<?= $sl[1] ?>;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600"><?= e($sl[0]) ?></span></td>
                    <td>৳<?= number_format($r['unit_price'] ?? 0, 2) ?></td>
                    <td>৳<?= number_format($r['repair_cost'] ?? 0, 2) ?></td>
                    <td style="color:#dc2626;font-weight:700">৳<?= number_format($r['total_loss'], 2) ?></td>
                    <td style="color:#6b7280;font-size:.85rem;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['reason'] ?? '—') ?></td>
                    <td style="color:#6b7280;font-size:.85rem"><?= e($r['recorder'] ?? '—') ?></td>
                    <td>
                        <?php if (hasRole(['admin', 'manager', 'accountant'])): ?>
                        <form method="POST" onsubmit="return confirm('Delete this equipment damage record?')">
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
function fillEqName(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    document.getElementById('eqName').value = opt.dataset.name || '';
    document.getElementById('eqDmg').value  = opt.dataset.val  || '0';
    calcEqTotal();
}
function calcEqTotal() {
    const d = parseFloat(document.getElementById('eqDmg').value) || 0;
    const r = parseFloat(document.getElementById('eqRep').value) || 0;
    document.getElementById('eqTotal').value = (d + r).toFixed(2);
}
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
