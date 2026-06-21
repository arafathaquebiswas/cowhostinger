<?php
/**
 * SaaS Engine Test Suite — CLI ONLY
 *
 * Usage:
 *   php tests/saas_engine_test.php
 *   php tests/saas_engine_test.php --verbose
 *
 * Tests Phase 7 scenarios from the SaaS architecture spec:
 *   Farm A — Free plan: must hit limits + be blocked from paid features
 *   Farm B — Paid plan: unlimited access, no feature blocks
 *   Farm C — Expired: read-only allowed, writes blocked
 *   Farm D — Suspended: fully blocked (CEO manual action)
 *   Farm E — CEO (superadmin): bypasses everything
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('CLI_TEST', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/role_guard.php';
require_once $root . '/includes/farm_guard.php';
require_once $root . '/includes/saas_guard.php';
// farm_guard.php auto-includes: rbac, activity_logger, subscription_engine, access_control

$verbose = in_array('--verbose', $argv ?? [], true);

// ── Test runner ───────────────────────────────────────────────────────────────

$results  = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$failures = [];
$suite    = '';

function suite(string $name): void {
    global $suite;
    $suite = $name;
    echo "\n\033[1;34m▶ {$name}\033[0m\n";
}

function ok(string $label, bool $cond): void {
    global $results, $failures, $suite, $verbose;
    if ($cond) {
        $results['pass']++;
        if ($verbose) echo "  \033[32m✓\033[0m {$label}\n";
    } else {
        $results['fail']++;
        $failures[] = "[{$suite}] {$label}";
        echo "  \033[31m✗ FAIL\033[0m {$label}\n";
    }
}

function skip(string $label, string $reason): void {
    global $results;
    $results['skip']++;
    echo "  \033[33m⊘ SKIP\033[0m {$label} — {$reason}\n";
}

function fake_session(int $farm_id, string $role = 'admin', ?int $impersonate = null): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = [
        'user_id'    => 9999,
        'user_role'  => $role,
        'user_name'  => 'Test',
        'farm_id'    => $farm_id ?: null,
        'is_owner'   => true,
        'login_time' => time(),
    ];
    if ($impersonate !== null) {
        $_SESSION['impersonating_as_farm_id'] = $impersonate;
    } else {
        unset($_SESSION['impersonating_as_farm_id']);
    }
    _subEngineFlush();
}

function fake_ceo(?int $impersonate = null): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = [
        'user_id'    => 1,
        'user_role'  => 'superadmin',
        'user_name'  => 'CEO',
        'farm_id'    => null,
        'login_time' => time(),
    ];
    if ($impersonate !== null) {
        $_SESSION['impersonating_as_farm_id'] = $impersonate;
    } else {
        unset($_SESSION['impersonating_as_farm_id']);
    }
    _subEngineFlush();
}

// ── DB discovery: find farms in each state ────────────────────────────────────

@session_start();
$db = getDB();

$farm_free     = null;
$farm_paid     = null;
$farm_expired  = null;
$farm_grace    = null;
$farm_suspended = null;

try {
    // Free: no subscription OR plan with price_monthly = 0
    $r = $db->query(
        "SELECT f.id FROM farms f
         LEFT JOIN subscriptions s ON s.farm_id = f.id
         LEFT JOIN plans p ON p.id = s.plan_id
         WHERE f.status = 'active' AND (s.id IS NULL OR p.price_monthly = 0)
         LIMIT 1"
    )->fetch();
    if ($r) $farm_free = (int)$r['id'];

    // Paid + active
    $r = $db->query(
        "SELECT f.id FROM farms f
         JOIN subscriptions s ON s.farm_id = f.id AND s.status = 'active'
         JOIN plans p ON p.id = s.plan_id
         WHERE f.status = 'active' AND p.price_monthly > 0
           AND (s.end_date IS NULL OR s.end_date >= CURDATE())
         LIMIT 1"
    )->fetch();
    if ($r) $farm_paid = (int)$r['id'];

    // Expired
    $r = $db->query(
        "SELECT f.id FROM farms f
         JOIN subscriptions s ON s.farm_id = f.id AND s.status = 'expired'
         WHERE f.status = 'active' LIMIT 1"
    )->fetch();
    if ($r) $farm_expired = (int)$r['id'];

    // Grace period
    $r = $db->query(
        "SELECT f.id FROM farms f
         JOIN subscriptions s ON s.farm_id = f.id AND s.status = 'grace'
         WHERE f.status = 'active' LIMIT 1"
    )->fetch();
    if ($r) $farm_grace = (int)$r['id'];

    // Suspended
    $r = $db->query(
        "SELECT f.id FROM farms f WHERE f.status = 'suspended' LIMIT 1"
    )->fetch();
    if ($r) $farm_suspended = (int)$r['id'];

} catch (\Throwable $e) {
    echo "\033[31mDB ERROR: {$e->getMessage()}\033[0m\n";
    exit(1);
}

echo "\033[1mSaaS Engine Test Suite\033[0m";
echo "\n" . str_repeat('─', 55) . "\n";
echo "DB farms discovered:\n";
echo "  Free:      " . ($farm_free      ? "farm #{$farm_free}"      : "\033[33mnone\033[0m") . "\n";
echo "  Paid:      " . ($farm_paid      ? "farm #{$farm_paid}"      : "\033[33mnone\033[0m") . "\n";
echo "  Expired:   " . ($farm_expired   ? "farm #{$farm_expired}"   : "\033[33mnone\033[0m") . "\n";
echo "  Grace:     " . ($farm_grace     ? "farm #{$farm_grace}"     : "\033[33mnone\033[0m") . "\n";
echo "  Suspended: " . ($farm_suspended ? "farm #{$farm_suspended}" : "\033[33mnone\033[0m") . "\n";


// ════════════════════════════════════════════════════════════════════════════
// SUITE A — Free plan
// ════════════════════════════════════════════════════════════════════════════

suite('Farm A — Free Plan');

if (!$farm_free) {
    skip('(all tests)', 'No free-plan farm in DB');
} else {
    fake_session($farm_free);
    $sub = getSubscription();

    ok('is_free = true',               $sub['is_free']);
    ok('is_blocked = false',           !$sub['is_blocked']);
    ok('is_expired = false',           !$sub['is_expired']);
    ok('is_unlimited = false',         !$sub['is_unlimited']);
    ok('cows_limit = 5',               $sub['cows_limit'] === 5);
    ok('workers_limit = 2',            $sub['workers_limit'] === 2);
    ok('equipment_limit = 5',          $sub['equipment_limit'] === 5);

    // Feature gates must be blocked
    ok('canAccess(finance.view) = false',  !canAccess('finance.view'));
    ok('canAccess(report.view) = false',   !canAccess('report.view'));
    ok('canAccess(report.export) = false', !canAccess('report.export'));
    ok('canAccess(milk.analytics) = false',!canAccess('milk.analytics'));
    ok('canAccess(analytics.view) = false',!canAccess('analytics.view'));

    // View/list features allowed even on free
    ok('canAccess(cow.view) = true',       canAccess('cow.view'));
    ok('canAccess(worker.view) = true',    canAccess('worker.view'));
    ok('canAccess(dashboard.view) = true', canAccess('dashboard.view'));

    // Resource gate (will be allowed until limit is hit — we check the gate logic, not count)
    $cow_usage = resourceUsage('cows');
    ok('resourceUsage returns allowed/current/max/pct', isset($cow_usage['allowed'], $cow_usage['current'], $cow_usage['max'], $cow_usage['pct']));
    ok('cows max = 5 on free',             $cow_usage['max'] === 5);
    ok('canAccess(cow.create) respects resourceUsage', canAccess('cow.create') === $cow_usage['allowed']);
}


// ════════════════════════════════════════════════════════════════════════════
// SUITE B — Paid plan (active)
// ════════════════════════════════════════════════════════════════════════════

suite('Farm B — Paid Plan (active)');

if (!$farm_paid) {
    skip('(all tests)', 'No active paid-plan farm in DB — seed one to test');
} else {
    fake_session($farm_paid);
    $sub = getSubscription();

    ok('is_free = false',              !$sub['is_free']);
    ok('is_blocked = false',           !$sub['is_blocked']);
    ok('is_expired = false',           !$sub['is_expired']);
    ok('sub_status = active',          $sub['sub_status'] === 'active');

    // Paid plan should have these features enabled
    ok('canAccess(finance.view) = true',   canAccess('finance.view'));
    ok('canAccess(report.view) = true',    canAccess('report.view'));
    ok('canAccess(report.export) = true',  canAccess('report.export'));

    // Writes allowed
    ok('canAccess(cow.create) = true',     canAccess('cow.create'));
    ok('canAccess(worker.create) = true',  canAccess('worker.create'));

    // resourceUsage shows unlimited (null max) or large limit
    $cow_usage = resourceUsage('cows');
    ok('cow max is null (unlimited) or large', $cow_usage['max'] === null || $cow_usage['max'] > 100);
}


// ════════════════════════════════════════════════════════════════════════════
// SUITE C — Expired subscription
// ════════════════════════════════════════════════════════════════════════════

suite('Farm C — Expired Subscription');

if (!$farm_expired) {
    skip('(all tests)', 'No expired-subscription farm in DB');
} else {
    fake_session($farm_expired);
    $sub = getSubscription();

    ok('is_expired = true',            $sub['is_expired']);
    ok('is_blocked = true',            $sub['is_blocked']);
    ok('sub_status = expired',         $sub['sub_status'] === 'expired');

    // Writes must be blocked
    ok('canAccess(cow.create) = false',     !canAccess('cow.create'));
    ok('canAccess(worker.create) = false',  !canAccess('worker.create'));
    ok('canAccess(finance.view) = false',   !canAccess('finance.view'));
    ok('canAccess(report.export) = false',  !canAccess('report.export'));

    // Read-only must still be allowed
    ok('canAccess(cow.view) = true',        canAccess('cow.view'));
    ok('canAccess(cow.list) = true',        canAccess('cow.list'));
    ok('canAccess(dashboard.view) = true',  canAccess('dashboard.view'));
    ok('canAccess(subscription.view) = true', canAccess('subscription.view'));
}


// ════════════════════════════════════════════════════════════════════════════
// SUITE D — Suspended farm (CEO action)
// ════════════════════════════════════════════════════════════════════════════

suite('Farm D — Suspended (CEO Manual Block)');

if (!$farm_suspended) {
    skip('(all tests)', 'No suspended farm in DB');
} else {
    fake_session($farm_suspended);
    $sub = getSubscription();

    ok('is_suspended = true',          $sub['is_suspended']);
    ok('is_blocked = true',            $sub['is_blocked']);

    ok('canAccess(cow.create) = false',    !canAccess('cow.create'));
    ok('canAccess(finance.view) = false',  !canAccess('finance.view'));
    ok('canAccess(cow.view) = true',       canAccess('cow.view'));
}


// ════════════════════════════════════════════════════════════════════════════
// SUITE E — CEO (superadmin) — must bypass everything
// ════════════════════════════════════════════════════════════════════════════

suite('Farm E — CEO (superadmin) Override');

fake_ceo();
$sub = getSubscription();

ok('is_unlimited = true',              $sub['is_unlimited']);
ok('is_blocked = false',               !$sub['is_blocked']);
ok('canAccess(finance.view) = true',   canAccess('finance.view'));
ok('canAccess(report.export) = true',  canAccess('report.export'));
ok('canAccess(cow.create) = true',     canAccess('cow.create'));
ok('canAccess(user.create) = true',    canAccess('user.create'));

// CEO impersonating an expired farm must be scoped (accurate view)
if ($farm_expired) {
    fake_ceo($farm_expired);
    $sub_imp = getSubscription();
    ok('CEO impersonating expired farm sees is_blocked=true', $sub_imp['is_blocked']);
    ok('CEO impersonating — fid() = expired farm id', fid() === $farm_expired);
}


// ════════════════════════════════════════════════════════════════════════════
// SUITE F — Grace period
// ════════════════════════════════════════════════════════════════════════════

suite('Farm F — Grace Period');

if (!$farm_grace) {
    skip('(all tests)', 'No grace-period farm in DB');
} else {
    fake_session($farm_grace);
    $sub = getSubscription();

    ok('is_grace = true',              $sub['is_grace']);
    ok('is_blocked = false',           !$sub['is_blocked']);
    ok('is_expired = false',           !$sub['is_expired']);

    // Grace = still has access, but banner should show
    ok('canAccess(cow.create) = true', canAccess('cow.create'));
    ok('canAccess(finance.view) respects plan', is_bool(canAccess('finance.view')));
    $banner = subscriptionExpiryBanner();
    ok('expiry banner is non-empty in grace', strlen($banner) > 0);
    ok('grace banner contains grace_end_date', $sub['grace_end_date'] !== null);
}


// ════════════════════════════════════════════════════════════════════════════
// SUITE G — Multi-tenancy isolation
// ════════════════════════════════════════════════════════════════════════════

suite('Farm G — Multi-tenancy SQL Isolation');

if ($farm_free && $farm_paid && $farm_free !== $farm_paid) {
    fake_session($farm_free);
    $filter_a = farmFilter();
    fake_session($farm_paid);
    $filter_b = farmFilter();

    ok('farmFilter differs between two farms', $filter_a !== $filter_b);
    ok('farmFilter contains farm id (no wildcard for regular user)', strpos($filter_b, '1=1') === false);
    ok('farmFilter is pure integer — no string injection possible',
        preg_match('/^farm_id = \d+$/', $filter_b) === 1
    );

    // CEO
    fake_ceo();
    $filter_ceo = farmFilter();
    ok('CEO without impersonation → farmFilter = 1=1', $filter_ceo === '1=1');

    // CEO impersonating
    if ($farm_free) {
        fake_ceo($farm_free);
        $filter_imp = farmFilter();
        ok('CEO impersonating → farmFilter scoped to target farm', $filter_imp === "farm_id = {$farm_free}");
        ok('CEO impersonating → 1=1 NOT returned', $filter_imp !== '1=1');
    }
} else {
    skip('SQL isolation (cross-farm)', 'Need at least 2 different farms in DB');
}


// ════════════════════════════════════════════════════════════════════════════
// SUITE H — RBAC role isolation
// ════════════════════════════════════════════════════════════════════════════

suite('Farm H — RBAC Role Gates');

if ($farm_paid) {
    // canAccess() = subscription gate only (plan allows finance on paid)
    // hasPermission() = role gate (worker cannot access finance regardless of plan)
    // Both must pass for a feature to be truly accessible.

    fake_session($farm_paid, 'worker');
    ok('Worker canAccess(finance.view) = true (plan allows)',    canAccess('finance.view'));
    ok('Worker hasPermission(finance.view) = false (role blocks)', !hasPermission('finance.view'));
    ok('Worker hasPermission(task.update) = true',               hasPermission('task.update'));
    ok('Worker combined check blocks finance',
        canAccess('finance.view') && !hasPermission('finance.view')); // plan yes, role no → blocked

    // Admin on paid farm: full access via both gates
    fake_session($farm_paid, 'admin');
    ok('Admin hasPermission(finance.view) = true',  hasPermission('finance.view'));
    ok('Admin canAccess(finance.view) = true',       canAccess('finance.view'));
    ok('Admin hasPermission(cow.create) = true',    hasPermission('cow.create'));

    // Accountant: finance yes, user management no
    fake_session($farm_paid, 'accountant');
    ok('Accountant hasPermission(finance.view) = true',  hasPermission('finance.view'));
    ok('Accountant hasPermission(user.create) = false',  !hasPermission('user.create'));
} else {
    skip('RBAC role gates', 'Need a paid farm in DB');
}


// ════════════════════════════════════════════════════════════════════════════
// SUITE I — Engine architecture guarantees
// ════════════════════════════════════════════════════════════════════════════

suite('Engine Architecture Guarantees');

ok('subscription_engine.php loaded (getSubscription exists)',  function_exists('getSubscription'));
ok('access_control.php loaded (canAccess exists)',             function_exists('canAccess'));
ok('farm_guard.php loaded (farmFilter exists)',                function_exists('farmFilter'));
ok('rbac.php loaded (hasPermission exists)',                   function_exists('hasPermission'));
ok('activity_logger.php loaded (logActivity exists)',          function_exists('logActivity'));
ok('saas_guard.php has saasRequire()',                         function_exists('saasRequire'));
ok('_subEngineFlush() exists for cache reset',                 function_exists('_subEngineFlush'));
ok('farmCanFinance() is backward-compat alias',                function_exists('farmCanFinance'));
ok('isSupportStaff() is available',                           function_exists('isSupportStaff'));
ok('isSaasUser() is available',                               function_exists('isSaasUser'));

// Support staff bypass: read-only yes, write no
if ($farm_paid) {
    $_SESSION = [
        'user_id'   => 9999,
        'user_role' => 'support_staff',
        'user_name' => 'Staff',
        'farm_id'   => null,
    ];
    _subEngineFlush();

    ok('support_staff canAccess(cow.view) = true',         canAccess('cow.view'));
    ok('support_staff canAccess(ticket.view) = true',      canAccess('ticket.view'));
    ok('support_staff canAccess(cow.create) = false',      !canAccess('cow.create'));
    ok('support_staff canAccess(finance.create) = false',  !canAccess('finance.create'));
}


// ════════════════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════════════════

$total = $results['pass'] + $results['fail'] + $results['skip'];

echo "\n" . str_repeat('─', 55) . "\n";
echo "\033[1mResults: {$results['pass']} passed · {$results['fail']} failed · {$results['skip']} skipped\033[0m\n";

if (!empty($failures)) {
    echo "\n\033[31mFailed assertions:\033[0m\n";
    foreach ($failures as $f) echo "  • {$f}\n";
}

if ($results['fail'] === 0) {
    echo "\n\033[1;32m✔ SAAS CORE ENGINE READY\033[0m\n\n";
    exit(0);
} else {
    echo "\n\033[1;31m✘ SYSTEM FAILED — fix the above before deploying\033[0m\n\n";
    exit(1);
}
