<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['superadmin']);

$db  = getDB();
$uid = (int)currentUser()['id'];

// ── APPROVE / REJECT HANDLER ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        jsonResponse(['ok' => false, 'error' => 'CSRF mismatch.'], 403);
    }

    $action     = $_POST['action']     ?? '';
    $payment_id = (int)($_POST['payment_id'] ?? 0);

    if (!in_array($action, ['approve', 'reject'], true) || $payment_id <= 0) {
        jsonResponse(['ok' => false, 'error' => 'Invalid request.'], 400);
    }

    // Load the payment row (must be pending)
    $pay = $db->prepare(
        "SELECT py.*, f.id AS f_id, f.farm_name FROM payments py
         JOIN farms f ON f.id = py.farm_id
         WHERE py.id = ? AND py.status = 'pending'"
    );
    $pay->execute([$payment_id]);
    $payment = $pay->fetch();

    if (!$payment) {
        jsonResponse(['ok' => false, 'error' => 'Payment not found or already processed.'], 404);
    }

    if ($action === 'reject') {
        $reason = trim(substr($_POST['rejection_reason'] ?? '', 0, 500));
        $db->prepare(
            "UPDATE payments SET status='failed', recorded_by=?, paid_at=NOW(),
             notes = CONCAT(COALESCE(notes,''), IF(notes IS NOT NULL AND notes != '', '\n', ''), 'Rejected: ', ?)
             WHERE id=?"
        )->execute([$uid, $reason ?: 'No reason given.', $payment_id]);

        auditLog($uid, 'PAYMENT_REJECTED', 'payments', $payment_id, null, [
            'farm_id' => $payment['farm_id'], 'reason' => $reason
        ]);
        jsonResponse(['ok' => true, 'message' => 'Payment request rejected.']);
    }

    // ── APPROVE ───────────────────────────────────────────────────────────────
    try {
        $db->beginTransaction();

        // 1. Mark payment completed
        $db->prepare(
            "UPDATE payments SET status='completed', recorded_by=?, paid_at=NOW() WHERE id=?"
        )->execute([$uid, $payment_id]);

        // 2. Extend/activate subscription
        $plan_id = (int)$payment['plan_id'];
        $months  = max(1, (int)($payment['months'] ?? 1));

        // Get plan's billing_days
        $plan_row = $db->prepare("SELECT billing_days FROM plans WHERE id=?");
        $plan_row->execute([$plan_id]);
        $plan_data = $plan_row->fetch();
        $billing_days = (int)($plan_data['billing_days'] ?? 30);
        $days_to_add  = $billing_days * $months;

        // Find current active/grace subscription
        $existing = $db->prepare(
            "SELECT * FROM subscriptions WHERE farm_id=? ORDER BY id DESC LIMIT 1"
        );
        $existing->execute([$payment['farm_id']]);
        $current_sub = $existing->fetch();

        $today = date('Y-m-d');

        if ($current_sub) {
            // Extend from existing end_date if still in future, else from today
            $base = ($current_sub['end_date'] && $current_sub['end_date'] > $today)
                    ? $current_sub['end_date']
                    : $today;
            $new_end = date('Y-m-d', strtotime($base . ' + ' . $days_to_add . ' days'));

            $db->prepare(
                "UPDATE subscriptions SET plan_id=?, status='active', start_date=?, end_date=?,
                 grace_end_date=DATE_ADD(?, INTERVAL 5 DAY)
                 WHERE id=?"
            )->execute([$plan_id, $today, $new_end, $new_end, $current_sub['id']]);
        } else {
            $new_end = date('Y-m-d', strtotime($today . ' + ' . $days_to_add . ' days'));
            $db->prepare(
                "INSERT INTO subscriptions (farm_id, plan_id, start_date, end_date, grace_end_date, status)
                 VALUES (?, ?, ?, ?, DATE_ADD(?, INTERVAL 5 DAY), 'active')"
            )->execute([$payment['farm_id'], $plan_id, $today, $new_end, $new_end]);
        }

        // 3. Ensure farm status is active
        $db->prepare("UPDATE farms SET status='active' WHERE id=?")->execute([$payment['farm_id']]);

        $db->commit();

        auditLog($uid, 'PAYMENT_APPROVED', 'payments', $payment_id, null, [
            'farm_id'  => $payment['farm_id'],
            'plan_id'  => $plan_id,
            'months'   => $months,
            'new_end'  => $new_end ?? null,
        ]);

        jsonResponse(['ok' => true, 'message' => 'Payment approved. Subscription activated until ' . ($new_end ?? 'N/A') . '.']);

    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('[PAYMENT_APPROVE] ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Database error. Please try again.'], 500);
    }
}

// ── PAGE DATA ─────────────────────────────────────────────────────────────────
$filter = $_GET['status'] ?? 'pending';
if (!in_array($filter, ['pending', 'completed', 'failed', 'all'], true)) $filter = 'pending';

$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$where  = ['1=1'];
$params = [];
if ($filter !== 'all') {
    $where[]  = 'py.status = ?';
    $params[] = $filter;
}
$where_sql = implode(' AND ', $where);

$total = (int)$db->prepare("SELECT COUNT(*) FROM payments py WHERE {$where_sql}")
                  ->execute($params) ? $db->prepare("SELECT COUNT(*) FROM payments py WHERE {$where_sql}")->execute($params) : 0;
$cnt_stmt = $db->prepare("SELECT COUNT(*) FROM payments py WHERE {$where_sql}");
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();

$pager = paginate($total, $per_page, $page);

$list_stmt = $db->prepare(
    "SELECT py.*, f.farm_name, f.farm_code, p.name AS plan_name,
            u.name AS recorded_by_name
     FROM payments py
     JOIN farms f ON f.id = py.farm_id
     JOIN plans p ON p.id = py.plan_id
     LEFT JOIN users u ON u.id = py.recorded_by
     WHERE {$where_sql}
     ORDER BY FIELD(py.status,'pending','failed','completed'), py.created_at DESC
     LIMIT ? OFFSET ?"
);
$list_stmt->execute(array_merge($params, [$per_page, $pager['offset']]));
$payments = $list_stmt->fetchAll();

// KPI counts
$kpi = $db->query(
    "SELECT
        SUM(status='pending')   AS pending_count,
        SUM(status='completed') AS approved_count,
        SUM(status='failed')    AS rejected_count,
        COALESCE(SUM(CASE WHEN status='pending' THEN amount END), 0) AS pending_value,
        COALESCE(SUM(CASE WHEN status='completed' THEN amount END), 0) AS total_collected
     FROM payments"
)->fetch();

$page_title = 'Payment Requests';
$active_nav = 'revenue';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Payment Requests</h2>
        <p class="text-sm text-muted">Review and approve farmer payment submissions</p>
    </div>
    <a href="/modules/super_admin/revenue.php" class="btn btn-secondary">← Revenue</a>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr));margin-bottom:1.5rem">
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-value"><?= (int)$kpi['pending_count'] ?></div>
        <div class="kpi-label">Pending Approval</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-value">৳<?= number_format((float)$kpi['pending_value'], 0) ?></div>
        <div class="kpi-label">Pending Value</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#059669;--kpi-soft:#F0FDF4">
        <div class="kpi-value"><?= (int)$kpi['approved_count'] ?></div>
        <div class="kpi-label">Approved</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#059669;--kpi-soft:#F0FDF4">
        <div class="kpi-value">৳<?= number_format((float)$kpi['total_collected'], 0) ?></div>
        <div class="kpi-label">Total Collected</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-value"><?= (int)$kpi['rejected_count'] ?></div>
        <div class="kpi-label">Rejected</div>
    </div>
</div>

<!-- Filter tabs -->
<div style="display:flex;gap:.35rem;margin-bottom:1rem;flex-wrap:wrap">
    <?php foreach (['pending' => 'Pending', 'completed' => 'Approved', 'failed' => 'Rejected', 'all' => 'All'] as $k => $l): ?>
    <a href="?status=<?= $k ?>" class="btn btn-sm <?= $filter===$k ? 'btn-primary' : 'btn-secondary' ?>">
        <?= $l ?>
        <?php if ($k === 'pending' && $kpi['pending_count'] > 0): ?>
        <span style="background:#DC2626;color:#fff;border-radius:50%;min-width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:700;margin-left:.3rem">
            <?= min((int)$kpi['pending_count'], 99) ?>
        </span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Payments Table -->
<div class="card">
<?php if (empty($payments)): ?>
<div style="padding:3rem;text-align:center;color:#9CA3AF">
    No <?= $filter === 'all' ? '' : $filter ?> payment requests.
</div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="table">
    <thead>
        <tr>
            <th>Date</th>
            <th>Farm</th>
            <th>Plan</th>
            <th>Amount</th>
            <th>Months</th>
            <th>Method</th>
            <th>Transaction ID</th>
            <th>Note</th>
            <th>Screenshot</th>
            <th>Status</th>
            <?php if ($filter === 'pending' || $filter === 'all'): ?>
            <th style="min-width:160px">Actions</th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($payments as $py): ?>
    <tr id="row-<?= $py['id'] ?>" style="<?= $py['status']==='pending' ? 'background:#FFFBEB' : '' ?>">
        <td class="text-xs text-muted"><?= e(formatDate($py['created_at'])) ?></td>
        <td>
            <a href="/modules/super_admin/farm_detail.php?id=<?= $py['farm_id'] ?>" style="font-weight:600">
                <?= e($py['farm_name']) ?>
            </a>
            <div class="text-xs text-muted"><?= e($py['farm_code']) ?></div>
        </td>
        <td style="font-weight:600"><?= e($py['plan_name']) ?></td>
        <td style="font-weight:700;color:#059669">৳<?= number_format((float)$py['amount'], 2) ?></td>
        <td style="text-align:center"><?= (int)($py['months'] ?? 1) ?></td>
        <td><?= e(ucfirst($py['method'])) ?></td>
        <td><code style="font-size:.78rem"><?= e($py['transaction_ref'] ?? '—') ?></code></td>
        <td class="text-xs text-muted" style="max-width:140px">
            <?= e($py['notes'] ?? '—') ?>
        </td>
        <td>
            <?php if ($py['screenshot_path']): ?>
            <a href="<?= e($py['screenshot_path']) ?>" target="_blank"
               onclick="viewScreenshot(event, '<?= e(addslashes($py['screenshot_path'])) ?>')"
               style="color:#7C3AED;font-weight:600;font-size:.8rem">View</a>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td>
            <?php
            $sc = $py['status'];
            $badge = match($sc) {
                'completed' => 'badge-green',
                'pending'   => 'badge-orange',
                'failed'    => 'badge-red',
                default     => 'badge-gray',
            };
            $label = match($sc) {
                'pending' => 'Pending',
                'completed' => 'Approved',
                'failed' => 'Rejected',
                default => ucfirst($sc),
            };
            ?>
            <span class="badge <?= $badge ?>"><?= $label ?></span>
            <?php if ($sc === 'completed' && $py['recorded_by_name']): ?>
            <div class="text-xs text-muted">by <?= e($py['recorded_by_name']) ?></div>
            <?php endif; ?>
        </td>
        <?php if ($filter === 'pending' || $filter === 'all'): ?>
        <td>
            <?php if ($py['status'] === 'pending'): ?>
            <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                <button type="button" class="btn btn-sm btn-primary" style="background:#059669;border:none"
                        onclick="approvePayment(<?= $py['id'] ?>)">
                    ✓ Approve
                </button>
                <button type="button" class="btn btn-sm btn-danger"
                        onclick="rejectPayment(<?= $py['id'] ?>)">
                    ✗ Reject
                </button>
            </div>
            <?php else: ?>
            <span class="text-muted text-xs">—</span>
            <?php endif; ?>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination" style="margin-top:1rem">
    <?php if ($pager['has_prev']): ?>
    <a href="?status=<?= urlencode($filter) ?>&page=<?= $pager['current_page']-1 ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p=max(1,$pager['current_page']-2); $p<=min($pager['total_pages'],$pager['current_page']+2); $p++): ?>
    <a href="?status=<?= urlencode($filter) ?>&page=<?= $p ?>"
       class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="?status=<?= urlencode($filter) ?>&page=<?= $pager['current_page']+1 ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Screenshot lightbox -->
<div id="screenshotLightbox" style="position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9200;display:none;align-items:center;justify-content:center;padding:1rem" onclick="this.style.display='none';document.body.style.overflow=''">
    <img id="lightboxImg" src="" alt="Payment Screenshot" style="max-width:90vw;max-height:90vh;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.5)">
</div>

<?php
$csrf_val  = generateCsrfToken();
$csrf_name = CSRF_TOKEN_NAME;
?>
<script>
var CSRF_NAME  = '<?= e($csrf_name) ?>';
var CSRF_VALUE = '<?= e($csrf_val) ?>';

function apiPost(data, cb) {
    var fd = new FormData();
    fd.append(CSRF_NAME, CSRF_VALUE);
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    fetch('/modules/super_admin/payments.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(cb)
        .catch(function(){ alert('Request failed. Please try again.'); });
}

function approvePayment(id) {
    if (!confirm('Approve this payment and activate/extend the farm subscription?')) return;
    apiPost({action:'approve', payment_id:id}, function(d){
        if (d.ok) {
            var row = document.getElementById('row-'+id);
            if (row) {
                row.style.background = '#F0FDF4';
                var td = row.querySelector('td:last-child');
                if (td) td.innerHTML = '<span class="badge badge-green">Approved</span>';
            }
            alert(d.message || 'Approved.');
            location.reload();
        } else {
            alert('Error: ' + (d.error || 'Unknown error.'));
        }
    });
}

function rejectPayment(id) {
    var reason = prompt('Reason for rejection (will be logged):');
    if (reason === null) return; // cancelled
    apiPost({action:'reject', payment_id:id, rejection_reason:reason}, function(d){
        if (d.ok) {
            var row = document.getElementById('row-'+id);
            if (row) {
                row.style.background = '#FEF2F2';
                var td = row.querySelector('td:last-child');
                if (td) td.innerHTML = '<span class="badge badge-red">Rejected</span>';
            }
            location.reload();
        } else {
            alert('Error: ' + (d.error || 'Unknown error.'));
        }
    });
}

function viewScreenshot(e, path) {
    e.preventDefault();
    var lb = document.getElementById('screenshotLightbox');
    document.getElementById('lightboxImg').src = path;
    lb.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
