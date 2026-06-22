<?php
/**
 * Environment configuration — lives OUTSIDE public_html (never served by web server).
 * Copy this file to the parent of public_html and fill in production values.
 *
 * Path: /home/<user>/env.php  (one level above public_html on Hostinger)
 *
 * DO NOT commit this file with real credentials.
 */
return [
    // ── Database ──────────────────────────────────────────────────────────────
    'DB_HOST'    => '127.0.0.1',
    'DB_NAME'    => 'cow_management',
    'DB_USER'    => 'root',
    'DB_PASS'    => '',               // ← set a strong password in production

    // ── Application ───────────────────────────────────────────────────────────
    'APP_URL'    => 'http://localhost:8080',  // ← set to https://yourdomain.com
    'APP_ENV'    => 'development',            // ← 'production' on live server
    'APP_DEBUG'  => false,                    // ← false in production

    // ── Session lifetime (seconds) ────────────────────────────────────────────
    'SESSION_LIFETIME' => 3600,
];
