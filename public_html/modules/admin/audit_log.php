<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireRole(['admin']);

$page_title = 'Audit Log';
$active_nav = 'audit_log';
$db = getDB();

// audit_log has no farm_id column — scope via users table JOIN
// superadmin sees all farms; farm admins see only their own farm's actions
$farm_scope = isSuperAdmin()
    ? '1=1'
    : ('al.user_id IN (SELECT id FROM users WHERE ' . farmFilter() . ')');

// Filters
$filter_user   = (int)($_GET['user_id']     ?? 0);
$filter_action = sanitize($_GET['action']   ?? '');
$filter_table  = sanitize($_GET['table_name'] ?? '');
$date_from     = trim($_GET['date_from']    ?? '');
$date_to       = trim($_GET['date_to']      ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 40;

$where  = [$farm_scope];
$params = [];

if ($filter_user > 0) {
    $where[] = 'al.user_id = ?';
    $params[] = $filter_user;
}
if ($filter_action !== '') {
    $where[] = 'al.action LIKE ?';
    $params[] = '%' . $filter_action . '%';
}
if ($filter_table !== '') {
    $where[] = 'al.table_name = ?';
    $params[] = $filter_table;
}
if ($date_from !== '' && strtotime($date_from)) {
    $where[] = 'DATE(al.created_at) >= ?';
    $params[] = $date_from;
}
if ($date_to !== '' && strtotime($date_to)) {
    $where[] = 'DATE(al.created_at) <= ?';
    $params[] = $date_to;
}
$where_sql = implode(' AND ', $where);

// Count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM audit_log al WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pager = paginate($total, $per_page, $page);

// Fetch logs
$stmt = $db->prepare(
    "SELECT al.id, al.action, al.table_name, al.record_id,
            al.old_value, al.new_value, al.ip_address, al.created_at,
            u.name AS user_name, u.role AS user_role
     FROM audit_log al
     LEFT JOIN users u ON u.id = al.user_id
     WHERE {$where_sql}
     ORDER BY al.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$per_page, $pager['offset']]));
$logs = $stmt->fetchAll();

// For filter dropdowns (farm-scoped)
$users_stmt = $db->prepare("SELECT id, name, role FROM users WHERE " . farmFilter() . " ORDER BY name ASC");
$users_stmt->execute();
$users = $users_stmt->fetchAll();
$tables  = $db->query("SELECT DISTINCT al.table_name FROM audit_log al WHERE {$farm_scope} ORDER BY al.table_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$actions = $db->query("SELECT DISTINCT al.action FROM audit_log al WHERE {$farm_scope} ORDER BY al.action ASC")->fetchAll(PDO::FETCH_COLUMN);

// Summary KPIs (farm-scoped)
$kpi = $db->query(
    "SELECT COUNT(*) AS total,
            SUM(DATE(al.created_at) = CURDATE()) AS today,
            SUM(al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS week,
            COUNT(DISTINCT al.user_id) AS unique_users
     FROM audit_log al
     WHERE {$farm_scope}"
)->fetch();

// Helper: format JSON value for display
$fmt_json = static function (?string $json): string {
    if ($json === null || $json === '') return '—';
    $dec = json_decode($json, true);
    if (!is_array($dec)) return mb_strimwidth($json, 0, 120, '…');
    $parts = [];
    $i = 0;
    foreach ($dec as $k => $v) {
        if ($i++ >= 4) { $parts[] = '…'; break; }
        $val = is_null($v) ? 'null' : (is_bool($v) ? ($v?'true':'false') : (string)$v);
        $parts[] = $k . ': ' . mb_strimwidth($val, 0, 40, '…');
    }
    return implode(' | ', $parts);
};

// Action badge color
$action_color = static function (string $action): string {
    if (str_starts_with($action, 'DELETE')) return 'badge-red';
    if (str_starts_with($action, 'CREATE')) return 'badge-green';
    if (str_starts_with($action, 'EDIT') || str_starts_with($action, 'UPDATE')) return 'badge-blue';
    if (str_starts_with($action, 'LOGIN') || str_starts_with($action, 'LOGOUT')) return 'badge-purple';
    return 'badge-gray';
};

$qs = static fn(array $p): string =>
    '/modules/admin/audit_log.php?' . http_build_query(array_filter($p,
        static fn($v) => $v !== '' && $v !== null && $v !== 0));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Audit Log</h2>
        <p class="text-sm text-muted">System-wide activity trail for all sensitive operations</p>
    </div>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(145px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-label">Total Events</div>
        <div class="kpi-value"><?= number_format((int)$kpi['total']) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Today</div>
        <div class="kpi-value"><?= (int)$kpi['today'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-label">Last 7 Days</div>
        <div class="kpi-value"><?= (int)$kpi['week'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-label">Active Users</div>
        <div class="kpi-value"><?= (int)$kpi['unique_users'] ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" action="/modules/admin/audit_log.php"
      style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem">
    <div class="form-group" style="margin:0;min-width:160px">
        <label class="form-label" style="font-size:.78rem">User</label>
        <select name="user_id" class="form-control">
            <option value="">All Users</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $filter_user===$u['id']?'selected':'' ?>>
                <?= e($u['name']) ?> (<?= e($u['role']) ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0;min-width:180px">
        <label class="form-label" style="font-size:.78rem">Action Contains</label>
        <input type="text" name="action" class="form-control" value="<?= e($filter_action) ?>"
               placeholder="e.g. DELETE, CREATE" maxlength="80">
    </div>
    <div class="form-group" style="margin:0;min-width:170px">
        <label class="form-label" style="font-size:.78rem">Table</label>
        <select name="table_name" class="form-control">
            <option value="">All Tables</option>
            <?php foreach ($tables as $tbl): ?>
            <option value="<?= e($tbl) ?>" <?= $filter_table===$tbl?'selected':'' ?>>
                <?= e($tbl) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($filter_user || $filter_action || $filter_table || $date_from || $date_to): ?>
    <a href="/modules/admin/audit_log.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
    <span class="text-sm text-muted" style="align-self:center;margin-left:auto">
        <?= number_format($total) ?> event<?= $total!==1?'s':'' ?>
    </span>
</form>

<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($logs)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
            <rect x="9" y="3" width="6" height="4" rx="1"/>
        </svg>
        <h3>No audit events found</h3>
        <p class="text-muted">Sensitive write operations will appear here once they occur.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table" style="font-size:.83rem">
        <thead>
            <tr>
                <th style="min-width:140px">Timestamp</th>
                <th>User</th>
                <th>Action</th>
                <th>Table</th>
                <th style="width:60px">ID</th>
                <th style="min-width:200px">Old Value</th>
                <th style="min-width:200px">New Value</th>
                <th>IP</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
            <td style="white-space:nowrap;color:var(--text-muted)">
                <?= e(formatDateTime($log['created_at'])) ?>
            </td>
            <td>
                <?php if ($log['user_name']): ?>
                <div style="font-weight:600"><?= e($log['user_name']) ?></div>
                <div class="text-muted" style="font-size:.74rem"><?= e($log['user_role']) ?></div>
                <?php else: ?>
                <span class="text-muted">System</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge <?= $action_color($log['action']) ?>" style="font-size:.71rem;letter-spacing:.02em">
                    <?= e($log['action']) ?>
                </span>
            </td>
            <td>
                <code style="font-size:.8rem;background:var(--bg-muted);padding:.1rem .35rem;border-radius:3px">
                    <?= e($log['table_name'] ?? '—') ?>
                </code>
            </td>
            <td class="text-muted"><?= $log['record_id'] ? (int)$log['record_id'] : '—' ?></td>
            <td style="color:var(--text-muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="<?= e($log['old_value'] ?? '') ?>">
                <?= e($fmt_json($log['old_value'])) ?>
            </td>
            <td style="color:var(--text-muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="<?= e($log['new_value'] ?? '') ?>">
                <?= e($fmt_json($log['new_value'])) ?>
            </td>
            <td class="text-muted" style="font-size:.79rem;white-space:nowrap">
                <?= $log['ip_address'] ? e($log['ip_address']) : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="<?= e($qs(['user_id'=>$filter_user,'action'=>$filter_action,'table_name'=>$filter_table,'date_from'=>$date_from,'date_to'=>$date_to,'page'=>$pager['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p=max(1,$pager['current_page']-2);$p<=min($pager['total_pages'],$pager['current_page']+2);$p++): ?>
    <a href="<?= e($qs(['user_id'=>$filter_user,'action'=>$filter_action,'table_name'=>$filter_table,'date_from'=>$date_from,'date_to'=>$date_to,'page'=>$p])) ?>"
       class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($qs(['user_id'=>$filter_user,'action'=>$filter_action,'table_name'=>$filter_table,'date_from'=>$date_from,'date_to'=>$date_to,'page'=>$pager['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
