<?php
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

if (isLoggedIn()) {
    redirect(getRoleRedirect(currentRole()));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!verifyCsrfToken($token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $email    = sanitize($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $result = login($email, $password);
            if ($result['success']) {
                redirect(getRoleRedirect($result['role']));
            } else {
                $error = $result['message'];
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
    <title>Sign In — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
                <p class="auth-brand-sub">Farm Management Portal</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/index.php" novalidate id="loginForm" autocomplete="on">
                <?= csrfField() ?>

                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control"
                        value="<?= e($email) ?>"
                        placeholder="you@farm.com"
                        required
                        autocomplete="email"
                        autofocus
                    >
                    <span class="form-error" id="emailError" role="alert"></span>
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="••••••••"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="input-group-btn" id="togglePwd" aria-label="Toggle password visibility">
                            <svg id="eyeShow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg id="eyeHide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none">
                                <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/>
                                <line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                    <span class="form-error" id="passwordError" role="alert"></span>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="loginBtn">
                    <span class="btn-text">Sign In</span>
                    <span class="btn-spinner" aria-hidden="true" style="display:none">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin">
                            <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                        </svg>
                        Signing in…
                    </span>
                </button>
            </form>

        </div>

        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:rgba(255,255,255,.65)">
            New here?
            <a href="/register.php" style="color:rgba(255,255,255,.9);font-weight:600">Create an account</a>
            &nbsp;·&nbsp;
            <a href="/home.php" style="color:rgba(255,255,255,.65)">Learn more</a>
        </p>

        <p class="auth-footer">
            &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.
        </p>
    </div>

    <script src="/assets/js/login.js"></script>
</body>
</html>
