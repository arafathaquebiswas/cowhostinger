<?php
// ============================================================
// Application Configuration
// IMPORTANT: Change DB_NAME, DB_USER, DB_PASS before deploying
// ============================================================

// Database
define('DB_HOST',    'localhost');
define('DB_NAME',    'your_db_name');     // ← Change this
define('DB_USER',    'your_db_user');     // ← Change this
define('DB_PASS',    'your_db_password'); // ← Change this
define('DB_CHARSET', 'utf8mb4');

// Application
define('APP_NAME',    'Cow Management & Diagnosis System');
define('APP_URL',     'https://yourdomain.com'); // No trailing slash
define('APP_VERSION', '1.0.0');

// Session
define('SESSION_LIFETIME', 3600); // 1 hour in seconds

// File Uploads
define('UPLOAD_MAX_SIZE',      5 * 1024 * 1024); // 5 MB
define('UPLOAD_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);
define('UPLOAD_PATH',          __DIR__ . '/../uploads/');

// Paths (no trailing slash)
define('BASE_PATH', dirname(__DIR__)); // Absolute filesystem path to public_html

// Security
define('CSRF_TOKEN_NAME', '_csrf_token');

// Error reporting (0 in production)
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');
