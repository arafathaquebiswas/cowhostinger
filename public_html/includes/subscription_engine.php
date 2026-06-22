<?php
/**
 * subscription_engine.php — Single source of truth for subscription state.
 *
 * ALL subscription logic lives here. No other file may calculate plan limits,
 * expiry dates, grace periods, or feature flags.
 *
 * Load order: auth.php → farm_guard.php (defines fid/isSuperAdmin/isImpersonating)
 *             → this file → access_control.php
 */

// ── Internal state builder ────────────────────────────────────────────────────

function _subEngine(): array {
    if (isset($GLOBALS['_sub_engine_cache'])) return $GLOBALS['_sub_engine_cache'];

    // CEO without impersonation → fully unlimited, no restrictions
    if (isSuperAdmin() && !isImpersonating()) {
        return $GLOBALS['_sub_engine_cache'] = _subUnlimited();
    }

    $fid = fid();
    if (!$fid) {
        return $GLOBALS['_sub_engine_cache'] = _subFree();
    }

    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT f.status AS farm_status,
                    s.id AS sub_id, s.status AS sub_status,
                    s.start_date, s.end_date, s.grace_end_date,
                    p.id AS plan_id, p.name, p.price_monthly,
                    p.cows_limit, p.workers_limit, p.equipment_limit,
                    p.feed_limit, p.medicine_limit, p.diagnosis_limit,
                    p.users_limit, p.can_export, p.can_analytics,
                    p.can_finance, p.can_reports, p.can_milk_analytics,
                    p.can_payroll, p.billing_days
             FROM farms f
             LEFT JOIN subscriptions s ON s.farm_id = f.id
             LEFT JOIN plans p ON p.id = s.plan_id
             WHERE f.id = ?
             ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute([$fid]);
        $data = $stmt->fetch();
    } catch (\Throwable $e) {
        error_log('[SUB_ENGINE] ' . $e->getMessage());
        return $GLOBALS['_sub_engine_cache'] = _subFree();
    }

    if (!$data || !$data['sub_id']) {
        return $GLOBALS['_sub_engine_cache'] = _subFree($data['farm_status'] ?? 'active');
    }

    $today      = date('Y-m-d');
    $sub_status = $data['sub_status'];

    // Farm suspended by CEO → always blocked regardless of sub status
    if ($data['farm_status'] === 'suspended') {
        $sub_status = 'suspended';

    // Active sub past end_date → start grace period
    } elseif ($sub_status === 'active' && $data['end_date'] && $data['end_date'] < $today) {
        $grace_days = (int)(_platformSetting('grace_period_days') ?? 5);
        $grace_end  = date('Y-m-d', strtotime($data['end_date'] . " +{$grace_days} days"));
        if (!$data['grace_end_date']) {
            try {
                $db->prepare("UPDATE subscriptions SET status='grace', grace_end_date=? WHERE id=?")
                   ->execute([$grace_end, $data['sub_id']]);
            } catch (\Throwable $e) { /* non-fatal */ }
            $data['grace_end_date'] = $grace_end;
        }
        $sub_status = 'grace';

    // Grace period past grace_end_date → expired
    } elseif ($sub_status === 'grace' && $data['grace_end_date'] && $data['grace_end_date'] < $today) {
        try {
            $db->prepare("UPDATE subscriptions SET status='expired' WHERE id=?")->execute([$data['sub_id']]);
        } catch (\Throwable $e) { /* non-fatal */ }
        $sub_status = 'expired';
    }

    $days_left = null;
    if ($data['end_date']) {
        $days_left = (int)ceil((strtotime($data['end_date']) - strtotime($today)) / 86400);
    }

    $is_free      = (float)($data['price_monthly'] ?? 0) == 0.0;
    $is_expired   = $sub_status === 'expired';
    $is_grace     = $sub_status === 'grace';
    $is_suspended = $sub_status === 'suspended';
    $is_blocked   = $is_expired || $is_suspended;

    return $GLOBALS['_sub_engine_cache'] = [
        // Plan identity
        'plan_id'          => (int)($data['plan_id']       ?? 0),
        'name'             => $data['name']                 ?? 'Free',
        'price_monthly'    => (float)($data['price_monthly'] ?? 0),
        'billing_days'     => $data['billing_days']         ?? null,
        // Subscription state
        'sub_id'           => (int)$data['sub_id'],
        'sub_status'       => $sub_status,
        'start_date'       => $data['start_date'],
        'end_date'         => $data['end_date'],
        'grace_end_date'   => $data['grace_end_date']       ?? null,
        'days_left'        => $days_left,
        // Computed flags
        'is_free'          => $is_free,
        'is_expired'       => $is_expired,
        'is_grace'         => $is_grace,
        'is_suspended'     => $is_suspended,
        'is_blocked'       => $is_blocked,
        'is_unlimited'     => false,
        // Resource limits (null = unlimited)
        'cows_limit'       => $data['cows_limit']       !== null ? (int)$data['cows_limit']       : null,
        'workers_limit'    => $data['workers_limit']    !== null ? (int)$data['workers_limit']    : null,
        'equipment_limit'  => $data['equipment_limit']  !== null ? (int)$data['equipment_limit']  : null,
        'feed_limit'       => $data['feed_limit']       !== null ? (int)$data['feed_limit']       : null,
        'medicine_limit'   => $data['medicine_limit']   !== null ? (int)$data['medicine_limit']   : null,
        'diagnosis_limit'  => $data['diagnosis_limit']  !== null ? (int)$data['diagnosis_limit']  : null,
        'users_limit'      => $data['users_limit']      !== null ? (int)$data['users_limit']      : null,
        // Feature flags
        'can_export'         => (bool)($data['can_export']         ?? false),
        'can_analytics'      => (bool)($data['can_analytics']      ?? false),
        'can_finance'        => (bool)($data['can_finance']        ?? false),
        'can_reports'        => (bool)($data['can_reports']        ?? false),
        'can_milk_analytics' => (bool)($data['can_milk_analytics'] ?? false),
        'can_payroll'        => (bool)($data['can_payroll']        ?? false),
    ];
}

function _subFree(string $farm_status = 'active'): array {
    $is_suspended = $farm_status === 'suspended';
    return [
        'plan_id'          => 1,
        'name'             => 'Free',
        'price_monthly'    => 0.0,
        'billing_days'     => null,
        'sub_id'           => 0,
        'sub_status'       => $is_suspended ? 'suspended' : 'trial',
        'start_date'       => null,
        'end_date'         => null,
        'grace_end_date'   => null,
        'days_left'        => null,
        'is_free'          => true,
        'is_expired'       => false,
        'is_grace'         => false,
        'is_suspended'     => $is_suspended,
        'is_blocked'       => $is_suspended,
        'is_unlimited'     => false,
        'cows_limit'       => 20,
        'workers_limit'    => 3,
        'equipment_limit'  => 5,
        'feed_limit'       => null,
        'medicine_limit'   => null,
        'diagnosis_limit'  => null,
        'users_limit'      => 3,
        'can_export'         => true,
        'can_analytics'      => false,
        'can_finance'        => true,
        'can_reports'        => false,
        'can_milk_analytics' => false,
        'can_payroll'        => false,
    ];
}

function _subUnlimited(): array {
    return [
        'plan_id'          => 0,
        'name'             => 'Superadmin',
        'price_monthly'    => 0.0,
        'billing_days'     => null,
        'sub_id'           => 0,
        'sub_status'       => 'active',
        'start_date'       => null,
        'end_date'         => null,
        'grace_end_date'   => null,
        'days_left'        => null,
        'is_free'          => false,
        'is_expired'       => false,
        'is_grace'         => false,
        'is_suspended'     => false,
        'is_blocked'       => false,
        'is_unlimited'     => true,
        'cows_limit'       => null,
        'workers_limit'    => null,
        'equipment_limit'  => null,
        'feed_limit'       => null,
        'medicine_limit'   => null,
        'diagnosis_limit'  => null,
        'users_limit'      => null,
        'can_export'         => true,
        'can_analytics'      => true,
        'can_finance'        => true,
        'can_reports'        => true,
        'can_milk_analytics' => true,
        'can_payroll'        => true,
    ];
}

function _platformSetting(string $key): ?string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $s = getDB()->prepare("SELECT value FROM platform_settings WHERE `key`=? LIMIT 1");
        $s->execute([$key]);
        return $cache[$key] = (($v = $s->fetchColumn()) !== false) ? (string)$v : null;
    } catch (\Throwable) {
        return $cache[$key] = null;
    }
}

// ── Public API ─────────────────────────────────────────────────────────────────

/** Flush the per-request cache — call after recording a payment or status change. */
function _subEngineFlush(): void {
    unset($GLOBALS['_sub_engine_cache']);
}

/** Full subscription state for the current farm. Cached per request. */
function getSubscription(): array { return _subEngine(); }

function subscriptionDaysLeft(): ?int  { return _subEngine()['days_left']; }
function isSubscriptionExpired(): bool { return _subEngine()['is_expired']; }
function isSubscriptionBlocked(): bool { return _subEngine()['is_blocked']; }
function isGracePeriod(): bool         { return _subEngine()['is_grace']; }
function isFreePlan(): bool            { return _subEngine()['is_free']; }
function currentPlanName(): string     { return _subEngine()['name']; }

/**
 * Count-based resource gate. Returns usage state for a single resource type.
 *
 * @param string $resource  cows | workers | equipment | feed | medicine | diagnosis | users
 * @return array{allowed: bool, current: int, max: int|null, pct: float}
 */
function resourceUsage(string $resource): array {
    $sub = _subEngine();

    if ($sub['is_unlimited']) {
        return ['allowed' => true, 'current' => 0, 'max' => null, 'pct' => 0.0];
    }
    if ($sub['is_blocked']) {
        return ['allowed' => false, 'current' => 0, 'max' => 0, 'pct' => 100.0];
    }

    $max_key = $resource . '_limit';
    $max     = $sub[$max_key] ?? null;

    // null limit = plan has no cap for this resource
    if ($max === null) {
        return ['allowed' => true, 'current' => 0, 'max' => null, 'pct' => 0.0];
    }

    $fid = fid();
    $db  = getDB();

    $current = match($resource) {
        'cows'      => _rcount($db, "SELECT COUNT(*) FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')", $fid),
        'workers'   => _rcount($db, "SELECT COUNT(*) FROM workers w JOIN users u ON u.id=w.user_id WHERE u.farm_id=? AND w.status='active'", $fid),
        'equipment' => _rcount($db, "SELECT COUNT(*) FROM equipment WHERE farm_id=? AND status NOT IN ('disposed','sold')", $fid),
        'feed'      => _rcount($db, "SELECT COUNT(*) FROM feed_inventory WHERE farm_id=?", $fid),
        'medicine'  => _rcount($db, "SELECT COUNT(*) FROM medicine_inventory WHERE farm_id=?", $fid),
        'diagnosis' => _rcount($db, "SELECT COUNT(*) FROM diagnosis_records WHERE farm_id=?", $fid),
        'users'     => _rcount($db, "SELECT COUNT(*) FROM users WHERE farm_id=? AND status='active'", $fid),
        default     => 0,
    };

    return [
        'allowed' => $current < $max,
        'current' => $current,
        'max'     => $max,
        'pct'     => $max > 0 ? min(100.0, round($current / $max * 100, 1)) : 100.0,
    ];
}

function _rcount(\PDO $db, string $sql, int $fid): int {
    $s = $db->prepare($sql);
    $s->execute([$fid]);
    return (int)$s->fetchColumn();
}

/** All resource usages in one call — use for dashboard rendering. */
function allResourceUsage(): array {
    $sub = _subEngine();
    if ($sub['is_unlimited']) {
        return array_fill_keys(['cows','workers','equipment','feed','medicine','diagnosis','users'], 0);
    }
    $fid = fid();
    $db  = getDB();
    return [
        'cows'      => _rcount($db, "SELECT COUNT(*) FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')", $fid),
        'workers'   => _rcount($db, "SELECT COUNT(*) FROM workers w JOIN users u ON u.id=w.user_id WHERE u.farm_id=? AND w.status='active'", $fid),
        'equipment' => _rcount($db, "SELECT COUNT(*) FROM equipment WHERE farm_id=? AND status NOT IN ('disposed','sold')", $fid),
        'feed'      => _rcount($db, "SELECT COUNT(*) FROM feed_inventory WHERE farm_id=?", $fid),
        'medicine'  => _rcount($db, "SELECT COUNT(*) FROM medicine_inventory WHERE farm_id=?", $fid),
        'diagnosis' => _rcount($db, "SELECT COUNT(*) FROM diagnosis_records WHERE farm_id=?", $fid),
        'users'     => _rcount($db, "SELECT COUNT(*) FROM users WHERE farm_id=? AND status='active'", $fid),
    ];
}

/**
 * Build the subscription expiry banner HTML.
 * Returns empty string when no banner is needed.
 */
function subscriptionExpiryBanner(): string {
    $sub = _subEngine();

    if ($sub['is_blocked']) {
        if ($sub['is_suspended']) {
            return '<div class="saas-banner saas-banner-danger">Your farm account has been <strong>suspended</strong>. Contact <strong>AB IT support</strong> to restore access.</div>';
        }
        return '<div class="saas-banner saas-banner-danger">Your subscription has <strong>expired</strong>. Renew now to restore access. <a href="/modules/subscription/index.php" class="saas-banner-btn">Renew Now</a></div>';
    }

    if ($sub['is_grace']) {
        $end = $sub['grace_end_date'] ?? 'soon';
        return "<div class=\"saas-banner saas-banner-warning\">You are in the <strong>grace period</strong>. Full access ends on <strong>{$end}</strong>. <a href=\"/modules/subscription/index.php\" class=\"saas-banner-btn\">Upgrade Now</a></div>";
    }

    $days = $sub['days_left'];
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
