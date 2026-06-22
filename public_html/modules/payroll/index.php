<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireAccess('payroll.view');
requireNotBlocked();

$page_title = 'Payroll Management';
$active_nav = 'payroll';
$db  = getDB();
$ff  = farmFilter();
$uid = (int)$_SESSION['user_id'];

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/payroll/index.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_batch' && hasRole(['admin'])) {
        $bid = (int)($_POST['batch_id'] ?? 0);
        $sel = $db->prepare("SELECT id, period_label, status FROM payroll_batches WHERE id = ? AND {$ff}");
        $sel->execute([$bid]);
        $b = $sel->fetch();
        if ($b && $b['status'] === 'draft') {
            $db->prepare("DELETE FROM payroll_batches WHERE id = ?")->execute([$bid]);
            auditLog($uid, 'DELETE_PAYROLL_BATCH', 'payroll_batches', $bid, $b, null);
            flashMessage('success', "Payroll batch \"{$b['period_label']}\" deleted.");
        } else {
            flashMessage('error', 'Only draft batches can be deleted.');
        }
        redirect('/modules/payroll/index.php');
    }

    redirect('/modules/payroll/index.php');
}

// ── KPIs ──────────────────────────────────────────────────────────────────────
$m_from  = date('Y-m-01');
$m_to    = date('Y-m-t');
$y_from  = date('Y-01-01');

$q = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM payroll_batches WHERE {$ff} AND period_from BETWEEN ? AND ?");
$q->execute([$m_from, $m_to]); $payroll_month = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COUNT(*) FROM workers WHERE {$ff} AND status = 'active'");
$q->execute(); $active_workers = (int)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(salary),0) FROM workers WHERE {$ff} AND status = 'active'");
$q->execute(); $salary_estimate = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COUNT(*) FROM payroll_records WHERE {$ff} AND status = 'pending'");
$q->execute(); $pending_slips = (int)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM payroll_batches WHERE {$ff} AND status = 'paid' AND period_from >= ?");
$q->execute([$y_from]); $ytd_paid = (float)$q->fetchColumn();

// ── Batch list ────────────────────────────────────────────────────────────────
$filter_status = in_array($_GET['status'] ?? '', ['draft','approved','paid'], true) ? $_GET['status'] : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$where  = [$ff];
$params = [];
if ($filter_status !== '') { $where[] = 'status = ?'; $params[] = $filter_status; }
$where_sql = implode(' AND ', $where);

$cnt_stmt = $db->prepare("SELECT COUNT(*) FROM payroll_batches WHERE {$where_sql}");
$cnt_stmt->execute($params); $total = (int)$cnt_stmt->fetchColumn();
$pager = paginate($total, $per_page, $page);

$list_stmt = $db->prepare(
    "SELECT pb.id, pb.period_label, pb.period_from, pb.period_to,
            pb.total_workers, pb.total_amount, pb.status, pb.created_at,
            uc.name AS creator_name,
            (SELECT COUNT(*) FROM payroll_records pr WHERE pr.batch_id = pb.id AND pr.status='paid') AS paid_count
     FROM payroll_batches pb
     LEFT JOIN users uc ON uc.id = pb.created_by
     WHERE {$where_sql}
     ORDER BY pb.period_from DESC
     LIMIT ? OFFSET ?"
);
$list_stmt->execute(array_merge($params, [$per_page, $pager['offset']]));
$batches = $list_stmt->fetchAll();

$sc_stmt = $db->prepare("SELECT status, COUNT(*) FROM payroll_batches WHERE {$ff} GROUP BY status");
$sc_stmt->execute(); $status_counts = $sc_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

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
        <h2>Payroll Management</h2>
        <p class="text-sm text-muted">Process monthly salaries, track payments, and generate pay slips</p>
    </div>
    <?php if (hasRole(['admin','accountant'])): ?>
    <a href="/modules/payroll/generate.php" class="btn btn-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Generate Payroll
    </a>
    <?php endif; ?>
</div>

<!-- KPI Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">

    <div class="card" style="padding:1.1rem 1.25rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);margin-bottom:.3rem">This Month</div>
        <div style="font-size:1.55rem;font-weight:800;color:var(--primary);line-height:1">৳<?= number_format($payroll_month, 0) ?></div>
        <div style="font-size:.75rem;color:var(--text-muted);margin-top:.25rem">Payroll generated</div>
    </div>

    <div class="card" style="padding:1.1rem 1.25rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);margin-bottom:.3rem">Salary Estimate</div>
        <div style="font-size:1.55rem;font-weight:800;color:#0284c7;line-height:1">৳<?= number_format($salary_estimate, 0) ?></div>
        <div style="font-size:.75rem;color:var(--text-muted);margin-top:.25rem"><?= $active_workers ?> active workers</div>
    </div>

    <div class="card" style="padding:1.1rem 1.25rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);margin-bottom:.3rem">Pending Slips</div>
        <div style="font-size:1.55rem;font-weight:800;color:<?= $pending_slips > 0 ? '#d97706' : '#059669' ?>;line-height:1"><?= $pending_slips ?></div>
        <div style="font-size:.75rem;color:var(--text-muted);margin-top:.25rem">Workers unpaid</div>
    </div>

    <div class="card" style="padding:1.1rem 1.25rem">
        <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-secondary);margin-bottom:.3rem">YTD Paid Out</div>
        <div style="font-size:1.55rem;font-weight:800;color:#059669;line-height:1">৳<?= number_format($ytd_paid, 0) ?></div>
        <div style="font-size:.75rem;color:var(--text-muted);margin-top:.25rem">All paid batches this year</div>
    </div>

</div>

<!-- Status filter pills -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <a href="/modules/payroll/index.php" class="btn btn-sm <?= $filter_status === '' ? 'btn-primary' : 'btn-secondary' ?>">
        All (<?= array_sum($status_counts) ?>)
    </a>
    <?php foreach (['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid'] as $sv => $sl): ?>
    <a href="?status=<?= $sv ?>" class="btn btn-sm <?= $filter_status === $sv ? 'btn-primary' : 'btn-secondary' ?>">
        <?= $sl ?> (<?= $status_counts[$sv] ?? 0 ?>)
    </a>
    <?php endforeach; ?>
</div>

<!-- Batch table -->
<div class="card">
    <?php if (empty($batches)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        <h3>No payroll batches yet</h3>
        <?php if (hasRole(['admin','accountant'])): ?>
        <p>Generate the first monthly payroll to get started.</p>
        <a href="/modules/payroll/generate.php" class="btn btn-primary btn-sm">Generate Payroll</a>
        <?php else: ?>
        <p>No payroll has been generated yet.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Period</th>
                <th>Workers</th>
                <th>Total Amount</th>
                <th>Paid</th>
                <th>Status</th>
                <th>Created By</th>
                <th>Generated On</th>
                <th style="width:110px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($batches as $b):
            $badge = match($b['status']) {
                'approved' => 'badge-blue',
                'paid'     => 'badge-green',
                default    => 'badge-yellow',
            };
            $paid_pct = $b['total_workers'] > 0
                ? round($b['paid_count'] / $b['total_workers'] * 100)
                : 0;
        ?>
        <tr>
            <td>
                <div style="font-weight:700"><?= e($b['period_label']) ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= date('d M', strtotime($b['period_from'])) ?> – <?= date('d M Y', strtotime($b['period_to'])) ?></div>
            </td>
            <td><?= $b['total_workers'] ?></td>
            <td style="font-weight:700">৳<?= number_format($b['total_amount'], 2) ?></td>
            <td>
                <div style="font-size:.8rem"><?= $b['paid_count'] ?>/<?= $b['total_workers'] ?></div>
                <?php if ($b['total_workers'] > 0): ?>
                <div style="height:4px;background:var(--border);border-radius:2px;margin-top:3px;width:70px;overflow:hidden">
                    <div style="height:100%;width:<?= $paid_pct ?>%;background:<?= $paid_pct >= 100 ? '#059669' : '#2D6A4F' ?>;border-radius:2px"></div>
                </div>
                <?php endif; ?>
            </td>
            <td><span class="badge <?= $badge ?>"><?= ucfirst($b['status']) ?></span></td>
            <td class="text-muted" style="font-size:.83rem"><?= e($b['creator_name'] ?? '—') ?></td>
            <td class="text-muted" style="font-size:.8rem;white-space:nowrap"><?= formatDate($b['created_at']) ?></td>
            <td>
                <div style="display:flex;gap:.3rem">
                    <a href="/modules/payroll/view.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-secondary" title="View / Process">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                    <?php if ($b['status'] === 'draft' && hasRole(['admin'])): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this payroll batch? This cannot be undone.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"   value="delete_batch">
                        <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if ($pager['total_pages'] > 1): ?>
    <div class="pagination" style="padding:1rem 1.25rem">
        <?php if ($pager['has_prev']): ?>
        <a href="?page=<?= $pager['current_page']-1 ?>&status=<?= e($filter_status) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p = max(1, $pager['current_page']-2); $p <= min($pager['total_pages'], $pager['current_page']+2); $p++): ?>
        <a href="?page=<?= $p ?>&status=<?= e($filter_status) ?>" class="page-btn <?= $p === $pager['current_page'] ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager['has_next']): ?>
        <a href="?page=<?= $pager['current_page']+1 ?>&status=<?= e($filter_status) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
