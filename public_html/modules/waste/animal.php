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
    if (!in_array('waste_cow_id', $cols)) {
        $db->exec("ALTER TABLE waste_records ADD COLUMN waste_cow_id INT UNSIGNED DEFAULT NULL AFTER finance_transaction_id");
    }
    if (!in_array('waste_loss_type', $cols)) {
        $db->exec("ALTER TABLE waste_records ADD COLUMN waste_loss_type VARCHAR(100) DEFAULT NULL AFTER waste_cow_id");
    }
})($db);

$errors      = [];
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;

// ── Cows list ─────────────────────────────────────────────────────────────────
$cows_stmt = $db->prepare("SELECT id, tag_number, breed, status FROM cows WHERE " . farmFilter() . " ORDER BY tag_number");
$cows_stmt->execute();
$cows_list = $cows_stmt->fetchAll();

$loss_types = ['Cow Death', 'Stillbirth', 'Missing / Lost', 'Emergency Disposal', 'Unsellable Meat', 'Unsellable Skin', 'Other Animal Loss'];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/waste/animal.php');
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete' && hasRole(['admin', 'manager', 'accountant'])) {
        $del_id = (int)($_POST['waste_id'] ?? 0);
        $sel = $db->prepare("SELECT * FROM waste_records WHERE id=? AND farm_id=? AND category='animal'");
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
                flashMessage('success', 'Animal loss record deleted.');
            } catch (Throwable $e) {
                $db->rollBack();
                flashMessage('error', 'Delete failed.');
            }
        }
        redirect('/modules/waste/animal.php');
    }

    $waste_date      = trim($_POST['waste_date'] ?? '');
    $cow_id          = (int)($_POST['cow_id'] ?? 0);
    $cow_name_manual = sanitize($_POST['cow_name_manual'] ?? '');
    $loss_type       = sanitize($_POST['loss_type'] ?? '');
    $est_value       = abs((float)($_POST['estimated_value'] ?? 0));
    $notes           = sanitize($_POST['notes'] ?? '');

    if ($waste_date === '')                           $errors[] = 'Date is required.';
    elseif (strtotime($waste_date) > time() + 86400) $errors[] = 'Date cannot be in the future.';
    if (!in_array($loss_type, $loss_types, true))    $errors[] = 'Loss type is required.';
    if ($est_value <= 0)                             $errors[] = 'Estimated value must be greater than zero.';
    if ($cow_id === 0 && $cow_name_manual === '')     $errors[] = 'Select a cow or enter a name.';

    if (empty($errors)) {
        $cow_label = '';
        if ($cow_id > 0) {
            $cr = $db->prepare("SELECT tag_number FROM cows WHERE id=? AND " . farmFilter());
            $cr->execute([$cow_id]);
            $co = $cr->fetch();
            $cow_label = $co ? "Cow #{$co['tag_number']}" : "Cow #{$cow_id}";
        } else {
            $cow_label = $cow_name_manual;
        }
        $item_name = "Animal Loss — {$cow_label} ({$loss_type})";
        $note      = "[Animal Waste] {$loss_type}: {$cow_label}" . ($notes ? " — {$notes}" : '');

        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'expense', 'Waste Loss', $est_value, 'waste', 0, $waste_date, $uid, $note]);
            $ft_id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO waste_records (farm_id,waste_date,category,item_name,quantity,unit,unit_price,total_loss,reason,finance_transaction_id,waste_cow_id,waste_loss_type,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $waste_date, 'animal', $item_name, 1, 'head', $est_value, $est_value, $loss_type, $ft_id, $cow_id ?: null, $loss_type, $uid]);
            $wr_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE finance_transactions SET reference_id=? WHERE id=?")->execute([$wr_id, $ft_id]);
            $db->commit();
            flashMessage('success', "Animal loss recorded: {$cow_label} — ৳" . number_format($est_value, 2) . ' loss.');
            redirect('/modules/waste/animal.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[animal_waste_add] ' . $e->getMessage());
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$stmt = $db->prepare("SELECT wr.*, u.name AS recorder FROM waste_records wr LEFT JOIN users u ON u.id=wr.recorded_by WHERE wr.farm_id=? AND wr.category='animal' AND wr.waste_date BETWEEN ? AND ? ORDER BY wr.waste_date DESC, wr.id DESC");
$stmt->execute([fid(), $filter_from, $filter_to]);
$records = $stmt->fetchAll();

$tq = $db->prepare("SELECT COALESCE(SUM(total_loss),0) AS total_loss, COUNT(*) AS cnt FROM waste_records WHERE farm_id=? AND category='animal' AND waste_date BETWEEN ? AND ?");
$tq->execute([fid(), $filter_from, $filter_to]);
$totals = $tq->fetch();

$page_title = 'Animal Waste';
$active_nav = 'waste_animal';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.sub-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.sub-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:.9rem 1.1rem; position:relative; }
.sub-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:10px 10px 0 0; background:var(--sc, #dc2626); }
.sub-card .sc-val { font-size:1.35rem; font-weight:800; color:#111; }
.sub-card .sc-lbl { font-size:.72rem; color:#6b7280; margin-top:.1rem; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">🐄 Animal Waste</h1>
        <p class="page-subtitle">Record livestock deaths, missing animals, and other animal losses</p>
    </div>
    <a href="/modules/waste/index.php" class="btn btn-secondary">← All Waste</a>
</div>

<div class="sub-cards">
    <div class="sub-card" style="--sc:#dc2626">
        <div class="sc-val">৳<?= number_format($totals['total_loss'], 2) ?></div>
        <div class="sc-lbl">Total Animal Loss</div>
    </div>
    <div class="sub-card" style="--sc:#ef4444">
        <div class="sc-val"><?= $totals['cnt'] ?></div>
        <div class="sc-lbl">Incidents (Period)</div>
    </div>
    <div class="sub-card" style="--sc:#f87171">
        <div class="sc-val"><?= count($cows_list) ?></div>
        <div class="sc-lbl">Active Cows in Farm</div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem">
    <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title">Record Animal Loss</span></div>
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
                    <label class="form-label">Select Cow</label>
                    <select name="cow_id" class="form-control" id="cowSel" onchange="toggleCowName(this)">
                        <option value="">— Manual entry —</option>
                        <?php foreach ($cows_list as $c): ?>
                        <option value="<?= $c['id'] ?>">#<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?> (<?= e($c['status']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="cowNameGroup">
                    <label class="form-label">Cow Name / ID (if not in list)</label>
                    <input type="text" name="cow_name_manual" class="form-control" placeholder="e.g. Brown Cow #12" maxlength="150">
                </div>
                <div class="form-group">
                    <label class="form-label">Loss Type <span style="color:var(--danger)">*</span></label>
                    <select name="loss_type" class="form-control" required>
                        <option value="">— Select type —</option>
                        <?php foreach ($loss_types as $lt): ?>
                        <option><?= e($lt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Estimated Value (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="estimated_value" class="form-control" step="0.01" min="0.01" placeholder="e.g. 50000" required>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Additional details about this loss..."></textarea>
                </div>
            </div>
            <div style="margin-top:.75rem;text-align:right">
                <button type="submit" class="btn btn-danger">Record Animal Loss</button>
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
        <span class="card-title">Animal Loss Records</span>
        <span style="font-size:.8rem;color:#6b7280"><?= count($records) ?> records</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Animal</th><th>Loss Type</th><th>Est. Value</th><th>Notes</th><th>Recorded By</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem">No animal loss records in this period.</td></tr>
            <?php else: ?>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($r['waste_date'])) ?></td>
                    <td><?= e($r['item_name']) ?></td>
                    <td><span style="background:#fee2e2;color:#dc2626;padding:.2rem .5rem;border-radius:4px;font-size:.78rem;font-weight:600"><?= e($r['waste_loss_type'] ?? $r['reason'] ?? '—') ?></span></td>
                    <td style="color:#dc2626;font-weight:700">৳<?= number_format($r['total_loss'], 2) ?></td>
                    <td style="color:#6b7280;font-size:.85rem;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['reason'] ?? '—') ?></td>
                    <td style="color:#6b7280;font-size:.85rem"><?= e($r['recorder'] ?? '—') ?></td>
                    <td>
                        <?php if (hasRole(['admin', 'manager', 'accountant'])): ?>
                        <form method="POST" onsubmit="return confirm('Delete this animal loss record?')">
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
function toggleCowName(sel) {
    const grp = document.getElementById('cowNameGroup');
    grp.style.display = sel.value ? 'none' : '';
}
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
