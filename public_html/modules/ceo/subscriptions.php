<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['superadmin']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Duration options ──────────────────────────────────────────────────────────
const DURATIONS = [
    '1_month'  => ['label' => '1 Month Free',   'months' => 1],
    '3_months' => ['label' => '3 Months Free',  'months' => 3],
    '6_months' => ['label' => '6 Months Free',  'months' => 6],
    '8_months' => ['label' => '8 Months Free',  'months' => 8],
    '1_year'   => ['label' => '1 Year Free',    'months' => 12],
    'lifetime' => ['label' => 'Lifetime Free',  'months' => null],
];

// ── POST: grant / change plan ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.'); redirect('/modules/ceo/subscriptions.php');
    }

    $action  = $_POST['action'] ?? '';
    $farm_id = (int)($_POST['farm_id'] ?? 0);

    if ($action === 'grant' && $farm_id > 0) {
        $plan_id  = (int)($_POST['plan_id']  ?? 1);
        $duration = sanitize($_POST['duration'] ?? '');
        $notes    = sanitize($_POST['notes']   ?? '');

        if (!array_key_exists($duration, DURATIONS)) {
            flashMessage('error', 'Invalid duration.');
            redirect('/modules/ceo/subscriptions.php');
        }

        // Fetch farm + current sub info
        $farm_stmt = $db->prepare(
            "SELECT f.id, f.farm_name,
                    u.name AS owner_name,
                    s.id AS sub_id, s.plan_id AS old_plan_id, s.end_date AS old_end,
                    s.is_lifetime AS was_lifetime,
                    p.name AS old_plan_name
             FROM farms f
             LEFT JOIN users u ON u.farm_id = f.id AND u.is_owner = 1
             LEFT JOIN subscriptions s ON s.farm_id = f.id
             LEFT JOIN plans p ON p.id = s.plan_id
             WHERE f.id = ? ORDER BY s.id DESC LIMIT 1"
        );
        $farm_stmt->execute([$farm_id]);
        $farm = $farm_stmt->fetch();

        if (!$farm) {
            flashMessage('error', 'Farm not found.');
            redirect('/modules/ceo/subscriptions.php');
        }

        $new_plan_stmt = $db->prepare("SELECT name FROM plans WHERE id = ?");
        $new_plan_stmt->execute([$plan_id]);
        $new_plan_name = $new_plan_stmt->fetchColumn() ?: 'Unknown';

        $is_lifetime = ($duration === 'lifetime') ? 1 : 0;
        $end_date    = null;
        if (!$is_lifetime) {
            $months   = DURATIONS[$duration]['months'];
            $end_date = date('Y-m-d', strtotime("+{$months} months"));
        }

        $action_type = $is_lifetime ? 'lifetime' : 'free_access';

        $db->beginTransaction();
        try {
            if ($farm['sub_id']) {
                $db->prepare(
                    "UPDATE subscriptions
                     SET plan_id=?, start_date=CURDATE(), end_date=?, status='active',
                         grace_end_date=NULL, is_lifetime=?
                     WHERE id=?"
                )->execute([$plan_id, $end_date, $is_lifetime, $farm['sub_id']]);
            } else {
                $db->prepare(
                    "INSERT INTO subscriptions (farm_id,plan_id,start_date,end_date,status,is_lifetime)
                     VALUES (?,?,CURDATE(),?,'active',?)"
                )->execute([$farm_id, $plan_id, $end_date, $is_lifetime]);
            }

            // Reset farm status to active if suspended/expired
            $db->prepare("UPDATE farms SET status='active' WHERE id=?")->execute([$farm_id]);

            // CEO grant audit log
            $db->prepare(
                "INSERT INTO ceo_grants
                 (farm_id,farm_name,owner_name,granted_by,action_type,
                  old_plan_id,new_plan_id,old_plan_name,new_plan_name,
                  duration_label,old_end_date,new_end_date,is_lifetime,notes)
                 VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?,?)"
            )->execute([
                $farm_id, $farm['farm_name'], $farm['owner_name'], $uid, $action_type,
                $farm['old_plan_id'], $plan_id, $farm['old_plan_name'], $new_plan_name,
                DURATIONS[$duration]['label'], $farm['old_end'], $end_date, $is_lifetime, $notes ?: null,
            ]);

            auditLog($uid, 'CEO_GRANT_SUBSCRIPTION', 'subscriptions', $farm_id, [
                'plan' => $farm['old_plan_name'], 'end_date' => $farm['old_end'],
            ], [
                'plan' => $new_plan_name, 'duration' => DURATIONS[$duration]['label'], 'end_date' => $end_date,
            ]);

            $db->commit();

            // Clear subscription engine cache so changes take effect immediately
            unset($GLOBALS['_sub_engine_cache']);

            flashMessage('success',
                'Granted <strong>' . e(DURATIONS[$duration]['label']) . '</strong> — ' .
                e($new_plan_name) . ' Plan to <strong>' . e($farm['farm_name']) . '</strong>.'
            );
        } catch (\Throwable $ex) {
            $db->rollBack();
            error_log('[CEO_GRANT] ' . $ex->getMessage());
            flashMessage('error', 'Database error. Please try again.');
        }
    } elseif ($action === 'suspend' && $farm_id > 0) {
        $notes = sanitize($_POST['notes'] ?? '');
        $farm_q = $db->prepare("SELECT name FROM farms WHERE id=?"); $farm_q->execute([$farm_id]);
        $fname  = $farm_q->fetchColumn() ?: '';
        $db->prepare("UPDATE farms SET status='suspended' WHERE id=?")->execute([$farm_id]);
        $db->prepare("UPDATE subscriptions SET status='suspended' WHERE farm_id=? ORDER BY id DESC LIMIT 1")->execute([$farm_id]);
        $db->prepare("INSERT INTO ceo_grants (farm_id,farm_name,granted_by,action_type,new_plan_id,notes) VALUES (?,?,?,'suspend',1,?)")->execute([$farm_id,$fname,$uid,$notes?:null]);
        auditLog($uid,'CEO_SUSPEND_FARM','farms',$farm_id,null,['reason'=>$notes]);
        flashMessage('success', "Farm <strong>" . e($fname) . "</strong> suspended.");
    } elseif ($action === 'reactivate' && $farm_id > 0) {
        $notes = sanitize($_POST['notes'] ?? '');
        $farm_q = $db->prepare("SELECT name FROM farms WHERE id=?"); $farm_q->execute([$farm_id]);
        $fname  = $farm_q->fetchColumn() ?: '';
        $db->prepare("UPDATE farms SET status='active' WHERE id=?")->execute([$farm_id]);
        $db->prepare("UPDATE subscriptions SET status='active' WHERE farm_id=? ORDER BY id DESC LIMIT 1")->execute([$farm_id]);
        $db->prepare("INSERT INTO ceo_grants (farm_id,farm_name,granted_by,action_type,new_plan_id,notes) VALUES (?,?,?,'reactivate',1,?)")->execute([$farm_id,$fname,$uid,$notes?:null]);
        auditLog($uid,'CEO_REACTIVATE_FARM','farms',$farm_id,null,['reason'=>$notes]);
        flashMessage('success', "Farm <strong>" . e($fname) . "</strong> reactivated.");
    }

    redirect('/modules/ceo/subscriptions.php?' . http_build_query(array_filter(['search' => $_POST['f_search'] ?? '', 'status' => $_POST['f_status'] ?? ''])));
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search  = trim($_GET['search'] ?? '');
$f_stat  = in_array($_GET['status'] ?? '', ['active','expired','suspended','trial','lifetime'], true) ? $_GET['status'] : '';
$f_plan  = (int)($_GET['plan'] ?? 0);
$page    = max(1,(int)($_GET['page'] ?? 1));
$per     = 25;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(f.farm_name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $like     = "%{$search}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($f_stat === 'lifetime') {
    $where[] = 's.is_lifetime = 1';
} elseif ($f_stat !== '') {
    $where[] = '(f.status = ? OR s.status = ?)';
    $params[] = $f_stat; $params[] = $f_stat;
}
if ($f_plan > 0) {
    $where[] = 's.plan_id = ?'; $params[] = $f_plan;
}

$where_sql = implode(' AND ', $where);

$count_stmt = $db->prepare("SELECT COUNT(*) FROM farms f LEFT JOIN users u ON u.farm_id=f.id AND u.is_owner=1 LEFT JOIN subscriptions s ON s.farm_id=f.id WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$params_page = array_merge($params, [$per, ($page-1)*$per]);
$farms_stmt  = $db->prepare(
    "SELECT f.id AS farm_id, f.farm_name, f.status AS farm_status,
            u.name AS owner_name, u.email AS owner_email,
            s.id AS sub_id, s.status AS sub_status, s.start_date, s.end_date,
            s.is_lifetime, p.id AS plan_id, p.name AS plan_name
     FROM farms f
     LEFT JOIN users u ON u.farm_id=f.id AND u.is_owner=1
     LEFT JOIN subscriptions s ON s.farm_id=f.id
     LEFT JOIN plans p ON p.id=s.plan_id
     WHERE {$where_sql}
     ORDER BY f.id DESC LIMIT ? OFFSET ?"
);
$farms_stmt->execute($params_page);
$farms = $farms_stmt->fetchAll();

// Plans for dropdowns
$all_plans = $db->query("SELECT id,name FROM plans WHERE is_active=1 ORDER BY price_monthly")->fetchAll();

$page_title = 'Subscription Manager';
$active_nav = 'ceo_sub_mgr';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Subscription Manager</h2>
        <p class="text-muted text-sm">Grant, upgrade, downgrade, or revoke farm subscriptions</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/ceo/index.php" class="btn btn-secondary btn-sm">Control Center</a>
        <a href="/modules/ceo/audit.php" class="btn btn-secondary btn-sm">Audit Log</a>
    </div>
</div>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem">
    <div class="form-group" style="margin:0;flex:1;min-width:200px">
        <label class="form-label">Search Farm / Owner / Email</label>
        <input type="text" name="search" class="form-control form-control-sm" value="<?= e($search) ?>" placeholder="Search…">
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label">Status</label>
        <select name="status" class="form-control form-control-sm">
            <option value="">All Status</option>
            <option value="active"    <?= $f_stat==='active'   ?'selected':'' ?>>Active</option>
            <option value="trial"     <?= $f_stat==='trial'    ?'selected':'' ?>>Trial</option>
            <option value="expired"   <?= $f_stat==='expired'  ?'selected':'' ?>>Expired</option>
            <option value="suspended" <?= $f_stat==='suspended'?'selected':'' ?>>Suspended</option>
            <option value="lifetime"  <?= $f_stat==='lifetime' ?'selected':'' ?>>Lifetime</option>
        </select>
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label">Plan</label>
        <select name="plan" class="form-control form-control-sm">
            <option value="0">All Plans</option>
            <?php foreach ($all_plans as $pl): ?>
            <option value="<?= $pl['id'] ?>" <?= $f_plan==$pl['id']?'selected':'' ?>><?= e($pl['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="/modules/ceo/subscriptions.php" class="btn btn-secondary btn-sm">Clear</a>
</form>

<!-- ── Stats strip ─────────────────────────────────────────────────────────── -->
<?php
$stats = $db->query("SELECT
    COUNT(f.id) AS total,
    SUM(CASE WHEN f.status='active' AND (s.status='active' OR s.is_lifetime=1) THEN 1 ELSE 0 END) AS active,
    SUM(CASE WHEN s.is_lifetime=1 THEN 1 ELSE 0 END) AS lifetime,
    SUM(CASE WHEN f.status='suspended' OR s.status='suspended' THEN 1 ELSE 0 END) AS suspended,
    SUM(CASE WHEN s.status='expired' THEN 1 ELSE 0 END) AS expired
FROM farms f LEFT JOIN subscriptions s ON s.farm_id=f.id")->fetch();
?>
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem">
    <?php foreach ([
        ['Total Farms',    $stats['total'],    '#0284c7'],
        ['Active',         $stats['active'],   '#16a34a'],
        ['Lifetime',       $stats['lifetime'], '#7c3aed'],
        ['Suspended',      $stats['suspended'],'#dc2626'],
        ['Expired',        $stats['expired'],  '#d97706'],
    ] as [$lbl,$val,$col]): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-left:3px solid <?= $col ?>;border-radius:8px;padding:.5rem .9rem;min-width:110px">
        <div style="font-size:.73rem;color:var(--text-secondary);font-weight:600;text-transform:uppercase"><?= $lbl ?></div>
        <div style="font-size:1.35rem;font-weight:800;color:<?= $col ?>"><?= $val ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Farms Table ──────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h3><?= number_format($total) ?> Farm<?= $total!==1?'s':'' ?></h3>
    </div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.83rem">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Farm</th>
                    <th>Owner</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Expires</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($farms)): ?>
            <tr><td colspan="7" class="text-center text-muted">No farms found.</td></tr>
            <?php endif; ?>
            <?php foreach ($farms as $f):
                $is_suspended = ($f['farm_status']==='suspended' || $f['sub_status']==='suspended');
                $is_expired   = ($f['sub_status']==='expired');
                $is_lifetime  = (bool)$f['is_lifetime'];
                $row_color    = $is_suspended ? '#fef2f2' : ($is_expired ? '#fffbeb' : '');
            ?>
            <tr style="background:<?= $row_color ?>">
                <td class="text-muted"><?= $f['farm_id'] ?></td>
                <td>
                    <strong><?= e($f['farm_name']) ?></strong>
                    <?php if ($is_lifetime): ?>
                    <span class="badge" style="background:linear-gradient(135deg,#7c3aed,#db2777);color:#fff;font-size:.68rem;margin-left:.3rem">♾ LIFETIME</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($f['owner_name'] ?: '—') ?>
                    <?php if ($f['owner_email']): ?><div class="text-xs text-muted"><?= e($f['owner_email']) ?></div><?php endif; ?>
                </td>
                <td>
                    <?php if ($f['plan_name']): ?>
                    <span class="badge" style="background:<?= match($f['plan_name']){
                        'Free'=>'#6b7280','Basic'=>'#0284c7','Pro'=>'#7c3aed','Enterprise'=>'#d97706',default=>'#6b7280'} ?>;color:#fff"><?= e($f['plan_name']) ?></span>
                    <?php else: ?>
                    <span class="text-muted">None</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $badge_map = ['active'=>'badge-green','trial'=>'badge-blue','grace'=>'badge-yellow','expired'=>'badge-red','suspended'=>'badge-red','cancelled'=>'badge-gray'];
                    $disp_stat = $f['farm_status']==='suspended' ? 'suspended' : ($f['sub_status'] ?: 'no-sub');
                    ?>
                    <span class="badge <?= $badge_map[$disp_stat] ?? 'badge-gray' ?>"><?= ucfirst($disp_stat) ?></span>
                </td>
                <td>
                    <?php if ($is_lifetime): ?>
                    <span style="color:#7c3aed;font-weight:700">♾ Lifetime</span>
                    <?php elseif ($f['end_date']): ?>
                    <?= e(date('d M Y', strtotime($f['end_date']))) ?>
                    <?php if ($f['end_date'] < date('Y-m-d')): ?>
                    <span class="badge badge-red" style="font-size:.68rem">Expired</span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <button class="btn btn-xs btn-primary"
                                onclick="openGrant(<?= $f['farm_id'] ?>, '<?= e(addslashes($f['farm_name'])) ?>', '<?= e(addslashes($f['plan_name']??'Free')) ?>', <?= $f['plan_id']??1 ?>)">
                            Grant
                        </button>
                        <?php if ($is_suspended): ?>
                        <button class="btn btn-xs btn-success" onclick="openReactivate(<?= $f['farm_id'] ?>, '<?= e(addslashes($f['farm_name'])) ?>')">Reactivate</button>
                        <?php else: ?>
                        <button class="btn btn-xs btn-danger" onclick="openSuspend(<?= $f['farm_id'] ?>, '<?= e(addslashes($f['farm_name'])) ?>')">Suspend</button>
                        <?php endif; ?>
                        <a href="/modules/super_admin/farm_detail.php?id=<?= $f['farm_id'] ?>" class="btn btn-xs btn-secondary">View</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:.4rem;justify-content:center;padding:1rem;flex-wrap:wrap">
        <?php for ($pg=1;$pg<=$pages;$pg++): ?>
        <a href="?<?= http_build_query(array_merge(['search'=>$search,'status'=>$f_stat,'plan'=>$f_plan],['page'=>$pg])) ?>"
           class="btn btn-xs <?= $pg===$page?'btn-primary':'btn-secondary' ?>"><?= $pg ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     GRANT MODAL
══════════════════════════════════════════════════════════════════════════════ -->
<div id="grantOverlay" onclick="closeModal('grantOverlay')" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;padding:1rem;overflow:auto">
    <div onclick="event.stopPropagation()" style="background:var(--surface);border-radius:12px;max-width:540px;margin:3rem auto;padding:1.75rem;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem">
            <div>
                <h3 style="margin:0">Grant Subscription</h3>
                <p class="text-muted text-sm" id="grantFarmLabel" style="margin:.2rem 0 0"></p>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="closeModal('grantOverlay')">✕</button>
        </div>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"    value="grant">
            <input type="hidden" name="farm_id"   id="grantFarmId">
            <input type="hidden" name="f_search"  value="<?= e($search) ?>">
            <input type="hidden" name="f_status"  value="<?= e($f_stat) ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                <div class="form-group" style="margin:0">
                    <label class="form-label">Plan <span style="color:var(--danger)">*</span></label>
                    <select name="plan_id" id="grantPlanId" class="form-control">
                        <?php foreach ($all_plans as $pl): ?>
                        <option value="<?= $pl['id'] ?>"><?= e($pl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-muted" id="grantCurrentPlan" style="margin:.3rem 0 0"></p>
                </div>

                <div class="form-group" style="margin:0">
                    <label class="form-label">Duration <span style="color:var(--danger)">*</span></label>
                    <select name="duration" id="grantDuration" class="form-control" onchange="checkLifetime()">
                        <?php foreach (DURATIONS as $key => $d): ?>
                        <option value="<?= $key ?>" <?= $key==='lifetime'?'style="font-weight:700;color:#7c3aed"':'' ?>><?= e($d['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="lifetimeWarning" style="display:none;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:8px;padding:.75rem;margin-bottom:1rem">
                <strong style="color:#7c3aed">♾ Lifetime Grant</strong>
                <p class="text-sm" style="margin:.25rem 0 0;color:#5b21b6">This user will have permanent access. Only the CEO can assign or revoke lifetime plans.</p>
            </div>

            <div class="form-group">
                <label class="form-label">Notes / Reason</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Promotional offer, partner deal…"></textarea>
            </div>

            <div style="display:flex;gap:.75rem;margin-top:.5rem">
                <button type="submit" class="btn btn-primary" id="grantBtn" style="flex:1">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Confirm Grant
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('grantOverlay')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Suspend Modal -->
<div id="suspendOverlay" onclick="closeModal('suspendOverlay')" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;padding:1rem;overflow:auto">
    <div onclick="event.stopPropagation()" style="background:var(--surface);border-radius:12px;max-width:440px;margin:5rem auto;padding:1.75rem;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <h3 style="margin:0 0 .25rem;color:#b91c1c">Suspend Farm</h3>
        <p class="text-muted text-sm" id="suspendFarmLabel"></p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"   value="suspend">
            <input type="hidden" name="farm_id"  id="suspendFarmId">
            <input type="hidden" name="f_search" value="<?= e($search) ?>">
            <div class="form-group"><label class="form-label">Reason</label><textarea name="notes" class="form-control" rows="2" placeholder="Reason for suspension…"></textarea></div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-danger" style="flex:1">Suspend Farm</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('suspendOverlay')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Reactivate Modal -->
<div id="reactivateOverlay" onclick="closeModal('reactivateOverlay')" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;padding:1rem;overflow:auto">
    <div onclick="event.stopPropagation()" style="background:var(--surface);border-radius:12px;max-width:440px;margin:5rem auto;padding:1.75rem;box-shadow:0 20px 60px rgba(0,0,0,.3)">
        <h3 style="margin:0 0 .25rem;color:#15803d">Reactivate Farm</h3>
        <p class="text-muted text-sm" id="reactivateFarmLabel"></p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"   value="reactivate">
            <input type="hidden" name="farm_id"  id="reactivateFarmId">
            <input type="hidden" name="f_search" value="<?= e($search) ?>">
            <div class="form-group"><label class="form-label">Notes (optional)</label><textarea name="notes" class="form-control" rows="2" placeholder="Reason for reactivation…"></textarea></div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-success" style="flex:1">Reactivate Farm</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('reactivateOverlay')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).style.display='none'; }

function openGrant(farmId, farmName, curPlan, curPlanId) {
    document.getElementById('grantFarmId').value    = farmId;
    document.getElementById('grantFarmLabel').textContent = 'Farm: ' + farmName;
    document.getElementById('grantCurrentPlan').textContent = 'Current plan: ' + curPlan;
    const sel = document.getElementById('grantPlanId');
    for (let i=0;i<sel.options.length;i++) { if (sel.options[i].value == curPlanId) { sel.selectedIndex=i; break; } }
    checkLifetime();
    document.getElementById('grantOverlay').style.display='block';
}

function checkLifetime() {
    const dur = document.getElementById('grantDuration').value;
    const warn= document.getElementById('lifetimeWarning');
    const btn = document.getElementById('grantBtn');
    if (dur === 'lifetime') {
        warn.style.display='block';
        btn.style.background='linear-gradient(135deg,#7c3aed,#db2777)';
        btn.style.borderColor='#7c3aed';
    } else {
        warn.style.display='none';
        btn.style.background=''; btn.style.borderColor='';
    }
}

function openSuspend(farmId, farmName) {
    document.getElementById('suspendFarmId').value    = farmId;
    document.getElementById('suspendFarmLabel').textContent = 'Farm: ' + farmName;
    document.getElementById('suspendOverlay').style.display='block';
}
function openReactivate(farmId, farmName) {
    document.getElementById('reactivateFarmId').value    = farmId;
    document.getElementById('reactivateFarmLabel').textContent = 'Farm: ' + farmName;
    document.getElementById('reactivateOverlay').style.display='block';
}
document.addEventListener('keydown', e => { if (e.key==='Escape') { ['grantOverlay','suspendOverlay','reactivateOverlay'].forEach(closeModal); } });
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
