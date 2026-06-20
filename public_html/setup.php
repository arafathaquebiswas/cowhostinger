<?php
/**
 * One-time setup script — creates the first admin user.
 * DELETE THIS FILE after running it successfully.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Block if an admin already exists
$check = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ((int)$check > 0) {
    die('<p style="font-family:sans-serif;color:red;padding:2rem">
        Setup has already been completed. An admin account exists.<br>
        <strong>Delete this file (setup.php) from your server.</strong>
    </p>');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password']  ?? '';
    $confirm  = $_POST['confirm']   ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $db->prepare(
            'INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $hash, 'admin', 'active']);
        $success = 'Admin account created successfully! You can now <a href="/index.php">log in</a>.<br>
                    <strong style="color:red">IMPORTANT: Delete this file (setup.php) from your server immediately.</strong>';
    }
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
    <p>Create the first administrator account for the Cow Management System.</p>

    <div class="warning">
        ⚠️ Run this setup only once. Delete <code>setup.php</code> from your server after completion.
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert-success"><?= $success ?></div>
    <?php else: ?>
        <form method="POST">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" placeholder="Farm Administrator" required
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" placeholder="admin@yourfarm.com" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

            <label for="password">Password <small>(min. 8 characters)</small></label>
            <input type="password" id="password" name="password" placeholder="••••••••" required>

            <label for="confirm">Confirm Password</label>
            <input type="password" id="confirm" name="confirm" placeholder="••••••••" required>

            <button type="submit">Create Admin Account</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
