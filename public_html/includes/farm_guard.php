<?php
/**
 * farm_guard.php — SaaS multi-tenancy helpers
 *
 * Include AFTER role_guard.php (which loads auth.php + functions.php).
 * Provides farm scoping, subscription plan enforcement, and super-admin bypass.
 */

// ── Farm identity (currentFarmId/currentFarm defined in auth.php) ─────────────

function isSuperAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'superadmin';
}

// Require authenticated user with a farm assigned (or superadmin)
function requireFarmScope(): void {
    requireAuth();
    if (!isSuperAdmin() && currentFarmId() === null) {
        flashMessage('error', 'Your account is not linked to a farm. Please contact support.');
        redirect('/index.php');
    }
}

// ── SQL helpers ───────────────────────────────────────────────────────────────

/**
 * Returns a safe SQL fragment for farm-level WHERE filtering.
 * Super-admins see everything (1=1) UNLESS impersonating a farm.
 * e.g.  "SELECT * FROM cows WHERE " . farmFilter() . " AND status='active'"
 */
function farmFilter(string $alias = ''): string {
    // When impersonating, superadmin is scoped to the target farm
    $imp_fid = impersonatingFarmId();
    if ($imp_fid !== null) {
        $col = $alias ? "`{$alias}`.farm_id" : 'farm_id';
        return $col . ' = ' . $imp_fid;
    }
    if (isSuperAdmin()) return '1=1';
    $fid = currentFarmId();
    if ($fid === null) return '1=0';          // locked out
    $col = $alias ? "`{$alias}`.farm_id" : 'farm_id';
    return $col . ' = ' . $fid;              // safe: int cast already done
}

/**
 * Shorthand for the current farm_id integer — for use in execute([]) arrays.
 * Returns the impersonated farm_id when CEO is impersonating.
 */
function fid(): int {
    return impersonatingFarmId() ?? currentFarmId() ?? 0;
}

// ── Subscription / plan helpers ───────────────────────────────────────────────

/**
 * Returns full plan + subscription state for the current farm.
 * Cached per request via static. Handles auto expiry → grace → expired transitions.
 */
function farmPlan(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    if (isSuperAdmin()) {
        return $cache = [
            'name'             => 'Superadmin',
            'price_monthly'    => 0,
            'cows_limit'       => null,
            'workers_limit'    => null,
            'equipment_limit'  => null,
            'feed_limit'       => null,
            'medicine_limit'   => null,
            'diagnosis_limit'  => null,
            'users_limit'      => null,
            'can_export'       => 1,
            'can_analytics'    => 1,
            'can_finance'      => 1,
            'can_reports'      => 1,
            'can_milk_analytics' => 1,
            'sub_status'       => 'active',
            'end_date'         => null,
            'grace_end_date'   => null,
            'days_left'        => null,
            'is_free'          => false,
            'is_expired'       => false,
            'is_grace'         => false,
            'is_suspended'     => false,
            'is_blocked'       => false,
        ];
    }

    $fid = currentFarmId();
    if (!$fid) {
        return $cache = _freePlanDefaults();
    }

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT p.*, s.id AS sub_id, s.status AS sub_status, s.start_date, s.end_date, s.grace_end_date,
                f.status AS farm_status
         FROM farms f
         LEFT JOIN subscriptions s ON s.farm_id = f.id
         LEFT JOIN plans p ON p.id = s.plan_id
         WHERE f.id = ?
         ORDER BY s.id DESC LIMIT 1"
    );
    $stmt->execute([$fid]);
    $row = $stmt->fetch();

    if (!$row || !$row['sub_id']) {
        return $cache = _freePlanDefaults();
    }

    // Auto-transition expired subscriptions
    $today      = date('Y-m-d');
    $sub_status = $row['sub_status'];

    if ($row['farm_status'] === 'suspended') {
        $sub_status = 'suspended';
    } elseif ($sub_status === 'active' && $row['end_date'] && $row['end_date'] < $today) {
        $grace_days = 5;
        $grace_end  = date('Y-m-d', strtotime($row['end_date'] . " +{$grace_days} days"));
        if ($row['grace_end_date'] === null) {
            $db->prepare("UPDATE subscriptions SET status='grace', grace_end_date=? WHERE id=?")
               ->execute([$grace_end, $row['sub_id']]);
            $row['grace_end_date'] = $grace_end;
        }
        $sub_status = 'grace';
    } elseif ($sub_status === 'grace' && $row['grace_end_date'] && $row['grace_end_date'] < $today) {
        $db->prepare("UPDATE subscriptions SET status='expired' WHERE id=?")->execute([$row['sub_id']]);
        $sub_status = 'expired';
    }

    $days_left = null;
    if ($row['end_date']) {
        $days_left = (int)ceil((strtotime($row['end_date']) - strtotime($today)) / 86400);
    }

    $is_free      = (float)($row['price_monthly'] ?? 0) == 0;
    $is_expired   = $sub_status === 'expired';
    $is_grace     = $sub_status === 'grace';
    $is_suspended = $sub_status === 'suspended';
    $is_blocked   = $is_expired || $is_suspended;

    return $cache = array_merge($row, [
        'sub_status'   => $sub_status,
        'days_left'    => $days_left,
        'is_free'      => $is_free,
        'is_expired'   => $is_expired,
        'is_grace'     => $is_grace,
        'is_suspended' => $is_suspended,
        'is_blocked'   => $is_blocked,
    ]);
}

function _freePlanDefaults(): array {
    return [
        'name'             => 'Free',
        'price_monthly'    => 0,
        'cows_limit'       => 5,
        'workers_limit'    => 2,
        'equipment_limit'  => 5,
        'feed_limit'       => 5,
        'medicine_limit'   => 5,
        'diagnosis_limit'  => 10,
        'users_limit'      => 2,
        'can_export'       => 0,
        'can_analytics'    => 0,
        'can_finance'      => 0,
        'can_reports'      => 0,
        'can_milk_analytics' => 0,
        'sub_status'       => 'trial',
        'end_date'         => null,
        'grace_end_date'   => null,
        'days_left'        => null,
        'is_free'          => true,
        'is_expired'       => false,
        'is_grace'         => false,
        'is_suspended'     => false,
        'is_blocked'       => false,
    ];
}

function farmPlanName(): string   { return farmPlan()['name'] ?? 'Free'; }
function farmCowLimit(): ?int     { $v = farmPlan()['cows_limit']      ?? null; return $v !== null ? (int)$v : null; }
function farmUsersLimit(): ?int   { $v = farmPlan()['users_limit']     ?? null; return $v !== null ? (int)$v : null; }
function farmCanExport(): bool    { return (bool)(farmPlan()['can_export']      ?? false); }
function farmCanAnalytics(): bool { return (bool)(farmPlan()['can_analytics']   ?? false); }
function farmCanFinance(): bool   { return (bool)(farmPlan()['can_finance']     ?? false); }
function farmCanReports(): bool   { return (bool)(farmPlan()['can_reports']     ?? false); }
function farmCanMilkAnalytics(): bool { return (bool)(farmPlan()['can_milk_analytics'] ?? false); }
function farmIsBlocked(): bool    { return (bool)(farmPlan()['is_blocked']      ?? false); }
function farmIsFreePlan(): bool   { return (bool)(farmPlan()['is_free']         ?? true); }

/**
 * Check if the farm can create one more of a given resource.
 * Returns ['allowed'=>bool, 'current'=>int, 'max'=>int|null, 'pct'=>float]
 */
function farmResourceLimit(string $resource): array {
    if (isSuperAdmin()) return ['allowed'=>true,'current'=>0,'max'=>null,'pct'=>0.0];

    $plan = farmPlan();
    if ($plan['is_blocked']) return ['allowed'=>false,'current'=>0,'max'=>0,'pct'=>100.0];

    $limit_col = match($resource) {
        'cows'      => 'cows_limit',
        'workers'   => 'workers_limit',
        'equipment' => 'equipment_limit',
        'feed'      => 'feed_limit',
        'medicine'  => 'medicine_limit',
        'diagnosis' => 'diagnosis_limit',
        'users'     => 'users_limit',
        default     => null,
    };

    $max = $limit_col ? ($plan[$limit_col] !== null ? (int)$plan[$limit_col] : null) : null;

    if ($max === null) {
        return ['allowed'=>true,'current'=>0,'max'=>null,'pct'=>0.0];
    }

    $fid = fid();
    $db  = getDB();

    $current = match($resource) {
        'cows'      => _farmCount($db, "SELECT COUNT(*) FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')", $fid),
        'workers'   => _farmCount($db, "SELECT COUNT(*) FROM workers w JOIN users u ON u.id=w.user_id WHERE u.farm_id=? AND w.status='active'", $fid),
        'equipment' => _farmCount($db, "SELECT COUNT(*) FROM equipment WHERE farm_id=? AND status NOT IN ('disposed','sold')", $fid),
        'feed'      => _farmCount($db, "SELECT COUNT(*) FROM feed_inventory WHERE farm_id=?", $fid),
        'medicine'  => _farmCount($db, "SELECT COUNT(*) FROM medicine_inventory WHERE farm_id=?", $fid),
        'diagnosis' => _farmCount($db, "SELECT COUNT(*) FROM diagnosis_records WHERE farm_id=?", $fid),
        'users'     => _farmCount($db, "SELECT COUNT(*) FROM users WHERE farm_id=? AND status='active'", $fid),
        default     => 0,
    };

    return [
        'allowed' => $current < $max,
        'current' => $current,
        'max'     => $max,
        'pct'     => $max > 0 ? min(100.0, round($current / $max * 100, 1)) : 100.0,
    ];
}

function _farmCount(\PDO $db, string $sql, int $fid): int {
    $s = $db->prepare($sql);
    $s->execute([$fid]);
    return (int)$s->fetchColumn();
}

/** Returns all usage counts at once (for dashboard — one pass) */
function farmAllUsage(): array {
    if (isSuperAdmin()) {
        return array_fill_keys(['cows','workers','equipment','feed','medicine','diagnosis','users'], 0);
    }
    $fid = fid();
    $db  = getDB();
    return [
        'cows'      => _farmCount($db, "SELECT COUNT(*) FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')", $fid),
        'workers'   => _farmCount($db, "SELECT COUNT(*) FROM workers w JOIN users u ON u.id=w.user_id WHERE u.farm_id=? AND w.status='active'", $fid),
        'equipment' => _farmCount($db, "SELECT COUNT(*) FROM equipment WHERE farm_id=? AND status NOT IN ('disposed','sold')", $fid),
        'feed'      => _farmCount($db, "SELECT COUNT(*) FROM feed_inventory WHERE farm_id=?", $fid),
        'medicine'  => _farmCount($db, "SELECT COUNT(*) FROM medicine_inventory WHERE farm_id=?", $fid),
        'diagnosis' => _farmCount($db, "SELECT COUNT(*) FROM diagnosis_records WHERE farm_id=?", $fid),
        'users'     => _farmCount($db, "SELECT COUNT(*) FROM users WHERE farm_id=? AND status='active'", $fid),
    ];
}

// Convenience wrappers kept for backward compat
function farmActiveCowCount(): int   { return farmResourceLimit('cows')['current']; }
function farmCanAddCow(): bool       { return farmResourceLimit('cows')['allowed']; }
function farmCanAddUser(): bool      { return farmResourceLimit('users')['allowed']; }
function farmCanAddWorker(): bool    { return farmResourceLimit('workers')['allowed']; }
function farmCanAddEquipment(): bool { return farmResourceLimit('equipment')['allowed']; }
function farmCanAddFeed(): bool      { return farmResourceLimit('feed')['allowed']; }
function farmCanAddMedicine(): bool  { return farmResourceLimit('medicine')['allowed']; }
function farmCanAddDiagnosis(): bool { return farmResourceLimit('diagnosis')['allowed']; }

// ── Subscription expiry alert helpers ─────────────────────────────────────────

function farmDaysLeft(): ?int { return farmPlan()['days_left']; }

function farmExpiryBanner(): string {
    $plan = farmPlan();
    if ($plan['is_blocked']) {
        if ($plan['is_suspended']) {
            return '<div class="saas-banner saas-banner-danger">Your farm account has been suspended. Contact <strong>AB IT support</strong> to restore access.</div>';
        }
        return '<div class="saas-banner saas-banner-danger">Your subscription has <strong>expired</strong>. Upgrade now to regain full access. <a href="/modules/subscription/index.php" class="saas-banner-btn">Renew Now</a></div>';
    }
    if ($plan['is_grace']) {
        $end = $plan['grace_end_date'] ?? 'soon';
        return "<div class=\"saas-banner saas-banner-warning\">You are in the <strong>grace period</strong>. Access ends on <strong>{$end}</strong>. <a href=\"/modules/subscription/index.php\" class=\"saas-banner-btn\">Upgrade Now</a></div>";
    }
    $days = $plan['days_left'];
    if ($days !== null) {
        if ($days <= 1) {
            return '<div class="saas-banner saas-banner-danger">Your subscription expires <strong>today</strong>! <a href="/modules/subscription/index.php" class="saas-banner-btn">Renew Now</a></div>';
        }
        if ($days <= 3) {
            return "<div class=\"saas-banner saas-banner-warning\">Subscription expires in <strong>{$days} days</strong>. <a href=\"/modules/subscription/index.php\" class=\"saas-banner-btn\">Renew</a></div>";
        }
        if ($days <= 7) {
            return "<div class=\"saas-banner saas-banner-info\">Subscription expires in <strong>{$days} days</strong>. <a href=\"/modules/subscription/index.php\" class=\"saas-banner-btn\">Renew</a></div>";
        }
    }
    return '';
}

// ── Impersonation helpers (CEO can login-as a farm) ───────────────────────────

function isImpersonating(): bool {
    return !empty($_SESSION['impersonating_as_farm_id']);
}

function impersonatingFarmId(): ?int {
    return $_SESSION['impersonating_as_farm_id'] ?? null;
}

// ── Plan badge HTML ───────────────────────────────────────────────────────────

function farmPlanBadge(): string {
    $name = farmPlanName();
    $color = match($name) {
        'Free'       => '#6B7280',
        'Basic'      => '#0284C7',
        'Pro'        => '#7C3AED',
        'Enterprise' => '#D97706',
        default      => '#6B7280',
    };
    return '<span class="plan-badge" style="background:' . $color . '">' . e($name) . '</span>';
}

/** Renders an upgrade prompt modal trigger button (inline) */
function upgradePrompt(string $feature = ''): string {
    $msg = $feature ? "Upgrade your plan to unlock <strong>{$feature}</strong>." : 'Upgrade your plan to unlock this feature.';
    return '<button type="button" class="btn btn-sm btn-upgrade" onclick="showUpgradeModal(\'' . e(addslashes($msg)) . '\')">'
         . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="17 11 12 6 7 11"/><line x1="12" y1="18" x2="12" y2="6"/></svg>'
         . ' Upgrade</button>';
}

/** Renders a locked feature link replacement */
function lockedNavItem(string $label, string $icon_svg, string $feature = ''): string {
    $tip = $feature ? "Upgrade to unlock {$feature}" : 'Upgrade your plan to unlock';
    return '<span class="nav-item nav-item-locked" title="' . e($tip) . '" onclick="showUpgradeModal(\'Upgrade to unlock ' . e(addslashes($feature ?: $label)) . '.\')">'
         . $icon_svg . ' ' . e($label)
         . ' <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>'
         . '</span>';
}
