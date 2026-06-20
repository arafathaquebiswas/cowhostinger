<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireAuth();
requireFarmScope();
requireModule('maintenance');

$page_title = 'Maintenance';
$active_nav = 'maintenance';
$db = getDB();

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/maintenance/index.php');
    }
    if (!hasRole(['admin'])) {
        flashMessage('error', 'Insufficient permissions.');
        redirect('/modules/maintenance/index.php');
    }
    $action  = $_POST['action'] ?? '';
    $user_id = (int)$_SESSION['user_id'];

    if ($action === 'complete_log') {
        $log_id = (int)($_POST['log_id'] ?? 0);
        if ($log_id > 0) {
            $sel = $db->prepare("SELECT * FROM maintenance_logs WHERE id = ? AND completed_date IS NULL AND " . farmFilter());
            $sel->execute([$log_id]);
            $log = $sel->fetch();
            if ($log) {
                $db->prepare("UPDATE maintenance_logs SET completed_date = CURDATE() WHERE id = ?")->execute([$log_id]);
                auditLog($user_id, 'COMPLETE_MAINTENANCE_LOG', 'maintenance_logs', $log_id, ['completed_date'=>null], ['completed_date'=>date('Y-m-d')]);
                flashMessage('success', 'Maintenance log marked as completed.');
            }
        }
        redirect('/modules/maintenance/index.php?tab=logs');
    }

    if ($action === 'delete_log') {
        $log_id = (int)($_POST['log_id'] ?? 0);
        if ($log_id > 0) {
            $sel = $db->prepare("SELECT * FROM maintenance_logs WHERE id = ? AND " . farmFilter());
            $sel->execute([$log_id]);
            $log = $sel->fetch();
            if ($log) {
                $db->prepare("DELETE FROM maintenance_logs WHERE id = ?")->execute([$log_id]);
                auditLog($user_id, 'DELETE_MAINTENANCE_LOG', 'maintenance_logs', $log_id, $log, null);
                flashMessage('success', 'Maintenance log deleted.');
            }
        }
        redirect('/modules/maintenance/index.php?tab=logs');
    }

    if ($action === 'delete_area') {
        $area_id = (int)($_POST['area_id'] ?? 0);
        if ($area_id > 0) {
            $sel = $db->prepare("SELECT id, name FROM farm_areas WHERE id = ? AND " . farmFilter());
            $sel->execute([$area_id]);
            $area = $sel->fetch();
            if ($area) {
                try {
                    $db->prepare("DELETE FROM farm_areas WHERE id = ?")->execute([$area_id]);
                    auditLog($user_id, 'DELETE_FARM_AREA', 'farm_areas', $area_id, $area, null);
                    flashMessage('success', "Farm area '{$area['name']}' deleted.");
                } catch (PDOException $e) {
                    flashMessage('error', "Cannot delete '{$area['name']}' — it has linked maintenance records.");
                }
            }
        }
        redirect('/modules/maintenance/index.php?tab=areas');
    }

    if ($action === 'delete_purchase') {
        $purchase_id = (int)($_POST['purchase_id'] ?? 0);
        if ($purchase_id > 0) {
            $sel = $db->prepare("SELECT ap.* FROM area_purchases ap JOIN farm_areas fa ON fa.id=ap.area_id WHERE ap.id = ? AND " . farmFilter('fa'));
            $sel->execute([$purchase_id]);
            $purchase = $sel->fetch();
            if ($purchase) {
                $db->prepare("DELETE FROM area_purchases WHERE id = ?")->execute([$purchase_id]);
                auditLog($user_id, 'DELETE_AREA_PURCHASE', 'area_purchases', $purchase_id, $purchase, null);
                flashMessage('success', 'Area purchase deleted.');
            }
        }
        redirect('/modules/maintenance/index.php?tab=purchases');
    }

    redirect('/modules/maintenance/index.php');
}

$active_tab = in_array($_GET['tab'] ?? '', ['logs','areas','purchases'], true) ? $_GET['tab'] : 'logs';

// ---- Maintenance Logs ----
$filter_status = $_GET['status'] ?? '';
$filter_eq     = (int)($_GET['equipment_id'] ?? 0);
$filter_area   = (int)($_GET['area_id']      ?? 0);
$page_log      = max(1, (int)($_GET['page_log'] ?? 1));
$per_page      = 20;

$log_where  = [farmFilter('ml')];
$log_params = [];
if ($filter_status === 'pending')   { $log_where[] = 'ml.completed_date IS NULL'; }
if ($filter_status === 'completed') { $log_where[] = 'ml.completed_date IS NOT NULL'; }
if ($filter_eq > 0)   { $log_where[] = 'ml.equipment_id = ?'; $log_params[] = $filter_eq; }
if ($filter_area > 0) { $log_where[] = 'ml.area_id = ?';      $log_params[] = $filter_area; }
$log_where_sql = implode(' AND ', $log_where);

$log_count = $db->prepare("SELECT COUNT(*) FROM maintenance_logs ml WHERE {$log_where_sql}");
$log_count->execute($log_params);
$log_total = (int)$log_count->fetchColumn();
$pager_log = paginate($log_total, $per_page, $page_log);

$log_stmt = $db->prepare(
    "SELECT ml.id, ml.description, ml.cost, ml.scheduled_date, ml.completed_date,
            e.id AS equipment_id, e.name AS equipment_name,
            a.id AS area_id, a.name AS area_name
     FROM maintenance_logs ml
     LEFT JOIN equipment  e ON e.id = ml.equipment_id
     LEFT JOIN farm_areas a ON a.id = ml.area_id
     WHERE {$log_where_sql}
     ORDER BY (ml.completed_date IS NOT NULL), ml.scheduled_date ASC, ml.created_at DESC
     LIMIT ? OFFSET ?"
);
$log_stmt->execute(array_merge($log_params, [$per_page, $pager_log['offset']]));
$logs = $log_stmt->fetchAll();

// Log KPIs
$log_kpi_stmt = $db->prepare(
    "SELECT
       COUNT(*) AS total,
       SUM(completed_date IS NULL) AS pending_cnt,
       COALESCE(SUM(CASE WHEN MONTH(COALESCE(completed_date,scheduled_date,created_at))=MONTH(CURDATE()) AND YEAR(COALESCE(completed_date,scheduled_date,created_at))=YEAR(CURDATE()) THEN cost ELSE 0 END),0) AS month_cost,
       COALESCE(SUM(cost),0) AS total_cost
     FROM maintenance_logs
     WHERE " . farmFilter()
);
$log_kpi_stmt->execute();
$log_kpi = $log_kpi_stmt->fetch();

// Dropdowns for filters
$eq_list_stmt = $db->prepare("SELECT id, name FROM equipment WHERE " . farmFilter() . " ORDER BY name ASC");
$eq_list_stmt->execute();
$equipment_list = $eq_list_stmt->fetchAll();

$area_list_stmt = $db->prepare("SELECT id, name FROM farm_areas WHERE " . farmFilter() . " ORDER BY name ASC");
$area_list_stmt->execute();
$area_list = $area_list_stmt->fetchAll();

// ---- Farm Areas ----
$page_area  = max(1, (int)($_GET['page_area'] ?? 1));
$area_cnt_stmt = $db->prepare("SELECT COUNT(*) FROM farm_areas WHERE " . farmFilter());
$area_cnt_stmt->execute();
$area_count = (int)$area_cnt_stmt->fetchColumn();
$pager_area = paginate($area_count, $per_page, $page_area);
$area_stmt  = $db->prepare(
    "SELECT fa.id, fa.name, fa.type, fa.capacity, fa.notes,
            (SELECT COUNT(*) FROM maintenance_logs ml WHERE ml.area_id = fa.id) AS log_count,
            (SELECT COUNT(*) FROM area_purchases ap WHERE ap.area_id = fa.id)   AS purchase_count
     FROM farm_areas fa
     WHERE " . farmFilter('fa') . "
     ORDER BY fa.name ASC
     LIMIT ? OFFSET ?"
);
$area_stmt->execute([$per_page, $pager_area['offset']]);
$areas = $area_stmt->fetchAll();

$area_type_labels = [
    'barn' => 'Barn', 'storage' => 'Storage', 'milking_shed' => 'Milking Shed',
    'medical' => 'Medical', 'office' => 'Office', 'other' => 'Other',
];

// ---- Area Purchases ----
$filter_area_p = (int)($_GET['area_purchase_area_id'] ?? 0);
$page_purch    = max(1, (int)($_GET['page_purch'] ?? 1));

$purch_where  = [farmFilter('fa')];
$purch_params = [];
if ($filter_area_p > 0) { $purch_where[] = 'ap.area_id = ?'; $purch_params[] = $filter_area_p; }
$purch_where_sql = implode(' AND ', $purch_where);

$purch_count = $db->prepare("SELECT COUNT(*) FROM area_purchases ap JOIN farm_areas fa ON fa.id=ap.area_id WHERE {$purch_where_sql}");
$purch_count->execute($purch_params);
$purch_total = (int)$purch_count->fetchColumn();
$pager_purch = paginate($purch_total, $per_page, $page_purch);

$purch_totals = $db->prepare("SELECT COALESCE(SUM(ap.cost),0) AS total FROM area_purchases ap JOIN farm_areas fa ON fa.id=ap.area_id WHERE {$purch_where_sql}");
$purch_totals->execute($purch_params);
$purch_total_cost = (float)$purch_totals->fetchColumn();

$purch_stmt = $db->prepare(
    "SELECT ap.id, ap.item, ap.cost, ap.purchase_date, ap.notes,
            fa.name AS area_name
     FROM area_purchases ap
     JOIN farm_areas fa ON fa.id = ap.area_id
     WHERE {$purch_where_sql}
     ORDER BY ap.purchase_date DESC, ap.id DESC
     LIMIT ? OFFSET ?"
);
$purch_stmt->execute(array_merge($purch_params, [$per_page, $pager_purch['offset']]));
$purchases = $purch_stmt->fetchAll();

$qs = static fn(array $p): string =>
    '/modules/maintenance/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null && $v !== 0));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Maintenance</h2>
        <p class="text-sm text-muted">Equipment logs, farm areas &amp; area purchases</p>
    </div>
    <?php if (hasRole(['admin'])): ?>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/maintenance/log_form.php"      class="btn btn-primary btn-sm">+ Log</a>
        <a href="/modules/maintenance/area_form.php"     class="btn btn-secondary btn-sm">+ Area</a>
        <a href="/modules/maintenance/purchase_form.php" class="btn btn-secondary btn-sm">+ Purchase</a>
    </div>
    <?php endif; ?>
</div>

<!-- KPI bar -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(155px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-label">Pending Logs</div>
        <div class="kpi-value" style="color:var(--warning)"><?= (int)$log_kpi['pending_cnt'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Total Log Entries</div>
        <div class="kpi-value"><?= (int)$log_kpi['total'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">This Month Cost</div>
        <div class="kpi-value" style="font-size:1.1rem"><?= e(formatCurrency((float)$log_kpi['month_cost'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-label">All-Time Maint. Cost</div>
        <div class="kpi-value" style="font-size:1.1rem"><?= e(formatCurrency((float)$log_kpi['total_cost'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-label">Farm Areas</div>
        <div class="kpi-value"><?= $area_count ?></div>
    </div>
</div>

<!-- Tabs -->
<nav class="tab-nav" style="margin-bottom:1.25rem">
    <button class="tab-btn <?= $active_tab==='logs'      ?'active':'' ?>" data-tab="tab_logs">
        Maintenance Logs
        <?php if ((int)$log_kpi['pending_cnt'] > 0): ?>
        <span class="badge badge-yellow" style="margin-left:.3rem"><?= (int)$log_kpi['pending_cnt'] ?> pending</span>
        <?php endif; ?>
    </button>
    <button class="tab-btn <?= $active_tab==='areas'     ?'active':'' ?>" data-tab="tab_areas">Farm Areas</button>
    <button class="tab-btn <?= $active_tab==='purchases' ?'active':'' ?>" data-tab="tab_purchases">Area Purchases</button>
</nav>

<!-- ==================== TAB: MAINTENANCE LOGS ==================== -->
<div id="tab_logs" class="tab-panel <?= $active_tab==='logs'?'active':'' ?>">

    <!-- Filters -->
    <form method="GET" action="/modules/maintenance/index.php"
          style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
        <input type="hidden" name="tab" value="logs">
        <div class="form-group" style="margin:0">
            <label class="form-label" style="font-size:.78rem">Status</label>
            <select name="status" class="form-control">
                <option value="">All</option>
                <option value="pending"   <?= $filter_status==='pending'   ?'selected':'' ?>>Pending</option>
                <option value="completed" <?= $filter_status==='completed' ?'selected':'' ?>>Completed</option>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label" style="font-size:.78rem">Equipment</label>
            <select name="equipment_id" class="form-control">
                <option value="">All</option>
                <?php foreach ($equipment_list as $eq): ?>
                <option value="<?= $eq['id'] ?>" <?= $filter_eq===$eq['id']?'selected':'' ?>><?= e($eq['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label" style="font-size:.78rem">Area</label>
            <select name="area_id" class="form-control">
                <option value="">All</option>
                <?php foreach ($area_list as $ar): ?>
                <option value="<?= $ar['id'] ?>" <?= $filter_area===$ar['id']?'selected':'' ?>><?= e($ar['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($filter_status || $filter_eq || $filter_area): ?>
        <a href="/modules/maintenance/index.php?tab=logs" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <div class="card" style="margin-bottom:1rem">
        <?php if (empty($logs)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
            <h3>No maintenance logs found</h3>
            <?php if (hasRole(['admin'])): ?><p><a href="/modules/maintenance/log_form.php">Add the first log entry.</a></p><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Target</th>
                    <th>Description</th>
                    <th>Cost</th>
                    <th>Scheduled</th>
                    <th>Completed</th>
                    <?php if (hasRole(['admin'])): ?><th style="width:110px">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <tr style="<?= $log['completed_date'] ? '' : 'background:var(--warning-soft,#FFFBEB)' ?>">
                <td>
                    <?php if ($log['completed_date']): ?>
                    <span class="badge badge-green">Completed</span>
                    <?php else: ?>
                    <span class="badge badge-yellow">Pending</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($log['equipment_name']): ?>
                    <span class="badge badge-blue" style="font-size:.75rem">Equipment</span>
                    <div style="font-size:.85rem;margin-top:.15rem"><?= e($log['equipment_name']) ?></div>
                    <?php elseif ($log['area_name']): ?>
                    <span class="badge badge-purple" style="font-size:.75rem">Area</span>
                    <div style="font-size:.85rem;margin-top:.15rem"><?= e($log['area_name']) ?></div>
                    <?php else: ?>
                    <span class="badge badge-gray" style="font-size:.75rem">General</span>
                    <?php endif; ?>
                </td>
                <td style="max-width:240px">
                    <div style="font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($log['description']) ?></div>
                </td>
                <td><?= $log['cost'] ? e(formatCurrency((float)$log['cost'])) : '—' ?></td>
                <td style="white-space:nowrap"><?= $log['scheduled_date'] ? e(formatDate($log['scheduled_date'])) : '—' ?></td>
                <td style="white-space:nowrap"><?= $log['completed_date'] ? e(formatDate($log['completed_date'])) : '—' ?></td>
                <?php if (hasRole(['admin'])): ?>
                <td>
                    <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                        <a href="/modules/maintenance/log_form.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <?php if (!$log['completed_date']): ?>
                        <form method="POST" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="complete_log">
                            <input type="hidden" name="log_id"  value="<?= $log['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Mark Complete">&#10003;</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this maintenance log?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_log">
                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pager_log['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pager_log['has_prev']): ?>
        <a href="<?= e($qs(['tab'=>'logs','status'=>$filter_status,'equipment_id'=>$filter_eq,'area_id'=>$filter_area,'page_log'=>$pager_log['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p=max(1,$pager_log['current_page']-2);$p<=min($pager_log['total_pages'],$pager_log['current_page']+2);$p++): ?>
        <a href="<?= e($qs(['tab'=>'logs','status'=>$filter_status,'equipment_id'=>$filter_eq,'area_id'=>$filter_area,'page_log'=>$p])) ?>" class="page-btn <?= $p===$pager_log['current_page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager_log['has_next']): ?>
        <a href="<?= e($qs(['tab'=>'logs','status'=>$filter_status,'equipment_id'=>$filter_eq,'area_id'=>$filter_area,'page_log'=>$pager_log['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ==================== TAB: FARM AREAS ==================== -->
<div id="tab_areas" class="tab-panel <?= $active_tab==='areas'?'active':'' ?>">
    <div class="card" style="margin-bottom:1rem">
        <?php if (empty($areas)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <h3>No farm areas defined</h3>
            <?php if (hasRole(['admin'])): ?><p><a href="/modules/maintenance/area_form.php">Add the first farm area.</a></p><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Capacity</th>
                    <th>Maint. Logs</th>
                    <th>Purchases</th>
                    <th>Notes</th>
                    <?php if (hasRole(['admin'])): ?><th style="width:90px">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($areas as $area): ?>
            <tr>
                <td style="font-weight:600"><?= e($area['name']) ?></td>
                <td><span class="badge badge-blue" style="font-size:.75rem"><?= e($area_type_labels[$area['type']] ?? ucfirst($area['type'])) ?></span></td>
                <td><?= $area['capacity'] ? e(number_format((int)$area['capacity'])) . ' head' : '—' ?></td>
                <td>
                    <?php if ($area['log_count'] > 0): ?>
                    <a href="<?= e($qs(['tab'=>'logs','area_id'=>$area['id']])) ?>"><?= $area['log_count'] ?> log<?= $area['log_count']!==1?'s':'' ?></a>
                    <?php else: ?><span class="text-muted">0</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($area['purchase_count'] > 0): ?>
                    <a href="<?= e($qs(['tab'=>'purchases','area_purchase_area_id'=>$area['id']])) ?>"><?= $area['purchase_count'] ?></a>
                    <?php else: ?><span class="text-muted">0</span><?php endif; ?>
                </td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem"><?= $area['notes'] ? e($area['notes']) : '—' ?></td>
                <?php if (hasRole(['admin'])): ?>
                <td>
                    <div style="display:flex;gap:.35rem">
                        <a href="/modules/maintenance/area_form.php?id=<?= $area['id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete <?= e(addslashes($area['name'])) ?>?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="delete_area">
                            <input type="hidden" name="area_id" value="<?= $area['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pager_area['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pager_area['has_prev']): ?>
        <a href="<?= e($qs(['tab'=>'areas','page_area'=>$pager_area['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p=max(1,$pager_area['current_page']-2);$p<=min($pager_area['total_pages'],$pager_area['current_page']+2);$p++): ?>
        <a href="<?= e($qs(['tab'=>'areas','page_area'=>$p])) ?>" class="page-btn <?= $p===$pager_area['current_page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager_area['has_next']): ?>
        <a href="<?= e($qs(['tab'=>'areas','page_area'=>$pager_area['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ==================== TAB: AREA PURCHASES ==================== -->
<div id="tab_purchases" class="tab-panel <?= $active_tab==='purchases'?'active':'' ?>">

    <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.85rem;flex-wrap:wrap">
        <form method="GET" action="/modules/maintenance/index.php" style="display:flex;gap:.5rem;align-items:flex-end">
            <input type="hidden" name="tab" value="purchases">
            <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:.78rem">Filter by Area</label>
                <select name="area_purchase_area_id" class="form-control" onchange="this.form.submit()">
                    <option value="">All Areas</option>
                    <?php foreach ($area_list as $ar): ?>
                    <option value="<?= $ar['id'] ?>" <?= $filter_area_p===$ar['id']?'selected':'' ?>><?= e($ar['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <span style="font-size:.875rem;color:var(--text-muted);margin-left:auto">
            Total: <strong><?= e(formatCurrency($purch_total_cost)) ?></strong>
            (<?= number_format($purch_total) ?> record<?= $purch_total!==1?'s':'' ?>)
        </span>
        <?php if (hasRole(['admin'])): ?>
        <a href="/modules/maintenance/purchase_form.php" class="btn btn-primary btn-sm">+ Purchase</a>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-bottom:1rem">
        <?php if (empty($purchases)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
            </svg>
            <h3>No purchases recorded</h3>
            <?php if (hasRole(['admin'])): ?><p><a href="/modules/maintenance/purchase_form.php">Record an area purchase.</a></p><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Area</th>
                    <th>Item</th>
                    <th>Cost</th>
                    <th>Notes</th>
                    <?php if (hasRole(['admin'])): ?><th style="width:90px">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($purchases as $p): ?>
            <tr>
                <td style="white-space:nowrap"><?= e(formatDate($p['purchase_date'])) ?></td>
                <td><span class="badge badge-purple" style="font-size:.75rem"><?= e($p['area_name']) ?></span></td>
                <td style="font-weight:500"><?= e($p['item']) ?></td>
                <td style="font-weight:700"><?= e(formatCurrency((float)$p['cost'])) ?></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem"><?= $p['notes'] ? e($p['notes']) : '—' ?></td>
                <?php if (hasRole(['admin'])): ?>
                <td>
                    <div style="display:flex;gap:.35rem">
                        <a href="/modules/maintenance/purchase_form.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this purchase?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"      value="delete_purchase">
                            <input type="hidden" name="purchase_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pager_purch['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pager_purch['has_prev']): ?>
        <a href="<?= e($qs(['tab'=>'purchases','area_purchase_area_id'=>$filter_area_p,'page_purch'=>$pager_purch['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p=max(1,$pager_purch['current_page']-2);$p<=min($pager_purch['total_pages'],$pager_purch['current_page']+2);$p++): ?>
        <a href="<?= e($qs(['tab'=>'purchases','area_purchase_area_id'=>$filter_area_p,'page_purch'=>$p])) ?>" class="page-btn <?= $p===$pager_purch['current_page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager_purch['has_next']): ?>
        <a href="<?= e($qs(['tab'=>'purchases','area_purchase_area_id'=>$filter_area_p,'page_purch'=>$pager_purch['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$init_tab = e("tab_{$active_tab}");
$inline_js = <<<JSEOF
(function() {
    var panels = document.querySelectorAll('.tab-panel');
    var btns   = document.querySelectorAll('.tab-btn');
    btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = btn.getAttribute('data-tab');
            panels.forEach(function(p) { p.classList.remove('active'); });
            btns.forEach(function(b)   { b.classList.remove('active'); });
            document.getElementById(target).classList.add('active');
            btn.classList.add('active');
        });
    });
    var initEl = document.getElementById('{$init_tab}');
    if (initEl) {
        panels.forEach(function(p) { p.classList.remove('active'); });
        btns.forEach(function(b)   { b.classList.remove('active'); });
        initEl.classList.add('active');
        var btn = document.querySelector('[data-tab="{$init_tab}"]');
        if (btn) btn.classList.add('active');
    }
})();
JSEOF;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
