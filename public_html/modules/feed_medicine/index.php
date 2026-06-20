<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();
requireModule('feed_medicine');

$page_title = 'Feed & Medicine';
$active_nav = 'feed_medicine';
$db = getDB();

$active_tab = in_array($_GET['tab'] ?? '', ['feed', 'medicine'], true) ? $_GET['tab'] : 'feed';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect("/modules/feed_medicine/index.php?tab={$active_tab}");
    }

    $action  = $_POST['action'] ?? '';
    $user_id = (int)$_SESSION['user_id'];

    // ── Adjust feed stock ──────────────────────────────────────────
    if ($action === 'adjust_feed' && hasRole(['admin', 'worker'])) {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $delta   = (float)($_POST['qty_delta'] ?? 0);

        if ($item_id > 0 && $delta != 0) {
            $sel = $db->prepare("SELECT id, item_name, quantity FROM feed_inventory WHERE id = ?");
            $sel->execute([$item_id]);
            $item = $sel->fetch();
            if ($item) {
                $new_qty = round((float)$item['quantity'] + $delta, 2);
                if ($new_qty < 0) {
                    flashMessage('error', "Cannot reduce stock below 0. Current: {$item['quantity']}");
                } else {
                    $db->prepare("UPDATE feed_inventory SET quantity = ? WHERE id = ?")->execute([$new_qty, $item_id]);
                    auditLog($user_id, 'ADJUST_FEED_STOCK', 'feed_inventory', $item_id,
                        ['quantity' => $item['quantity']], ['quantity' => $new_qty, 'delta' => $delta]);
                    $sign = $delta > 0 ? '+' : '';
                    flashMessage('success', "{$item['item_name']}: stock adjusted {$sign}{$delta} → {$new_qty}");
                }
            }
        }
        redirect('/modules/feed_medicine/index.php?tab=feed');
    }

    // ── Adjust medicine stock ──────────────────────────────────────
    if ($action === 'adjust_medicine' && hasRole(['admin', 'veterinarian'])) {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $delta   = (float)($_POST['qty_delta'] ?? 0);

        if ($item_id > 0 && $delta != 0) {
            $sel = $db->prepare("SELECT id, item_name, quantity FROM medicine_inventory WHERE id = ?");
            $sel->execute([$item_id]);
            $item = $sel->fetch();
            if ($item) {
                $new_qty = round((float)$item['quantity'] + $delta, 2);
                if ($new_qty < 0) {
                    flashMessage('error', "Cannot reduce stock below 0. Current: {$item['quantity']}");
                } else {
                    $db->prepare("UPDATE medicine_inventory SET quantity = ? WHERE id = ?")->execute([$new_qty, $item_id]);
                    auditLog($user_id, 'ADJUST_MEDICINE_STOCK', 'medicine_inventory', $item_id,
                        ['quantity' => $item['quantity']], ['quantity' => $new_qty, 'delta' => $delta]);
                    $sign = $delta > 0 ? '+' : '';
                    flashMessage('success', "{$item['item_name']}: stock adjusted {$sign}{$delta} → {$new_qty}");
                }
            }
        }
        redirect('/modules/feed_medicine/index.php?tab=medicine');
    }

    // ── Delete feed item ───────────────────────────────────────────
    if ($action === 'delete_feed' && hasRole(['admin'])) {
        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $sel = $db->prepare("SELECT id, item_name FROM feed_inventory WHERE id = ?");
            $sel->execute([$item_id]);
            $item = $sel->fetch();
            if ($item) {
                $db->prepare("DELETE FROM feed_inventory WHERE id = ?")->execute([$item_id]);
                auditLog($user_id, 'DELETE_FEED_ITEM', 'feed_inventory', $item_id, $item, null);
                flashMessage('success', "Feed item '{$item['item_name']}' deleted.");
            }
        }
        redirect('/modules/feed_medicine/index.php?tab=feed');
    }

    // ── Delete medicine item ───────────────────────────────────────
    if ($action === 'delete_medicine' && hasRole(['admin'])) {
        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($item_id > 0) {
            $sel = $db->prepare("SELECT id, item_name FROM medicine_inventory WHERE id = ?");
            $sel->execute([$item_id]);
            $item = $sel->fetch();
            if ($item) {
                try {
                    $db->prepare("DELETE FROM medicine_inventory WHERE id = ?")->execute([$item_id]);
                    auditLog($user_id, 'DELETE_MEDICINE_ITEM', 'medicine_inventory', $item_id, $item, null);
                    flashMessage('success', "Medicine '{$item['item_name']}' deleted.");
                } catch (PDOException $e) {
                    flashMessage('error', "Cannot delete '{$item['item_name']}' — it is referenced in treatment records.");
                }
            }
        }
        redirect('/modules/feed_medicine/index.php?tab=medicine');
    }

    redirect("/modules/feed_medicine/index.php?tab={$active_tab}");
}

// ── Load data ─────────────────────────────────────────────────────

$feed_items = $db->query(
    "SELECT id, item_name, quantity, unit, reorder_threshold, last_updated
     FROM feed_inventory ORDER BY item_name ASC"
)->fetchAll();

$medicine_items = $db->query(
    "SELECT id, item_name, quantity, unit, expiry_date, reorder_threshold, last_updated,
            DATEDIFF(expiry_date, CURDATE()) AS days_to_expiry
     FROM medicine_inventory ORDER BY item_name ASC"
)->fetchAll();

// KPI counts
$low_feed     = count(array_filter($feed_items, fn($r) => (float)$r['quantity'] <= (float)$r['reorder_threshold'] && (float)$r['reorder_threshold'] > 0));
$low_med      = count(array_filter($medicine_items, fn($r) => (float)$r['quantity'] <= (float)$r['reorder_threshold'] && (float)$r['reorder_threshold'] > 0));
$expiring_med = count(array_filter($medicine_items, fn($r) => $r['expiry_date'] && $r['days_to_expiry'] >= 0 && $r['days_to_expiry'] <= 30));
$expired_med  = count(array_filter($medicine_items, fn($r) => $r['expiry_date'] && $r['days_to_expiry'] < 0));

// Helper: determine stock status badge
function feed_stock_badge(array $item): string {
    if ((float)$item['reorder_threshold'] > 0 && (float)$item['quantity'] <= (float)$item['reorder_threshold']) {
        return (float)$item['quantity'] <= 0 ? '<span class="badge badge-red">Out of Stock</span>' : '<span class="badge badge-yellow">Low Stock</span>';
    }
    return '';
}

function med_status_badges(array $item): string {
    $out = '';
    if ((float)$item['reorder_threshold'] > 0 && (float)$item['quantity'] <= (float)$item['reorder_threshold']) {
        $out .= (float)$item['quantity'] <= 0 ? '<span class="badge badge-red">Out of Stock</span> ' : '<span class="badge badge-yellow">Low Stock</span> ';
    }
    if ($item['expiry_date']) {
        $d = (int)$item['days_to_expiry'];
        if ($d < 0)      $out .= '<span class="badge badge-red">Expired</span>';
        elseif ($d <= 7) $out .= '<span class="badge badge-red">Expires in ' . $d . 'd</span>';
        elseif ($d <= 30)$out .= '<span class="badge badge-yellow">Expiring ' . $d . 'd</span>';
    }
    return $out;
}

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Feed &amp; Medicine</h2>
        <p class="text-sm text-muted">
            <?= count($feed_items) ?> feed types &nbsp;·&nbsp; <?= count($medicine_items) ?> medicines
        </p>
    </div>
    <?php if (hasRole(['admin'])): ?>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/feed_medicine/feed_form.php" class="btn btn-secondary btn-sm">+ Add Feed</a>
        <a href="/modules/feed_medicine/medicine_form.php" class="btn btn-primary btn-sm">+ Add Medicine</a>
    </div>
    <?php endif; ?>
</div>

<!-- KPI bar -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:<?= $low_feed > 0 ? '#D97706' : '#16A34A' ?>;--kpi-soft:<?= $low_feed > 0 ? '#FFFBEB' : '#F0FDF4' ?>">
        <div class="kpi-label">Feed Types</div>
        <div class="kpi-value"><?= count($feed_items) ?></div>
        <?php if ($low_feed > 0): ?>
        <div class="kpi-label" style="color:var(--warning)"><?= $low_feed ?> low stock</div>
        <?php endif; ?>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $low_med > 0 || $expiring_med > 0 ? '#D97706' : '#16A34A' ?>;--kpi-soft:<?= $low_med > 0 || $expiring_med > 0 ? '#FFFBEB' : '#F0FDF4' ?>">
        <div class="kpi-label">Medicine Types</div>
        <div class="kpi-value"><?= count($medicine_items) ?></div>
        <?php if ($low_med > 0): ?>
        <div class="kpi-label" style="color:var(--warning)"><?= $low_med ?> low stock</div>
        <?php endif; ?>
    </div>
    <?php if ($expiring_med > 0): ?>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-label">Expiring ≤ 30 days</div>
        <div class="kpi-value"><?= $expiring_med ?></div>
    </div>
    <?php endif; ?>
    <?php if ($expired_med > 0): ?>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">Expired</div>
        <div class="kpi-value"><?= $expired_med ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Tabs -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="tab-nav" id="invTabNav">
        <button class="tab-btn <?= $active_tab === 'feed'     ? 'active' : '' ?>" data-tab="feed">
            Feed Inventory
            <?php if ($low_feed > 0): ?><span class="badge badge-yellow" style="font-size:.7rem"><?= $low_feed ?></span><?php endif; ?>
        </button>
        <button class="tab-btn <?= $active_tab === 'medicine' ? 'active' : '' ?>" data-tab="medicine">
            Medicine Inventory
            <?php if ($expired_med + $expiring_med + $low_med > 0): ?>
            <span class="badge badge-red" style="font-size:.7rem"><?= $expired_med + $expiring_med + $low_med ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- TAB: Feed -->
    <div id="tab_feed" class="tab-panel <?= $active_tab === 'feed' ? 'active' : '' ?>">
        <?php if (empty($feed_items)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 11h18M3 6h18M3 16h18"/></svg>
            <h3>No feed items</h3>
            <p><?= hasRole(['admin']) ? '<a href="/modules/feed_medicine/feed_form.php">Add the first feed item</a>' : 'No feed inventory records yet.' ?></p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Quantity</th>
                    <th>Threshold</th>
                    <th>Last Updated</th>
                    <?php if (hasRole(['admin','worker'])): ?><th style="min-width:200px">Adjust Stock</th><?php endif; ?>
                    <?php if (hasRole(['admin'])): ?><th style="width:90px">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($feed_items as $item): ?>
            <tr>
                <td>
                    <div style="font-weight:600"><?= e($item['item_name']) ?></div>
                    <?= feed_stock_badge($item) ?>
                </td>
                <td><?= e(number_format((float)$item['quantity'], 2)) ?> <?= e($item['unit']) ?></td>
                <td><?= (float)$item['reorder_threshold'] > 0 ? e(number_format((float)$item['reorder_threshold'], 2)) . ' ' . e($item['unit']) : '—' ?></td>
                <td style="font-size:.82rem"><?= e(formatDateTime($item['last_updated'])) ?></td>
                <?php if (hasRole(['admin','worker'])): ?>
                <td>
                    <form method="POST" action="/modules/feed_medicine/index.php"
                          style="display:flex;gap:.35rem;align-items:center">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="adjust_feed">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <input type="number" name="qty_delta" class="form-control"
                               style="width:100px;padding:.3rem .5rem"
                               step="0.01" placeholder="+/- qty" required>
                        <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
                    </form>
                </td>
                <?php endif; ?>
                <?php if (hasRole(['admin'])): ?>
                <td>
                    <div style="display:flex;gap:.35rem">
                        <a href="/modules/feed_medicine/feed_form.php?id=<?= $item['id'] ?>"
                           class="btn btn-sm btn-secondary" title="Edit">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Delete <?= e(addslashes($item['item_name'])) ?>?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="delete_feed">
                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- TAB: Medicine -->
    <div id="tab_medicine" class="tab-panel <?= $active_tab === 'medicine' ? 'active' : '' ?>">
        <?php if (empty($medicine_items)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0016.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 002 8.5c0 2.3 1.5 4.05 3 5.5l7 7z"/></svg>
            <h3>No medicine items</h3>
            <p><?= hasRole(['admin']) ? '<a href="/modules/feed_medicine/medicine_form.php">Add the first medicine</a>' : 'No medicine inventory records yet.' ?></p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Medicine Name</th>
                    <th>Quantity</th>
                    <th>Expiry Date</th>
                    <th>Threshold</th>
                    <th>Last Updated</th>
                    <?php if (hasRole(['admin','veterinarian'])): ?><th style="min-width:200px">Adjust Stock</th><?php endif; ?>
                    <?php if (hasRole(['admin'])): ?><th style="width:90px">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($medicine_items as $item): ?>
            <?php
                $row_style = '';
                if ($item['expiry_date'] && (int)$item['days_to_expiry'] < 0) $row_style = 'background:#FEF2F2';
                elseif ($item['expiry_date'] && (int)$item['days_to_expiry'] <= 7) $row_style = 'background:#FFF8F0';
            ?>
            <tr style="<?= $row_style ?>">
                <td>
                    <div style="font-weight:600"><?= e($item['item_name']) ?></div>
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.2rem">
                        <?= med_status_badges($item) ?>
                    </div>
                </td>
                <td><?= e(number_format((float)$item['quantity'], 2)) ?> <?= e($item['unit']) ?></td>
                <td>
                    <?php if ($item['expiry_date']): ?>
                    <?= e(formatDate($item['expiry_date'])) ?>
                    <span class="text-muted" style="font-size:.78rem">
                        (<?= (int)$item['days_to_expiry'] >= 0 ? (int)$item['days_to_expiry'] . 'd left' : abs((int)$item['days_to_expiry']) . 'd ago' ?>)
                    </span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= (float)$item['reorder_threshold'] > 0 ? e(number_format((float)$item['reorder_threshold'], 2)) . ' ' . e($item['unit']) : '—' ?></td>
                <td style="font-size:.82rem"><?= e(formatDateTime($item['last_updated'])) ?></td>
                <?php if (hasRole(['admin','veterinarian'])): ?>
                <td>
                    <form method="POST" action="/modules/feed_medicine/index.php"
                          style="display:flex;gap:.35rem;align-items:center">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="adjust_medicine">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        <input type="number" name="qty_delta" class="form-control"
                               style="width:100px;padding:.3rem .5rem"
                               step="0.01" placeholder="+/- qty" required>
                        <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
                    </form>
                </td>
                <?php endif; ?>
                <?php if (hasRole(['admin'])): ?>
                <td>
                    <div style="display:flex;gap:.35rem">
                        <a href="/modules/feed_medicine/medicine_form.php?id=<?= $item['id'] ?>"
                           class="btn btn-sm btn-secondary" title="Edit">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Delete <?= e(addslashes($item['item_name'])) ?>?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="delete_medicine">
                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                            </button>
                        </form>
                    </div>
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

<?php
$inline_js = <<<'JS'
(function() {
    var btns   = document.querySelectorAll('#invTabNav .tab-btn');
    var panels = document.querySelectorAll('.tab-panel');
    btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var t = this.dataset.tab;
            btns.forEach(function(b)   { b.classList.remove('active'); });
            panels.forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            document.getElementById('tab_' + t).classList.add('active');
        });
    });
})();
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
