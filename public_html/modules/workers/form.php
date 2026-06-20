<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin']);
requireFarmScope();
requireModule('workers');

$db         = getDB();
$worker_id  = (int)($_GET['id'] ?? 0);
$is_edit    = $worker_id > 0;

$errors = [];
$form = [
    'user_id'          => '',
    'salary'           => '',
    'hire_date'        => date('Y-m-d'),
    'termination_date' => '',
    'status'           => 'active',
];
$worker_user = null;

if ($is_edit) {
    $sel = $db->prepare(
        "SELECT w.id, w.user_id, w.salary, w.hire_date, w.termination_date, w.status,
                u.name, u.email, u.role
         FROM workers w JOIN users u ON u.id = w.user_id
         WHERE w.id = ? AND " . farmFilter('u')
    );
    $sel->execute([$worker_id]);
    $worker_user = $sel->fetch();
    if (!$worker_user) {
        flashMessage('error', 'Worker profile not found.');
        redirect('/modules/workers/index.php');
    }
    $form = array_merge($form, [
        'user_id'          => $worker_user['user_id'],
        'salary'           => $worker_user['salary'],
        'hire_date'        => $worker_user['hire_date'],
        'termination_date' => $worker_user['termination_date'] ?? '',
        'status'           => $worker_user['status'],
    ]);
}

// Users available for new worker profiles (no existing worker profile)
$available_users = [];
if (!$is_edit) {
    $avail_stmt = $db->prepare(
        "SELECT u.id, u.name, u.email, u.role
         FROM users u
         WHERE u.status = 'active'
           AND " . farmFilter('u') . "
           AND u.id NOT IN (SELECT user_id FROM workers)
         ORDER BY u.name ASC"
    );
    $avail_stmt->execute();
    $available_users = $avail_stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/workers/form.php' . ($is_edit ? "?id={$worker_id}" : ''));
    }

    $form['salary']           = trim($_POST['salary']           ?? '');
    $form['hire_date']        = trim($_POST['hire_date']        ?? '');
    $form['termination_date'] = trim($_POST['termination_date'] ?? '');
    $form['status']           = sanitize($_POST['status']       ?? 'active');
    if (!$is_edit) {
        $form['user_id'] = (int)($_POST['user_id'] ?? 0);
    }

    // Validation
    $salary = $form['salary'] !== '' ? (float)$form['salary'] : null;
    if ($salary === null || $salary < 0) $errors[] = 'Salary must be 0 or greater.';
    if ($salary > 9999999)               $errors[] = 'Salary value is too large.';

    if ($form['hire_date'] === '')         $errors[] = 'Hire date is required.';
    elseif (!strtotime($form['hire_date'])) $errors[] = 'Invalid hire date.';

    if ($form['termination_date'] !== '' && !strtotime($form['termination_date'])) {
        $errors[] = 'Invalid termination date.';
    }
    if ($form['termination_date'] !== '' && $form['hire_date'] !== '' &&
        strtotime($form['termination_date']) < strtotime($form['hire_date'])) {
        $errors[] = 'Termination date cannot be before hire date.';
    }

    $valid_statuses = ['active','inactive','terminated'];
    if (!in_array($form['status'], $valid_statuses, true)) $errors[] = 'Invalid status.';

    if (!$is_edit) {
        if ($form['user_id'] <= 0) {
            $errors[] = 'Please select a user.';
        } else {
            // Confirm user belongs to this farm
            $ucheck = $db->prepare("SELECT id FROM users WHERE id = ? AND " . farmFilter());
            $ucheck->execute([$form['user_id']]);
            if (!$ucheck->fetch()) {
                $errors[] = 'Invalid user selected.';
            } else {
                $chk = $db->prepare("SELECT id FROM workers WHERE user_id = ?");
                $chk->execute([$form['user_id']]);
                if ($chk->fetch()) $errors[] = 'This user already has a worker profile.';
            }
        }
    }

    if (empty($errors)) {
        $uid = (int)$_SESSION['user_id'];
        $hire  = $form['hire_date'];
        $term  = $form['termination_date'] !== '' ? $form['termination_date'] : null;

        if ($is_edit) {
            $upd = $db->prepare(
                "UPDATE workers SET salary=?, hire_date=?, termination_date=?, status=? WHERE id=?"
            );
            $upd->execute([$salary, $hire, $term, $form['status'], $worker_id]);
            auditLog($uid, 'UPDATE_WORKER', 'workers', $worker_id, $worker_user, $form);
            flashMessage('success', "Worker profile for {$worker_user['name']} updated.");
        } else {
            $ins = $db->prepare(
                "INSERT INTO workers (farm_id, user_id, salary, hire_date, termination_date, status) VALUES (?,?,?,?,?,?)"
            );
            $ins->execute([fid(), $form['user_id'], $salary, $hire, $term, $form['status']]);
            $new_id = (int)$db->lastInsertId();
            auditLog($uid, 'CREATE_WORKER', 'workers', $new_id, null, $form);

            // Get user name for flash
            $uname = $db->prepare("SELECT name FROM users WHERE id = ?");
            $uname->execute([$form['user_id']]);
            $n = $uname->fetchColumn();
            flashMessage('success', "Worker profile created for {$n}.");
        }
        redirect('/modules/workers/index.php');
    }
}

$page_title = $is_edit ? "Edit Worker — {$worker_user['name']}" : 'Add Worker Profile';
$active_nav = 'workers';

$status_options = ['active' => 'Active', 'inactive' => 'Inactive', 'terminated' => 'Terminated'];

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2><?= $is_edit ? 'Edit Worker' : 'Add Worker Profile' ?></h2>
        <?php if ($is_edit): ?>
        <p class="text-sm text-muted"><?= e($worker_user['name']) ?> (<?= e($worker_user['email']) ?>)</p>
        <?php endif; ?>
    </div>
    <a href="/modules/workers/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following errors:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/workers/form.php<?= $is_edit ? "?id={$worker_id}" : '' ?>" novalidate>
    <?= csrfField() ?>

    <div class="card" style="margin-bottom:1.25rem;max-width:640px">
        <div class="card-header">
            <span class="card-title"><?= $is_edit ? 'Profile Details' : 'Assign User Account' ?></span>
        </div>
        <div class="card-body">

            <?php if (!$is_edit): ?>
            <div class="form-group">
                <label class="form-label" for="user_id">User Account <span style="color:var(--danger)">*</span></label>
                <select id="user_id" name="user_id" class="form-control" required>
                    <option value="">— Select user —</option>
                    <?php foreach ($available_users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= (int)$form['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['name']) ?> (<?= e($u['email']) ?>) — <?= e(ucfirst($u['role'])) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">
                    Only active users without an existing worker profile are listed.
                    <?= empty($available_users) ? '<strong>No available users — <a href="/modules/admin/users.php">create a user account first</a>.</strong>' : '' ?>
                </span>
            </div>
            <?php else: ?>
            <div class="form-group">
                <div class="form-label">User Account</div>
                <div style="padding:.6rem .8rem;background:var(--bg-sidebar);border:1px solid var(--border);border-radius:var(--radius);font-size:.875rem">
                    <strong><?= e($worker_user['name']) ?></strong>
                    <span class="text-muted"> — <?= e($worker_user['email']) ?></span>
                    <span class="role-badge role-<?= e($worker_user['role']) ?>" style="margin-left:.5rem"><?= e(ucfirst($worker_user['role'])) ?></span>
                </div>
                <span class="form-hint">User account cannot be changed. Edit via <a href="/modules/admin/users.php">User Management</a>.</span>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="salary">Monthly Salary (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="salary" name="salary" class="form-control"
                           value="<?= e($form['salary']) ?>"
                           step="0.01" min="0" placeholder="e.g. 15000.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <?php foreach ($status_options as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $form['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="hire_date">Hire Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" id="hire_date" name="hire_date" class="form-control"
                           value="<?= e($form['hire_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="termination_date">Termination Date</label>
                    <input type="date" id="termination_date" name="termination_date" class="form-control"
                           value="<?= e($form['termination_date'] ?? '') ?>">
                    <span class="form-hint">Leave empty if still employed.</span>
                </div>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Create Profile' ?>
        </button>
        <a href="/modules/workers/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
