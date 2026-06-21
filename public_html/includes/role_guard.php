<?php
require_once __DIR__ . '/auth.php';

function requireAuth(): void {
    startSecureSession();

    if (!isLoggedIn()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            jsonResponse(['error' => 'Unauthorized', 'redirect' => '/index.php'], 401);
        }
        flashMessage('error', 'Please log in to access this page.');
        redirect('/index.php');
    }

    validateDeviceSession();

    // Session timeout
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
        logout();
    }

    $_SESSION['login_time'] = time();
}

function requireRole(array $allowedRoles): void {
    requireAuth();

    if (!hasRole($allowedRoles)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            jsonResponse(['error' => 'Forbidden', 'message' => 'Access denied.'], 403);
        }
        flashMessage('error', 'You do not have permission to access that page.');
        redirect('/dashboard.php');
    }
}

function requireModule(string $moduleName): void {
    if (!isModuleEnabled($moduleName)) {
        flashMessage('error', 'This module is currently disabled by the administrator.');
        redirect('/dashboard.php');
    }
}
