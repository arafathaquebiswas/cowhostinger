<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireAccess('payroll.view');
requireNotBlocked();

$db  = getDB();
$ff  = farmFilter();
$uid = (int)$_SESSION['user_id'];

$batch_id = (int)($_GET['id'] ?? 0);
if ($batch_id <= 0) { redirect('/modules/payroll/index.php'); }

// Load batch (farm-scoped)
$bstmt = $db->prepare(
    "SELECT pb.*, uc.name AS creator_name, ua.name AS approver_name
     FROM payroll_batches pb
     LEFT JOIN users uc ON uc.id = pb.created_by
     LEFT JOIN users ua ON ua.id = pb.approved_by
     WHERE pb.id = ? AND pb.{$ff}"
);
$bstmt->execute([$batch_id]);
$batch = $bstmt->fetch();
if (!$batch) {
    flashMessage('error', 'Payroll batch not found.');
    redirect('/modules/payroll/index.php');
}

$page_title = "Payroll — {$batch['period_label']}";
$active_nav = 'payroll';

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/payroll/view.php?id=' . $batch_id);
    }

    $action = $_POST['action'] ?? '';

    // Mark single record paid
    if ($action === 'mark_paid') {
        $rec_id = (int)($_POST['record_id'] ?? 0);
        $method = in_array($_POST['payment_method'] ?? '', ['cash','bank','bkash','nagad','rocket'], true)
                  ? $_POST['payment_method'] : 'cash';
        $pay_date = sanitize($_POST['payment_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pay_date)) $pay_date = date('Y-m-d');

        $rec = $db->prepare("SELECT id, worker_id, status FROM payroll_records WHERE id = ? AND batch_id = ? AND {$ff}");
        $rec->execute([$rec_id, $batch_id]);
        $r = $rec->fetch();
        if ($r && $r['status'] === 'pending') {
            $db->prepare(
                "UPDATE payroll_records SET status='paid', payment_method=?, payment_date=? WHERE id=?"
            )->execute([$method, $pay_date, $rec_id]);
            // Update batch total_paid check — recalc if all paid
            $remaining = $db->prepare("SELECT COUNT(*) FROM payroll_records WHERE batch_id=? AND status='pending'");
            $remaining->execute([$batch_id]);
            if ((int)$remaining->fetchColumn() === 0 && $batch['status'] !== 'paid') {
                $db->prepare("UPDATE payroll_batches SET status='paid' WHERE id=?")->execute([$batch_id]);
                auditLog($uid, 'PAYROLL_BATCH_PAID', 'payroll_batches', $batch_id, ['status'=>$batch['status']], ['status'=>'paid']);
            }
            auditLog($uid, 'PAYROLL_RECORD_PAID', 'payroll_records', $rec_id, ['status'=>'pending'], ['status'=>'paid','method'=>$method]);
            flashMessage('success', 'Payment marked as paid.');
        }
        redirect('/modules/payroll/view.php?id=' . $batch_id);
    }

    // Approve batch (admin only)
    if ($action === 'approve_batch' && hasRole(['admin'])) {
        if ($batch['status'] === 'draft') {
            $db->prepare(
                "UPDATE payroll_batches SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?"
            )->execute([$uid, $batch_id]);
            auditLog($uid, 'APPROVE_PAYROLL_BATCH', 'payroll_batches', $batch_id, ['status'=>'draft'], ['status'=>'approved']);
            flashMessage('success', "Payroll for {$batch['period_label']} approved.");
        }
        redirect('/modules/payroll/view.php?id=' . $batch_id);
    }

    // Mark ALL pending as paid + create finance entry
    if ($action === 'pay_all' && hasRole(['admin', 'accountant'])) {
        if (in_array($batch['status'], ['approved', 'draft'], true)) {
            $pay_date = date('Y-m-d');
            $db->prepare(
                "UPDATE payroll_records SET status='paid', payment_date=? WHERE batch_id=? AND status='pending'"
            )->execute([$pay_date, $batch_id]);

            // Reload total from records
            $tot = $db->prepare("SELECT COALESCE(SUM(net_salary),0) FROM payroll_records WHERE batch_id=?");
            $tot->execute([$batch_id]);
            $total_paid = (float)$tot->fetchColumn();

            $db->prepare(
                "UPDATE payroll_batches SET status='paid', total_amount=? WHERE id=?"
            )->execute([$total_paid, $batch_id]);

            // Auto-create finance expense entry
            $existing = $db->prepare(
                "SELECT id FROM finance_transactions WHERE {$ff} AND related_module='payroll' AND reference_id=?"
            );
            $existing->execute([$batch_id]);
            if (!$existing->fetch() && $total_paid > 0) {
                $db->prepare(
                    "INSERT INTO finance_transactions
                     (farm_id, type, category, amount, related_module, reference_id, transaction_date, recorded_by, notes)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([
                    fid(), 'expense', 'Payroll',
                    $total_paid, 'payroll', $batch_id,
                    $pay_date, $uid,
                    "Salary payment — {$batch['period_label']}",
                ]);
            }

            auditLog($uid, 'PAYROLL_BATCH_PAY_ALL', 'payroll_batches', $batch_id,
                ['status' => $batch['status']], ['status' => 'paid', 'total' => $total_paid]);
            flashMessage('success', "All workers paid. Finance expense entry created for ৳" . number_format($total_paid, 2) . ".");
        }
        redirect('/modules/payroll/view.php?id=' . $batch_id);
    }

    redirect('/modules/payroll/view.php?id=' . $batch_id);
}

// ── Load records ──────────────────────────────────────────────────────────────
$rstmt = $db->prepare(
    "SELECT pr.id AS record_id, pr.basic_salary, pr.working_days, pr.present_days,
            pr.overtime_pay, pr.bonuses, pr.deductions, pr.net_salary,
            pr.payment_method, pr.payment_date, pr.status, pr.notes,
            u.name AS worker_name, u.email AS worker_email
     FROM payroll_records pr
     JOIN workers w ON w.id = pr.worker_id
     JOIN users   u ON u.id = w.user_id
     WHERE pr.batch_id = ? AND pr.{$ff}
     ORDER BY u.name ASC"
);
$rstmt->execute([$batch_id]);
$records = $rstmt->fetchAll();

$paid_total   = 0.0;
$pending_total = 0.0;
foreach ($records as $r) {
    if ($r['status'] === 'paid') $paid_total   += (float)$r['net_salary'];
    else                         $pending_total += (float)$r['net_salary'];
}
$paid_count    = count(array_filter($records, fn($r) => $r['status'] === 'paid'));
$pending_count = count($records) - $paid_count;

$badge_class = match($batch['status']) {
    'approved' => 'badge-blue',
    'paid'     => 'badge-green',
    default    => 'badge-yellow',
};

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
$flash = getFlashMessage();
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom:1rem">
    <?= e($flash['message']) ?>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h2><?= e($batch['period_label']) ?> Payroll</h2>
        <p class="text-sm text-muted">
            <span class="badge <?= $badge_class ?>"><?= ucfirst($batch['status']) ?></span>
            &nbsp;·&nbsp; Generated by <?= e($batch['creator_name'] ?? '—') ?>
            &nbsp;·&nbsp; <?= formatDate($batch['created_at']) ?>
            <?php if ($batch['approver_name']): ?>
            &nbsp;·&nbsp; Approved by <?= e($batch['approver_name']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <?php if ($batch['status'] === 'draft' && hasRole(['admin'])): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve_batch">
            <button type="submit" class="btn btn-primary btn-sm"
                    onclick="return confirm('Approve this payroll batch?')">
                ✓ Approve Batch
            </button>
        </form>
        <?php endif; ?>
        <?php if (in_array($batch['status'], ['draft','approved']) && $pending_count > 0 && hasRole(['admin','accountant'])): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="pay_all">
            <button type="submit" class="btn btn-primary btn-sm"
                    onclick="return confirm('Mark ALL pending workers as paid and create a Finance expense entry?')">
                💰 Pay All & Close
            </button>
        </form>
        <?php endif; ?>
        <a href="/modules/payroll/index.php" class="btn btn-secondary btn-sm">← Payroll List</a>
        <a href="#" onclick="window.print()" class="btn btn-secondary btn-sm">🖨 Print</a>
    </div>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="card" style="padding:1rem 1.1rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);margin-bottom:.25rem">Total Workers</div>
        <div style="font-size:1.4rem;font-weight:800;color:var(--primary)"><?= count($records) ?></div>
    </div>
    <div class="card" style="padding:1rem 1.1rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);margin-bottom:.25rem">Total Payroll</div>
        <div style="font-size:1.4rem;font-weight:800;color:var(--primary)">৳<?= number_format($batch['total_amount'], 0) ?></div>
    </div>
    <div class="card" style="padding:1rem 1.1rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);margin-bottom:.25rem">Paid Out</div>
        <div style="font-size:1.4rem;font-weight:800;color:#059669">৳<?= number_format($paid_total, 0) ?></div>
        <div style="font-size:.72rem;color:var(--text-muted)"><?= $paid_count ?> workers</div>
    </div>
    <div class="card" style="padding:1rem 1.1rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);margin-bottom:.25rem">Remaining</div>
        <div style="font-size:1.4rem;font-weight:800;color:<?= $pending_count > 0 ? '#d97706' : '#059669' ?>">৳<?= number_format($pending_total, 0) ?></div>
        <div style="font-size:.72rem;color:var(--text-muted)"><?= $pending_count ?> workers</div>
    </div>
</div>

<!-- Worker records table -->
<div class="card">
    <?php if (empty($records)): ?>
    <div class="empty-state"><p>No payroll records found for this batch.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table" style="min-width:820px">
        <thead>
            <tr>
                <th style="min-width:150px">Worker</th>
                <th>Basic Salary</th>
                <th>Attendance</th>
                <th>Overtime</th>
                <th>Bonus</th>
                <th>Deduction</th>
                <th>Net Salary</th>
                <th>Method</th>
                <th>Status</th>
                <th style="width:120px">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
            <td>
                <div style="font-weight:600;font-size:.875rem"><?= e($r['worker_name']) ?></div>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= e($r['worker_email']) ?></div>
            </td>
            <td>৳<?= number_format($r['basic_salary'], 2) ?></td>
            <td>
                <?= $r['present_days'] ?>/<?= $r['working_days'] ?> days
                <?php if ($r['present_days'] < $r['working_days']): ?>
                <span style="font-size:.7rem;color:#d97706">(<?= round($r['present_days']/$r['working_days']*100) ?>%)</span>
                <?php endif; ?>
            </td>
            <td><?= $r['overtime_pay'] > 0 ? '৳' . number_format($r['overtime_pay'], 2) : '—' ?></td>
            <td><?= $r['bonuses'] > 0 ? '৳' . number_format($r['bonuses'], 2) : '—' ?></td>
            <td><?= $r['deductions'] > 0 ? '<span style="color:#dc2626">-৳' . number_format($r['deductions'], 2) . '</span>' : '—' ?></td>
            <td style="font-weight:700;color:var(--primary)">৳<?= number_format($r['net_salary'], 2) ?></td>
            <td style="font-size:.82rem"><?= ucfirst($r['payment_method']) ?></td>
            <td>
                <?php if ($r['status'] === 'paid'): ?>
                <span class="badge badge-green">Paid</span>
                <div style="font-size:.7rem;color:var(--text-muted);margin-top:.15rem">
                    <?= $r['payment_date'] ? formatDate($r['payment_date']) : '' ?>
                </div>
                <?php else: ?>
                <span class="badge badge-yellow">Pending</span>
                <?php endif; ?>
            </td>
            <td>
                <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                    <a href="/modules/payroll/slip.php?id=<?= $r['record_id'] ?>" target="_blank"
                       class="btn btn-sm btn-secondary" title="Pay Slip">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </a>
                    <?php if ($r['status'] === 'pending' && hasRole(['admin','accountant'])): ?>
                    <button type="button" class="btn btn-sm btn-primary"
                            onclick="openPayModal(<?= $r['record_id'] ?>, '<?= e($r['worker_name']) ?>', '<?= e($r['payment_method']) ?>')"
                            title="Mark Paid">
                        ✓
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background:var(--bg-base)">
                <td colspan="5" style="text-align:right;padding:.75rem 1rem;font-weight:700;font-size:.85rem">Grand Total:</td>
                <td colspan="5" style="padding:.75rem 1rem;font-weight:800;color:var(--primary);font-size:1rem">৳<?= number_format($batch['total_amount'], 2) ?></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Mark Paid Modal -->
<div id="payModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:2rem;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.25)">
        <h3 style="font-size:1rem;font-weight:700;margin:0 0 .5rem">Mark Payment Received</h3>
        <p id="payModalWorker" style="font-size:.85rem;color:var(--text-secondary);margin:0 0 1.25rem"></p>
        <form method="POST" action="/modules/payroll/view.php?id=<?= $batch_id ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action"    value="mark_paid">
            <input type="hidden" name="record_id" id="payRecordId">
            <div class="form-group">
                <label class="form-label">Payment Date</label>
                <input type="date" name="payment_date" class="form-control"
                       value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select name="payment_method" id="payMethod" class="form-control">
                    <option value="cash">Cash</option>
                    <option value="bkash">bKash</option>
                    <option value="nagad">Nagad</option>
                    <option value="rocket">Rocket</option>
                    <option value="bank">Bank Transfer</option>
                </select>
            </div>
            <div style="display:flex;gap:.5rem;margin-top:1.25rem">
                <button type="submit" class="btn btn-primary" style="flex:1">Confirm Paid</button>
                <button type="button" class="btn btn-secondary" onclick="closePayModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayModal(recordId, workerName, defaultMethod) {
    document.getElementById('payRecordId').value  = recordId;
    document.getElementById('payModalWorker').textContent = 'Worker: ' + workerName;
    var sel = document.getElementById('payMethod');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === defaultMethod) { sel.selectedIndex = i; break; }
    }
    var modal = document.getElementById('payModal');
    modal.style.display = 'flex';
}
function closePayModal() {
    document.getElementById('payModal').style.display = 'none';
}
document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});

// Print: hide action buttons, show full table
window.addEventListener('beforeprint', function() {
    document.querySelectorAll('.page-header div:last-child, #payModal').forEach(function(el) {
        el.style.display = 'none';
    });
});
window.addEventListener('afterprint', function() {
    document.querySelectorAll('.page-header div:last-child').forEach(function(el) {
        el.style.display = '';
    });
});
</script>

<style>
@media print {
    .sidebar, .topbar, .page-header > div:last-child, #payModal { display: none !important; }
    .main-content { margin-left: 0 !important; padding: 1rem !important; }
    .btn { display: none !important; }
}
</style>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
