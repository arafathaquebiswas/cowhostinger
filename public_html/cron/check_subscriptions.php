<?php
/**
 * cron/check_subscriptions.php — Daily subscription maintenance.
 *
 * Run via cron job on Hostinger:
 *   0 1 * * * php /home/user/public_html/cron/check_subscriptions.php >> /tmp/saas_cron.log 2>&1
 *
 * Performs:
 *   1. active → grace    when end_date has passed
 *   2. grace  → expired  when grace_end_date has passed
 *   3. Expiry alert creation (7 / 3 / 1 days before end_date)
 *
 * Safe to run multiple times (idempotent UPDATE checks prevent duplicates).
 */

// ── Bootstrap (CLI context — no session, no auth check) ──────────────────────
define('ROOT_PATH', dirname(__DIR__));
define('CLI_CRON', true);
require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/functions.php';

$db    = getDB();
$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');
$log   = fn(string $msg) => fwrite(STDOUT, "[{$now}] {$msg}\n");

// ── Load platform settings ────────────────────────────────────────────────────
try {
    $settings_stmt = $db->query("SELECT `key`, value FROM platform_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $e) {
    $settings = [];
}
$grace_days = (int)($settings['grace_period_days'] ?? 5);

$log("Starting subscription check. Grace period: {$grace_days} days.");
$transitions = ['to_grace' => 0, 'to_expired' => 0, 'alerts_sent' => 0];

// ── 1. active → grace ─────────────────────────────────────────────────────────
// FOR UPDATE must be inside an explicit transaction — with autocommit=1 the lock
// would be released immediately after SELECT, making it ineffective against
// concurrent cron runs. The transaction holds the lock through the UPDATE.
try {
    $db->beginTransaction();

    $to_grace = $db->prepare(
        "SELECT s.id, s.farm_id, s.end_date, f.farm_name
         FROM subscriptions s
         JOIN farms f ON f.id = s.farm_id
         WHERE s.status = 'active'
           AND s.end_date IS NOT NULL
           AND s.end_date < ?
         FOR UPDATE"
    );
    $to_grace->execute([$today]);
    $due_grace = $to_grace->fetchAll();

    foreach ($due_grace as $sub) {
        $grace_end = date('Y-m-d', strtotime($sub['end_date'] . " +{$grace_days} days"));
        $db->prepare(
            "UPDATE subscriptions SET status='grace', grace_end_date=? WHERE id=? AND status='active'"
        )->execute([$grace_end, $sub['id']]);

        $log("→ grace: farm #{$sub['farm_id']} ({$sub['farm_name']}) — grace until {$grace_end}");
        $transitions['to_grace']++;
    }

    $db->commit();

    // Alerts outside the transaction — inserts are non-critical and should not
    // cause the transaction to roll back if they fail
    foreach ($due_grace as $sub) {
        $grace_end = date('Y-m-d', strtotime($sub['end_date'] . " +{$grace_days} days"));
        _cronAlert($db, $sub['farm_id'], 'subscription', 'high',
            "Your subscription has expired. You have {$grace_days} days grace period until {$grace_end}. Please renew.");
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $log("ERROR (active→grace): " . $e->getMessage());
}

// ── 2. grace → expired ────────────────────────────────────────────────────────
try {
    $to_expired = $db->prepare(
        "SELECT s.id, s.farm_id, f.farm_name
         FROM subscriptions s
         JOIN farms f ON f.id = s.farm_id
         WHERE s.status = 'grace'
           AND s.grace_end_date IS NOT NULL
           AND s.grace_end_date < ?"
    );
    $to_expired->execute([$today]);
    $due_expired = $to_expired->fetchAll();

    foreach ($due_expired as $sub) {
        $db->prepare("UPDATE subscriptions SET status='expired' WHERE id=? AND status='grace'")
           ->execute([$sub['id']]);

        _cronAlert($db, $sub['farm_id'], 'subscription', 'critical',
            'Your subscription grace period has ended. Access is now restricted. Please renew immediately.');

        $log("→ expired: farm #{$sub['farm_id']} ({$sub['farm_name']})");
        $transitions['to_expired']++;
    }
} catch (\Throwable $e) {
    $log("ERROR (grace→expired): " . $e->getMessage());
}

// ── 3. Expiry alerts (7 / 3 / 1 days before end_date) ────────────────────────
$alert_thresholds = [7, 3, 1];

foreach ($alert_thresholds as $days_before) {
    $target_date = date('Y-m-d', strtotime("+{$days_before} days"));

    try {
        $upcoming = $db->prepare(
            "SELECT s.id, s.farm_id, s.end_date, f.farm_name
             FROM subscriptions s
             JOIN farms f ON f.id = s.farm_id
             WHERE s.status = 'active'
               AND s.end_date = ?"
        );
        $upcoming->execute([$target_date]);
        $farms_due = $upcoming->fetchAll();

        foreach ($farms_due as $sub) {
            // Check if we already sent an alert of this type today
            $already = $db->prepare(
                "SELECT 1 FROM alerts
                 WHERE farm_id = ? AND type = 'subscription'
                   AND message LIKE ?
                   AND DATE(created_at) = CURDATE()
                 LIMIT 1"
            );
            $already->execute([$sub['farm_id'], "%{$days_before} day%"]);
            if ($already->fetchColumn()) continue;

            _cronAlert($db, $sub['farm_id'], 'subscription',
                $days_before <= 1 ? 'critical' : ($days_before <= 3 ? 'high' : 'medium'),
                "Your subscription expires in {$days_before} day" . ($days_before > 1 ? 's' : '')
                . " on {$sub['end_date']}. Renew now to avoid interruption.");

            $log("  alert {$days_before}d: farm #{$sub['farm_id']} ({$sub['farm_name']})");
            $transitions['alerts_sent']++;
        }
    } catch (\Throwable $e) {
        $log("ERROR (alert {$days_before}d): " . $e->getMessage());
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
$log("Done. to_grace={$transitions['to_grace']} to_expired={$transitions['to_expired']} alerts={$transitions['alerts_sent']}");

// ── Helper ────────────────────────────────────────────────────────────────────
function _cronAlert(\PDO $db, int $farm_id, string $type, string $severity, string $message): void {
    try {
        $db->prepare(
            "INSERT INTO alerts (farm_id, type, severity, message, related_table, is_read, created_at)
             VALUES (?, ?, ?, ?, 'subscriptions', 0, NOW())"
        )->execute([$farm_id, $type, $severity, $message]);
    } catch (\Throwable $e) {
        fwrite(STDOUT, "[ALERT_ERR] " . $e->getMessage() . "\n");
    }
}
