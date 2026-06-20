<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
startSecureSession();
requireAuth();

$db    = getDB();
$count = 0;

// Prevent duplicate unread alerts for the same entity
function alert_exists(PDO $db, string $type, ?string $table, ?int $id): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM alerts WHERE type = ? AND related_table = ? AND related_id = ? AND is_read = 0"
    );
    $stmt->execute([$type, $table, $id]);
    return (int)$stmt->fetchColumn() > 0;
}

// 1. Low feed stock
$rows = $db->query(
    "SELECT id, item_name, quantity, reorder_threshold, unit FROM feed_inventory
     WHERE reorder_threshold > 0 AND quantity <= reorder_threshold"
)->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'low_feed_stock', 'feed_inventory', $r['id'])) {
        createAlert('low_feed_stock', 'high',
            "Low feed stock: {$r['item_name']} — {$r['quantity']} {$r['unit']} remaining (threshold: {$r['reorder_threshold']})",
            'feed_inventory', (int)$r['id']);
        $count++;
    }
}

// 2. Low medicine stock
$rows = $db->query(
    "SELECT id, item_name, quantity, reorder_threshold, unit FROM medicine_inventory
     WHERE reorder_threshold > 0 AND quantity <= reorder_threshold"
)->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'low_medicine_stock', 'medicine_inventory', $r['id'])) {
        createAlert('low_medicine_stock', 'high',
            "Low medicine stock: {$r['item_name']} — {$r['quantity']} {$r['unit']} remaining",
            'medicine_inventory', (int)$r['id']);
        $count++;
    }
}

// 3. Medicine expiring within 30 days
$rows = $db->query(
    "SELECT id, item_name, expiry_date FROM medicine_inventory
     WHERE expiry_date IS NOT NULL
       AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
       AND quantity > 0"
)->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'medicine_expiring', 'medicine_inventory', $r['id'])) {
        $days = max(0, (int)round((strtotime($r['expiry_date']) - time()) / 86400));
        $sev  = $days <= 7 ? 'critical' : 'high';
        createAlert('medicine_expiring', $sev,
            "Medicine expiring in {$days} day(s): {$r['item_name']} (expires {$r['expiry_date']})",
            'medicine_inventory', (int)$r['id']);
        $count++;
    }
}

// 4. Sick or quarantined cows
$rows = $db->query(
    "SELECT id, tag_number, status FROM cows WHERE status IN ('sick','quarantine')"
)->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'sick_cow', 'cows', $r['id'])) {
        $sev = $r['status'] === 'quarantine' ? 'critical' : 'high';
        createAlert('sick_cow', $sev,
            "Cow #{$r['tag_number']} is {$r['status']} — veterinary attention required.",
            'cows', (int)$r['id']);
        $count++;
    }
}

// 5. Overdue worker tasks
$rows = $db->query(
    "SELECT wt.id, u.name AS worker_name, wt.task_type, wt.assigned_date
     FROM worker_tasks wt
     JOIN workers w ON w.id = wt.worker_id
     JOIN users   u ON u.id = w.user_id
     WHERE wt.status IN ('pending','in_progress')
       AND wt.assigned_date < CURDATE()"
)->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'overdue_task', 'worker_tasks', $r['id'])) {
        createAlert('overdue_task', 'medium',
            "Overdue task: {$r['task_type']} assigned to {$r['worker_name']} (due {$r['assigned_date']})",
            'worker_tasks', (int)$r['id']);
        $count++;
    }
}

// 6. Calving approaching (within 14 days)
$rows = $db->query(
    "SELECT br.id, c.tag_number, br.expected_calving_date
     FROM breeding_records br
     JOIN cows c ON c.id = br.cow_id
     WHERE br.status = 'pregnant'
       AND br.expected_calving_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)"
)->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'calving_approaching', 'breeding_records', $r['id'])) {
        $days = max(0, (int)round((strtotime($r['expected_calving_date']) - time()) / 86400));
        $sev  = $days <= 3 ? 'critical' : 'high';
        createAlert('calving_approaching', $sev,
            "Cow #{$r['tag_number']} expected to calve in {$days} day(s) — {$r['expected_calving_date']}",
            'breeding_records', (int)$r['id']);
        $count++;
    }
}

// 7. Overdue maintenance
$rows = $db->query(
    "SELECT id, description, scheduled_date FROM maintenance_logs
     WHERE completed_date IS NULL
       AND scheduled_date IS NOT NULL
       AND scheduled_date < CURDATE()"
)->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'maintenance_overdue', 'maintenance_logs', $r['id'])) {
        createAlert('maintenance_overdue', 'medium',
            "Overdue maintenance: {$r['description']} (was scheduled for {$r['scheduled_date']})",
            'maintenance_logs', (int)$r['id']);
        $count++;
    }
}

// 8. Equipment damaged
$rows = $db->query(
    "SELECT id, name FROM equipment WHERE status = 'damaged'"
)->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'equipment_damaged', 'equipment', $r['id'])) {
        createAlert('equipment_damaged', 'high',
            "Equipment marked as damaged: {$r['name']} — repair required.",
            'equipment', (int)$r['id']);
        $count++;
    }
}

jsonResponse(['success' => true, 'alerts_generated' => $count]);
