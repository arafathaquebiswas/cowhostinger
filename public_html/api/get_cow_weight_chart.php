<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
startSecureSession();
requireAuth();

$cow_id = (int)($_GET['cow_id'] ?? 0);
if ($cow_id <= 0) {
    jsonResponse(['error' => 'Missing cow_id'], 400);
}

$db = getDB();

$chk = $db->prepare("SELECT id FROM cows WHERE id = ?");
$chk->execute([$cow_id]);
if (!$chk->fetch()) {
    jsonResponse(['error' => 'Cow not found'], 404);
}

$stmt = $db->prepare(
    "SELECT DATE_FORMAT(recorded_at, '%d %b %Y') AS label,
            weight,
            recorded_at
     FROM cow_weight_logs
     WHERE cow_id = ?
     ORDER BY recorded_at DESC
     LIMIT 30"
);
$stmt->execute([$cow_id]);
$rows = array_reverse($stmt->fetchAll());

jsonResponse([
    'labels' => array_column($rows, 'label'),
    'data'   => array_map('floatval', array_column($rows, 'weight')),
]);
