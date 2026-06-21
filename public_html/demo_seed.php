<?php
/**
 * DEMO SEED — creates one test account per role with known passwords.
 * ⚠  DELETE THIS FILE immediately after use. Never leave on production.
 *
 * Visit: /demo_seed.php  → click "Create Demo Accounts"
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Abort if already seeded
$existing = $db->query("SELECT COUNT(*) FROM users WHERE email LIKE 'demo.%@cowapp.test'")->fetchColumn();

$done    = [];
$errors  = [];
$already = (int)$existing > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already) {

    // ── 1. Find/create a demo farm ──────────────────────────────────────────
    $farm = $db->query("SELECT id FROM farms WHERE farm_code='DEMO-00001' LIMIT 1")->fetch();
    if (!$farm) {
        $db->prepare("INSERT INTO farms (farm_name, location, farm_code, status) VALUES (?,?,?,?)")
           ->execute(['Demo Farm', 'Dhaka, Bangladesh', 'DEMO-00001', 'active']);
        $farm_id = (int)$db->lastInsertId();

        // Link to Free plan subscription
        $db->prepare("INSERT INTO subscriptions (farm_id, plan_id, start_date, status) VALUES (?,1,CURDATE(),'trial')")
           ->execute([$farm_id]);
    } else {
        $farm_id = (int)$farm['id'];
    }

    // ── 2. Demo accounts ────────────────────────────────────────────────────
    $accounts = [
        // [role,             email,                          name,              password,       is_owner]
        ['superadmin',  'demo.ceo@cowapp.test',         'CEO (Demo)',       'CeoPass@123',   0],
        ['support_staff','demo.support@cowapp.test',    'Support (Demo)',   'Support@123',   0],
        ['admin',       'demo.admin@cowapp.test',       'Admin (Demo)',     'Admin@123',     1],
        ['manager',     'demo.manager@cowapp.test',     'Manager (Demo)',   'Manager@123',   0],
        ['accountant',  'demo.accountant@cowapp.test',  'Accountant (Demo)','Account@123',  0],
        ['veterinarian','demo.vet@cowapp.test',         'Vet (Demo)',       'Vet@12345',     0],
        ['worker',      'demo.worker@cowapp.test',      'Worker (Demo)',    'Worker@123',    0],
    ];

    foreach ($accounts as [$role, $email, $name, $pass, $is_owner]) {
        $chk = $db->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->fetch()) { $done[] = ['role'=>$role,'email'=>$email,'pass'=>$pass,'skip'=>true]; continue; }

        $hash        = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $platformRole = in_array($role, ['superadmin','support_staff']);

        if ($platformRole) {
            $db->prepare(
                "INSERT INTO users (name, email, password_hash, role, status) VALUES (?,?,?,?,'active')"
            )->execute([$name, $email, $hash, $role]);
        } else {
            $db->prepare(
                "INSERT INTO users (farm_id, is_owner, name, email, password_hash, role, status)
                 VALUES (?,?,?,?,?,?,'active')"
            )->execute([$farm_id, $is_owner, $name, $email, $hash, $role]);
        }

        $done[] = ['role'=>$role,'email'=>$email,'pass'=>$pass,'skip'=>false];
    }

    // Set demo admin as farm owner
    $owner = array_filter($done, fn($a) => $a['role']==='admin');
    if ($owner) {
        $oid = $db->query("SELECT id FROM users WHERE email='demo.admin@cowapp.test' LIMIT 1")->fetchColumn();
        if ($oid) $db->prepare("UPDATE farms SET owner_user_id=? WHERE id=?")->execute([$oid, $farm_id]);
    }
}

$role_colors = [
    'superadmin'   => '#7c3aed',
    'support_staff'=> '#0284c7',
    'admin'        => '#16a34a',
    'manager'      => '#d97706',
    'accountant'   => '#0891b2',
    'veterinarian' => '#dc2626',
    'worker'       => '#6b7280',
];
$role_labels = [
    'superadmin'   => 'CEO / Super Admin',
    'support_staff'=> 'Support Staff',
    'admin'        => 'Farm Admin (Owner)',
    'manager'      => 'Manager',
    'accountant'   => 'Accountant',
    'veterinarian' => 'Veterinarian',
    'worker'       => 'Worker',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Demo Seed — Cow Management</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;min-height:100vh;padding:2rem 1rem}
.wrap{max-width:720px;margin:0 auto}
h1{font-size:1.4rem;font-weight:800;color:#111827;margin-bottom:.25rem}
.sub{font-size:.85rem;color:#6b7280;margin-bottom:2rem}
.warn{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:.75rem 1rem;border-radius:8px;font-size:.85rem;margin-bottom:1.5rem;font-weight:600}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);overflow:hidden;margin-bottom:1.5rem}
table{width:100%;border-collapse:collapse;font-size:.85rem}
thead tr{background:#f9fafb;border-bottom:2px solid #e5e7eb}
th{padding:.6rem 1rem;text-align:left;font-weight:700;color:#374151;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
td{padding:.65rem 1rem;border-bottom:1px solid #f3f4f6;color:#1f2937}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.7rem;font-weight:700;color:#fff}
.copy{font-family:monospace;background:#f3f4f6;padding:.15rem .4rem;border-radius:4px;font-size:.82rem;color:#1f2937;cursor:pointer;border:1px solid #e5e7eb}
.copy:hover{background:#e5e7eb}
.btn{display:inline-block;background:#dc2626;color:#fff;border:none;padding:.65rem 1.5rem;border-radius:8px;font-size:.9rem;font-weight:700;cursor:pointer;text-decoration:none;margin-top:1rem}
.btn:hover{background:#b91c1c}
.btn-green{background:#16a34a}.btn-green:hover{background:#15803d}
.new-tag{background:#dcfce7;color:#15803d;font-size:.68rem;font-weight:700;padding:.1rem .35rem;border-radius:4px;margin-left:.4rem}
.skip-tag{background:#fef3c7;color:#92400e;font-size:.68rem;font-weight:700;padding:.1rem .35rem;border-radius:4px;margin-left:.4rem}
.login-link{display:inline-block;margin-top:1.5rem;font-size:.85rem;color:#4b5563}
.login-link a{color:#2D6A4F;font-weight:700}
.farm-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;padding:.65rem 1rem;border-radius:8px;font-size:.83rem;margin-bottom:1rem}
</style>
</head>
<body>
<div class="wrap">
    <h1>🐄 Demo Account Seed</h1>
    <p class="sub">Creates one test login per role. Delete this file after use.</p>

    <div class="warn">⚠ SECURITY WARNING — This file exposes plain-text passwords. Delete <code>demo_seed.php</code> from your server immediately after use.</div>

    <?php if ($already && empty($done)): ?>
    <div class="card" style="padding:1.25rem">
        <p style="color:#16a34a;font-weight:700;margin-bottom:.5rem">✓ Demo accounts already exist.</p>
        <p style="font-size:.85rem;color:#6b7280">Use the table below to log in, or scroll down to delete and re-seed.</p>
    </div>
    <?php endif; ?>

    <?php if (!empty($done)): ?>
    <div class="farm-info">🏠 Demo Farm: <strong>DEMO-00001</strong> &nbsp;·&nbsp; Farm ID: <strong><?= $farm_id ?? '—' ?></strong></div>

    <div class="card">
        <table>
            <thead>
                <tr><th>Role</th><th>Email</th><th>Password</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($done as $a): ?>
            <tr>
                <td>
                    <span class="badge" style="background:<?= $role_colors[$a['role']] ?? '#6b7280' ?>"><?= $role_labels[$a['role']] ?? $a['role'] ?></span>
                    <?= $a['skip'] ? '<span class="skip-tag">already existed</span>' : '<span class="new-tag">created</span>' ?>
                </td>
                <td><code class="copy" onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($a['email']) ?></code></td>
                <td><code class="copy" onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($a['pass']) ?></code></td>
                <td><a href="/index.php" style="font-size:.75rem;color:#2D6A4F;font-weight:600">→ Login</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="font-size:.8rem;color:#6b7280;margin-bottom:.5rem">💡 Click any email or password to copy it. Farm Code login: use <strong>DEMO-00001</strong></p>
    <p class="login-link">→ <a href="/index.php">Go to Login Page</a></p>

    <?php elseif (!$already): ?>
    <div class="card" style="padding:1.5rem">
        <p style="font-size:.9rem;color:#374151;margin-bottom:1rem">This will create <strong>7 demo accounts</strong> (one per role) on a new "Demo Farm".</p>
        <form method="POST">
            <button type="submit" class="btn btn-green">Create Demo Accounts</button>
        </form>
    </div>
    <?php endif; ?>

    <?php
    // Show existing demo accounts if already seeded
    if ($already):
        $rows = $db->query("SELECT name, email, role FROM users WHERE email LIKE 'demo.%@cowapp.test' ORDER BY FIELD(role,'superadmin','support_staff','admin','manager','accountant','veterinarian','worker')")->fetchAll();
        $pwds = [
            'demo.ceo@cowapp.test'        => 'CeoPass@123',
            'demo.support@cowapp.test'    => 'Support@123',
            'demo.admin@cowapp.test'      => 'Admin@123',
            'demo.manager@cowapp.test'    => 'Manager@123',
            'demo.accountant@cowapp.test' => 'Account@123',
            'demo.vet@cowapp.test'        => 'Vet@12345',
            'demo.worker@cowapp.test'     => 'Worker@123',
        ];
    ?>
    <div class="card">
        <table>
            <thead><tr><th>Role</th><th>Email</th><th>Password</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><span class="badge" style="background:<?= $role_colors[$r['role']] ?? '#6b7280' ?>"><?= $role_labels[$r['role']] ?? $r['role'] ?></span></td>
                <td><code class="copy" onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($r['email']) ?></code></td>
                <td><code class="copy" onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($pwds[$r['email']] ?? '(custom)') ?></code></td>
                <td><a href="/index.php" style="font-size:.75rem;color:#2D6A4F;font-weight:600">→ Login</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="font-size:.8rem;color:#6b7280">Farm Code login: <strong>DEMO-00001</strong></p>
    <p class="login-link">→ <a href="/index.php">Go to Login Page</a></p>
    <?php endif; ?>

</div>
</body>
</html>
