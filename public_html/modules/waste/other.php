<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant', 'veterinarian', 'worker']);
requireFarmScope();
requireNotBlocked();

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');

$errors      = [];
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;

$other_categories = ['Theft', 'Natural Disaster', 'Fire Damage', 'Water / Flood Damage', 'Pest Damage', 'Administrative Error', 'Miscellaneous'];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/waste/other.php');
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete' && hasRole(['admin', 'manager', 'accountant'])) {
        $del_id = (int)($_POST['waste_id'] ?? 0);
        $sel = $db->prepare("SELECT * FROM waste_records WHERE id=? AND farm_id=? AND category='other'");
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
                flashMessage('success', 'Loss record deleted.');
            } catch (Throwable $e) {
                $db->rollBack();
                flashMessage('error', 'Delete failed.');
            }
        }
        redirect('/modules/waste/other.php');
    }

    $waste_date  = trim($_POST['waste_date'] ?? '');
    $cat_choice  = sanitize($_POST['other_category'] ?? '');
    $item_name   = sanitize($_POST['item_name'] ?? '');
    $total_loss  = abs((float)($_POST['total_loss'] ?? 0));
    $description = sanitize($_POST['description'] ?? '');

    if ($waste_date === '')                           $errors[] = 'Date is required.';
    elseif (strtotime($waste_date) > time() + 86400) $errors[] = 'Date cannot be in the future.';
    if ($cat_choice === '')    $errors[] = 'Category is required.';
    if ($item_name === '')     $errors[] = 'Description is required.';
    if ($total_loss <= 0)     $errors[] = 'Loss amount must be greater than zero.';

    if (empty($errors)) {
        $label = "Other Loss — {$cat_choice}: {$item_name}";
        $note  = "[Other Loss] {$cat_choice}: {$item_name}" . ($description ? " — {$description}" : '');
        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'expense', 'Waste Loss', $total_loss, 'waste', 0, $waste_date, $uid, $note]);
            $ft_id = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO waste_records (farm_id,waste_date,category,item_name,quantity,unit,unit_price,total_loss,reason,finance_transaction_id,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $waste_date, 'other', $label, null, null, null, $total_loss, $description ?: $cat_choice, $ft_id, $uid]);
            $wr_id = (int)$db->lastInsertId();
            $db->prepare("UPDATE finance_transactions SET reference_id=? WHERE id=?")->execute([$wr_id, $ft_id]);
            $db->commit();
            flashMessage('success', "Loss recorded: {$cat_choice} — ৳" . number_format($total_loss, 2) . '.');
            redirect('/modules/waste/other.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[other_waste_add] ' . $e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$stmt = $db->prepare("SELECT wr.*, u.name AS recorder FROM waste_records wr LEFT JOIN users u ON u.id=wr.recorded_by WHERE wr.farm_id=? AND wr.category='other' AND wr.waste_date BETWEEN ? AND ? ORDER BY wr.waste_date DESC, wr.id DESC");
$stmt->execute([fid(), $filter_from, $filter_to]);
$records = $stmt->fetchAll();

$tq = $db->prepare("SELECT COALESCE(SUM(total_loss),0) AS total_loss, COUNT(*) AS cnt FROM waste_records WHERE farm_id=? AND category='other' AND waste_date BETWEEN ? AND ?");
$tq->execute([fid(), $filter_from, $filter_to]);
$totals = $tq->fetch();

$page_title = 'Other Loss';
$active_nav = 'waste_other';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.sub-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.sub-card { background:#fff; border:1px solid var(--border); border-radius:10px; padding:.9rem 1.1rem; position:relative; }
.sub-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:10px 10px 0 0; background:var(--sc, #f97316); }
.sub-card .sc-val { font-size:1.35rem; font-weight:800; color:#111; }
.sub-card .sc-lbl { font-size:.72rem; color:#6b7280; margin-top:.1rem; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">💸 Other Loss</h1>
        <p class="page-subtitle">Record theft, natural disasters, fire damage, and miscellaneous losses</p>
    </div>
    <a href="/modules/waste/index.php" class="btn btn-secondary">← All Waste</a>
</div>

<div class="sub-cards">
    <div class="sub-card" style="--sc:#f97316">
        <div class="sc-val">৳<?= number_format($totals['total_loss'], 2) ?></div>
        <div class="sc-lbl">Total Other Loss</div>
    </div>
    <div class="sub-card" style="--sc:#fb923c">
        <div class="sc-val"><?= $totals['cnt'] ?></div>
        <div class="sc-lbl">Records (Period)</div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem">
    <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title">Record Other Loss</span></div>
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
                    <label class="form-label">Loss Category <span style="color:var(--danger)">*</span></label>
                    <select name="other_category" class="form-control" required>
                        <option value="">— Select category —</option>
                        <?php foreach ($other_categories as $oc): ?>
                        <option><?= e($oc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Brief Description <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="item_name" class="form-control" placeholder="e.g. Generator stolen from shed" maxlength="200" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Loss Amount (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="total_loss" class="form-control" step="0.01" min="0.01" placeholder="e.g. 15000" required>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Additional Details</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="More details about what happened..."></textarea>
                </div>
            </div>
            <div style="margin-top:.75rem;text-align:right">
                <button type="submit" class="btn btn-danger">Record Loss</button>
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
        <span class="card-title">Other Loss Records</span>
        <span style="font-size:.8rem;color:#6b7280"><?= count($records) ?> records</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr><th>Date</th><th>Category / Description</th><th>Amount</th><th>Details</th><th>Recorded By</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($records)): ?>
                <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:2rem">No other loss records in this period.</td></tr>
            <?php else: ?>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($r['waste_date'])) ?></td>
                    <td><?= e($r['item_name']) ?></td>
                    <td style="color:#dc2626;font-weight:700">৳<?= number_format($r['total_loss'], 2) ?></td>
                    <td style="color:#6b7280;font-size:.85rem;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['reason'] ?? '—') ?></td>
                    <td style="color:#6b7280;font-size:.85rem"><?= e($r['recorder'] ?? '—') ?></td>
                    <td>
                        <?php if (hasRole(['admin', 'manager', 'accountant'])): ?>
                        <form method="POST" onsubmit="return confirm('Delete this loss record?')">
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
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
