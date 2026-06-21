<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireAuth();
requireFarmScope();
requireModule('milk');

// Accountants need to view pricing for revenue reporting; only admins can write
if (!hasRole(['admin', 'manager', 'accountant'])) {
    flashMessage('error', 'You do not have permission to view milk pricing.');
    redirect('/modules/milk/index.php');
}

$page_title = 'Milk Pricing';
$active_nav = 'milk_pricing';
$db  = getDB();
$fid = fid();
$uid = (int)currentUser()['id'];

// ── POST HANDLER ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hasPermission('milk.pricing')) {
        flashMessage('error', 'Only farm admins can manage milk pricing.');
        redirect('/modules/milk/pricing.php');
    }
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request. Please try again.');
        redirect('/modules/milk/pricing.php');
    }

    requireNotBlocked();

    $action = $_POST['action'] ?? '';

    // ── Add new price ──────────────────────────────────────────────────────────
    if ($action === 'add_price') {
        $price_raw      = trim($_POST['price_per_liter'] ?? '');
        $effective_date = trim($_POST['effective_date']  ?? '');
        $note           = trim(substr($_POST['note'] ?? '', 0, 200));

        $errors = [];
        if ($price_raw === '' || !is_numeric($price_raw))  $errors[] = 'Price must be a number.';
        if ((float)$price_raw <= 0)                        $errors[] = 'Price must be greater than zero.';
        if ((float)$price_raw > 9999.99)                   $errors[] = 'Price seems unrealistically high. Please check.';
        if ($effective_date === '' || !strtotime($effective_date)) $errors[] = 'A valid effective date is required.';

        if (empty($errors)) {
            // Duplicate guard: one price per farm per effective date
            $dup = $db->prepare(
                "SELECT id FROM milk_price_history WHERE farm_id=? AND effective_date=? LIMIT 1"
            );
            $dup->execute([$fid, $effective_date]);
            if ($dup->fetch()) {
                $errors[] = 'A price already exists for ' . htmlspecialchars($effective_date) . '. Delete it first or choose a different date.';
            }
        }

        if (!empty($errors)) {
            flashMessage('error', implode(' ', $errors));
            redirect('/modules/milk/pricing.php');
        }

        $price = round((float)$price_raw, 2);
        $db->prepare(
            "INSERT INTO milk_price_history (farm_id, price_per_liter, effective_date, created_at)
             VALUES (?, ?, ?, NOW())"
        )->execute([$fid, $price, $effective_date]);

        $inserted_id = (int)$db->lastInsertId();
        auditLog($uid, 'MILK_PRICE_SET', 'milk_price_history', $inserted_id, null, [
            'price'          => $price,
            'effective_date' => $effective_date,
        ]);

        flashMessage('success', 'Milk price set to ৳' . number_format($price, 2) . '/L effective ' . $effective_date . '.');
        redirect('/modules/milk/pricing.php');
    }

    // ── Delete price entry ─────────────────────────────────────────────────────
    if ($action === 'delete_price') {
        $pid = (int)($_POST['price_id'] ?? 0);
        if ($pid > 0) {
            $row = $db->prepare(
                "SELECT id, price_per_liter, effective_date FROM milk_price_history WHERE id=? AND farm_id=?"
            );
            $row->execute([$pid, $fid]);
            $old = $row->fetch();
            if ($old) {
                $db->prepare("DELETE FROM milk_price_history WHERE id=? AND farm_id=?")->execute([$pid, $fid]);
                auditLog($uid, 'MILK_PRICE_DELETE', 'milk_price_history', $pid, $old, null);
                flashMessage('success', 'Price entry deleted.');
            }
        }
        redirect('/modules/milk/pricing.php');
    }

    redirect('/modules/milk/pricing.php');
}

// ── PAGE DATA ─────────────────────────────────────────────────────────────────

// Current effective price (most recent entry on or before today for this farm)
$current_row = $db->prepare(
    "SELECT id, price_per_liter, effective_date, created_at
     FROM milk_price_history
     WHERE farm_id=? AND effective_date <= CURDATE()
     ORDER BY effective_date DESC, id DESC
     LIMIT 1"
);
$current_row->execute([$fid]);
$current = $current_row->fetch();
$current_price = $current ? (float)$current['price_per_liter'] : 0.0;

// Next scheduled price (future dated, if any)
$next_row = $db->prepare(
    "SELECT price_per_liter, effective_date
     FROM milk_price_history
     WHERE farm_id=? AND effective_date > CURDATE()
     ORDER BY effective_date ASC
     LIMIT 1"
);
$next_row->execute([$fid]);
$next_price = $next_row->fetch();

// Price history
$history_stmt = $db->prepare(
    "SELECT id, price_per_liter, effective_date, created_at
     FROM milk_price_history
     WHERE farm_id=?
     ORDER BY effective_date DESC, id DESC
     LIMIT 30"
);
$history_stmt->execute([$fid]);
$history = $history_stmt->fetchAll();

// Revenue impact: total milk liters × current price (last 30 days)
$impact = $db->prepare(
    "SELECT COALESCE(SUM(liters),0) AS liters_30d,
            COALESCE(SUM(CASE WHEN DATE(recorded_at)=CURDATE() THEN liters END),0) AS liters_today,
            COALESCE(SUM(CASE WHEN MONTH(recorded_at)=MONTH(CURDATE()) AND YEAR(recorded_at)=YEAR(CURDATE()) THEN liters END),0) AS liters_month
     FROM milk_records
     WHERE " . farmFilter()
);
$impact->execute();
$impact = $impact->fetch();

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Milk Pricing</h2>
        <p class="text-sm text-muted">Set the price per liter — used automatically in revenue calculations across all milk reports</p>
    </div>
    <a href="/modules/milk/index.php" class="btn btn-secondary">← Milk Records</a>
</div>

<!-- Current price + impact KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(190px,1fr));margin-bottom:1.5rem">
    <div class="kpi-card" style="--kpi-color:<?= $current_price > 0 ? '#059669' : '#DC2626' ?>;--kpi-soft:<?= $current_price > 0 ? '#F0FDF4' : '#FEF2F2' ?>">
        <div class="kpi-value"><?= $current_price > 0 ? '৳' . number_format($current_price, 2) : '—' ?></div>
        <div class="kpi-label">Current Price / Liter</div>
        <div class="kpi-label" style="margin-top:-.15rem;font-size:.7rem">
            <?= $current ? 'Since ' . e($current['effective_date']) : 'No price set yet' ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-value">৳<?= number_format((float)$impact['liters_today'] * $current_price, 2) ?></div>
        <div class="kpi-label">Today's Revenue</div>
        <div class="kpi-label" style="margin-top:-.15rem;font-size:.7rem"><?= number_format((float)$impact['liters_today'], 1) ?> L × ৳<?= number_format($current_price, 2) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-value">৳<?= number_format((float)$impact['liters_month'] * $current_price, 2) ?></div>
        <div class="kpi-label">This Month's Revenue</div>
        <div class="kpi-label" style="margin-top:-.15rem;font-size:.7rem"><?= number_format((float)$impact['liters_month'], 1) ?> L × ৳<?= number_format($current_price, 2) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-value">৳<?= number_format((float)$impact['liters_30d'] * $current_price, 2) ?></div>
        <div class="kpi-label">Last 30 Days Revenue</div>
        <div class="kpi-label" style="margin-top:-.15rem;font-size:.7rem"><?= number_format((float)$impact['liters_30d'], 1) ?> L × ৳<?= number_format($current_price, 2) ?></div>
    </div>
</div>

<?php if ($next_price): ?>
<div class="alert" style="background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
        Scheduled price change: <strong>৳<?= number_format((float)$next_price['price_per_liter'], 2) ?>/L</strong>
        effective <strong><?= e($next_price['effective_date']) ?></strong>
    </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:1.5rem;align-items:start">

    <!-- Add New Price Form -->
    <?php if (hasPermission('milk.pricing')): ?>
    <div class="card">
        <div class="card-header"><span class="card-title">Set New Price</span></div>
        <div style="padding:1.25rem">
            <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.82rem;color:#166534">
                New prices take effect on the date you set. Historical milk records use the price
                that was active <strong>on the date they were recorded</strong>.
            </div>

            <form method="POST" action="/modules/milk/pricing.php" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_price">

                <div class="form-group">
                    <label class="form-label">Price Per Liter (৳) <span style="color:#DC2626">*</span></label>
                    <div class="input-group">
                        <span style="display:flex;align-items:center;padding:0 .75rem;background:var(--bg-muted);border:1px solid var(--border);border-right:none;border-radius:var(--radius) 0 0 var(--radius);font-weight:600;color:#374151">৳</span>
                        <input type="number" name="price_per_liter" class="form-control" step="0.01" min="0.01" max="9999.99"
                               placeholder="0.00" required style="border-radius:0 var(--radius) var(--radius) 0">
                    </div>
                    <span class="text-xs text-muted">e.g. 55.00 for ৳55 per liter</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Effective Date <span style="color:#DC2626">*</span></label>
                    <input type="date" name="effective_date" class="form-control"
                           value="<?= date('Y-m-d') ?>" required>
                    <span class="text-xs text-muted">Revenue reports use this date to apply the correct price</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Note <span style="color:#9CA3AF;font-weight:400">(optional)</span></label>
                    <input type="text" name="note" class="form-control" maxlength="200"
                           placeholder="e.g. Market rate adjustment Q1">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Save Price</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body" style="padding:1.25rem;text-align:center;color:#6B7280">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto .5rem;display:block;color:#9CA3AF"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Only Farm Admins can set milk prices.
        </div>
    </div>
    <?php endif; ?>

    <!-- Price History -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Price History</span>
            <span class="text-sm text-muted">Last 30 entries</span>
        </div>
        <?php if (empty($history)): ?>
        <div style="padding:2rem;text-align:center;color:#9CA3AF">
            <div style="font-size:2rem;margin-bottom:.5rem">💰</div>
            No price history yet.
            <?= hasPermission('milk.pricing') ? 'Set your first milk price using the form on the left.' : '' ?>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table" style="margin:0">
            <thead>
                <tr>
                    <th>Effective Date</th>
                    <th>Price / Liter</th>
                    <th>Revenue Impact</th>
                    <th>Status</th>
                    <?php if (hasPermission('milk.pricing')): ?><th style="width:50px"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history as $i => $h):
                $is_current = ($current && $h['id'] === $current['id']);
                $is_future  = $h['effective_date'] > date('Y-m-d');

                // Revenue impact: milk liters on that specific effective date period
                // (from this price's date until the next price's date)
                $next_date_for_row = null;
                if (isset($history[$i - 1])) {
                    $next_date_for_row = $history[$i - 1]['effective_date'];
                }
                $impact_stmt = $db->prepare(
                    "SELECT COALESCE(SUM(liters),0) AS liters
                     FROM milk_records
                     WHERE " . farmFilter() . "
                     AND DATE(recorded_at) >= ?
                     " . ($next_date_for_row ? "AND DATE(recorded_at) < ?" : "")
                );
                $impact_params = $next_date_for_row
                    ? [$h['effective_date'], $next_date_for_row]
                    : [$h['effective_date']];
                $impact_stmt->execute($impact_params);
                $row_liters   = (float)$impact_stmt->fetchColumn();
                $row_revenue  = $row_liters * (float)$h['price_per_liter'];
            ?>
            <tr style="<?= $is_current ? 'background:#F0FDF4;' : ($is_future ? 'background:#EFF6FF;' : '') ?>">
                <td>
                    <strong><?= e($h['effective_date']) ?></strong>
                    <div class="text-xs text-muted">Added <?= e(date('d M Y', strtotime($h['created_at']))) ?></div>
                </td>
                <td style="font-weight:700;font-size:1rem;color:#059669">
                    ৳<?= number_format((float)$h['price_per_liter'], 2) ?>/L
                </td>
                <td class="text-xs text-muted">
                    <?php if ($row_liters > 0): ?>
                    <?= number_format($row_liters, 1) ?> L → <strong>৳<?= number_format($row_revenue, 2) ?></strong>
                    <?php else: ?>
                    <?= $is_future ? 'Future' : 'No records' ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($is_current): ?>
                    <span class="badge badge-green">Active</span>
                    <?php elseif ($is_future): ?>
                    <span class="badge badge-blue">Scheduled</span>
                    <?php else: ?>
                    <span class="badge badge-gray">Past</span>
                    <?php endif; ?>
                </td>
                <?php if (hasPermission('milk.pricing')): ?>
                <td>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Delete this price entry? Historical revenue calculations using this price will show ৳0.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"   value="delete_price">
                        <input type="hidden" name="price_id" value="<?= $h['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete"
                                <?= $is_current ? 'style="opacity:.6" title="Deleting the active price will reset revenue to ৳0"' : '' ?>>
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                        </button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
