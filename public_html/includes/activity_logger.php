<?php
/**
 * activity_logger.php — Platform-wide activity tracking.
 *
 * Logs high-level actions across all user types. Lighter than audit_log
 * (which tracks row-level DB changes); this tracks sessions and intent.
 *
 * Auto-included via farm_guard.php → no separate require needed.
 *
 * Usage:
 *   logActivity('farm.viewed',    ['farm_id' => 12], 12);
 *   logActivity('ticket.created', ['ticket_id' => 5]);
 *   logActivity('impersonation.start', ['target' => 12], 12);
 */

function logActivity(string $action, array $context = [], ?int $target_farm_id = null): void {
    // Non-blocking — never let logging failures break page flow
    try {
        $uid   = (int)($_SESSION['user_id']   ?? 0);
        $role  = (string)($_SESSION['user_role'] ?? 'unknown');
        $farm  = $target_farm_id ?? (isset($_SESSION['farm_id']) ? (int)$_SESSION['farm_id'] : null);
        $ctx   = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua    = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        getDB()->prepare(
            "INSERT INTO activity_log (user_id, user_role, action, context, farm_id, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$uid ?: null, $role, $action, $ctx, $farm, $ip, $ua]);
    } catch (\Throwable $e) {
        error_log('[ACTIVITY_LOG] ' . $e->getMessage());
    }
}

/**
 * Fetch recent activity (for dashboards and audit views).
 *
 * @param  array $filters  farm_id, user_id, action, role, limit, offset
 * @return array
 */
function getActivityLog(array $filters = []): array {
    try {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['farm_id'])) {
            $where[]  = 'al.farm_id = ?';
            $params[] = (int)$filters['farm_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[]  = 'al.user_id = ?';
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[]  = 'al.action LIKE ?';
            $params[] = '%' . $filters['action'] . '%';
        }
        if (!empty($filters['role'])) {
            $where[]  = 'al.user_role = ?';
            $params[] = $filters['role'];
        }
        if (!empty($filters['date_from'])) {
            $where[]  = 'DATE(al.created_at) >= ?';
            $params[] = $filters['date_from'];
        }

        $limit  = min(200, (int)($filters['limit']  ?? 50));
        $offset = (int)($filters['offset'] ?? 0);
        $sql    = "SELECT al.*, u.name AS user_name, f.farm_name
                   FROM activity_log al
                   LEFT JOIN users u ON u.id = al.user_id
                   LEFT JOIN farms f ON f.id = al.farm_id
                   WHERE " . implode(' AND ', $where) . "
                   ORDER BY al.created_at DESC
                   LIMIT {$limit} OFFSET {$offset}";

        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}
