<?php
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

if (isLoggedIn()) {
    redirect(getRoleRedirect(currentRole()));
}

$error      = '';
$tab        = $_GET['tab'] ?? 'email';    // 'email' or 'phone'
$email      = '';
$farm_code  = '';
$phone_val  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!verifyCsrfToken($token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $tab = $_POST['tab'] ?? 'email';

        if ($tab === 'phone') {
            // Farm Code + Phone login
            $farm_code = strtoupper(sanitize($_POST['farm_code'] ?? ''));
            $phone_val = sanitize($_POST['phone'] ?? '');
            $password  = $_POST['password'] ?? '';

            if ($farm_code === '' || $phone_val === '' || $password === '') {
                $error = 'Farm Code, phone number, and password are all required.';
            } else {
                $result = loginByPhone($farm_code, $phone_val, $password);
                if ($result['success']) {
                    redirect(getRoleRedirect($result['role']));
                } else {
                    $error = $result['message'];
                }
            }
        } else {
            // Standard email login
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .login-tabs { display:flex;border-bottom:2px solid var(--border);margin-bottom:1.5rem; }
        .login-tab {
            flex:1;text-align:center;padding:.6rem .5rem;font-size:.85rem;font-weight:600;
            color:var(--text-muted);cursor:pointer;border-bottom:2px solid transparent;
            margin-bottom:-2px;transition:var(--transition);
        }
        .login-tab.active { color:var(--primary);border-bottom-color:var(--primary); }
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }
        .farm-code-hint {
            font-size:.75rem;color:rgba(255,255,255,.6);text-align:center;
            margin-top:.5rem;
        }
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
            <p class="auth-brand-sub">Farm Management Portal</p>
        </div>

        <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= e($error) ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="login-tabs">
            <div class="login-tab <?= $tab==='email'?'active':'' ?>" data-tab="email">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:.3rem"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Email Login
            </div>
            <div class="login-tab <?= $tab==='phone'?'active':'' ?>" data-tab="phone">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:.3rem"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                Farm Code Login
            </div>
        </div>

        <!-- Email tab -->
        <div class="tab-panel <?= $tab==='email'?'active':'' ?>" id="panel-email">
            <form method="POST" action="/index.php" novalidate autocomplete="on" id="loginForm">
                <?= csrfField() ?>
                <input type="hidden" name="tab" value="email">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?= e($tab==='email'?$email:'') ?>"
                           placeholder="you@farm.com" required autocomplete="email" autofocus>
                    <span class="form-error" id="emailError" role="alert"></span>
                </div>
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" id="password" name="password" class="form-control"
                               placeholder="••••••••" required autocomplete="current-password">
                        <button type="button" class="input-group-btn" id="togglePwd" aria-label="Toggle password visibility">
                            <svg id="eyeShow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg id="eyeHide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
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

        <!-- Farm Code tab -->
        <div class="tab-panel <?= $tab==='phone'?'active':'' ?>" id="panel-phone">
            <form method="POST" action="/index.php" novalidate autocomplete="on">
                <?= csrfField() ?>
                <input type="hidden" name="tab" value="phone">
                <div class="form-group">
                    <label class="form-label">Farm Code</label>
                    <input type="text" name="farm_code" class="form-control"
                           value="<?= e($farm_code) ?>"
                           placeholder="FARM-00001" maxlength="20"
                           style="text-transform:uppercase;letter-spacing:.08em;font-weight:600"
                           required autocomplete="off">
                    <span class="text-xs text-muted">Your Farm Code was shown after registration</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control"
                           value="<?= e($phone_val) ?>"
                           placeholder="01700-000000" maxlength="20" required autocomplete="tel">
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control"
                           placeholder="••••••••" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Sign In with Farm Code</button>
            </form>
        </div>

        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--text-secondary)">
            New farmer?
            <a href="/register.php" style="font-weight:600">Register your farm</a>
            &nbsp;·&nbsp;
            <a href="/home.php">Learn more</a>
        </p>
    </div>

    <?php
    $flash = getFlashMessage();
    if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="alert" style="margin-top:1rem;max-width:440px">
        <?= $flash['message'] ?>
    </div>
    <?php endif; ?>

    <p class="auth-footer">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
</div>

<script src="/assets/js/login.js"></script>
<script>
(function(){
    'use strict';
    // Tab switching
    document.querySelectorAll('.login-tab').forEach(function(tab){
        tab.addEventListener('click', function(){
            var t = this.dataset.tab;
            document.querySelectorAll('.login-tab').forEach(function(x){x.classList.remove('active');});
            document.querySelectorAll('.tab-panel').forEach(function(x){x.classList.remove('active');});
            this.classList.add('active');
            document.getElementById('panel-'+t).classList.add('active');
        });
    });
    // Farm code auto-uppercase
    var fc = document.querySelector('[name="farm_code"]');
    if(fc) fc.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });
}());
</script>
</body>
</html>
