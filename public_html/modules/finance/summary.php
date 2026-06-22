<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireModule('finance');
if (!canAccess('finance.view')) requireAccess('finance.view');

$db  = getDB();
$ff  = farmFilter();
$today = date('Y-m-d');
$year  = (int)date('Y');
$month = (int)date('m');

// ── Core financial calculator (same logic as profit.php) ─────────────────────
function finSummary(PDO $db, string $from, string $to): array {
    $ff = farmFilter();

    $q = $db->prepare("SELECT COALESCE(SUM(milk_value),0) FROM milk_records WHERE {$ff} AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ?");
    $q->execute([$from,$to]); $rev_milk = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(sale_price),0) FROM cow_sales WHERE {$ff} AND sale_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $rev_cow = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(total_revenue),0) FROM meat_sales WHERE {$ff} AND sale_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $rev_meat = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE {$ff} AND type='income' AND transaction_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $rev_manual = (float)$q->fetchColumn();

    $rev = $rev_milk + $rev_cow + $rev_meat + $rev_manual;

    $q = $db->prepare("SELECT COALESCE(SUM(cost),0) FROM treatments WHERE {$ff} AND treatment_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $exp_vet = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(cost),0) FROM maintenance_logs WHERE {$ff} AND completed_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $exp_maint = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE {$ff} AND type='expense' AND transaction_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $exp_manual = (float)$q->fetchColumn();

    // Worker salary proration (exact same logic as profit.php)
    $q = $db->prepare(
        "SELECT COALESCE(SUM(GREATEST(0,
             DATEDIFF(LEAST(IFNULL(termination_date,?),?), GREATEST(hire_date,?))+1
         ) * salary / 30.4375), 0)
         FROM workers WHERE {$ff} AND hire_date<=? AND (termination_date IS NULL OR termination_date>=?)"
    );
    $q->execute([$to,$to,$from,$to,$from]);
    $exp_salary = (float)$q->fetchColumn();

    $exp = $exp_vet + $exp_maint + $exp_manual + $exp_salary;
    $net = $rev - $exp;

    return [
        'from' => $from, 'to' => $to,
        'revenue'    => $rev,
        'expenses'   => $exp,
        'net'        => $net,
        'margin'     => $rev > 0 ? round($net / $rev * 100, 1) : 0.0,
        'rev_milk'   => $rev_milk,  'rev_cow'    => $rev_cow,
        'rev_meat'   => $rev_meat,  'rev_manual' => $rev_manual,
        'exp_vet'    => $exp_vet,   'exp_maint'  => $exp_maint,
        'exp_salary' => $exp_salary,'exp_manual' => $exp_manual,
    ];
}

function pct(float $old, float $new): ?float {
    return $old == 0 ? null : round(($new - $old) / abs($old) * 100, 1);
}

// ── Fixed periods ────────────────────────────────────────────────────────────
$this_m  = ['from' => date('Y-m-01'),                           'to' => date('Y-m-t')];
$last_m  = ['from' => date('Y-m-01', strtotime('-1 month')),    'to' => date('Y-m-t', strtotime('-1 month'))];
$this_y  = ['from' => date('Y-01-01'),                          'to' => $today];
$last_y  = ['from' => ($year-1).'-01-01',                       'to' => ($year-1).'-12-31'];

$tm = finSummary($db, $this_m['from'],  $this_m['to']);
$lm = finSummary($db, $last_m['from'],  $last_m['to']);
$ty = finSummary($db, $this_y['from'],  $this_y['to']);
$ly = finSummary($db, $last_y['from'],  $last_y['to']);

// ── 3-Month Custom Comparison ────────────────────────────────────────────────
$months_3 = [];
$compare_err = '';

$slots = ['a', 'b', 'c'];
foreach ($slots as $s) {
    $raw = trim($_GET["month_{$s}"] ?? '');
    if ($raw !== '' && preg_match('/^\d{4}-\d{2}$/', $raw)) {
        $from = $raw . '-01';
        $to   = date('Y-m-t', strtotime($from));
        $months_3[$s] = array_merge(['label' => date('F Y', strtotime($from)), 'input' => $raw],
                                     finSummary($db, $from, $to));
    }
}

// Pre-fill with last 3 months if nothing selected
if (empty($months_3)) {
    foreach ([0, 1, 2] as $i) {
        $ts   = strtotime("-{$i} month");
        $raw  = date('Y-m', $ts);
        $from = date('Y-m-01', $ts);
        $to   = date('Y-m-t', $ts);
        $key  = $slots[$i];
        $months_3[$key] = array_merge(['label' => date('F Y', $ts), 'input' => $raw],
                                       finSummary($db, $from, $to));
    }
}

// ── 12-month sparkline data ──────────────────────────────────────────────────
$spark_labels = []; $spark_rev = []; $spark_exp = []; $spark_net = [];
for ($i = 11; $i >= 0; $i--) {
    $ts  = strtotime("-{$i} month");
    $f   = date('Y-m-01', $ts);
    $t   = date('Y-m-t',  $ts);
    $fin = finSummary($db, $f, $t);
    $spark_labels[] = date('M y', $ts);
    $spark_rev[]    = round($fin['revenue'],  0);
    $spark_exp[]    = round($fin['expenses'], 0);
    $spark_net[]    = round($fin['net'],      0);
}

$page_title = 'Financial Summary';
$active_nav = 'profit_engine';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<style>
/* ── Financial Summary Styles ─────────────────────────────────── */
.fs-grid-4 { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.fs-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem; }
.fs-grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }
@media(max-width:900px) { .fs-grid-3,.fs-grid-2 { grid-template-columns:1fr; } }
@media(max-width:600px) { .fs-grid-4 { grid-template-columns:1fr 1fr; } }

.period-card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    overflow:hidden;
    position:relative;
}
.period-card::before {
    content:'';
    display:block;
    height:4px;
    background:var(--bar,#2D6A4F);
}
.pc-head {
    padding:.85rem 1.1rem .5rem;
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
}
.pc-title   { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--text-secondary); }
.pc-period  { font-size:.68rem; color:var(--text-secondary); margin-top:.1rem; }
.pc-badge   { font-size:.68rem; font-weight:700; padding:.2rem .55rem; border-radius:99px; }
.pc-badge.profit { background:#dcfce7; color:#15803d; }
.pc-badge.loss   { background:#fee2e2; color:#b91c1c; }
.pc-body    { padding:.5rem 1.1rem 1rem; }
.pc-net {
    font-size:1.6rem;
    font-weight:800;
    line-height:1;
    margin-bottom:.5rem;
}
.pc-net.profit { color:#059669; }
.pc-net.loss   { color:#dc2626; }
.pc-row {
    display:flex;
    justify-content:space-between;
    font-size:.8rem;
    padding:.28rem 0;
    border-bottom:1px solid #f9fafb;
}
.pc-row:last-child { border:none; }
.pc-row .lbl { color:var(--text-secondary); }
.pc-row .val { font-weight:600; }
.pc-trend {
    font-size:.73rem;
    font-weight:700;
    margin-top:.6rem;
    display:flex;
    align-items:center;
    gap:.3rem;
}
.pc-trend.up   { color:#059669; }
.pc-trend.down { color:#dc2626; }
.pc-trend.flat { color:#6b7280; }

.fs-section-title {
    font-size:1rem;
    font-weight:800;
    color:var(--text-primary);
    margin:0 0 .85rem;
    display:flex;
    align-items:center;
    gap:.4rem;
}
.compare-card {
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    overflow:hidden;
}
.compare-card-head {
    padding:1rem 1.1rem .75rem;
    border-bottom:1px solid var(--border);
}
.compare-form {
    display:grid;
    grid-template-columns:1fr 1fr 1fr auto;
    gap:.6rem;
    align-items:end;
}
@media(max-width:700px) { .compare-form { grid-template-columns:1fr 1fr; } }

.cmp-col {
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    overflow:hidden;
}
.cmp-col-head {
    padding:.7rem 1rem;
    font-size:.82rem;
    font-weight:700;
    color:#fff;
}
.cmp-col-body { padding:.75rem 1rem; }
.cmp-row { display:flex; justify-content:space-between; font-size:.82rem; padding:.35rem 0; border-bottom:1px solid #f9fafb; }
.cmp-row:last-child { border:none; }
.cmp-row .label { color:var(--text-secondary); }
.cmp-row .value { font-weight:700; }
.best-badge {
    display:inline-block;
    font-size:.68rem;
    font-weight:700;
    padding:.15rem .45rem;
    border-radius:99px;
    background:#dcfce7;
    color:#15803d;
    margin-left:.3rem;
}
.worst-badge {
    display:inline-block;
    font-size:.68rem;
    font-weight:700;
    padding:.15rem .45rem;
    border-radius:99px;
    background:#fee2e2;
    color:#b91c1c;
    margin-left:.3rem;
}
.trend-indicator {
    font-size:.75rem;
    font-weight:700;
    padding:.2rem .5rem;
    border-radius:99px;
    display:inline-block;
    margin-top:.4rem;
}

.chart-container { height:220px; position:relative; }
</style>

<div class="page-header">
    <div>
        <h2>Financial Summary</h2>
        <p class="text-muted text-sm">Period comparisons, profit analysis, and multi-month comparison</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/finance/profit.php" class="btn btn-secondary btn-sm">Full Report</a>
        <a href="/modules/finance/index.php"  class="btn btn-primary btn-sm">Ledger</a>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 1: MONTHLY COMPARISON
     ══════════════════════════════════════════════════════════════ -->
<p class="fs-section-title">📅 Monthly Profit & Loss</p>
<div class="fs-grid-4">
    <?php
    $monthly_pairs = [
        ['This Month',   $tm, date('F Y'),                              '#2D6A4F'],
        ['Last Month',   $lm, date('F Y', strtotime('-1 month')),       '#3b82f6'],
    ];
    foreach ($monthly_pairs as [$title, $d, $sub, $color]):
        $is_profit = $d['net'] >= 0;
        $rev_change = pct($d === $tm ? $lm['revenue'] : $ly['revenue'], $d['revenue']);
    ?>
    <div class="period-card" style="--bar:<?= $color ?>">
        <div class="pc-head">
            <div>
                <div class="pc-title"><?= $title ?></div>
                <div class="pc-period"><?= e($sub) ?></div>
            </div>
            <span class="pc-badge <?= $is_profit ? 'profit' : 'loss' ?>"><?= $is_profit ? 'Profit' : 'Loss' ?></span>
        </div>
        <div class="pc-body">
            <div class="pc-net <?= $is_profit ? 'profit' : 'loss' ?>">
                <?= $is_profit ? '' : '-' ?>৳<?= number_format(abs($d['net']), 0) ?>
            </div>
            <div class="pc-row"><span class="lbl">Revenue</span><span class="val" style="color:#059669">৳<?= number_format($d['revenue'], 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Expenses</span><span class="val" style="color:#dc2626">৳<?= number_format($d['expenses'], 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Margin</span><span class="val" style="color:<?= $is_profit?'#059669':'#dc2626' ?>"><?= $d['margin'] ?>%</span></div>
            <?php if ($d['revenue'] > 0): ?>
            <div class="pc-row"><span class="lbl">Milk Rev</span><span class="val">৳<?= number_format($d['rev_milk'], 0) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

<!-- MoM Change Card -->
    <?php
    $rev_diff = $tm['revenue'] - $lm['revenue'];
    $net_diff = $tm['net'] - $lm['net'];
    $rev_pct  = pct($lm['revenue'], $tm['revenue']);
    $net_pct  = pct(abs($lm['net']), $tm['net']);
    ?>
    <div class="period-card" style="--bar:#8b5cf6">
        <div class="pc-head">
            <div>
                <div class="pc-title">Month-on-Month Change</div>
                <div class="pc-period">vs <?= date('F Y', strtotime('-1 month')) ?></div>
            </div>
            <span class="pc-badge <?= $rev_diff >= 0 ? 'profit' : 'loss' ?>">
                <?= $rev_diff >= 0 ? '▲' : '▼' ?>
            </span>
        </div>
        <div class="pc-body">
            <div class="pc-net <?= $rev_diff >= 0 ? 'profit' : 'loss' ?>" style="font-size:1.2rem">
                <?= $rev_pct !== null ? (($rev_pct >= 0 ? '+' : '') . $rev_pct . '%') : '—' ?>
            </div>
            <div class="pc-row"><span class="lbl">Revenue Δ</span><span class="val" style="color:<?= $rev_diff>=0?'#059669':'#dc2626' ?>"><?= $rev_diff >= 0 ? '+' : '' ?>৳<?= number_format($rev_diff, 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Expense Δ</span><span class="val">৳<?= number_format($tm['expenses'] - $lm['expenses'], 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Profit Δ</span><span class="val" style="color:<?= $net_diff>=0?'#059669':'#dc2626' ?>"><?= $net_diff>=0?'+':'' ?>৳<?= number_format($net_diff, 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Margin Δ</span><span class="val"><?= $tm['margin'] - $lm['margin'] >= 0 ? '+' : '' ?><?= round($tm['margin'] - $lm['margin'], 1) ?>%</span></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 2: YEARLY COMPARISON
     ══════════════════════════════════════════════════════════════ -->
<p class="fs-section-title">📆 Yearly Profit & Loss</p>
<div class="fs-grid-4">
    <?php
    $yearly_pairs = [
        ['This Year (YTD)', $ty, date('Y') . ' Jan–' . date('d M'), '#059669'],
        ['Last Year (Full)', $ly, ($year-1) . ' Jan–Dec',            '#d97706'],
    ];
    foreach ($yearly_pairs as [$title, $d, $sub, $color]):
        $is_profit = $d['net'] >= 0;
    ?>
    <div class="period-card" style="--bar:<?= $color ?>">
        <div class="pc-head">
            <div>
                <div class="pc-title"><?= $title ?></div>
                <div class="pc-period"><?= e($sub) ?></div>
            </div>
            <span class="pc-badge <?= $is_profit ? 'profit' : 'loss' ?>"><?= $is_profit ? 'Profit' : 'Loss' ?></span>
        </div>
        <div class="pc-body">
            <div class="pc-net <?= $is_profit ? 'profit' : 'loss' ?>">
                <?= $is_profit ? '' : '-' ?>৳<?= number_format(abs($d['net']), 0) ?>
            </div>
            <div class="pc-row"><span class="lbl">Revenue</span><span class="val" style="color:#059669">৳<?= number_format($d['revenue'], 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Expenses</span><span class="val" style="color:#dc2626">৳<?= number_format($d['expenses'], 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Margin</span><span class="val"><?= $d['margin'] ?>%</span></div>
            <div class="pc-row"><span class="lbl">Vet Costs</span><span class="val">৳<?= number_format($d['exp_vet'], 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Salaries</span><span class="val">৳<?= number_format($d['exp_salary'], 0) ?></span></div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php
    $y_rev_pct = pct($ly['revenue'], $ty['revenue']);
    $y_net_pct = pct(abs($ly['net']), $ty['net']);
    $y_rev_d   = $ty['revenue'] - $ly['revenue'];
    $y_net_d   = $ty['net']     - $ly['net'];
    ?>
    <div class="period-card" style="--bar:#ef4444">
        <div class="pc-head">
            <div>
                <div class="pc-title">Year-on-Year Change</div>
                <div class="pc-period"><?= $year ?> YTD vs <?= $year-1 ?> Full Year</div>
            </div>
            <span class="pc-badge <?= $y_rev_d >= 0 ? 'profit' : 'loss' ?>"><?= $y_rev_d >= 0 ? '▲' : '▼' ?></span>
        </div>
        <div class="pc-body">
            <div class="pc-net <?= $y_rev_d >= 0 ? 'profit' : 'loss' ?>" style="font-size:1.2rem">
                <?= $y_rev_pct !== null ? (($y_rev_pct >= 0 ? '+' : '') . $y_rev_pct . '%') : '—' ?>
            </div>
            <div class="pc-row"><span class="lbl">Revenue Δ</span><span class="val" style="color:<?= $y_rev_d>=0?'#059669':'#dc2626' ?>"><?= $y_rev_d>=0?'+':'' ?>৳<?= number_format($y_rev_d, 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Expense Δ</span><span class="val">৳<?= number_format($ty['expenses'] - $ly['expenses'], 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Profit Δ</span><span class="val" style="color:<?= $y_net_d>=0?'#059669':'#dc2626' ?>"><?= $y_net_d>=0?'+':'' ?>৳<?= number_format($y_net_d, 0) ?></span></div>
            <div class="pc-row"><span class="lbl">Margin Δ</span><span class="val"><?= $ty['margin'] - $ly['margin'] >= 0 ? '+' : '' ?><?= round($ty['margin'] - $ly['margin'], 1) ?>%</span></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 3: 12-MONTH TREND CHART
     ══════════════════════════════════════════════════════════════ -->
<div class="compare-card" style="margin-bottom:1.5rem">
    <div class="compare-card-head">
        <div style="font-weight:700;font-size:.88rem">📈 12-Month Revenue, Expense & Profit Trend</div>
    </div>
    <div style="padding:1rem 1.1rem">
        <div class="chart-container"><canvas id="trendChart"></canvas></div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SECTION 4: 3-MONTH CUSTOM COMPARISON
     ══════════════════════════════════════════════════════════════ -->
<div class="compare-card">
    <div class="compare-card-head">
        <div style="font-weight:700;font-size:.88rem">🔀 Custom 3-Month Comparison</div>
        <p style="font-size:.78rem;color:var(--text-secondary);margin:.25rem 0 0">Select any 3 months to compare side by side</p>
    </div>
    <div style="padding:1rem 1.1rem;border-bottom:1px solid var(--border)">
        <form method="GET" class="compare-form">
            <?php foreach ($slots as $i => $s): ?>
            <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:.75rem">Month <?= chr(65+$i) ?></label>
                <input type="month" name="month_<?= $s ?>" class="form-control form-control-sm"
                       value="<?= e($months_3[$s]['input'] ?? '') ?>"
                       max="<?= date('Y-m') ?>">
            </div>
            <?php endforeach; ?>
            <div>
                <button type="submit" class="btn btn-primary btn-sm" style="width:100%">Compare</button>
            </div>
        </form>
    </div>

    <?php if (count($months_3) === 3):
        // Find best/worst net
        $nets = array_column($months_3, 'net');
        $max_net = max($nets);
        $min_net = min($nets);
        $cols_colors = ['#2D6A4F', '#3b82f6', '#d97706'];
    ?>
    <div style="padding:1rem 1.1rem">
        <div class="fs-grid-3">
        <?php foreach (array_values($months_3) as $i => $m):
            $is_best   = $m['net'] == $max_net && $max_net > $min_net;
            $is_worst  = $m['net'] == $min_net && $max_net > $min_net;
            $is_profit = $m['net'] >= 0;
            $color     = $cols_colors[$i];
            // Trend vs previous month in comparison
            $prev_net  = $i > 0 ? array_values($months_3)[$i-1]['net'] : null;
            $trend     = $prev_net !== null ? ($m['net'] > $prev_net ? 'up' : ($m['net'] < $prev_net ? 'down' : 'flat')) : null;
        ?>
        <div class="cmp-col">
            <div class="cmp-col-head" style="background:<?= $color ?>">
                <?= e($m['label']) ?>
                <?php if ($is_best):  ?><span style="background:rgba(255,255,255,.25);font-size:.68rem;padding:.15rem .4rem;border-radius:99px;margin-left:.4rem">Best</span><?php endif; ?>
                <?php if ($is_worst): ?><span style="background:rgba(0,0,0,.25);font-size:.68rem;padding:.15rem .4rem;border-radius:99px;margin-left:.4rem">Lowest</span><?php endif; ?>
            </div>
            <div class="cmp-col-body">
                <div style="font-size:1.5rem;font-weight:800;color:<?= $is_profit?'#059669':'#dc2626' ?>;margin-bottom:.5rem">
                    <?= $is_profit ? '' : '-' ?>৳<?= number_format(abs($m['net']), 0) ?>
                </div>
                <?php if ($trend !== null): ?>
                <div class="trend-indicator <?= $trend ?>" style="background:<?= $trend==='up'?'#dcfce7':($trend==='down'?'#fee2e2':'#f1f5f9') ?>;color:<?= $trend==='up'?'#15803d':($trend==='down'?'#b91c1c':'#475569') ?>;margin-bottom:.5rem">
                    <?= $trend === 'up' ? '▲' : ($trend === 'down' ? '▼' : '→') ?>
                    <?php
                    $diff_pct = $prev_net != 0 ? round(($m['net'] - $prev_net) / abs($prev_net) * 100, 1) : null;
                    echo $diff_pct !== null ? abs($diff_pct) . '% vs prev' : 'vs prev';
                    ?>
                </div>
                <?php endif; ?>
                <div class="cmp-row"><span class="label">Revenue</span><span class="value" style="color:#059669">৳<?= number_format($m['revenue'], 0) ?></span></div>
                <div class="cmp-row"><span class="label">Expenses</span><span class="value" style="color:#dc2626">৳<?= number_format($m['expenses'], 0) ?></span></div>
                <div class="cmp-row"><span class="label">Margin</span><span class="value"><?= $m['margin'] ?>%</span></div>
                <div style="margin-top:.5rem;padding-top:.5rem;border-top:1px solid #f3f4f6;font-size:.75rem;color:var(--text-secondary)">
                    Revenue sources
                </div>
                <div class="cmp-row"><span class="label">🥛 Milk</span><span class="value">৳<?= number_format($m['rev_milk'], 0) ?></span></div>
                <div class="cmp-row"><span class="label">🐄 Sales</span><span class="value">৳<?= number_format($m['rev_cow'] + $m['rev_meat'], 0) ?></span></div>
                <div class="cmp-row"><span class="label">💼 Other</span><span class="value">৳<?= number_format($m['rev_manual'], 0) ?></span></div>
                <div style="margin-top:.5rem;padding-top:.5rem;border-top:1px solid #f3f4f6;font-size:.75rem;color:var(--text-secondary)">
                    Expense sources
                </div>
                <div class="cmp-row"><span class="label">🩺 Vet</span><span class="value">৳<?= number_format($m['exp_vet'], 0) ?></span></div>
                <div class="cmp-row"><span class="label">👷 Salaries</span><span class="value">৳<?= number_format($m['exp_salary'], 0) ?></span></div>
                <div class="cmp-row"><span class="label">🔧 Maint.</span><span class="value">৳<?= number_format($m['exp_maint'], 0) ?></span></div>
                <div class="cmp-row"><span class="label">📋 Other</span><span class="value">৳<?= number_format($m['exp_manual'], 0) ?></span></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- Mini bar chart comparing the 3 months -->
        <div style="margin-top:1rem">
            <div class="chart-container" style="height:180px"><canvas id="compareChart"></canvas></div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#6b7280';

// ── 12-month trend ───────────────────────────────────────────────────────────
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($spark_labels) ?>,
        datasets: [
            { label:'Revenue',  data:<?= json_encode($spark_rev) ?>, borderColor:'#059669', backgroundColor:'rgba(5,150,105,.07)', tension:.35, fill:true, pointRadius:3 },
            { label:'Expenses', data:<?= json_encode($spark_exp) ?>, borderColor:'#ef4444', backgroundColor:'rgba(239,68,68,.04)',  tension:.35, fill:false, borderDash:[4,3], pointRadius:3 },
            { label:'Net Profit',data:<?= json_encode($spark_net) ?>,borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,.04)', tension:.35, fill:false, pointRadius:3 },
        ]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ position:'top' }, tooltip:{ mode:'index', intersect:false, callbacks:{ label:c => c.dataset.label+': ৳'+c.raw.toLocaleString() } } },
        scales:{ y:{ grid:{ color:'#f3f4f6' }, ticks:{ callback:v=>'৳'+v.toLocaleString() } }, x:{ grid:{ display:false } } }
    }
});

<?php if (count($months_3) === 3): ?>
// ── 3-month comparison chart ─────────────────────────────────────────────────
new Chart(document.getElementById('compareChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($months_3, 'label')) ?>,
        datasets: [
            { label:'Revenue',   data:<?= json_encode(array_map(fn($m)=>round($m['revenue'],0), $months_3)) ?>,  backgroundColor:'rgba(5,150,105,.75)',  borderRadius:4 },
            { label:'Expenses',  data:<?= json_encode(array_map(fn($m)=>round($m['expenses'],0), $months_3)) ?>, backgroundColor:'rgba(239,68,68,.65)',   borderRadius:4 },
            { label:'Net Profit',data:<?= json_encode(array_map(fn($m)=>round($m['net'],0), $months_3)) ?>,
              backgroundColor: <?= json_encode(array_map(fn($m) => $m['net'] >= 0 ? 'rgba(59,130,246,.75)' : 'rgba(220,38,38,.75)', $months_3)) ?>, borderRadius:4 },
        ]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ position:'top' }, tooltip:{ callbacks:{ label:c => c.dataset.label+': ৳'+c.raw.toLocaleString() } } },
        scales:{ y:{ grid:{ color:'#f3f4f6' }, ticks:{ callback:v=>'৳'+v.toLocaleString() } }, x:{ grid:{ display:false } } }
    }
});
<?php endif; ?>
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
