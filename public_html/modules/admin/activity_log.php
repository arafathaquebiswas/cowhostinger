<?php
require_once dirname(__DIR__, 2) . '/includes/saas_guard.php';
requireRole(['superadmin', 'support_staff']);

$db = getDB();

// ── Filters ────────────────────────────────────────────────────────────────────
$filter_action = sanitize($_GET['action'] ?? '');
$filter_user   = (int)($_GET['user_id'] ?? 0);
$filter_farm   = (int)($_GET['farm_id'] ?? 0);
$filter_date   = sanitize($_GET['date'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 50;
$offset        = ($page - 1) * $per_page;

// ── Build WHERE ────────────────────────────────────────────────────────────────
$wheres = [];
$params = [];

if ($filter_action !== '') {
    $wheres[] = 'al.action LIKE ?';
    $params[]  = '%' . $filter_action . '%';
}
if ($filter_user > 0) {
    $wheres[] = 'al.user_id = ?';
    $params[]  = $filter_user;
}
if ($filter_farm > 0) {
    $wheres[] = 'al.farm_id = ?';
    $params[]  = $filter_farm;
}
if ($filter_date !== '') {
    $wheres[] = 'DATE(al.created_at) = ?';
    $params[]  = $filter_date;
}

$where_sql = $wheres ? ('WHERE ' . implode(' AND ', $wheres)) : '';

// ── Count ──────────────────────────────────────────────────────────────────────
$count_stmt = $db->prepare(
    "SELECT COUNT(*) FROM activity_log al {$where_sql}"
);
$count_stmt->execute($params);
$total_rows = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));

// ── Rows ───────────────────────────────────────────────────────────────────────
$rows_stmt = $db->prepare(
    "SELECT al.*, u.name AS user_name, f.farm_name
     FROM activity_log al
     LEFT JOIN users u  ON u.id  = al.user_id
     LEFT JOIN farms f  ON f.id  = al.farm_id
     {$where_sql}
     ORDER BY al.created_at DESC
     LIMIT {$per_page} OFFSET {$offset}"
);
$rows_stmt->execute($params);
$rows = $rows_stmt->fetchAll();

// ── Distinct actions for filter dropdown ───────────────────────────────────────
$actions = $db->query(
    "SELECT DISTINCT action FROM activity_log ORDER BY action ASC LIMIT 100"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Staff list for filter ──────────────────────────────────────────────────────
$staff_users = $db->query(
    "SELECT id, name FROM users WHERE role IN ('superadmin','support_staff') ORDER BY name"
)->fetchAll();

$page_title = 'Activity Log';
$active_nav = 'activity_log';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Platform Activity Log</h2>
        <p class="text-sm text-muted"><?= number_format($total_rows) ?> total entries</p>
    </div>
    <?php if (isSuperAdmin()): ?>
    <a href="/modules/super_admin/dashboard.php" class="btn btn-secondary">← CEO Dashboard</a>
    <?php else: ?>
    <a href="/modules/support/dashboard.php" class="btn btn-secondary">← Support Dashboard</a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:1.5rem">
    <div style="padding:1rem 1.25rem">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group" style="margin:0;min-width:180px">
                <label class="form-label" style="font-size:.78rem">Action</label>
                <select name="action" class="form-control" style="font-size:.82rem">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>" <?= $filter_action === $a ? 'selected' : '' ?>>
                        <?= e($a) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:160px">
                <label class="form-label" style="font-size:.78rem">User</label>
                <select name="user_id" class="form-control" style="font-size:.82rem">
                    <option value="">All users</option>
                    <?php foreach ($staff_users as $su): ?>
                    <option value="<?= $su['id'] ?>" <?= $filter_user === (int)$su['id'] ? 'selected' : '' ?>>
                        <?= e($su['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;min-width:140px">
                <label class="form-label" style="font-size:.78rem">Farm ID</label>
                <input type="number" name="farm_id" class="form-control" style="font-size:.82rem"
                       value="<?= $filter_farm ?: '' ?>" placeholder="Any">
            </div>
            <div class="form-group" style="margin:0;min-width:140px">
                <label class="form-label" style="font-size:.78rem">Date</label>
                <input type="date" name="date" class="form-control" style="font-size:.82rem"
                       value="<?= e($filter_date) ?>">
            </div>
            <button type="submit" class="btn btn-primary" style="height:38px">Filter</button>
            <?php if ($filter_action || $filter_user || $filter_farm || $filter_date): ?>
            <a href="/modules/admin/activity_log.php" class="btn btn-secondary" style="height:38px;line-height:1.6">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Log table -->
<div class="card">
    <div style="overflow-x:auto">
    <table class="table" style="margin:0;font-size:.8rem">
        <thead>
            <tr>
                <th style="width:140px">Time</th>
                <th>Action</th>
                <th>User</th>
                <th>Role</th>
                <th>Farm</th>
                <th>Context</th>
                <th style="width:100px">IP</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
        <tr>
            <td colspan="7" style="text-align:center;padding:2rem;color:#9CA3AF">
                No activity log entries match your filters.
            </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
            $ctx = $row['context'] ? json_decode($row['context'], true) : [];
            $ctx_str = '';
            if ($ctx) {
                $parts = [];
                foreach ($ctx as $k => $v) {
                    $parts[] = $k . ': ' . (is_array($v) ? json_encode($v) : $v);
                }
                $ctx_str = implode(', ', $parts);
            }
            $role_color = match($row['user_role']) {
                'superadmin'    => '#7C3AED',
                'support_staff' => '#0284C7',
                'admin'         => '#059669',
                'worker'        => '#6B7280',
                default         => '#9CA3AF',
            };
        ?>
        <tr>
            <td class="text-xs text-muted" style="white-space:nowrap">
                <?= e(date('d M H:i:s', strtotime($row['created_at']))) ?>
            </td>
            <td style="font-weight:600;color:#111827">
                <?= e(str_replace('.', ' → ', $row['action'])) ?>
            </td>
            <td>
                <?= e($row['user_name'] ?? '—') ?>
            </td>
            <td>
                <span class="badge" style="background:<?= $role_color ?>;color:#fff;font-size:.65rem">
                    <?= e($row['user_role']) ?>
                </span>
            </td>
            <td class="text-xs">
                <?php if ($row['farm_name']): ?>
                <a href="/modules/super_admin/farm_detail.php?id=<?= (int)$row['farm_id'] ?>"
                   style="color:#0284C7"><?= e($row['farm_name']) ?></a>
                <?php else: ?>
                <span style="color:#9CA3AF">—</span>
                <?php endif; ?>
            </td>
            <td class="text-xs text-muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="<?= e($ctx_str) ?>">
                <?= e($ctx_str) ?>
            </td>
            <td class="text-xs text-muted"><?= e($row['ip_address'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div style="padding:.75rem 1.25rem;border-top:1px solid var(--border);display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <span class="text-xs text-muted">Page <?= $page ?> of <?= $total_pages ?></span>
        <?php
        $qs = http_build_query(array_filter([
            'action'  => $filter_action,
            'user_id' => $filter_user ?: '',
            'farm_id' => $filter_farm ?: '',
            'date'    => $filter_date,
        ]));
        $qs_sep = $qs ? '&' : '';
        for ($p = 1; $p <= $total_pages; $p++):
            if ($p > 7 && $p < $total_pages - 1) { echo '<span style="color:#9CA3AF">…</span>'; $p = $total_pages - 1; continue; }
        ?>
        <a href="?<?= $qs . $qs_sep ?>page=<?= $p ?>"
           class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"
           style="min-width:32px;text-align:center;padding:.25rem .5rem">
            <?= $p ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
