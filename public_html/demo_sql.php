<?php
/**
 * Shows ready-to-run SQL for creating all demo accounts.
 * Copy the SQL → paste into phpMyAdmin → Execute.
 * DELETE THIS FILE after use.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Generate fresh hashes
$accounts = [
    ['superadmin',    'demo.ceo@cowapp.test',       'CEO Demo',        'CeoPass@123'],
    ['support_staff', 'demo.support@cowapp.test',   'Support Demo',    'Support@123'],
    ['admin',         'demo.admin@cowapp.test',      'Admin Demo',      'Admin@123'],
    ['manager',       'demo.manager@cowapp.test',    'Manager Demo',    'Manager@123'],
    ['accountant',    'demo.accountant@cowapp.test', 'Accountant Demo', 'Account@123'],
    ['veterinarian',  'demo.vet@cowapp.test',        'Vet Demo',        'Vet@12345'],
    ['worker',        'demo.worker@cowapp.test',     'Worker Demo',     'Worker@123'],
];

$results  = [];
$farm_id  = null;
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $db->beginTransaction();
    try {
        // Create demo farm if not exists
        $f = $db->query("SELECT id FROM farms WHERE farm_code='DEMO-00001' LIMIT 1")->fetch();
        if ($f) {
            $farm_id = (int)$f['id'];
        } else {
            // Try with all columns, fall back to minimal if farm_id/phone not present
            try {
                $db->prepare("INSERT INTO farms (farm_name, location, phone, farm_code, status) VALUES (?,?,?,?,?)")
                   ->execute(['Demo Farm', 'Dhaka, Bangladesh', '01700000000', 'DEMO-00001', 'active']);
            } catch (Throwable $e) {
                $db->prepare("INSERT INTO farms (farm_name, farm_code) VALUES (?,?)")
                   ->execute(['Demo Farm', 'DEMO-00001']);
            }
            $farm_id = (int)$db->lastInsertId();
            try {
                $db->prepare("INSERT INTO subscriptions (farm_id,plan_id,start_date,status) VALUES (?,1,CURDATE(),'trial')")
                   ->execute([$farm_id]);
            } catch (Throwable $e) {}
        }

        foreach ($accounts as [$role, $email, $name, $pass]) {
            // Delete existing demo account first
            $db->prepare("DELETE FROM users WHERE email=?")->execute([$email]);

            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $isPlatform = in_array($role, ['superadmin', 'support_staff']);

            if ($isPlatform) {
                try {
                    $db->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?,?,?,?,'active')")
                       ->execute([$name, $email, $hash, $role]);
                } catch (Throwable $e) {
                    $db->prepare("INSERT INTO users (name, email, password_hash, role, status, farm_id, is_owner) VALUES (?,?,?,?,'active',NULL,0)")
                       ->execute([$name, $email, $hash, $role]);
                }
            } else {
                try {
                    $db->prepare("INSERT INTO users (farm_id, is_owner, name, email, password_hash, role, status) VALUES (?,0,?,?,?,?,'active')")
                       ->execute([$farm_id, $name, $email, $hash, $role]);
                } catch (Throwable $e) {
                    $db->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?,?,?,?,'active')")
                       ->execute([$name, $email, $hash, $role]);
                }
            }
            $results[] = ['role' => $role, 'email' => $email, 'pass' => $pass, 'ok' => true];
        }

        // Set admin as farm owner
        $adminId = $db->query("SELECT id FROM users WHERE email='demo.admin@cowapp.test' LIMIT 1")->fetchColumn();
        if ($adminId && $farm_id) {
            try { $db->prepare("UPDATE farms SET owner_user_id=? WHERE id=?")->execute([$adminId, $farm_id]); } catch(Throwable $e){}
        }

        $db->commit();
        $messages[] = ['ok', '✓ All demo accounts created successfully!'];
    } catch (Throwable $e) {
        $db->rollBack();
        $messages[] = ['err', 'Error: ' . $e->getMessage()];
    }
}

$role_colors = [
    'superadmin'    => '#7c3aed',
    'support_staff' => '#0284c7',
    'admin'         => '#16a34a',
    'manager'       => '#d97706',
    'accountant'    => '#0891b2',
    'veterinarian'  => '#dc2626',
    'worker'        => '#6b7280',
];
$role_labels = [
    'superadmin'    => 'CEO / Super Admin',
    'support_staff' => 'Support Staff',
    'admin'         => 'Farm Admin',
    'manager'       => 'Manager',
    'accountant'    => 'Accountant',
    'veterinarian'  => 'Veterinarian',
    'worker'        => 'Worker',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Demo Accounts</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;min-height:100vh;padding:2rem 1rem;color:#111827}
.wrap{max-width:750px;margin:0 auto}
h1{font-size:1.4rem;font-weight:800;margin-bottom:.2rem}
.sub{font-size:.85rem;color:#6b7280;margin-bottom:1.5rem}
.warn{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;margin-bottom:1.25rem;font-weight:600}
.msg-ok{background:#f0fdf4;border:1px solid #86efac;color:#15803d;padding:.7rem 1rem;border-radius:8px;font-size:.85rem;margin-bottom:1rem;font-weight:600}
.msg-err{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:.7rem 1rem;border-radius:8px;font-size:.85rem;margin-bottom:1rem}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);overflow:hidden;margin-bottom:1.5rem}
table{width:100%;border-collapse:collapse;font-size:.85rem}
thead tr{background:#f9fafb;border-bottom:2px solid #e5e7eb}
th{padding:.6rem 1rem;text-align:left;font-weight:700;color:#374151;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em}
td{padding:.65rem 1rem;border-bottom:1px solid #f3f4f6;vertical-align:middle}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.7rem;font-weight:700;color:#fff}
code{font-family:monospace;background:#f3f4f6;padding:.15rem .45rem;border-radius:4px;font-size:.83rem;border:1px solid #e5e7eb;cursor:pointer;user-select:all}
code:hover{background:#e5e7eb}
.btn{display:inline-block;background:#16a34a;color:#fff;border:none;padding:.7rem 1.75rem;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;transition:.15s}
.btn:hover{background:#15803d}
.note{font-size:.78rem;color:#6b7280;margin-top:.6rem}
</style>
</head>
<body>
<div class="wrap">
    <h1>🐄 Demo Accounts</h1>
    <p class="sub">Creates test logins for every role. Delete this file after use.</p>

    <div class="warn">⚠ Delete <code>demo_sql.php</code> from your server after use.</div>

    <?php foreach ($messages as [$type, $msg]): ?>
    <div class="<?= $type === 'ok' ? 'msg-ok' : 'msg-err' ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>

    <?php if (!empty($results)): ?>
    <div class="card">
        <table>
            <thead><tr><th>Role</th><th>Email (click to copy)</th><th>Password (click to copy)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($results as $a): ?>
            <tr>
                <td><span class="badge" style="background:<?= $role_colors[$a['role']] ?>"><?= $role_labels[$a['role']] ?></span></td>
                <td><code onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($a['email']) ?></code></td>
                <td><code onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($a['pass']) ?></code></td>
                <td style="color:#16a34a;font-size:.8rem;font-weight:700">✓</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="note">Farm Code login: <strong>DEMO-00001</strong> &nbsp;·&nbsp; <a href="/index.php" style="color:#2D6A4F;font-weight:700">→ Go to Login</a></p>

    <?php else: ?>
    <div class="card" style="padding:1.5rem">
        <p style="margin-bottom:1rem;font-size:.9rem;color:#374151">Click the button to create <strong>7 demo accounts</strong>, one per role. Existing demo accounts will be replaced.</p>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <button type="submit" class="btn">Create All Demo Accounts</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Always show the credentials table -->
    <?php if (empty($results)): ?>
    <div class="card">
        <table>
            <thead><tr><th>Role</th><th>Email</th><th>Password</th></tr></thead>
            <tbody>
            <?php foreach ($accounts as [$role, $email, $name, $pass]): ?>
            <tr>
                <td><span class="badge" style="background:<?= $role_colors[$role] ?>"><?= $role_labels[$role] ?></span></td>
                <td><code onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($email) ?></code></td>
                <td><code onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($pass) ?></code></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
