<?php
require_once dirname(__DIR__, 2) . '/includes/saas_guard.php';
requireRole(['superadmin']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

$errors = [];

// ── POST: add / update / deactivate employee ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'CSRF error.');
        redirect('/modules/admin/employees.php');
    }

    $action = $_POST['action'] ?? '';

    // Add new support staff
    if ($action === 'add') {
        $name    = sanitize($_POST['name']  ?? '');
        $email   = strtolower(sanitize($_POST['email'] ?? ''));
        $phone   = sanitize($_POST['phone'] ?? '');
        $pwd     = $_POST['password'] ?? '';

        if ($name === '')                                     $errors[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))      $errors[] = 'Valid email is required.';
        if (strlen($pwd) < 8)                                $errors[] = 'Password must be at least 8 characters.';

        if (empty($errors)) {
            // Check email uniqueness
            $chk = $db->prepare("SELECT 1 FROM users WHERE email=? LIMIT 1");
            $chk->execute([$email]);
            if ($chk->fetchColumn()) {
                $errors[] = 'A user with this email already exists.';
            } else {
                $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare(
                    "INSERT INTO users (farm_id, name, email, phone, password_hash, role, status, is_owner)
                     VALUES (NULL,?,?,?,?,'support_staff','active',0)"
                )->execute([$name, $email, $phone ?: null, $hash]);
                $new_id = (int)$db->lastInsertId();
                logActivity('employee.created', ['employee_id' => $new_id, 'name' => $name]);
                auditLog($uid, 'EMPLOYEE_CREATED', 'users', $new_id);
                flashMessage('success', "Support staff '{$name}' added successfully.");
                redirect('/modules/admin/employees.php');
            }
        }
    }

    if ($action === 'toggle_status') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $current = $db->prepare("SELECT status FROM users WHERE id=? AND role='support_staff' LIMIT 1");
        $current->execute([$emp_id]);
        $row = $current->fetch();
        if ($row) {
            $new_status = $row['status'] === 'active' ? 'inactive' : 'active';
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new_status, $emp_id]);
            logActivity('employee.status_changed', ['employee_id' => $emp_id, 'status' => $new_status]);
            flashMessage('success', "Employee status set to: {$new_status}.");
        }
        redirect('/modules/admin/employees.php');
    }

    if ($action === 'reset_password') {
        $emp_id  = (int)($_POST['employee_id'] ?? 0);
        $new_pwd = $_POST['new_password'] ?? '';
        if (strlen($new_pwd) >= 8) {
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='support_staff'")
               ->execute([$hash, $emp_id]);
            logActivity('employee.password_reset', ['employee_id' => $emp_id]);
            flashMessage('success', 'Password reset successfully.');
        } else {
            flashMessage('error', 'Password must be at least 8 characters.');
        }
        redirect('/modules/admin/employees.php');
    }
}

// ── Load employees ────────────────────────────────────────────────────────────
$employees = $db->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM support_tickets t WHERE t.assigned_to=u.id) AS total_tickets,
            (SELECT COUNT(*) FROM support_tickets t WHERE t.assigned_to=u.id AND t.status IN ('open','in_progress')) AS open_tickets,
            (SELECT COUNT(*) FROM support_ticket_messages m JOIN support_tickets t ON t.id=m.ticket_id WHERE m.sender_id=u.id) AS total_replies,
            (SELECT MAX(created_at) FROM activity_log WHERE user_id=u.id) AS last_active
     FROM users u
     WHERE u.role='support_staff'
     ORDER BY u.status DESC, u.name ASC"
)->fetchAll();

$page_title = 'Employee Management';
$active_nav = 'employees';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>AB IT Team — Employee Management</h2>
        <p class="text-sm text-muted">Manage support staff accounts and monitor performance</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error" style="margin-bottom:1rem"><?php foreach ($errors as $e): echo e($e) . '<br>'; endforeach; ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start">

    <!-- Employee list -->
    <div>
        <div class="card">
            <div class="card-header">
                <span class="card-title">Support Staff (<?= count($employees) ?>)</span>
            </div>
            <?php if (empty($employees)): ?>
            <div style="padding:2rem;text-align:center;color:#9CA3AF">No support staff yet. Add your first employee →</div>
            <?php else: ?>
            <?php foreach ($employees as $emp): ?>
            <div style="padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:1rem">
                <!-- Avatar -->
                <div style="width:40px;height:40px;border-radius:50%;background:<?= $emp['status']==='active'?'#7C3AED':'#9CA3AF' ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0">
                    <?= strtoupper(substr($emp['name'], 0, 1)) ?>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                        <span style="font-weight:700;color:#111827"><?= e($emp['name']) ?></span>
                        <span class="badge <?= $emp['status']==='active'?'badge-green':'badge-red' ?>" style="font-size:.65rem">
                            <?= ucfirst($emp['status']) ?>
                        </span>
                    </div>
                    <div class="text-xs text-muted" style="margin-top:.1rem">
                        <?= e($emp['email']) ?>
                        <?php if ($emp['phone']): ?> &middot; <?= e($emp['phone']) ?><?php endif; ?>
                    </div>
                    <!-- Performance stats -->
                    <div style="display:flex;gap:1.5rem;margin-top:.5rem;font-size:.75rem;color:#6B7280">
                        <span>📋 <strong><?= (int)$emp['total_tickets'] ?></strong> total tickets</span>
                        <span>🔓 <strong><?= (int)$emp['open_tickets'] ?></strong> open</span>
                        <span>💬 <strong><?= (int)$emp['total_replies'] ?></strong> replies</span>
                        <span>🕐 <?= $emp['last_active'] ? date('d M', strtotime($emp['last_active'])) : 'Never' ?></span>
                    </div>
                </div>
                <div style="display:flex;flex-direction:column;gap:.35rem;min-width:110px">
                    <!-- Toggle status -->
                    <form method="POST" style="margin:0">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                        <button type="submit" class="btn btn-sm <?= $emp['status']==='active'?'btn-secondary':'btn-success' ?>" style="width:100%;font-size:.75rem">
                            <?= $emp['status']==='active' ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                    <!-- Reset password -->
                    <button type="button" class="btn btn-sm btn-secondary" style="font-size:.75rem"
                            onclick="document.getElementById('pwd-form-<?= $emp['id'] ?>').style.display='block';this.style.display='none'">
                        Reset Password
                    </button>
                    <form method="POST" id="pwd-form-<?= $emp['id'] ?>" style="display:none;margin:0">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                        <input type="password" name="new_password" class="form-control"
                               placeholder="New password" minlength="8" style="font-size:.75rem;padding:.3rem .4rem;margin-bottom:.3rem">
                        <button type="submit" class="btn btn-sm btn-primary" style="width:100%;font-size:.75rem">Set</button>
                    </form>
                    <a href="/modules/support/index.php?assigned_to=<?= $emp['id'] ?>" class="btn btn-sm btn-secondary" style="font-size:.75rem;text-align:center">
                        View Tickets
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add new employee form -->
    <div class="card">
        <div class="card-header"><span class="card-title">Add Support Staff</span></div>
        <div style="padding:1.25rem">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="form-label">Full Name <span style="color:#DC2626">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= e($_POST['name'] ?? '') ?>" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email <span style="color:#DC2626">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= e($_POST['email'] ?? '') ?>" maxlength="150" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= e($_POST['phone'] ?? '') ?>" maxlength="30"
                           placeholder="+880-XXX-XXXXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">Initial Password <span style="color:#DC2626">*</span></label>
                    <input type="password" name="password" class="form-control" minlength="8" required
                           placeholder="Min 8 characters">
                </div>
                <div style="background:#F0F9FF;border:1px solid #BAE6FD;border-radius:8px;padding:.75rem;margin-bottom:1rem;font-size:.78rem;color:#0369A1">
                    <strong>Role: Support Staff</strong><br>
                    Can view farm data (read-only), manage support tickets, and search farms. Cannot modify financial data or subscriptions.
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%">Add Employee</button>
            </form>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
