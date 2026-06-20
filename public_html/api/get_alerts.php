<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
startSecureSession();
requireAuth();

$limit  = min((int)($_GET['limit'] ?? 20), 100);
$filter = $_GET['filter'] ?? 'unread';   // unread | all
$severity = $_GET['severity'] ?? '';

$db     = getDB();
$where  = ['1=1'];
$params = [];

if ($filter === 'unread') {
    $where[] = 'is_read = 0';
}
if (in_array($severity, ['low','medium','high','critical'], true)) {
    $where[] = 'severity = ?';
    $params[] = $severity;
}

$whereSql = implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT id, type, severity, message, related_table, related_id, is_read, created_at
     FROM alerts
     WHERE {$whereSql}
     ORDER BY FIELD(severity,'critical','high','medium','low'), created_at DESC
     LIMIT ?"
);
$params[] = $limit;
$stmt->execute($params);

$unread_count = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE is_read = 0")->fetchColumn();

jsonResponse([
    'alerts'       => $stmt->fetchAll(),
    'unread_count' => $unread_count,
]);
