<?php
require_once dirname(__DIR__, 2) . '/includes/saas_guard.php';
requireRole(['superadmin']);

$db = getDB();

logActivity('ceo.dashboard.viewed');

// ── Platform-wide KPIs ────────────────────────────────────────────────────────
$farm_kpi = $db->query(
    "SELECT
        COUNT(*) AS total,
        SUM(f.status='active')    AS active,
        SUM(f.status='suspended') AS suspended,
        SUM(s.status='trial' OR s.id IS NULL) AS trial,
        SUM(s.status='grace')     AS grace,
        SUM(s.status='expired')   AS expired
     FROM farms f
     LEFT JOIN subscriptions s ON s.farm_id=f.id"
)->fetch();

$rev_kpi = $db->query(
    "SELECT
        COALESCE(SUM(amount),0) AS total_revenue,
        COALESCE(SUM(CASE WHEN MONTH(paid_at)=MONTH(CURDATE()) AND YEAR(paid_at)=YEAR(CURDATE()) THEN amount END),0) AS mrr,
        COUNT(*) AS total_payments
     FROM payments WHERE status='completed'"
)->fetch();

$ticket_kpi = $db->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status='open')        AS open_count,
        SUM(status='in_progress') AS in_progress,
        SUM(priority='critical')  AS critical,
        SUM(DATE(created_at)=CURDATE()) AS today
     FROM support_tickets"
)->fetch();

$user_kpi = $db->query(
    "SELECT
        COUNT(*) AS total_users,
        SUM(role='support_staff') AS staff_count,
        SUM(status='active')      AS active_users
     FROM users WHERE role != 'superadmin'"
)->fetch();

// ── System health ─────────────────────────────────────────────────────────────
$cow_total = (int)$db->query("SELECT COUNT(*) FROM cows WHERE status='active'")->fetchColumn();
$milk_today = (float)$db->query("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE DATE(recorded_at)=CURDATE()")->fetchColumn();
$alert_unread = (int)$db->query("SELECT COUNT(*) FROM alerts WHERE is_read=0")->fetchColumn();

// ── Support staff performance ──────────────────────────────────────────────────
$staff_perf = $db->query(
    "SELECT u.name, u.status,
            COUNT(DISTINCT t.id) AS assigned,
            SUM(t.status IN ('resolved','closed')) AS resolved,
            COUNT(DISTINCT m.id) AS replies,
            MAX(al.created_at) AS last_login
     FROM users u
     LEFT JOIN support_tickets t  ON t.assigned_to = u.id
     LEFT JOIN support_ticket_messages m ON m.sender_id = u.id
     LEFT JOIN activity_log al ON al.user_id = u.id AND al.action = 'support.dashboard.viewed'
     WHERE u.role = 'support_staff'
     GROUP BY u.id, u.name, u.status
     ORDER BY resolved DESC, replies DESC"
)->fetchAll();

// ── Recent logins ─────────────────────────────────────────────────────────────
$recent_logins = getActivityLog(['action' => 'login', 'limit' => 10]);

// ── Upcoming renewals (next 14 days) ─────────────────────────────────────────
$renewals = $db->query(
    "SELECT f.farm_name, s.end_date, p.name AS plan_name, p.price_monthly,
            DATEDIFF(s.end_date, CURDATE()) AS days_left, f.id AS farm_id
     FROM subscriptions s
     JOIN farms f ON f.id=s.farm_id
     JOIN plans p ON p.id=s.plan_id
     WHERE s.status='active' AND s.end_date IS NOT NULL AND DATEDIFF(s.end_date,CURDATE()) <= 14
     ORDER BY s.end_date ASC LIMIT 10"
)->fetchAll();

// ── Critical alerts ───────────────────────────────────────────────────────────
$crit_tickets = $db->query(
    "SELECT t.*, f.farm_name FROM support_tickets t JOIN farms f ON f.id=t.farm_id
     WHERE t.priority='critical' AND t.status IN ('open','in_progress')
     ORDER BY t.created_at ASC LIMIT 5"
)->fetchAll();

$page_title = 'CEO Dashboard';
$active_nav = 'ceo_dashboard';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>CEO Dashboard</h2>
        <p class="text-sm text-muted">AB IT Platform Control Center — <?= date('d F Y') ?></p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/super_admin/index.php"  class="btn btn-secondary">All Farms</a>
        <a href="/modules/super_admin/revenue.php" class="btn btn-secondary">Revenue</a>
        <a href="/modules/admin/employees.php"     class="btn btn-primary">Team</a>
    </div>
</div>

<!-- System KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(165px,1fr));margin-bottom:1.75rem">
    <div class="kpi-card" style="--kpi-color:#2D6A4F;--kpi-soft:#D8F3DC">
        <div class="kpi-value"><?= (int)$farm_kpi['total'] ?></div>
        <div class="kpi-label">Total Farms</div>
        <div style="font-size:.7rem;color:#059669;margin-top:.15rem"><?= (int)$farm_kpi['active'] ?> active</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#059669;--kpi-soft:#F0FDF4">
        <div class="kpi-value">৳<?= number_format((float)$rev_kpi['mrr'], 0) ?></div>
        <div class="kpi-label">Revenue This Month</div>
        <div style="font-size:.7rem;color:#059669;margin-top:.15rem">৳<?= number_format((float)$rev_kpi['total_revenue'], 0) ?> total</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-value"><?= (int)$ticket_kpi['open_count'] ?></div>
        <div class="kpi-label">Open Tickets</div>
        <div style="font-size:.7rem;color:#DC2626;margin-top:.15rem"><?= (int)$ticket_kpi['critical'] ?> critical</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-value"><?= (int)$user_kpi['staff_count'] ?></div>
        <div class="kpi-label">Support Staff</div>
        <div style="font-size:.7rem;color:#7C3AED;margin-top:.15rem"><?= (int)$user_kpi['total_users'] ?> total users</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#0284C7;--kpi-soft:#F0F9FF">
        <div class="kpi-value"><?= number_format($cow_total) ?></div>
        <div class="kpi-label">Active Cows</div>
        <div style="font-size:.7rem;color:#0284C7;margin-top:.15rem"><?= number_format($milk_today, 0) ?>L milk today</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-value"><?= (int)$farm_kpi['grace'] + (int)$farm_kpi['expired'] ?></div>
        <div class="kpi-label">At-Risk Farms</div>
        <div style="font-size:.7rem;color:#D97706;margin-top:.15rem"><?= (int)$farm_kpi['grace'] ?> grace · <?= (int)$farm_kpi['expired'] ?> expired</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">

    <!-- Farm subscription health -->
    <div class="card">
        <div class="card-header"><span class="card-title">Farm Health</span></div>
        <div style="padding:1.25rem">
            <?php
            $health_items = [
                ['Active',    (int)$farm_kpi['active'],    '#059669'],
                ['Trial',     (int)$farm_kpi['trial'],     '#0284C7'],
                ['Grace',     (int)$farm_kpi['grace'],     '#D97706'],
                ['Expired',   (int)$farm_kpi['expired'],   '#DC2626'],
                ['Suspended', (int)$farm_kpi['suspended'], '#6B7280'],
            ];
            $total = max(1, (int)$farm_kpi['total']);
            foreach ($health_items as [$lbl,$cnt,$col]):
                $pct = round($cnt / $total * 100);
            ?>
            <div style="margin-bottom:.65rem">
                <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:.2rem">
                    <span><?= $lbl ?></span>
                    <span style="font-weight:700;color:<?= $col ?>"><?= $cnt ?> (<?= $pct ?>%)</span>
                </div>
                <div style="height:5px;background:#F3F4F6;border-radius:3px">
                    <div style="height:5px;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:3px"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <a href="/modules/super_admin/index.php" class="btn btn-sm btn-secondary" style="width:100%;margin-top:.5rem;text-align:center">
                Manage All Farms
            </a>
        </div>
    </div>

    <!-- Support team performance -->
    <div class="card">
        <div class="card-header"><span class="card-title">Support Team Performance</span></div>
        <?php if (empty($staff_perf)): ?>
        <div style="padding:1.5rem;text-align:center;color:#9CA3AF;font-size:.85rem">
            No support staff yet. <a href="/modules/admin/employees.php">Add team members</a>.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table" style="margin:0;font-size:.8rem">
            <thead><tr><th>Name</th><th>Status</th><th>Assigned</th><th>Resolved</th><th>Replies</th><th>Last Active</th></tr></thead>
            <tbody>
            <?php foreach ($staff_perf as $sp): ?>
            <tr>
                <td style="font-weight:600"><?= e($sp['name']) ?></td>
                <td><span class="badge <?= $sp['status']==='active'?'badge-green':'badge-red' ?>" style="font-size:.65rem"><?= ucfirst($sp['status']) ?></span></td>
                <td style="text-align:center"><?= (int)$sp['assigned'] ?></td>
                <td style="text-align:center;color:#059669;font-weight:700"><?= (int)$sp['resolved'] ?></td>
                <td style="text-align:center"><?= (int)$sp['replies'] ?></td>
                <td class="text-xs text-muted"><?= $sp['last_login'] ? date('d M', strtotime($sp['last_login'])) : 'Never' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        <div style="padding:.75rem 1rem;border-top:1px solid var(--border)">
            <a href="/modules/admin/employees.php" class="btn btn-sm btn-secondary" style="width:100%;text-align:center">
                Manage Team
            </a>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

    <!-- Critical tickets -->
    <div class="card">
        <div class="card-header" style="<?= !empty($crit_tickets) ? 'background:#FEF2F2' : '' ?>">
            <span class="card-title" style="<?= !empty($crit_tickets) ? 'color:#DC2626' : '' ?>">
                <?= !empty($crit_tickets) ? '🚨 ' : '' ?>Critical Tickets
            </span>
            <a href="/modules/support/index.php?priority=critical" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <?php if (empty($crit_tickets)): ?>
        <div style="padding:1.5rem;text-align:center;color:#059669;font-size:.85rem">✓ No critical tickets open.</div>
        <?php else: ?>
        <?php foreach ($crit_tickets as $t): ?>
        <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border)">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <a href="/modules/support/view.php?id=<?= $t['id'] ?>" style="font-weight:600;font-size:.83rem">
                        #<?= $t['id'] ?> <?= e($t['subject']) ?>
                    </a>
                    <div class="text-xs text-muted"><?= e($t['farm_name']) ?> · <?= e(date('d M H:i', strtotime($t['created_at']))) ?></div>
                </div>
                <a href="/modules/support/view.php?id=<?= $t['id'] ?>" class="btn btn-sm" style="background:#DC2626;color:#fff;border:none;font-size:.72rem">
                    Respond
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Upcoming renewals -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Renewals Due (14 days)</span>
            <a href="/modules/super_admin/revenue.php" class="btn btn-sm btn-secondary">Full Report</a>
        </div>
        <?php if (empty($renewals)): ?>
        <div style="padding:1.5rem;text-align:center;color:#9CA3AF;font-size:.85rem">No renewals due in the next 14 days.</div>
        <?php else: ?>
        <?php foreach ($renewals as $r):
            $urg = (int)$r['days_left'] <= 3 ? '#FEF2F2' : ((int)$r['days_left'] <= 7 ? '#FFFBEB' : 'transparent');
        ?>
        <div style="padding:.7rem 1rem;border-bottom:1px solid var(--border);background:<?= $urg ?>">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <span style="font-weight:600;font-size:.83rem"><?= e($r['farm_name']) ?></span>
                    <span class="text-xs text-muted"> · <?= e($r['plan_name']) ?> · ৳<?= number_format($r['price_monthly'],0) ?>/mo</span>
                    <div style="font-size:.72rem;color:<?= (int)$r['days_left'] <= 3 ? '#DC2626' : '#D97706' ?>;font-weight:700">
                        <?= (int)$r['days_left'] ?> days left · <?= e($r['end_date']) ?>
                    </div>
                </div>
                <a href="/modules/super_admin/farm_detail.php?id=<?= $r['farm_id'] ?>" class="btn btn-sm btn-secondary" style="font-size:.72rem">
                    View
                </a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
