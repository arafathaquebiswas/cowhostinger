<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireModule('cows');
requireRole(['admin', 'manager']);

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$errors = [];

$type_labels = [
    'family_gift'  => 'Family Gift',
    'family_use'   => 'Family Use (Milk/Meat)',
    'internal_use' => 'Internal Use',
    'charity'      => 'Charity / Donation',
    'sacrifice'    => 'Religious Sacrifice',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/cows/family_transfer.php');
    }
    $action = $_POST['action'] ?? 'add';
    if ($action === 'delete') {
        $db->prepare("DELETE FROM cow_family_transfers WHERE id = ? AND farm_id = ?")->execute([(int)$_POST['tr_id'], fid()]);
        flashMessage('success', 'Transfer record deleted.');
        redirect('/modules/cows/family_transfer.php');
    }

    $cow_id   = (int)($_POST['cow_id'] ?? 0);
    $tr_type  = in_array($_POST['transfer_type'] ?? '', array_keys($type_labels), true)
                ? $_POST['transfer_type'] : '';
    $recip    = sanitize($_POST['recipient_name'] ?? '');
    $est_val  = (float)($_POST['estimated_value'] ?? 0);
    $tr_date  = trim($_POST['transfer_date'] ?? '');
    $notes    = sanitize($_POST['notes'] ?? '');
    $mark_sold = isset($_POST['mark_transferred']);

    if ($cow_id <= 0)  $errors[] = 'Please select a cow.';
    if ($tr_type === '') $errors[] = 'Transfer type is required.';
    if ($tr_date === '') $errors[] = 'Transfer date is required.';

    // Verify cow belongs to this farm
    if ($cow_id > 0) {
        $chk = $db->prepare("SELECT id, tag_number, status FROM cows WHERE id = ? AND " . farmFilter());
        $chk->execute([$cow_id]);
        $the_cow = $chk->fetch();
        if (!$the_cow) $errors[] = 'Cow not found.';
        elseif ($the_cow['status'] === 'sold') $errors[] = 'This cow is already marked as sold.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO cow_family_transfers (farm_id,cow_id,transfer_type,recipient_name,estimated_value,transfer_date,notes,recorded_by)
                          VALUES (?,?,?,?,?,?,?,?)")
               ->execute([fid(), $cow_id, $tr_type, $recip ?: null, $est_val, $tr_date, $notes ?: null, $uid]);
            $tr_id = (int)$db->lastInsertId();

            if ($mark_sold) {
                $db->prepare("UPDATE cows SET status='sold' WHERE id = ? AND " . farmFilter())->execute([$cow_id]);
            }

            if ($est_val > 0) {
                $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([fid(), 'expense', 'Family Transfer', $est_val, 'cows', $tr_id, $tr_date, $uid,
                             ($type_labels[$tr_type] ?? $tr_type) . " — Cow #{$the_cow['tag_number']}"]);
            }

            auditLog($uid, 'FAMILY_TRANSFER', 'cows', $cow_id, ['status' => $the_cow['status']], ['transfer_type' => $tr_type]);
            $db->commit();
            flashMessage('success', "Transfer recorded for Cow #{$the_cow['tag_number']}.");
            redirect('/modules/cows/family_transfer.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[family_transfer] ' . $e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$cows = $db->prepare("SELECT id, tag_number, breed, status FROM cows WHERE " . farmFilter() . " AND status NOT IN ('sold','deceased') ORDER BY tag_number");
$cows->execute();
$cows = $cows->fetchAll();

$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;
$transfers = $db->prepare("SELECT cft.*, c.tag_number, u.name AS recorder FROM cow_family_transfers cft JOIN cows c ON c.id=cft.cow_id LEFT JOIN users u ON u.id=cft.recorded_by WHERE cft.farm_id=? AND cft.transfer_date BETWEEN ? AND ? ORDER BY cft.transfer_date DESC");
$transfers->execute([fid(), $filter_from, $filter_to]);
$transfers = $transfers->fetchAll();

$page_title = 'Family Cow Transfer';
$active_nav = 'family_transfer';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Family Cow Transfer</h1><p class="page-subtitle">Record cows given as gifts, family use, charity, or sacrifice</p></div>
    <a href="/modules/cows/index.php" class="btn btn-secondary">← All Cows</a>
</div>

<?php if (!empty($errors)): ?><div class="alert alert-danger" style="margin-bottom:1rem"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:1.5rem;align-items:start">
    <div class="card">
        <div class="card-header"><span class="card-title">Record Transfer</span></div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="form-label">Select Cow <span style="color:var(--danger)">*</span></label>
                    <select name="cow_id" class="form-control" required>
                        <option value="">— Choose cow —</option>
                        <?php foreach ($cows as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['tag_number']) ?> (<?= e($c['breed']) ?> — <?= ucfirst($c['status']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($cows)): ?><span class="form-hint" style="color:#f59e0b">No active cows available.</span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label">Transfer Type <span style="color:var(--danger)">*</span></label>
                    <select name="transfer_type" class="form-control" required>
                        <option value="">— Select type —</option>
                        <?php foreach ($type_labels as $v => $l): ?>
                        <option value="<?= $v ?>"><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Recipient / Family Member</label>
                    <input type="text" name="recipient_name" class="form-control" placeholder="e.g. Brother, Father" maxlength="150">
                </div>
                <div class="form-group">
                    <label class="form-label">Estimated Value (BDT)</label>
                    <input type="number" name="estimated_value" class="form-control" step="0.01" min="0" placeholder="0.00">
                    <span class="form-hint">Recorded as internal expense for accounting</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Transfer Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="transfer_date" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional details…"></textarea>
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:.5rem;background:#fef2f2;padding:.65rem .75rem;border-radius:8px">
                    <input type="checkbox" name="mark_transferred" id="mark_tr" value="1">
                    <label for="mark_tr" class="form-label" style="margin:0;color:#7f1d1d">Mark cow as <strong>Sold/Transferred</strong> (removes from active herd)</label>
                </div>
                <button type="submit" class="btn btn-primary">Record Transfer</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:.5rem">
            <span class="card-title">Transfer History</span>
            <form method="GET" style="display:flex;gap:.4rem;margin-left:auto">
                <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:125px">
                <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:125px">
                <button class="btn btn-secondary btn-sm">Filter</button>
            </form>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($transfers)): ?>
            <p style="text-align:center;padding:2rem;color:#9ca3af">No transfers recorded for this period.</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead><tr style="background:#f9fafb">
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Date</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Cow</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Type</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Recipient</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Value</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($transfers as $t): ?>
                <tr style="border-bottom:1px solid #f3f4f6">
                    <td style="padding:.5rem .75rem"><?= e(formatDate($t['transfer_date'])) ?></td>
                    <td style="padding:.5rem .75rem;font-weight:600">#<?= e($t['tag_number']) ?></td>
                    <td style="padding:.5rem .75rem"><?= e($type_labels[$t['transfer_type']] ?? $t['transfer_type']) ?></td>
                    <td style="padding:.5rem .75rem;color:#6b7280"><?= e($t['recipient_name'] ?? '—') ?></td>
                    <td style="padding:.5rem .75rem;text-align:right"><?= $t['estimated_value'] > 0 ? number_format($t['estimated_value'], 0) . ' BDT' : '—' ?></td>
                    <td style="padding:.5rem .75rem">
                        <a href="/modules/cows/view.php?id=<?= $t['cow_id'] ?>" class="btn btn-secondary btn-sm">View</a>
                        <?php if (hasRole(['admin','manager'])): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this transfer record?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="tr_id" value="<?= $t['id'] ?>">
                            <button class="btn btn-danger btn-sm">Del</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
