<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['superadmin']);

$db    = getDB();
$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$year  = (int)date('Y');
$month = (int)date('m');

// ── Farm & subscription KPIs ──────────────────────────────────────────────────
$kpi = $db->query("
    SELECT
        COUNT(DISTINCT f.id)                                                                                   AS total_farms,
        SUM(CASE WHEN f.status='active' AND s.status IN ('active','trial') THEN 1 ELSE 0 END)                AS active_subs,
        SUM(CASE WHEN s.is_lifetime=1 THEN 1 ELSE 0 END)                                                      AS lifetime,
        SUM(CASE WHEN s.status='trial'  THEN 1 ELSE 0 END)                                                    AS on_trial,
        SUM(CASE WHEN s.status='expired'   THEN 1 ELSE 0 END)                                                 AS expired,
        SUM(CASE WHEN f.status='suspended' OR s.status='suspended' THEN 1 ELSE 0 END)                        AS suspended,
        SUM(CASE WHEN p.name='Free' AND s.status IN ('active','trial') THEN 1 ELSE 0 END)                    AS free_members,
        SUM(CASE WHEN s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 10 DAY)
                  AND s.status='active' AND s.is_lifetime=0 THEN 1 ELSE 0 END)                                AS expiring_10,
        SUM(CASE WHEN s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
                  AND s.status='active' AND s.is_lifetime=0 THEN 1 ELSE 0 END)                                AS expiring_30,
        SUM(CASE WHEN DATE(f.created_at)=CURDATE() THEN 1 ELSE 0 END)                                        AS new_today
    FROM farms f
    LEFT JOIN subscriptions s ON s.farm_id = f.id
    LEFT JOIN plans p         ON p.id       = s.plan_id
")->fetch();

// ── Revenue ───────────────────────────────────────────────────────────────────
$rev_month  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND MONTH(paid_at)={$month} AND YEAR(paid_at)={$year}")->fetchColumn();
$rev_year   = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND YEAR(paid_at)={$year}")->fetchColumn();
$rev_total  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed'")->fetchColumn();
$pending    = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'")->fetchColumn();
$pending_ct = (int)$db->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();

// ── Today's activity ──────────────────────────────────────────────────────────
$today_payments = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND DATE(paid_at)=CURDATE()")->fetchColumn();
$today_req      = (int)$db->query("SELECT COUNT(*) FROM payments WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// ── Plan distribution ─────────────────────────────────────────────────────────
$plan_dist = $db->query("
    SELECT p.name, p.price_monthly, COUNT(s.id) AS cnt
    FROM subscriptions s JOIN plans p ON p.id=s.plan_id
    WHERE s.status IN ('active','trial')
    GROUP BY p.name, p.price_monthly ORDER BY p.price_monthly
")->fetchAll();

// ── Coupon stats ──────────────────────────────────────────────────────────────
$coupon_stats = ['total'=>0,'active'=>0,'used_total'=>0];
$tables = array_column($db->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
if (in_array('coupons', $tables)) {
    $cs = $db->query("SELECT COUNT(*) AS total, SUM(is_active) AS active, SUM(used_count) AS used_total FROM coupons")->fetch();
    $coupon_stats = ['total'=>(int)$cs['total'], 'active'=>(int)$cs['active'], 'used_total'=>(int)($cs['used_total']??0)];
}

// ── Farms expiring soon ───────────────────────────────────────────────────────
$expiring_10 = $db->query("
    SELECT f.id AS farm_id, f.farm_name, s.end_date, p.name AS plan_name,
           DATEDIFF(s.end_date, CURDATE()) AS days_left
    FROM farms f JOIN subscriptions s ON s.farm_id=f.id JOIN plans p ON p.id=s.plan_id
    WHERE s.status='active' AND s.is_lifetime=0 AND s.end_date IS NOT NULL
      AND s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 10 DAY)
    ORDER BY s.end_date ASC LIMIT 15
")->fetchAll();

$expiring_30 = $db->query("
    SELECT f.id AS farm_id, f.farm_name, s.end_date, p.name AS plan_name,
           DATEDIFF(s.end_date, CURDATE()) AS days_left
    FROM farms f JOIN subscriptions s ON s.farm_id=f.id JOIN plans p ON p.id=s.plan_id
    WHERE s.status='active' AND s.is_lifetime=0 AND s.end_date IS NOT NULL
      AND s.end_date > DATE_ADD(CURDATE(),INTERVAL 10 DAY)
      AND s.end_date <= DATE_ADD(CURDATE(),INTERVAL 30 DAY)
    ORDER BY s.end_date ASC LIMIT 15
")->fetchAll();

// ── Recent CEO grants ─────────────────────────────────────────────────────────
$recent_grants = $db->query("
    SELECT cg.*, u.name AS ceo_name
    FROM ceo_grants cg LEFT JOIN users u ON u.id=cg.granted_by
    WHERE cg.farm_name != 'GLOBAL PRICING'
    ORDER BY cg.created_at DESC LIMIT 10
")->fetchAll();

// ── Monthly revenue trend (last 6 months) ────────────────────────────────────
$rev_trend = $db->query("
    SELECT DATE_FORMAT(paid_at,'%b %Y') AS mo, YEAR(paid_at) AS yr, MONTH(paid_at) AS mn,
           COALESCE(SUM(amount),0) AS total
    FROM payments WHERE status='completed' AND paid_at >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
    GROUP BY yr,mn,mo ORDER BY yr,mn
")->fetchAll();

// ── Pending payments list ─────────────────────────────────────────────────────
$pending_list = $db->query("
    SELECT py.id, py.amount, py.method, py.created_at, f.farm_name, p.name AS plan_name
    FROM payments py
    JOIN farms f ON f.id=py.farm_id
    JOIN plans  p ON p.id=py.plan_id
    WHERE py.status='pending'
    ORDER BY py.created_at ASC LIMIT 8
")->fetchAll();

$page_title = 'CEO Control Center';
$active_nav = 'ceo_control';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<style>
.ceo-kpi{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:.85rem 1rem;display:flex;flex-direction:column;gap:.2rem;transition:box-shadow .15s}
.ceo-kpi:hover{box-shadow:0 4px 16px rgba(0,0,0,.09)}
.ceo-kpi-val{font-size:1.65rem;font-weight:800;line-height:1}
.ceo-kpi-lbl{font-size:.72rem;color:var(--text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.ceo-kpi-sub{font-size:.72rem;color:var(--text-secondary)}
.ceo-rev{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1rem 1.2rem;display:flex;align-items:center;gap:1rem;transition:box-shadow .15s}
.ceo-rev:hover{box-shadow:0 4px 16px rgba(0,0,0,.09)}
.ceo-rev-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0}
.ceo-qa{display:flex;align-items:center;gap:.75rem;background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:.75rem 1rem;text-decoration:none;transition:all .15s;cursor:pointer}
.ceo-qa:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.1);text-decoration:none}
.ceo-qa-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.section-title{font-size:.8rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.06em;margin:0 0 .65rem}
.farm-row{display:flex;justify-content:space-between;align-items:center;padding:.5rem 1rem;border-bottom:1px solid var(--border)}
.farm-row:last-child{border-bottom:none}
.badge-action{display:inline-block;padding:.18rem .5rem;border-radius:99px;font-size:.68rem;font-weight:700;color:#fff;white-space:nowrap}
</style>

<!-- ── Page header ────────────────────────────────────────────────────────── -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem">
    <div>
        <h2 style="font-size:1.35rem;font-weight:800;margin:0 0 .2rem;display:flex;align-items:center;gap:.5rem">
            <span style="background:linear-gradient(135deg,#7c3aed,#db2777);-webkit-background-clip:text;-webkit-text-fill-color:transparent">👑 CEO Control Center</span>
        </h2>
        <p style="color:var(--text-secondary);font-size:.82rem;margin:0">
            <?= date('l, d F Y') ?>
            &nbsp;·&nbsp; <?= (int)$kpi['total_farms'] ?> farms &nbsp;·&nbsp;
            <?= (int)$kpi['active_subs'] ?> active subscriptions
            <?php if ($kpi['new_today'] > 0): ?>
            &nbsp;·&nbsp; <span style="color:#16a34a;font-weight:700">+<?= (int)$kpi['new_today'] ?> new today</span>
            <?php endif; ?>
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="/modules/ceo/subscriptions.php" class="btn btn-primary btn-sm">📋 Subscriptions</a>
        <a href="/modules/ceo/plans.php"         class="btn btn-secondary btn-sm">💲 Plans</a>
        <a href="/modules/ceo/coupons.php"       class="btn btn-secondary btn-sm">🎟 Coupons</a>
        <a href="/modules/ceo/audit.php"         class="btn btn-secondary btn-sm">📜 Audit Log</a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 1 — Subscription KPIs (9 cards)
══════════════════════════════════════════════════════════════ -->
<p class="section-title">Subscription Overview</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.65rem;margin-bottom:1.5rem">
<?php
$sub_kpis = [
    ['Total Farms',      $kpi['total_farms'],  '#0284c7', '🏠', null],
    ['Active Subs',      $kpi['active_subs'],  '#16a34a', '✅', null],
    ['Lifetime',         $kpi['lifetime'],     '#7c3aed', '♾',  null],
    ['Free Members',     $kpi['free_members'], '#6b7280', '🎁', null],
    ['On Trial',         $kpi['on_trial'],     '#d97706', '🔄', null],
    ['Expiring 10d',     $kpi['expiring_10'],  '#f97316', '⏰', 'urgent'],
    ['Expiring 30d',     $kpi['expiring_30'],  '#eab308', '📅', null],
    ['Expired',          $kpi['expired'],      '#dc2626', '⛔', $kpi['expired'] > 0 ? 'urgent' : null],
    ['Suspended',        $kpi['suspended'],    '#b91c1c', '🔒', $kpi['suspended'] > 0 ? 'urgent' : null],
];
foreach ($sub_kpis as [$lbl,$val,$col,$icon,$flag]):
    $glow = $flag === 'urgent' && $val > 0 ? ";box-shadow:0 0 0 2px {$col}33" : '';
?>
<div class="ceo-kpi" style="border-top:3px solid <?= $col ?><?= $glow ?>">
    <div style="font-size:1.1rem;line-height:1"><?= $icon ?></div>
    <div class="ceo-kpi-val" style="color:<?= $col ?>"><?= number_format((int)$val) ?></div>
    <div class="ceo-kpi-lbl"><?= $lbl ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 2 — Revenue KPIs
══════════════════════════════════════════════════════════════ -->
<p class="section-title">Revenue</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:.65rem;margin-bottom:1.5rem">
<?php foreach ([
    ['This Month',    $rev_month,  '#16a34a', '#f0fdf4', '💰', date('M Y')],
    ['This Year',     $rev_year,   '#0284c7', '#eff6ff', '📊', 'Jan–'.date('M Y')],
    ['All-Time',      $rev_total,  '#7c3aed', '#f5f3ff', '💎', 'Cumulative'],
    ['Today',         $today_payments,'#059669','#f0fdf4','⚡', 'Completed today'],
    ['Pending',       $pending,    '#d97706', '#fffbeb', '⏳', "{$pending_ct} request".($pending_ct!=1?'s':'').' awaiting'],
] as [$lbl,$val,$col,$bg,$icon,$sub]): ?>
<div class="ceo-rev" style="border-left:4px solid <?= $col ?>;background:<?= $bg ?>">
    <div class="ceo-rev-icon" style="background:<?= $col ?>22"><?= $icon ?></div>
    <div>
        <div style="font-size:.72rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.03em"><?= $lbl ?></div>
        <div style="font-size:1.2rem;font-weight:800;color:<?= $col ?>">৳<?= number_format($val, 2) ?></div>
        <div style="font-size:.72rem;color:var(--text-secondary)"><?= $sub ?></div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 3 — Quick Actions
══════════════════════════════════════════════════════════════ -->
<p class="section-title">Quick Actions</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:.6rem;margin-bottom:1.5rem">
<?php foreach ([
    ['/modules/ceo/subscriptions.php',                  '🎟',  'Grant Free Access',    '#16a34a', '#f0fdf4', '1M – Lifetime for any farm'],
    ['/modules/ceo/subscriptions.php?status=lifetime',  '♾',   'Lifetime Members',     '#7c3aed', '#f5f3ff', 'Manage lifetime holders'],
    ['/modules/ceo/subscriptions.php?status=expired',   '🔁',  'Reactivate Expired',   '#d97706', '#fffbeb', (int)$kpi['expired'].' expired farms'],
    ['/modules/ceo/subscriptions.php?status=suspended', '🔒',  'Suspended Farms',      '#dc2626', '#fef2f2', (int)$kpi['suspended'].' suspended'],
    ['/modules/ceo/plans.php',                          '💲',  'Plans & Pricing',      '#0284c7', '#eff6ff', 'Edit prices & limits'],
    ['/modules/ceo/coupons.php',                        '🏷',  'Coupon Codes',         '#7c3aed', '#f5f3ff', $coupon_stats['active'].' active coupons'],
    ['/modules/super_admin/payments.php',               '💳',  'Pending Payments',     '#d97706', '#fffbeb', "{$pending_ct} need review"],
    ['/modules/ceo/audit.php',                          '📜',  'CEO Audit Log',        '#6b7280', '#f9fafb', 'Full action history'],
] as [$url,$icon,$lbl,$col,$bg,$desc]): ?>
<a href="<?= e($url) ?>" class="ceo-qa">
    <div class="ceo-qa-icon" style="background:<?= $col ?>22;color:<?= $col ?>"><?= $icon ?></div>
    <div>
        <div style="font-weight:700;color:<?= $col ?>;font-size:.83rem;line-height:1.2"><?= $lbl ?></div>
        <div style="font-size:.71rem;color:var(--text-secondary);margin-top:.1rem"><?= e($desc) ?></div>
    </div>
</a>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 4 — Three-column layout: Expiry + Plan dist + Coupons
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.1rem;margin-bottom:1.25rem">

    <!-- Expiring in 10 days -->
    <div class="card" style="border-top:3px solid #f97316">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;background:#fff7ed">
            <div>
                <div style="font-weight:700;color:#c2410c;font-size:.9rem">⏰ Expiring in 10 Days</div>
                <div style="font-size:.72rem;color:#9a3412"><?= count($expiring_10) ?> farm<?= count($expiring_10)!=1?'s':'' ?></div>
            </div>
            <a href="/modules/ceo/subscriptions.php?status=active" class="btn btn-xs btn-secondary">Manage</a>
        </div>
        <?php if (empty($expiring_10)): ?>
        <div style="padding:1.25rem;text-align:center;color:var(--text-secondary);font-size:.83rem">✅ No farms expiring this week</div>
        <?php else: ?>
        <?php foreach ($expiring_10 as $ex):
            $dl = (int)$ex['days_left'];
            $dc = $dl <= 1 ? '#dc2626' : ($dl <= 3 ? '#f97316' : '#d97706');
        ?>
        <div class="farm-row">
            <div>
                <div style="font-size:.84rem;font-weight:600"><?= e($ex['farm_name']) ?></div>
                <div style="font-size:.72rem;color:var(--text-secondary)"><?= e($ex['plan_name']) ?> · <?= e(date('d M', strtotime($ex['end_date']))) ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:.45rem">
                <span style="font-size:.8rem;font-weight:800;color:<?= $dc ?>"><?= $dl <= 0 ? 'TODAY' : ($dl === 1 ? '1 day' : "{$dl}d") ?></span>
                <a href="/modules/ceo/subscriptions.php?search=<?= urlencode($ex['farm_name']) ?>" class="btn btn-xs btn-primary" style="font-size:.68rem;padding:.2rem .5rem">Grant</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Plan distribution -->
    <div class="card">
        <div class="card-header">
            <div style="font-weight:700;font-size:.9rem">📊 Plan Distribution</div>
            <div style="font-size:.72rem;color:var(--text-secondary)">Active subscriptions</div>
        </div>
        <div style="padding:.5rem 1rem">
        <?php
        $total_active = max(1, array_sum(array_column($plan_dist,'cnt')));
        if (empty($plan_dist)):
        ?>
        <p style="color:var(--text-secondary);font-size:.83rem;padding:.75rem 0;text-align:center">No active subscriptions yet.</p>
        <?php else: foreach ($plan_dist as $pd):
            $bc = match($pd['name']){'Free'=>'#6b7280','Basic'=>'#0284c7','Pro'=>'#7c3aed','Enterprise'=>'#d97706',default=>'#9ca3af'};
            $w  = round($pd['cnt']/$total_active*100);
        ?>
        <div style="padding:.45rem 0;border-bottom:1px solid var(--border)">
            <div style="display:flex;justify-content:space-between;margin-bottom:.3rem">
                <span style="font-size:.82rem;font-weight:700;color:<?= $bc ?>"><?= e($pd['name']) ?></span>
                <span style="font-size:.82rem;font-weight:700"><?= $pd['cnt'] ?> <span style="color:var(--text-secondary);font-weight:400">(<?= $w ?>%)</span></span>
            </div>
            <div style="height:6px;background:var(--border);border-radius:99px">
                <div style="height:6px;border-radius:99px;background:<?= $bc ?>;width:<?= $w ?>%;transition:width .6s"></div>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
        <?php if ($coupon_stats['total'] > 0): ?>
        <div style="border-top:1px solid var(--border);padding:.6rem 1rem;display:flex;gap:1.25rem">
            <div style="text-align:center">
                <div style="font-size:1.1rem;font-weight:800;color:#7c3aed"><?= $coupon_stats['active'] ?></div>
                <div style="font-size:.7rem;color:var(--text-secondary)">Active Coupons</div>
            </div>
            <div style="text-align:center">
                <div style="font-size:1.1rem;font-weight:800;color:#16a34a"><?= $coupon_stats['used_total'] ?></div>
                <div style="font-size:.7rem;color:var(--text-secondary)">Total Uses</div>
            </div>
            <div style="text-align:center">
                <div style="font-size:1.1rem;font-weight:800;color:#0284c7"><?= $coupon_stats['total'] ?></div>
                <div style="font-size:.7rem;color:var(--text-secondary)">Total Coupons</div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Expiring 11–30 days -->
    <div class="card" style="border-top:3px solid #eab308">
        <div class="card-header" style="background:#fefce8">
            <div style="font-weight:700;color:#854d0e;font-size:.9rem">📅 Expiring 11–30 Days</div>
            <div style="font-size:.72rem;color:#a16207"><?= count($expiring_30) ?> farm<?= count($expiring_30)!=1?'s':'' ?></div>
        </div>
        <?php if (empty($expiring_30)): ?>
        <div style="padding:1.25rem;text-align:center;color:var(--text-secondary);font-size:.83rem">✅ None in this window</div>
        <?php else: ?>
        <?php foreach (array_slice($expiring_30,0,7) as $ex): ?>
        <div class="farm-row">
            <div>
                <div style="font-size:.83rem;font-weight:600"><?= e($ex['farm_name']) ?></div>
                <div style="font-size:.72rem;color:var(--text-secondary)"><?= e($ex['plan_name']) ?> · <?= e(date('d M', strtotime($ex['end_date']))) ?></div>
            </div>
            <span style="font-size:.8rem;font-weight:700;color:#92400e"><?= $ex['days_left'] ?>d</span>
        </div>
        <?php endforeach;
        if (count($expiring_30) > 7): ?>
        <div style="padding:.5rem 1rem;font-size:.75rem">
            <a href="/modules/ceo/subscriptions.php?status=active" style="color:#0284c7">+<?= count($expiring_30)-7 ?> more →</a>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 5 — Pending payments + Recent actions + Revenue trend
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.1rem;margin-bottom:1.25rem">

    <!-- Pending Payment Requests -->
    <div class="card" style="border-top:3px solid #d97706">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;background:#fffbeb">
            <div>
                <div style="font-weight:700;color:#b45309;font-size:.9rem">💳 Pending Payments</div>
                <div style="font-size:.72rem;color:#92400e"><?= $pending_ct ?> request<?= $pending_ct!=1?'s':'' ?> · ৳<?= number_format($pending, 2) ?></div>
            </div>
            <a href="/modules/super_admin/payments.php" class="btn btn-xs btn-primary" style="background:#d97706;border-color:#d97706">Review</a>
        </div>
        <?php if (empty($pending_list)): ?>
        <div style="padding:1.25rem;text-align:center;color:var(--text-secondary);font-size:.83rem">✅ No pending payments</div>
        <?php else: ?>
        <?php foreach ($pending_list as $py): ?>
        <div class="farm-row">
            <div>
                <div style="font-size:.83rem;font-weight:600"><?= e($py['farm_name']) ?></div>
                <div style="font-size:.72rem;color:var(--text-secondary)"><?= e($py['plan_name']) ?> · <?= ucfirst($py['method']) ?></div>
            </div>
            <div style="text-align:right">
                <div style="font-size:.84rem;font-weight:700;color:#d97706">৳<?= number_format($py['amount'], 2) ?></div>
                <div style="font-size:.7rem;color:var(--text-secondary)"><?= e(date('d M', strtotime($py['created_at']))) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Recent CEO Actions -->
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-weight:700;font-size:.9rem">👑 Recent CEO Actions</div>
            <a href="/modules/ceo/audit.php" class="btn btn-xs btn-secondary">View All</a>
        </div>
        <?php if (empty($recent_grants)): ?>
        <div style="padding:1.5rem;text-align:center;color:var(--text-secondary);font-size:.83rem">No CEO actions recorded yet.</div>
        <?php else: ?>
        <?php foreach ($recent_grants as $g):
            $is_lt = (bool)$g['is_lifetime'];
            $bg = match($g['action_type']){
                'lifetime'   =>'linear-gradient(135deg,#7c3aed,#db2777)',
                'free_access'=>'#16a34a', 'plan_change'=>'#0284c7',
                'suspend'    =>'#dc2626', 'reactivate' =>'#d97706',
                default      =>'#6b7280'
            };
        ?>
        <div class="farm-row" style="align-items:flex-start;gap:.5rem">
            <div style="flex:1;min-width:0">
                <div style="font-size:.83rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($g['farm_name'] ?: 'Farm #'.$g['farm_id']) ?></div>
                <div style="font-size:.72rem;color:var(--text-secondary)">
                    <?= e($g['old_plan_name'] ?: '—') ?> → <strong><?= e($g['new_plan_name'] ?: '—') ?></strong>
                </div>
                <div style="font-size:.7rem;color:var(--text-secondary)"><?= e(formatDateTime($g['created_at'])) ?></div>
            </div>
            <span class="badge-action" style="background:<?= $bg ?>;flex-shrink:0">
                <?= $is_lt ? '♾ ' : '' ?><?= ucfirst(str_replace('_',' ',$g['action_type'])) ?>
            </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Revenue Trend -->
    <div class="card">
        <div class="card-header">
            <div style="font-weight:700;font-size:.9rem">📈 Revenue Trend</div>
            <div style="font-size:.72rem;color:var(--text-secondary)">Last 6 months</div>
        </div>
        <?php if (empty($rev_trend)): ?>
        <div style="padding:1.5rem;text-align:center;color:var(--text-secondary);font-size:.83rem">No payment data yet.</div>
        <?php else: ?>
        <div style="padding:.5rem 1rem">
        <?php
        $max_rev = max(array_column($rev_trend,'total')) ?: 1;
        foreach ($rev_trend as $rt):
            $w = round($rt['total']/$max_rev*100);
        ?>
        <div style="display:flex;align-items:center;gap:.65rem;padding:.3rem 0;border-bottom:1px solid var(--border)">
            <span style="width:60px;font-size:.78rem;color:var(--text-secondary);flex-shrink:0"><?= e($rt['mo']) ?></span>
            <div style="flex:1;height:8px;background:var(--border);border-radius:99px">
                <div style="height:8px;border-radius:99px;background:linear-gradient(90deg,#16a34a,#4ade80);width:<?= $w ?>%"></div>
            </div>
            <span style="width:85px;text-align:right;font-size:.8rem;font-weight:700;color:#16a34a;flex-shrink:0">৳<?= number_format($rt['total'],2) ?></span>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════
     SECTION 6 — Info banners
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem">

    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.9rem 1.1rem">
        <div style="font-weight:700;color:#1d4ed8;margin-bottom:.45rem;font-size:.85rem">⏰ Auto-Expiry Warning System</div>
        <div style="display:flex;flex-direction:column;gap:.2rem;font-size:.78rem;color:#1e3a8a">
            <?php foreach ([['10 days','First warning'],['7 days','Regular warning'],['3 days','Urgent warning'],['1 day','"Expires tomorrow"'],['Today','"Expired" — critical']] as [$w,$d]): ?>
            <div><strong><?= $w ?>:</strong> <?= $d ?></div>
            <?php endforeach; ?>
        </div>
        <div style="font-size:.72rem;color:#64748b;margin-top:.5rem">Auto-created daily in each farm's notification center.</div>
    </div>

    <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:.9rem 1.1rem">
        <div style="font-weight:700;color:#7c3aed;margin-bottom:.45rem;font-size:.85rem">🛡️ CEO Security Rules</div>
        <div style="display:flex;flex-direction:column;gap:.2rem;font-size:.78rem;color:#5b21b6">
            <?php foreach ([
                'CEO role cannot be deleted or downgraded',
                'Only CEO can assign Lifetime plans',
                'Only CEO can change global plan pricing',
                'All CEO actions logged in audit trail',
                'CEO bypasses all subscription restrictions',
                'Admin staff cannot grant free access beyond scope',
            ] as $rule): ?>
            <div>✓ <?= $rule ?></div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
