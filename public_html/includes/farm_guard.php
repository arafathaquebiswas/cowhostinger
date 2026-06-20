<?php
/**
 * farm_guard.php — SaaS multi-tenancy helpers
 *
 * Include AFTER role_guard.php (which loads auth.php + functions.php).
 * Provides farm scoping, subscription plan enforcement, and super-admin bypass.
 */

// ── Farm identity ─────────────────────────────────────────────────────────────

function currentFarmId(): ?int {
    return isset($_SESSION['farm_id']) && (int)$_SESSION['farm_id'] > 0
        ? (int)$_SESSION['farm_id']
        : null;
}

function currentFarm(): ?array {
    return $_SESSION['_farm'] ?? null;
}

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
 * Super-admins see everything (1=1).
 * e.g.  "SELECT * FROM cows WHERE " . farmFilter() . " AND status='active'"
 */
function farmFilter(string $alias = ''): string {
    if (isSuperAdmin()) return '1=1';
    $fid = currentFarmId();
    if ($fid === null) return '1=0';          // locked out
    $col = $alias ? "`{$alias}`.farm_id" : 'farm_id';
    return $col . ' = ' . $fid;              // safe: int cast already done
}

/**
 * Shorthand for the current farm_id integer — for use in execute([]) arrays.
 * Returns 0 if no farm (causes safe empty result in farm-filtered queries).
 */
function fid(): int {
    return currentFarmId() ?? 0;
}

// ── Subscription / plan helpers ───────────────────────────────────────────────

function farmPlan(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $fid = currentFarmId();
    if (!$fid) {
        return $cache = ['name'=>'None','cows_limit'=>0,'users_limit'=>0,'can_export'=>0,'can_analytics'=>0];
    }

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT p.* FROM plans p
         JOIN subscriptions s ON s.plan_id = p.id
         WHERE s.farm_id = ? AND s.status IN ('active','trial')
         ORDER BY s.id DESC LIMIT 1"
    );
    $stmt->execute([$fid]);
    $row = $stmt->fetch();

    return $cache = $row ?: [
        'name'          => 'Free',
        'cows_limit'    => 20,
        'users_limit'   => 1,
        'can_export'    => 0,
        'can_analytics' => 0,
    ];
}

function farmPlanName(): string {
    return farmPlan()['name'] ?? 'Free';
}

function farmCowLimit(): int {
    return (int)(farmPlan()['cows_limit'] ?? 20);
}

function farmUsersLimit(): int {
    return (int)(farmPlan()['users_limit'] ?? 1);
}

function farmCanExport(): bool {
    return (bool)(farmPlan()['can_export'] ?? false);
}

function farmCanAnalytics(): bool {
    return (bool)(farmPlan()['can_analytics'] ?? false);
}

function farmActiveCowCount(): int {
    $fid = currentFarmId();
    if (!$fid) return 0;
    $stmt = getDB()->prepare(
        "SELECT COUNT(*) FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')"
    );
    $stmt->execute([$fid]);
    return (int)$stmt->fetchColumn();
}

function farmCanAddCow(): bool {
    if (isSuperAdmin()) return true;
    $limit = farmCowLimit();
    if ($limit >= 9999) return true;
    return farmActiveCowCount() < $limit;
}

function farmCanAddUser(): bool {
    if (isSuperAdmin()) return true;
    $limit = farmUsersLimit();
    if ($limit >= 99) return true;
    $fid  = currentFarmId();
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM users WHERE farm_id=? AND status='active'");
    $stmt->execute([$fid]);
    return (int)$stmt->fetchColumn() < $limit;
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
    return '<span style="display:inline-block;font-size:.6rem;font-weight:700;letter-spacing:.04em;'
        . 'padding:.1rem .45rem;border-radius:50px;background:' . $color . ';color:#fff;'
        . 'text-transform:uppercase;vertical-align:middle">' . e($name) . '</span>';
}
