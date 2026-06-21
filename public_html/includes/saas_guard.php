<?php
/**
 * saas_guard.php — Unified SaaS request pipeline.
 *
 * Single include that boots the full security chain:
 *   auth  →  farm scope  →  subscription engine  →  access control
 *
 * Usage in module controllers:
 *   require_once dirname(__DIR__, N) . '/includes/saas_guard.php';
 *   saasRequire(['admin', 'accountant']);   // roles allowed; omit = any authenticated
 *
 * Then gate features with:
 *   if (!canAccess('finance.view')) requireAccess('finance.view');
 *   // or inline: requireAccess('cow.create');
 */

// ── Bootstrap chain (idempotent includes) ─────────────────────────────────────

if (!function_exists('requireRole'))   require_once __DIR__ . '/role_guard.php';
if (!function_exists('isSuperAdmin'))  require_once __DIR__ . '/farm_guard.php';
if (!function_exists('getSubscription')) require_once __DIR__ . '/subscription_engine.php';
if (!function_exists('canAccess'))     require_once __DIR__ . '/access_control.php';

// ── Unified require ───────────────────────────────────────────────────────────

/**
 * Enforce authentication + role + farm scope + subscription state in one call.
 *
 * @param array $roles          Roles allowed to access this page (empty = any authenticated)
 * @param bool  $enforce_block  If true, expired/suspended farms are redirected immediately.
 *                              Set false on the subscription page itself to avoid redirect loop.
 */
function saasRequire(array $roles = [], bool $enforce_block = true): void {
    // 1. Authentication
    requireAuth();

    // 2. Role check
    if (!empty($roles)) {
        requireRole($roles);
    }

    // 3. Farm scope (superadmin bypasses unless impersonating)
    requireFarmScope();

    // 4. Load subscription (cached per request — safe to call multiple times)
    $sub = getSubscription();

    // 5. Block enforcement
    //    CEO impersonating a blocked farm still sees the block (accurate view)
    //    CEO's own session is never blocked (is_unlimited = true)
    if ($enforce_block && $sub['is_blocked'] && !isSuperAdmin()) {
        $msg = $sub['is_suspended']
            ? 'Your account has been suspended. Contact AB IT support to restore access.'
            : 'Your subscription has expired. Please renew to continue using the system.';
        flashMessage('error', $msg);
        redirect('/modules/subscription/index.php');
    }
}
