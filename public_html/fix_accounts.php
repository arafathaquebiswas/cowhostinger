<?php
/**
 * fix_accounts.php — Diagnose & fix demo account login issues.
 * DELETE THIS FILE after use.
 */
require_once __DIR__ . '/includes/db.php';

$db = getDB();

// ── Get actual columns in users table ────────────────────────────────────────
$cols_raw = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
$cols     = array_column($cols_raw, 'Field');

// ── Accounts to create ───────────────────────────────────────────────────────
$accounts = [
    ['role'=>'superadmin',    'name'=>'CEO Demo',        'email'=>'demo.ceo@cowapp.test',       'password'=>'CeoPass@123'],
    ['role'=>'support_staff', 'name'=>'Support Demo',    'email'=>'demo.support@cowapp.test',   'password'=>'Support@123'],
    ['role'=>'admin',         'name'=>'Admin Demo',      'email'=>'demo.admin@cowapp.test',     'password'=>'Admin@123'],
    ['role'=>'manager',       'name'=>'Manager Demo',    'email'=>'demo.manager@cowapp.test',   'password'=>'Manager@123'],
    ['role'=>'accountant',    'name'=>'Accountant Demo', 'email'=>'demo.accountant@cowapp.test','password'=>'Account@123'],
    ['role'=>'veterinarian',  'name'=>'Vet Demo',        'email'=>'demo.vet@cowapp.test',       'password'=>'Vet@12345'],
    ['role'=>'worker',        'name'=>'Worker Demo',     'email'=>'demo.worker@cowapp.test',    'password'=>'Worker@123'],
];

$log = [];

// ── Action: CREATE ────────────────────────────────────────────────────────────
if (isset($_POST['create'])) {

    // Get or create demo farm
    $farm_id = null;
    if (in_array('farm_id', $cols)) {
        $f = $db->query("SELECT id FROM farms WHERE farm_code='DEMO-00001' LIMIT 1")->fetch();
        if (!$f) {
            // Build INSERT based on what columns farms table has
            $fc = array_column($db->query("SHOW COLUMNS FROM farms")->fetchAll(PDO::FETCH_ASSOC),'Field');
            $fvals = ['farm_name'=>'Demo Farm','farm_code'=>'DEMO-00001'];
            if (in_array('status',    $fc)) $fvals['status']   = 'active';
            if (in_array('location',  $fc)) $fvals['location'] = 'Dhaka, Bangladesh';
            if (in_array('phone',     $fc)) $fvals['phone']    = '01700000000';
            $fsql = "INSERT INTO farms (".implode(',',array_keys($fvals)).") VALUES (".implode(',',array_fill(0,count($fvals),'?')).")";
            $db->prepare($fsql)->execute(array_values($fvals));
            $farm_id = (int)$db->lastInsertId();
            try { $db->prepare("INSERT INTO subscriptions (farm_id,plan_id,start_date,status) VALUES (?,1,CURDATE(),'trial')")->execute([$farm_id]); } catch(Throwable $e){}
        } else {
            $farm_id = (int)$f['id'];
        }
        $log[] = ['info', "Demo Farm ID: $farm_id (DEMO-00001)"];
    }

    foreach ($accounts as $a) {
        // Remove existing
        $db->prepare("DELETE FROM users WHERE email=?")->execute([$a['email']]);

        $hash = password_hash($a['password'], PASSWORD_BCRYPT, ['cost'=>12]);
        $isPlatform = in_array($a['role'], ['superadmin','support_staff']);

        // Build INSERT dynamically based on actual columns
        $insert = [
            'name'          => $a['name'],
            'email'         => $a['email'],
            'password_hash' => $hash,
            'role'          => $a['role'],
            'status'        => 'active',
        ];
        if (in_array('farm_id',  $cols) && !$isPlatform && $farm_id) $insert['farm_id']  = $farm_id;
        if (in_array('is_owner', $cols))  $insert['is_owner'] = ($a['role']==='admin' ? 1 : 0);
        if (in_array('phone',    $cols))  $insert['phone']    = null;

        $sql = "INSERT INTO users (".implode(',',array_keys($insert)).") VALUES (".implode(',',array_fill(0,count($insert),'?')).")";
        try {
            $db->prepare($sql)->execute(array_values($insert));
            $log[] = ['ok', "✓ Created: {$a['email']} / {$a['password']}"];
        } catch (Throwable $e) {
            $log[] = ['err', "✗ {$a['email']}: " . $e->getMessage()];
        }
    }

    // Set admin as farm owner
    if ($farm_id && in_array('owner_user_id', array_column($db->query("SHOW COLUMNS FROM farms")->fetchAll(PDO::FETCH_ASSOC),'Field'))) {
        $oid = $db->query("SELECT id FROM users WHERE email='demo.admin@cowapp.test' LIMIT 1")->fetchColumn();
        if ($oid) try { $db->prepare("UPDATE farms SET owner_user_id=? WHERE id=?")->execute([$oid,$farm_id]); } catch(Throwable $e){}
    }
}

// ── Action: TEST PASSWORD ─────────────────────────────────────────────────────
if (isset($_POST['test'])) {
    $email = trim($_POST['test_email'] ?? '');
    $pass  = trim($_POST['test_pass']  ?? '');
    $row   = $db->prepare("SELECT id,name,role,password_hash,status FROM users WHERE email=? LIMIT 1");
    $row->execute([$email]);
    $user = $row->fetch();
    if (!$user) {
        $log[] = ['err', "No user found with email: $email"];
    } else {
        $log[] = ['info', "Found user #{$user['id']} — name:{$user['name']} role:{$user['role']} status:{$user['status']}"];
        $ok = password_verify($pass, $user['password_hash']);
        $log[] = [$ok?'ok':'err', $ok ? "✓ Password correct!" : "✗ Password WRONG — hash in DB: " . substr($user['password_hash'],0,30) . "..."];
    }
}

// ── Read current demo users from DB ──────────────────────────────────────────
$existing = $db->query("SELECT id,name,email,role,status FROM users WHERE email LIKE 'demo.%@cowapp.test' ORDER BY id")->fetchAll();

$colors = ['superadmin'=>'#7c3aed','support_staff'=>'#0284c7','admin'=>'#16a34a','manager'=>'#d97706','accountant'=>'#0891b2','veterinarian'=>'#dc2626','worker'=>'#6b7280'];
$labels = ['superadmin'=>'CEO','support_staff'=>'Support','admin'=>'Admin','manager'=>'Manager','accountant'=>'Accountant','veterinarian'=>'Vet','worker'=>'Worker'];
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fix Demo Accounts</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;padding:1.5rem 1rem;color:#111827}
.w{max-width:800px;margin:0 auto}
h1{font-size:1.3rem;font-weight:800;margin-bottom:.15rem}
.sub{font-size:.82rem;color:#6b7280;margin-bottom:1.5rem}
.warn{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:.65rem 1rem;border-radius:8px;font-size:.82rem;margin-bottom:1rem;font-weight:600}
.card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);overflow:hidden;margin-bottom:1.25rem}
.card-hdr{padding:.75rem 1rem;font-weight:700;font-size:.85rem;border-bottom:1px solid #f3f4f6;background:#f9fafb}
.card-body{padding:1rem}
table{width:100%;border-collapse:collapse;font-size:.83rem}
th{padding:.5rem .8rem;text-align:left;font-weight:700;font-size:.74rem;color:#6b7280;text-transform:uppercase;border-bottom:2px solid #e5e7eb;background:#f9fafb}
td{padding:.55rem .8rem;border-bottom:1px solid #f3f4f6;vertical-align:middle}
tr:last-child td{border-bottom:none}
.badge{display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.7rem;font-weight:700;color:#fff}
code{font-family:monospace;background:#f3f4f6;padding:.12rem .4rem;border-radius:4px;font-size:.82rem;border:1px solid #e5e7eb;cursor:pointer;user-select:all}
.btn{background:#16a34a;color:#fff;border:none;padding:.6rem 1.4rem;border-radius:7px;font-size:.85rem;font-weight:700;cursor:pointer;transition:.15s}
.btn:hover{background:#15803d}
.btn-blue{background:#0284c7}.btn-blue:hover{background:#0369a1}
.log-ok{color:#15803d;font-size:.82rem;padding:.2rem 0}.log-err{color:#b91c1c;font-size:.82rem;padding:.2rem 0}.log-info{color:#374151;font-size:.82rem;padding:.2rem 0}
.cols{font-size:.75rem;color:#6b7280;font-family:monospace;margin-top:.4rem;word-break:break-all}
.input{width:100%;padding:.45rem .6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.83rem}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
</style>
</head>
<body><div class="w">
<h1>🔧 Fix Demo Accounts</h1>
<p class="sub">Diagnose and fix login issues. Delete this file after use.</p>
<div class="warn">⚠ DELETE <code>fix_accounts.php</code> from server after use.</div>

<!-- DB Columns Info -->
<div class="card">
    <div class="card-hdr">📋 users table columns detected</div>
    <div class="card-body">
        <div class="cols"><?= implode(', ', $cols) ?></div>
    </div>
</div>

<!-- Log output -->
<?php if (!empty($log)): ?>
<div class="card">
    <div class="card-hdr">📝 Result</div>
    <div class="card-body">
        <?php foreach ($log as [$t,$m]): ?>
        <div class="log-<?= $t ?>"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Current demo users in DB -->
<div class="card">
    <div class="card-hdr">👥 Demo users currently in database</div>
    <?php if (empty($existing)): ?>
    <div class="card-body" style="color:#b91c1c;font-size:.85rem">No demo accounts found — click Create below.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>ID</th><th>Role</th><th>Email</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($existing as $u): ?>
        <tr>
            <td style="color:#6b7280">#<?= $u['id'] ?></td>
            <td><span class="badge" style="background:<?= $colors[$u['role']]??'#6b7280' ?>"><?= $labels[$u['role']]??$u['role'] ?></span></td>
            <td><code><?= htmlspecialchars($u['email']) ?></code></td>
            <td style="color:<?= $u['status']==='active'?'#16a34a':'#dc2626' ?>"><?= $u['status'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Credentials table -->
<div class="card">
    <div class="card-hdr">🔑 Login Credentials</div>
    <table>
        <thead><tr><th>Role</th><th>Email (click=copy)</th><th>Password (click=copy)</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $a): ?>
        <tr>
            <td><span class="badge" style="background:<?= $colors[$a['role']]??'#6b7280' ?>"><?= $labels[$a['role']]??$a['role'] ?></span></td>
            <td><code onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($a['email']) ?></code></td>
            <td><code onclick="navigator.clipboard.writeText(this.textContent)"><?= htmlspecialchars($a['password']) ?></code></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Actions -->
<div class="card">
    <div class="card-hdr">⚡ Actions</div>
    <div class="card-body" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-start">
        <!-- Create -->
        <form method="POST">
            <button name="create" class="btn">🔄 (Re)Create All Demo Accounts</button>
            <p style="font-size:.75rem;color:#6b7280;margin-top:.35rem">Deletes old demo accounts and creates fresh ones</p>
        </form>

        <!-- Test specific login -->
        <form method="POST" style="flex:1;min-width:260px">
            <div class="row2">
                <input name="test_email" class="input" placeholder="Email to test" value="demo.admin@cowapp.test">
                <input name="test_pass"  class="input" placeholder="Password" value="Admin@123">
            </div>
            <button name="test" class="btn btn-blue" style="margin-top:.5rem;width:100%">🔍 Test This Login</button>
        </form>
    </div>
</div>

<p style="font-size:.8rem;color:#6b7280;margin-top:.5rem">
    After accounts work → <a href="/index.php" style="color:#2D6A4F;font-weight:700">Go to Login →</a>
    &nbsp;|&nbsp; Farm Code login: <strong>DEMO-00001</strong>
</p>

</div>
<script>
document.querySelectorAll('code[onclick]').forEach(function(el){
    el.title = 'Click to copy';
});
</script>
</body></html>
