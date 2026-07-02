<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant', 'veterinarian', 'worker']);
requireFarmScope();
requireNotBlocked();

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

// ── Ensure waste_records table exists (safe to call on every load) ────────────
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

$errors      = [];
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/waste/milk.php');
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete' && hasRole(['admin', 'manager', 'accountant'])) {
        $del_id = (int)($_POST['waste_id'] ?? 0);
        $sel = $db->prepare("SELECT * FROM waste_records WHERE id=? AND farm_id=? AND category='milk'");
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
                flashMessage('success', 'Milk waste record deleted.');
            } catch (Throwable $e) {
                $db->rollBack();
                flashMessage('error', 'Delete failed.');
            }
        }
        redirect('/modules/waste/milk.php');
    }

    $waste_date = trim($_POST['waste_date'] ?? '');
    $quantity   = (float)($_POST['quantity'] ?? 0);
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    $reason     = sanitize($_POST['reason'] ?? '');
    $total_loss = round($quantity * $unit_price, 2);

    if ($waste_date === '')                           $errors[] = 'Date is required.';
    elseif (strtotime($waste_date) > time() + 86400) $errors[] = 'Date cannot be in the future.';
    if ($quantity <= 0)   $errors[] = 'Quantity must be greater than zero.';
    if ($unit_price <= 0) $errors[] = 'Cost per liter must be greater than zero.';
    if ($reason === '')   $errors[] = 'Reason is required.';

    if (empty($errors)) {
        $item_name = 'Milk Waste — ' . $reason;
        $note      = "[Milk Waste] {$quantity}L @ ৳{$unit_price}/L — {$reason}";
        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'expense', 'Waste Loss', $total_loss, 'waste', 0, $waste_date, $uid, $note]);
            $ft_id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO waste_records (farm_id,waste_date,category,item_name,quantity,unit,unit_price,total_loss,reason,finance_transaction_id,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $waste_date, 'milk', $item_name, $quantity, 'L', $unit_price, $total_loss, $reason, $ft_id, $uid]);
            $wr_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE finance_transactions SET reference_id=? WHERE id=?")->execute([$wr_id, $ft_id]);
            $db->commit();
            flashMessage('success', "Milk waste recorded: {$quantity}L — ৳" . number_format($total_loss, 2) . ' loss.');
            redirect('/modules/waste/milk.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[milk_waste_add] ' . $e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// ── Load records ──────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT wr.*, u.name AS recorder FROM waste_records wr LEFT JOIN users u ON u.id=wr.recorded_by WHERE wr.farm_id=? AND wr.category='milk' AND wr.waste_date BETWEEN ? AND ? ORDER BY wr.waste_date DESC, wr.id DESC");
$stmt->execute([fid(), $filter_from, $filter_to]);
$records = $stmt->fetchAll();

$tq = $db->prepare("SELECT COALESCE(SUM(total_loss),0) AS total_loss, COALESCE(SUM(quantity),0) AS qty FROM waste_records WHERE farm_id=? AND category='milk' AND waste_date BETWEEN ? AND ?");
$tq->execute([fid(), $filter_from, $filter_to]);
$totals = $tq->fetch();

$page_title = 'Milk Waste';
$active_nav = 'waste_milk';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.sub-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.sub-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:.9rem 1.1rem; position:relative; }
.sub-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:10px 10px 0 0; background:var(--sc, #3b82f6); }
.sub-card .sc-val { font-size:1.35rem; font-weight:800; color:#111; }
.sub-card .sc-lbl { font-size:.72rem; color:#6b7280; margin-top:.1rem; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">🥛 Milk Waste</h1>
        <p class="page-subtitle">Track spoiled, expired, or damaged milk — auto-logged to financial records</p>
    </div>
    <a href="/modules/waste/index.php" class="btn btn-secondary">← All Waste</a>
</div>

<div class="sub-cards">
    <div class="sub-card" style="--sc:#3b82f6">
        <div class="sc-val">৳<?= number_format($totals['total_loss'], 2) ?></div>
        <div class="sc-lbl">Total Milk Loss</div>
    </div>
    <div class="sub-card" style="--sc:#60a5fa">
        <div class="sc-val"><?= number_format($totals['qty'], 1) ?> L</div>
        <div class="sc-lbl">Total Litres Wasted</div>
    </div>
    <div class="sub-card" style="--sc:#93c5fd">
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
    <div class="card-header"><span class="card-title">Record Milk Waste</span></div>
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
                    <label class="form-label">Milk Quantity (L) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="quantity" class="form-control" step="0.1" min="0.1" placeholder="e.g. 5.5" id="mwQty" oninput="calcMilkLoss()" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Cost per Liter (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="unit_price" class="form-control" step="0.01" min="0.01" placeholder="e.g. 50.00" id="mwUpr" oninput="calcMilkLoss()" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Total Loss (৳)</label>
                    <input type="number" name="total_loss" class="form-control" id="mwTotal" step="0.01" placeholder="Auto-calculated" readonly style="background:#f9fafb;font-weight:700;color:#dc2626">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Reason <span style="color:var(--danger)">*</span></label>
                    <select name="reason" class="form-control" required>
                        <option value="">— Select reason —</option>
                        <option>Spoiled</option>
                        <option>Expired</option>
                        <option>Storage Failure</option>
                        <option>Transport Damage</option>
                        <option>Customer Rejection</option>
                        <option>Internal Consumption</option>
                        <option>Other</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:.75rem;text-align:right">
                <button type="submit" class="btn btn-danger">Record Milk Waste</button>
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
        <span class="card-title">Milk Waste Records</span>
        <span style="font-size:.8rem;color:#6b7280"><?= count($records) ?> records</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Qty (L)</th><th>Cost/L</th><th>Total Loss</th><th>Reason</th><th>Recorded By</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:2rem">No milk waste records in this period.</td></tr>
            <?php else: ?>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($r['waste_date'])) ?></td>
                    <td><?= $r['quantity'] ? number_format($r['quantity'], 1) : '—' ?></td>
                    <td><?= $r['unit_price'] ? '৳' . number_format($r['unit_price'], 2) : '—' ?></td>
                    <td style="color:#dc2626;font-weight:700">৳<?= number_format($r['total_loss'], 2) ?></td>
                    <td><?= e($r['reason'] ?? '—') ?></td>
                    <td style="color:#6b7280;font-size:.85rem"><?= e($r['recorder'] ?? '—') ?></td>
                    <td>
                        <?php if (hasRole(['admin', 'manager', 'accountant'])): ?>
                        <form method="POST" onsubmit="return confirm('Delete this milk waste record?')">
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
function calcMilkLoss() {
    const q = parseFloat(document.getElementById('mwQty').value) || 0;
    const p = parseFloat(document.getElementById('mwUpr').value) || 0;
    document.getElementById('mwTotal').value = (q > 0 && p > 0) ? (q * p).toFixed(2) : '';
}
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
