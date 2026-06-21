<?php
/**
 * expiry_checker.php — Subscription expiry alert engine.
 *
 * Included in layout_header.php once per session (throttled by $_SESSION).
 * Runs only for farm-scoped admin/accountant users — never for CEO or support staff.
 * Creates a single alert per farm per day for expiry windows: 10, 7, 3, 1 days, and 0 (expired).
 *
 * NEVER modify the CEO/subscription engine files — this is an add-on layer only.
 */

// Only run for authenticated, farm-scoped, non-CEO users
if (!isLoggedIn() || isSuperAdmin() || isSupportStaff()) return;

$_ec_fid = fid();
if (!$_ec_fid) return;

$_ec_role = currentRole() ?? '';
if (!in_array($_ec_role, ['admin', 'accountant'], true)) return;

// Throttle: run at most once per hour per farm to avoid hammering on every page load
$_ec_sess_key = '_expiry_checked_' . $_ec_fid;
if (isset($_SESSION[$_ec_sess_key]) && (time() - $_SESSION[$_ec_sess_key]) < 3600) return;
$_SESSION[$_ec_sess_key] = time();

try {
    $_ec_db = getDB();

    // Fetch the most recent active subscription for this farm
    $stmt = $_ec_db->prepare(
        "SELECT s.end_date, s.status, s.is_lifetime, p.name AS plan_name
         FROM subscriptions s
         JOIN plans p ON p.id = s.plan_id
         WHERE s.farm_id = ? AND s.status IN ('active','grace','trial')
         ORDER BY s.id DESC LIMIT 1"
    );
    $stmt->execute([$_ec_fid]);
    $sub = $stmt->fetch();

    // Skip if no sub, lifetime, or no end_date set
    if (!$sub || $sub['is_lifetime'] || !$sub['end_date']) return;

    $today     = date('Y-m-d');
    $days_left = (int)ceil((strtotime($sub['end_date']) - strtotime($today)) / 86400);

    // Only alert if within 10 days window (including expired = 0 or negative)
    if ($days_left > 10) return;

    // Prevent duplicate alerts on the same day for same farm + type
    $dup = $_ec_db->prepare(
        "SELECT id FROM alerts WHERE farm_id = ? AND type = 'subscription_expiry' AND DATE(created_at) = ? LIMIT 1"
    );
    $dup->execute([$_ec_fid, $today]);
    if ($dup->fetchColumn()) return;

    // Build message and severity based on days remaining
    $plan = $sub['plan_name'];
    if ($days_left <= 0) {
        $msg      = "Your {$plan} subscription has expired today. Renew now to restore full access.";
        $severity = 'critical';
    } elseif ($days_left === 1) {
        $msg      = "Your {$plan} subscription expires tomorrow. Renew today to avoid interruption.";
        $severity = 'critical';
    } elseif ($days_left <= 3) {
        $msg      = "Urgent: Your {$plan} subscription will expire in {$days_left} days. Renew now.";
        $severity = 'high';
    } elseif ($days_left <= 7) {
        $msg      = "Your {$plan} subscription expires in {$days_left} days. Renew now to keep all features active.";
        $severity = 'medium';
    } else {
        $msg      = "Your {$plan} subscription will expire in {$days_left} days. Please renew to avoid service interruption.";
        $severity = 'low';
    }

    // createAlert() uses fid() internally — correct farm context since we checked fid() above
    createAlert('subscription_expiry', $severity, $msg, 'subscriptions', null);

} catch (\Throwable $_ec_ex) {
    error_log('[EXPIRY_CHECKER] ' . $_ec_ex->getMessage());
}
