<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
require_once dirname(__DIR__) . '/includes/farm_guard.php';
startSecureSession();
requireAuth();

$db  = getDB();
$raw = [];

$stmt = $db->prepare(
    "SELECT DATE(recorded_at) AS d, SUM(liters) AS total
     FROM milk_records
     WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
       AND " . farmFilter() . "
     GROUP BY DATE(recorded_at)"
);
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $raw[$row['d']] = round((float)$row['total'], 1);
}

$labels = [];
$data   = [];
for ($i = 6; $i >= 0; $i--) {
    $date     = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = date('d M', strtotime($date));
    $data[]   = $raw[$date] ?? 0;
}

jsonResponse(['labels' => $labels, 'data' => $data]);
