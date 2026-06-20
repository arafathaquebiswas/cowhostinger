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
    "SELECT cs.symptom, cs.severity, cs.temperature, cs.heart_rate,
            cs.appetite_status, cs.stool_condition, cs.blood_in_milk,
            DATE_FORMAT(cs.recorded_at, '%d %b %Y %H:%i') AS recorded_at,
            u.name AS recorded_by
     FROM cow_symptoms cs
     JOIN users u ON u.id = cs.recorded_by
     WHERE cs.cow_id = ?
     ORDER BY cs.recorded_at DESC
     LIMIT 5"
);
$stmt->execute([$cow_id]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['temperature']   = $r['temperature']   !== null ? (float)$r['temperature']  : null;
    $r['heart_rate']    = $r['heart_rate']     !== null ? (int)$r['heart_rate']     : null;
    $r['blood_in_milk'] = (bool)$r['blood_in_milk'];
}
unset($r);

jsonResponse(['symptoms' => $rows]);
