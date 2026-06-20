<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();
requireModule('workers');

$db      = getDB();
$user_id = (int)$_SESSION['user_id'];

// Find worker profile for current user
$my_worker_stmt = $db->prepare(
    "SELECT w.id, u.name FROM workers w JOIN users u ON u.id = w.user_id WHERE w.user_id = ? LIMIT 1"
);
$my_worker_stmt->execute([$user_id]);
$my_worker = $my_worker_stmt->fetch();

// Admin can view any worker's tasks via ?worker_id=X
$view_worker_id = (int)($_GET['worker_id'] ?? 0);
$viewing_own    = true;

if (hasRole(['admin']) && $view_worker_id > 0) {
    $vw_stmt = $db->prepare(
        "SELECT w.id, u.name FROM workers w JOIN users u ON u.id = w.user_id WHERE w.id = ? LIMIT 1"
    );
    $vw_stmt->execute([$view_worker_id]);
    $viewing_worker = $vw_stmt->fetch();
    if ($viewing_worker) {
        $my_worker  = $viewing_worker;
        $viewing_own = false;
    }
}

// Handle POST actions (status update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/workers/my_tasks.php');
    }

    $action  = $_POST['action'] ?? '';
    $task_id = (int)($_POST['task_id'] ?? 0);

    if ($action === 'update_status' && $task_id > 0 && $my_worker) {
        $new_status  = sanitize($_POST['new_status'] ?? '');
        $valid_next  = ['pending', 'in_progress', 'completed'];

        if (!in_array($new_status, $valid_next, true)) {
            flashMessage('error', 'Invalid status.');
        } else {
            // Verify task belongs to this worker
            $chk = $db->prepare("SELECT id, status FROM worker_tasks WHERE id = ? AND worker_id = ?");
            $chk->execute([$task_id, $my_worker['id']]);
            $task = $chk->fetch();

            if (!$task) {
                flashMessage('error', 'Task not found or access denied.');
            } else {
                $completed_at = $new_status === 'completed' ? date('Y-m-d H:i:s') : null;
                $db->prepare(
                    "UPDATE worker_tasks SET status=?, completed_at=? WHERE id=?"
                )->execute([$new_status, $completed_at, $task_id]);
                auditLog($user_id, 'UPDATE_TASK_STATUS', 'worker_tasks', $task_id,
                    ['status' => $task['status']], ['status' => $new_status]);
                flashMessage('success', 'Task status updated to ' . ucfirst($new_status) . '.');
            }
        }
    }

    $redir = '/modules/workers/my_tasks.php';
    if (!$viewing_own && $my_worker) $redir .= '?worker_id=' . $my_worker['id'];
    redirect($redir);
}

// Fetch tasks
$tasks = [];
if ($my_worker) {
    $t_stmt = $db->prepare(
        "SELECT id, task_type, description, assigned_date, completed_at, status
         FROM worker_tasks
         WHERE worker_id = ?
         ORDER BY
             FIELD(status,'overdue','in_progress','pending','completed'),
             assigned_date ASC
         LIMIT 60"
    );
    $t_stmt->execute([$my_worker['id']]);
    $tasks = $t_stmt->fetchAll();
}

// Group tasks by status for display
$task_groups = ['overdue' => [], 'in_progress' => [], 'pending' => [], 'completed' => []];
foreach ($tasks as $t) {
    $task_groups[$t['status']][] = $t;
}

function task_badge(string $s): string {
    return match($s) {
        'pending'     => 'badge-yellow',
        'in_progress' => 'badge-blue',
        'completed'   => 'badge-green',
        'overdue'     => 'badge-red',
        default       => 'badge-gray',
    };
}

function task_next_statuses(string $current): array {
    return match($current) {
        'pending'     => ['in_progress' => 'Start Task',   'completed' => 'Mark Complete'],
        'in_progress' => ['completed'   => 'Mark Complete', 'pending'  => 'Reset to Pending'],
        'overdue'     => ['in_progress' => 'Start Task',   'completed' => 'Mark Complete'],
        default       => [],
    };
}

$page_title = $viewing_own ? 'My Tasks' : "Tasks — {$my_worker['name']}";
$active_nav = 'workers';

$base_url = '/modules/workers/my_tasks.php' . (!$viewing_own && $my_worker ? '?worker_id=' . $my_worker['id'] : '');

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2><?= $viewing_own ? 'My Tasks' : 'Worker Tasks' ?></h2>
        <?php if (!$viewing_own && $my_worker): ?>
        <p class="text-sm text-muted">Viewing tasks for <strong><?= e($my_worker['name']) ?></strong></p>
        <?php endif; ?>
    </div>
    <?php if (hasRole(['admin'])): ?>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/workers/tasks.php" class="btn btn-secondary">All Tasks</a>
        <a href="/modules/workers/tasks.php#assign" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Assign Task
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$my_worker): ?>
<div class="card" style="max-width:480px">
    <div class="empty-state" style="padding:2.5rem 1rem">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
        </svg>
        <h3>No Worker Profile</h3>
        <p>Your user account does not have an associated worker profile.
            <?= hasRole(['admin']) ? 'Visit <a href="/modules/workers/form.php">Workers</a> to create one.' : 'Contact your administrator.' ?>
        </p>
    </div>
</div>

<?php else: ?>

<!-- Summary -->
<?php
$open    = count($task_groups['overdue']) + count($task_groups['in_progress']) + count($task_groups['pending']);
$done    = count($task_groups['completed']);
$overdue = count($task_groups['overdue']);
?>
<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem">
    <div class="kpi-card" style="flex:1;min-width:120px;--kpi-color:<?= $overdue > 0 ? '#DC2626' : '#2563EB' ?>;--kpi-soft:<?= $overdue > 0 ? '#FEF2F2' : '#EFF6FF' ?>">
        <div class="kpi-label">Open Tasks</div>
        <div class="kpi-value"><?= $open ?></div>
    </div>
    <?php if ($overdue > 0): ?>
    <div class="kpi-card" style="flex:1;min-width:120px;--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">Overdue</div>
        <div class="kpi-value"><?= $overdue ?></div>
    </div>
    <?php endif; ?>
    <div class="kpi-card" style="flex:1;min-width:120px;--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Completed</div>
        <div class="kpi-value"><?= $done ?></div>
    </div>
</div>

<?php if (empty($tasks)): ?>
<div class="empty-state">
    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
    </svg>
    <h3>No tasks assigned</h3>
    <p>No tasks have been assigned yet.</p>
</div>
<?php else: ?>

<?php
// Render active groups first, then completed
$render_groups = [
    'overdue'     => ['Overdue', '⚠️'],
    'in_progress' => ['In Progress', '⏳'],
    'pending'     => ['Pending', '📋'],
    'completed'   => ['Completed', '✅'],
];
foreach ($render_groups as $gkey => [$gtitle, $gicon]):
    if (empty($task_groups[$gkey])) continue;
?>
<div style="margin-bottom:1.5rem">
    <div class="section-heading" style="margin-bottom:.65rem">
        <?= $gicon ?> <?= $gtitle ?> (<?= count($task_groups[$gkey]) ?>)
    </div>
    <?php foreach ($task_groups[$gkey] as $task):
        $next = task_next_statuses($task['status']);
        $is_overdue_today = $task['status'] === 'pending'
            && strtotime($task['assigned_date']) < strtotime('today');
    ?>
    <div class="record-card" style="<?= $gkey === 'overdue' ? 'border-left:3px solid var(--danger)' : ($gkey === 'in_progress' ? 'border-left:3px solid var(--info)' : '') ?>">
        <div class="record-card-header">
            <div>
                <div class="record-card-title"><?= e($task['task_type']) ?></div>
                <div class="record-card-meta" style="margin-top:.2rem">
                    Due: <?= e(formatDate($task['assigned_date'])) ?>
                    <?php if ($task['completed_at']): ?>
                    · Completed: <?= e(formatDateTime($task['completed_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <span class="badge <?= task_badge($task['status']) ?>"><?= e(str_replace('_',' ',ucfirst($task['status']))) ?></span>
        </div>
        <?php if ($task['description']): ?>
        <div class="record-card-body" style="margin-bottom:.65rem"><?= e($task['description']) ?></div>
        <?php endif; ?>
        <?php if (!empty($next) && ($viewing_own || hasRole(['admin']))): ?>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap">
            <?php foreach ($next as $new_s => $btn_label): ?>
            <form method="POST" action="<?= e($base_url) ?>" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action"     value="update_status">
                <input type="hidden" name="task_id"    value="<?= $task['id'] ?>">
                <input type="hidden" name="new_status" value="<?= e($new_s) ?>">
                <button type="submit" class="btn btn-sm <?= $new_s === 'completed' ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= e($btn_label) ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
