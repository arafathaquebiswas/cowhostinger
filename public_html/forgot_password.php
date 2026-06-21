<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password_reset_service.php';

startSecureSession();

if (isLoggedIn()) {
    redirect(getRoleRedirect(currentRole()));
}

$submitted = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            createResetToken($email);
            $submitted = true; // Always show success — never reveal whether email exists
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            <p class="auth-brand-sub">Password Recovery</p>
        </div>

        <?php if ($submitted): ?>
        <div class="alert alert-success" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            If that email address is registered, we've sent a password reset link. Check your inbox (and spam folder).
            The link expires in <?= RESET_TOKEN_EXPIRY_MINUTES ?> minutes.
        </div>
        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--text-secondary)">
            <a href="/index.php" style="font-weight:600">← Back to Sign In</a>
        </p>

        <?php else: ?>

        <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= e($error) ?>
        </div>
        <?php endif; ?>

        <p style="font-size:.88rem;color:var(--text-secondary);margin-bottom:1.25rem;line-height:1.5">
            Enter the email address associated with your account and we'll send you a link to reset your password.
        </p>

        <form method="POST" action="/forgot_password.php" novalidate>
            <?= csrfField() ?>
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= e($_POST['email'] ?? '') ?>"
                       placeholder="you@farm.com" required autocomplete="email" autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
        </form>

        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--text-secondary)">
            <a href="/index.php" style="font-weight:600">← Back to Sign In</a>
        </p>
        <?php endif; ?>
    </div>

    <p class="auth-footer">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
</div>
</body>
</html>
