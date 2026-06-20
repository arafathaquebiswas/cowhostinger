<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();
requireModule('equipment');

$page_title = 'Equipment';
$active_nav = 'equipment';
$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/equipment/index.php');
    }

    $action  = $_POST['action'] ?? '';
    $eq_id   = (int)($_POST['equipment_id'] ?? 0);
    $user_id = (int)$_SESSION['user_id'];

    if ($action === 'update_status' && hasRole(['admin']) && $eq_id > 0) {
        $new_status = sanitize($_POST['new_status'] ?? '');
        $valid      = ['operational', 'maintenance', 'damaged'];
        if (in_array($new_status, $valid, true)) {
            $sel = $db->prepare("SELECT id, name, status FROM equipment WHERE id = ?");
            $sel->execute([$eq_id]);
            $eq = $sel->fetch();
            if ($eq) {
                $extra = [];
                if ($new_status === 'operational') {
                    $extra = [', last_maintenance_date = CURDATE()'];
                }
                $sql = "UPDATE equipment SET status = ?" . ($extra ? $extra[0] : '') . " WHERE id = ?";
                $db->prepare($sql)->execute([$new_status, $eq_id]);
                auditLog($user_id, 'UPDATE_EQUIPMENT_STATUS', 'equipment', $eq_id,
                    ['status' => $eq['status']], ['status' => $new_status]);
                flashMessage('success', "{$eq['name']} status → " . ucfirst($new_status) . '.');
            }
        }
        redirect('/modules/equipment/index.php');
    }

    if ($action === 'delete' && hasRole(['admin']) && $eq_id > 0) {
        $sel = $db->prepare("SELECT id, name FROM equipment WHERE id = ?");
        $sel->execute([$eq_id]);
        $eq = $sel->fetch();
        if ($eq) {
            try {
                $db->prepare("DELETE FROM equipment WHERE id = ?")->execute([$eq_id]);
                auditLog($user_id, 'DELETE_EQUIPMENT', 'equipment', $eq_id, $eq, null);
                flashMessage('success', "Equipment '{$eq['name']}' deleted.");
            } catch (PDOException $e) {
                flashMessage('error', "Cannot delete '{$eq['name']}' — it has linked maintenance records.");
            }
        }
        redirect('/modules/equipment/index.php');
    }

    redirect('/modules/equipment/index.php');
}

// Filters
$filter_status = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;

$valid_statuses = ['operational', 'maintenance', 'damaged'];
if (!in_array($filter_status, $valid_statuses, true)) $filter_status = '';

$where  = ['1=1'];
$params = [];
if ($filter_status !== '') {
    $where[]  = 'status = ?';
    $params[] = $filter_status;
}
if ($search !== '') {
    $where[]  = 'name LIKE ?';
    $params[] = "%{$search}%";
}
$where_sql = implode(' AND ', $where);

$count_stmt = $db->prepare("SELECT COUNT(*) FROM equipment WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pager = paginate($total, $per_page, $page);

$fetch_params = array_merge($params, [$per_page, $pager['offset']]);
$stmt = $db->prepare(
    "SELECT id, name, purchase_date, status, lifespan_months, last_maintenance_date, photo_url, notes
     FROM equipment
     WHERE {$where_sql}
     ORDER BY FIELD(status,'damaged','maintenance','operational'), name ASC
     LIMIT ? OFFSET ?"
);
$stmt->execute($fetch_params);
$equipment = $stmt->fetchAll();

// Status counts
$sc_rows = $db->query("SELECT status, COUNT(*) AS cnt FROM equipment GROUP BY status")->fetchAll();
$status_counts = [];
foreach ($sc_rows as $r) $status_counts[$r['status']] = (int)$r['cnt'];
$total_all = (int)$db->query("SELECT COUNT(*) FROM equipment")->fetchColumn();

function equipment_status_badge(string $s): string {
    return match($s) {
        'operational' => 'badge-green',
        'maintenance' => 'badge-yellow',
        'damaged'     => 'badge-red',
        default       => 'badge-gray',
    };
}

// Maintenance age helper: months since last maintenance
function maintenance_warning(?string $last_date, ?int $lifespan_months): string {
    if (!$last_date || !$lifespan_months) return '';
    $months_since = (int)round((time() - strtotime($last_date)) / (30.44 * 86400));
    $pct = $lifespan_months > 0 ? $months_since / $lifespan_months : 0;
    if ($pct >= 1)    return '<span class="badge badge-red" title="Overdue maintenance">Overdue</span>';
    if ($pct >= 0.75) return '<span class="badge badge-yellow" title="Maintenance due soon">Due Soon</span>';
    return '';
}

$qs = static fn(array $p): string =>
    '/modules/equipment/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Equipment</h2>
        <p class="text-sm text-muted"><?= $total_all ?> piece<?= $total_all !== 1 ? 's' : '' ?> of equipment</p>
    </div>
    <?php if (hasRole(['admin'])): ?>
    <a href="/modules/equipment/form.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Equipment
    </a>
    <?php endif; ?>
</div>

<!-- Summary KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(145px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Operational</div>
        <div class="kpi-value"><?= $status_counts['operational'] ?? 0 ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-label">In Maintenance</div>
        <div class="kpi-value"><?= $status_counts['maintenance'] ?? 0 ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">Damaged</div>
        <div class="kpi-value"><?= $status_counts['damaged'] ?? 0 ?></div>
    </div>
</div>

<!-- Quick filters + search -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem">
    <a href="<?= e($qs(['search'=>$search])) ?>"
       class="btn btn-sm <?= $filter_status === '' ? 'btn-primary' : 'btn-secondary' ?>">All</a>
    <?php foreach (['operational'=>'Operational','maintenance'=>'In Maintenance','damaged'=>'Damaged'] as $sval=>$slabel): ?>
    <a href="<?= e($qs(['status'=>$sval,'search'=>$search])) ?>"
       class="btn btn-sm <?= $filter_status===$sval ? 'btn-primary' : 'btn-secondary' ?>">
        <?= $slabel ?> <span style="opacity:.7">(<?= $status_counts[$sval] ?? 0 ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<form method="GET" action="/modules/equipment/index.php"
      style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem">
    <input type="hidden" name="status" value="<?= e($filter_status) ?>">
    <input type="text" name="search" class="form-control" placeholder="Search by name…"
           value="<?= e($search) ?>" style="max-width:260px">
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <?php if ($search): ?>
    <a href="<?= e($qs(['status'=>$filter_status])) ?>" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
</form>

<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($equipment)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M4.93 4.93l1.41 1.41M4.93 19.07l1.41-1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"/>
        </svg>
        <h3>No equipment found</h3>
        <p><?= hasRole(['admin']) ? '<a href="/modules/equipment/form.php">Add the first piece of equipment.</a>' : 'No equipment records yet.' ?></p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Equipment</th>
                <th>Status</th>
                <th>Purchase Date</th>
                <th>Last Maintenance</th>
                <th>Lifespan</th>
                <?php if (hasRole(['admin'])): ?><th style="width:160px">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($equipment as $eq):
            $maint_badge = maintenance_warning($eq['last_maintenance_date'], $eq['lifespan_months']);
        ?>
        <tr>
            <td>
                <div style="display:flex;align-items:center;gap:.65rem">
                    <?php if ($eq['photo_url']): ?>
                    <img src="<?= e($eq['photo_url']) ?>" alt="<?= e($eq['name']) ?>"
                         style="width:36px;height:36px;object-fit:cover;border-radius:var(--radius);border:1px solid var(--border);flex-shrink:0">
                    <?php else: ?>
                    <span style="width:36px;height:36px;border-radius:var(--radius);background:var(--primary-soft);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">🔧</span>
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:600"><?= e($eq['name']) ?></div>
                        <?php if ($eq['notes']): ?>
                        <div class="text-muted" style="font-size:.79rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($eq['notes']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <td>
                <span class="badge <?= equipment_status_badge($eq['status']) ?>">
                    <?= e(ucfirst($eq['status'])) ?>
                </span>
                <?= $maint_badge ?>
            </td>
            <td><?= $eq['purchase_date'] ? e(formatDate($eq['purchase_date'])) : '—' ?></td>
            <td><?= $eq['last_maintenance_date'] ? e(formatDate($eq['last_maintenance_date'])) : '—' ?></td>
            <td><?= $eq['lifespan_months'] ? e($eq['lifespan_months']) . ' mo' : '—' ?></td>
            <?php if (hasRole(['admin'])): ?>
            <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <a href="/modules/equipment/form.php?id=<?= $eq['id'] ?>"
                       class="btn btn-sm btn-secondary" title="Edit">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </a>
                    <!-- Status cycle button -->
                    <?php
                    $next_status = match($eq['status']) {
                        'operational' => 'maintenance',
                        'maintenance' => 'operational',
                        'damaged'     => 'maintenance',
                        default       => 'operational',
                    };
                    $next_label = match($eq['status']) {
                        'operational' => 'Send to Maintenance',
                        'maintenance' => 'Mark Operational',
                        'damaged'     => 'Start Repair',
                        default       => 'Update Status',
                    };
                    ?>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"       value="update_status">
                        <input type="hidden" name="equipment_id" value="<?= $eq['id'] ?>">
                        <input type="hidden" name="new_status"   value="<?= e($next_status) ?>">
                        <button type="submit" class="btn btn-sm btn-warning" title="<?= e($next_label) ?>">
                            <?= $eq['status'] === 'operational' ? '🔧' : ($eq['status'] === 'damaged' ? '🛠️' : '✓') ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Delete <?= e(addslashes($eq['name'])) ?>?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"       value="delete">
                        <input type="hidden" name="equipment_id" value="<?= $eq['id'] ?>">
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

<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="<?= e($qs(['status'=>$filter_status,'search'=>$search,'page'=>$pager['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p=max(1,$pager['current_page']-2); $p<=min($pager['total_pages'],$pager['current_page']+2); $p++): ?>
    <a href="<?= e($qs(['status'=>$filter_status,'search'=>$search,'page'=>$p])) ?>"
       class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($qs(['status'=>$filter_status,'search'=>$search,'page'=>$pager['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
