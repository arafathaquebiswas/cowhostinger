<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();

$page_title = 'Alerts';
$active_nav = 'alerts';

$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!verifyCsrfToken($token)) {
        flashMessage('error', 'Invalid request. Please try again.');
        redirect('/modules/alerts/index.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read' && !empty($_POST['alert_id'])) {
        $id = (int)$_POST['alert_id'];
        $db->prepare("UPDATE alerts SET is_read = 1 WHERE id = ?")->execute([$id]);
        auditLog((int)$_SESSION['user_id'], 'MARK_ALERT_READ', 'alerts', $id);
        flashMessage('success', 'Alert marked as read.');
    } elseif ($action === 'mark_all_read') {
        $db->exec("UPDATE alerts SET is_read = 1 WHERE is_read = 0");
        auditLog((int)$_SESSION['user_id'], 'MARK_ALL_ALERTS_READ', 'alerts');
        flashMessage('success', 'All alerts marked as read.');
    } elseif ($action === 'delete' && !empty($_POST['alert_id']) && hasRole(['admin'])) {
        $id = (int)$_POST['alert_id'];
        $db->prepare("DELETE FROM alerts WHERE id = ?")->execute([$id]);
        auditLog((int)$_SESSION['user_id'], 'DELETE_ALERT', 'alerts', $id);
        flashMessage('success', 'Alert deleted.');
    } elseif ($action === 'delete_all_read' && hasRole(['admin'])) {
        $db->exec("DELETE FROM alerts WHERE is_read = 1");
        auditLog((int)$_SESSION['user_id'], 'DELETE_READ_ALERTS', 'alerts');
        flashMessage('success', 'All read alerts deleted.');
    }

    redirect('/modules/alerts/index.php?' . http_build_query(array_filter([
        'severity' => $_POST['current_severity'] ?? '',
        'filter'   => $_POST['current_filter']   ?? '',
    ])));
}

// Filters
$filter   = in_array($_GET['filter'] ?? '', ['unread', 'all', 'read']) ? $_GET['filter'] : 'all';
$severity = in_array($_GET['severity'] ?? '', ['critical', 'high', 'medium', 'low']) ? $_GET['severity'] : '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

// Build WHERE
$where  = ['1=1'];
$params = [];
if ($filter === 'unread') { $where[] = 'is_read = 0'; }
if ($filter === 'read')   { $where[] = 'is_read = 1'; }
if ($severity !== '')     { $where[] = 'severity = ?'; $params[] = $severity; }
$where_sql = implode(' AND ', $where);

// Count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM alerts WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$pager  = paginate($total, $per_page, $page);
$offset = $pager['offset'];

// Fetch
$fetch_params = array_merge($params, [$per_page, $offset]);
$stmt = $db->prepare(
    "SELECT id, type, severity, message, related_table, related_id, is_read, created_at
     FROM alerts WHERE {$where_sql}
     ORDER BY FIELD(severity,'critical','high','medium','low'), created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute($fetch_params);
$alerts = $stmt->fetchAll();

$unread_count = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE is_read = 0")->fetchColumn();

$severity_labels = [
    'critical' => ['label' => 'Critical', 'badge' => 'badge-red'],
    'high'     => ['label' => 'High',     'badge' => 'badge-yellow'],
    'medium'   => ['label' => 'Medium',   'badge' => 'badge-blue'],
    'low'      => ['label' => 'Low',      'badge' => 'badge-green'],
];

$type_labels = [
    'low_feed_stock'     => 'Low Feed Stock',
    'low_medicine_stock' => 'Low Medicine Stock',
    'medicine_expiring'  => 'Medicine Expiring',
    'sick_cow'           => 'Sick Cow',
    'overdue_task'       => 'Overdue Task',
    'calving_approaching'=> 'Calving Approaching',
    'maintenance_overdue'=> 'Maintenance Overdue',
    'equipment_damaged'  => 'Equipment Damaged',
];

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Alerts</h2>
        <?php if ($unread_count > 0): ?>
        <p class="text-sm" style="color:var(--danger);margin-top:.25rem">
            <?= $unread_count ?> unread alert<?= $unread_count > 1 ? 's' : '' ?>
        </p>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-1" style="flex-wrap:wrap">
        <?php if ($unread_count > 0): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <input type="hidden" name="current_severity" value="<?= e($severity) ?>">
            <input type="hidden" name="current_filter"   value="<?= e($filter) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Mark All Read</button>
        </form>
        <?php endif; ?>
        <button class="btn btn-secondary btn-sm" id="refreshAlertsBtn">Refresh Alerts</button>
        <?php if (hasRole(['admin'])): ?>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Delete all read alerts? This cannot be undone.')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_all_read">
            <input type="hidden" name="current_severity" value="<?= e($severity) ?>">
            <input type="hidden" name="current_filter"   value="<?= e($filter) ?>">
            <button type="submit" class="btn btn-danger btn-sm">Clear Read Alerts</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Filter bar -->
<div class="filter-bar" style="margin-bottom:1rem">
    <div class="d-flex gap-1" style="flex-wrap:wrap">
        <?php
        $base_url = '/modules/alerts/index.php';
        $filter_params = fn(array $p) => $base_url . '?' . http_build_query(array_filter($p));

        $filters = ['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'];
        foreach ($filters as $fkey => $flabel):
            $active_cls = $filter === $fkey ? 'btn-primary' : 'btn-secondary';
        ?>
        <a href="<?= e($filter_params(['filter' => $fkey, 'severity' => $severity])) ?>"
           class="btn <?= $active_cls ?> btn-sm"><?= $flabel ?></a>
        <?php endforeach; ?>

        <span style="width:1px;background:var(--border);margin:0 .25rem"></span>

        <a href="<?= e($filter_params(['filter' => $filter])) ?>"
           class="btn btn-sm <?= $severity === '' ? 'btn-primary' : 'btn-secondary' ?>">All Severity</a>
        <?php foreach ($severity_labels as $skey => $sdata):
            $active_cls = $severity === $skey ? 'btn-primary' : 'btn-secondary';
        ?>
        <a href="<?= e($filter_params(['filter' => $filter, 'severity' => $skey])) ?>"
           class="btn <?= $active_cls ?> btn-sm"><?= $sdata['label'] ?></a>
        <?php endforeach; ?>
    </div>
    <span class="text-sm text-muted" style="margin-left:auto;white-space:nowrap">
        <?= number_format($total) ?> alert<?= $total !== 1 ? 's' : '' ?>
    </span>
</div>

<!-- Alerts list -->
<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($alerts)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        <h3>No alerts found</h3>
        <p>No alerts match the current filter. The farm is running smoothly!</p>
    </div>
    <?php else: ?>
    <?php foreach ($alerts as $alert):
        $sev_info = $severity_labels[$alert['severity']] ?? ['label' => ucfirst($alert['severity']), 'badge' => 'badge-gray'];
        $type_label = $type_labels[$alert['type']] ?? ucwords(str_replace('_', ' ', $alert['type']));
        $is_unread  = !(bool)$alert['is_read'];
    ?>
    <div class="alert-item <?= $is_unread ? 'unread ' . e($alert['severity']) : '' ?>" id="alert_<?= $alert['id'] ?>">
        <span class="severity-dot severity-<?= e($alert['severity']) ?>" style="margin-top:.4rem;flex-shrink:0"></span>
        <div class="alert-item-body">
            <div class="alert-item-msg"><?= e($alert['message']) ?></div>
            <div class="alert-item-meta" style="margin-top:.35rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <span class="badge <?= $sev_info['badge'] ?>"><?= $sev_info['label'] ?></span>
                <span class="badge badge-gray"><?= e($type_label) ?></span>
                <?php if ($alert['related_table']): ?>
                <span class="text-xs text-muted"><?= e($alert['related_table']) ?> #<?= e($alert['related_id']) ?></span>
                <?php endif; ?>
                <span class="text-xs text-muted"><?= e(formatDateTime($alert['created_at'])) ?></span>
                <?php if (!$is_unread): ?>
                <span class="badge badge-gray" style="font-size:.68rem">READ</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="alert-item-actions">
            <?php if ($is_unread): ?>
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action"           value="mark_read">
                <input type="hidden" name="alert_id"         value="<?= $alert['id'] ?>">
                <input type="hidden" name="current_severity" value="<?= e($severity) ?>">
                <input type="hidden" name="current_filter"   value="<?= e($filter) ?>">
                <button type="submit" class="btn btn-sm btn-secondary" title="Mark as read">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                </button>
            </form>
            <?php endif; ?>
            <?php if (hasRole(['admin'])): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Delete this alert?')">
                <?= csrfField() ?>
                <input type="hidden" name="action"           value="delete">
                <input type="hidden" name="alert_id"         value="<?= $alert['id'] ?>">
                <input type="hidden" name="current_severity" value="<?= e($severity) ?>">
                <input type="hidden" name="current_filter"   value="<?= e($filter) ?>">
                <button type="submit" class="btn btn-sm btn-danger" title="Delete alert">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="<?= e($filter_params(['filter' => $filter, 'severity' => $severity, 'page' => $pager['current_page'] - 1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p = max(1, $pager['current_page'] - 2); $p <= min($pager['total_pages'], $pager['current_page'] + 2); $p++): ?>
    <a href="<?= e($filter_params(['filter' => $filter, 'severity' => $severity, 'page' => $p])) ?>"
       class="page-btn <?= $p === $pager['current_page'] ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($filter_params(['filter' => $filter, 'severity' => $severity, 'page' => $pager['current_page'] + 1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
document.getElementById('refreshAlertsBtn').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Refreshing…';
    fetch('/api/generate_system_alerts.php')
        .then(r => r.json())
        .then(function(d) {
            btn.disabled = false;
            btn.textContent = 'Refresh Alerts';
            if (d.alerts_generated > 0) {
                window.location.reload();
            } else {
                alert('No new alerts generated — everything is up to date.');
            }
        })
        .catch(function() { btn.disabled = false; btn.textContent = 'Refresh Alerts'; });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
