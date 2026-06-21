<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['superadmin']);

$db = getDB();

// ── KPIs ──────────────────────────────────────────────────────────────────────
$kpi = $db->query(
    "SELECT
        COALESCE(SUM(amount),0)                                    AS total_revenue,
        COALESCE(SUM(CASE WHEN MONTH(paid_at)=MONTH(CURDATE()) AND YEAR(paid_at)=YEAR(CURDATE()) THEN amount END), 0) AS mrr,
        COUNT(DISTINCT farm_id)                                    AS paying_farms,
        COUNT(*)                                                   AS total_payments,
        COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS pending_amount
     FROM payments WHERE status IN ('completed','pending')"
)->fetch();

// Active / trial / grace / expired farm counts
$farm_counts = $db->query(
    "SELECT
        COUNT(*)                                                      AS total_farms,
        SUM(f.status='active')                                        AS active_farms,
        SUM(f.status='suspended')                                     AS suspended_farms,
        SUM(s.status='trial' OR s.id IS NULL)                        AS trial_farms,
        SUM(s.status='grace')                                         AS grace_farms,
        SUM(s.status='expired')                                       AS expired_farms
     FROM farms f
     LEFT JOIN subscriptions s ON s.farm_id=f.id"
)->fetch();

// Plan breakdown
$plan_dist = $db->query(
    "SELECT p.name, COUNT(DISTINCT s.farm_id) AS farms,
            COALESCE(SUM(py.amount),0) AS revenue
     FROM plans p
     LEFT JOIN subscriptions s ON s.plan_id=p.id AND s.status IN ('active','grace')
     LEFT JOIN payments py ON py.plan_id=p.id AND py.status='completed'
     GROUP BY p.id, p.name ORDER BY p.price_monthly ASC"
)->fetchAll();

// Monthly revenue — last 12 months
$monthly = $db->query(
    "SELECT DATE_FORMAT(paid_at,'%Y-%m') AS ym,
            DATE_FORMAT(paid_at,'%b %Y') AS label,
            COALESCE(SUM(amount),0)      AS revenue,
            COUNT(*)                     AS payments
     FROM payments WHERE status='completed' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY ym, label ORDER BY ym ASC"
)->fetchAll();

// Recent payments (latest 30)
$recent_payments = $db->query(
    "SELECT py.*, f.farm_name, p.name AS plan_name, u.name AS recorded_by_name
     FROM payments py
     JOIN farms f ON f.id=py.farm_id
     JOIN plans p ON p.id=py.plan_id
     LEFT JOIN users u ON u.id=py.recorded_by
     ORDER BY py.created_at DESC LIMIT 30"
)->fetchAll();

// Upcoming renewals (expiring in ≤30 days)
$upcoming = $db->query(
    "SELECT f.id AS farm_id, f.farm_name, f.farm_code, s.end_date, s.status AS sub_status, p.name AS plan_name, p.price_monthly,
            DATEDIFF(s.end_date, CURDATE()) AS days_left
     FROM subscriptions s
     JOIN farms f ON f.id=s.farm_id
     JOIN plans p ON p.id=s.plan_id
     WHERE s.status IN ('active','grace') AND s.end_date IS NOT NULL AND DATEDIFF(s.end_date,CURDATE()) <= 30
     ORDER BY s.end_date ASC LIMIT 20"
)->fetchAll();

$page_title = 'Revenue Dashboard';
$active_nav = 'revenue';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Revenue Dashboard</h2>
        <p class="text-sm text-muted">Platform-wide financial overview — <?= date('d M Y') ?></p>
    </div>
    <a href="/modules/super_admin/index.php" class="btn btn-secondary">← Farms</a>
</div>

<!-- KPI Row -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-bottom:1.75rem">
    <div class="kpi-card" style="--kpi-color:#059669;--kpi-soft:#F0FDF4">
        <div class="kpi-value">৳<?= number_format((float)$kpi['total_revenue'], 0) ?></div>
        <div class="kpi-label">Total Revenue</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#0284C7;--kpi-soft:#F0F9FF">
        <div class="kpi-value">৳<?= number_format((float)$kpi['mrr'], 0) ?></div>
        <div class="kpi-label">This Month</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-value"><?= (int)$kpi['paying_farms'] ?></div>
        <div class="kpi-label">Paying Farms</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-value">৳<?= number_format((float)$kpi['pending_amount'], 0) ?></div>
        <div class="kpi-label">Pending</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#2D6A4F;--kpi-soft:#D8F3DC">
        <div class="kpi-value"><?= (int)$farm_counts['total_farms'] ?></div>
        <div class="kpi-label">Total Farms</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-value"><?= (int)$farm_counts['expired_farms'] ?></div>
        <div class="kpi-label">Expired</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;margin-bottom:1.5rem">

    <!-- Monthly Revenue Chart -->
    <div class="card">
        <div class="card-header"><span class="card-title">Monthly Revenue (Last 12 Months)</span></div>
        <div style="padding:1.25rem">
            <?php if (empty($monthly)): ?>
            <p class="text-sm text-muted">No payment data yet.</p>
            <?php else: ?>
            <?php
            $max_rev = max(array_column($monthly, 'revenue') ?: [1]);
            ?>
            <div style="display:flex;align-items:flex-end;gap:6px;height:160px;padding-bottom:.5rem">
                <?php foreach ($monthly as $m): ?>
                <?php $h = max(4, round(($m['revenue'] / $max_rev) * 140)); ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0">
                    <div style="font-size:.6rem;color:#6B7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;text-align:center" title="৳<?= number_format($m['revenue'],0) ?>">
                        ৳<?= number_format($m['revenue'],0) ?>
                    </div>
                    <div style="width:100%;height:<?= $h ?>px;background:linear-gradient(180deg,#7C3AED,#A855F7);border-radius:4px 4px 0 0;min-height:4px"
                         title="<?= e($m['label']) ?>: ৳<?= number_format($m['revenue'],2) ?> (<?= $m['payments'] ?> payments)"></div>
                    <div style="font-size:.6rem;color:#9CA3AF;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;text-align:center">
                        <?= e(substr($m['label'],0,3)) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Farm Status + Plan Breakdown -->
    <div style="display:flex;flex-direction:column;gap:1rem">
        <!-- Farm status -->
        <div class="card">
            <div class="card-header"><span class="card-title">Farm Status</span></div>
            <div style="padding:1rem">
                <?php
                $statuses = [
                    ['Active',    (int)$farm_counts['active_farms'],    '#059669','#F0FDF4'],
                    ['Trial',     (int)$farm_counts['trial_farms'],     '#0284C7','#F0F9FF'],
                    ['Grace',     (int)$farm_counts['grace_farms'],     '#D97706','#FFFBEB'],
                    ['Expired',   (int)$farm_counts['expired_farms'],   '#DC2626','#FEF2F2'],
                    ['Suspended', (int)$farm_counts['suspended_farms'], '#6B7280','#F9FAFB'],
                ];
                $total = max(1, (int)$farm_counts['total_farms']);
                foreach ($statuses as [$lbl,$cnt,$col,$bg]):
                    $pct = round($cnt / $total * 100);
                ?>
                <div style="margin-bottom:.65rem">
                    <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:.2rem">
                        <span><?= $lbl ?></span>
                        <span style="color:<?= $col ?>;font-weight:700"><?= $cnt ?> (<?= $pct ?>%)</span>
                    </div>
                    <div style="height:5px;background:#F3F4F6;border-radius:3px">
                        <div style="height:5px;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:3px"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Plan distribution -->
        <div class="card">
            <div class="card-header"><span class="card-title">Plan Distribution</span></div>
            <div style="overflow-x:auto">
            <table class="table" style="margin:0">
                <thead><tr><th>Plan</th><th style="text-align:right">Farms</th><th style="text-align:right">Revenue</th></tr></thead>
                <tbody>
                <?php foreach ($plan_dist as $pd): ?>
                <tr>
                    <td style="font-weight:600"><?= e($pd['name']) ?></td>
                    <td style="text-align:right"><?= (int)$pd['farms'] ?></td>
                    <td style="text-align:right">৳<?= number_format((float)$pd['revenue'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<!-- Upcoming Renewals -->
<?php if (!empty($upcoming)): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header" style="background:#FFFBEB;border-bottom:1px solid #FDE68A">
        <span class="card-title" style="color:#92400E">⚠ Upcoming Renewals (next 30 days)</span>
    </div>
    <div style="overflow-x:auto">
    <table class="table" style="margin:0">
        <thead>
            <tr><th>Farm</th><th>Plan</th><th>Expires</th><th>Days Left</th><th>Monthly Value</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($upcoming as $r): ?>
        <?php $urg = (int)$r['days_left'] <= 7 ? '#FEF2F2' : ((int)$r['days_left'] <= 14 ? '#FFFBEB' : 'transparent'); ?>
        <tr style="background:<?= $urg ?>">
            <td>
                <a href="/modules/super_admin/farm_detail.php?id=<?= $r['farm_id'] ?? '' ?>" style="font-weight:600">
                    <?= e($r['farm_name']) ?>
                </a>
                <span class="text-xs text-muted d-block"><?= e($r['farm_code']) ?></span>
            </td>
            <td><?= e($r['plan_name']) ?></td>
            <td><?= e($r['end_date']) ?></td>
            <td>
                <span style="font-weight:700;color:<?= (int)$r['days_left'] <= 3 ? '#DC2626' : ((int)$r['days_left'] <= 7 ? '#D97706' : '#059669') ?>">
                    <?= (int)$r['days_left'] ?> days
                </span>
            </td>
            <td>৳<?= number_format((float)$r['price_monthly'], 0) ?></td>
            <td>
                <span class="badge <?= $r['sub_status']==='grace' ? 'badge-orange' : 'badge-green' ?>">
                    <?= ucfirst($r['sub_status']) ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Recent Payments -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Recent Payments</span>
        <span class="text-sm text-muted">Last 30 records</span>
    </div>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr><th>Date</th><th>Farm</th><th>Plan</th><th>Amount</th><th>Method</th><th>Ref</th><th>Months</th><th>By</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php if (empty($recent_payments)): ?>
        <tr><td colspan="9" class="text-center text-muted" style="padding:2rem">No payments recorded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent_payments as $py): ?>
        <tr>
            <td class="text-xs text-muted"><?= e(date('d M Y', strtotime($py['created_at']))) ?></td>
            <td>
                <a href="/modules/super_admin/farm_detail.php?id=<?= $py['farm_id'] ?>" style="font-weight:600">
                    <?= e($py['farm_name']) ?>
                </a>
            </td>
            <td><?= e($py['plan_name']) ?></td>
            <td style="font-weight:700;color:#059669">৳<?= number_format((float)$py['amount'], 2) ?></td>
            <td><?= e(ucfirst($py['method'])) ?></td>
            <td class="text-xs text-muted"><?= e($py['transaction_ref'] ?? '—') ?></td>
            <td style="text-align:center"><?= (int)($py['months'] ?? 1) ?></td>
            <td class="text-xs text-muted"><?= e($py['recorded_by_name'] ?? 'System') ?></td>
            <td>
                <span class="badge <?= $py['status']==='completed' ? 'badge-green' : ($py['status']==='pending' ? 'badge-orange' : 'badge-red') ?>">
                    <?= ucfirst($py['status']) ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
