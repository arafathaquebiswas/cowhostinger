<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();

$db    = getDB();
$ff    = farmFilter();
$today = date('Y-m-d');
$year  = (int)date('Y');
$month = (int)date('m');

// ── Date ranges ──────────────────────────────────────────────────────────────
$m_from  = date('Y-m-01');
$m_to    = date('Y-m-t');
$lm_from = date('Y-m-01', strtotime('first day of last month'));
$lm_to   = date('Y-m-t',  strtotime('last day of last month'));
$y_from  = date('Y-01-01');
$ly_from = ($year - 1) . '-01-01';
$ly_to   = ($year - 1) . '-12-31';
$w_from  = date('Y-m-d', strtotime('monday this week'));
$lw_from = date('Y-m-d', strtotime('monday last week'));
$lw_to   = date('Y-m-d', strtotime('sunday last week'));

// ── Financial computation ─────────────────────────────────────────────────────
function execFinancials(PDO $db, string $from, string $to): array {
    $ff = farmFilter();

    // Single source of truth: finance_transactions — eliminates double-counting
    $q = $db->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN type='income'  THEN amount ELSE 0 END),0)                                                                                                 AS total_rev,
          COALESCE(SUM(CASE WHEN type='expense' AND category != 'Equipment Purchase' THEN amount ELSE 0 END),0)                                                        AS total_exp_ledger,
          COALESCE(SUM(CASE WHEN type='income'  AND category='Milk Sales'                                                           THEN amount ELSE 0 END),0)         AS rev_milk,
          COALESCE(SUM(CASE WHEN type='income'  AND category IN ('Cow Sale','Cow Sales','Animal Sale')                              THEN amount ELSE 0 END),0)         AS rev_cow,
          COALESCE(SUM(CASE WHEN type='income'  AND category IN ('Meat Sale','Meat Sales')                                         THEN amount ELSE 0 END),0)         AS rev_meat,
          COALESCE(SUM(CASE WHEN type='expense' AND category='Veterinary Treatment'                                                 THEN amount ELSE 0 END),0)         AS exp_vet,
          COALESCE(SUM(CASE WHEN type='expense' AND category IN ('Maintenance','Maintenance Cost','Equipment Maintenance','Equipment Repair') THEN amount ELSE 0 END),0) AS exp_maint,
          COALESCE(SUM(CASE WHEN type='expense' AND category IN ('Salary','Payroll','Worker Salary')                                THEN amount ELSE 0 END),0)         AS exp_salary_ledger
        FROM finance_transactions
        WHERE {$ff} AND DATE(transaction_date) BETWEEN ? AND ?
    ");
    $q->execute([$from, $to]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    $total_rev         = (float)$row['total_rev'];
    $total_exp_ledger  = (float)$row['total_exp_ledger'];
    $rev_milk          = (float)$row['rev_milk'];
    $rev_cow           = (float)$row['rev_cow'];
    $rev_meat          = (float)$row['rev_meat'];
    $rev_other         = max(0.0, $total_rev - $rev_milk - $rev_cow - $rev_meat);
    $exp_vet           = (float)$row['exp_vet'];
    $exp_maint         = (float)$row['exp_maint'];
    $exp_salary_ledger = (float)$row['exp_salary_ledger'];

    // Use prorated worker salaries only when not already recorded in the ledger
    $exp_salary = $exp_salary_ledger;
    if ($exp_salary_ledger == 0.0) {
        $period_days = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
        $wq = $db->prepare("SELECT salary FROM workers WHERE {$ff} AND hire_date <= ? AND (termination_date IS NULL OR termination_date >= ?)");
        $wq->execute([$to, $from]);
        foreach ($wq->fetchAll() as $w) {
            $exp_salary += ((float)$w['salary'] / 30) * $period_days;
        }
    }

    $exp_other = max(0.0, $total_exp_ledger - $exp_vet - $exp_maint - $exp_salary_ledger);
    $total_exp = $total_exp_ledger - $exp_salary_ledger + $exp_salary;
    $net       = $total_rev - $total_exp;

    return compact(
        'rev_milk', 'rev_cow', 'rev_meat', 'rev_other',
        'exp_vet',  'exp_maint', 'exp_salary', 'exp_other',
        'total_rev', 'total_exp', 'net'
    ) + ['margin' => $total_rev > 0 ? round(($net / $total_rev) * 100, 1) : 0];
}

function pctChange(float $old, float $new): ?float {
    return $old == 0 ? null : round((($new - $old) / abs($old)) * 100, 1);
}

// ── Compute all periods ───────────────────────────────────────────────────────
$fin_tm  = execFinancials($db, $m_from, $m_to);
$fin_lm  = execFinancials($db, $lm_from, $lm_to);
$fin_ytd = execFinancials($db, $y_from, $today);
$fin_ly  = execFinancials($db, $ly_from, $ly_to);

// ── Livestock ─────────────────────────────────────────────────────────────────
$q = $db->prepare("SELECT status, COUNT(*) as n FROM cows WHERE {$ff} GROUP BY status");
$q->execute(); $cow_by_status = $q->fetchAll(PDO::FETCH_KEY_PAIR);

$cow_total     = array_sum($cow_by_status);
$cow_active    = ($cow_by_status['active'] ?? 0) + ($cow_by_status['lactating'] ?? 0);
$cow_lactating = $cow_by_status['lactating'] ?? 0;
$cow_pregnant  = ($cow_by_status['pregnant'] ?? 0);
$q2 = $db->prepare("SELECT COUNT(*) FROM cows WHERE {$ff} AND is_pregnant=1 AND status NOT IN ('pregnant')");
$q2->execute(); $cow_pregnant += (int)$q2->fetchColumn();
$cow_sick      = ($cow_by_status['sick'] ?? 0) + ($cow_by_status['quarantine'] ?? 0);
$cow_dry       = $cow_by_status['dry'] ?? 0;

// ── Milk production ───────────────────────────────────────────────────────────
$q = $db->prepare("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE {$ff} AND contamination_flag=0 AND DATE(recorded_at)=?");
$q->execute([$today]); $milk_today = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE {$ff} AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ?");
$q->execute([$m_from, $m_to]); $milk_tm = (float)$q->fetchColumn();
$q->execute([$lm_from, $lm_to]); $milk_lm = (float)$q->fetchColumn();
$q->execute([$w_from, $today]);  $milk_tw = (float)$q->fetchColumn();
$q->execute([$lw_from, $lw_to]); $milk_lw = (float)$q->fetchColumn();

$milk_avg_daily = $milk_tm > 0 ? round($milk_tm / (int)date('j'), 1) : 0;
$milk_per_cow   = $cow_lactating > 0 ? round($milk_tm / $cow_lactating, 1) : 0;
$milk_pct_wk    = pctChange($milk_lw, $milk_tw);

// Top 5 milk cows this month
$ff_c = farmFilter('c');
$q = $db->prepare(
    "SELECT c.tag_number, c.tag_number AS cow_name, COALESCE(SUM(r.liters),0) AS total_liters
     FROM cows c
     LEFT JOIN milk_records r ON r.cow_id=c.id AND r.contamination_flag=0
         AND DATE(r.recorded_at) BETWEEN ? AND ?
     WHERE {$ff_c}
     GROUP BY c.id ORDER BY total_liters DESC LIMIT 5"
);
$q->execute([$m_from, $m_to]); $top_cows = $q->fetchAll();

// ── Alerts ───────────────────────────────────────────────────────────────────
$alerts = [];

// Sick cows
if ($cow_sick > 0) {
    $alerts[] = ['type' => 'critical', 'icon' => '🚨', 'title' => "Sick / Quarantined Cows",
        'body' => "{$cow_sick} cow(s) need immediate attention", 'link' => '/modules/treatments/index.php'];
}

// Low feed stock
$q = $db->prepare("SELECT COUNT(*) FROM feed_inventory WHERE {$ff} AND quantity <= reorder_threshold AND quantity >= 0");
$q->execute(); $low_feed = (int)$q->fetchColumn();
if ($low_feed > 0) {
    $alerts[] = ['type' => 'warning', 'icon' => '📦', 'title' => "Low Feed Stock",
        'body' => "{$low_feed} feed item(s) at or below reorder level", 'link' => '/modules/feed_medicine/index.php'];
}

// Expired medicine
$q = $db->prepare("SELECT COUNT(*) FROM medicine_inventory WHERE {$ff} AND expiry_date < ? AND quantity > 0");
$q->execute([$today]); $exp_meds = (int)$q->fetchColumn();
if ($exp_meds > 0) {
    $alerts[] = ['type' => 'critical', 'icon' => '💊', 'title' => "Expired Medicine",
        'body' => "{$exp_meds} medicine(s) expired — dispose immediately", 'link' => '/modules/feed_medicine/index.php'];
}

// Expiring medicine in 30 days
$q = $db->prepare("SELECT COUNT(*) FROM medicine_inventory WHERE {$ff} AND expiry_date BETWEEN ? AND DATE_ADD(?,INTERVAL 30 DAY) AND quantity > 0");
$q->execute([$today, $today]); $exp_soon = (int)$q->fetchColumn();
if ($exp_soon > 0) {
    $alerts[] = ['type' => 'warning', 'icon' => '⏰', 'title' => "Medicine Expiring Soon",
        'body' => "{$exp_soon} medicine(s) expire within 30 days", 'link' => '/modules/feed_medicine/index.php'];
}

// Low medicine stock
$q = $db->prepare("SELECT COUNT(*) FROM medicine_inventory WHERE {$ff} AND quantity <= reorder_threshold AND quantity >= 0");
$q->execute(); $low_meds = (int)$q->fetchColumn();
if ($low_meds > 0) {
    $alerts[] = ['type' => 'warning', 'icon' => '🩺', 'title' => "Low Medicine Stock",
        'body' => "{$low_meds} medicine(s) below reorder threshold", 'link' => '/modules/feed_medicine/index.php'];
}

// Equipment needs maintenance
$q = $db->prepare("SELECT COUNT(*) FROM equipment WHERE {$ff} AND status IN ('maintenance','damaged')");
$q->execute(); $broken_eq = (int)$q->fetchColumn();
if ($broken_eq > 0) {
    $alerts[] = ['type' => 'info', 'icon' => '🔧', 'title' => "Equipment Needs Attention",
        'body' => "{$broken_eq} equipment item(s) in maintenance/damaged state", 'link' => '/modules/equipment/index.php'];
}

// Loss alert
if ($fin_tm['net'] < 0) {
    $loss = number_format(abs($fin_tm['net']), 0);
    $alerts[] = ['type' => 'critical', 'icon' => '📉', 'title' => "Monthly Net Loss",
        'body' => "Farm is at ৳{$loss} loss this month — review expenses", 'link' => '/modules/finance/profit.php'];
}

// ── Workforce ─────────────────────────────────────────────────────────────────
$q = $db->prepare("SELECT COUNT(*) FROM workers WHERE {$ff} AND (termination_date IS NULL OR termination_date > ?)");
$q->execute([$today]); $worker_count = (int)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(salary),0) FROM workers WHERE {$ff} AND (termination_date IS NULL OR termination_date > ?)");
$q->execute([$today]); $monthly_salary = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COUNT(*) FROM workers WHERE {$ff} AND hire_date BETWEEN ? AND ?");
$q->execute([$m_from, $m_to]); $new_workers = (int)$q->fetchColumn();

// ── 12-month trend (for chart) ────────────────────────────────────────────────
$trend_labels = [];
$trend_rev    = [];
$trend_exp    = [];
$trend_net    = [];
$trend_milk   = [];

for ($i = 11; $i >= 0; $i--) {
    $ts  = strtotime("-{$i} month");
    $mf  = date('Y-m-01', $ts);
    $mt  = date('Y-m-t',  $ts);
    $fin = execFinancials($db, $mf, $mt);

    $q = $db->prepare("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE {$ff} AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ?");
    $q->execute([$mf, $mt]); $lts = (float)$q->fetchColumn();

    $trend_labels[] = date('M y', $ts);
    $trend_rev[]    = round($fin['total_rev'], 2);
    $trend_exp[]    = round($fin['total_exp'], 2);
    $trend_net[]    = round($fin['net'], 2);
    $trend_milk[]   = round($lts, 1);
}

// ── Revenue breakdown for doughnut ───────────────────────────────────────────
$rev_parts = [
    'Milk Sales'  => $fin_ytd['rev_milk'],
    'Cow Sales'   => $fin_ytd['rev_cow'],
    'Meat Sales'  => $fin_ytd['rev_meat'],
    'Other Income'=> $fin_ytd['rev_other'],
];
$rev_parts = array_filter($rev_parts, fn($v) => $v > 0);

// ── Expense breakdown for doughnut ───────────────────────────────────────────
$exp_parts = [
    'Veterinary'  => $fin_ytd['exp_vet'],
    'Maintenance' => $fin_ytd['exp_maint'],
    'Salaries'    => $fin_ytd['exp_salary'],
    'Other'       => $fin_ytd['exp_other'],
];
$exp_parts = array_filter($exp_parts, fn($v) => $v > 0);

// ── Top expense category (for AI insight) ────────────────────────────────────
arsort($exp_parts); $top_exp_cat = array_key_first($exp_parts) ?? '—';
$top_exp_pct = $fin_ytd['total_exp'] > 0
    ? round((reset($exp_parts) / $fin_ytd['total_exp']) * 100, 0) : 0;

// ── Period-over-period change helpers ────────────────────────────────────────
$rev_change = pctChange($fin_lm['total_rev'], $fin_tm['total_rev']);
$exp_change = pctChange($fin_lm['total_exp'], $fin_tm['total_exp']);
$net_change = pctChange(abs($fin_lm['net']), $fin_tm['net']);

// ── AI Insights ──────────────────────────────────────────────────────────────
$ai_insights = [];

// Profit trend
if ($rev_change !== null) {
    $dir  = $fin_tm['total_rev'] > $fin_lm['total_rev'] ? 'up' : 'down';
    $col  = $dir === 'up' ? '#059669' : '#dc2626';
    $icon = $dir === 'up' ? '📈' : '📉';
    $ai_insights[] = [
        'icon' => $icon, 'color' => $col,
        'text' => "Revenue is <strong style='color:{$col}'>" . ($dir === 'up' ? 'up' : 'down')
            . " " . abs($rev_change) . "%</strong> vs last month"
            . ($fin_tm['total_rev'] > 0 ? " (৳" . number_format($fin_tm['total_rev'], 0) . " this month)" : ""),
    ];
}

// Net profit insight
if ($fin_tm['net'] >= 0) {
    $ai_insights[] = [
        'icon' => '✅', 'color' => '#059669',
        'text' => "Farm is <strong style='color:#059669'>profitable</strong> this month with ৳"
            . number_format($fin_tm['net'], 0) . " net profit (" . $fin_tm['margin'] . "% margin)",
    ];
} else {
    $ai_insights[] = [
        'icon' => '⚠️', 'color' => '#dc2626',
        'text' => "Farm is <strong style='color:#dc2626'>at a loss</strong> this month — ৳"
            . number_format(abs($fin_tm['net']), 0) . " more in expenses than revenue",
    ];
}

// Top expense
if ($top_exp_cat !== '—') {
    $ai_insights[] = [
        'icon' => '💡', 'color' => '#d97706',
        'text' => "<strong>{$top_exp_cat}</strong> is your biggest expense category at <strong>{$top_exp_pct}%</strong> of total YTD expenses",
    ];
}

// Milk production
if ($milk_pct_wk !== null) {
    $dir2 = $milk_tw > $milk_lw ? 'higher' : 'lower';
    $col2 = $dir2 === 'higher' ? '#059669' : '#dc2626';
    $ai_insights[] = [
        'icon' => '🥛', 'color' => $col2,
        'text' => "This week's milk collection is <strong style='color:{$col2}'>"
            . abs($milk_pct_wk) . "% {$dir2}</strong> than last week"
            . ($milk_per_cow > 0 ? " — avg {$milk_per_cow}L per lactating cow this month" : ""),
    ];
}

// Sick cow alert insight
if ($cow_sick > 0) {
    $pct = $cow_total > 0 ? round(($cow_sick / $cow_total) * 100, 0) : 0;
    $ai_insights[] = [
        'icon' => '🚨', 'color' => '#dc2626',
        'text' => "<strong style='color:#dc2626'>{$cow_sick} cow(s) ({$pct}% of herd)</strong> are sick or quarantined — immediate action recommended",
    ];
}

// ── Comparison table data ─────────────────────────────────────────────────────
$compare_rows = [
    ['label' => 'Revenue',   'tm' => $fin_tm['total_rev'],  'lm' => $fin_lm['total_rev'],  'ytd' => $fin_ytd['total_rev'],  'ly' => $fin_ly['total_rev']],
    ['label' => 'Expenses',  'tm' => $fin_tm['total_exp'],  'lm' => $fin_lm['total_exp'],  'ytd' => $fin_ytd['total_exp'],  'ly' => $fin_ly['total_exp']],
    ['label' => 'Net Profit','tm' => $fin_tm['net'],        'lm' => $fin_lm['net'],        'ytd' => $fin_ytd['net'],        'ly' => $fin_ly['net']],
    ['label' => 'Margin %',  'tm' => $fin_tm['margin'],     'lm' => $fin_lm['margin'],     'ytd' => $fin_ytd['margin'],     'ly' => $fin_ly['margin'], 'is_pct' => true],
];

// ── Recent transactions (last 10) ─────────────────────────────────────────────
$q = $db->prepare(
    "SELECT transaction_date, category, amount, type, notes AS description
     FROM finance_transactions WHERE {$ff}
     ORDER BY transaction_date DESC, id DESC LIMIT 10"
);
$q->execute();
$recent_txns = $q->fetchAll();

$page_title = 'Executive Dashboard';
$active_nav = 'executive_dashboard';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<style>
/* ── Executive Dashboard Styles ─────────────────────────────────────────── */
.exec-hero {
    background: linear-gradient(135deg, #0f2419 0%, #1B4332 45%, #2D6A4F 100%);
    border-radius: 16px;
    padding: 1.75rem 2rem 1.5rem;
    margin-bottom: 1.25rem;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.exec-hero::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: rgba(255,255,255,.04);
}
.exec-hero::after {
    content: '';
    position: absolute;
    bottom: -40px; left: 30%;
    width: 160px; height: 160px;
    border-radius: 50%;
    background: rgba(255,255,255,.03);
}
.exec-hero-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: .75rem;
}
.exec-hero-title { font-size: 1.45rem; font-weight: 800; letter-spacing: -.02em; line-height: 1.1; }
.exec-hero-sub   { font-size: .8rem; color: rgba(255,255,255,.65); margin-top: .2rem; }
.exec-hero-strip {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: .65rem;
}
.exec-strip-card {
    background: rgba(255,255,255,.09);
    backdrop-filter: blur(6px);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 10px;
    padding: .7rem .9rem;
}
.exec-strip-card .label { font-size: .66rem; text-transform: uppercase; letter-spacing: .07em; color: rgba(255,255,255,.58); margin-bottom: .2rem; }
.exec-strip-card .val   { font-size: 1.1rem; font-weight: 800; color: #fff; }
.exec-strip-card .sub   { font-size: .66rem; color: rgba(255,255,255,.5); margin-top: .12rem; }
.exec-strip-card.green  .val { color: #6ee7b7; }
.exec-strip-card.red    .val { color: #fca5a5; }
.exec-strip-card.amber  .val { color: #fcd34d; }

/* Alerts */
.alert-strip {
    display: flex;
    flex-direction: column;
    gap: .45rem;
    margin-bottom: 1.25rem;
}
.alert-banner {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .65rem 1rem;
    border-radius: 10px;
    border-left: 4px solid transparent;
    font-size: .82rem;
}
.alert-banner.critical { background: #fff1f2; border-color: #ef4444; color: #7f1d1d; }
.alert-banner.warning  { background: #fffbeb; border-color: #f59e0b; color: #78350f; }
.alert-banner.info     { background: #eff6ff; border-color: #3b82f6; color: #1e3a8a; }
.alert-banner .a-icon  { font-size: 1rem; flex-shrink: 0; }
.alert-banner .a-title { font-weight: 700; }
.alert-banner .a-body  { flex: 1; }
.alert-banner a.a-link {
    padding: .25rem .65rem;
    border-radius: 6px;
    font-size: .75rem;
    font-weight: 700;
    text-decoration: none;
    background: currentColor;
    color: inherit;
    opacity: .15;
    flex-shrink: 0;
}
.alert-banner a.a-link:hover { opacity: .25; }

/* Tabs */
.exec-tabs {
    display: flex;
    gap: .35rem;
    overflow-x: auto;
    padding-bottom: .1rem;
    margin-bottom: 1.25rem;
    border-bottom: 2px solid var(--border);
    scrollbar-width: none;
}
.exec-tabs::-webkit-scrollbar { display: none; }
.exec-tab {
    padding: .55rem 1rem;
    border-radius: 8px 8px 0 0;
    border: none;
    background: transparent;
    color: var(--text-secondary);
    font-size: .83rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    transition: all .15s;
}
.exec-tab:hover    { color: var(--primary); background: rgba(45,106,79,.06); }
.exec-tab.active   { color: var(--primary); border-bottom-color: var(--primary); }

/* KPI grid */
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.kpi-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1rem 1.1rem;
    position: relative;
    overflow: hidden;
}
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--kpi-color, var(--primary));
}
.kpi-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-secondary); margin-bottom: .3rem; }
.kpi-value { font-size: 1.35rem; font-weight: 800; color: var(--text-primary); line-height: 1.1; }
.kpi-badge { font-size: .7rem; font-weight: 700; padding: .15rem .45rem; border-radius: 99px; margin-top: .35rem; display: inline-block; }
.kpi-badge.up   { background: #dcfce7; color: #15803d; }
.kpi-badge.down { background: #fee2e2; color: #b91c1c; }
.kpi-badge.flat { background: #f1f5f9; color: #475569; }
.kpi-icon { position: absolute; top: .8rem; right: .9rem; font-size: 1.5rem; opacity: .12; }

/* 2/3-col grids */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr;     gap: 1rem; margin-bottom: 1.25rem; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem; }
.grid-3-1 { display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1.25rem; }
@media(max-width:768px) {
    .grid-2, .grid-3, .grid-3-1 { grid-template-columns: 1fr; }
    .exec-hero-strip { grid-template-columns: repeat(2, 1fr); }
}

/* Cards */
.e-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.e-card-hdr {
    padding: .85rem 1.1rem .6rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.e-card-hdr h4 { font-size: .85rem; font-weight: 700; color: var(--text-primary); margin: 0; }
.e-card-hdr .badge { font-size: .7rem; padding: .2rem .55rem; border-radius: 99px; background: #f1f5f9; color: #475569; }
.e-card-body { padding: 1rem 1.1rem; }

/* Comparison table */
.cmp-table { width: 100%; border-collapse: collapse; font-size: .83rem; }
.cmp-table th { padding: .5rem .75rem; font-size: .68rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); background: #f9fafb; border-bottom: 1px solid var(--border); }
.cmp-table td { padding: .55rem .75rem; border-bottom: 1px solid #f1f5f9; }
.cmp-table tr:last-child td { border-bottom: none; }
.cmp-table .val-up   { color: #059669; font-weight: 700; }
.cmp-table .val-down { color: #dc2626; font-weight: 700; }
.cmp-table .val-zero { color: #6b7280; }
.cmp-table .row-label { font-weight: 600; color: var(--text-primary); }

/* AI Insights */
.ai-card { display: flex; align-items: flex-start; gap: .7rem; padding: .7rem .9rem; border-radius: 10px; background: #f9fafb; border: 1px solid #f1f5f9; margin-bottom: .5rem; font-size: .82rem; }
.ai-card:last-child { margin-bottom: 0; }
.ai-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: .05rem; }

/* Top cows */
.cow-rank-row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid #f1f5f9; }
.cow-rank-row:last-child { border-bottom: none; }
.cow-rank-num { width: 24px; height: 24px; border-radius: 50%; background: var(--primary); color: #fff; font-size: .7rem; font-weight: 800; display: grid; place-items: center; flex-shrink: 0; }
.cow-rank-bar-wrap { flex: 1; height: 6px; background: #e5e7eb; border-radius: 99px; overflow: hidden; }
.cow-rank-bar { height: 100%; background: var(--primary); border-radius: 99px; }
.cow-rank-val { font-size: .78rem; font-weight: 700; color: var(--text-primary); white-space: nowrap; }

/* Chart containers */
.chart-wrap { position: relative; height: 240px; }
.chart-wrap-sm { position: relative; height: 180px; }

/* Status pills */
.status-row { display: flex; justify-content: space-between; align-items: center; padding: .45rem 0; border-bottom: 1px solid #f9fafb; font-size: .82rem; }
.status-row:last-child { border-bottom: none; }
.status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: .4rem; }

/* Txn list */
.txn-row { display: flex; align-items: center; gap: .6rem; padding: .55rem 0; border-bottom: 1px solid #f9fafb; font-size: .81rem; }
.txn-row:last-child { border-bottom: none; }
.txn-badge { padding: .15rem .45rem; border-radius: 5px; font-size: .7rem; font-weight: 700; flex-shrink: 0; }
.txn-badge.income  { background: #dcfce7; color: #15803d; }
.txn-badge.expense { background: #fee2e2; color: #b91c1c; }

.exec-tab-section { display: none; }
.exec-tab-section.active { display: block; }

.section-title { font-size: .95rem; font-weight: 800; color: var(--text-primary); margin: 0 0 .75rem; display: flex; align-items: center; gap: .4rem; }
</style>

<!-- ── Hero Banner ─────────────────────────────────────────────────────────── -->
<div class="exec-hero">
    <div class="exec-hero-top">
        <div>
            <div class="exec-hero-title">Executive Dashboard</div>
            <div class="exec-hero-sub"><?= date('l, d F Y') ?> &nbsp;·&nbsp; <?= e(currentFarm()['farm_name'] ?? 'Farm') ?></div>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <a href="/modules/finance/profit.php" style="padding:.4rem .9rem;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:8px;color:#fff;text-decoration:none;font-size:.78rem;font-weight:700">Financial Overview ↗</a>
            <a href="/modules/milk/analytics.php" style="padding:.4rem .9rem;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:8px;color:#fff;text-decoration:none;font-size:.78rem;font-weight:700">Milk Analytics ↗</a>
        </div>
    </div>
    <div class="exec-hero-strip">
        <div class="exec-strip-card <?= $fin_tm['total_rev'] > 0 ? 'green' : '' ?>">
            <div class="label">Revenue (Month)</div>
            <div class="val">৳<?= number_format($fin_tm['total_rev'], 0) ?></div>
            <div class="sub"><?php if ($rev_change !== null) echo ($rev_change >= 0 ? '▲' : '▼') . ' ' . abs($rev_change) . '% vs last month'; else echo 'No prior data'; ?></div>
        </div>
        <div class="exec-strip-card <?= $fin_tm['total_exp'] > 0 ? 'amber' : '' ?>">
            <div class="label">Expenses (Month)</div>
            <div class="val">৳<?= number_format($fin_tm['total_exp'], 0) ?></div>
            <div class="sub"><?php $ec = pctChange($fin_lm['total_exp'], $fin_tm['total_exp']); echo $ec !== null ? (($ec >= 0 ? '▲' : '▼') . ' ' . abs($ec) . '% vs last month') : 'No prior data'; ?></div>
        </div>
        <div class="exec-strip-card <?= $fin_tm['net'] >= 0 ? 'green' : 'red' ?>">
            <div class="label">Net Profit</div>
            <div class="val">৳<?= number_format($fin_tm['net'], 0) ?></div>
            <div class="sub"><?= $fin_tm['margin'] ?>% margin</div>
        </div>
        <div class="exec-strip-card">
            <div class="label">Today's Milk</div>
            <div class="val"><?= number_format($milk_today, 1) ?>L</div>
            <div class="sub">Avg <?= $milk_avg_daily ?>L/day this month</div>
        </div>
        <div class="exec-strip-card">
            <div class="label">Total Herd</div>
            <div class="val"><?= $cow_total ?></div>
            <div class="sub"><?= $cow_lactating ?> lactating</div>
        </div>
        <div class="exec-strip-card <?= $cow_sick > 0 ? 'red' : 'green' ?>">
            <div class="label">Health Status</div>
            <div class="val"><?= $cow_sick > 0 ? $cow_sick . ' Sick' : 'All OK' ?></div>
            <div class="sub"><?= $cow_sick > 0 ? 'Needs attention' : $cow_total . ' healthy' ?></div>
        </div>
        <div class="exec-strip-card">
            <div class="label">YTD Revenue</div>
            <div class="val">৳<?= number_format($fin_ytd['total_rev'], 0) ?></div>
            <div class="sub">Net ৳<?= number_format($fin_ytd['net'], 0) ?></div>
        </div>
        <div class="exec-strip-card">
            <div class="label">Workforce</div>
            <div class="val"><?= $worker_count ?></div>
            <div class="sub">৳<?= number_format($monthly_salary, 0) ?>/mo salary</div>
        </div>
    </div>
</div>

<?php if (!empty($alerts)): ?>
<!-- ── Alerts ─────────────────────────────────────────────────────────────── -->
<div class="alert-strip">
    <?php foreach (array_slice($alerts, 0, 5) as $al): ?>
    <div class="alert-banner <?= e($al['type']) ?>">
        <span class="a-icon"><?= $al['icon'] ?></span>
        <span class="a-body"><span class="a-title"><?= e($al['title']) ?></span> — <?= e($al['body']) ?></span>
        <a href="<?= e($al['link']) ?>" class="a-link">View →</a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Tab Navigation ────────────────────────────────────────────────────── -->
<div class="exec-tabs">
    <button class="exec-tab active" onclick="switchTab('overview',this)">📊 Overview</button>
    <button class="exec-tab" onclick="switchTab('financial',this)">💰 Financial Intelligence</button>
    <button class="exec-tab" onclick="switchTab('livestock',this)">🐄 Livestock Analytics</button>
    <button class="exec-tab" onclick="switchTab('production',this)">🥛 Production</button>
    <button class="exec-tab" onclick="switchTab('workforce',this)">👷 Workforce</button>
    <?php if (!empty($alerts)): ?>
    <button class="exec-tab" onclick="switchTab('alerts',this)" style="color:#dc2626">🚨 Alerts (<?= count($alerts) ?>)</button>
    <?php else: ?>
    <button class="exec-tab" onclick="switchTab('alerts',this)">✅ Alerts</button>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: OVERVIEW
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="tab-overview" class="exec-tab-section active">

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card" style="--kpi-color:#059669">
            <div class="kpi-icon">💰</div>
            <div class="kpi-label">Revenue This Month</div>
            <div class="kpi-value">৳<?= number_format($fin_tm['total_rev'], 0) ?></div>
            <?php if ($rev_change !== null): ?>
            <div class="kpi-badge <?= $rev_change >= 0 ? 'up' : 'down' ?>"><?= $rev_change >= 0 ? '▲' : '▼' ?> <?= abs($rev_change) ?>% MoM</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card" style="--kpi-color:#f59e0b">
            <div class="kpi-icon">📤</div>
            <div class="kpi-label">Expenses This Month</div>
            <div class="kpi-value">৳<?= number_format($fin_tm['total_exp'], 0) ?></div>
            <?php $ec = pctChange($fin_lm['total_exp'], $fin_tm['total_exp']); if ($ec !== null): ?>
            <div class="kpi-badge <?= $ec >= 0 ? 'down' : 'up' ?>"><?= $ec >= 0 ? '▲' : '▼' ?> <?= abs($ec) ?>%</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card" style="--kpi-color:<?= $fin_tm['net'] >= 0 ? '#059669' : '#dc2626' ?>">
            <div class="kpi-icon"><?= $fin_tm['net'] >= 0 ? '📈' : '📉' ?></div>
            <div class="kpi-label">Net Profit / Loss</div>
            <div class="kpi-value" style="color:<?= $fin_tm['net'] >= 0 ? '#059669' : '#dc2626' ?>">৳<?= number_format($fin_tm['net'], 0) ?></div>
            <div class="kpi-badge <?= $fin_tm['margin'] >= 0 ? 'up' : 'down' ?>"><?= $fin_tm['margin'] ?>% margin</div>
        </div>
        <div class="kpi-card" style="--kpi-color:#3b82f6">
            <div class="kpi-icon">📊</div>
            <div class="kpi-label">YTD Revenue</div>
            <div class="kpi-value">৳<?= number_format($fin_ytd['total_rev'], 0) ?></div>
            <div class="kpi-badge <?= $fin_ytd['net'] >= 0 ? 'up' : 'down' ?>">Net ৳<?= number_format($fin_ytd['net'], 0) ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#8b5cf6">
            <div class="kpi-icon">🥛</div>
            <div class="kpi-label">Milk This Month</div>
            <div class="kpi-value"><?= number_format($milk_tm, 0) ?>L</div>
            <?php $mc = pctChange($milk_lm, $milk_tm); if ($mc !== null): ?>
            <div class="kpi-badge <?= $mc >= 0 ? 'up' : 'down' ?>"><?= $mc >= 0 ? '▲' : '▼' ?> <?= abs($mc) ?>%</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card" style="--kpi-color:#06b6d4">
            <div class="kpi-icon">🐄</div>
            <div class="kpi-label">Total Herd</div>
            <div class="kpi-value"><?= $cow_total ?></div>
            <div class="kpi-badge flat"><?= $cow_lactating ?> milking</div>
        </div>
        <div class="kpi-card" style="--kpi-color:<?= $cow_sick > 0 ? '#dc2626' : '#059669' ?>">
            <div class="kpi-icon">🩺</div>
            <div class="kpi-label">Sick / Quarantined</div>
            <div class="kpi-value" style="color:<?= $cow_sick > 0 ? '#dc2626' : '#059669' ?>"><?= $cow_sick ?></div>
            <div class="kpi-badge <?= $cow_sick > 0 ? 'down' : 'up' ?>"><?= $cow_sick > 0 ? 'Action needed' : 'Healthy herd' ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#f97316">
            <div class="kpi-icon">👷</div>
            <div class="kpi-label">Active Workers</div>
            <div class="kpi-value"><?= $worker_count ?></div>
            <div class="kpi-badge flat">৳<?= number_format($monthly_salary, 0) ?>/mo</div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Comparison Table -->
        <div class="e-card">
            <div class="e-card-hdr">
                <h4>📋 Period Comparison</h4>
                <span class="badge">Financial</span>
            </div>
            <div style="overflow-x:auto">
                <table class="cmp-table">
                    <thead>
                        <tr>
                            <th style="text-align:left">Metric</th>
                            <th>This Month</th>
                            <th>Last Month</th>
                            <th>YTD</th>
                            <th>Last Year</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($compare_rows as $cr):
                        $is_pct = $cr['is_pct'] ?? false;
                        $fmt    = fn($v) => $is_pct ? number_format($v, 1) . '%' : '৳' . number_format($v, 0);
                        $cls    = fn($v) => $v > 0 ? 'val-up' : ($v < 0 ? 'val-down' : 'val-zero');
                    ?>
                    <tr>
                        <td class="row-label"><?= e($cr['label']) ?></td>
                        <td class="<?= $cls($cr['tm']) ?>"><?= $fmt($cr['tm']) ?></td>
                        <td class="<?= $cls($cr['lm']) ?>"><?= $fmt($cr['lm']) ?></td>
                        <td class="<?= $cls($cr['ytd']) ?>"><?= $fmt($cr['ytd']) ?></td>
                        <td class="<?= $cls($cr['ly']) ?>"><?= $fmt($cr['ly']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- AI Insights -->
        <div class="e-card">
            <div class="e-card-hdr">
                <h4>🤖 AI Insights</h4>
                <span class="badge">Auto-generated</span>
            </div>
            <div class="e-card-body">
                <?php if (empty($ai_insights)): ?>
                <p style="color:var(--text-secondary);font-size:.83rem">Not enough data to generate insights yet.</p>
                <?php else: ?>
                <?php foreach ($ai_insights as $ins): ?>
                <div class="ai-card">
                    <span class="ai-icon"><?= $ins['icon'] ?></span>
                    <span style="line-height:1.5"><?= $ins['text'] ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Revenue Trend (12 months) -->
    <div class="e-card" style="margin-bottom:1.25rem">
        <div class="e-card-hdr">
            <h4>📈 12-Month Revenue vs Expense Trend</h4>
            <span class="badge">Chart</span>
        </div>
        <div class="e-card-body">
            <div class="chart-wrap">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="e-card">
        <div class="e-card-hdr">
            <h4>🧾 Recent Finance Entries</h4>
            <a href="/modules/finance/index.php" style="font-size:.75rem;color:var(--primary);font-weight:700;text-decoration:none">View All →</a>
        </div>
        <div class="e-card-body" style="padding-top:.5rem">
            <?php if (empty($recent_txns)): ?>
            <p style="color:var(--text-secondary);font-size:.83rem;text-align:center;padding:1rem">No transactions recorded yet.</p>
            <?php else: ?>
            <?php foreach ($recent_txns as $txn): ?>
            <div class="txn-row">
                <span class="txn-badge <?= $txn['type'] === 'income' ? 'income' : 'expense' ?>"><?= $txn['type'] === 'income' ? '+' : '-' ?></span>
                <span style="flex:1;color:var(--text-primary)"><?= e($txn['category'] ?: ($txn['description'] ?: '—')) ?></span>
                <span style="font-weight:700;color:<?= $txn['type'] === 'income' ? '#059669' : '#dc2626' ?>">৳<?= number_format($txn['amount'], 0) ?></span>
                <span style="color:var(--text-secondary);font-size:.75rem;white-space:nowrap"><?= date('d M', strtotime($txn['transaction_date'])) ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: FINANCIAL INTELLIGENCE
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="tab-financial" class="exec-tab-section">

    <!-- Revenue / Expense breakdown charts -->
    <div class="grid-3" style="margin-bottom:1.25rem">
        <div class="e-card" style="grid-column:span 1">
            <div class="e-card-hdr"><h4>💵 Revenue Breakdown (YTD)</h4></div>
            <div class="e-card-body">
                <div class="chart-wrap-sm"><canvas id="revDonut"></canvas></div>
                <div style="margin-top:.75rem">
                <?php foreach ($rev_parts as $label => $val): ?>
                <div class="status-row">
                    <span><?= e($label) ?></span>
                    <span style="font-weight:700">৳<?= number_format($val, 0) ?></span>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="e-card" style="grid-column:span 1">
            <div class="e-card-hdr"><h4>📤 Expense Breakdown (YTD)</h4></div>
            <div class="e-card-body">
                <div class="chart-wrap-sm"><canvas id="expDonut"></canvas></div>
                <div style="margin-top:.75rem">
                <?php foreach ($exp_parts as $label => $val): ?>
                <div class="status-row">
                    <span><?= e($label) ?></span>
                    <span style="font-weight:700">৳<?= number_format($val, 0) ?></span>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="e-card" style="grid-column:span 1">
            <div class="e-card-hdr"><h4>📊 Net Profit Trend</h4></div>
            <div class="e-card-body">
                <div class="chart-wrap-sm"><canvas id="netChart"></canvas></div>
                <div style="margin-top:.75rem">
                    <div class="status-row"><span>This Month</span><span style="font-weight:700;color:<?= $fin_tm['net']>=0?'#059669':'#dc2626'?>">৳<?= number_format($fin_tm['net'],0) ?></span></div>
                    <div class="status-row"><span>Last Month</span><span style="font-weight:700;color:<?= $fin_lm['net']>=0?'#059669':'#dc2626'?>">৳<?= number_format($fin_lm['net'],0) ?></span></div>
                    <div class="status-row"><span>YTD Total</span><span style="font-weight:700;color:<?= $fin_ytd['net']>=0?'#059669':'#dc2626'?>">৳<?= number_format($fin_ytd['net'],0) ?></span></div>
                    <div class="status-row"><span>Last Year</span><span style="font-weight:700;color:<?= $fin_ly['net']>=0?'#059669':'#dc2626'?>">৳<?= number_format($fin_ly['net'],0) ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Revenue sources detail this month -->
    <div class="grid-2">
        <div class="e-card">
            <div class="e-card-hdr"><h4>Revenue Sources — This Month</h4></div>
            <div class="e-card-body">
                <?php
                $rev_tm_parts = [
                    '🥛 Milk Sales'  => $fin_tm['rev_milk'],
                    '🐄 Cow Sales'   => $fin_tm['rev_cow'],
                    '🥩 Meat Sales'  => $fin_tm['rev_meat'],
                    '💼 Other Income'=> $fin_tm['rev_other'],
                ];
                $total_tm_rev = max(0.01, $fin_tm['total_rev']);
                foreach ($rev_tm_parts as $lbl => $val):
                    $pct = round(($val / $total_tm_rev) * 100, 0);
                ?>
                <div style="margin-bottom:.8rem">
                    <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.25rem">
                        <span><?= $lbl ?></span>
                        <span style="font-weight:700">৳<?= number_format($val, 0) ?> <span style="color:var(--text-secondary);font-weight:400">(<?= $pct ?>%)</span></span>
                    </div>
                    <div style="height:5px;background:#e5e7eb;border-radius:99px;overflow:hidden">
                        <div style="width:<?= $pct ?>%;height:100%;background:#2D6A4F;border-radius:99px"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="e-card">
            <div class="e-card-hdr"><h4>Expense Sources — This Month</h4></div>
            <div class="e-card-body">
                <?php
                $exp_tm_parts = [
                    '🩺 Veterinary'   => $fin_tm['exp_vet'],
                    '🔧 Maintenance'  => $fin_tm['exp_maint'],
                    '👷 Salaries'     => $fin_tm['exp_salary'],
                    '📋 Other'        => $fin_tm['exp_other'],
                ];
                $total_tm_exp = max(0.01, $fin_tm['total_exp']);
                foreach ($exp_tm_parts as $lbl => $val):
                    $pct = round(($val / $total_tm_exp) * 100, 0);
                ?>
                <div style="margin-bottom:.8rem">
                    <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.25rem">
                        <span><?= $lbl ?></span>
                        <span style="font-weight:700">৳<?= number_format($val, 0) ?> <span style="color:var(--text-secondary);font-weight:400">(<?= $pct ?>%)</span></span>
                    </div>
                    <div style="height:5px;background:#e5e7eb;border-radius:99px;overflow:hidden">
                        <div style="width:<?= $pct ?>%;height:100%;background:#f59e0b;border-radius:99px"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: LIVESTOCK ANALYTICS
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="tab-livestock" class="exec-tab-section">

    <div class="grid-2">
        <!-- Herd Status Breakdown -->
        <div class="e-card">
            <div class="e-card-hdr"><h4>🐄 Herd Status Breakdown</h4><span class="badge"><?= $cow_total ?> total</span></div>
            <div class="e-card-body">
                <div class="chart-wrap-sm"><canvas id="herdDonut"></canvas></div>
                <div style="margin-top:.75rem">
                <?php
                $status_colors = [
                    'active'     => ['color' => '#2D6A4F', 'label' => 'Active'],
                    'lactating'  => ['color' => '#059669', 'label' => 'Lactating'],
                    'dry'        => ['color' => '#d97706', 'label' => 'Dry'],
                    'pregnant'   => ['color' => '#8b5cf6', 'label' => 'Pregnant'],
                    'sick'       => ['color' => '#ef4444', 'label' => 'Sick'],
                    'quarantine' => ['color' => '#dc2626', 'label' => 'Quarantine'],
                    'sold'       => ['color' => '#6b7280', 'label' => 'Sold'],
                    'deceased'   => ['color' => '#374151', 'label' => 'Deceased'],
                ];
                foreach ($cow_by_status as $status => $cnt):
                    if ($cnt == 0) continue;
                    $info = $status_colors[$status] ?? ['color' => '#94a3b8', 'label' => ucfirst($status)];
                ?>
                <div class="status-row">
                    <span><span class="status-dot" style="background:<?= $info['color'] ?>"></span><?= $info['label'] ?></span>
                    <span>
                        <span style="font-weight:700"><?= $cnt ?></span>
                        <span style="color:var(--text-secondary);font-size:.75rem"> (<?= $cow_total>0 ? round($cnt/$cow_total*100,0) : 0 ?>%)</span>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if ($cow_pregnant > ($cow_by_status['pregnant']??0)): ?>
                <div class="status-row">
                    <span><span class="status-dot" style="background:#8b5cf6"></span>Also Pregnant</span>
                    <span style="font-weight:700"><?= $cow_pregnant - ($cow_by_status['pregnant']??0) ?></span>
                </div>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top 5 Milk Cows -->
        <div class="e-card">
            <div class="e-card-hdr"><h4>🏆 Top 5 Milk Producers (This Month)</h4></div>
            <div class="e-card-body">
                <?php if (empty($top_cows) || $top_cows[0]['total_liters'] == 0): ?>
                <p style="color:var(--text-secondary);font-size:.83rem;text-align:center;padding:1rem">No milk records this month.</p>
                <?php else:
                    $max_liters = (float)$top_cows[0]['total_liters'];
                    foreach ($top_cows as $i => $tc):
                        $pct = $max_liters > 0 ? round(($tc['total_liters'] / $max_liters) * 100, 0) : 0;
                ?>
                <div class="cow-rank-row">
                    <div class="cow-rank-num"><?= $i + 1 ?></div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:.8rem;font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?= e($tc['cow_name'] ?: $tc['tag_number'] ?: 'Unknown') ?>
                            <?php if ($tc['tag_number']): ?><span style="color:var(--text-secondary);font-weight:400;font-size:.73rem">(<?= e($tc['tag_number']) ?>)</span><?php endif; ?>
                        </div>
                        <div class="cow-rank-bar-wrap" style="margin-top:.3rem">
                            <div class="cow-rank-bar" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <div class="cow-rank-val"><?= number_format($tc['total_liters'], 1) ?>L</div>
                </div>
                <?php endforeach; endif; ?>
                <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;font-size:.8rem">
                    <span style="color:var(--text-secondary)">Avg per lactating cow</span>
                    <span style="font-weight:700"><?= $milk_per_cow ?>L / month</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Cow Summary Stats -->
    <div class="kpi-grid" style="margin-top:0">
        <div class="kpi-card" style="--kpi-color:#2D6A4F">
            <div class="kpi-icon">🐄</div>
            <div class="kpi-label">Total Herd</div>
            <div class="kpi-value"><?= $cow_total ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#059669">
            <div class="kpi-icon">🥛</div>
            <div class="kpi-label">Lactating</div>
            <div class="kpi-value"><?= $cow_lactating ?></div>
            <div class="kpi-badge flat"><?= $cow_total>0?round($cow_lactating/$cow_total*100,0):0 ?>% of herd</div>
        </div>
        <div class="kpi-card" style="--kpi-color:#8b5cf6">
            <div class="kpi-icon">🤰</div>
            <div class="kpi-label">Pregnant</div>
            <div class="kpi-value"><?= $cow_pregnant ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#d97706">
            <div class="kpi-icon">💤</div>
            <div class="kpi-label">Dry Period</div>
            <div class="kpi-value"><?= $cow_dry ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:<?= $cow_sick > 0 ? '#dc2626' : '#059669' ?>">
            <div class="kpi-icon">🩺</div>
            <div class="kpi-label">Sick / Quarantined</div>
            <div class="kpi-value" style="color:<?= $cow_sick>0?'#dc2626':'#6b7280' ?>"><?= $cow_sick ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#6b7280">
            <div class="kpi-icon">🏷️</div>
            <div class="kpi-label">Active (Non-milk)</div>
            <div class="kpi-value"><?= $cow_by_status['active'] ?? 0 ?></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: PRODUCTION
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="tab-production" class="exec-tab-section">

    <div class="kpi-grid">
        <div class="kpi-card" style="--kpi-color:#059669">
            <div class="kpi-icon">🥛</div>
            <div class="kpi-label">Today's Milk</div>
            <div class="kpi-value"><?= number_format($milk_today, 1) ?>L</div>
        </div>
        <div class="kpi-card" style="--kpi-color:#2D6A4F">
            <div class="kpi-icon">📅</div>
            <div class="kpi-label">This Month Total</div>
            <div class="kpi-value"><?= number_format($milk_tm, 0) ?>L</div>
            <?php $mc2 = pctChange($milk_lm, $milk_tm); if ($mc2 !== null): ?>
            <div class="kpi-badge <?= $mc2>=0?'up':'down' ?>"><?= $mc2>=0?'▲':'▼' ?> <?= abs($mc2) ?>% vs last month</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card" style="--kpi-color:#3b82f6">
            <div class="kpi-icon">📊</div>
            <div class="kpi-label">Daily Average</div>
            <div class="kpi-value"><?= $milk_avg_daily ?>L</div>
            <div class="kpi-badge flat">this month</div>
        </div>
        <div class="kpi-card" style="--kpi-color:#8b5cf6">
            <div class="kpi-icon">🐄</div>
            <div class="kpi-label">Per Cow (Monthly)</div>
            <div class="kpi-value"><?= $milk_per_cow ?>L</div>
            <div class="kpi-badge flat"><?= $cow_lactating ?> lactating cows</div>
        </div>
        <div class="kpi-card" style="--kpi-color:<?= $milk_pct_wk !== null && $milk_pct_wk >= 0 ? '#059669' : '#dc2626' ?>">
            <div class="kpi-icon">📆</div>
            <div class="kpi-label">This Week</div>
            <div class="kpi-value"><?= number_format($milk_tw, 1) ?>L</div>
            <?php if ($milk_pct_wk !== null): ?>
            <div class="kpi-badge <?= $milk_pct_wk>=0?'up':'down' ?>"><?= $milk_pct_wk>=0?'▲':'▼' ?> <?= abs($milk_pct_wk) ?>% vs last week</div>
            <?php endif; ?>
        </div>
        <div class="kpi-card" style="--kpi-color:#6b7280">
            <div class="kpi-icon">📆</div>
            <div class="kpi-label">Last Month Total</div>
            <div class="kpi-value"><?= number_format($milk_lm, 0) ?>L</div>
        </div>
    </div>

    <!-- Milk production 12-month chart -->
    <div class="e-card">
        <div class="e-card-hdr"><h4>🥛 12-Month Milk Production Trend</h4><span class="badge">Liters</span></div>
        <div class="e-card-body">
            <div class="chart-wrap"><canvas id="milkChart"></canvas></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: WORKFORCE
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="tab-workforce" class="exec-tab-section">

    <div class="kpi-grid">
        <div class="kpi-card" style="--kpi-color:#f97316">
            <div class="kpi-icon">👷</div>
            <div class="kpi-label">Active Workers</div>
            <div class="kpi-value"><?= $worker_count ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#ef4444">
            <div class="kpi-icon">💵</div>
            <div class="kpi-label">Monthly Salary Bill</div>
            <div class="kpi-value">৳<?= number_format($monthly_salary, 0) ?></div>
            <div class="kpi-badge flat"><?= $fin_tm['total_rev'] > 0 ? round(($monthly_salary / max(1, $fin_tm['total_rev'])) * 100, 0) : 0 ?>% of monthly rev</div>
        </div>
        <div class="kpi-card" style="--kpi-color:#8b5cf6">
            <div class="kpi-icon">✨</div>
            <div class="kpi-label">New Hires (Month)</div>
            <div class="kpi-value"><?= $new_workers ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#06b6d4">
            <div class="kpi-icon">💰</div>
            <div class="kpi-label">Avg Salary</div>
            <div class="kpi-value">৳<?= $worker_count > 0 ? number_format($monthly_salary / $worker_count, 0) : 0 ?></div>
            <div class="kpi-badge flat">per worker / mo</div>
        </div>
        <div class="kpi-card" style="--kpi-color:#059669">
            <div class="kpi-icon">📊</div>
            <div class="kpi-label">YTD Salary Paid</div>
            <div class="kpi-value">৳<?= number_format($fin_ytd['exp_salary'], 0) ?></div>
        </div>
        <div class="kpi-card" style="--kpi-color:#d97706">
            <div class="kpi-icon">📋</div>
            <div class="kpi-label">Salary % of Expense</div>
            <div class="kpi-value"><?= $fin_ytd['total_exp'] > 0 ? round(($fin_ytd['exp_salary'] / max(1, $fin_ytd['total_exp'])) * 100, 0) : 0 ?>%</div>
            <div class="kpi-badge flat">YTD</div>
        </div>
    </div>

    <div class="e-card">
        <div class="e-card-hdr"><h4>👷 Worker Overview</h4><a href="/modules/workers/index.php" style="font-size:.75rem;color:var(--primary);font-weight:700;text-decoration:none">Manage →</a></div>
        <div class="e-card-body">
            <p style="color:var(--text-secondary);font-size:.85rem">
                Currently <strong><?= $worker_count ?></strong> active worker(s) with a total monthly salary commitment of <strong>৳<?= number_format($monthly_salary, 0) ?></strong>.
                <?php if ($new_workers > 0): ?>
                <strong><?= $new_workers ?></strong> new hire(s) this month.
                <?php endif; ?>
            </p>
            <p style="color:var(--text-secondary);font-size:.83rem;margin-top:.5rem">
                Salaries account for <strong><?= $fin_ytd['total_exp'] > 0 ? round(($fin_ytd['exp_salary'] / max(1, $fin_ytd['total_exp'])) * 100, 0) : 0 ?>%</strong> of total YTD expenses.
                <a href="/modules/workers/index.php" style="color:var(--primary)">View full workforce →</a>
            </p>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: ALERTS
     ═══════════════════════════════════════════════════════════════════════ -->
<div id="tab-alerts" class="exec-tab-section">
    <?php if (empty($alerts)): ?>
    <div class="e-card">
        <div class="e-card-body" style="text-align:center;padding:3rem 1rem">
            <div style="font-size:3rem;margin-bottom:.75rem">✅</div>
            <div style="font-size:1.1rem;font-weight:700;color:var(--text-primary)">All Clear</div>
            <div style="color:var(--text-secondary);font-size:.85rem;margin-top:.35rem">No alerts at this time. Your farm is running smoothly.</div>
        </div>
    </div>
    <?php else: ?>
    <div style="margin-bottom:.75rem;font-size:.82rem;color:var(--text-secondary)"><?= count($alerts) ?> active alert(s) requiring your attention</div>
    <div class="alert-strip">
    <?php foreach ($alerts as $al): ?>
    <div class="alert-banner <?= e($al['type']) ?>" style="padding:.85rem 1.1rem">
        <span class="a-icon" style="font-size:1.4rem"><?= $al['icon'] ?></span>
        <div class="a-body">
            <div class="a-title" style="font-size:.9rem;margin-bottom:.15rem"><?= e($al['title']) ?></div>
            <div style="font-size:.81rem;opacity:.85"><?= e($al['body']) ?></div>
        </div>
        <a href="<?= e($al['link']) ?>" style="padding:.35rem .85rem;border-radius:7px;font-size:.78rem;font-weight:700;text-decoration:none;background:rgba(0,0,0,.08);color:inherit;white-space:nowrap">View →</a>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap">
        <a href="/modules/treatments/index.php" class="btn btn-secondary btn-sm">Vet Records</a>
        <a href="/modules/feed_medicine/index.php" class="btn btn-secondary btn-sm">Feed & Medicine</a>
        <a href="/modules/cows/index.php" class="btn btn-secondary btn-sm">Cow List</a>
        <a href="/modules/equipment/index.php" class="btn btn-secondary btn-sm">Equipment</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
// ── Tab switching ────────────────────────────────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.exec-tab-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.exec-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    // Lazy-init charts when their tab is first shown
    initCharts(name);
}

// ── Chart.js defaults ────────────────────────────────────────────────────────
Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#6b7280';

const trendLabels = <?= json_encode($trend_labels) ?>;
const trendRev    = <?= json_encode($trend_rev) ?>;
const trendExp    = <?= json_encode($trend_exp) ?>;
const trendNet    = <?= json_encode($trend_net) ?>;
const trendMilk   = <?= json_encode($trend_milk) ?>;
const revParts    = <?= json_encode(array_values($rev_parts)) ?>;
const revLabels   = <?= json_encode(array_keys($rev_parts)) ?>;
const expParts    = <?= json_encode(array_values($exp_parts)) ?>;
const expLabels   = <?= json_encode(array_keys($exp_parts)) ?>;
const herdCounts  = <?= json_encode(array_values($cow_by_status)) ?>;
const herdLabels  = <?= json_encode(array_map('ucfirst', array_keys($cow_by_status))) ?>;

const chartsInited = {};
function initCharts(tab) {
    if (chartsInited[tab]) return;
    chartsInited[tab] = true;

    if (tab === 'overview') {
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    { label: 'Revenue', data: trendRev, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.08)', tension: .35, fill: true, pointRadius: 3 },
                    { label: 'Expenses', data: trendExp, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.05)', tension: .35, fill: false, pointRadius: 3 },
                    { label: 'Net Profit', data: trendNet, borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.05)', tension: .35, fill: false, borderDash: [4,3], pointRadius: 3 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top' }, tooltip: { mode: 'index', intersect: false } },
                scales: { y: { grid: { color: '#f3f4f6' }, ticks: { callback: v => '৳' + v.toLocaleString() } }, x: { grid: { display: false } } }
            }
        });
    }

    if (tab === 'financial') {
        const donutOpts = {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ctx.label + ': ৳' + ctx.raw.toLocaleString() } } },
            cutout: '62%'
        };
        const clrs = ['#2D6A4F','#059669','#f59e0b','#3b82f6','#8b5cf6','#ef4444'];

        if (revLabels.length > 0) {
            new Chart(document.getElementById('revDonut'), {
                type: 'doughnut',
                data: { labels: revLabels, datasets: [{ data: revParts, backgroundColor: clrs, borderWidth: 2, borderColor: '#fff' }] },
                options: donutOpts
            });
        }
        if (expLabels.length > 0) {
            new Chart(document.getElementById('expDonut'), {
                type: 'doughnut',
                data: { labels: expLabels, datasets: [{ data: expParts, backgroundColor: ['#ef4444','#f97316','#f59e0b','#6b7280'], borderWidth: 2, borderColor: '#fff' }] },
                options: donutOpts
            });
        }
        new Chart(document.getElementById('netChart'), {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [{ label: 'Net Profit', data: trendNet,
                    backgroundColor: trendNet.map(v => v >= 0 ? 'rgba(5,150,105,.75)' : 'rgba(220,38,38,.75)'),
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => '৳' + ctx.raw.toLocaleString() } } },
                scales: { y: { grid: { color: '#f3f4f6' }, ticks: { callback: v => '৳' + v.toLocaleString() } }, x: { grid: { display: false } } }
            }
        });
    }

    if (tab === 'livestock') {
        if (herdCounts.length > 0) {
            new Chart(document.getElementById('herdDonut'), {
                type: 'doughnut',
                data: {
                    labels: herdLabels,
                    datasets: [{ data: herdCounts,
                        backgroundColor: ['#2D6A4F','#059669','#d97706','#8b5cf6','#ef4444','#dc2626','#9ca3af','#374151'],
                        borderWidth: 2, borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '58%',
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    if (tab === 'production') {
        new Chart(document.getElementById('milkChart'), {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Liters',
                    data: trendMilk,
                    backgroundColor: 'rgba(45,106,79,.7)',
                    borderRadius: 5,
                    hoverBackgroundColor: '#2D6A4F'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ctx.raw + ' L' } } },
                scales: { y: { grid: { color: '#f3f4f6' }, ticks: { callback: v => v + 'L' } }, x: { grid: { display: false } } }
            }
        });
    }
}

// Init overview charts immediately (first tab)
initCharts('overview');
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
