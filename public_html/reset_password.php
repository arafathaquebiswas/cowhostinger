<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset_service.php';

startSecureSession();

if (isLoggedIn()) {
    redirect(getRoleRedirect(currentRole()));
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$user  = null;
$error = '';

// Validate token on every page load (GET and failed POST)
if ($token !== '') {
    $user = validateResetToken($token);
}

if ($token === '' || $user === null) {
    $invalid_token = true;
} else {
    $invalid_token = false;
}

if (!$invalid_token && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $password         = $_POST['password']         ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $password_confirm) {
            $error = 'Passwords do not match.';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain at least one uppercase letter and one number.';
        } else {
            if (consumeResetToken($token, $password)) {
                flashMessage('success', 'Your password has been reset. Please sign in with your new password.');
                redirect('/index.php');
            } else {
                $error = 'This reset link has expired or already been used. Please request a new one.';
                $invalid_token = true;
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
    <title>Reset Password — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .password-strength { height:4px;border-radius:2px;background:#E5E7EB;margin-top:.4rem;overflow:hidden }
        .password-strength-bar { height:100%;width:0;transition:width .3s,background .3s;border-radius:2px }
        .pwd-hint { font-size:.75rem;color:var(--text-muted);margin-top:.3rem }
    </style>
</head>
<body class="auth-page">
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand">
            <div class="auth-brand-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="48" height="48">
                    <ellipse cx="32" cy="38" rx="22" ry="16" fill="#fff" stroke="#2D6A4F" stroke-width="2"/>
                    <circle cx="20" cy="24" r="8" fill="#fff" stroke="#2D6A4F" stroke-width="2"/>
                    <circle cx="44" cy="24" r="8" fill="#fff" stroke="#2D6A4F" stroke-width="2"/>
                    <circle cx="26" cy="40" r="3" fill="#2D6A4F"/><circle cx="38" cy="40" r="3" fill="#2D6A4F"/>
                    <ellipse cx="32" cy="46" rx="5" ry="3" fill="#D4A017"/>
                    <line x1="14" y1="18" x2="10" y2="10" stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="20" y1="16" x2="18" y2="8"  stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="50" y1="18" x2="54" y2="10" stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="44" y1="16" x2="46" y2="8"  stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="auth-brand-name"><?= e(APP_NAME) ?></h1>
            <p class="auth-brand-sub">Set New Password</p>
        </div>

        <?php if ($invalid_token): ?>
        <div class="alert alert-danger" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= ($error !== '') ? e($error) : 'This password reset link is invalid or has expired.' ?>
        </div>
        <p style="font-size:.85rem;color:var(--text-secondary);text-align:center;margin-top:1rem">
            <a href="/forgot_password.php" style="font-weight:600">Request a new reset link</a>
            &nbsp;·&nbsp;
            <a href="/index.php">Back to Sign In</a>
        </p>

        <?php else: ?>

        <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= e($error) ?>
        </div>
        <?php endif; ?>

        <p style="font-size:.88rem;color:var(--text-secondary);margin-bottom:1.25rem;line-height:1.5">
            Hi <strong><?= e($user['name']) ?></strong>, enter your new password below.
        </p>

        <form method="POST" action="/reset_password.php" novalidate id="resetForm">
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <div class="form-group">
                <label for="password" class="form-label">New Password</label>
                <div class="input-group">
                    <input type="password" id="password" name="password" class="form-control"
                           placeholder="••••••••" required minlength="8"
                           autocomplete="new-password" autofocus>
                    <button type="button" class="input-group-btn" id="togglePwd" aria-label="Toggle password visibility">
                        <svg id="eyeShow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg id="eyeHide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </div>
                <div class="password-strength"><div class="password-strength-bar" id="strengthBar"></div></div>
                <p class="pwd-hint" id="strengthLabel">Min 8 characters, one uppercase, one number</p>
            </div>

            <div class="form-group">
                <label for="password_confirm" class="form-label">Confirm New Password</label>
                <input type="password" id="password_confirm" name="password_confirm" class="form-control"
                       placeholder="••••••••" required minlength="8"
                       autocomplete="new-password">
                <span class="form-error" id="confirmError" role="alert" style="display:none">Passwords do not match.</span>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Set New Password</button>
        </form>

        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--text-secondary)">
            <a href="/index.php" style="font-weight:600">← Back to Sign In</a>
        </p>
        <?php endif; ?>
    </div>

    <p class="auth-footer">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
</div>

<script>
(function(){
    'use strict';
    var pwd     = document.getElementById('password');
    var confirm = document.getElementById('password_confirm');
    var bar     = document.getElementById('strengthBar');
    var label   = document.getElementById('strengthLabel');
    var toggleBtn = document.getElementById('togglePwd');

    if (toggleBtn && pwd) {
        toggleBtn.addEventListener('click', function(){
            var show = pwd.type === 'password';
            pwd.type = show ? 'text' : 'password';
            document.getElementById('eyeShow').style.display = show ? 'none' : '';
            document.getElementById('eyeHide').style.display = show ? ''     : 'none';
        });
    }

    function scorePassword(p) {
        var score = 0;
        if (p.length >= 8)  score++;
        if (p.length >= 12) score++;
        if (/[A-Z]/.test(p)) score++;
        if (/[0-9]/.test(p)) score++;
        if (/[^A-Za-z0-9]/.test(p)) score++;
        return score;
    }

    if (pwd && bar && label) {
        pwd.addEventListener('input', function(){
            var score = scorePassword(this.value);
            var pct   = Math.min(100, score * 20) + '%';
            var color = score <= 1 ? '#DC2626' : score <= 2 ? '#D97706' : score <= 3 ? '#2D6A4F' : '#059669';
            var text  = score <= 1 ? 'Very weak' : score <= 2 ? 'Weak' : score <= 3 ? 'Good' : score <= 4 ? 'Strong' : 'Very strong';
            bar.style.width    = pct;
            bar.style.background = color;
            label.textContent  = text;
            label.style.color  = color;
        });
    }

    if (confirm) {
        confirm.addEventListener('input', function(){
            var err = document.getElementById('confirmError');
            if (pwd && this.value && this.value !== pwd.value) {
                err.style.display = 'block';
            } else {
                err.style.display = 'none';
            }
        });
    }

    var form = document.getElementById('resetForm');
    if (form) {
        form.addEventListener('submit', function(e){
            if (pwd && confirm && pwd.value !== confirm.value) {
                e.preventDefault();
                document.getElementById('confirmError').style.display = 'block';
                confirm.focus();
            }
        });
    }
}());
</script>
</body>
</html>
