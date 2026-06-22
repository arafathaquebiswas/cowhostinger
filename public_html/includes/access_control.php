<?php
/**
 * access_control.php — Central access control engine.
 *
 * THIS IS THE ONLY PLACE WHERE ACCESS DECISIONS ARE MADE.
 *
 * Modules MUST use canAccess('feature.action') — never implement their own logic.
 *
 * Feature naming: <module>.<action>
 *   Examples: cow.create, finance.view, report.export, milk.analytics
 *
 * Requires: subscription_engine.php (getSubscription, resourceUsage)
 *           farm_guard.php          (isSuperAdmin, isImpersonating)
 */

/**
 * The single access gate.
 *
 * @param  string $feature  dot-separated feature key (e.g. 'cow.create')
 * @return bool             true = allowed, false = denied
 */
function canAccess(string $feature): bool {
    // CEO without impersonation has unconditional full access
    if (isSuperAdmin() && !isImpersonating()) return true;

    // Support staff are org-level users — not subject to farm subscription gates.
    // Their access is governed entirely by RBAC (hasPermission).
    if (isSupportStaff()) {
        // Allow read-only farm features; block all mutations and paid features
        return str_ends_with($feature, '.view')
            || str_ends_with($feature, '.list')
            || in_array($feature, ['ticket.view', 'ticket.respond', 'ticket.create', 'alert.view', 'dashboard.view'], true);
    }

    $sub = getSubscription();

    // ── Blocked state (expired or suspended) ──────────────────────────────────
    // Only read-only views are permitted; all mutations and gated features denied
    if ($sub['is_blocked']) {
        return in_array($feature, _allowedWhenBlocked(), true);
    }

    // ── Feature flag gates ─────────────────────────────────────────────────────
    return match($feature) {
        'finance.view',
        'finance.create',
        'finance.edit',
        'finance.delete'   => (bool)$sub['can_finance'],

        'report.view',
        'report.generate'  => (bool)$sub['can_reports'],

        'report.export'    => (bool)$sub['can_export'],

        'milk.analytics'   => (bool)$sub['can_milk_analytics'],

        'analytics.view'   => (bool)$sub['can_analytics'],

        'payroll.view',
        'payroll.create',
        'payroll.approve',
        'payroll.process'  => (bool)$sub['can_payroll'],

        // ── Resource count gates ───────────────────────────────────────────────
        'cow.create'       => resourceUsage('cows')['allowed'],
        'worker.create'    => resourceUsage('workers')['allowed'],
        'equipment.create' => resourceUsage('equipment')['allowed'],
        'feed.create'      => resourceUsage('feed')['allowed'],
        'medicine.create'  => resourceUsage('medicine')['allowed'],
        'diagnosis.create' => resourceUsage('diagnosis')['allowed'],
        'user.create'      => resourceUsage('users')['allowed'],

        // ── Everything else is allowed (core CRUD, views, etc.) ───────────────
        default => true,
    };
}

/**
 * Block-state gate — redirects any suspended or expired farm before write operations.
 *
 * Call this immediately after requireFarmScope() on any page that writes data.
 * CEO and support_staff are exempt (they bypass subscription state entirely).
 * Grace-period farms are NOT blocked — they retain write access until grace expires.
 */
function requireNotBlocked(): void {
    if (isSuperAdmin() || isSupportStaff()) return;
    $sub = getSubscription();
    if (!$sub['is_blocked']) return;
    $msg = $sub['is_suspended']
        ? 'Your account has been suspended. Contact AB IT support to restore access.'
        : 'Your subscription has expired. Please renew to continue.';
    flashMessage('error', $msg);
    redirect('/modules/subscription/index.php');
}

/**
 * Enforce access — redirect with flash message if denied.
 * Use at the top of any page/action that requires a feature.
 *
 * @param string $feature   The feature key being checked
 * @param string $redirect  Override redirect URL (defaults to subscription page)
 */
function requireAccess(string $feature, string $redirect = ''): void {
    if (canAccess($feature)) return;

    $sub = getSubscription();

    if ($sub['is_blocked']) {
        $msg = $sub['is_suspended']
            ? 'Your account has been suspended. Contact AB IT support.'
            : 'Your subscription has expired. Please renew to continue.';
        flashMessage('error', $msg);
        redirect('/modules/subscription/index.php');
    }

    // Feature locked by plan — store prompt flag for upgrade modal
    $_SESSION['_saas_upgrade_feature'] = $feature;
    flashMessage('error', _featureDeniedMessage($feature));
    redirect($redirect ?: '/modules/subscription/index.php');
}

/**
 * Return a human-readable denial message for a feature.
 */
function _featureDeniedMessage(string $feature): string {
    return match(true) {
        str_starts_with($feature, 'finance')   => 'Finance module requires a paid plan. Upgrade to unlock.',
        str_starts_with($feature, 'report')    => 'Reports & exports require a paid plan. Upgrade to unlock.',
        str_starts_with($feature, 'milk')      => 'Milk Analytics requires a paid plan. Upgrade to unlock.',
        str_starts_with($feature, 'analytics') => 'Advanced analytics requires a paid plan. Upgrade to unlock.',
        str_starts_with($feature, 'payroll')   => 'Payroll Management requires the Pro plan or higher. Upgrade to unlock.',
        str_ends_with($feature, '.create')     => 'Plan limit reached. Upgrade your plan to add more.',
        default                                => 'This feature is not available on your current plan. Upgrade to unlock.',
    };
}

/**
 * Features permitted even when subscription is expired or account is suspended.
 * Blocked farms may only read their own data — no mutations, no gated features.
 */
function _allowedWhenBlocked(): array {
    return [
        'dashboard.view',
        'subscription.view',
        'profile.view',
        'cow.view',     'cow.list',
        'milk.view',    'milk.list',
        'worker.view',  'worker.list',
        'equipment.view','equipment.list',
        'feed.view',    'feed.list',
        'medicine.view','medicine.list',
        'diagnosis.view','diagnosis.list',
        'treatment.view','treatment.list',
        'breeding.view','breeding.list',
        'sales.view',   'sales.list',
        'alert.view',
    ];
}

/**
 * Returns structured denial info — useful for JSON API responses or AJAX gates.
 *
 * @return array{denied: bool, reason: string, feature: string, upgrade_url: string}
 */
function accessDeniedInfo(string $feature): array {
    return [
        'denied'      => true,
        'reason'      => isSubscriptionBlocked() ? 'subscription_expired' : 'plan_limit',
        'feature'     => $feature,
        'upgrade_url' => '/modules/subscription/index.php',
        'message'     => _featureDeniedMessage($feature),
    ];
}
