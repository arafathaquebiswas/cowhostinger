<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant', 'veterinarian', 'worker']);
requireFarmScope();
requireNotBlocked();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Inline migration ──────────────────────────────────────────────────────────
(function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `waste_records` (
      `id`                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
      `farm_id`                 INT UNSIGNED  NOT NULL,
      `waste_date`              DATE          NOT NULL,
      `category`                ENUM('milk','feed','medicine','animal','equipment','other') NOT NULL DEFAULT 'other',
      `item_name`               VARCHAR(200)  NOT NULL,
      `quantity`                DECIMAL(10,3) DEFAULT NULL,
      `unit`                    VARCHAR(50)   DEFAULT NULL,
      `unit_price`              DECIMAL(12,2) DEFAULT NULL,
      `total_loss`              DECIMAL(12,2) NOT NULL,
      `reason`                  TEXT          DEFAULT NULL,
      `finance_transaction_id`  INT UNSIGNED  DEFAULT NULL,
      `recorded_by`             INT UNSIGNED  NOT NULL,
      `created_at`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_farm_date` (`farm_id`, `waste_date`),
      KEY `idx_category`  (`farm_id`, `category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
})($db);

$errors     = [];
$today      = date('Y-m-d');
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? $today;
$filter_cat  = $_GET['cat']  ?? '';

$cat_meta = [
    'milk'      => ['label' => 'Milk Waste',      'icon' => '🥛', 'color' => '#3b82f6'],
    'feed'      => ['label' => 'Feed Waste',       'icon' => '🌾', 'color' => '#d97706'],
    'medicine'  => ['label' => 'Medicine Waste',   'icon' => '💊', 'color' => '#8b5cf6'],
    'animal'    => ['label' => 'Animal Waste',     'icon' => '🐄', 'color' => '#dc2626'],
    'equipment' => ['label' => 'Equipment Damage', 'icon' => '⚙️', 'color' => '#6b7280'],
    'other'     => ['label' => 'Other Loss',       'icon' => '💸', 'color' => '#f97316'],
];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/waste/index.php');
    }

    $action = $_POST['action'] ?? 'add';

    // ── Delete ────────────────────────────────────────────────────────────────
    if ($action === 'delete' && hasRole(['admin', 'manager', 'accountant'])) {
        $del_id = (int)($_POST['waste_id'] ?? 0);
        $sel    = $db->prepare("SELECT * FROM waste_records WHERE id=? AND farm_id=?");
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
                flashMessage('success', 'Waste record deleted.');
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[waste_delete] ' . $e->getMessage());
                flashMessage('error', 'Delete failed. Please try again.');
            }
        }
        redirect('/modules/waste/index.php');
    }

    // ── Add ───────────────────────────────────────────────────────────────────
    $cat        = in_array($_POST['category'] ?? '', array_keys($cat_meta), true) ? $_POST['category'] : '';
    $item_name  = sanitize($_POST['item_name'] ?? '');
    $quantity   = trim($_POST['quantity'] ?? '') !== '' ? abs((float)$_POST['quantity']) : null;
    $unit       = sanitize($_POST['unit'] ?? '');
    $unit_price = trim($_POST['unit_price'] ?? '') !== '' ? abs((float)$_POST['unit_price']) : null;
    $total_loss = abs((float)($_POST['total_loss'] ?? 0));
    $reason     = sanitize($_POST['reason'] ?? '');
    $waste_date = trim($_POST['waste_date'] ?? '');

    if ($cat === '')                               $errors[] = 'Waste category is required.';
    if ($item_name === '')                         $errors[] = 'Item / description is required.';
    if ($total_loss <= 0)                          $errors[] = 'Total loss amount must be greater than zero.';
    if ($waste_date === '')                        $errors[] = 'Date is required.';
    elseif (strtotime($waste_date) === false)      $errors[] = 'Invalid date.';
    elseif (strtotime($waste_date) > time() + 86400) $errors[] = 'Date cannot be in the future.';

    if (empty($errors)) {
        $ft_note = "[{$cat_meta[$cat]['label']}] {$item_name}" . ($reason ? " — {$reason}" : '');
        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO finance_transactions
                (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes)
                VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), 'expense', 'Waste Loss', $total_loss, 'waste', 0, $waste_date, $uid, $ft_note]);
            $ft_id = (int)$db->lastInsertId();

            $db->prepare("INSERT INTO waste_records
                (farm_id,waste_date,category,item_name,quantity,unit,unit_price,total_loss,reason,finance_transaction_id,recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $waste_date, $cat, $item_name, $quantity, $unit ?: null,
                          $unit_price, $total_loss, $reason ?: null, $ft_id, $uid]);
            $wr_id = (int)$db->lastInsertId();

            $db->prepare("UPDATE finance_transactions SET reference_id=? WHERE id=?")->execute([$wr_id, $ft_id]);
            auditLog($uid, 'CREATE_WASTE', 'waste_records', $wr_id, null, compact('cat', 'item_name', 'total_loss'));
            $db->commit();

            flashMessage('success', "Waste recorded — {$item_name}: ৳" . number_format($total_loss, 2) . " loss.");
            redirect('/modules/waste/index.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[waste_add] ' . $e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// ── Load records ──────────────────────────────────────────────────────────────
$w_parts = ["wr.farm_id = " . fid()];
$w_params = [];
if ($filter_cat !== '' && isset($cat_meta[$filter_cat])) {
    $w_parts[] = "wr.category = ?";
    $w_params[] = $filter_cat;
}
$w_parts[]  = "wr.waste_date BETWEEN ? AND ?";
$w_params[] = $filter_from;
$w_params[] = $filter_to;

$stmt = $db->prepare("SELECT wr.*, u.name AS recorder
    FROM waste_records wr LEFT JOIN users u ON u.id = wr.recorded_by
    WHERE " . implode(' AND ', $w_parts) . " ORDER BY wr.waste_date DESC, wr.id DESC");
$stmt->execute($w_params);
$records = $stmt->fetchAll();

$tq = $db->prepare("SELECT
    COALESCE(SUM(total_loss),0)                                             AS grand_total,
    COALESCE(SUM(CASE WHEN category='milk'      THEN total_loss END),0)     AS milk_total,
    COALESCE(SUM(CASE WHEN category='feed'      THEN total_loss END),0)     AS feed_total,
    COALESCE(SUM(CASE WHEN category='medicine'  THEN total_loss END),0)     AS med_total,
    COALESCE(SUM(CASE WHEN category='animal'    THEN total_loss END),0)     AS animal_total,
    COALESCE(SUM(CASE WHEN category='equipment' THEN total_loss END),0)     AS equip_total,
    COALESCE(SUM(CASE WHEN category='other'     THEN total_loss END),0)     AS other_total,
    COUNT(*)                                                                 AS entry_count
    FROM waste_records WHERE farm_id=? AND waste_date BETWEEN ? AND ?");
$tq->execute([fid(), $filter_from, $filter_to]);
$totals = $tq->fetch();

$page_title = 'Waste & Loss Tracker';
$active_nav = 'waste';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<style>
.cat-badge {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .18rem .55rem; border-radius: 999px;
    font-size: .72rem; font-weight: 700;
}
.waste-summary-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .75rem; margin-bottom: 1.5rem; }
.ws-card { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: .85rem 1rem; position: relative; overflow: hidden; }
.ws-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--ws-color, #dc2626); }
.ws-card .ws-val { font-size: 1.2rem; font-weight: 800; color: #111; margin-bottom: .15rem; }
.ws-card .ws-lbl { font-size: .72rem; color: #6b7280; }
.ws-card .ws-ico { position: absolute; top: .7rem; right: .85rem; font-size: 1.4rem; opacity: .14; }
.reason-cell { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #6b7280; font-size: .78rem; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">Waste &amp; Loss Tracker</h1>
        <p class="page-subtitle">Master view — all 6 waste categories combined. Use dedicated modules for detailed entry.</p>
    </div>
    <a href="/modules/reports/financial_report.php" class="btn btn-secondary">PDF Report</a>
</div>

<!-- ── Module Quick-Links ──────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.65rem;margin-bottom:1.5rem">
    <a href="/modules/waste/milk.php" style="display:flex;flex-direction:column;align-items:center;padding:.75rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;text-decoration:none;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 2px 8px rgba(59,130,246,.2)'" onmouseout="this.style.boxShadow=''">
        <span style="font-size:1.5rem">🥛</span>
        <span style="font-size:.78rem;font-weight:700;color:#1d4ed8;margin-top:.3rem">Milk Waste</span>
        <span style="font-size:.7rem;color:#3b82f6">৳<?= number_format($totals['milk_total'], 0) ?></span>
    </a>
    <a href="/modules/waste/feed.php" style="display:flex;flex-direction:column;align-items:center;padding:.75rem;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;text-decoration:none;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 2px 8px rgba(217,119,6,.2)'" onmouseout="this.style.boxShadow=''">
        <span style="font-size:1.5rem">🌾</span>
        <span style="font-size:.78rem;font-weight:700;color:#b45309;margin-top:.3rem">Feed Waste</span>
        <span style="font-size:.7rem;color:#d97706">৳<?= number_format($totals['feed_total'], 0) ?></span>
    </a>
    <a href="/modules/waste/medicine.php" style="display:flex;flex-direction:column;align-items:center;padding:.75rem;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;text-decoration:none;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 2px 8px rgba(139,92,246,.2)'" onmouseout="this.style.boxShadow=''">
        <span style="font-size:1.5rem">💊</span>
        <span style="font-size:.78rem;font-weight:700;color:#6d28d9;margin-top:.3rem">Medicine</span>
        <span style="font-size:.7rem;color:#8b5cf6">৳<?= number_format($totals['med_total'], 0) ?></span>
    </a>
    <a href="/modules/waste/animal.php" style="display:flex;flex-direction:column;align-items:center;padding:.75rem;background:#fff1f2;border:1px solid #fecdd3;border-radius:10px;text-decoration:none;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 2px 8px rgba(220,38,38,.2)'" onmouseout="this.style.boxShadow=''">
        <span style="font-size:1.5rem">🐄</span>
        <span style="font-size:.78rem;font-weight:700;color:#b91c1c;margin-top:.3rem">Animal Loss</span>
        <span style="font-size:.7rem;color:#dc2626">৳<?= number_format($totals['animal_total'], 0) ?></span>
    </a>
    <a href="/modules/waste/equipment.php" style="display:flex;flex-direction:column;align-items:center;padding:.75rem;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;text-decoration:none;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 2px 8px rgba(107,114,128,.2)'" onmouseout="this.style.boxShadow=''">
        <span style="font-size:1.5rem">⚙️</span>
        <span style="font-size:.78rem;font-weight:700;color:#374151;margin-top:.3rem">Equipment</span>
        <span style="font-size:.7rem;color:#6b7280">৳<?= number_format($totals['equip_total'], 0) ?></span>
    </a>
    <a href="/modules/waste/other.php" style="display:flex;flex-direction:column;align-items:center;padding:.75rem;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;text-decoration:none;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 2px 8px rgba(249,115,22,.2)'" onmouseout="this.style.boxShadow=''">
        <span style="font-size:1.5rem">💸</span>
        <span style="font-size:.78rem;font-weight:700;color:#c2410c;margin-top:.3rem">Other Loss</span>
        <span style="font-size:.7rem;color:#f97316">৳<?= number_format($totals['other_total'], 0) ?></span>
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem">
    <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Summary Cards ───────────────────────────────────────────────────────── -->
<div class="waste-summary-grid">
    <div class="ws-card" style="--ws-color:#dc2626">
        <div class="ws-ico">💸</div>
        <div class="ws-val">৳<?= number_format($totals['grand_total'], 0) ?></div>
        <div class="ws-lbl">Total Loss (Period)</div>
    </div>
    <div class="ws-card" style="--ws-color:#3b82f6">
        <div class="ws-ico">🥛</div>
        <div class="ws-val">৳<?= number_format($totals['milk_total'], 0) ?></div>
        <div class="ws-lbl">Milk Waste</div>
    </div>
    <div class="ws-card" style="--ws-color:#d97706">
        <div class="ws-ico">🌾</div>
        <div class="ws-val">৳<?= number_format($totals['feed_total'], 0) ?></div>
        <div class="ws-lbl">Feed Waste</div>
    </div>
    <div class="ws-card" style="--ws-color:#8b5cf6">
        <div class="ws-ico">💊</div>
        <div class="ws-val">৳<?= number_format($totals['med_total'], 0) ?></div>
        <div class="ws-lbl">Medicine Waste</div>
    </div>
    <div class="ws-card" style="--ws-color:#dc2626">
        <div class="ws-ico">🐄</div>
        <div class="ws-val">৳<?= number_format($totals['animal_total'], 0) ?></div>
        <div class="ws-lbl">Animal Waste</div>
    </div>
    <div class="ws-card" style="--ws-color:#6b7280">
        <div class="ws-ico">⚙️</div>
        <div class="ws-val">৳<?= number_format($totals['equip_total'], 0) ?></div>
        <div class="ws-lbl">Equipment Damage</div>
    </div>
</div>

<!-- ── Add Form ────────────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title">Record New Waste / Loss</span></div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:1rem">
                <div class="form-group">
                    <label class="form-label">Waste Category <span style="color:var(--danger)">*</span></label>
                    <select name="category" class="form-control" required id="wasteCat">
                        <option value="">— Select category —</option>
                        <?php foreach ($cat_meta as $k => $m): ?>
                        <option value="<?= $k ?>"><?= $m['icon'] ?> <?= e($m['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Item / Description <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="item_name" class="form-control" placeholder="e.g. Spoiled morning milk" maxlength="200" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Quantity</label>
                    <input type="number" name="quantity" id="qty" class="form-control" step="0.001" min="0" placeholder="e.g. 20" oninput="autoCalc()">
                </div>
                <div class="form-group">
                    <label class="form-label">Unit</label>
                    <input type="text" name="unit" id="unit_field" class="form-control" placeholder="L / kg / pcs" maxlength="50">
                </div>
                <div class="form-group">
                    <label class="form-label">Unit Price (BDT)</label>
                    <input type="number" name="unit_price" id="upr" class="form-control" step="0.01" min="0" placeholder="e.g. 65" oninput="autoCalc()">
                </div>
                <div class="form-group">
                    <label class="form-label">Total Loss (BDT) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="total_loss" id="total_loss" class="form-control" step="0.01" min="0.01" placeholder="e.g. 1300" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="waste_date" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Reason / Notes</label>
                    <input type="text" name="reason" class="form-control" placeholder="Brief explanation" maxlength="500">
                </div>
            </div>
            <div style="margin-top:.25rem;padding:.65rem .85rem;background:#fff1f2;border-radius:8px;border-left:3px solid #dc2626;font-size:.82rem;color:#7f1d1d">
                ⚠️ This loss will be automatically recorded as an expense and <strong>subtracted from your Net Profit</strong>.
            </div>
            <div style="margin-top:1rem;display:flex;gap:.75rem">
                <button type="submit" class="btn btn-danger">Record Loss</button>
                <a href="/modules/waste/index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Filter + Records Table ─────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:.75rem">
        <span class="card-title">Waste Records
            <?php if ($totals['entry_count'] > 0): ?>
            <span style="font-size:.8rem;font-weight:500;color:#6b7280;margin-left:.35rem">(<?= $totals['entry_count'] ?> entries — ৳<?= number_format($totals['grand_total'], 2) ?> total loss)</span>
            <?php endif; ?>
        </span>
        <form method="GET" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-left:auto">
            <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:130px">
            <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:130px">
            <select name="cat" class="form-control" style="width:150px">
                <option value="">All Categories</option>
                <?php foreach ($cat_meta as $k => $m): ?>
                <option value="<?= $k ?>" <?= $filter_cat === $k ? 'selected' : '' ?>><?= $m['icon'] ?> <?= e($m['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-secondary btn-sm">Filter</button>
        </form>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($records)): ?>
        <p style="text-align:center;padding:2.5rem;color:#9ca3af">No waste records found for the selected period.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.84rem">
            <thead>
                <tr style="background:#fafafa">
                    <th style="padding:.55rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Date</th>
                    <th style="padding:.55rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Category</th>
                    <th style="padding:.55rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Item</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Qty</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Unit Price</th>
                    <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Loss (BDT)</th>
                    <th style="padding:.55rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Reason</th>
                    <th style="padding:.55rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">By</th>
                    <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($records as $r):
                $cm = $cat_meta[$r['category']] ?? ['label' => ucfirst($r['category']), 'icon' => '📦', 'color' => '#6b7280'];
            ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem;white-space:nowrap"><?= date('d M Y', strtotime($r['waste_date'])) ?></td>
                <td style="padding:.5rem .75rem">
                    <span class="cat-badge" style="background:<?= $cm['color'] ?>22;color:<?= $cm['color'] ?>">
                        <?= $cm['icon'] ?> <?= e($cm['label']) ?>
                    </span>
                </td>
                <td style="padding:.5rem .75rem;font-weight:600"><?= e($r['item_name']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right">
                    <?= $r['quantity'] !== null ? number_format((float)$r['quantity'], 2) . ($r['unit'] ? ' ' . e($r['unit']) : '') : '—' ?>
                </td>
                <td style="padding:.5rem .75rem;text-align:right">
                    <?= $r['unit_price'] !== null ? '৳' . number_format((float)$r['unit_price'], 2) : '—' ?>
                </td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:#dc2626">
                    ৳<?= number_format((float)$r['total_loss'], 2) ?>
                </td>
                <td style="padding:.5rem .75rem"><div class="reason-cell" title="<?= e($r['reason'] ?? '') ?>"><?= e($r['reason'] ?? '—') ?></div></td>
                <td style="padding:.5rem .75rem;color:#6b7280;font-size:.78rem"><?= e($r['recorder'] ?? '—') ?></td>
                <td style="padding:.5rem .75rem">
                    <?php if (hasRole(['admin','manager','accountant'])): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this waste record? The linked finance entry will also be removed.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="waste_id" value="<?= $r['id'] ?>">
                        <button class="btn btn-danger btn-sm">Del</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#fafafa;font-weight:700">
                    <td colspan="5" style="padding:.55rem .75rem;font-size:.83rem;color:#374151">Total Loss for Period</td>
                    <td style="padding:.55rem .75rem;text-align:right;color:#dc2626;font-size:.95rem">৳<?= number_format($totals['grand_total'], 2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
// Category → auto-fill unit hint
document.getElementById('wasteCat').addEventListener('change', function() {
    var hints = {milk:'L', feed:'kg', medicine:'pcs', animal:'kg', equipment:'pcs', other:''};
    document.getElementById('unit_field').placeholder = hints[this.value] || 'unit';
});

function autoCalc() {
    var q = parseFloat(document.getElementById('qty').value) || 0;
    var p = parseFloat(document.getElementById('upr').value) || 0;
    if (q > 0 && p > 0) {
        document.getElementById('total_loss').value = (q * p).toFixed(2);
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
