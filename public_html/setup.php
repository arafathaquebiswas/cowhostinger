<?php
/**
 * One-time setup script — creates the first superadmin/admin user.
 *
 * SECURITY: This file is protected by a two-layer lock:
 *   Layer 1 — Lockfile: once setup completes, a .setup_complete file is
 *             written one level ABOVE public_html (outside the web root).
 *             Even if the DB is wiped, the lockfile blocks re-entry.
 *   Layer 2 — DB check: if any admin user already exists, block access.
 *
 * After running: delete this file OR leave the lockfile in place.
 */

// ── Lockfile path (outside web root) ─────────────────────────────────────────
$LOCK_FILE = dirname(__DIR__) . '/.setup_complete';

// Layer 1 — lockfile check (survives DB wipes)
if (file_exists($LOCK_FILE)) {
    http_response_code(403);
    die(setupHtml('403 — Already Set Up',
        '<p style="color:#dc2626;font-weight:700">Setup has already been completed and is permanently locked.</p>
         <p style="margin-top:.5rem">Delete <code>setup.php</code> from your server.</p>'
    ));
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Layer 2 — DB check (belt-and-suspenders)
$admin_count = (int)$db->query("SELECT COUNT(*) FROM users WHERE role IN ('superadmin','admin')")->fetchColumn();
if ($admin_count > 0) {
    http_response_code(403);
    die(setupHtml('Setup Already Complete',
        '<p style="color:#dc2626;font-weight:700">An admin account already exists.</p>
         <p style="margin-top:.5rem"><strong>Delete <code>setup.php</code> from your server immediately.</strong></p>'
    ));
}

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password']  ?? '';
    $confirm  = $_POST['confirm']   ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare(
            'INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $hash, 'admin', 'active']);

        // Write lockfile OUTSIDE web root — permanently blocks future setup runs
        @file_put_contents($LOCK_FILE,
            'Setup completed at ' . date('Y-m-d H:i:s') . ' — admin: ' . $email . PHP_EOL,
            LOCK_EX
        );

        $success = true;
    }
}

// ── Helper: minimal page wrapper ──────────────────────────────────────────────
function setupHtml(string $title, string $body): string {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>' . htmlspecialchars($title) . '</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f4f8;margin:0}
    .box{background:#fff;padding:2rem;border-radius:10px;max-width:480px;width:100%;box-shadow:0 4px 20px rgba(0,0,0,.1)}
    h1{color:#1B4332;margin-bottom:1rem}code{background:#f3f4f6;padding:.1rem .4rem;border-radius:4px;font-size:.9em}</style>
    </head><body><div class="box"><h1>' . htmlspecialchars($title) . '</h1>' . $body . '</div></body></html>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Setup</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f4f8; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 1.5rem;
        }
        .card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.1);
            padding: 2.5rem 2rem; width: 100%; max-width: 440px;
        }
        h1 { font-size: 1.4rem; color: #1B4332; margin-bottom: .25rem; }
        p  { color: #6b7280; font-size: .875rem; margin-bottom: 1.5rem; }
        label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .35rem; color: #374151; }
        input {
            width: 100%; padding: .6rem .85rem; border: 1.5px solid #d1d5db;
            border-radius: 6px; font-size: .9rem; margin-bottom: 1.1rem; outline: none;
        }
        input:focus { border-color: #2D6A4F; box-shadow: 0 0 0 3px rgba(45,106,79,.12); }
        button {
            width: 100%; padding: .7rem; background: #2D6A4F; color: #fff;
            border: none; border-radius: 6px; font-size: .95rem; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #1B4332; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: .75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem; font-size: .875rem; }
        .alert-success { background: #f0fdf4; border: 1px solid #a7f3d0; color: #065f46; padding: .75rem 1rem; border-radius: 6px; font-size: .875rem; }
        .warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: .65rem 1rem; border-radius: 6px; font-size: .8rem; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>System Setup</h1>
    <p>Create the first administrator account. This form can only be used once.</p>

    <div class="warning">
        ⚠️ After setup completes, this page is permanently locked. Delete <code>setup.php</code> from your server.
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert-success">
            ✓ Admin account created. Setup is now permanently locked.<br><br>
            <strong style="color:#dc2626">Delete setup.php from your server immediately.</strong><br><br>
            <a href="/login.php" style="color:#065f46;font-weight:600">→ Go to Login</a>
        </div>
    <?php else: ?>
        <form method="POST">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" placeholder="Farm Administrator" required
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autocomplete="off">

            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" placeholder="admin@yourfarm.com" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="off">

            <label for="password">Password <small>(min. 10 characters)</small></label>
            <input type="password" id="password" name="password" placeholder="••••••••••" required autocomplete="new-password">

            <label for="confirm">Confirm Password</label>
            <input type="password" id="confirm" name="confirm" placeholder="••••••••••" required autocomplete="new-password">

            <button type="submit">Create Admin Account</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
