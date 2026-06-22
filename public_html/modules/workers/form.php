<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager']);
requireFarmScope();
requireNotBlocked();
requireModule('workers');

$db        = getDB();
$worker_id = (int)($_GET['id'] ?? 0);
$is_edit   = $worker_id > 0;

if (!$is_edit && !canAccess('worker.create')) {
    $lim = resourceUsage('workers');
    flashMessage('error', "Worker limit reached ({$lim['current']}/{$lim['max']}). Upgrade your plan to add more workers.");
    redirect('/modules/workers/index.php');
}

// ── Roles farm owners can assign to staff ─────────────────────────────────────
$staff_roles = [
    'worker'      => 'Worker (Field Staff)',
    'veterinarian'=> 'Veterinarian (Doctor)',
    'milkman'     => 'Milkman / Dairy Handler',
    'feed_worker' => 'Feed Worker',
    'accountant'  => 'Accountant',
    'manager'     => 'Manager',
];

$errors      = [];
$worker_user = null;

// Default form state
$form = [
    'mode'             => 'new',       // 'new' | 'existing'
    'user_id'          => '',
    // New user fields
    'name'             => '',
    'email'            => '',   // optional — leave blank if no login access needed
    'phone'            => '',   // optional
    'role'             => 'worker',
    'password'         => '',   // required only when email is provided
    'confirm'          => '',
    // Worker profile fields
    'salary'           => '',
    'hire_date'        => date('Y-m-d'),
    'termination_date' => '',
    'status'           => 'active',
];

// ── Edit mode — load existing worker ─────────────────────────────────────────
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

// ── Users available for "link existing" tab ───────────────────────────────────
$available_users = [];
if (!$is_edit) {
    $avail_stmt = $db->prepare(
        "SELECT u.id, u.name, u.email, u.role
         FROM users u
         WHERE u.status = 'active'
           AND " . farmFilter('u') . "
           AND u.role != 'admin'
           AND u.id NOT IN (SELECT user_id FROM workers WHERE user_id IS NOT NULL)
         ORDER BY u.name ASC"
    );
    $avail_stmt->execute();
    $available_users = $avail_stmt->fetchAll();
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/workers/form.php' . ($is_edit ? "?id={$worker_id}" : ''));
    }

    // Repopulate
    $form['mode']             = $_POST['mode'] ?? 'new';
    $form['salary']           = trim($_POST['salary']           ?? '');
    $form['hire_date']        = trim($_POST['hire_date']        ?? '');
    $form['termination_date'] = trim($_POST['termination_date'] ?? '');
    $form['status']           = sanitize($_POST['status']       ?? 'active');

    if (!$is_edit) {
        $form['user_id'] = (int)($_POST['user_id'] ?? 0);
        $form['name']    = trim($_POST['name']     ?? '');
        $form['email']   = strtolower(trim($_POST['email'] ?? ''));
        $form['phone']   = trim($_POST['phone']    ?? '');
        $form['role']    = $_POST['role']     ?? 'worker';
        $form['password']= $_POST['password'] ?? '';
        $form['confirm'] = $_POST['confirm']  ?? '';
    }

    // ── Validate worker profile fields ────────────────────────────────────────
    $salary = $form['salary'] !== '' ? (float)$form['salary'] : null;
    if ($salary === null || $salary < 0) $errors[] = 'Salary must be 0 or greater.';
    if ($salary !== null && $salary > 9999999) $errors[] = 'Salary value is too large.';

    $validDate = static function (string $d): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
        [$y, $m, $day] = explode('-', $d);
        return checkdate((int)$m, (int)$day, (int)$y);
    };

    if ($form['hire_date'] === '')           $errors[] = 'Hire date is required.';
    elseif (!$validDate($form['hire_date'])) $errors[] = 'Invalid hire date.';

    if ($form['termination_date'] !== '' && !$validDate($form['termination_date']))
        $errors[] = 'Invalid termination date.';

    if ($form['termination_date'] !== '' && $form['hire_date'] !== '' &&
        $form['termination_date'] < $form['hire_date'])
        $errors[] = 'Termination date cannot be before hire date.';

    if (!in_array($form['status'], ['active','inactive','terminated'], true))
        $errors[] = 'Invalid status.';

    // ── NEW user path: validate and create user + worker in one transaction ───
    if (!$is_edit && $form['mode'] === 'new') {
        $allowed_roles = array_keys($staff_roles);

        if ($form['name'] === '')                                    $errors[] = 'Full name is required.';
        if (!in_array($form['role'], $allowed_roles, true))          $errors[] = 'Please select a valid role.';

        // Email — optional, but must be valid if provided
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL))
            $errors[] = 'Invalid email address format.';

        // Password — required only when email is set (email = login identifier)
        $has_email = $form['email'] !== '';
        if ($has_email) {
            if ($form['password'] === '')               $errors[] = 'Password is required when email is provided.';
            elseif (strlen($form['password']) < 10)    $errors[] = 'Password must be at least 10 characters.';
            elseif ($form['password'] !== $form['confirm']) $errors[] = 'Passwords do not match.';
        }

        // Email uniqueness within this farm (only if email provided)
        if ($has_email && empty($errors)) {
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND farm_id = ?");
            $chk->execute([$form['email'], fid()]);
            if ($chk->fetch()) $errors[] = 'That email address is already in use in your farm.';
        }

        if (empty($errors)) {
            $db->beginTransaction();
            try {
                // 1. Create the user account
                $email_val = $form['email'] !== '' ? $form['email'] : null;
                $phone_val = $form['phone'] !== '' ? $form['phone'] : null;
                $hash      = $has_email
                    ? password_hash($form['password'], PASSWORD_BCRYPT, ['cost' => 12])
                    : password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 10]); // unusable random hash when no email

                $db->prepare(
                    "INSERT INTO users (farm_id, name, email, phone, password_hash, role, status) VALUES (?,?,?,?,?,?,?)"
                )->execute([fid(), $form['name'], $email_val, $phone_val, $hash, $form['role'], 'active']);
                $new_user_id = (int)$db->lastInsertId();

                // 2. Create the worker profile
                $hire = $form['hire_date'];
                $term = $form['termination_date'] !== '' ? $form['termination_date'] : null;
                $db->prepare(
                    "INSERT INTO workers (farm_id, user_id, salary, hire_date, termination_date, status) VALUES (?,?,?,?,?,?)"
                )->execute([fid(), $new_user_id, $salary, $hire, $term, $form['status']]);
                $new_worker_id = (int)$db->lastInsertId();

                $db->commit();

                auditLog((int)$_SESSION['user_id'], 'CREATE_USER', 'users', $new_user_id, null,
                    ['name' => $form['name'], 'email' => $email_val, 'role' => $form['role']]);
                auditLog((int)$_SESSION['user_id'], 'CREATE_WORKER', 'workers', $new_worker_id);

                $login_note = $has_email
                    ? "Login email: <strong>{$form['email']}</strong>"
                    : "No email set — staff cannot log in until an email is added.";
                flashMessage('success',
                    "Worker <strong>{$form['name']}</strong> created. {$login_note}"
                );
                redirect('/modules/workers/index.php');

            } catch (Throwable $e) {
                $db->rollBack();
                $errors[] = 'Could not save. Please try again. (' . $e->getMessage() . ')';
            }
        }

    // ── EXISTING user path: link an existing user account ────────────────────
    } elseif (!$is_edit && $form['mode'] === 'existing') {
        if ($form['user_id'] <= 0) {
            $errors[] = 'Please select a user from the list.';
        } else {
            $ucheck = $db->prepare("SELECT id FROM users WHERE id = ? AND " . farmFilter());
            $ucheck->execute([$form['user_id']]);
            if (!$ucheck->fetch()) {
                $errors[] = 'Invalid user selected.';
            } else {
                $dup = $db->prepare("SELECT id FROM workers WHERE user_id = ?");
                $dup->execute([$form['user_id']]);
                if ($dup->fetch()) $errors[] = 'This user already has a worker profile.';
            }
        }

        if (empty($errors)) {
            $hire = $form['hire_date'];
            $term = $form['termination_date'] !== '' ? $form['termination_date'] : null;
            $db->prepare(
                "INSERT INTO workers (farm_id, user_id, salary, hire_date, termination_date, status) VALUES (?,?,?,?,?,?)"
            )->execute([fid(), $form['user_id'], $salary, $hire, $term, $form['status']]);
            $new_id = (int)$db->lastInsertId();

            $uname = $db->prepare("SELECT name FROM users WHERE id = ?");
            $uname->execute([$form['user_id']]);
            $n = $uname->fetchColumn();

            auditLog((int)$_SESSION['user_id'], 'CREATE_WORKER', 'workers', $new_id);
            flashMessage('success', "Worker profile created for <strong>{$n}</strong>.");
            redirect('/modules/workers/index.php');
        }

    // ── Edit path ─────────────────────────────────────────────────────────────
    } elseif ($is_edit) {
        if (empty($errors)) {
            $hire = $form['hire_date'];
            $term = $form['termination_date'] !== '' ? $form['termination_date'] : null;
            $db->prepare(
                "UPDATE workers SET salary=?, hire_date=?, termination_date=?, status=? WHERE id=?"
            )->execute([$salary, $hire, $term, $form['status'], $worker_id]);
            auditLog((int)$_SESSION['user_id'], 'UPDATE_WORKER', 'workers', $worker_id, $worker_user, $form);
            flashMessage('success', "Worker profile for <strong>{$worker_user['name']}</strong> updated.");
            redirect('/modules/workers/index.php');
        }
    }
}

$page_title = $is_edit ? "Edit Worker — {$worker_user['name']}" : 'Add Worker';
$active_nav = 'workers';

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<style>
.mode-tab-bar { display:flex; gap:.5rem; margin-bottom:1.5rem; border-bottom:2px solid var(--border); }
.mode-tab { padding:.55rem 1.1rem; font-size:.875rem; font-weight:600; cursor:pointer;
            border:none; background:none; color:var(--text-muted); border-bottom:2px solid transparent;
            margin-bottom:-2px; transition:color .15s, border-color .15s; }
.mode-tab.active { color:var(--primary); border-bottom-color:var(--primary); }
.mode-section { display:none; }
.mode-section.active { display:block; }
.pwd-hint { font-size:.78rem; color:var(--text-muted); margin-top:.25rem; }
</style>

<div class="page-header">
    <div>
        <h2><?= $is_edit ? 'Edit Worker' : 'Add Worker' ?></h2>
        <?php if ($is_edit): ?>
        <p class="text-sm text-muted"><?= e($worker_user['name']) ?> — <?= e($worker_user['email']) ?></p>
        <?php else: ?>
        <p class="text-sm text-muted">Create a new staff member and their worker profile in one step.</p>
        <?php endif; ?>
    </div>
    <a href="/modules/workers/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/workers/form.php<?= $is_edit ? "?id={$worker_id}" : '' ?>" novalidate id="workerForm">
    <?= csrfField() ?>
    <input type="hidden" name="mode" id="modeInput" value="<?= e($form['mode']) ?>">

<?php if (!$is_edit): ?>
    <!-- ── Tab switcher ──────────────────────────────────────────────────── -->
    <div class="mode-tab-bar">
        <button type="button" class="mode-tab <?= $form['mode'] !== 'existing' ? 'active' : '' ?>"
                onclick="switchMode('new')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:.3rem"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Create New Staff Member
        </button>
        <button type="button" class="mode-tab <?= $form['mode'] === 'existing' ? 'active' : '' ?>"
                onclick="switchMode('existing')">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:.3rem"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Link Existing User
        </button>
    </div>

    <!-- ── NEW USER section ──────────────────────────────────────────────── -->
    <div class="mode-section <?= $form['mode'] !== 'existing' ? 'active' : '' ?>" id="sec-new">
        <div class="card" style="margin-bottom:1.25rem;max-width:680px">
            <div class="card-header"><span class="card-title">Account Details</span></div>
            <div class="card-body">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label" for="n_name">Full Name <span style="color:var(--danger)">*</span></label>
                        <input type="text" id="n_name" name="name" class="form-control"
                               value="<?= e($form['name']) ?>" placeholder="e.g. Rahim Mia" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="n_email">Email Address <span class="text-muted" style="font-weight:400">(optional)</span></label>
                        <input type="email" id="n_email" name="email" class="form-control"
                               value="<?= e($form['email']) ?>" placeholder="rahim@farm.com" autocomplete="off">
                        <span class="form-hint">Required for login access. Leave blank for tracking-only staff.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="n_phone">Phone Number <span class="text-muted" style="font-weight:400">(optional)</span></label>
                        <input type="tel" id="n_phone" name="phone" class="form-control"
                               value="<?= e($form['phone']) ?>" placeholder="e.g. 01700000000" autocomplete="off">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label class="form-label" for="n_role">Role <span style="color:var(--danger)">*</span></label>
                        <select id="n_role" name="role" class="form-control">
                            <?php foreach ($staff_roles as $rkey => $rlabel): ?>
                            <option value="<?= e($rkey) ?>" <?= $form['role'] === $rkey ? 'selected' : '' ?>>
                                <?= e($rlabel) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="pwdGroup">
                        <label class="form-label" for="n_password">Password <span id="pwdRequired" style="color:var(--danger)"></span></label>
                        <input type="password" id="n_password" name="password" class="form-control"
                               placeholder="Min. 10 characters" autocomplete="new-password">
                        <div class="pwd-hint" id="pwdHint">Required when email is provided. Min. 10 characters.</div>
                    </div>
                    <div class="form-group" id="confirmGroup">
                        <label class="form-label" for="n_confirm">Confirm Password</label>
                        <input type="password" id="n_confirm" name="confirm" class="form-control"
                               placeholder="Repeat password" autocomplete="new-password">
                        <span class="form-error" id="confirmErr" style="color:var(--danger);font-size:.8rem"></span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ── EXISTING USER section ─────────────────────────────────────────── -->
    <div class="mode-section <?= $form['mode'] === 'existing' ? 'active' : '' ?>" id="sec-existing">
        <div class="card" style="margin-bottom:1.25rem;max-width:680px">
            <div class="card-header"><span class="card-title">Select Existing User</span></div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label" for="user_id">User Account</label>
                    <select id="user_id" name="user_id" class="form-control">
                        <option value="">— Select user —</option>
                        <?php foreach ($available_users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (int)$form['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= e($u['name']) ?> (<?= e($u['email']) ?>) — <?= e(ucfirst($u['role'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($available_users)): ?>
                    <span class="form-hint" style="color:var(--warning)">
                        No eligible users available. Use "Create New Staff Member" tab instead.
                    </span>
                    <?php else: ?>
                    <span class="form-hint">Only active users without an existing worker profile are listed.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ── Edit: show linked user (read-only) ────────────────────────────── -->
    <div class="card" style="margin-bottom:1.25rem;max-width:680px">
        <div class="card-header"><span class="card-title">Linked User Account</span></div>
        <div class="card-body">
            <div style="padding:.6rem .9rem;background:var(--bg-sidebar);border:1px solid var(--border);border-radius:var(--radius);font-size:.875rem">
                <strong><?= e($worker_user['name']) ?></strong>
                <span class="text-muted"> — <?= e($worker_user['email']) ?></span>
                <span class="role-badge role-<?= e($worker_user['role']) ?>" style="margin-left:.5rem">
                    <?= e(ucfirst(str_replace('_',' ',$worker_user['role']))) ?>
                </span>
            </div>
            <span class="form-hint">To change the user, delete this profile and create a new one.</span>
        </div>
    </div>
<?php endif; ?>

    <!-- ── Worker profile fields (shared for all modes) ──────────────────── -->
    <div class="card" style="margin-bottom:1.25rem;max-width:680px">
        <div class="card-header"><span class="card-title">Employment Details</span></div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="salary">Monthly Salary (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="salary" name="salary" class="form-control"
                           value="<?= e($form['salary']) ?>" step="0.01" min="0" placeholder="e.g. 15000" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="w_status">Status</label>
                    <select id="w_status" name="status" class="form-control">
                        <option value="active"     <?= $form['status'] === 'active'     ? 'selected' : '' ?>>Active</option>
                        <option value="inactive"   <?= $form['status'] === 'inactive'   ? 'selected' : '' ?>>Inactive</option>
                        <option value="terminated" <?= $form['status'] === 'terminated' ? 'selected' : '' ?>>Terminated</option>
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
            <?= $is_edit ? 'Save Changes' : 'Create Worker' ?>
        </button>
        <a href="/modules/workers/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<script>
'use strict';
function switchMode(m) {
    document.getElementById('modeInput').value = m;
    document.querySelectorAll('.mode-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.mode-section').forEach(function(s) { s.classList.remove('active'); });
    document.querySelector('[onclick="switchMode(\'' + m + '\')"]').classList.add('active');
    var sec = document.getElementById('sec-' + m);
    if (sec) sec.classList.add('active');
}

// Show/hide password fields based on whether email is filled
var emailField = document.getElementById('n_email');
var pwdGroup   = document.getElementById('pwdGroup');
var confGroup  = document.getElementById('confirmGroup');
var pwdReq     = document.getElementById('pwdRequired');
var pwdHint    = document.getElementById('pwdHint');

function togglePwdFields() {
    if (!emailField) return;
    var hasEmail = emailField.value.trim() !== '';
    if (pwdGroup)  pwdGroup.style.opacity  = hasEmail ? '1' : '.45';
    if (confGroup) confGroup.style.opacity = hasEmail ? '1' : '.45';
    if (pwdReq)    pwdReq.textContent      = hasEmail ? '*' : '';
    if (pwdHint)   pwdHint.textContent     = hasEmail
        ? 'Required when email is provided. Min. 10 characters.'
        : 'Not needed — this staff member will not have login access.';
}
if (emailField) {
    emailField.addEventListener('input', togglePwdFields);
    togglePwdFields(); // run on page load
}

// Confirm password live check
var pwd  = document.getElementById('n_password');
var conf = document.getElementById('n_confirm');
if (pwd && conf) {
    conf.addEventListener('input', function() {
        var err = document.getElementById('confirmErr');
        if (err) err.textContent = (this.value && this.value !== pwd.value) ? 'Passwords do not match.' : '';
    });
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
