<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin']);
requireModule('workers');

$page_title = 'Task Management';
$active_nav = 'workers';
$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/workers/tasks.php');
    }

    $action  = $_POST['action'] ?? '';
    $user_id = (int)$_SESSION['user_id'];

    // Assign new task
    if ($action === 'assign_task') {
        $worker_id   = (int)($_POST['worker_id']   ?? 0);
        $task_type   = sanitize($_POST['task_type']   ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $assigned_date = sanitize($_POST['assigned_date'] ?? '');

        $err = null;
        if ($worker_id <= 0) $err = 'Please select a worker.';
        elseif ($task_type === '') $err = 'Task type is required.';
        elseif (strlen($task_type) > 100) $err = 'Task type is too long.';
        elseif ($assigned_date === '' || !strtotime($assigned_date)) $err = 'Valid assigned date is required.';
        else {
            $chk = $db->prepare("SELECT id FROM workers WHERE id = ? AND status = 'active'");
            $chk->execute([$worker_id]);
            if (!$chk->fetch()) $err = 'Selected worker is invalid or inactive.';
        }

        if ($err) {
            flashMessage('error', $err);
        } else {
            $db->prepare(
                "INSERT INTO worker_tasks (worker_id, task_type, description, assigned_date, status)
                 VALUES (?,?,?,?,'pending')"
            )->execute([$worker_id, $task_type, $description ?: null, $assigned_date]);
            $new_id = (int)$db->lastInsertId();
            auditLog($user_id, 'ASSIGN_TASK', 'worker_tasks', $new_id, null, [
                'worker_id' => $worker_id, 'task_type' => $task_type, 'assigned_date' => $assigned_date,
            ]);
            flashMessage('success', "Task '{$task_type}' assigned.");
        }
        redirect('/modules/workers/tasks.php');
    }

    // Update task status
    if ($action === 'update_status') {
        $task_id    = (int)($_POST['task_id']    ?? 0);
        $new_status = sanitize($_POST['new_status'] ?? '');
        $valid      = ['pending','in_progress','completed','overdue'];
        if ($task_id > 0 && in_array($new_status, $valid, true)) {
            $sel = $db->prepare("SELECT id, status FROM worker_tasks WHERE id = ?");
            $sel->execute([$task_id]);
            $t = $sel->fetch();
            if ($t) {
                $completed_at = $new_status === 'completed' ? date('Y-m-d H:i:s') : null;
                $db->prepare("UPDATE worker_tasks SET status=?, completed_at=? WHERE id=?")
                   ->execute([$new_status, $completed_at, $task_id]);
                auditLog($user_id, 'UPDATE_TASK_STATUS', 'worker_tasks', $task_id,
                    ['status' => $t['status']], ['status' => $new_status]);
                flashMessage('success', 'Task status updated.');
            }
        }
        redirect('/modules/workers/tasks.php?' . http_build_query(array_filter([
            'worker_id' => $_POST['f_worker'] ?? '',
            'status'    => $_POST['f_status'] ?? '',
        ])));
    }

    // Delete task
    if ($action === 'delete_task') {
        $task_id = (int)($_POST['task_id'] ?? 0);
        if ($task_id > 0) {
            $sel = $db->prepare("SELECT id, task_type FROM worker_tasks WHERE id = ?");
            $sel->execute([$task_id]);
            $t = $sel->fetch();
            if ($t) {
                $db->prepare("DELETE FROM worker_tasks WHERE id = ?")->execute([$task_id]);
                auditLog($user_id, 'DELETE_TASK', 'worker_tasks', $task_id, $t, null);
                flashMessage('success', "Task '{$t['task_type']}' deleted.");
            }
        }
        redirect('/modules/workers/tasks.php');
    }

    redirect('/modules/workers/tasks.php');
}

// Filters
$filter_worker = (int)($_GET['worker_id'] ?? 0);
$filter_status = $_GET['status'] ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 25;

$valid_statuses = ['pending','in_progress','completed','overdue'];
if (!in_array($filter_status, $valid_statuses, true)) $filter_status = '';

$where  = ['1=1'];
$params = [];
if ($filter_worker > 0) {
    $where[]  = 'wt.worker_id = ?';
    $params[] = $filter_worker;
}
if ($filter_status !== '') {
    $where[]  = 'wt.status = ?';
    $params[] = $filter_status;
}
$where_sql = implode(' AND ', $where);

$count_stmt = $db->prepare(
    "SELECT COUNT(*) FROM worker_tasks wt WHERE {$where_sql}"
);
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pager = paginate($total, $per_page, $page);

$fetch_params = array_merge($params, [$per_page, $pager['offset']]);
$stmt = $db->prepare(
    "SELECT wt.id, wt.task_type, wt.description, wt.assigned_date, wt.completed_at, wt.status,
            wt.worker_id, u.name AS worker_name
     FROM worker_tasks wt
     JOIN workers w ON w.id = wt.worker_id
     JOIN users   u ON u.id = w.user_id
     WHERE {$where_sql}
     ORDER BY FIELD(wt.status,'overdue','in_progress','pending','completed'), wt.assigned_date ASC
     LIMIT ? OFFSET ?"
);
$stmt->execute($fetch_params);
$tasks = $stmt->fetchAll();

// All active workers for assign form + filter dropdown
$all_workers = $db->query(
    "SELECT w.id, u.name FROM workers w
     JOIN users u ON u.id = w.user_id
     WHERE w.status = 'active'
     ORDER BY u.name ASC"
)->fetchAll();

// Summary counts
$summary = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM worker_tasks GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

function task_badge(string $s): string {
    return match($s) {
        'pending'     => 'badge-yellow',
        'in_progress' => 'badge-blue',
        'completed'   => 'badge-green',
        'overdue'     => 'badge-red',
        default       => 'badge-gray',
    };
}

$qs = static fn(array $p): string =>
    '/modules/workers/tasks.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null && $v !== 0));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Task Management</h2>
        <p class="text-sm text-muted">Assign and track all worker tasks</p>
    </div>
    <a href="/modules/workers/index.php" class="btn btn-secondary">Workers List</a>
</div>

<!-- Summary pills -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <a href="/modules/workers/tasks.php" class="btn btn-sm <?= $filter_status === '' ? 'btn-primary' : 'btn-secondary' ?>">
        All (<?= array_sum($summary) ?>)
    </a>
    <?php
    $status_labels = ['overdue'=>'Overdue','in_progress'=>'In Progress','pending'=>'Pending','completed'=>'Completed'];
    foreach ($status_labels as $sval => $slabel):
        $cnt = $summary[$sval] ?? 0;
    ?>
    <a href="<?= e($qs(['worker_id'=>$filter_worker?:null,'status'=>$sval])) ?>"
       class="btn btn-sm <?= $filter_status === $sval ? 'btn-primary' : 'btn-secondary' ?>">
        <?= $slabel ?> (<?= $cnt ?>)
    </a>
    <?php endforeach; ?>
</div>

<!-- Worker filter + task list -->
<div style="display:grid;grid-template-columns:1fr;gap:1.5rem">

    <!-- Filters row -->
    <form method="GET" action="/modules/workers/tasks.php" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;min-width:200px">
            <label class="form-label" style="font-size:.78rem">Worker</label>
            <select name="worker_id" class="form-control">
                <option value="">All Workers</option>
                <?php foreach ($all_workers as $aw): ?>
                <option value="<?= $aw['id'] ?>" <?= $filter_worker === (int)$aw['id'] ? 'selected' : '' ?>>
                    <?= e($aw['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="status" value="<?= e($filter_status) ?>">
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($filter_worker || $filter_status): ?>
        <a href="/modules/workers/tasks.php" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
        <span class="text-sm text-muted" style="align-self:center;margin-left:auto">
            <?= number_format($total) ?> task<?= $total !== 1 ? 's' : '' ?>
        </span>
    </form>

    <!-- Tasks table -->
    <div class="card">
        <?php if (empty($tasks)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
            </svg>
            <h3>No tasks found</h3>
            <p>Assign a task below to get started.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Worker</th>
                    <th>Assigned Date</th>
                    <th>Status</th>
                    <th>Completed</th>
                    <th style="width:130px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $t): ?>
            <tr>
                <td>
                    <div style="font-weight:600"><?= e($t['task_type']) ?></div>
                    <?php if ($t['description']): ?>
                    <div class="text-muted" style="font-size:.8rem;margin-top:.1rem"><?= e(mb_strimwidth($t['description'], 0, 60, '…')) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/modules/workers/my_tasks.php?worker_id=<?= $t['worker_id'] ?>"
                       style="color:var(--primary)"><?= e($t['worker_name']) ?></a>
                </td>
                <td style="white-space:nowrap"><?= e(formatDate($t['assigned_date'])) ?></td>
                <td><span class="badge <?= task_badge($t['status']) ?>"><?= e(str_replace('_',' ',ucfirst($t['status']))) ?></span></td>
                <td style="font-size:.82rem"><?= $t['completed_at'] ? e(formatDate($t['completed_at'])) : '—' ?></td>
                <td>
                    <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                        <a href="/modules/workers/edit_task.php?id=<?= $t['id'] ?>&back=<?= urlencode('/modules/workers/tasks.php?' . http_build_query(array_filter(['worker_id'=>$filter_worker?:null,'status'=>$filter_status]))) ?>"
                           class="btn btn-sm btn-secondary" title="Edit">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <?php if ($t['status'] !== 'completed'): ?>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"     value="update_status">
                            <input type="hidden" name="task_id"    value="<?= $t['id'] ?>">
                            <input type="hidden" name="new_status" value="completed">
                            <input type="hidden" name="f_worker"   value="<?= $filter_worker ?: '' ?>">
                            <input type="hidden" name="f_status"   value="<?= e($filter_status) ?>">
                            <button type="submit" class="btn btn-sm btn-primary" title="Mark complete">✓</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Delete this task?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="delete_task">
                            <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($pager['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pager['has_prev']): ?>
        <a href="<?= e($qs(['worker_id'=>$filter_worker?:null,'status'=>$filter_status,'page'=>$pager['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p=max(1,$pager['current_page']-2); $p<=min($pager['total_pages'],$pager['current_page']+2); $p++): ?>
        <a href="<?= e($qs(['worker_id'=>$filter_worker?:null,'status'=>$filter_status,'page'=>$p])) ?>"
           class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager['has_next']): ?>
        <a href="<?= e($qs(['worker_id'=>$filter_worker?:null,'status'=>$filter_status,'page'=>$pager['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Assign Task Form -->
    <div class="card" id="assign">
        <div class="card-header">
            <span class="card-title">Assign New Task</span>
        </div>
        <div class="card-body">
            <?php if (empty($all_workers)): ?>
            <p class="text-muted">No active workers available. <a href="/modules/workers/form.php">Add workers first.</a></p>
            <?php else: ?>
            <form method="POST" action="/modules/workers/tasks.php" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign_task">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem">
                    <div class="form-group">
                        <label class="form-label" for="worker_id_assign">Worker <span style="color:var(--danger)">*</span></label>
                        <select id="worker_id_assign" name="worker_id" class="form-control" required>
                            <option value="">— Select worker —</option>
                            <?php foreach ($all_workers as $aw): ?>
                            <option value="<?= $aw['id'] ?>"><?= e($aw['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="task_type">Task Type <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="task_type" name="task_type" class="form-control"
                               placeholder="e.g. Feeding, Cleaning, Milking" maxlength="100" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="assigned_date">Assigned Date <span style="color:var(--danger)">*</span></label>
                        <input type="date" id="assigned_date" name="assigned_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="2"
                              placeholder="Optional task details…"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Assign Task
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
