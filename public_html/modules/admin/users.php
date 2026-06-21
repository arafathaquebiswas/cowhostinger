<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireRole(['admin']);

$page_title = 'User Management';
$active_nav = 'admin_users';

$db      = getDB();
$errors  = [];
$success = '';
$edit_user = null;

// ── Handle POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!verifyCsrfToken($token)) {
        flashMessage('error', 'Invalid request. Please try again.');
        redirect('/modules/admin/users.php');
    }

    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    // ── Toggle status ──────────────────────────────────────────
    if ($action === 'toggle_status' && $user_id) {
        if ($user_id === (int)$_SESSION['user_id']) {
            flashMessage('error', 'You cannot deactivate your own account.');
            redirect('/modules/admin/users.php');
        }
        $stmt = $db->prepare("SELECT status FROM users WHERE id = ? AND " . farmFilter());
        $stmt->execute([$user_id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            flashMessage('error', 'User not found.');
            redirect('/modules/admin/users.php');
        }
        $new_status = ($current === 'active') ? 'inactive' : 'active';
        $db->prepare("UPDATE users SET status = ? WHERE id = ? AND " . farmFilter())->execute([$new_status, $user_id]);
        auditLog((int)$_SESSION['user_id'], 'USER_STATUS_TOGGLE', 'users', $user_id, ['status' => $current], ['status' => $new_status]);
        flashMessage('success', 'User status updated to ' . $new_status . '.');
        redirect('/modules/admin/users.php');
    }

    // ── Delete user ────────────────────────────────────────────
    if ($action === 'delete' && $user_id) {
        if ($user_id === (int)$_SESSION['user_id']) {
            flashMessage('error', 'You cannot delete your own account.');
            redirect('/modules/admin/users.php');
        }
        $db->prepare("DELETE FROM users WHERE id = ? AND " . farmFilter())->execute([$user_id]);
        auditLog((int)$_SESSION['user_id'], 'DELETE_USER', 'users', $user_id);
        flashMessage('success', 'User deleted.');
        redirect('/modules/admin/users.php');
    }

    // ── Add / Edit user ────────────────────────────────────────
    if ($action === 'add' && !farmCanAddUser()) {
        $lim = farmResourceLimit('users');
        flashMessage('error', "User limit reached ({$lim['current']}/{$lim['max']}). Upgrade your plan to add more users.");
        redirect('/modules/admin/users.php');
    }

    if (in_array($action, ['add', 'edit'], true)) {
        $name     = sanitize($_POST['name']     ?? '');
        $email    = strtolower(sanitize($_POST['email'] ?? ''));
        $role     = $_POST['role']     ?? '';
        $status   = $_POST['status']   ?? 'active';
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm']  ?? '';

        $allowed_roles    = ['admin','worker','accountant','veterinarian','reception'];
        $allowed_statuses = ['active','inactive'];

        if ($name === '')                                    $errors[] = 'Name is required.';
        if ($email === '')                                   $errors[] = 'Email is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
        if (!in_array($role, $allowed_roles, true))          $errors[] = 'Invalid role selected.';
        if (!in_array($status, $allowed_statuses, true))     $errors[] = 'Invalid status selected.';

        if ($action === 'add') {
            if ($password === '')              $errors[] = 'Password is required.';
            elseif (strlen($password) < 8)    $errors[] = 'Password must be at least 8 characters.';
            elseif ($password !== $confirm)   $errors[] = 'Passwords do not match.';
        } elseif ($password !== '') {
            if (strlen($password) < 8)        $errors[] = 'New password must be at least 8 characters.';
            elseif ($password !== $confirm)   $errors[] = 'Passwords do not match.';
        }

        // Check email uniqueness
        if (empty($errors)) {
            $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $action === 'edit' ? $user_id : 0]);
            if ($chk->fetch()) $errors[] = 'That email address is already in use.';
        }

        if (empty($errors)) {
            if ($action === 'add') {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("INSERT INTO users (farm_id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)");
                $stmt->execute([fid(), $name, $email, $hash, $role, $status]);
                $new_id = (int)$db->lastInsertId();
                auditLog((int)$_SESSION['user_id'], 'CREATE_USER', 'users', $new_id, null, ['name'=>$name,'email'=>$email,'role'=>$role]);
                flashMessage('success', "User \"{$name}\" created successfully.");
                redirect('/modules/admin/users.php');
            } else {
                // Edit
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $db->prepare("UPDATE users SET name=?, email=?, password_hash=?, role=?, status=? WHERE id=? AND " . farmFilter());
                    $stmt->execute([$name, $email, $hash, $role, $status, $user_id]);
                } else {
                    $stmt = $db->prepare("UPDATE users SET name=?, email=?, role=?, status=? WHERE id=? AND " . farmFilter());
                    $stmt->execute([$name, $email, $role, $status, $user_id]);
                }
                auditLog((int)$_SESSION['user_id'], 'UPDATE_USER', 'users', $user_id);
                flashMessage('success', "User \"{$name}\" updated successfully.");
                redirect('/modules/admin/users.php');
            }
        }

        // Repopulate on error
        $edit_user = ['id'=>$user_id,'name'=>$name,'email'=>$email,'role'=>$role,'status'=>$status];
    }
}

// Load edit user if requested
if ($_GET['edit'] ?? false) {
    $stmt = $db->prepare("SELECT id, name, email, role, status FROM users WHERE id = ? AND " . farmFilter());
    $stmt->execute([(int)$_GET['edit']]);
    $edit_user = $stmt->fetch() ?: null;
}

// Pagination + search
$search   = sanitize($_GET['q']    ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;

$where  = [farmFilter()];
$params = [];
if ($search !== '') {
    $where[]  = '(name LIKE ? OR email LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$where_sql = implode(' AND ', $where);

$cnt_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE {$where_sql}");
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pager = paginate($total, $per_page, $page);

$list_params = array_merge($params, [$per_page, $pager['offset']]);
$list_stmt   = $db->prepare(
    "SELECT id, name, email, role, status, created_at FROM users
     WHERE {$where_sql} ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
$list_stmt->execute($list_params);
$users = $list_stmt->fetchAll();

$role_labels = [
    'admin'        => 'Admin',
    'worker'       => 'Worker',
    'accountant'   => 'Accountant',
    'veterinarian' => 'Veterinarian',
    'reception'    => 'Reception',
];

$show_form = !empty($errors) || !empty($_GET['add']) || !empty($_GET['edit']);

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>User Management</h2>
        <p class="text-sm text-muted"><?= number_format($total) ?> user<?= $total !== 1 ? 's' : '' ?> registered</p>
    </div>
    <a href="?add=1" class="btn btn-primary">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add User
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem" role="alert">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
        <?php foreach ($errors as $err): ?>
        <div><?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Add/Edit Form -->
<?php if ($show_form): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header">
        <span class="card-title"><?= $edit_user && $edit_user['id'] ? 'Edit User' : 'Add New User' ?></span>
        <a href="/modules/admin/users.php" class="btn btn-secondary btn-sm">Cancel</a>
    </div>
    <div class="card-body">
        <form method="POST" action="/modules/admin/users.php" novalidate id="userForm">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="<?= ($edit_user && $edit_user['id']) ? 'edit' : 'add' ?>">
            <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?? 0 ?>">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="u_name">Full Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="u_name" name="name" class="form-control"
                           value="<?= e($edit_user['name'] ?? '') ?>" placeholder="John Smith" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="u_email">Email Address <span style="color:var(--danger)">*</span></label>
                    <input type="email" id="u_email" name="email" class="form-control"
                           value="<?= e($edit_user['email'] ?? '') ?>" placeholder="john@farm.com" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="u_role">Role <span style="color:var(--danger)">*</span></label>
                    <select id="u_role" name="role" class="form-control" required>
                        <option value="">— Select role —</option>
                        <?php foreach ($role_labels as $rkey => $rlabel): ?>
                        <option value="<?= e($rkey) ?>"
                            <?= ($edit_user['role'] ?? '') === $rkey ? 'selected' : '' ?>>
                            <?= e($rlabel) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="u_status">Status</label>
                    <select id="u_status" name="status" class="form-control">
                        <option value="active"   <?= (($edit_user['status'] ?? 'active') === 'active')   ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($edit_user['status'] ?? 'active') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="u_password">
                        Password <?= ($edit_user && $edit_user['id']) ? '' : '<span style="color:var(--danger)">*</span>' ?>
                    </label>
                    <div class="input-group">
                        <input type="password" id="u_password" name="password" class="form-control"
                               placeholder="<?= ($edit_user && $edit_user['id']) ? 'Leave blank to keep current' : 'Minimum 8 characters' ?>"
                               <?= ($edit_user && $edit_user['id']) ? '' : 'required' ?>>
                        <button type="button" class="input-group-btn toggle-pwd-btn" data-target="u_password" aria-label="Toggle">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div class="password-strength" id="strengthBars">
                        <div class="strength-bar" id="sb1"></div>
                        <div class="strength-bar" id="sb2"></div>
                        <div class="strength-bar" id="sb3"></div>
                    </div>
                    <span class="form-hint" id="strengthLabel"></span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="u_confirm">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" id="u_confirm" name="confirm" class="form-control"
                               placeholder="Repeat password">
                        <button type="button" class="input-group-btn toggle-pwd-btn" data-target="u_confirm" aria-label="Toggle">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <span class="form-error" id="confirmError"></span>
                </div>
            </div>

            <div class="d-flex gap-1">
                <button type="submit" class="btn btn-primary">
                    <?= ($edit_user && $edit_user['id']) ? 'Update User' : 'Create User' ?>
                </button>
                <a href="/modules/admin/users.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Search -->
<div class="filter-bar">
    <form method="GET" action="/modules/admin/users.php" class="search-input-wrap">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" name="q" class="form-control" placeholder="Search by name or email…"
               value="<?= e($search) ?>">
    </form>
    <?php if ($search !== ''): ?>
    <a href="/modules/admin/users.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
</div>

<!-- Users Table -->
<div class="card" style="margin-bottom:1rem">
    <?php if (empty($users)): ?>
    <div class="empty-state">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <h3>No users found</h3>
        <p><?= $search ? 'No users match "' . e($search) . '".' : 'No users have been created yet.' ?></p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $is_self   = $u['id'] === (int)$_SESSION['user_id'];
                $role_cls  = 'role-' . $u['role'];
                $initials  = strtoupper(substr($u['name'], 0, 1));
            ?>
            <tr>
                <td>
                    <div class="d-flex align-center gap-1">
                        <div class="user-avatar-sm"><?= e($initials) ?></div>
                        <span class="fw-600"><?= e($u['name']) ?></span>
                        <?php if ($is_self): ?>
                        <span class="badge badge-blue" style="font-size:.65rem">You</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="text-muted text-sm"><?= e($u['email']) ?></td>
                <td><span class="role-badge <?= $role_cls ?>"><?= e($role_labels[$u['role']] ?? ucfirst($u['role'])) ?></span></td>
                <td>
                    <?php if ($u['status'] === 'active'): ?>
                    <span class="badge badge-green">Active</span>
                    <?php else: ?>
                    <span class="badge badge-red">Inactive</span>
                    <?php endif; ?>
                </td>
                <td class="text-sm text-muted"><?= e(formatDate($u['created_at'])) ?></td>
                <td>
                    <div class="table-actions">
                        <a href="?edit=<?= $u['id'] ?>" class="btn btn-sm btn-secondary" title="Edit user">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <?php if (!$is_self): ?>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="toggle_status">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-warning"
                                    title="<?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <?php if ($u['status'] === 'active'): ?>
                                    <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                                    <?php else: ?>
                                    <polyline points="20 6 9 17 4 12"/>
                                    <?php endif; ?>
                                </svg>
                            </button>
                        </form>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Delete user <?= addslashes(e($u['name'])) ?>? This cannot be undone.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete user">
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

<!-- Pagination -->
<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="?<?= http_build_query(['q'=>$search,'page'=>$pager['current_page']-1]) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p = max(1,$pager['current_page']-2); $p <= min($pager['total_pages'],$pager['current_page']+2); $p++): ?>
    <a href="?<?= http_build_query(['q'=>$search,'page'=>$p]) ?>"
       class="page-btn <?= $p===$pager['current_page'] ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="?<?= http_build_query(['q'=>$search,'page'=>$pager['current_page']+1]) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
'use strict';
// Password show/hide toggles
document.querySelectorAll('.toggle-pwd-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input = document.getElementById(this.dataset.target);
        if (input) input.type = input.type === 'password' ? 'text' : 'password';
    });
});

// Password strength meter
var pwdInput = document.getElementById('u_password');
var confirmInput = document.getElementById('u_confirm');
if (pwdInput) {
    pwdInput.addEventListener('input', function() {
        var val = this.value;
        var score = 0;
        if (val.length >= 8)                     score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val) || /[^A-Za-z0-9]/.test(val)) score++;

        var classes  = ['', 'active-weak', 'active-fair', 'active-strong'];
        var labels   = ['', 'Weak', 'Fair', 'Strong'];
        for (var i = 1; i <= 3; i++) {
            var bar = document.getElementById('sb' + i);
            if (bar) bar.className = 'strength-bar ' + (i <= score ? classes[score] : '');
        }
        var lbl = document.getElementById('strengthLabel');
        if (lbl) lbl.textContent = val.length > 0 ? 'Strength: ' + (labels[score] || '') : '';
    });
}

// Confirm password check
if (confirmInput) {
    confirmInput.addEventListener('input', function() {
        var err = document.getElementById('confirmError');
        if (!err) return;
        err.textContent = (pwdInput && this.value && this.value !== pwdInput.value)
            ? 'Passwords do not match.' : '';
    });
}

// Client-side form validation
var userForm = document.getElementById('userForm');
if (userForm) {
    userForm.addEventListener('submit', function(e) {
        var name  = document.getElementById('u_name').value.trim();
        var email = document.getElementById('u_email').value.trim();
        var role  = document.getElementById('u_role').value;
        var pwd   = pwdInput ? pwdInput.value : '';
        var conf  = confirmInput ? confirmInput.value : '';
        var isEdit = document.querySelector('[name="user_id"]').value > 0;

        var ok = true;
        if (!name)  { ok = false; }
        if (!email) { ok = false; }
        if (!role)  { ok = false; }
        if (!isEdit && !pwd)      { ok = false; }
        if (!isEdit && pwd.length < 8)  { ok = false; }
        if (pwd && pwd !== conf) { ok = false; }

        if (!ok) { e.preventDefault(); }
    });
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
