<?php
/**
 * AJAX: validate a coupon code for a given plan.
 * Returns JSON: {ok, discount_type, discount_value, message}
 */
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();

header('Content-Type: application/json');

$db      = getDB();
$code    = strtoupper(trim($_GET['code']    ?? ''));
$plan_id = (int)($_GET['plan_id'] ?? 0);

if ($code === '' || $plan_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
    exit;
}

// Check table exists
$tables = array_column($db->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
if (!in_array('coupons', $tables)) {
    echo json_encode(['ok' => false, 'message' => 'No coupons available.']);
    exit;
}

$stmt = $db->prepare("SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1");
$stmt->execute([$code]);
$c = $stmt->fetch();

if (!$c) {
    echo json_encode(['ok' => false, 'message' => 'Invalid coupon code.']);
    exit;
}
if ($c['expires_at'] && $c['expires_at'] < date('Y-m-d')) {
    echo json_encode(['ok' => false, 'message' => 'This coupon has expired.']);
    exit;
}
if ($c['max_uses'] !== null && $c['used_count'] >= $c['max_uses']) {
    echo json_encode(['ok' => false, 'message' => 'This coupon has reached its usage limit.']);
    exit;
}
if ($c['plan_id'] !== null && (int)$c['plan_id'] !== $plan_id) {
    // Get plan name for helpful message
    $pname = $db->prepare("SELECT name FROM plans WHERE id=? LIMIT 1");
    $pname->execute([$c['plan_id']]);
    $row = $pname->fetch();
    echo json_encode(['ok' => false, 'message' => 'This coupon is only valid for the ' . ($row['name'] ?? '') . ' plan.']);
    exit;
}

echo json_encode([
    'ok'             => true,
    'discount_type'  => $c['discount_type'],
    'discount_value' => (float)$c['discount_value'],
    'message'        => $c['discount_type'] === 'percent'
                        ? number_format($c['discount_value'], 0) . '% discount applied!'
                        : '৳' . number_format($c['discount_value'], 2) . ' discount applied!',
]);
