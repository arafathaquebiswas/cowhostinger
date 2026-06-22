<?php
// ============================================================
// Application Configuration
//
// Credentials are loaded from env.php, which lives ONE LEVEL
// ABOVE public_html (outside the web root) so it is never
// directly accessible via HTTP.
//
// On Hostinger: /home/<user>/env.php
// Locally:      /path/to/project/env.php  (sibling of public_html)
//
// If env.php is absent the hard-coded dev defaults below are used.
// NEVER deploy to production without an env.php — the defaults
// use 'root' with no password which is insecure.
// ============================================================

// Load env.php from outside the web root (two directory levels up from here)
$_env_file = dirname(__DIR__, 2) . '/env.php';
$_env       = file_exists($_env_file) ? (require $_env_file) : [];

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST',    $_env['DB_HOST']    ?? '127.0.0.1');
define('DB_NAME',    $_env['DB_NAME']    ?? 'cow_management');
define('DB_USER',    $_env['DB_USER']    ?? 'root');
define('DB_PASS',    $_env['DB_PASS']    ?? '');
define('DB_CHARSET', 'utf8mb4');

// ── Application ───────────────────────────────────────────────────────────────
define('APP_NAME',    'Cow Management & Diagnosis System');
define('APP_URL',     $_env['APP_URL']   ?? 'http://localhost:8080'); // change to https:// in production
define('APP_VERSION', '1.0.0');
define('APP_ENV',     $_env['APP_ENV']   ?? 'development'); // 'development' | 'production'
define('APP_DEBUG',   (bool)($_env['APP_DEBUG'] ?? (APP_ENV === 'development')));

// ── Session ───────────────────────────────────────────────────────────────────
define('SESSION_LIFETIME', (int)($_env['SESSION_LIFETIME'] ?? 3600));

// ── File Uploads ──────────────────────────────────────────────────────────────
define('UPLOAD_MAX_SIZE',      5 * 1024 * 1024); // 5 MB
define('UPLOAD_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
define('UPLOAD_PATH',          __DIR__ . '/../uploads/');

// ── Paths (no trailing slash) ─────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__)); // Absolute filesystem path to public_html

// ── Security ──────────────────────────────────────────────────────────────────
define('CSRF_TOKEN_NAME', '_csrf_token');

// ── Error reporting ───────────────────────────────────────────────────────────
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');

// ── Cleanup ───────────────────────────────────────────────────────────────────
unset($_env_file, $_env);
