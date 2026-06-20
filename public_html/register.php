<?php
require_once __DIR__ . '/includes/auth.php';
startSecureSession();

if (isLoggedIn()) {
    redirect(getRoleRedirect(currentRole()));
}

$errors = [];
$form   = ['name' => '', 'email' => '', 'farm_name' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $name      = sanitize($_POST['name']      ?? '');
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $password  = $_POST['password']         ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';
        $farm_name = sanitize($_POST['farm_name'] ?? '');
        $phone     = sanitize($_POST['phone']     ?? '');

        $form = compact('name', 'email', 'farm_name', 'phone');

        if ($name === '') {
            $errors[] = 'Full name is required.';
        } elseif (strlen($name) > 100) {
            $errors[] = 'Full name must be 100 characters or less.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (strlen($email) > 150) {
            $errors[] = 'Email address is too long.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if ($farm_name !== '' && strlen($farm_name) > 150) {
            $errors[] = 'Farm name must be 150 characters or less.';
        }

        if ($phone !== '' && strlen($phone) > 30) {
            $errors[] = 'Phone number must be 30 characters or less.';
        }

        if (empty($errors)) {
            $db   = getDB();
            $chk  = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $chk->execute([$email]);

            if ($chk->fetch()) {
                $errors[] = 'An account with this email already exists. Please log in or use a different email.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare(
                    "INSERT INTO users (name, farm_name, email, phone, password_hash, role, status)
                     VALUES (?, ?, ?, ?, ?, 'user', 'active')"
                );
                $stmt->execute([
                    $name,
                    $farm_name !== '' ? $farm_name : null,
                    $email,
                    $phone !== '' ? $phone : null,
                    $hash,
                ]);
                $new_id = (int)$db->lastInsertId();
                auditLog($new_id, 'REGISTER', 'users', $new_id, null, ['role' => 'user', 'email' => $email]);

                flashMessage('success', 'Registration successful! Please sign in with your new account.');
                redirect('/index.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .auth-back {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            color: rgba(255,255,255,.75);
            font-size: .83rem;
            text-decoration: none;
            margin-bottom: 1.5rem;
        }
        .auth-back:hover { color: #fff; text-decoration: none; }
        .register-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 1rem;
        }
        @media (max-width: 480px) {
            .register-grid { grid-template-columns: 1fr; }
        }
        .auth-card { max-width: 520px; }
        .divider {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin: .5rem 0 1.25rem;
            color: var(--text-muted);
            font-size: .78rem;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }
    </style>
</head>
<body class="auth-page">

<div class="auth-wrap" style="max-width:560px">
    <a href="/home.php" class="auth-back">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Home
    </a>

    <div class="auth-card">
        <div class="auth-brand">
            <div class="auth-brand-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="48" height="48">
                    <ellipse cx="32" cy="38" rx="22" ry="16" fill="#fff" stroke="#2D6A4F" stroke-width="2"/>
                    <circle cx="20" cy="24" r="8" fill="#fff" stroke="#2D6A4F" stroke-width="2"/>
                    <circle cx="44" cy="24" r="8" fill="#fff" stroke="#2D6A4F" stroke-width="2"/>
                    <circle cx="26" cy="40" r="3" fill="#2D6A4F"/>
                    <circle cx="38" cy="40" r="3" fill="#2D6A4F"/>
                    <ellipse cx="32" cy="46" rx="5" ry="3" fill="#D4A017"/>
                    <line x1="14" y1="18" x2="10" y2="10" stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="20" y1="16" x2="18" y2="8"  stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="50" y1="18" x2="54" y2="10" stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="44" y1="16" x2="46" y2="8"  stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="auth-brand-name"><?= e(APP_NAME) ?></h1>
            <p class="auth-brand-sub">Create Your Free Account</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" style="flex-shrink:0;margin-top:.1rem">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <ul style="margin:0;padding-left:1rem">
                <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" action="/register.php" novalidate autocomplete="on">
            <?= csrfField() ?>

            <!-- Account info -->
            <div class="form-group">
                <label for="name" class="form-label">Full Name <span style="color:var(--danger)">*</span></label>
                <input type="text" id="name" name="name" class="form-control"
                       value="<?= e($form['name']) ?>"
                       placeholder="e.g. Mohammad Hasan"
                       required maxlength="100" autocomplete="name" autofocus>
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Email Address <span style="color:var(--danger)">*</span></label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= e($form['email']) ?>"
                       placeholder="you@example.com"
                       required maxlength="150" autocomplete="email">
            </div>

            <div class="register-grid">
                <div class="form-group">
                    <label for="password" class="form-label">Password <span style="color:var(--danger)">*</span></label>
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="Min 8 characters"
                           required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password <span style="color:var(--danger)">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                           placeholder="Repeat password"
                           required autocomplete="new-password">
                    <span class="form-error" id="confirmError" role="alert" style="display:none"></span>
                </div>
            </div>

            <div class="divider">Optional farm details</div>

            <div class="register-grid">
                <div class="form-group">
                    <label for="farm_name" class="form-label">Farm Name</label>
                    <input type="text" id="farm_name" name="farm_name" class="form-control"
                           value="<?= e($form['farm_name']) ?>"
                           placeholder="e.g. Green Valley Farm"
                           maxlength="150" autocomplete="organization">
                </div>
                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control"
                           value="<?= e($form['phone']) ?>"
                           placeholder="e.g. 01700-000000"
                           maxlength="30" autocomplete="tel">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block" id="registerBtn">
                <span class="btn-text">Create Account</span>
            </button>
        </form>

        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--text-secondary)">
            Already have an account?
            <a href="/index.php" style="font-weight:600">Sign in</a>
        </p>
    </div>

    <p class="auth-footer">
        &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.
    </p>
</div>

<script>
(function () {
    'use strict';
    var pwd     = document.getElementById('password');
    var confirm = document.getElementById('confirm_password');
    var err     = document.getElementById('confirmError');

    function checkMatch() {
        if (confirm.value && pwd.value !== confirm.value) {
            err.textContent = 'Passwords do not match.';
            err.style.display = 'block';
        } else {
            err.textContent = '';
            err.style.display = 'none';
        }
    }
    confirm.addEventListener('input', checkMatch);
    pwd.addEventListener('input', checkMatch);
})();
</script>
</body>
</html>
