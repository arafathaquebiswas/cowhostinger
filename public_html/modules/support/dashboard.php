<?php
require_once dirname(__DIR__, 2) . '/includes/saas_guard.php';
requireAuth();
requireRole(['support_staff', 'superadmin']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

logActivity('support.dashboard.viewed');

// ── KPIs ─────────────────────────────────────────────────────────────────────
$kpi = $db->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status='open')        AS open_count,
        SUM(status='in_progress') AS in_progress,
        SUM(status='resolved')    AS resolved,
        SUM(priority='critical')  AS critical,
        SUM(DATE(created_at)=CURDATE()) AS today
     FROM support_tickets"
)->fetch();

// My assigned tickets
$my_tickets = $db->prepare(
    "SELECT t.*, f.farm_name,
            (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id=t.id AND m.is_internal=0) AS reply_count
     FROM support_tickets t
     JOIN farms f ON f.id = t.farm_id
     WHERE t.assigned_to = ? AND t.status IN ('open','in_progress')
     ORDER BY FIELD(t.priority,'critical','high','medium','low'), t.updated_at DESC
     LIMIT 20"
);
$my_tickets->execute([$uid]);
$assigned = $my_tickets->fetchAll();

// Recent unassigned tickets
$unassigned_stmt = $db->query(
    "SELECT t.*, f.farm_name
     FROM support_tickets t
     JOIN farms f ON f.id = t.farm_id
     WHERE t.assigned_to IS NULL AND t.status IN ('open','in_progress')
     ORDER BY FIELD(t.priority,'critical','high','medium','low'), t.created_at ASC
     LIMIT 10"
);
$unassigned = $unassigned_stmt->fetchAll();

// Farm search
$search_results = [];
$farm_q = sanitize($_GET['q'] ?? '');
if (strlen($farm_q) >= 2) {
    $sq = $db->prepare(
        "SELECT f.id, f.farm_name, f.farm_code, f.status,
                s.status AS sub_status, p.name AS plan_name,
                COUNT(DISTINCT t.id) AS open_tickets
         FROM farms f
         LEFT JOIN subscriptions s ON s.farm_id=f.id
         LEFT JOIN plans p ON p.id=s.plan_id
         LEFT JOIN support_tickets t ON t.farm_id=f.id AND t.status IN ('open','in_progress')
         WHERE f.farm_name LIKE ? OR f.farm_code LIKE ?
         GROUP BY f.id
         ORDER BY f.farm_name ASC LIMIT 15"
    );
    $sq->execute(["%{$farm_q}%", "%{$farm_q}%"]);
    $search_results = $sq->fetchAll();
    logActivity('farm.searched', ['query' => $farm_q]);
}

// Recent activity
$activity = getActivityLog(['limit' => 15]);

$page_title = 'Support Dashboard';
$active_nav = 'support_dashboard';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Support Dashboard</h2>
        <p class="text-sm text-muted">AB IT Internal — <?= e($_SESSION['user_name'] ?? 'Staff') ?></p>
    </div>
    <a href="/modules/support/index.php" class="btn btn-primary">View All Tickets</a>
</div>

<!-- KPI row -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:1.75rem">
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-value"><?= (int)$kpi['open_count'] ?></div>
        <div class="kpi-label">Open Tickets</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-value"><?= (int)$kpi['in_progress'] ?></div>
        <div class="kpi-label">In Progress</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-value"><?= (int)$kpi['critical'] ?></div>
        <div class="kpi-label">Critical</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#059669;--kpi-soft:#F0FDF4">
        <div class="kpi-value"><?= (int)$kpi['resolved'] ?></div>
        <div class="kpi-label">Resolved</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#0284C7;--kpi-soft:#F0F9FF">
        <div class="kpi-value"><?= (int)$kpi['today'] ?></div>
        <div class="kpi-label">Today</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:1.5rem">

    <div>
        <!-- My assigned tickets -->
        <div class="card" style="margin-bottom:1.5rem">
            <div class="card-header">
                <span class="card-title">My Assigned Tickets</span>
                <span class="text-sm text-muted"><?= count($assigned) ?> active</span>
            </div>
            <?php if (empty($assigned)): ?>
            <div style="padding:1.5rem;text-align:center;color:#9CA3AF;font-size:.85rem">
                No tickets assigned to you.
                <a href="/modules/support/index.php">View all open tickets</a>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="table" style="margin:0">
                <thead><tr><th>#</th><th>Farm</th><th>Subject</th><th>Priority</th><th>Status</th><th>Updated</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($assigned as $t):
                    $pc = ['critical'=>'#DC2626','high'=>'#D97706','medium'=>'#0284C7','low'=>'#6B7280'][$t['priority']] ?? '#6B7280';
                    $sc = ['open'=>'#DC2626','in_progress'=>'#D97706'][$t['status']] ?? '#6B7280';
                ?>
                <tr>
                    <td class="text-xs text-muted">#<?= $t['id'] ?></td>
                    <td class="text-sm"><?= e($t['farm_name']) ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <a href="/modules/support/view.php?id=<?= $t['id'] ?>" style="font-weight:600"><?= e($t['subject']) ?></a>
                    </td>
                    <td><span class="badge" style="background:<?= $pc ?>;color:#fff;font-size:.65rem"><?= ucfirst($t['priority']) ?></span></td>
                    <td><span class="badge" style="background:<?= $sc ?>;color:#fff;font-size:.65rem"><?= ucwords(str_replace('_',' ',$t['status'])) ?></span></td>
                    <td class="text-xs text-muted"><?= e(date('d M', strtotime($t['updated_at']))) ?></td>
                    <td><a href="/modules/support/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-secondary">Open</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Unassigned tickets -->
        <?php if (!empty($unassigned)): ?>
        <div class="card" style="margin-bottom:1.5rem;border:1px solid #FECACA">
            <div class="card-header" style="background:#FEF2F2">
                <span class="card-title" style="color:#DC2626">⚠ Unassigned Tickets (<?= count($unassigned) ?>)</span>
            </div>
            <div style="overflow-x:auto">
            <table class="table" style="margin:0">
                <thead><tr><th>#</th><th>Farm</th><th>Subject</th><th>Priority</th><th>Opened</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($unassigned as $t):
                    $pc = ['critical'=>'#DC2626','high'=>'#D97706','medium'=>'#0284C7','low'=>'#6B7280'][$t['priority']] ?? '#6B7280';
                ?>
                <tr>
                    <td class="text-xs text-muted">#<?= $t['id'] ?></td>
                    <td class="text-sm"><?= e($t['farm_name']) ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <a href="/modules/support/view.php?id=<?= $t['id'] ?>" style="font-weight:600"><?= e($t['subject']) ?></a>
                    </td>
                    <td><span class="badge" style="background:<?= $pc ?>;color:#fff;font-size:.65rem"><?= ucfirst($t['priority']) ?></span></td>
                    <td class="text-xs text-muted"><?= e(date('d M H:i', strtotime($t['created_at']))) ?></td>
                    <td><a href="/modules/support/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-primary">Assign Me</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Farm search tool -->
        <div class="card">
            <div class="card-header"><span class="card-title">Farm Search Tool</span></div>
            <div style="padding:1.25rem">
                <form method="GET" style="display:flex;gap:.5rem;margin-bottom:1rem">
                    <input type="text" name="q" class="form-control" value="<?= e($farm_q) ?>"
                           placeholder="Search by farm name or code..." style="flex:1">
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>

                <?php if (!empty($search_results)): ?>
                <table class="table" style="margin:0;font-size:.82rem">
                    <thead><tr><th>Farm</th><th>Code</th><th>Plan</th><th>Status</th><th>Open Tickets</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($search_results as $r): ?>
                    <tr>
                        <td style="font-weight:600"><?= e($r['farm_name']) ?></td>
                        <td><code style="font-size:.75rem;background:var(--bg-muted);padding:.1rem .3rem;border-radius:4px"><?= e($r['farm_code']) ?></code></td>
                        <td><?= e($r['plan_name'] ?? 'Free') ?></td>
                        <td><span class="badge <?= $r['status']==='active'?'badge-green':'badge-red' ?>" style="font-size:.65rem"><?= ucfirst($r['status']) ?></span></td>
                        <td style="text-align:center"><?= (int)$r['open_tickets'] ?></td>
                        <td>
                            <a href="/modules/super_admin/farm_detail.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">
                                View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php elseif ($farm_q !== ''): ?>
                <div class="text-sm text-muted">No farms found for "<?= e($farm_q) ?>".</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right sidebar: recent activity -->
    <div>
        <div class="card">
            <div class="card-header"><span class="card-title">Recent Activity</span></div>
            <div style="overflow-y:auto;max-height:600px">
            <?php if (empty($activity)): ?>
            <div style="padding:1.5rem;text-align:center;color:#9CA3AF;font-size:.82rem">No activity yet.</div>
            <?php endif; ?>
            <?php foreach ($activity as $al): ?>
            <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border);font-size:.78rem">
                <div style="font-weight:600;color:#111827"><?= e(str_replace('.', ' → ', $al['action'])) ?></div>
                <div style="color:#6B7280;margin-top:.15rem">
                    <?= e($al['user_name'] ?? '—') ?> · <?= e($al['user_role']) ?>
                    <?php if ($al['farm_name']): ?> · <em><?= e($al['farm_name']) ?></em><?php endif; ?>
                </div>
                <div style="color:#9CA3AF;margin-top:.1rem"><?= e(date('d M H:i', strtotime($al['created_at']))) ?></div>
            </div>
            <?php endforeach; ?>
            </div>
            <div style="padding:.75rem 1rem;border-top:1px solid var(--border)">
                <a href="/modules/admin/activity_log.php" class="btn btn-sm btn-secondary" style="width:100%">Full Activity Log</a>
            </div>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
