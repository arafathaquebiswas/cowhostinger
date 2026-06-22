<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
require_once dirname(__DIR__) . '/includes/farm_guard.php';
startSecureSession();
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// CSRF check for this JSON API endpoint (token sent as request header)
$_csrf_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($_csrf_header)) {
    jsonResponse(['error' => 'Invalid CSRF token'], 403);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id    = isset($input['id'])  ? (int)$input['id'] : null;
$all   = !empty($input['all']);

$db = getDB();

if ($all) {
    $db->prepare("UPDATE alerts SET is_read = 1 WHERE is_read = 0 AND " . farmFilter())->execute();
    auditLog((int)$_SESSION['user_id'], 'MARK_ALL_ALERTS_READ', 'alerts');
} elseif ($id > 0) {
    $db->prepare("UPDATE alerts SET is_read = 1 WHERE id = ? AND " . farmFilter())->execute([$id]);
    auditLog((int)$_SESSION['user_id'], 'MARK_ALERT_READ', 'alerts', $id);
} else {
    jsonResponse(['error' => 'Invalid request — provide id or all=true'], 400);
}

$uc_stmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE is_read = 0 AND " . farmFilter());
$uc_stmt->execute();
$unread = (int)$uc_stmt->fetchColumn();

jsonResponse(['success' => true, 'unread_count' => $unread]);
