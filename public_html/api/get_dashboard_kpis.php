<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
startSecureSession();
requireAuth();

$db = getDB();

// 1. Total active cows
$total_cows = (int)$db->query(
    "SELECT COUNT(*) FROM cows WHERE status NOT IN ('sold','deceased')"
)->fetchColumn();

// 2. Healthy cows
$healthy_cows = (int)$db->query(
    "SELECT COUNT(*) FROM cows WHERE status IN ('active','lactating','dry')"
)->fetchColumn();

// 3. Sick cows
$sick_cows = (int)$db->query(
    "SELECT COUNT(*) FROM cows WHERE status IN ('sick','quarantine')"
)->fetchColumn();

// 4. Pregnant cows
$pregnant_cows = (int)$db->query(
    "SELECT COUNT(*) FROM cows WHERE status = 'pregnant' OR is_pregnant = 1"
)->fetchColumn();

// 5. Today's milk (liters)
$milk_today = (float)$db->query(
    "SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE DATE(recorded_at) = CURDATE()"
)->fetchColumn();

// 6. Monthly milk revenue (latest price × monthly litres)
$price_row = $db->query(
    "SELECT price_per_liter FROM milk_price_history ORDER BY effective_date DESC LIMIT 1"
)->fetch();
$price_per_liter = $price_row ? (float)$price_row['price_per_liter'] : 0;

$monthly_liters = (float)$db->query(
    "SELECT COALESCE(SUM(liters),0) FROM milk_records
     WHERE MONTH(recorded_at) = MONTH(CURDATE()) AND YEAR(recorded_at) = YEAR(CURDATE())"
)->fetchColumn();
$milk_revenue = $monthly_liters * $price_per_liter;

// 7. Feed stock alerts (quantity at or below reorder threshold)
$feed_alerts = (int)$db->query(
    "SELECT COUNT(*) FROM feed_inventory
     WHERE reorder_threshold > 0 AND quantity <= reorder_threshold"
)->fetchColumn();

// 8. Medicine alerts (low stock OR expiring within 30 days)
$med_alerts = (int)$db->query(
    "SELECT COUNT(*) FROM medicine_inventory
     WHERE (reorder_threshold > 0 AND quantity <= reorder_threshold)
        OR (expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))"
)->fetchColumn();

// 9. Equipment under maintenance
$equip_maint = (int)$db->query(
    "SELECT COUNT(*) FROM equipment WHERE status = 'maintenance'"
)->fetchColumn();

// 10. Net profit this month
$net_profit = (float)$db->query(
    "SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END), 0)
     FROM finance_transactions
     WHERE MONTH(transaction_date) = MONTH(CURDATE())
       AND YEAR(transaction_date)  = YEAR(CURDATE())"
)->fetchColumn();

jsonResponse([
    'total_cows'    => $total_cows,
    'healthy_cows'  => $healthy_cows,
    'sick_cows'     => $sick_cows,
    'pregnant_cows' => $pregnant_cows,
    'milk_today_l'  => round($milk_today, 1),
    'milk_revenue'  => number_format($milk_revenue, 0),
    'feed_alerts'   => $feed_alerts,
    'med_alerts'    => $med_alerts,
    'equip_maint'   => $equip_maint,
    'net_profit'    => ($net_profit >= 0 ? '+' : '') . number_format($net_profit, 0),
    'net_profit_raw' => $net_profit,
]);
