<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin', 'reception']);
requireModule('workers');

$page_title = 'Workers';
$active_nav = 'workers';
$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/workers/index.php');
    }

    $action    = $_POST['action'] ?? '';
    $worker_id = (int)($_POST['worker_id'] ?? 0);
    $user_id   = (int)$_SESSION['user_id'];

    if ($action === 'toggle_status' && hasRole(['admin']) && $worker_id > 0) {
        $sel = $db->prepare("SELECT w.id, w.status, u.name FROM workers w JOIN users u ON u.id = w.user_id WHERE w.id = ?");
        $sel->execute([$worker_id]);
        $w = $sel->fetch();
        if ($w) {
            $new_status = $w['status'] === 'active' ? 'inactive' : 'active';
            $db->prepare("UPDATE workers SET status = ? WHERE id = ?")->execute([$new_status, $worker_id]);
            auditLog($user_id, 'TOGGLE_WORKER_STATUS', 'workers', $worker_id, ['status' => $w['status']], ['status' => $new_status]);
            flashMessage('success', "{$w['name']} marked as {$new_status}.");
        }
    }

    if ($action === 'delete' && hasRole(['admin']) && $worker_id > 0) {
        $sel = $db->prepare("SELECT w.id, u.name FROM workers w JOIN users u ON u.id = w.user_id WHERE w.id = ?");
        $sel->execute([$worker_id]);
        $w = $sel->fetch();
        if ($w) {
            try {
                $db->prepare("DELETE FROM workers WHERE id = ?")->execute([$worker_id]);
                auditLog($user_id, 'DELETE_WORKER', 'workers', $worker_id, $w, null);
                flashMessage('success', "Worker profile for {$w['name']} deleted.");
            } catch (PDOException $e) {
                flashMessage('error', "Cannot delete worker — they have linked task records.");
            }
        }
    }

    redirect('/modules/workers/index.php');
}

// Filters
$search     = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 20;

$valid_statuses = ['active','inactive','terminated'];
if (!in_array($filter_status, $valid_statuses, true)) $filter_status = '';

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[]  = '(u.name LIKE ? OR u.email LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($filter_status !== '') {
    $where[]  = 'w.status = ?';
    $params[] = $filter_status;
}
$where_sql = implode(' AND ', $where);

$count_stmt = $db->prepare(
    "SELECT COUNT(*) FROM workers w JOIN users u ON u.id = w.user_id WHERE {$where_sql}"
);
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pager = paginate($total, $per_page, $page);

$fetch_params = array_merge($params, [$per_page, $pager['offset']]);
$stmt = $db->prepare(
    "SELECT w.id AS worker_id, w.salary, w.hire_date, w.termination_date, w.status AS worker_status,
            u.id AS user_id, u.name, u.email, u.role, u.is_active,
            (SELECT COUNT(*) FROM worker_tasks wt
             WHERE wt.worker_id = w.id AND wt.status IN ('pending','in_progress')) AS pending_tasks,
            (SELECT COUNT(*) FROM worker_tasks wt
             WHERE wt.worker_id = w.id AND wt.status = 'overdue') AS overdue_tasks
     FROM workers w
     JOIN users u ON u.id = w.user_id
     WHERE {$where_sql}
     ORDER BY w.status ASC, u.name ASC
     LIMIT ? OFFSET ?"
);
$stmt->execute($fetch_params);
$workers = $stmt->fetchAll();

// Summary stats
$stats = $db->query(
    "SELECT
       COUNT(*) AS total,
       SUM(w.status = 'active') AS active,
       (SELECT COUNT(*) FROM worker_tasks WHERE status IN ('pending','in_progress')) AS open_tasks,
       (SELECT COUNT(*) FROM worker_tasks WHERE status = 'overdue') AS overdue
     FROM workers w"
)->fetch();

$qs = static fn(array $p): string =>
    '/modules/workers/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null));

function worker_status_badge(string $s): string {
    return match($s) {
        'active'     => 'badge-green',
        'inactive'   => 'badge-yellow',
        'terminated' => 'badge-red',
        default      => 'badge-gray',
    };
}

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Workers</h2>
        <p class="text-sm text-muted"><?= (int)$stats['total'] ?> worker profile<?= $stats['total'] != 1 ? 's' : '' ?></p>
    </div>
    <?php if (hasRole(['admin'])): ?>
    <a href="/modules/workers/form.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Worker
    </a>
    <?php endif; ?>
</div>

<!-- Summary KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Active Workers</div>
        <div class="kpi-value"><?= (int)$stats['active'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-label">Open Tasks</div>
        <div class="kpi-value"><?= (int)$stats['open_tasks'] ?></div>
    </div>
    <?php if ($stats['overdue'] > 0): ?>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">Overdue Tasks</div>
        <div class="kpi-value"><?= (int)$stats['overdue'] ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <?php
    $status_pills = ['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'terminated' => 'Terminated'];
    foreach ($status_pills as $sval => $slabel):
        $act = $filter_status === $sval;
    ?>
    <a href="<?= e($qs(['status' => $sval, 'search' => $search])) ?>"
       class="btn btn-sm <?= $act ? 'btn-primary' : 'btn-secondary' ?>"><?= $slabel ?></a>
    <?php endforeach; ?>
</div>

<form method="GET" action="/modules/workers/index.php"
      style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem">
    <input type="hidden" name="status" value="<?= e($filter_status) ?>">
    <input type="text" name="search" class="form-control" placeholder="Search by name or email…"
           value="<?= e($search) ?>" style="max-width:280px">
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <?php if ($search): ?>
    <a href="<?= e($qs(['status' => $filter_status])) ?>" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
</form>

<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($workers)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
        </svg>
        <h3>No workers found</h3>
        <p><?= hasRole(['admin'])
            ? 'No worker profiles yet. <a href="/modules/workers/form.php">Add the first worker.</a>'
            : 'No workers match the current filters.' ?></p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Salary (৳/mo)</th>
                <th>Hire Date</th>
                <th>Status</th>
                <th>Tasks</th>
                <th style="width:130px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($workers as $w): ?>
        <tr>
            <td>
                <div style="font-weight:600"><?= e($w['name']) ?></div>
                <div class="text-muted" style="font-size:.8rem"><?= e($w['email']) ?></div>
            </td>
            <td>
                <span class="role-badge role-<?= e($w['role']) ?>"><?= e(ucfirst($w['role'])) ?></span>
            </td>
            <td><?= e(number_format((float)$w['salary'], 2)) ?></td>
            <td><?= e(formatDate($w['hire_date'])) ?></td>
            <td>
                <span class="badge <?= worker_status_badge($w['worker_status']) ?>">
                    <?= e(ucfirst($w['worker_status'])) ?>
                </span>
            </td>
            <td>
                <?php if ($w['overdue_tasks'] > 0): ?>
                <span class="badge badge-red"><?= (int)$w['overdue_tasks'] ?> overdue</span>
                <?php endif; ?>
                <?php if ($w['pending_tasks'] > 0): ?>
                <span class="badge badge-yellow"><?= (int)$w['pending_tasks'] ?> open</span>
                <?php elseif ($w['overdue_tasks'] == 0): ?>
                <span class="text-muted" style="font-size:.82rem">None</span>
                <?php endif; ?>
            </td>
            <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <a href="/modules/workers/tasks.php?worker_id=<?= $w['worker_id'] ?>"
                       class="btn btn-sm btn-secondary" title="View tasks">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                    </a>
                    <?php if (hasRole(['admin'])): ?>
                    <a href="/modules/workers/form.php?id=<?= $w['worker_id'] ?>"
                       class="btn btn-sm btn-secondary" title="Edit">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </a>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"    value="toggle_status">
                        <input type="hidden" name="worker_id" value="<?= $w['worker_id'] ?>">
                        <button type="submit"
                                class="btn btn-sm <?= $w['worker_status'] === 'active' ? 'btn-warning' : 'btn-secondary' ?>"
                                title="<?= $w['worker_status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                            <?= $w['worker_status'] === 'active' ? '⏸' : '▶' ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Delete worker profile for <?= e(addslashes($w['name'])) ?>?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"    value="delete">
                        <input type="hidden" name="worker_id" value="<?= $w['worker_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
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
    <?php endif; ?>
</div>

<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="<?= e($qs(['status'=>$filter_status,'search'=>$search,'page'=>$pager['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p=max(1,$pager['current_page']-2); $p<=min($pager['total_pages'],$pager['current_page']+2); $p++): ?>
    <a href="<?= e($qs(['status'=>$filter_status,'search'=>$search,'page'=>$p])) ?>"
       class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($qs(['status'=>$filter_status,'search'=>$search,'page'=>$pager['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
