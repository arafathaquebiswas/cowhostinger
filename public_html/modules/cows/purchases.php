<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireNotBlocked();

$page_title = 'Cow Purchases';
$active_nav = 'cows';
$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── POST: add purchase record ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/cows/purchases.php');
    }

    $action        = $_POST['action'] ?? '';
    $cow_id        = (int)($_POST['cow_id']        ?? 0);
    $seller_id     = (int)($_POST['seller_id']     ?? 0);
    $purchase_price = trim($_POST['purchase_price'] ?? '');
    $transport_cost = trim($_POST['transport_cost'] ?? '0');
    $purchase_date  = trim($_POST['purchase_date']  ?? '');
    $notes          = trim($_POST['notes']           ?? '');

    if ($action === 'add_purchase') {
        $errs = [];

        // Validate cow
        if ($cow_id <= 0) {
            $errs[] = 'Select a cow.';
        } else {
            $cv = $db->prepare("SELECT id FROM cows WHERE id=? AND " . farmFilter());
            $cv->execute([$cow_id]);
            if (!$cv->fetch()) $errs[] = 'Cow not found.';
        }

        $price = is_numeric($purchase_price) ? (float)$purchase_price : null;
        if ($price === null || $price <= 0) $errs[] = 'Purchase price must be a positive number.';
        $transport = is_numeric($transport_cost) ? (float)$transport_cost : 0;
        if ($transport < 0) $errs[] = 'Transport cost cannot be negative.';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchase_date)) $errs[] = 'Invalid purchase date.';
        if ($purchase_date > date('Y-m-d')) $errs[] = 'Purchase date cannot be in the future.';

        // Check no duplicate purchase for this cow
        $dup = $db->prepare("SELECT id FROM cow_purchases WHERE cow_id=? AND farm_id=? LIMIT 1");
        $dup->execute([$cow_id, fid()]);
        if ($dup->fetch()) $errs[] = 'A purchase record already exists for this cow.';

        if (!empty($errs)) {
            flashMessage('error', implode(' ', $errs));
        } else {
            $total = $price + $transport;
            $db->prepare(
                "INSERT INTO cow_purchases (farm_id,cow_id,seller_id,purchase_price,transport_cost,total_cost,purchase_date,notes,recorded_by)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                fid(), $cow_id, $seller_id > 0 ? $seller_id : null,
                $price, $transport, $total, $purchase_date,
                $notes !== '' ? $notes : null, $uid,
            ]);

            // Also update cows table for backwards compat if purchase_price exists there
            $db->prepare("UPDATE cows SET purchase_price=?, purchase_date=? WHERE id=? AND " . farmFilter())
               ->execute([$price, $purchase_date, $cow_id]);

            $pid = (int)$db->lastInsertId();
            auditLog($uid, 'CREATE_COW_PURCHASE', 'cow_purchases', $pid, null,
                ['cow_id' => $cow_id, 'price' => $price, 'date' => $purchase_date]);
            flashMessage('success', "Purchase record saved — ৳" . number_format($total, 2) . " total.");
            redirect('/modules/cows/purchases.php');
        }
    }

    if ($action === 'delete_purchase' && hasRole(['admin', 'manager'])) {
        $pid = (int)($_POST['purchase_id'] ?? 0);
        if ($pid > 0) {
            $db->prepare("DELETE FROM cow_purchases WHERE id=? AND farm_id=?")->execute([$pid, fid()]);
            auditLog($uid, 'DELETE_COW_PURCHASE', 'cow_purchases', $pid);
            flashMessage('success', 'Purchase record deleted.');
        }
        redirect('/modules/cows/purchases.php');
    }
}

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi = $db->prepare(
    "SELECT COUNT(*) AS total_purchases,
       COALESCE(SUM(purchase_price),0) AS total_price,
       COALESCE(SUM(total_cost),0)     AS total_cost,
       COALESCE(SUM(transport_cost),0) AS total_transport
     FROM cow_purchases WHERE farm_id=?"
);
$kpi->execute([fid()]);
$kpi = $kpi->fetch();

// ── Load data ─────────────────────────────────────────────────────────────────
$purchases = $db->prepare(
    "SELECT cp.*, c.tag_number, c.breed, c.status AS cow_status,
       s.name AS seller_name, s.phone AS seller_phone,
       u.name AS recorder
     FROM cow_purchases cp
     JOIN cows c ON c.id = cp.cow_id
     LEFT JOIN sellers s ON s.id = cp.seller_id
     LEFT JOIN users u ON u.id = cp.recorded_by
     WHERE cp.farm_id = ?
     ORDER BY cp.purchase_date DESC, cp.id DESC"
);
$purchases->execute([fid()]);
$purchases = $purchases->fetchAll();

// Cows without a purchase record
$cows_without = $db->prepare(
    "SELECT id, tag_number, breed, purchase_price, purchase_date FROM cows
     WHERE " . farmFilter() . "
       AND id NOT IN (SELECT cow_id FROM cow_purchases WHERE farm_id = ?)
     ORDER BY tag_number ASC"
);
$cows_without->execute([fid()]);
$cows_without = $cows_without->fetchAll();

$sellers = $db->prepare("SELECT id, name, phone FROM sellers WHERE farm_id=? ORDER BY name");
$sellers->execute([fid()]);
$sellers = $sellers->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Cow Purchases</h2>
        <p class="text-sm text-muted">Track the acquisition cost of each cow</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/sales/sellers.php" class="btn btn-secondary">Sellers</a>
        <a href="/modules/cows/index.php"    class="btn btn-secondary">All Cows</a>
    </div>
</div>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Cows Tracked</div>
        <div style="font-size:1.8rem;font-weight:700"><?= $kpi['total_purchases'] ?></div>
    </div>
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Total Invested</div>
        <div style="font-size:1.8rem;font-weight:700;color:var(--danger)">৳<?= number_format($kpi['total_cost'], 0) ?></div>
    </div>
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Avg Purchase</div>
        <div style="font-size:1.8rem;font-weight:700">
            ৳<?= $kpi['total_purchases'] > 0 ? number_format($kpi['total_price'] / $kpi['total_purchases'], 0) : '0' ?>
        </div>
    </div>
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Transport Cost</div>
        <div style="font-size:1.8rem;font-weight:700">৳<?= number_format($kpi['total_transport'], 0) ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:380px 1fr;gap:1.25rem;align-items:start">

    <!-- Add Form -->
    <div class="card">
        <div class="card-header"><span class="card-title">Add Purchase Record</span></div>
        <div class="card-body">
            <?php if (empty($cows_without)): ?>
            <p style="color:var(--text-muted);font-size:.85rem">All cows already have purchase records.</p>
            <?php else: ?>
            <form method="POST" action="/modules/cows/purchases.php" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_purchase">

                <div class="form-group">
                    <label class="form-label">Cow <span style="color:var(--danger)">*</span></label>
                    <select name="cow_id" class="form-control" required onchange="prefillCowPrice(this)">
                        <option value="">— Select cow —</option>
                        <?php foreach ($cows_without as $c): ?>
                        <option value="<?= $c['id'] ?>"
                                data-price="<?= $c['purchase_price'] ?? '' ?>"
                                data-date="<?= $c['purchase_date'] ?? '' ?>">
                            #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Seller</label>
                    <select name="seller_id" class="form-control">
                        <option value="">— Unknown / not recorded —</option>
                        <?php foreach ($sellers as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['name']) ?> <?= $s['phone'] ? '('.$s['phone'].')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-hint"><a href="/modules/sales/sellers.php" target="_blank">Add new seller ↗</a></span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Purchase Price (৳) <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="purchase_price" id="f_price" class="form-control"
                               step="0.01" min="1" placeholder="e.g. 50000" required oninput="calcTotal()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Transport Cost (৳)</label>
                        <input type="number" name="transport_cost" id="f_transport" class="form-control"
                               step="0.01" min="0" value="0" oninput="calcTotal()">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Total Cost: <strong id="f_total">৳0</strong></label>
                </div>

                <div class="form-group">
                    <label class="form-label">Purchase Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="purchase_date" id="f_date" class="form-control"
                           value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" maxlength="500" placeholder="Optional">
                </div>

                <button type="submit" class="btn btn-primary btn-sm">Save Purchase</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Purchase History -->
    <div class="card">
        <div class="card-header"><span class="card-title">Purchase History</span></div>
        <div style="overflow-x:auto">
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Cow</th><th>Seller</th><th>Price</th><th>Transport</th><th>Total</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($purchases)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:2rem">No purchase records yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($purchases as $p): ?>
                    <tr>
                        <td><?= e($p['purchase_date']) ?></td>
                        <td>
                            <a href="/modules/cows/view.php?id=<?= $p['cow_id'] ?>" style="font-weight:600">#<?= e($p['tag_number']) ?></a>
                            <span style="font-size:.78rem;color:var(--text-muted)"><?= e($p['breed']) ?></span>
                        </td>
                        <td style="font-size:.82rem"><?= $p['seller_name'] ? e($p['seller_name']) : '<span style="color:var(--text-muted)">—</span>' ?></td>
                        <td>৳<?= number_format($p['purchase_price'], 0) ?></td>
                        <td><?= $p['transport_cost'] > 0 ? '৳'.number_format($p['transport_cost'],0) : '—' ?></td>
                        <td><strong>৳<?= number_format($p['total_cost'], 0) ?></strong></td>
                        <td>
                            <span class="badge badge-<?= match($p['cow_status']) {
                                'active' => 'success', 'sold' => 'info', 'deceased' => 'danger', default => 'secondary'
                            } ?>"><?= e($p['cow_status']) ?></span>
                        </td>
                        <td>
                            <?php if (hasRole(['admin', 'manager'])): ?>
                            <form method="POST" onsubmit="return confirm('Delete this purchase record?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"      value="delete_purchase">
                                <input type="hidden" name="purchase_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="padding:.2rem .5rem;font-size:.72rem">×</button>
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

</div>

<script>
function prefillCowPrice(sel) {
    var opt = sel.options[sel.selectedIndex];
    var price = opt.getAttribute('data-price');
    var date  = opt.getAttribute('data-date');
    if (price) document.getElementById('f_price').value = price;
    if (date)  document.getElementById('f_date').value  = date;
    calcTotal();
}
function calcTotal() {
    var p = parseFloat(document.getElementById('f_price').value)     || 0;
    var t = parseFloat(document.getElementById('f_transport').value) || 0;
    document.getElementById('f_total').textContent = '৳' + (p + t).toLocaleString('en-BD', {minimumFractionDigits:0});
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
