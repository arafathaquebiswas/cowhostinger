<?php
require_once __DIR__ . '/includes/auth.php';
startSecureSession();

if (isLoggedIn()) {
    redirect(getRoleRedirect(currentRole()));
}

$errors = [];
$form   = ['name' => '', 'phone' => '', 'email' => '', 'farm_name' => '', 'location' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $name      = sanitize($_POST['name']      ?? '');
        $phone     = sanitize($_POST['phone']      ?? '');
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $password  = $_POST['password']            ?? '';
        $confirm   = $_POST['confirm_password']    ?? '';
        $farm_name = sanitize($_POST['farm_name']  ?? '');
        $location  = sanitize($_POST['location']   ?? '');

        $form = ['name'=>$name,'phone'=>$phone,'email'=>$email,'farm_name'=>$farm_name,'location'=>$location];

        // Auto-generate farm name if not provided
        if ($farm_name === '') $farm_name = $name . "'s Farm";

        // Validation
        if ($name === '')         $errors[] = 'Full name is required.';
        if ($phone === '' && $email === '') $errors[] = 'At least one of phone or email is required.';
        if ($phone !== '' && !preg_match('/^[0-9\+\-\s()]{7,20}$/', $phone)) $errors[] = 'Enter a valid phone number.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)  $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            $db = getDB();

            // Email uniqueness (only if provided)
            if ($email !== '') {
                $chk = $db->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
                $chk->execute([$email]);
                if ($chk->fetch()) $errors[] = 'An account with this email already exists.';
            }
            // Phone uniqueness
            if ($phone !== '' && empty($errors)) {
                $chk = $db->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
                $chk->execute([$phone]);
                if ($chk->fetch()) $errors[] = 'An account with this phone number already exists.';
            }
        }

        if (empty($errors)) {
            $db = getDB();
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $db->beginTransaction();
            try {
                // Bug fix: farm_code is NOT NULL with STRICT mode — must be included in INSERT.
                // Use a collision-safe temporary code; UPDATE to the canonical FARM-XXXXX immediately after.
                $tmp_code = 'REG-' . bin2hex(random_bytes(8)); // 20 chars, unique within transaction
                $db->prepare(
                    "INSERT INTO farms (farm_name, location, phone, farm_code) VALUES (?,?,?,?)"
                )->execute([$farm_name, $location !== '' ? $location : null, $phone !== '' ? $phone : null, $tmp_code]);

                $farm_id   = (int)$db->lastInsertId();
                $farm_code = 'FARM-' . str_pad($farm_id, 5, '0', STR_PAD_LEFT);
                $db->prepare("UPDATE farms SET farm_code=? WHERE id=?")->execute([$farm_code, $farm_id]);

                // Create owner account
                $db->prepare(
                    "INSERT INTO users (farm_id, is_owner, name, email, phone, password_hash, role, status)
                     VALUES (?,1,?,?,?,?,'admin','active')"
                )->execute([$farm_id, $name, $email !== '' ? $email : null, $phone !== '' ? $phone : null, $hash]);

                $user_id = (int)$db->lastInsertId();

                // Link farm owner
                $db->prepare("UPDATE farms SET owner_user_id=? WHERE id=?")->execute([$user_id, $farm_id]);

                // Free subscription (trial)
                $db->prepare(
                    "INSERT INTO subscriptions (farm_id, plan_id, start_date, status) VALUES (?,1,CURDATE(),'trial')"
                )->execute([$farm_id]);

                // Seed per-farm module settings from global defaults (farm_id = 0)
                $db->prepare(
                    "INSERT IGNORE INTO module_settings (farm_id, module_name, is_enabled)
                     SELECT ?, module_name, is_enabled FROM module_settings WHERE farm_id = 0"
                )->execute([$farm_id]);

                auditLog($user_id, 'REGISTER_FARM', 'farms', $farm_id, null, ['farm_name' => $farm_name]);

                $db->commit();

                // Bug fix: establish login session immediately — do not force the user to sign in again.
                session_regenerate_id(true);
                _setUserSession([
                    'id'       => $user_id,
                    'name'     => $name,
                    'email'    => $email !== '' ? $email : null,
                    'role'     => 'admin',
                    'farm_id'  => $farm_id,
                    'is_owner' => 1,
                ]);

                flashMessage('success', "Welcome! Your Farm Code is <strong>{$farm_code}</strong>. Keep it safe — workers use it to log in.");
                redirect('/dashboard.php');

            } catch (Throwable $e) {
                $db->rollBack();
                $errors[] = 'Registration failed. Please try again.';
                error_log('[REGISTER] ' . $e->getMessage());
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
        .auth-card { max-width: 520px; }
        .auth-back { display:inline-flex;align-items:center;gap:.4rem;color:rgba(255,255,255,.75);font-size:.83rem;text-decoration:none;margin-bottom:1.5rem; }
        .auth-back:hover { color:#fff;text-decoration:none; }
        .reg-grid { display:grid;grid-template-columns:1fr 1fr;gap:0 1rem; }
        .divider { display:flex;align-items:center;gap:.75rem;margin:.25rem 0 1rem;color:var(--text-muted);font-size:.78rem; }
        .divider::before,.divider::after { content:'';flex:1;height:1px;background:var(--border); }
        @media(max-width:480px){.reg-grid{grid-template-columns:1fr;}}
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
                    <circle cx="26" cy="40" r="3" fill="#2D6A4F"/><circle cx="38" cy="40" r="3" fill="#2D6A4F"/>
                    <ellipse cx="32" cy="46" rx="5" ry="3" fill="#D4A017"/>
                    <line x1="14" y1="18" x2="10" y2="10" stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="20" y1="16" x2="18" y2="8"  stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="50" y1="18" x2="54" y2="10" stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                    <line x1="44" y1="16" x2="46" y2="8"  stroke="#2D6A4F" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <h1 class="auth-brand-name"><?= e(APP_NAME) ?></h1>
            <p class="auth-brand-sub">Create Your Account</p>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:.1rem"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <ul style="margin:0;padding-left:1rem"><?php foreach ($errors as $e_): ?><li><?= e($e_) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <form method="POST" action="/register.php" novalidate id="regForm">
            <?= csrfField() ?>

            <!-- Farm details -->
            <div class="divider">Your Farm <span style="font-weight:400;font-size:.78rem">(optional)</span></div>
            <div class="form-group">
                <label class="form-label">Farm Name <span style="color:var(--text-muted);font-size:.78rem;font-weight:400">(optional)</span></label>
                <input type="text" name="farm_name" class="form-control"
                       value="<?= e($form['farm_name']) ?>" placeholder="e.g. Green Valley Dairy Farm — leave blank to auto-fill"
                       maxlength="200">
            </div>
            <div class="form-group">
                <label class="form-label">Farm Location <span style="color:var(--text-muted);font-size:.78rem;font-weight:400">(optional)</span></label>
                <input type="text" name="location" class="form-control"
                       value="<?= e($form['location']) ?>" placeholder="e.g. Dhaka, Bangladesh" maxlength="300">
            </div>
            <div class="divider">Your Details</div>

            <!-- Personal info -->
            <div class="form-group">
                <label class="form-label">Full Name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="name" class="form-control" value="<?= e($form['name']) ?>"
                       placeholder="Your full name" required maxlength="100" autofocus>
            </div>
            <div class="reg-grid">
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" value="<?= e($form['phone']) ?>"
                           placeholder="01700-000000" maxlength="20">
                    <span class="text-xs text-muted">For Farm Code login</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($form['email']) ?>"
                           placeholder="Optional" maxlength="150">
                </div>
            </div>
            <div class="reg-grid">
                <div class="form-group">
                    <label class="form-label">Password <span style="color:var(--danger)">*</span></label>
                    <input type="password" name="password" class="form-control" placeholder="Min 8 chars" required minlength="8">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password <span style="color:var(--danger)">*</span></label>
                    <input type="password" name="confirm_password" id="confirmPwd" class="form-control" placeholder="Repeat" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Register My Farm</button>
        </form>

        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--text-secondary)">
            Already have an account? <a href="/index.php" style="font-weight:600">Sign in</a>
        </p>
    </div>

    <p class="auth-footer">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></p>
</div>

<script>
(function(){
    'use strict';
    var pwd = document.querySelector('[name="password"]');
    var cpw = document.getElementById('confirmPwd');
    function chkMatch() {
        cpw.setCustomValidity(cpw.value && pwd.value !== cpw.value ? 'Passwords do not match.' : '');
    }
    if (pwd && cpw) {
        pwd.addEventListener('input', chkMatch);
        cpw.addEventListener('input', chkMatch);
    }
}());
</script>
</body>
</html>
