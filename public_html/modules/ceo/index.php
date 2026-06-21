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
        COUNT(DISTINCT f.id) AS total_farms,
        SUM(CASE WHEN f.status='active' AND s.status IN ('active','trial') THEN 1 ELSE 0 END) AS active_subs,
        SUM(CASE WHEN s.is_lifetime=1 THEN 1 ELSE 0 END) AS lifetime,
        SUM(CASE WHEN s.status='trial'    THEN 1 ELSE 0 END) AS on_trial,
        SUM(CASE WHEN s.status='expired'  THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN f.status='suspended' OR s.status='suspended' THEN 1 ELSE 0 END) AS suspended,
        SUM(CASE WHEN p.name='Free' AND s.status IN ('active','trial') THEN 1 ELSE 0 END) AS free_members,
        SUM(CASE WHEN s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 10 DAY)
                  AND s.status='active' AND s.is_lifetime=0 THEN 1 ELSE 0 END) AS expiring_10,
        SUM(CASE WHEN s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
                  AND s.status='active' AND s.is_lifetime=0 THEN 1 ELSE 0 END) AS expiring_30,
        SUM(CASE WHEN DATE(f.created_at)=CURDATE() THEN 1 ELSE 0 END) AS new_today
    FROM farms f
    LEFT JOIN subscriptions s ON s.farm_id=f.id
    LEFT JOIN plans p         ON p.id=s.plan_id
")->fetch();

// ── Revenue ───────────────────────────────────────────────────────────────────
$rev_month      = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND MONTH(paid_at)=$month AND YEAR(paid_at)=$year")->fetchColumn();
$rev_year       = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND YEAR(paid_at)=$year")->fetchColumn();
$rev_total      = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed'")->fetchColumn();
$pending_amt    = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'")->fetchColumn();
$pending_ct     = (int)$db->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();
$today_rev      = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='completed' AND DATE(paid_at)=CURDATE()")->fetchColumn();

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
    $cs = $db->query("SELECT COUNT(*) AS total, SUM(is_active) AS active, COALESCE(SUM(used_count),0) AS used_total FROM coupons")->fetch();
    $coupon_stats = ['total'=>(int)$cs['total'],'active'=>(int)$cs['active'],'used_total'=>(int)$cs['used_total']];
}

// ── Farms expiring in 10 days ─────────────────────────────────────────────────
$expiring_10 = $db->query("
    SELECT f.farm_name, s.end_date, p.name AS plan_name,
           DATEDIFF(s.end_date,CURDATE()) AS days_left
    FROM farms f JOIN subscriptions s ON s.farm_id=f.id JOIN plans p ON p.id=s.plan_id
    WHERE s.status='active' AND s.is_lifetime=0 AND s.end_date IS NOT NULL
      AND s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 10 DAY)
    ORDER BY s.end_date ASC LIMIT 8
")->fetchAll();

// ── Farms expiring 11–30 days ─────────────────────────────────────────────────
$expiring_30 = $db->query("
    SELECT f.farm_name, s.end_date, p.name AS plan_name,
           DATEDIFF(s.end_date,CURDATE()) AS days_left
    FROM farms f JOIN subscriptions s ON s.farm_id=f.id JOIN plans p ON p.id=s.plan_id
    WHERE s.status='active' AND s.is_lifetime=0 AND s.end_date IS NOT NULL
      AND s.end_date > DATE_ADD(CURDATE(),INTERVAL 10 DAY)
      AND s.end_date <= DATE_ADD(CURDATE(),INTERVAL 30 DAY)
    ORDER BY s.end_date ASC LIMIT 8
")->fetchAll();

// ── Recent CEO grants ─────────────────────────────────────────────────────────
$recent_grants = $db->query("
    SELECT cg.*, u.name AS ceo_name
    FROM ceo_grants cg LEFT JOIN users u ON u.id=cg.granted_by
    WHERE cg.farm_name != 'GLOBAL PRICING'
    ORDER BY cg.created_at DESC LIMIT 8
")->fetchAll();

// ── Revenue trend (last 6 months) ─────────────────────────────────────────────
$rev_trend = $db->query("
    SELECT DATE_FORMAT(paid_at,'%b %Y') AS mo, YEAR(paid_at) AS yr, MONTH(paid_at) AS mn,
           COALESCE(SUM(amount),0) AS total
    FROM payments WHERE status='completed' AND paid_at >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
    GROUP BY yr,mn,mo ORDER BY yr,mn
")->fetchAll();

// ── Pending payment requests list ─────────────────────────────────────────────
$pending_list = $db->query("
    SELECT py.id, py.amount, py.method, py.created_at, f.farm_name, p.name AS plan_name
    FROM payments py JOIN farms f ON f.id=py.farm_id JOIN plans p ON p.id=py.plan_id
    WHERE py.status='pending' ORDER BY py.created_at ASC LIMIT 6
")->fetchAll();

$page_title = 'CEO Control Center';
$active_nav = 'ceo_control';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<style>
/* ── CEO Dashboard specific styles ─────────────────────────────────────────── */
.ceo-hero {
    background: linear-gradient(135deg, #1B4332 0%, #2D6A4F 60%, #40916C 100%);
    border-radius: var(--radius-xl);
    padding: 1.75rem 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.ceo-hero::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 200px; height: 200px;
    background: rgba(255,255,255,.04);
    border-radius: 50%;
}
.ceo-hero::after {
    content: '';
    position: absolute;
    bottom: -60px; right: 80px;
    width: 280px; height: 280px;
    background: rgba(255,255,255,.03);
    border-radius: 50%;
}
.ceo-hero-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.5rem;
    position: relative;
    z-index: 1;
}
.ceo-hero-stats {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
    gap: 1px;
    background: rgba(255,255,255,.12);
    border-radius: var(--radius-lg);
    overflow: hidden;
    position: relative;
    z-index: 1;
}
.ceo-hero-stat {
    background: rgba(255,255,255,.05);
    padding: .85rem 1.1rem;
    text-align: center;
    transition: background .15s;
}
.ceo-hero-stat:hover { background: rgba(255,255,255,.1); }
.ceo-hero-stat-val {
    font-size: 1.6rem;
    font-weight: 800;
    color: #fff;
    line-height: 1;
}
.ceo-hero-stat-lbl {
    font-size: .68rem;
    color: rgba(255,255,255,.65);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-top: .25rem;
}
.ceo-hero-stat-lbl.accent { color: #86efac; }
.ceo-hero-stat-val.warn   { color: #fcd34d; }
.ceo-hero-stat-val.danger { color: #fca5a5; }

/* Revenue tiles */
.rev-tile {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 1.1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: box-shadow .15s, transform .15s;
}
.rev-tile:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
.rev-tile-icon {
    width: 46px; height: 46px;
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.rev-tile-val {
    font-size: 1.15rem;
    font-weight: 800;
    line-height: 1.1;
}
.rev-tile-lbl {
    font-size: .71rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-secondary);
}
.rev-tile-sub { font-size: .72rem; color: var(--text-secondary); margin-top: .1rem; }

/* Metric cards (subscription KPIs) */
.metric-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: .85rem 1rem;
    text-align: center;
    position: relative;
    overflow: hidden;
    transition: box-shadow .15s, transform .15s;
    cursor: default;
}
.metric-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
.metric-card.urgent {
    border-color: transparent;
}
.metric-card.urgent::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: var(--radius-lg);
    border: 2px solid currentColor;
    opacity: .25;
    pointer-events: none;
}
.metric-val {
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1;
    margin: .25rem 0 .2rem;
}
.metric-lbl {
    font-size: .7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-secondary);
    line-height: 1.3;
}
.metric-icon { font-size: 1.2rem; line-height: 1; }

/* Quick action cards */
.qa-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: .9rem 1rem;
    display: flex;
    align-items: center;
    gap: .75rem;
    text-decoration: none !important;
    transition: box-shadow .15s, transform .15s, border-color .15s;
    color: var(--text-primary) !important;
}
.qa-card:hover {
    box-shadow: var(--shadow);
    transform: translateY(-2px);
    border-color: #c8c2b5;
}
.qa-card-icon {
    width: 40px; height: 40px;
    border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.qa-card-title { font-size: .84rem; font-weight: 700; line-height: 1.2; }
.qa-card-sub { font-size: .71rem; color: var(--text-secondary); margin-top: .1rem; }

/* Section heading */
.dash-section {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--text-secondary);
    margin: 0 0 .75rem;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.dash-section::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* Farm list rows */
.farm-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    padding: .6rem 1.1rem;
    border-bottom: 1px solid var(--border);
    transition: background .12s;
}
.farm-row:last-child { border-bottom: none; }
.farm-row:hover { background: rgba(45,106,79,.03); }
.farm-name { font-size: .84rem; font-weight: 600; color: var(--text-primary); }
.farm-meta { font-size: .71rem; color: var(--text-secondary); margin-top: .05rem; }

/* Action type badge */
.action-badge {
    display: inline-flex;
    align-items: center;
    padding: .2rem .55rem;
    border-radius: 99px;
    font-size: .68rem;
    font-weight: 700;
    white-space: nowrap;
    color: #fff;
}

/* Bar chart rows */
.bar-row { display: flex; align-items: center; gap: .65rem; padding: .35rem 0; border-bottom: 1px solid var(--border); }
.bar-row:last-child { border-bottom: none; }
.bar-label { width: 58px; font-size: .75rem; color: var(--text-secondary); flex-shrink: 0; }
.bar-track { flex: 1; height: 8px; background: var(--border); border-radius: 99px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 99px; transition: width .6s ease; }
.bar-val { width: 80px; text-align: right; font-size: .78rem; font-weight: 700; flex-shrink: 0; }

/* Urgent banner */
.urgent-banner {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .65rem 1.1rem;
    border-radius: var(--radius);
    font-size: .82rem;
    font-weight: 600;
    margin-bottom: .5rem;
}
</style>

<!-- ══════════════════════════════════════════════════════════
     HERO BANNER
══════════════════════════════════════════════════════════════ -->
<div class="ceo-hero">
    <div class="ceo-hero-top">
        <div>
            <div style="font-size:1.35rem;font-weight:800;color:#fff;display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem">
                <span>👑</span> CEO Control Center
            </div>
            <div style="font-size:.82rem;color:rgba(255,255,255,.65)">
                <?= date('l, d F Y') ?>
                <?php if ($kpi['new_today'] > 0): ?>
                &nbsp;·&nbsp; <span style="color:#86efac;font-weight:700">+<?= (int)$kpi['new_today'] ?> new farm<?= $kpi['new_today']>1?'s':'' ?> today</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;position:relative;z-index:1">
            <a href="/modules/ceo/subscriptions.php" class="btn btn-sm" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.25);color:#fff;backdrop-filter:blur(4px)">📋 Subscriptions</a>
            <a href="/modules/ceo/plans.php"         class="btn btn-sm" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.25);color:#fff;backdrop-filter:blur(4px)">💲 Plans</a>
            <a href="/modules/ceo/coupons.php"       class="btn btn-sm" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.25);color:#fff;backdrop-filter:blur(4px)">🏷 Coupons</a>
            <a href="/modules/ceo/audit.php"         class="btn btn-sm" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.25);color:#fff;backdrop-filter:blur(4px)">📜 Audit</a>
        </div>
    </div>

    <!-- Hero stats strip -->
    <div class="ceo-hero-stats">
        <div class="ceo-hero-stat">
            <div class="ceo-hero-stat-val"><?= (int)$kpi['total_farms'] ?></div>
            <div class="ceo-hero-stat-lbl">Total Farms</div>
        </div>
        <div class="ceo-hero-stat">
            <div class="ceo-hero-stat-val" style="color:#86efac"><?= (int)$kpi['active_subs'] ?></div>
            <div class="ceo-hero-stat-lbl accent">Active Subs</div>
        </div>
        <div class="ceo-hero-stat">
            <div class="ceo-hero-stat-val" style="color:#c4b5fd"><?= (int)$kpi['lifetime'] ?></div>
            <div class="ceo-hero-stat-lbl">Lifetime</div>
        </div>
        <div class="ceo-hero-stat">
            <div class="ceo-hero-stat-val" style="color:#fcd34d"><?= (int)$kpi['on_trial'] ?></div>
            <div class="ceo-hero-stat-lbl">On Trial</div>
        </div>
        <div class="ceo-hero-stat">
            <div class="ceo-hero-stat-val <?= $kpi['expiring_10']>0?'warn':'' ?>"><?= (int)$kpi['expiring_10'] ?></div>
            <div class="ceo-hero-stat-lbl">Expiring 10d</div>
        </div>
        <div class="ceo-hero-stat">
            <div class="ceo-hero-stat-val <?= $pending_ct>0?'warn':'' ?>"><?= $pending_ct ?></div>
            <div class="ceo-hero-stat-lbl">Pending Pay</div>
        </div>
        <div class="ceo-hero-stat">
            <div class="ceo-hero-stat-val <?= $kpi['expired']>0?'danger':'' ?>"><?= (int)$kpi['expired'] ?></div>
            <div class="ceo-hero-stat-lbl">Expired</div>
        </div>
        <div class="ceo-hero-stat">
            <div class="ceo-hero-stat-val <?= $kpi['suspended']>0?'danger':'' ?>"><?= (int)$kpi['suspended'] ?></div>
            <div class="ceo-hero-stat-lbl">Suspended</div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     URGENT ALERTS (only shown if there are issues)
══════════════════════════════════════════════════════════════ -->
<?php
$urgents = [];
if ($kpi['expiring_10'] > 0) $urgents[] = ['⏰', (int)$kpi['expiring_10'].' farm'.($kpi['expiring_10']>1?'s':'').' expiring within 10 days', '#b45309', '#fffbeb', '#fde68a', '/modules/ceo/subscriptions.php?status=active', 'Extend Now'];
if ($kpi['suspended']   > 0) $urgents[] = ['🔒', (int)$kpi['suspended'].' farm'.($kpi['suspended']>1?'s':'').' currently suspended', '#b91c1c', '#fef2f2', '#fecaca', '/modules/ceo/subscriptions.php?status=suspended', 'Review'];
if ($pending_ct         > 0) $urgents[] = ['💳', $pending_ct.' payment request'.($pending_ct>1?'s':'').' waiting for approval — ৳'.number_format($pending_amt,2), '#0369a1', '#eff6ff', '#bae6fd', '/modules/super_admin/payments.php', 'Approve'];
?>
<?php if (!empty($urgents)): ?>
<div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:1.25rem">
<?php foreach ($urgents as [$icon,$msg,$col,$bg,$bdr,$url,$cta]): ?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.65rem 1rem;background:<?= $bg ?>;border:1px solid <?= $bdr ?>;border-radius:var(--radius);border-left:4px solid <?= $col ?>">
    <div style="display:flex;align-items:center;gap:.6rem;font-size:.84rem;font-weight:600;color:<?= $col ?>">
        <span style="font-size:1rem;flex-shrink:0"><?= $icon ?></span>
        <?= $msg ?>
    </div>
    <a href="<?= e($url) ?>" class="btn btn-xs" style="background:<?= $col ?>;border-color:<?= $col ?>;color:#fff;flex-shrink:0"><?= $cta ?></a>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     REVENUE TILES
══════════════════════════════════════════════════════════════ -->
<p class="dash-section">Revenue</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.75rem;margin-bottom:1.5rem">

    <div class="rev-tile" style="border-top:3px solid #16a34a">
        <div class="rev-tile-icon" style="background:#f0fdf4">💰</div>
        <div>
            <div class="rev-tile-lbl">This Month</div>
            <div class="rev-tile-val" style="color:#16a34a">৳<?= number_format($rev_month,2) ?></div>
            <div class="rev-tile-sub"><?= date('F Y') ?></div>
        </div>
    </div>

    <div class="rev-tile" style="border-top:3px solid var(--info)">
        <div class="rev-tile-icon" style="background:#eff6ff">📊</div>
        <div>
            <div class="rev-tile-lbl">This Year</div>
            <div class="rev-tile-val" style="color:var(--info)">৳<?= number_format($rev_year,2) ?></div>
            <div class="rev-tile-sub">Jan – <?= date('M Y') ?></div>
        </div>
    </div>

    <div class="rev-tile" style="border-top:3px solid #7c3aed">
        <div class="rev-tile-icon" style="background:#f5f3ff">💎</div>
        <div>
            <div class="rev-tile-lbl">All-Time</div>
            <div class="rev-tile-val" style="color:#7c3aed">৳<?= number_format($rev_total,2) ?></div>
            <div class="rev-tile-sub">Cumulative total</div>
        </div>
    </div>

    <div class="rev-tile" style="border-top:3px solid #059669">
        <div class="rev-tile-icon" style="background:#ecfdf5">⚡</div>
        <div>
            <div class="rev-tile-lbl">Today</div>
            <div class="rev-tile-val" style="color:#059669">৳<?= number_format($today_rev,2) ?></div>
            <div class="rev-tile-sub">Completed today</div>
        </div>
    </div>

    <div class="rev-tile" style="border-top:3px solid <?= $pending_ct>0?'#d97706':'var(--border)' ?>">
        <div class="rev-tile-icon" style="background:#fffbeb">⏳</div>
        <div>
            <div class="rev-tile-lbl">Pending</div>
            <div class="rev-tile-val" style="color:<?= $pending_ct>0?'#d97706':'var(--text-secondary)' ?>">৳<?= number_format($pending_amt,2) ?></div>
            <div class="rev-tile-sub"><?= $pending_ct ?> request<?= $pending_ct!=1?'s':'' ?> awaiting</div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════
     SUBSCRIPTION METRICS
══════════════════════════════════════════════════════════════ -->
<p class="dash-section">Subscriptions</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:.6rem;margin-bottom:1.5rem">
<?php
$metrics = [
    ['🏠', 'Total Farms',   $kpi['total_farms'],   '#0284c7', '#eff6ff', false],
    ['✅', 'Active Subs',   $kpi['active_subs'],   '#16a34a', '#f0fdf4', false],
    ['♾',  'Lifetime',      $kpi['lifetime'],       '#7c3aed', '#f5f3ff', false],
    ['🎁', 'Free Members',  $kpi['free_members'],   '#6b7280', '#f9fafb', false],
    ['🔄', 'On Trial',      $kpi['on_trial'],       '#d97706', '#fffbeb', false],
    ['📅', 'Expiring 30d',  $kpi['expiring_30'],    '#eab308', '#fefce8', $kpi['expiring_30']>0],
    ['⏰', 'Expiring 10d',  $kpi['expiring_10'],    '#f97316', '#fff7ed', $kpi['expiring_10']>0],
    ['⛔', 'Expired',       $kpi['expired'],        '#dc2626', '#fef2f2', $kpi['expired']>0],
    ['🔒', 'Suspended',     $kpi['suspended'],      '#991b1b', '#fef2f2', $kpi['suspended']>0],
];
foreach ($metrics as [$icon,$lbl,$val,$col,$bg,$urgent]):
?>
<div class="metric-card <?= $urgent?'urgent':'' ?>" style="background:<?= $bg ?>;<?= $urgent?'color:'.$col.';border-color:'.$col.'40':'' ?>">
    <div class="metric-icon"><?= $icon ?></div>
    <div class="metric-val" style="color:<?= $col ?>"><?= number_format((int)$val) ?></div>
    <div class="metric-lbl"><?= $lbl ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     QUICK ACTIONS
══════════════════════════════════════════════════════════════ -->
<p class="dash-section">Quick Actions</p>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.6rem;margin-bottom:1.5rem">
<?php
$actions = [
    ['🎟', 'Grant Free Access',    '#16a34a', '#f0fdf4', '/modules/ceo/subscriptions.php',                  '1 month – lifetime for any farm'],
    ['♾',  'Lifetime Members',     '#7c3aed', '#f5f3ff', '/modules/ceo/subscriptions.php?status=lifetime',  (int)$kpi['lifetime'].' members'],
    ['🔁', 'Reactivate Expired',   '#d97706', '#fffbeb', '/modules/ceo/subscriptions.php?status=expired',   (int)$kpi['expired'].' expired farms'],
    ['🔒', 'Suspended Farms',      '#dc2626', '#fef2f2', '/modules/ceo/subscriptions.php?status=suspended', (int)$kpi['suspended'].' suspended'],
    ['💲', 'Plans & Pricing',      '#0284c7', '#eff6ff', '/modules/ceo/plans.php',                          'Edit prices & limits'],
    ['🏷', 'Coupon Codes',         '#7c3aed', '#f5f3ff', '/modules/ceo/coupons.php',                        $coupon_stats['active'].' active coupons'],
    ['💳', 'Pending Payments',     '#d97706', '#fffbeb', '/modules/super_admin/payments.php',               $pending_ct.' need approval'],
    ['📜', 'Audit Log',            '#475569', '#f8fafc', '/modules/ceo/audit.php',                          'Full action history'],
    ['🏘', 'All Farms',            '#1B4332', '#f0fdf4', '/modules/super_admin/index.php',                  'View & manage all farms'],
    ['📈', 'Revenue Report',       '#059669', '#ecfdf5', '/modules/super_admin/revenue.php',                'Detailed revenue breakdown'],
];
foreach ($actions as [$icon,$title,$col,$bg,$url,$sub]):
?>
<a href="<?= e($url) ?>" class="qa-card">
    <div class="qa-card-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><?= $icon ?></div>
    <div>
        <div class="qa-card-title" style="color:<?= $col ?>"><?= $title ?></div>
        <div class="qa-card-sub"><?= e($sub) ?></div>
    </div>
</a>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     3-COL: Expiring 10d | Plan Distribution + Coupons | Revenue Trend
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:1rem;margin-bottom:1rem">

    <!-- Expiring in 10 days -->
    <div class="card">
        <div class="card-header" style="background:#fff7ed;border-bottom-color:#fed7aa">
            <div>
                <div style="font-weight:700;font-size:.9rem;color:#c2410c">⏰ Expiring in 10 Days</div>
                <div style="font-size:.72rem;color:#9a3412"><?= count($expiring_10) ?> farm<?= count($expiring_10)!=1?'s':'' ?></div>
            </div>
            <a href="/modules/ceo/subscriptions.php?status=active" class="btn btn-xs btn-secondary">Manage</a>
        </div>
        <?php if (empty($expiring_10)): ?>
        <div style="padding:2rem;text-align:center">
            <div style="font-size:1.5rem;margin-bottom:.5rem">✅</div>
            <div style="font-size:.83rem;color:var(--text-secondary)">No farms expiring this week</div>
        </div>
        <?php else: ?>
        <?php foreach ($expiring_10 as $ex):
            $dl = (int)$ex['days_left'];
            $dc = $dl <= 1 ? '#dc2626' : ($dl <= 3 ? '#ea580c' : '#d97706');
            $dlabel = $dl <= 0 ? 'TODAY' : ($dl === 1 ? '1 day' : "{$dl}d");
        ?>
        <div class="farm-row">
            <div style="flex:1;min-width:0">
                <div class="farm-name"><?= e($ex['farm_name']) ?></div>
                <div class="farm-meta"><?= e($ex['plan_name']) ?> · <?= e(date('d M', strtotime($ex['end_date']))) ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:.4rem;flex-shrink:0">
                <span style="font-size:.8rem;font-weight:800;color:<?= $dc ?>"><?= $dlabel ?></span>
                <a href="/modules/ceo/subscriptions.php?search=<?= urlencode($ex['farm_name']) ?>"
                   class="btn btn-xs btn-primary" style="font-size:.68rem;padding:.2rem .5rem">Grant</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Plan Distribution + Coupon mini-stats -->
    <div class="card">
        <div class="card-header">
            <div style="font-weight:700;font-size:.9rem">📊 Plan Distribution</div>
            <span style="font-size:.75rem;color:var(--text-secondary)">Active subscriptions</span>
        </div>
        <div style="padding:.75rem 1.1rem">
        <?php
        $ta = max(1, array_sum(array_column($plan_dist,'cnt')));
        if (empty($plan_dist)):
        ?>
        <div style="text-align:center;padding:1rem;color:var(--text-secondary);font-size:.83rem">No active subscriptions yet.</div>
        <?php else: foreach ($plan_dist as $pd):
            $bc = match($pd['name']){'Free'=>'#6b7280','Basic'=>'#0284c7','Pro'=>'#7c3aed','Enterprise'=>'#d97706',default=>'#9ca3af'};
            $w  = round($pd['cnt']/$ta*100);
        ?>
        <div style="margin-bottom:.6rem">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.3rem">
                <span style="font-size:.82rem;font-weight:700;color:<?= $bc ?>"><?= e($pd['name']) ?></span>
                <span style="font-size:.8rem;font-weight:700"><?= $pd['cnt'] ?> <span style="font-weight:400;color:var(--text-secondary)">(<?= $w ?>%)</span></span>
            </div>
            <div style="height:7px;background:var(--border);border-radius:99px;overflow:hidden">
                <div style="height:7px;background:<?= $bc ?>;border-radius:99px;width:<?= $w ?>%"></div>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
        <?php if ($coupon_stats['total'] > 0): ?>
        <div style="border-top:1px solid var(--border);display:grid;grid-template-columns:repeat(3,1fr)">
            <?php foreach ([['Active Coupons',$coupon_stats['active'],'#7c3aed'],['Total Uses',$coupon_stats['used_total'],'#16a34a'],['All Coupons',$coupon_stats['total'],'#0284c7']] as [$cl,$cv,$cc]): ?>
            <div style="text-align:center;padding:.65rem .5rem;border-right:1px solid var(--border)">
                <div style="font-size:1.2rem;font-weight:800;color:<?= $cc ?>"><?= $cv ?></div>
                <div style="font-size:.68rem;color:var(--text-secondary);font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-top:.1rem"><?= $cl ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Revenue Trend -->
    <div class="card">
        <div class="card-header">
            <div style="font-weight:700;font-size:.9rem">📈 Revenue Trend</div>
            <span style="font-size:.75rem;color:var(--text-secondary)">Last 6 months</span>
        </div>
        <div style="padding:.75rem 1.1rem">
        <?php if (empty($rev_trend)): ?>
        <div style="text-align:center;padding:1.5rem;color:var(--text-secondary);font-size:.83rem">No payment data yet.</div>
        <?php else:
            $max_rev = max(array_column($rev_trend,'total')) ?: 1;
            foreach ($rev_trend as $rt):
                $w = round($rt['total']/$max_rev*100);
                $pct = $rt['total']/$max_rev;
                $barCol = $pct >= .75 ? '#16a34a' : ($pct >= .4 ? '#0284c7' : '#94a3b8');
        ?>
        <div class="bar-row">
            <div class="bar-label"><?= e($rt['mo']) ?></div>
            <div class="bar-track">
                <div class="bar-fill" style="width:<?= $w ?>%;background:<?= $barCol ?>"></div>
            </div>
            <div class="bar-val" style="color:<?= $barCol ?>">৳<?= number_format($rt['total'],0) ?></div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════
     2-COL: Pending Payments | Recent CEO Actions
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:1rem;margin-bottom:1rem">

    <!-- Pending Payments -->
    <div class="card">
        <div class="card-header" style="<?= $pending_ct>0?'background:#fffbeb;border-bottom-color:#fde68a':'' ?>">
            <div>
                <div style="font-weight:700;font-size:.9rem;color:<?= $pending_ct>0?'#b45309':'var(--text-primary)' ?>">💳 Pending Payments</div>
                <div style="font-size:.72rem;color:var(--text-secondary)"><?= $pending_ct ?> request<?= $pending_ct!=1?'s':'' ?> · ৳<?= number_format($pending_amt,2) ?></div>
            </div>
            <?php if ($pending_ct > 0): ?>
            <a href="/modules/super_admin/payments.php" class="btn btn-xs" style="background:#d97706;border-color:#d97706;color:#fff">Review All</a>
            <?php endif; ?>
        </div>
        <?php if (empty($pending_list)): ?>
        <div style="padding:2rem;text-align:center">
            <div style="font-size:1.5rem;margin-bottom:.5rem">✅</div>
            <div style="font-size:.83rem;color:var(--text-secondary)">No pending payments right now</div>
        </div>
        <?php else: ?>
        <?php foreach ($pending_list as $py): ?>
        <div class="farm-row">
            <div style="flex:1;min-width:0">
                <div class="farm-name"><?= e($py['farm_name']) ?></div>
                <div class="farm-meta"><?= e($py['plan_name']) ?> · <?= ucfirst(e($py['method'])) ?> · <?= e(date('d M', strtotime($py['created_at']))) ?></div>
            </div>
            <div style="font-size:.88rem;font-weight:800;color:#d97706;flex-shrink:0">৳<?= number_format((float)$py['amount'],2) ?></div>
        </div>
        <?php endforeach; ?>
        <div style="padding:.6rem 1.1rem;border-top:1px solid var(--border)">
            <a href="/modules/super_admin/payments.php" style="font-size:.78rem;color:var(--primary);font-weight:600">View all payments →</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent CEO Actions -->
    <div class="card">
        <div class="card-header">
            <div style="font-weight:700;font-size:.9rem">👑 Recent CEO Actions</div>
            <a href="/modules/ceo/audit.php" class="btn btn-xs btn-secondary">View All</a>
        </div>
        <?php if (empty($recent_grants)): ?>
        <div style="padding:2rem;text-align:center">
            <div style="font-size:1.5rem;margin-bottom:.5rem">📋</div>
            <div style="font-size:.83rem;color:var(--text-secondary)">No CEO actions recorded yet</div>
        </div>
        <?php else: ?>
        <?php foreach ($recent_grants as $g):
            $bg = match($g['action_type']){
                'lifetime'   => 'linear-gradient(135deg,#7c3aed,#db2777)',
                'free_access'=> '#16a34a',
                'plan_change'=> '#0284c7',
                'suspend'    => '#dc2626',
                'reactivate' => '#d97706',
                'extend'     => '#059669',
                default      => '#6b7280'
            };
        ?>
        <div class="farm-row" style="align-items:flex-start">
            <div style="flex:1;min-width:0">
                <div class="farm-name" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($g['farm_name'] ?: 'Farm #'.$g['farm_id']) ?></div>
                <div class="farm-meta">
                    <?= e($g['old_plan_name'] ?: '—') ?> → <strong style="color:var(--text-primary)"><?= e($g['new_plan_name'] ?: '—') ?></strong>
                    <?php if ($g['duration_label']): ?> · <?= e($g['duration_label']) ?><?php endif; ?>
                </div>
                <div style="font-size:.7rem;color:var(--text-secondary);margin-top:.1rem"><?= e(formatDateTime($g['created_at'])) ?></div>
            </div>
            <span class="action-badge" style="background:<?= $bg ?>;margin-top:.15rem;flex-shrink:0">
                <?= $g['is_lifetime'] ? '♾ ' : '' ?><?= ucfirst(str_replace('_',' ',$g['action_type'])) ?>
            </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════════
     Expiring 11–30 days (collapsed by default)
══════════════════════════════════════════════════════════════ -->
<?php if (!empty($expiring_30)): ?>
<div class="card" style="margin-bottom:1rem">
    <div class="card-header" style="background:#fefce8;border-bottom-color:#fde047;cursor:pointer" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none';this.querySelector('.toggle-chv').style.transform=this.nextElementSibling.style.display==='none'?'':'rotate(180deg)'">
        <div>
            <div style="font-weight:700;font-size:.9rem;color:#854d0e">📅 Expiring in 11–30 Days</div>
            <div style="font-size:.72rem;color:#a16207"><?= count($expiring_30) ?> farm<?= count($expiring_30)!=1?'s':'' ?></div>
        </div>
        <div style="display:flex;align-items:center;gap:.5rem">
            <a href="/modules/ceo/subscriptions.php?status=active" class="btn btn-xs btn-secondary" onclick="event.stopPropagation()">Manage</a>
            <span class="toggle-chv" style="font-size:.9rem;color:var(--text-secondary);transition:transform .2s">▼</span>
        </div>
    </div>
    <div style="display:none">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr))">
    <?php foreach ($expiring_30 as $ex): ?>
    <div class="farm-row">
        <div style="flex:1;min-width:0">
            <div class="farm-name"><?= e($ex['farm_name']) ?></div>
            <div class="farm-meta"><?= e($ex['plan_name']) ?> · <?= e(date('d M Y', strtotime($ex['end_date']))) ?></div>
        </div>
        <span style="font-size:.82rem;font-weight:700;color:#92400e;flex-shrink:0"><?= $ex['days_left'] ?>d</span>
    </div>
    <?php endforeach; ?>
    </div>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     INFO BANNERS
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:.75rem">
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--radius-lg);padding:1rem 1.25rem">
        <div style="font-weight:700;color:#1d4ed8;margin-bottom:.5rem;font-size:.85rem;display:flex;align-items:center;gap:.4rem">⏰ Auto-Expiry Warnings</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.2rem .75rem;font-size:.78rem;color:#1e3a8a">
            <?php foreach ([['10 days','First warning'],['7 days','Medium alert'],['3 days','High alert'],['1 day','Critical'],['Today','Expired alert']] as [$w,$d]): ?>
            <div><strong><?= $w ?>:</strong> <?= $d ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:var(--radius-lg);padding:1rem 1.25rem">
        <div style="font-weight:700;color:#7c3aed;margin-bottom:.5rem;font-size:.85rem;display:flex;align-items:center;gap:.4rem">🛡️ CEO Security Rules</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.2rem .75rem;font-size:.78rem;color:#5b21b6">
            <?php foreach (['CEO role cannot be deleted','Only CEO assigns Lifetime plans','Only CEO changes pricing','All actions audit-logged','CEO bypasses all restrictions','Admins cannot grant free access'] as $r): ?>
            <div>✓ <?= $r ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
