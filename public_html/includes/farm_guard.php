<?php
/**
 * farm_guard.php — Multi-tenancy helpers.
 *
 * Responsibilities:
 *   - Farm identity (farmFilter, fid)
 *   - Impersonation (CEO login-as)
 *   - requireFarmScope()
 *   - UI helpers (farmPlanBadge, upgradePrompt, lockedNavItem, farmExpiryBanner)
 *
 * Subscription logic lives in subscription_engine.php.
 * Feature gating lives in access_control.php.
 * Both are auto-loaded at the bottom of this file.
 */

// ── Superadmin identity ───────────────────────────────────────────────────────

function isSuperAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'superadmin';
}

// ── RBAC + Activity logger (auto-loaded here so every page gets them) ─────────
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/activity_logger.php';

// ── Impersonation (CEO login-as a farm) ───────────────────────────────────────

function isImpersonating(): bool {
    return !empty($_SESSION['impersonating_as_farm_id']);
}

function impersonatingFarmId(): ?int {
    $v = $_SESSION['impersonating_as_farm_id'] ?? null;
    return $v !== null ? (int)$v : null;
}

// ── Farm scope ────────────────────────────────────────────────────────────────

function requireFarmScope(): void {
    requireAuth();
    // CEO and support staff are org-level users — no farm_id required
    if (isSuperAdmin() || isSupportStaff()) return;
    if (currentFarmId() === null) {
        flashMessage('error', 'Your account is not linked to a farm. Please contact support.');
        redirect('/index.php');
    }
}

// ── SQL filter helpers ────────────────────────────────────────────────────────

/**
 * Returns a safe SQL fragment for farm-level WHERE filtering.
 *
 * CEO without impersonation → "1=1" (sees all farms)
 * CEO impersonating         → "farm_id = N" (scoped to target farm)
 * Regular user              → "farm_id = N"
 * Unauthenticated/no farm   → "1=0" (returns nothing)
 *
 * NEVER add a ? placeholder — the integer is embedded directly.
 * Do NOT add fid() to execute() arrays when farmFilter() is in the WHERE.
 */
function farmFilter(string $alias = ''): string {
    $col = $alias ? "`{$alias}`.farm_id" : 'farm_id';

    $imp = impersonatingFarmId();
    if ($imp !== null) return $col . ' = ' . $imp;
    if (isSuperAdmin())  return '1=1';

    $fid = currentFarmId();
    if ($fid === null)   return '1=0';
    return $col . ' = ' . $fid;
}

/**
 * Current effective farm_id integer — for INSERT execute() arrays.
 * Returns impersonated farm_id when CEO is impersonating.
 */
function fid(): int {
    return impersonatingFarmId() ?? currentFarmId() ?? 0;
}

// ── UI helpers ────────────────────────────────────────────────────────────────

function farmPlanBadge(): string {
    $name  = currentPlanName();
    $color = match($name) {
        'Free'       => '#6B7280',
        'Basic'      => '#0284C7',
        'Pro'        => '#7C3AED',
        'Enterprise' => '#D97706',
        default      => '#6B7280',
    };
    return '<span class="plan-badge" style="background:' . $color . '">' . e($name) . '</span>';
}

function upgradePrompt(string $feature = ''): string {
    $label = $feature ?: 'this feature';
    $msg   = "Upgrade your plan to unlock <strong>{$label}</strong>.";
    return '<button type="button" class="btn btn-sm btn-upgrade" onclick="showUpgradeModal(\'' . e(addslashes($msg)) . '\')">'
         . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="17 11 12 6 7 11"/><line x1="12" y1="18" x2="12" y2="6"/></svg>'
         . ' Upgrade</button>';
}

function lockedNavItem(string $label, string $icon_svg, string $feature = ''): string {
    $tip = $feature ? "Upgrade to unlock {$feature}" : 'Upgrade your plan to unlock';
    return '<span class="nav-item nav-item-locked" title="' . e($tip) . '"'
         . ' onclick="showUpgradeModal(\'Upgrade to unlock ' . e(addslashes($feature ?: $label)) . '.\')">'
         . $icon_svg . ' ' . e($label)
         . ' <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"'
         . ' style="opacity:.5"><rect x="3" y="11" width="18" height="11" rx="2"/>'
         . '<path d="M7 11V7a5 5 0 0110 0v4"/></svg>'
         . '</span>';
}

// ── Backward-compat aliases (delegate to subscription_engine / access_control) ─
// These keep existing view code working without changes.
// New module code should use canAccess() and getSubscription() directly.

function farmPlan(): array            { return getSubscription(); }
function farmPlanName(): string       { return currentPlanName(); }
function farmCowLimit(): ?int         { return getSubscription()['cows_limit'] ?? null; }
function farmUsersLimit(): ?int       { return getSubscription()['users_limit'] ?? null; }
function farmCanExport(): bool        { return canAccess('report.export'); }
function farmCanAnalytics(): bool     { return canAccess('analytics.view'); }
function farmCanFinance(): bool       { return canAccess('finance.view'); }
function farmCanReports(): bool       { return canAccess('report.view'); }
function farmCanMilkAnalytics(): bool { return canAccess('milk.analytics'); }
function farmIsBlocked(): bool        { return isSubscriptionBlocked(); }
function farmIsFreePlan(): bool       { return isFreePlan(); }
function farmDaysLeft(): ?int         { return subscriptionDaysLeft(); }
function farmExpiryBanner(): string   { return subscriptionExpiryBanner(); }
function farmAllUsage(): array        { return allResourceUsage(); }
function farmActiveCowCount(): int    { return resourceUsage('cows')['current']; }
function farmResourceLimit(string $r): array { return resourceUsage($r); }
function farmCanAddCow(): bool        { return canAccess('cow.create'); }
function farmCanAddWorker(): bool     { return canAccess('worker.create'); }
function farmCanAddEquipment(): bool  { return canAccess('equipment.create'); }
function farmCanAddFeed(): bool       { return canAccess('feed.create'); }
function farmCanAddMedicine(): bool   { return canAccess('medicine.create'); }
function farmCanAddDiagnosis(): bool  { return canAccess('diagnosis.create'); }
function farmCanAddUser(): bool       { return canAccess('user.create'); }

// ── Auto-load engines ─────────────────────────────────────────────────────────
// Any file that includes farm_guard.php automatically gets subscription + access control.

require_once __DIR__ . '/subscription_engine.php';
require_once __DIR__ . '/access_control.php';
