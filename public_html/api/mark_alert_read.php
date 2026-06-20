<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
startSecureSession();
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id    = isset($input['id'])  ? (int)$input['id'] : null;
$all   = !empty($input['all']);

$db = getDB();

if ($all) {
    $db->exec("UPDATE alerts SET is_read = 1 WHERE is_read = 0");
    auditLog((int)$_SESSION['user_id'], 'MARK_ALL_ALERTS_READ', 'alerts');
} elseif ($id > 0) {
    $db->prepare("UPDATE alerts SET is_read = 1 WHERE id = ?")->execute([$id]);
    auditLog((int)$_SESSION['user_id'], 'MARK_ALERT_READ', 'alerts', $id);
} else {
    jsonResponse(['error' => 'Invalid request — provide id or all=true'], 400);
}

$unread = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE is_read = 0")->fetchColumn();

jsonResponse(['success' => true, 'unread_count' => $unread]);
