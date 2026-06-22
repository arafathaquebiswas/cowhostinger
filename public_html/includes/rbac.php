<?php
/**
 * rbac.php — Role-Based Access Control.
 *
 * Two-layer architecture:
 *   PLATFORM layer: superadmin (CEO), support_staff — farm_id IS NULL, cross-farm visibility
 *   FARM layer:     admin, manager, accountant, veterinarian, worker — scoped to one farm_id
 *
 * Hierarchy: CEO > support_staff > admin > manager > accountant / veterinarian > worker
 *
 * CEO bypasses this matrix entirely (isSuperAdmin() === true → always allowed).
 * Support staff is read-only across all farms; they never write farm data.
 * Farm roles are always scoped by farmFilter() — they cannot see other farms.
 */

// ── Role constants ─────────────────────────────────────────────────────────────

define('ROLE_CEO',       'superadmin');     // Platform — SaaS owner
define('ROLE_SUPPORT',   'support_staff'); // Platform — internal helpdesk
define('ROLE_ADMIN',     'admin');          // Farm — owner/highest authority
define('ROLE_MANAGER',   'manager');        // Farm — full ops, no billing/users
define('ROLE_ACCOUNTANT','accountant');     // Farm — finance & reports
define('ROLE_VET',       'veterinarian');  // Farm — animal health
define('ROLE_MILKMAN',   'milkman');        // Farm — dairy handler / milk recording
define('ROLE_WORKER',    'worker');         // Farm — general task execution

// ── Permission matrix ─────────────────────────────────────────────────────────
// CEO always bypasses this — isSuperAdmin() short-circuits everything.
// Wildcards: 'module.*' grants all actions under that module.

define('RBAC_PERMISSIONS', [

    // ── Platform: Support Staff (read-only cross-farm) ────────────────────────
    ROLE_SUPPORT => [
        'farm.list', 'farm.view', 'farm.support_view',
        'subscription.view',
        'ticket.view', 'ticket.create', 'ticket.respond', 'ticket.assign', 'ticket.close',
        'error_log.view', 'activity_log.view', 'alert.view',
    ],

    // ── Farm: Admin — full farm authority ─────────────────────────────────────
    ROLE_ADMIN => [
        'cow.*', 'milk.*', 'breeding.*', 'calf.*',
        'worker.*', 'task.*', 'equipment.*',
        'feed.*', 'medicine.*', 'diagnosis.*', 'treatment.*',
        'sales.*', 'finance.*', 'report.*',
        'alert.*', 'user.*',
        'subscription.view',
        'ticket.create', 'ticket.view_own',
        'dashboard.view', 'profile.*',
    ],

    // ── Farm: Manager — full ops, no user management, no finance delete ───────
    ROLE_MANAGER => [
        'cow.*', 'milk.*', 'breeding.*', 'calf.*',
        'worker.*', 'task.*', 'equipment.*',
        'feed.*', 'medicine.*', 'diagnosis.*', 'treatment.*',
        'sales.*',
        'finance.view', 'finance.create',   // cannot delete finance records
        'report.*',
        'alert.*',
        'user.view', 'user.list',            // can see user list, cannot manage
        'subscription.view',
        'ticket.create', 'ticket.view_own',
        'dashboard.view', 'profile.*',
    ],

    // ── Farm: Accountant — finance and reporting only ─────────────────────────
    ROLE_ACCOUNTANT => [
        'finance.*', 'report.*',
        'cow.view', 'cow.list',
        'milk.view', 'milk.list',
        'sales.view', 'sales.list', 'sales.create',
        'alert.view',
        'ticket.create', 'ticket.view_own',
        'subscription.view',
        'dashboard.view', 'profile.*',
    ],

    // ── Farm: Veterinarian — animal health only ───────────────────────────────
    ROLE_VET => [
        'cow.*', 'diagnosis.*', 'treatment.*',
        'breeding.*', 'calf.*',
        'milk.view', 'milk.list', 'milk.create',
        'feed.view', 'medicine.*',
        'alert.view',
        'ticket.create', 'ticket.view_own',
        'dashboard.view', 'profile.*',
    ],

    // ── Farm: Milkman — dairy handler, milk recording ────────────────────────
    ROLE_MILKMAN => [
        'milk.create', 'milk.view', 'milk.list', 'milk.edit',
        'cow.view', 'cow.list',
        'feed.view', 'feed.log',
        'alert.view',
        'dashboard.view', 'profile.*',
    ],

    // ── Farm: Worker — general task execution ────────────────────────────────
    ROLE_WORKER => [
        'task.view_assigned', 'task.update',
        'cow.view',
        'milk.create', 'milk.view',
        'feed.log',
        'dashboard.view', 'profile.*',
    ],
]);

// ── Core permission check ─────────────────────────────────────────────────────

/**
 * Check role permission with wildcard support.
 * CEO always returns true without hitting the matrix.
 * Subscription gates use canAccess() separately — both may be needed.
 */
function hasPermission(string $permission): bool {
    if (isSuperAdmin()) return true;
    $role  = currentRole();
    $perms = RBAC_PERMISSIONS[$role] ?? [];
    if (in_array($permission, $perms, true)) return true;
    [$module] = explode('.', $permission, 2);
    return in_array($module . '.*', $perms, true);
}

function requirePermission(string $permission, string $redirect = ''): void {
    if (hasPermission($permission)) return;
    flashMessage('error', 'You do not have permission to perform this action.');
    redirect($redirect ?: '/dashboard.php');
}

// ── Role identity helpers ─────────────────────────────────────────────────────

function isSupportStaff(): bool  { return currentRole() === ROLE_SUPPORT; }
function isFarmAdmin(): bool     { return currentRole() === ROLE_ADMIN; }
function isFarmOwner(): bool     { return currentRole() === ROLE_ADMIN && (bool)($_SESSION['is_owner'] ?? false); }
function isFarmManager(): bool   { return currentRole() === ROLE_MANAGER; }
function isFarmWorker(): bool    { return currentRole() === ROLE_WORKER; }

/** Returns true for platform-layer users (CEO or support staff). */
function isSaasUser(): bool      { return in_array(currentRole(), [ROLE_CEO, ROLE_SUPPORT], true); }
function isOrgUser(): bool       { return isSuperAdmin() || isSupportStaff(); }

// ── Post-login routing ────────────────────────────────────────────────────────

function dashboardUrl(): string {
    return match(currentRole()) {
        ROLE_CEO     => '/modules/super_admin/dashboard.php',
        ROLE_SUPPORT => '/modules/support/dashboard.php',
        ROLE_WORKER  => '/modules/workers/my_tasks.php',
        default      => '/dashboard.php',
    };
}
