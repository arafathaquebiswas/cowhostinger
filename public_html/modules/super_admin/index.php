<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['superadmin']);

$page_title = 'Super Admin — All Farms';
$active_nav = 'super_admin';

$db = getDB();

// ── Handle POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        jsonResponse(['ok' => false, 'error' => 'CSRF mismatch.'], 403);
    }

    $action  = $_POST['action'] ?? '';
    $farm_id = (int)($_POST['farm_id'] ?? 0);
    $uid     = (int)$_SESSION['user_id'];

    if ($action === 'set_status' && $farm_id > 0) {
        $status = in_array($_POST['status'] ?? '', ['active','suspended','trial'], true)
                  ? $_POST['status'] : 'active';
        $db->prepare("UPDATE farms SET status=? WHERE id=?")->execute([$status, $farm_id]);
        auditLog($uid, 'FARM_STATUS_CHANGE', 'farms', $farm_id, null, ['status' => $status]);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'set_plan' && $farm_id > 0) {
        $plan_id = (int)($_POST['plan_id'] ?? 1);
        // Upsert subscription
        $existing = $db->prepare("SELECT id FROM subscriptions WHERE farm_id=? ORDER BY id DESC LIMIT 1");
        $existing->execute([$farm_id]);
        if ($row = $existing->fetch()) {
            $db->prepare("UPDATE subscriptions SET plan_id=?,status='active',start_date=CURDATE(),end_date=NULL WHERE id=?")
               ->execute([$plan_id, $row['id']]);
        } else {
            $db->prepare("INSERT INTO subscriptions (farm_id,plan_id,start_date,status) VALUES (?,?,CURDATE(),'active')")
               ->execute([$farm_id, $plan_id]);
        }
        auditLog($uid, 'PLAN_CHANGE', 'farms', $farm_id, null, ['plan_id' => $plan_id]);
        jsonResponse(['ok' => true]);
    }

    redirect('/modules/super_admin/index.php');
}

// ── Filters ────────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 20;

$where  = ['1=1'];
$params = [];

if ($filter_status !== '' && in_array($filter_status, ['active','suspended','trial'], true)) {
    $where[]  = 'f.status = ?';
    $params[] = $filter_status;
}
if ($search !== '') {
    $where[]  = '(f.farm_name LIKE ? OR f.farm_code LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$where_sql = implode(' AND ', $where);

// Count
$cnt_stmt = $db->prepare("SELECT COUNT(*) FROM farms f WHERE {$where_sql}");
$cnt_stmt->execute($params);
$total = (int)$cnt_stmt->fetchColumn();
$pager = paginate($total, $per_page, $page);

// Farms list
$list_sql = "
    SELECT f.id, f.farm_name, f.farm_code, f.status, f.location, f.created_at,
           u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
           p.name AS plan_name,
           (SELECT COUNT(*) FROM users uu WHERE uu.farm_id=f.id AND uu.status='active') AS user_count,
           (SELECT COUNT(*) FROM cows  cc WHERE cc.farm_id=f.id AND cc.status NOT IN ('sold','deceased','archived')) AS cow_count
    FROM farms f
    LEFT JOIN users u ON u.id = f.owner_user_id
    LEFT JOIN subscriptions s ON s.farm_id = f.id AND s.status IN ('active','trial')
    LEFT JOIN plans p ON p.id = s.plan_id
    WHERE {$where_sql}
    GROUP BY f.id
    ORDER BY f.created_at DESC
    LIMIT ? OFFSET ?
";
$list_params = array_merge($params, [$per_page, $pager['offset']]);
$list_stmt   = $db->prepare($list_sql);
$list_stmt->execute($list_params);
$farms = $list_stmt->fetchAll();

// Summary stats
$stats = $db->query("
    SELECT
        COUNT(*)                                                        AS total_farms,
        SUM(status='active')                                            AS active_farms,
        SUM(status='suspended')                                         AS suspended_farms,
        (SELECT COUNT(*) FROM cows WHERE status NOT IN ('sold','deceased','archived')) AS total_cows,
        (SELECT COUNT(*) FROM users WHERE status='active')              AS total_users
    FROM farms
")->fetch();

$plans = $db->query("SELECT id, name FROM plans WHERE is_active=1 ORDER BY price_monthly")->fetchAll();

function farm_status_badge(string $s): string {
    return match($s) {
        'active'    => 'badge-green',
        'suspended' => 'badge-red',
        'trial'     => 'badge-orange',
        default     => 'badge-gray',
    };
}

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>All Farms</h2>
        <p class="text-sm text-muted"><?= number_format($stats['total_farms']) ?> registered farms on the platform</p>
    </div>
</div>

<!-- Platform stats -->
<div class="kpi-grid" style="margin-bottom:2rem">
    <div class="kpi-card" style="--kpi-color:#2D6A4F;--kpi-soft:#D8F3DC">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg></div>
        <div class="kpi-value"><?= number_format($stats['total_farms']) ?></div>
        <div class="kpi-label">Total Farms</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#059669;--kpi-soft:#F0FDF4">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div class="kpi-value"><?= number_format($stats['active_farms']) ?></div>
        <div class="kpi-label">Active Farms</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
        <div class="kpi-value"><?= number_format($stats['suspended_farms']) ?></div>
        <div class="kpi-label">Suspended</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#2D6A4F;--kpi-soft:#D8F3DC">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="14" rx="8" ry="6"/><circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/></svg></div>
        <div class="kpi-value"><?= number_format($stats['total_cows']) ?></div>
        <div class="kpi-label">Total Cows (Platform)</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></div>
        <div class="kpi-value"><?= number_format($stats['total_users']) ?></div>
        <div class="kpi-label">Total Users (Platform)</div>
    </div>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;align-items:center">
    <input type="text" name="search" class="form-control" placeholder="Search farm name or code…"
           value="<?= e($search) ?>" style="max-width:260px">
    <select name="status" class="form-control" style="max-width:160px">
        <option value="">All Status</option>
        <option value="active"    <?= $filter_status==='active'?'selected':''    ?>>Active</option>
        <option value="trial"     <?= $filter_status==='trial'?'selected':''     ?>>Trial</option>
        <option value="suspended" <?= $filter_status==='suspended'?'selected':'' ?>>Suspended</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($search!==''||$filter_status!==''): ?>
    <a href="/modules/super_admin/index.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!-- Farms table -->
<div class="card">
    <?php if (empty($farms)): ?>
    <div class="empty-state">
        <h3>No farms found</h3>
        <p>No farms match the current filters.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Farm</th>
                <th>Code</th>
                <th>Owner</th>
                <th>Plan</th>
                <th>Cows</th>
                <th>Users</th>
                <th>Status</th>
                <th>Joined</th>
                <th style="width:160px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($farms as $f): ?>
        <tr id="farm-row-<?= $f['id'] ?>">
            <td>
                <a href="/modules/super_admin/farm_detail.php?id=<?= $f['id'] ?>"
                   style="font-weight:600;color:var(--primary)"><?= e($f['farm_name']) ?></a>
                <?php if ($f['location']): ?>
                <div class="text-xs text-muted"><?= e($f['location']) ?></div>
                <?php endif; ?>
            </td>
            <td><code style="font-size:.82rem;background:var(--bg-base);padding:.15rem .4rem;border-radius:4px"><?= e($f['farm_code']) ?></code></td>
            <td>
                <?php if ($f['owner_name']): ?>
                <div style="font-weight:500"><?= e($f['owner_name']) ?></div>
                <div class="text-xs text-muted"><?= e($f['owner_phone'] ?? $f['owner_email'] ?? '—') ?></div>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td>
                <select class="form-control" style="font-size:.8rem;padding:.25rem .5rem;min-width:110px"
                        onchange="setPlan(<?= $f['id'] ?>, this.value)">
                    <?php foreach ($plans as $pl): ?>
                    <option value="<?= $pl['id'] ?>" <?= ($f['plan_name']===$pl['name'])?'selected':'' ?>><?= e($pl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td style="font-weight:600"><?= number_format($f['cow_count']) ?></td>
            <td><?= number_format($f['user_count']) ?></td>
            <td>
                <span class="badge <?= farm_status_badge($f['status']) ?>">
                    <?= ucfirst($f['status']) ?>
                </span>
            </td>
            <td class="text-muted text-xs"><?= e(formatDate($f['created_at'])) ?></td>
            <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <a href="/modules/super_admin/farm_detail.php?id=<?= $f['id'] ?>"
                       class="btn btn-sm btn-secondary" title="View detail">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                    <?php if ($f['status'] !== 'suspended'): ?>
                    <button type="button" class="btn btn-sm btn-danger" title="Suspend"
                            onclick="setStatus(<?= $f['id'] ?>, 'suspended')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-sm btn-secondary" title="Activate"
                            onclick="setStatus(<?= $f['id'] ?>, 'active')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination" style="margin-top:1rem">
    <?php if ($pager['has_prev']): ?>
    <a href="?page=<?= $pager['current_page']-1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filter_status) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p=max(1,$pager['current_page']-2); $p<=min($pager['total_pages'],$pager['current_page']+2); $p++): ?>
    <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filter_status) ?>"
       class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="?page=<?= $pager['current_page']+1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filter_status) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
$csrf_val  = generateCsrfToken();
$csrf_name = CSRF_TOKEN_NAME;
?>
<script>
var CSRF_NAME  = '<?= e($csrf_name) ?>';
var CSRF_VALUE = '<?= e($csrf_val) ?>';

function apiPost(data, cb) {
    var fd = new FormData();
    fd.append(CSRF_NAME, CSRF_VALUE);
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    fetch('/modules/super_admin/index.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(cb)
        .catch(function(){ alert('Request failed.'); });
}

function setStatus(farmId, status) {
    var label = status === 'suspended' ? 'suspend' : 'activate';
    if (!confirm('Are you sure you want to ' + label + ' this farm?')) return;
    apiPost({action:'set_status', farm_id:farmId, status:status}, function(d){
        if (d.ok) location.reload();
        else alert(d.error || 'Error');
    });
}

function setPlan(farmId, planId) {
    apiPost({action:'set_plan', farm_id:farmId, plan_id:planId}, function(d){
        if (!d.ok) alert(d.error || 'Error changing plan');
    });
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
