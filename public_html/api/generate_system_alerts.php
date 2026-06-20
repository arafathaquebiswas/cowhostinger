<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
require_once dirname(__DIR__) . '/includes/farm_guard.php';
startSecureSession();
requireAuth();

$db    = getDB();
$count = 0;

// Prevent duplicate unread alerts for the same entity (scoped to farm)
function alert_exists(PDO $db, string $type, ?string $table, ?int $id): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM alerts WHERE type = ? AND related_table = ? AND related_id = ? AND is_read = 0 AND " . farmFilter()
    );
    $stmt->execute([$type, $table, $id]);
    return (int)$stmt->fetchColumn() > 0;
}

// 1. Low feed stock
$fi_stmt = $db->prepare(
    "SELECT id, item_name, quantity, reorder_threshold, unit FROM feed_inventory
     WHERE reorder_threshold > 0 AND quantity <= reorder_threshold AND " . farmFilter()
);
$fi_stmt->execute();
$rows = $fi_stmt->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'low_feed_stock', 'feed_inventory', $r['id'])) {
        createAlert('low_feed_stock', 'high',
            "Low feed stock: {$r['item_name']} — {$r['quantity']} {$r['unit']} remaining (threshold: {$r['reorder_threshold']})",
            'feed_inventory', (int)$r['id']);
        $count++;
    }
}

// 2. Low medicine stock
$mi_stmt = $db->prepare(
    "SELECT id, item_name, quantity, reorder_threshold, unit FROM medicine_inventory
     WHERE reorder_threshold > 0 AND quantity <= reorder_threshold AND " . farmFilter()
);
$mi_stmt->execute();
$rows = $mi_stmt->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'low_medicine_stock', 'medicine_inventory', $r['id'])) {
        createAlert('low_medicine_stock', 'high',
            "Low medicine stock: {$r['item_name']} — {$r['quantity']} {$r['unit']} remaining",
            'medicine_inventory', (int)$r['id']);
        $count++;
    }
}

// 3. Medicine expiring within 30 days
$me_stmt = $db->prepare(
    "SELECT id, item_name, expiry_date FROM medicine_inventory
     WHERE expiry_date IS NOT NULL
       AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
       AND quantity > 0 AND " . farmFilter()
);
$me_stmt->execute();
$rows = $me_stmt->fetchAll();
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
$sc_stmt = $db->prepare(
    "SELECT id, tag_number, status FROM cows WHERE status IN ('sick','quarantine') AND " . farmFilter()
);
$sc_stmt->execute();
$rows = $sc_stmt->fetchAll();
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
$ot_stmt = $db->prepare(
    "SELECT wt.id, u.name AS worker_name, wt.task_type, wt.assigned_date
     FROM worker_tasks wt
     JOIN workers w ON w.id = wt.worker_id
     JOIN users   u ON u.id = w.user_id
     WHERE wt.status IN ('pending','in_progress')
       AND wt.assigned_date < CURDATE()
       AND " . farmFilter('u')
);
$ot_stmt->execute();
$rows = $ot_stmt->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'overdue_task', 'worker_tasks', $r['id'])) {
        createAlert('overdue_task', 'medium',
            "Overdue task: {$r['task_type']} assigned to {$r['worker_name']} (due {$r['assigned_date']})",
            'worker_tasks', (int)$r['id']);
        $count++;
    }
}

// 6. Calving approaching (within 14 days)
$ca_stmt = $db->prepare(
    "SELECT br.id, c.tag_number, br.expected_calving_date
     FROM breeding_records br
     JOIN cows c ON c.id = br.cow_id
     WHERE br.status = 'pregnant'
       AND br.expected_calving_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
       AND " . farmFilter('br')
);
$ca_stmt->execute();
$rows = $ca_stmt->fetchAll();
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
$om_stmt = $db->prepare(
    "SELECT id, description, scheduled_date FROM maintenance_logs
     WHERE completed_date IS NULL
       AND scheduled_date IS NOT NULL
       AND scheduled_date < CURDATE()
       AND " . farmFilter()
);
$om_stmt->execute();
$rows = $om_stmt->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'maintenance_overdue', 'maintenance_logs', $r['id'])) {
        createAlert('maintenance_overdue', 'medium',
            "Overdue maintenance: {$r['description']} (was scheduled for {$r['scheduled_date']})",
            'maintenance_logs', (int)$r['id']);
        $count++;
    }
}

// 8. Equipment damaged
$ed_stmt = $db->prepare(
    "SELECT id, name FROM equipment WHERE status = 'damaged' AND " . farmFilter()
);
$ed_stmt->execute();
$rows = $ed_stmt->fetchAll();
foreach ($rows as $r) {
    if (!alert_exists($db, 'equipment_damaged', 'equipment', $r['id'])) {
        createAlert('equipment_damaged', 'high',
            "Equipment marked as damaged: {$r['name']} — repair required.",
            'equipment', (int)$r['id']);
        $count++;
    }
}

jsonResponse(['success' => true, 'alerts_generated' => $count]);
