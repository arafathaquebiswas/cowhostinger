<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['superadmin']);

$db = getDB();

// ── Filters ───────────────────────────────────────────────────────────────────
$search     = trim($_GET['search']      ?? '');
$f_action   = sanitize($_GET['action_type'] ?? '');
$f_from     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$f_to       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : '';
$page       = max(1,(int)($_GET['page'] ?? 1));
$per        = 30;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $like     = "%{$search}%";
    $where[]  = '(cg.farm_name LIKE ? OR cg.owner_name LIKE ?)';
    $params[] = $like; $params[] = $like;
}
if ($f_action !== '') {
    $valid_actions = ['free_access','plan_change','lifetime','extend','suspend','reactivate','reset'];
    if (in_array($f_action, $valid_actions, true)) {
        $where[] = 'cg.action_type = ?'; $params[] = $f_action;
    }
}
if ($f_from !== '') { $where[] = 'DATE(cg.created_at) >= ?'; $params[] = $f_from; }
if ($f_to   !== '') { $where[] = 'DATE(cg.created_at) <= ?'; $params[] = $f_to;   }

$where_sql = implode(' AND ', $where);

$total_stmt = $db->prepare("SELECT COUNT(*) FROM ceo_grants cg WHERE {$where_sql}");
$total_stmt->execute($params);
$total = (int)$total_stmt->fetchColumn();
$pages = max(1,(int)ceil($total/$per));
$page  = min($page,$pages);

$rows_stmt = $db->prepare(
    "SELECT cg.*, u.name AS ceo_name
     FROM ceo_grants cg
     LEFT JOIN users u ON u.id = cg.granted_by
     WHERE {$where_sql}
     ORDER BY cg.created_at DESC
     LIMIT ? OFFSET ?"
);
$rows_stmt->execute(array_merge($params, [$per, ($page-1)*$per]));
$rows = $rows_stmt->fetchAll();

// ── Summary stats ─────────────────────────────────────────────────────────────
$stats = $db->query("
    SELECT
        COUNT(*) AS total_actions,
        SUM(CASE WHEN is_lifetime=1 THEN 1 ELSE 0 END) AS lifetime_grants,
        SUM(CASE WHEN action_type='suspend' THEN 1 ELSE 0 END) AS suspensions,
        SUM(CASE WHEN action_type='reactivate' THEN 1 ELSE 0 END) AS reactivations,
        SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) AS today
    FROM ceo_grants
")->fetch();

$page_title = 'CEO Audit Log';
$active_nav = 'ceo_audit';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

$action_colors = [
    'free_access' => ['bg'=>'#dcfce7','color'=>'#15803d','label'=>'Free Access'],
    'plan_change' => ['bg'=>'#dbeafe','color'=>'#1d4ed8','label'=>'Plan Change'],
    'lifetime'    => ['bg'=>'#f5f3ff','color'=>'#7c3aed','label'=>'Lifetime'],
    'extend'      => ['bg'=>'#fef3c7','color'=>'#b45309','label'=>'Extend'],
    'suspend'     => ['bg'=>'#fee2e2','color'=>'#b91c1c','label'=>'Suspend'],
    'reactivate'  => ['bg'=>'#fef9c3','color'=>'#854d0e','label'=>'Reactivate'],
    'reset'       => ['bg'=>'#f1f5f9','color'=>'#475569','label'=>'Reset'],
];
?>

<div class="page-header">
    <div>
        <h2>CEO Audit Log</h2>
        <p class="text-muted text-sm">Every CEO subscription action is permanently recorded here</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/ceo/index.php"           class="btn btn-secondary btn-sm">Control Center</a>
        <a href="/modules/ceo/subscriptions.php"   class="btn btn-primary btn-sm">Subscription Manager</a>
    </div>
</div>

<!-- ── Summary KPIs ─────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem">
    <?php foreach ([
        ['Total Actions',      $stats['total_actions'],    '#0284c7'],
        ['Lifetime Grants',    $stats['lifetime_grants'],  '#7c3aed'],
        ['Suspensions',        $stats['suspensions'],      '#dc2626'],
        ['Reactivations',      $stats['reactivations'],    '#16a34a'],
        ["Today's Actions",    $stats['today'],            '#d97706'],
    ] as [$lbl,$val,$col]): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-left:3px solid <?= $col ?>;border-radius:8px;padding:.5rem .9rem;min-width:120px">
        <div style="font-size:.72rem;color:var(--text-secondary);font-weight:600;text-transform:uppercase"><?= $lbl ?></div>
        <div style="font-size:1.35rem;font-weight:800;color:<?= $col ?>"><?= number_format($val) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem">
    <div class="form-group" style="margin:0;flex:1;min-width:180px">
        <label class="form-label">Search Farm / Owner</label>
        <input type="text" name="search" class="form-control form-control-sm" value="<?= e($search) ?>" placeholder="Search…">
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label">Action Type</label>
        <select name="action_type" class="form-control form-control-sm">
            <option value="">All Actions</option>
            <?php foreach ($action_colors as $key=>$ac): ?>
            <option value="<?= $key ?>" <?= $f_action===$key?'selected':'' ?>><?= $ac['label'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label">From Date</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($f_from) ?>">
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label">To Date</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($f_to) ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="/modules/ceo/audit.php" class="btn btn-secondary btn-sm">Clear</a>
</form>

<!-- ── Audit Table ─────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h3><?= number_format($total) ?> Record<?= $total!==1?'s':'' ?></h3>
    </div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Farm</th>
                    <th>Owner</th>
                    <th>Action</th>
                    <th>Old Plan</th>
                    <th>New Plan</th>
                    <th>Duration</th>
                    <th>Expires</th>
                    <th>Granted By</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-center text-muted" style="padding:2rem">No records match your filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $ac = $action_colors[$r['action_type']] ?? ['bg'=>'#f1f5f9','color'=>'#475569','label'=>ucfirst($r['action_type'])];
            ?>
            <tr>
                <td style="white-space:nowrap">
                    <div><?= e(date('d M Y', strtotime($r['created_at']))) ?></div>
                    <div class="text-muted text-xs"><?= e(date('H:i:s', strtotime($r['created_at']))) ?></div>
                </td>
                <td>
                    <strong><?= e($r['farm_name'] ?: 'Farm #'.$r['farm_id']) ?></strong>
                    <div class="text-muted text-xs">ID: <?= $r['farm_id'] ?></div>
                </td>
                <td><?= e($r['owner_name'] ?: '—') ?></td>
                <td>
                    <span style="background:<?= $ac['bg'] ?>;color:<?= $ac['color'] ?>;padding:.2rem .6rem;border-radius:20px;font-size:.75rem;font-weight:700;white-space:nowrap">
                        <?php if ($r['is_lifetime']): ?>♾ <?php endif; ?><?= $ac['label'] ?>
                    </span>
                </td>
                <td style="color:var(--text-secondary)"><?= e($r['old_plan_name'] ?: '—') ?></td>
                <td style="font-weight:600"><?= e($r['new_plan_name'] ?: '—') ?></td>
                <td>
                    <?php if ($r['is_lifetime']): ?>
                    <span style="color:#7c3aed;font-weight:700">♾ Lifetime</span>
                    <?php else: ?>
                    <?= e($r['duration_label'] ?: '—') ?>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <?php if ($r['is_lifetime']): ?>
                    <span style="color:#7c3aed;font-weight:700">Never</span>
                    <?php elseif ($r['new_end_date']): ?>
                    <?= e(date('d M Y', strtotime($r['new_end_date']))) ?>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="font-weight:600;color:#7c3aed">👑 <?= e($r['ceo_name'] ?: 'CEO') ?></span>
                </td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['notes'] ?? '') ?>">
                    <?= e($r['notes'] ?: '—') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:.4rem;justify-content:center;padding:1rem;flex-wrap:wrap">
        <?php
        $qs = http_build_query(array_filter(['search'=>$search,'action_type'=>$f_action,'date_from'=>$f_from,'date_to'=>$f_to]));
        for ($pg=1;$pg<=$pages;$pg++):
        ?>
        <a href="?<?= $qs ?>&page=<?= $pg ?>" class="btn btn-xs <?= $pg===$page?'btn-primary':'btn-secondary' ?>"><?= $pg ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Audit record details (click to expand, read-only) ─────────────────── -->
<div class="card" style="margin-top:1.25rem;background:#fffbeb;border-color:#fde68a">
    <div class="card-body" style="padding:.85rem 1.1rem">
        <div style="font-size:.82rem;color:#92400e">
            <strong>📜 Audit Integrity</strong> — All records in this log are append-only and cannot be edited or deleted by anyone, including the CEO.
            Every action is cross-referenced with the system <code>audit_log</code> table for double verification.
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
