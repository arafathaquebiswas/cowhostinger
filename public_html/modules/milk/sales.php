<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireModule('milk');
requireRole(['admin', 'manager', 'accountant', 'milkman']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Inline migration ──────────────────────────────────────────────────────────
(function (PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS `milk_sales` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `farm_id` INT UNSIGNED NOT NULL,
      `customer_id` INT UNSIGNED DEFAULT NULL,
      `customer_name` VARCHAR(150) NOT NULL,
      `liters_sold` DECIMAL(10,2) NOT NULL,
      `price_per_liter` DECIMAL(10,2) NOT NULL,
      `total_amount` DECIMAL(12,2) NOT NULL,
      `payment_status` ENUM('paid','pending','partial') NOT NULL DEFAULT 'paid',
      `amount_paid` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      `sale_date` DATE NOT NULL,
      `notes` TEXT DEFAULT NULL,
      `recorded_by` INT UNSIGNED NOT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_farm_date` (`farm_id`, `sale_date`),
      KEY `idx_payment_status` (`farm_id`, `payment_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
})($db);

$errors = [];
$today  = date('Y-m-d');

// ── Date filter ───────────────────────────────────────────────────────────────
$filter_from   = $_GET['from'] ?? date('Y-m-01');
$filter_to     = $_GET['to']   ?? $today;
$filter_status = $_GET['status'] ?? '';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/milk/sales.php');
    }

    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $del_id = (int)($_POST['sale_id'] ?? 0);
        $db->prepare("DELETE FROM milk_sales WHERE id = ? AND farm_id = ?")->execute([$del_id, fid()]);
        flashMessage('success', 'Sale record deleted.');
        redirect('/modules/milk/sales.php');
    }

    $cust_id   = (int)($_POST['customer_id'] ?? 0) ?: null;
    $cust_name = sanitize($_POST['customer_name'] ?? '');
    $liters    = (float)($_POST['liters_sold'] ?? 0);
    $ppl       = (float)($_POST['price_per_liter'] ?? 0);
    $total     = round($liters * $ppl, 2);
    $pay_stat  = in_array($_POST['payment_status'] ?? '', ['paid','pending','partial'], true)
                 ? $_POST['payment_status'] : 'paid';
    $amt_paid  = (float)($_POST['amount_paid'] ?? $total);
    $sale_date = trim($_POST['sale_date'] ?? '');
    $notes     = sanitize($_POST['notes'] ?? '');

    // Auto-fill customer name from customer list
    if ($cust_id) {
        $cn = $db->prepare("SELECT name FROM milk_customers WHERE id = ? AND farm_id = ?");
        $cn->execute([$cust_id, fid()]);
        $cn_row = $cn->fetch();
        if ($cn_row) $cust_name = $cn_row['name'];
    }

    if ($cust_name === '') $errors[] = 'Customer name is required.';
    if ($liters <= 0)      $errors[] = 'Liters sold must be greater than zero.';
    if ($ppl <= 0)         $errors[] = 'Price per liter must be greater than zero.';
    if ($sale_date === '') $errors[] = 'Sale date is required.';
    if ($pay_stat === 'partial' && $amt_paid <= 0) $errors[] = 'Enter amount paid for partial payment.';

    // Milk stock validation: prevent overselling beyond production for that date
    if (empty($errors) && $sale_date !== '') {
        $ps = $db->prepare("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE farm_id=? AND contamination_flag=0 AND DATE(recorded_at)=?");
        $ps->execute([fid(), $sale_date]);
        $produced = (float)$ps->fetchColumn();
        if ($produced > 0) {
            $ss = $db->prepare("SELECT COALESCE(SUM(liters_sold),0) FROM milk_sales WHERE farm_id=? AND sale_date=?");
            $ss->execute([fid(), $sale_date]);
            $already_sold = (float)$ss->fetchColumn();
            $available = max(0.0, $produced - $already_sold);
            if ($liters > $available) {
                $errors[] = sprintf(
                    'Insufficient stock for %s: %.2fL available (%.2fL produced − %.2fL already sold).',
                    date('d M Y', strtotime($sale_date)), $available, $produced, $already_sold
                );
            }
        }
    }

    if (empty($errors)) {
        $amt_paid_final = match($pay_stat) {
            'paid'    => $total,
            'pending' => 0.00,
            default   => $amt_paid,
        };

        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO milk_sales
                (farm_id,customer_id,customer_name,liters_sold,price_per_liter,total_amount,payment_status,amount_paid,sale_date,notes,recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([fid(), $cust_id, $cust_name, $liters, $ppl, $total, $pay_stat, $amt_paid_final, $sale_date, $notes ?: null, $uid]);
            $sale_id = (int)$db->lastInsertId();

            if ($total > 0) {
                $db->prepare("INSERT INTO finance_transactions
                    (farm_id,type,category,amount,related_module,reference_id,transaction_date,recorded_by,notes)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([fid(), 'income', 'Milk Sales', $total, 'milk', $sale_id, $sale_date, $uid,
                              "{$liters}L @ {$ppl} BDT/L — {$cust_name}"]);
            }

            auditLog($uid, 'MILK_SALE', 'milk_sales', $sale_id, [], ['liters' => $liters, 'total' => $total]);
            $db->commit();
            flashMessage('success', "Milk sale recorded — {$liters}L sold to {$cust_name} for " . number_format($total, 2) . " BDT.");
            redirect('/modules/milk/sales.php');
        } catch (Throwable $e) {
            $db->rollBack();
            error_log('[milk_sales] ' . $e->getMessage());
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
$customers = $db->prepare("SELECT id, name, price_per_liter FROM milk_customers WHERE farm_id = ? AND is_active = 1 ORDER BY name");
$customers->execute([fid()]);
$customers = $customers->fetchAll();

$q = "SELECT ms.*, u.name AS recorder FROM milk_sales ms LEFT JOIN users u ON u.id = ms.recorded_by WHERE ms.farm_id = ? AND ms.sale_date BETWEEN ? AND ?";
$params = [fid(), $filter_from, $filter_to];
if ($filter_status !== '') { $q .= " AND ms.payment_status = ?"; $params[] = $filter_status; }
$q .= " ORDER BY ms.sale_date DESC, ms.id DESC";
$stmt = $db->prepare($q);
$stmt->execute($params);
$sales = $stmt->fetchAll();

$totals = $db->prepare("SELECT COALESCE(SUM(total_amount),0) AS total_rev, COALESCE(SUM(amount_paid),0) AS total_paid, COALESCE(SUM(liters_sold),0) AS total_liters FROM milk_sales WHERE farm_id = ? AND sale_date BETWEEN ? AND ?");
$totals->execute([fid(), $filter_from, $filter_to]);
$totals = $totals->fetch();

// Today's available milk stock for the form header
$tp = $db->prepare("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE farm_id=? AND contamination_flag=0 AND DATE(recorded_at)=?");
$tp->execute([fid(), $today]); $stock_produced = (float)$tp->fetchColumn();
$ts2 = $db->prepare("SELECT COALESCE(SUM(liters_sold),0) FROM milk_sales WHERE farm_id=? AND sale_date=?");
$ts2->execute([fid(), $today]); $stock_sold = (float)$ts2->fetchColumn();
$stock_available = max(0.0, $stock_produced - $stock_sold);

$page_title = 'Milk Sales';
$active_nav = 'milk_sales';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.status-paid{background:#dcfce7;color:#166534}.status-pending{background:#fef9c3;color:#713f12}.status-partial{background:#dbeafe;color:#1e40af}
.stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:1rem 1.25rem;text-align:center}
.stat-card .val{font-size:1.5rem;font-weight:700;color:#111827}
.stat-card .lbl{font-size:.8rem;color:#6b7280;margin-top:.1rem}
</style>

<div class="page-header">
    <div><h1 class="page-title">Milk Sales</h1><p class="page-subtitle">Record and track milk sold to customers</p></div>
    <a href="/modules/milk/customers.php" class="btn btn-secondary">Manage Customers</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem">
    <div class="stat-card"><div class="val"><?= number_format($totals['total_liters'], 1) ?>L</div><div class="lbl">Liters Sold</div></div>
    <div class="stat-card"><div class="val"><?= number_format($totals['total_rev'], 0) ?></div><div class="lbl">Total Revenue (BDT)</div></div>
    <div class="stat-card"><div class="val"><?= number_format($totals['total_rev'] - $totals['total_paid'], 0) ?></div><div class="lbl">Outstanding (BDT)</div></div>
</div>

<!-- Today's milk stock summary -->
<?php if ($stock_produced > 0): ?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem">
    <div class="stat-card"><div class="val"><?= number_format($stock_produced, 1) ?>L</div><div class="lbl">Produced Today</div></div>
    <div class="stat-card"><div class="val"><?= number_format($stock_sold, 1) ?>L</div><div class="lbl">Sold Today</div></div>
    <div class="stat-card" style="<?= $stock_available <= 0 ? 'background:#fff1f2;border-color:#ef4444' : 'background:#f0fdf4;border-color:#22c55e' ?>">
        <div class="val" style="color:<?= $stock_available <= 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($stock_available, 1) ?>L</div>
        <div class="lbl">Available Today</div>
    </div>
</div>
<?php else: ?>
<div style="background:#fefce8;border-left:3px solid #eab308;padding:.65rem 1rem;margin-bottom:1rem;font-size:.85rem;color:#713f12;border-radius:6px">
    No milk production recorded for today. <a href="/modules/milk/record.php" style="font-weight:700">Record milk first →</a>
</div>
<?php endif; ?>

<!-- Add Form -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title">Record New Sale</span></div>
    <div class="card-body">
        <form method="POST" id="milkSaleForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem">
                <div class="form-group">
                    <label class="form-label">Customer (from list)</label>
                    <select name="customer_id" id="cust_sel" class="form-control">
                        <option value="">— Walk-in / Manual —</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" data-ppl="<?= $c['price_per_liter'] ?>">
                            <?= e($c['name']) ?><?= $c['price_per_liter'] ? ' (' . number_format($c['price_per_liter'], 2) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Customer Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="customer_name" id="cust_name" class="form-control" placeholder="Or type name" required maxlength="150">
                </div>
                <div class="form-group">
                    <label class="form-label">Liters Sold <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="liters_sold" id="liters" class="form-control" step="0.1" min="0.1" placeholder="e.g. 20" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Price / Liter (BDT) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="price_per_liter" id="ppl" class="form-control" step="0.01" min="0.01" required placeholder="e.g. 65">
                </div>
                <div class="form-group">
                    <label class="form-label">Total Amount</label>
                    <input type="text" id="total_preview" class="form-control" readonly style="background:#f9fafb;font-weight:600">
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status" id="pay_stat" class="form-control">
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="partial">Partial</option>
                    </select>
                </div>
                <div class="form-group" id="amt_paid_wrap" style="display:none">
                    <label class="form-label">Amount Paid (BDT)</label>
                    <input type="number" name="amount_paid" class="form-control" step="0.01" min="0" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label class="form-label">Sale Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="sale_date" class="form-control" value="<?= $today ?>" max="<?= $today ?>" required>
                </div>
                <div class="form-group" style="grid-column:1/-1">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="Optional" maxlength="500">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Record Sale</button>
        </form>
    </div>
</div>

<!-- Filter + Table -->
<div class="card">
    <div class="card-header" style="flex-wrap:wrap;gap:.75rem">
        <span class="card-title">Sales History</span>
        <form method="GET" style="display:flex;gap:.5rem;align-items:center;margin-left:auto;flex-wrap:wrap">
            <input type="date" name="from" value="<?= e($filter_from) ?>" class="form-control" style="width:130px">
            <input type="date" name="to"   value="<?= e($filter_to) ?>"   class="form-control" style="width:130px">
            <select name="status" class="form-control" style="width:120px">
                <option value="">All Status</option>
                <option value="paid"    <?= $filter_status==='paid'    ? 'selected':'' ?>>Paid</option>
                <option value="pending" <?= $filter_status==='pending' ? 'selected':'' ?>>Pending</option>
                <option value="partial" <?= $filter_status==='partial' ? 'selected':'' ?>>Partial</option>
            </select>
            <button class="btn btn-secondary btn-sm">Filter</button>
        </form>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($sales)): ?>
        <p style="text-align:center;padding:2rem;color:#9ca3af">No milk sales found for the selected period.</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
            <thead><tr style="background:#f9fafb">
                <th style="padding:.55rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Date</th>
                <th style="padding:.55rem .75rem;text-align:left;border-bottom:2px solid #e5e7eb">Customer</th>
                <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Liters</th>
                <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Price/L</th>
                <th style="padding:.55rem .75rem;text-align:right;border-bottom:2px solid #e5e7eb">Total</th>
                <th style="padding:.55rem .75rem;text-align:center;border-bottom:2px solid #e5e7eb">Status</th>
                <th style="padding:.55rem .75rem;border-bottom:2px solid #e5e7eb"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($sales as $s): ?>
            <tr style="border-bottom:1px solid #f3f4f6">
                <td style="padding:.5rem .75rem"><?= e(formatDate($s['sale_date'])) ?></td>
                <td style="padding:.5rem .75rem;font-weight:500"><?= e($s['customer_name']) ?></td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format($s['liters_sold'], 1) ?>L</td>
                <td style="padding:.5rem .75rem;text-align:right"><?= number_format($s['price_per_liter'], 2) ?></td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:600"><?= number_format($s['total_amount'], 2) ?></td>
                <td style="padding:.5rem .75rem;text-align:center">
                    <span style="padding:.15rem .55rem;border-radius:999px;font-size:.72rem;font-weight:600" class="status-<?= $s['payment_status'] ?>">
                        <?= ucfirst($s['payment_status']) ?>
                        <?php if ($s['payment_status'] === 'partial'): ?>
                        (<?= number_format($s['amount_paid'], 0) ?> paid)
                        <?php endif; ?>
                    </span>
                </td>
                <td style="padding:.5rem .75rem">
                    <?php if (hasRole(['admin','manager','accountant'])): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this sale record?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
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

<script>
(function(){
    const custSel  = document.getElementById('cust_sel');
    const custName = document.getElementById('cust_name');
    const litersEl = document.getElementById('liters');
    const pplEl    = document.getElementById('ppl');
    const totalEl  = document.getElementById('total_preview');
    const payStat  = document.getElementById('pay_stat');
    const amtWrap  = document.getElementById('amt_paid_wrap');

    custSel.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt.value) {
            custName.value = opt.text.replace(/\s*\(.*\)$/, '').trim();
            const ppl = opt.dataset.ppl;
            if (ppl && parseFloat(ppl) > 0) pplEl.value = ppl;
        }
        calcTotal();
    });

    function calcTotal() {
        const l = parseFloat(litersEl.value) || 0;
        const p = parseFloat(pplEl.value) || 0;
        totalEl.value = l > 0 && p > 0 ? (l * p).toFixed(2) + ' BDT' : '';
    }
    litersEl.addEventListener('input', calcTotal);
    pplEl.addEventListener('input', calcTotal);

    payStat.addEventListener('change', function() {
        amtWrap.style.display = this.value === 'partial' ? '' : 'none';
    });
})();
</script>
<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
