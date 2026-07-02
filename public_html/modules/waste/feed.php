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
    if (!in_array('feed_inv_id', $cols)) {
        $db->exec("ALTER TABLE waste_records ADD COLUMN feed_inv_id INT UNSIGNED DEFAULT NULL AFTER finance_transaction_id");
    }
})($db);

$errors      = [];
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;

// ── Feed inventory list for dropdown ─────────────────────────────────────────
$feed_items = $db->prepare("SELECT id, item_name, quantity, unit, purchase_price FROM feed_inventory WHERE farm_id=? ORDER BY item_name");
$feed_items->execute([fid()]);
$feed_items = $feed_items->fetchAll();

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/waste/feed.php');
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete' && hasRole(['admin', 'manager', 'accountant'])) {
        $del_id = (int)($_POST['waste_id'] ?? 0);
        $sel = $db->prepare("SELECT * FROM waste_records WHERE id=? AND farm_id=? AND category='feed'");
        $sel->execute([$del_id, fid()]);
        $rec = $sel->fetch();
        if ($rec) {
            try {
                $db->beginTransaction();
                // Restore stock
                if ($rec['feed_inv_id'] && $rec['quantity']) {
                    $db->prepare("UPDATE feed_inventory SET quantity = quantity + ? WHERE id=? AND farm_id=?")
                       ->execute([$rec['quantity'], $rec['feed_inv_id'], fid()]);
                }
                if ($rec['finance_transaction_id']) {
                    $db->prepare("DELETE FROM finance_transactions WHERE id=? AND farm_id=?")
                       ->execute([$rec['finance_transaction_id'], fid()]);
                }
                $db->prepare("DELETE FROM waste_records WHERE id=? AND farm_id=?")->execute([$del_id, fid()]);
                $db->commit();
                flashMessage('success', 'Feed waste record deleted and stock restored.');
            } catch (Throwable $e) {
                $db->rollBack();
                flashMessage('error', 'Delete failed.');
            }
        }
        redirect('/modules/waste/feed.php');
    }

    $waste_date  = trim($_POST['waste_date'] ?? '');
    $feed_inv_id = (int)($_POST['feed_inv_id'] ?? 0);
    $item_name   = sanitize($_POST['item_name'] ?? '');
    $quantity    = (float)($_POST['quantity'] ?? 0);
    $unit        = sanitize($_POST['unit'] ?? 'kg');
    $unit_price  = (float)($_POST['unit_price'] ?? 0);
    $reason      = sanitize($_POST['reason'] ?? '');
    $total_loss  = round($quantity * $unit_price, 2);

    if ($waste_date === '')                           $errors[] = 'Date is required.';
    elseif (strtotime($waste_date) > time() + 86400) $errors[] = 'Date cannot be in the future.';
    if ($item_name === '')   $errors[] = 'Feed name is required.';
    if ($quantity <= 0)      $errors[] = 'Quantity must be greater than zero.';
    if ($unit_price <= 0)    $errors[] = 'Cost per unit must be greater than zero.';
    if ($reason === '')      $errors[] = 'Reason is required.';

    if (empty($errors)) {
        $label     = "Feed Waste — {$item_name}";
        $note      = "[Feed Waste] {$item_name} {$quantity}{$unit} @ ৳{$unit_price} — {$reason}";
        try {
            $db->beginTransaction();

            // Reduce feed stock if linked to inventory
            if ($feed_inv_id > 0) {
                $chk = $db->prepare("SELECT quantity FROM feed_inventory WHERE id=? AND farm_id=?");
                $chk->execute([$feed_inv_id, fid()]);
                $inv = $chk->fetch();
                if (!$inv) { throw new RuntimeException('Feed item not found.'); }
                $new_qty = max(0, $inv['quantity'] - $quantity);
                $db->prepare("UPDATE feed_inventory SET quantity=? WHERE id=? AND farm_id=?")
                   ->execute([$new_qty, $feed_inv_id, fid()]);
            }

            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'expense', 'Waste Loss', $total_loss, 'waste', 0, $waste_date, $uid, $note]);
            $ft_id = (int)$db->lastInsertId();

            $db->prepare("INSERT INTO waste_records (farm_id,waste_date,category,item_name,quantity,unit,unit_price,total_loss,reason,finance_transaction_id,feed_inv_id,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $waste_date, 'feed', $label, $quantity, $unit, $unit_price, $total_loss, $reason, $ft_id, $feed_inv_id ?: null, $uid]);
            $wr_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE finance_transactions SET reference_id=? WHERE id=?")->execute([$wr_id, $ft_id]);
            $db->commit();
            flashMessage('success', "Feed waste recorded: {$quantity}{$unit} — ৳" . number_format($total_loss, 2) . ' loss.');
            redirect('/modules/waste/feed.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[feed_waste_add] ' . $e->getMessage());
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// ── Load records ──────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT wr.*, u.name AS recorder FROM waste_records wr LEFT JOIN users u ON u.id=wr.recorded_by WHERE wr.farm_id=? AND wr.category='feed' AND wr.waste_date BETWEEN ? AND ? ORDER BY wr.waste_date DESC, wr.id DESC");
$stmt->execute([fid(), $filter_from, $filter_to]);
$records = $stmt->fetchAll();

$tq = $db->prepare("SELECT COALESCE(SUM(total_loss),0) AS total_loss, COALESCE(SUM(quantity),0) AS qty FROM waste_records WHERE farm_id=? AND category='feed' AND waste_date BETWEEN ? AND ?");
$tq->execute([fid(), $filter_from, $filter_to]);
$totals = $tq->fetch();

$page_title = 'Feed Waste';
$active_nav = 'waste_feed';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.sub-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.sub-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:.9rem 1.1rem; position:relative; }
.sub-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:10px 10px 0 0; background:var(--sc, #d97706); }
.sub-card .sc-val { font-size:1.35rem; font-weight:800; color:#111; }
.sub-card .sc-lbl { font-size:.72rem; color:#6b7280; margin-top:.1rem; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">🌾 Feed Waste</h1>
        <p class="page-subtitle">Track spoiled or damaged feed — automatically reduces feed stock and logs to finance</p>
    </div>
    <a href="/modules/waste/index.php" class="btn btn-secondary">← All Waste</a>
</div>

<div class="sub-cards">
    <div class="sub-card" style="--sc:#d97706">
        <div class="sc-val">৳<?= number_format($totals['total_loss'], 2) ?></div>
        <div class="sc-lbl">Total Feed Loss</div>
    </div>
    <div class="sub-card" style="--sc:#f59e0b">
        <div class="sc-val"><?= number_format($totals['qty'], 1) ?></div>
        <div class="sc-lbl">Units Wasted (Period)</div>
    </div>
    <div class="sub-card" style="--sc:#fbbf24">
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
    <div class="card-header"><span class="card-title">Record Feed Waste</span></div>
    <div class="card-body">
        <form method="POST" id="feedWasteForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:1rem">
                <div class="form-group">
                    <label class="form-label">Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="waste_date" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Feed Item <span style="color:var(--danger)">*</span></label>
                    <select name="feed_inv_id" id="feedSel" class="form-control" onchange="fillFeedDetails(this)">
                        <option value="">— Manual entry —</option>
                        <?php foreach ($feed_items as $fi): ?>
                        <option value="<?= $fi['id'] ?>" data-name="<?= e($fi['item_name']) ?>" data-unit="<?= e($fi['unit']) ?>" data-price="<?= $fi['purchase_price'] ?>">
                            <?= e($fi['item_name']) ?> (Stock: <?= number_format($fi['quantity'], 1) ?> <?= e($fi['unit']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Feed Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="item_name" id="feedName" class="form-control" placeholder="e.g. Corn Silage" maxlength="200" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Qty Wasted <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="quantity" id="feedQty" class="form-control" step="0.1" min="0.1" placeholder="e.g. 20" oninput="calcFeedLoss()" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" id="feedUnit" class="form-control" placeholder="kg" maxlength="30">
                </div>
                <div class="form-group">
                    <label class="form-label">Cost per Unit (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="unit_price" id="feedUpr" class="form-control" step="0.01" min="0.01" placeholder="e.g. 25.00" oninput="calcFeedLoss()" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Total Loss (৳)</label>
                    <input type="number" name="total_loss" id="feedTotal" class="form-control" step="0.01" readonly style="background:#f9fafb;font-weight:700;color:#dc2626" placeholder="Auto-calculated">
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Reason <span style="color:var(--danger)">*</span></label>
                    <select name="reason" class="form-control" required>
                        <option value="">— Select reason —</option>
                        <option>Spoiled</option>
                        <option>Mold / Fungal</option>
                        <option>Water Damage</option>
                        <option>Rodent Damage</option>
                        <option>Feeding Wastage</option>
                        <option>Expired</option>
                        <option>Other</option>
                    </select>
                </div>
            </div>
            <div style="margin-top:.75rem;text-align:right">
                <button type="submit" class="btn btn-danger">Record Feed Waste</button>
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
        <span class="card-title">Feed Waste Records</span>
        <span style="font-size:.8rem;color:#6b7280"><?= count($records) ?> records</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Item</th><th>Qty</th><th>Cost/Unit</th><th>Total Loss</th><th>Reason</th><th>Recorded By</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:2rem">No feed waste records in this period.</td></tr>
            <?php else: ?>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($r['waste_date'])) ?></td>
                    <td><?= e($r['item_name']) ?></td>
                    <td><?= $r['quantity'] ? number_format($r['quantity'], 1) . ' ' . e($r['unit'] ?? '') : '—' ?></td>
                    <td><?= $r['unit_price'] ? '৳' . number_format($r['unit_price'], 2) : '—' ?></td>
                    <td style="color:#dc2626;font-weight:700">৳<?= number_format($r['total_loss'], 2) ?></td>
                    <td><?= e($r['reason'] ?? '—') ?></td>
                    <td style="color:#6b7280;font-size:.85rem"><?= e($r['recorder'] ?? '—') ?></td>
                    <td>
                        <?php if (hasRole(['admin', 'manager', 'accountant'])): ?>
                        <form method="POST" onsubmit="return confirm('Delete this feed waste record? Stock will be restored.')">
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
function fillFeedDetails(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    document.getElementById('feedName').value = opt.dataset.name || '';
    document.getElementById('feedUnit').value = opt.dataset.unit || 'kg';
    document.getElementById('feedUpr').value  = opt.dataset.price || '';
    calcFeedLoss();
}
function calcFeedLoss() {
    const q = parseFloat(document.getElementById('feedQty').value) || 0;
    const p = parseFloat(document.getElementById('feedUpr').value) || 0;
    document.getElementById('feedTotal').value = (q > 0 && p > 0) ? (q * p).toFixed(2) : '';
}
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
