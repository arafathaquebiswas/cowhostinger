<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['superadmin']);
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';

if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
    flashMessage('error', 'CSRF mismatch.');
    redirect('/dashboard.php');
}

$farm_id = impersonatingFarmId();
if ($farm_id !== null) {
    $db  = getDB();
    $uid = (int)$_SESSION['user_id'];
    // Mark ended_at in impersonation_log
    $db->prepare(
        "UPDATE impersonation_log SET ended_at=NOW() WHERE superadmin_id=? AND target_farm_id=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1"
    )->execute([$uid, $farm_id]);
    auditLog($uid, 'IMPERSONATION_END', 'farms', $farm_id);
}

unset($_SESSION['impersonating_as_farm_id']);
flashMessage('success', 'Impersonation ended. Viewing as Super Admin.');
redirect('/modules/super_admin/index.php');
