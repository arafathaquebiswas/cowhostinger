<?php
/**
 * rbac.php — Role-Based Access Control engine.
 *
 * Defines the 4-layer role hierarchy and permission matrix.
 * Works alongside canAccess() (subscription gating) and farmFilter() (tenancy).
 *
 * Role hierarchy:
 *   superadmin > support_staff > admin > accountant/vet/reception > worker
 *
 * NOTE: currentRole() and hasRole() are defined in auth.php (already loaded).
 *       This file adds permission logic on top of those primitives.
 */

// ── Role constants ────────────────────────────────────────────────────────────

define('ROLE_CEO',       'superadmin');
define('ROLE_SUPPORT',   'support_staff');
define('ROLE_ADMIN',     'admin');
define('ROLE_ACCOUNTANT','accountant');
define('ROLE_VET',       'veterinarian');
define('ROLE_RECEPTION', 'reception');
define('ROLE_WORKER',    'worker');

// ── Permission matrix ─────────────────────────────────────────────────────────
// CEO (superadmin) bypasses this matrix entirely — always allowed.
// Wildcards: 'module.*' grants all actions under that module.

define('RBAC_PERMISSIONS', [

    ROLE_SUPPORT => [
        // Farm visibility (strictly read-only)
        'farm.list',
        'farm.view',
        'farm.support_view',
        // Subscription info (read-only — cannot change)
        'subscription.view',
        // Support tickets
        'ticket.view',
        'ticket.create',
        'ticket.respond',
        'ticket.assign',
        'ticket.close',
        // Monitoring & logs
        'error_log.view',
        'activity_log.view',
        'alert.view',
    ],

    ROLE_ADMIN => [
        'cow.*',       'milk.*',    'breeding.*', 'calf.*',
        'worker.*',    'task.*',    'equipment.*',
        'feed.*',      'medicine.*','diagnosis.*','treatment.*',
        'sales.*',
        'finance.*',
        'report.*',
        'alert.*',
        'user.*',
        'subscription.view',
        'ticket.create', 'ticket.view_own',
        'dashboard.view', 'profile.*',
    ],

    ROLE_ACCOUNTANT => [
        'finance.*',   'report.*',
        'cow.view',    'cow.list',
        'milk.view',   'milk.list',
        'sales.view',  'sales.list',
        'alert.view',
        'ticket.create','ticket.view_own',
        'subscription.view',
        'dashboard.view', 'profile.*',
    ],

    ROLE_VET => [
        'cow.*',       'diagnosis.*','treatment.*',
        'breeding.*',  'calf.*',
        'milk.view',   'milk.list',
        'alert.view',
        'ticket.create','ticket.view_own',
        'dashboard.view', 'profile.*',
    ],

    ROLE_RECEPTION => [
        'cow.view',    'cow.list',
        'milk.view',   'milk.list',
        'alert.view',
        'ticket.create','ticket.view_own',
        'dashboard.view', 'profile.*',
    ],

    ROLE_WORKER => [
        'task.view_assigned', 'task.update',
        'cow.view',
        'dashboard.view', 'profile.*',
    ],
]);

// ── Core permission check ─────────────────────────────────────────────────────

/**
 * Role-based permission check.
 * CEO always returns true.
 * Others checked against RBAC_PERMISSIONS with wildcard support.
 *
 * Note: This checks ROLE permissions only.
 *       Subscription gates use canAccess() in access_control.php.
 *       Both checks may be needed: hasPermission() && canAccess()
 */
function hasPermission(string $permission): bool {
    if (isSuperAdmin()) return true;

    $role  = currentRole();
    $perms = RBAC_PERMISSIONS[$role] ?? [];

    if (in_array($permission, $perms, true)) return true;

    // Wildcard: 'cow.*' grants 'cow.create', 'cow.view', etc.
    [$module] = explode('.', $permission, 2);
    return in_array($module . '.*', $perms, true);
}

/** Enforce permission or redirect with flash. */
function requirePermission(string $permission, string $redirect = ''): void {
    if (hasPermission($permission)) return;
    flashMessage('error', 'You do not have permission to perform this action.');
    redirect($redirect ?: '/dashboard.php');
}

// ── Role identity helpers ─────────────────────────────────────────────────────

function isSupportStaff(): bool {
    return currentRole() === ROLE_SUPPORT;
}

function isFarmAdmin(): bool {
    return currentRole() === ROLE_ADMIN;
}

function isFarmOwner(): bool {
    return currentRole() === ROLE_ADMIN && (bool)($_SESSION['is_owner'] ?? false);
}

function isFarmWorker(): bool {
    return currentRole() === ROLE_WORKER;
}

function isSaasUser(): bool {
    return in_array(currentRole(), [ROLE_CEO, ROLE_SUPPORT], true);
}

/** Support staff cannot belong to any specific farm. */
function isOrgUser(): bool {
    return isSuperAdmin() || isSupportStaff();
}

// ── Dashboard routing ─────────────────────────────────────────────────────────

function dashboardUrl(): string {
    return match(currentRole()) {
        ROLE_CEO     => '/modules/super_admin/dashboard.php',
        ROLE_SUPPORT => '/modules/support/dashboard.php',
        ROLE_WORKER  => '/modules/workers/my_tasks.php',
        default      => '/dashboard.php',
    };
}
