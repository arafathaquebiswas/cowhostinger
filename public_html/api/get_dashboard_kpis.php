<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
require_once dirname(__DIR__) . '/includes/farm_guard.php';
startSecureSession();
requireAuth();

$db  = getDB();
$ff  = farmFilter();   // e.g. "farm_id = 1" or "1=1" for superadmin

// 1. Total active cows
$s = $db->prepare("SELECT COUNT(*) FROM cows WHERE {$ff} AND status NOT IN ('sold','deceased')");
$s->execute();
$total_cows = (int)$s->fetchColumn();

// Helper: prepare + execute, return fetchColumn value
function _kpi(PDO $db, string $sql, array $p = []): string|int|float|false {
    $st = $db->prepare($sql); $st->execute($p); return $st->fetchColumn();
}

// 2–4. Cow health counts
$healthy_cows  = (int)_kpi($db, "SELECT COUNT(*) FROM cows WHERE {$ff} AND status IN ('active','lactating','dry')");
$sick_cows     = (int)_kpi($db, "SELECT COUNT(*) FROM cows WHERE {$ff} AND status IN ('sick','quarantine')");
$pregnant_cows = (int)_kpi($db, "SELECT COUNT(*) FROM cows WHERE {$ff} AND (status='pregnant' OR is_pregnant=1)");

// 5. Today's milk
$milk_today = (float)_kpi($db, "SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE {$ff} AND DATE(recorded_at)=CURDATE()");

// 6. Monthly milk revenue
$price_row       = $db->prepare("SELECT price_per_liter FROM milk_price_history WHERE {$ff} ORDER BY effective_date DESC LIMIT 1");
$price_row->execute();
$price_per_liter = ($r = $price_row->fetch()) ? (float)$r['price_per_liter'] : 0;
$monthly_liters  = (float)_kpi($db, "SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE {$ff} AND MONTH(recorded_at)=MONTH(CURDATE()) AND YEAR(recorded_at)=YEAR(CURDATE())");
$milk_revenue    = $monthly_liters * $price_per_liter;

// 7. Feed alerts
$feed_alerts = (int)_kpi($db, "SELECT COUNT(*) FROM feed_inventory WHERE {$ff} AND reorder_threshold>0 AND quantity<=reorder_threshold");

// 8. Medicine alerts
$med_alerts = (int)_kpi($db, "SELECT COUNT(*) FROM medicine_inventory WHERE {$ff} AND ((reorder_threshold>0 AND quantity<=reorder_threshold) OR (expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)))");

// 9. Equipment under maintenance
$equip_maint = (int)_kpi($db, "SELECT COUNT(*) FROM equipment WHERE {$ff} AND status='maintenance'");

// 10. Net profit this month
$net_profit = (float)_kpi($db, "SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM finance_transactions WHERE {$ff} AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())");

// 11. Damaged equipment
$damaged_equipment = (int)_kpi($db, "SELECT COUNT(*) FROM equipment WHERE {$ff} AND status='damaged'");

// 12. Feed cost this month
$feed_cost_month = (float)_kpi($db, "SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE {$ff} AND category='Feed Purchase' AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())");

// 13. Equipment sales this month
$equip_sales_month = 0.0;
try {
    $equip_sales_month = (float)_kpi($db, "SELECT COALESCE(SUM(sale_price),0) FROM equipment_sales WHERE {$ff} AND MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE())");
} catch (PDOException $e) {}

// 14. Previous month net profit
$prev_month_profit = (float)_kpi($db, "SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM finance_transactions WHERE {$ff} AND MONTH(transaction_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(transaction_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))");

jsonResponse([
    'total_cows'         => $total_cows,
    'healthy_cows'       => $healthy_cows,
    'sick_cows'          => $sick_cows,
    'pregnant_cows'      => $pregnant_cows,
    'milk_today_l'       => round($milk_today, 1),
    'milk_revenue'       => number_format($milk_revenue, 0),
    'feed_alerts'        => $feed_alerts,
    'med_alerts'         => $med_alerts,
    'equip_maint'        => $equip_maint,
    'net_profit'         => ($net_profit >= 0 ? '+' : '') . number_format($net_profit, 0),
    'net_profit_raw'     => $net_profit,
    'damaged_equipment'  => $damaged_equipment,
    'feed_cost_month'    => number_format($feed_cost_month, 0),
    'equip_sales_month'  => number_format($equip_sales_month, 0),
    'prev_month_profit'  => ($prev_month_profit >= 0 ? '+' : '') . number_format($prev_month_profit, 0),
    'prev_month_profit_raw' => $prev_month_profit,
]);
