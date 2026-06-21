<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager']);
requireFarmScope();
requireNotBlocked();
requireModule('workers');

$db      = getDB();
$task_id = (int)($_GET['id'] ?? $_POST['task_id'] ?? 0);
$back    = $_GET['back'] ?? $_POST['back'] ?? '/modules/workers/tasks.php';

// Whitelist back URL to prevent open redirect
if (!str_starts_with($back, '/modules/workers/')) {
    $back = '/modules/workers/tasks.php';
}

if ($task_id <= 0) { redirect($back); }

// Load task + worker name
$task_stmt = $db->prepare(
    "SELECT wt.id, wt.task_type, wt.description, wt.assigned_date, wt.status, wt.worker_id,
            u.name AS worker_name
     FROM worker_tasks wt
     JOIN workers w ON w.id = wt.worker_id
     JOIN users   u ON u.id = w.user_id
     WHERE wt.id = ? AND " . farmFilter('u')
);
$task_stmt->execute([$task_id]);
$task = $task_stmt->fetch();
if (!$task) { flashMessage('error', 'Task not found.'); redirect($back); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect($back);
    }

    $task_type   = sanitize($_POST['task_type']   ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $assigned_date = sanitize($_POST['assigned_date'] ?? '');
    $new_status  = sanitize($_POST['status'] ?? '');
    $valid_statuses = ['pending', 'in_progress', 'completed', 'overdue'];

    if ($task_type === '')  $errors[] = 'Task type is required.';
    elseif (strlen($task_type) > 100) $errors[] = 'Task type must be 100 characters or less.';
    if ($assigned_date === '' || !strtotime($assigned_date)) $errors[] = 'Valid assigned date is required.';
    if (!in_array($new_status, $valid_statuses, true)) $errors[] = 'Invalid status selected.';

    if (empty($errors)) {
        $completed_at = ($new_status === 'completed' && $task['status'] !== 'completed')
            ? date('Y-m-d H:i:s')
            : ($new_status !== 'completed' ? null : null); // keep null if de-completing

        $old = ['task_type' => $task['task_type'], 'description' => $task['description'],
                'assigned_date' => $task['assigned_date'], 'status' => $task['status']];

        $db->prepare(
            "UPDATE worker_tasks
             SET task_type=?, description=?, assigned_date=?, status=?,
                 completed_at = CASE WHEN ? = 'completed' THEN COALESCE(completed_at, NOW()) ELSE NULL END
             WHERE id=?"
        )->execute([$task_type, $description ?: null, $assigned_date, $new_status, $new_status, $task_id]);

        auditLog((int)$_SESSION['user_id'], 'EDIT_TASK', 'worker_tasks', $task_id,
            $old, compact('task_type', 'description', 'assigned_date', 'new_status'));

        flashMessage('success', "Task '{$task_type}' updated.");
        redirect($back);
    }

    // Re-populate for re-display
    $task['task_type']    = $task_type;
    $task['description']  = $description;
    $task['assigned_date'] = $assigned_date;
    $task['status']       = $new_status;
}

$page_title = 'Edit Task';
$active_nav = 'workers';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Edit Task</h2>
        <p class="text-sm text-muted">Worker: <strong><?= e($task['worker_name']) ?></strong></p>
    </div>
    <a href="<?= e($back) ?>" class="btn btn-secondary">Cancel</a>
</div>

<?php foreach ($errors as $err): ?>
<div class="alert alert-danger" style="margin-bottom:1rem"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:600px">
    <div class="card-header"><span class="card-title">Task Details</span></div>
    <div class="card-body">
        <form method="POST" action="/modules/workers/edit_task.php" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="task_id" value="<?= $task_id ?>">
            <input type="hidden" name="back"    value="<?= e($back) ?>">

            <div class="form-group">
                <label class="form-label" for="task_type">
                    Task Type <span style="color:var(--danger)">*</span>
                </label>
                <input type="text" id="task_type" name="task_type" class="form-control"
                       value="<?= e($task['task_type']) ?>" maxlength="100" required
                       placeholder="e.g. Feeding, Cleaning, Milking">
            </div>

            <div class="form-group">
                <label class="form-label" for="assigned_date">
                    Assigned Date <span style="color:var(--danger)">*</span>
                </label>
                <input type="date" id="assigned_date" name="assigned_date" class="form-control"
                       value="<?= e($task['assigned_date']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="status">
                    Status <span style="color:var(--danger)">*</span>
                </label>
                <select id="status" name="status" class="form-control" required>
                    <?php
                    $statuses = ['pending' => 'Pending', 'in_progress' => 'In Progress',
                                 'completed' => 'Completed', 'overdue' => 'Overdue'];
                    foreach ($statuses as $val => $label):
                    ?>
                    <option value="<?= $val ?>" <?= $task['status'] === $val ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3"
                          placeholder="Optional task details…"><?= e($task['description'] ?? '') ?></textarea>
            </div>

            <div style="display:flex;gap:.5rem;margin-top:1.25rem">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= e($back) ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
