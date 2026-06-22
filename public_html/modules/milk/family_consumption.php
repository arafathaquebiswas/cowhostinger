<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireModule('milk');
requireRole(['admin', 'manager', 'accountant', 'milkman']);

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/milk/family_consumption.php');
    }
    $action = $_POST['action'] ?? 'add';
    if ($action === 'delete') {
        $db->prepare("DELETE FROM family_consumption WHERE id = ? AND farm_id = ? AND item_type = 'milk'")->execute([(int)$_POST['fc_id'], fid()]);
        flashMessage('success', 'Record deleted.');
        redirect('/modules/milk/family_consumption.php');
    }
    $qty   = (float)($_POST['quantity'] ?? 0);
    $val   = (float)($_POST['estimated_value'] ?? 0);
    $date  = trim($_POST['consumption_date'] ?? '');
    $notes = sanitize($_POST['notes'] ?? '');
    if ($qty <= 0)   $errors[] = 'Quantity must be greater than zero.';
    if ($date === '') $errors[] = 'Date is required.';
    if (empty($errors)) {
        $db->prepare("INSERT INTO family_consumption (farm_id,item_type,item_name,quantity,unit,estimated_value,consumption_date,notes,recorded_by)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([fid(), 'milk', 'Milk (Family)', $qty, 'litre', $val, $date, $notes ?: null, $uid]);
        flashMessage('success', "Family milk consumption of {$qty}L recorded.");
        redirect('/modules/milk/family_consumption.php');
    }
}

$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;
$records = $db->prepare("SELECT fc.*, u.name AS recorder FROM family_consumption fc LEFT JOIN users u ON u.id=fc.recorded_by WHERE fc.farm_id=? AND fc.item_type='milk' AND fc.consumption_date BETWEEN ? AND ? ORDER BY fc.consumption_date DESC");
$records->execute([fid(), $filter_from, $filter_to]);
$records = $records->fetchAll();
$totals = $db->prepare("SELECT COALESCE(SUM(quantity),0) AS total_liters, COALESCE(SUM(estimated_value),0) AS total_value FROM family_consumption WHERE farm_id=? AND item_type='milk' AND consumption_date BETWEEN ? AND ?");
$totals->execute([fid(), $filter_from, $filter_to]);
$totals = $totals->fetch();

$page_title = 'Family Milk Consumption';
$active_nav = 'family_milk';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<div class="page-header">
    <div><h1 class="page-title">Family Milk Consumption</h1><p class="page-subtitle">Track milk used by family (not sold)</p></div>
    <a href="/modules/milk/sales.php" class="btn btn-secondary">← Milk Sales</a>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:1.5rem;align-items:start">
    <div class="card">
        <div class="card-header"><span class="card-title">Record Consumption</span></div>
        <div class="card-body">
            <?php if (!empty($errors)): ?><div class="alert alert-danger" style="margin-bottom:1rem"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div><?php endif; ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="form-label">Quantity (Litres) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="quantity" class="form-control" step="0.1" min="0.1" placeholder="e.g. 2.5" value="<?= e($_POST['quantity'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Estimated Value (BDT)</label>
                    <input type="number" name="estimated_value" class="form-control" step="0.01" min="0" placeholder="Optional" value="<?= e($_POST['estimated_value'] ?? '') ?>">
                    <span class="form-hint">For accounting purposes only — not counted as revenue</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="consumption_date" class="form-control" value="<?= e($_POST['consumption_date'] ?? $today) ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Morning milk for household"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Record</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="flex-wrap:wrap;gap:.5rem">
            <span class="card-title">Consumption History</span>
            <div style="margin-left:auto;display:flex;gap:.5rem;align-items:center">
                <span style="font-size:.82rem;color:#6b7280"><?= number_format($totals['total_liters'],1) ?>L / <?= number_format($totals['total_value'],0) ?> BDT (this period)</span>
                <form method="GET" style="display:flex;gap:.4rem">
                    <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:125px">
                    <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:125px">
                    <button class="btn btn-secondary btn-sm">Filter</button>
                </form>
            </div>
        </div>
        <div class="card-body" style="padding:0">
            <?php if (empty($records)): ?>
            <p style="text-align:center;padding:2rem;color:#9ca3af">No records found.</p>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                <thead><tr style="background:#f9fafb">
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb;text-align:left">Date</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb;text-align:right">Qty (L)</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb;text-align:right">Est. Value</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb">Notes</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($records as $r): ?>
                <tr style="border-bottom:1px solid #f3f4f6">
                    <td style="padding:.5rem .75rem"><?= e(formatDate($r['consumption_date'])) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right;font-weight:600"><?= number_format($r['quantity'],1) ?></td>
                    <td style="padding:.5rem .75rem;text-align:right"><?= $r['estimated_value'] > 0 ? number_format($r['estimated_value'],2) : '—' ?></td>
                    <td style="padding:.5rem .75rem;color:#6b7280"><?= e($r['notes'] ?? '—') ?></td>
                    <td style="padding:.5rem .75rem">
                        <?php if (hasRole(['admin','manager'])): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this record?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="fc_id" value="<?= $r['id'] ?>">
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
