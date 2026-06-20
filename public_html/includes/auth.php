<?php
require_once __DIR__ . '/functions.php';

function startSecureSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// ── Rate limiting ─────────────────────────────────────────────────────────────

function _getClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function _checkRateLimit(string $identifier): ?string {
    $db  = getDB();
    $ip  = _getClientIp();
    $win = date('Y-m-d H:i:s', time() - 900); // 15-minute window

    $cnt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address=? AND attempted_at > ?');
    $cnt->execute([$ip, $win]);
    $ip_count = (int)$cnt->fetchColumn();
    if ($ip_count >= 10) {
        return 'Too many login attempts from your IP. Please wait 15 minutes.';
    }

    $cnt2 = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier=? AND attempted_at > ?');
    $cnt2->execute([$identifier, $win]);
    if ((int)$cnt2->fetchColumn() >= 5) {
        return 'Too many failed attempts for this account. Please wait 15 minutes.';
    }
    return null;
}

function _recordFailedAttempt(string $identifier): void {
    $db = getDB();
    $db->prepare('INSERT INTO login_attempts (ip_address, identifier) VALUES (?,?)')
       ->execute([_getClientIp(), $identifier]);
    // Prune records older than 1 hour (1 in 20 chance to avoid overhead every request)
    if (random_int(1, 20) === 1) {
        $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
}

function _clearAttempts(string $identifier): void {
    $db = getDB();
    $db->prepare('DELETE FROM login_attempts WHERE identifier=? OR ip_address=?')
       ->execute([$identifier, _getClientIp()]);
}

// ── Login functions ───────────────────────────────────────────────────────────

function login(string $email, string $password): array {
    $email = strtolower(trim($email));
    $db    = getDB();

    if ($msg = _checkRateLimit($email)) {
        return ['success' => false, 'message' => $msg];
    }

    $stmt = $db->prepare(
        'SELECT id, name, email, phone, password_hash, role, status, farm_id, is_owner
         FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        _recordFailedAttempt($email);
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }
    if ($user['status'] !== 'active') {
        _recordFailedAttempt($email);
        return ['success' => false, 'message' => 'Your account is inactive. Contact the administrator.'];
    }

    _clearAttempts($email);
    session_regenerate_id(true);
    _setUserSession($user);
    auditLog((int)$user['id'], 'LOGIN', 'users', (int)$user['id']);

    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([$newHash, $user['id']]);
    }

    return ['success' => true, 'role' => $user['role']];
}

// Login by phone number + farm code (Bangladesh-friendly)
function loginByPhone(string $farm_code, string $phone, string $password): array {
    $farm_code  = strtoupper(trim($farm_code));
    $phone      = trim($phone);
    $identifier = $farm_code . ':' . $phone;
    $db         = getDB();

    if ($msg = _checkRateLimit($identifier)) {
        return ['success' => false, 'message' => $msg];
    }

    $stmt = $db->prepare(
        'SELECT u.id, u.name, u.email, u.phone, u.password_hash, u.role, u.status, u.farm_id, u.is_owner
         FROM users u
         JOIN farms f ON f.id = u.farm_id
         WHERE f.farm_code = ? AND u.phone = ?
         LIMIT 1'
    );
    $stmt->execute([$farm_code, $phone]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        _recordFailedAttempt($identifier);
        return ['success' => false, 'message' => 'Invalid Farm Code, phone, or password.'];
    }
    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => 'Your account is inactive. Contact the farm owner.'];
    }

    _clearAttempts($identifier);
    session_regenerate_id(true);
    _setUserSession($user);
    auditLog((int)$user['id'], 'LOGIN_PHONE', 'users', (int)$user['id']);

    return ['success' => true, 'role' => $user['role']];
}

function _setUserSession(array $user): void {
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'] ?? '';
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['logged_in']  = true;
    $_SESSION['login_time'] = time();
    $_SESSION['farm_id']    = $user['farm_id'] ? (int)$user['farm_id'] : null;
    $_SESSION['is_owner']   = (bool)$user['is_owner'];

    // Load and cache farm details in session
    if ($user['farm_id']) {
        $farm_stmt = getDB()->prepare(
            'SELECT f.farm_name, f.farm_code, f.status,
                    p.name AS plan_name
             FROM farms f
             LEFT JOIN subscriptions s ON s.farm_id = f.id AND s.status IN (\'active\',\'trial\')
             LEFT JOIN plans p ON p.id = s.plan_id
             WHERE f.id = ? LIMIT 1'
        );
        $farm_stmt->execute([(int)$user['farm_id']]);
        $farm = $farm_stmt->fetch();
        $_SESSION['_farm'] = $farm ?: null;
    } else {
        $_SESSION['_farm'] = null;
    }
}

function logout(): void {
    startSecureSession();
    if (isLoggedIn()) {
        auditLog((int)$_SESSION['user_id'], 'LOGOUT', 'users', (int)$_SESSION['user_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    redirect('/index.php');
}

function isLoggedIn(): bool {
    return !empty($_SESSION['logged_in']) && !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'name'     => $_SESSION['user_name'],
        'email'    => $_SESSION['user_email'],
        'role'     => $_SESSION['user_role'],
        'farm_id'  => $_SESSION['farm_id'] ?? null,
        'is_owner' => $_SESSION['is_owner'] ?? false,
    ];
}

function currentFarmId(): ?int {
    return isset($_SESSION['farm_id']) && (int)$_SESSION['farm_id'] > 0
        ? (int)$_SESSION['farm_id']
        : null;
}

function currentFarm(): ?array {
    return $_SESSION['_farm'] ?? null;
}

function currentRole(): string {
    return $_SESSION['user_role'] ?? '';
}

function hasRole(array $roles): bool {
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}

function getRoleRedirect(string $role): string {
    return match ($role) {
        'superadmin'   => '/modules/super_admin/index.php',
        'admin'        => '/dashboard.php',
        'worker'       => '/modules/workers/my_tasks.php',
        'accountant'   => '/modules/finance/index.php',
        'veterinarian' => '/modules/cows/index.php',
        'reception'    => '/modules/cows/index.php',
        'user'         => '/user_dashboard.php',
        default        => '/index.php',
    };
}
