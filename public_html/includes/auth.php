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

function login(string $email, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, name, email, password_hash, role, status
         FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid email or password.'];
    }
    if ($user['status'] !== 'active') {
        return ['success' => false, 'message' => 'Your account is inactive. Contact the administrator.'];
    }

    session_regenerate_id(true);

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['logged_in']  = true;
    $_SESSION['login_time'] = time();

    auditLog((int)$user['id'], 'LOGIN', 'users', (int)$user['id']);

    // Upgrade hash cost if needed
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([$newHash, $user['id']]);
    }

    return ['success' => true, 'role' => $user['role']];
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
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role'  => $_SESSION['user_role'],
    ];
}

function currentRole(): string {
    return $_SESSION['user_role'] ?? '';
}

function hasRole(array $roles): bool {
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}

function getRoleRedirect(string $role): string {
    return match ($role) {
        'admin'        => '/dashboard.php',
        'worker'       => '/modules/workers/my_tasks.php',
        'accountant'   => '/modules/finance/index.php',
        'veterinarian' => '/modules/cows/index.php',
        'reception'    => '/modules/cows/index.php',
        default        => '/index.php',
    };
}
