<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin', 'accountant']);
requireModule('finance');

$page_title = 'Finance Charts';
$active_nav = 'finance_charts';
$db = getDB();

// Last 12 months — income vs expense
$monthly_stmt = $db->query(
    "SELECT DATE_FORMAT(transaction_date,'%Y-%m')  AS ym,
            DATE_FORMAT(transaction_date,'%b %Y')   AS label,
            SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS income,
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
     FROM finance_transactions
     WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
     GROUP BY ym, label
     ORDER BY ym ASC"
);
$monthly_raw = [];
foreach ($monthly_stmt->fetchAll() as $r) {
    $monthly_raw[$r['ym']] = $r;
}
$m_labels = [];
$m_income = [];
$m_expense= [];
$m_net    = [];
for ($i = 11; $i >= 0; $i--) {
    $ym  = date('Y-m', strtotime("-{$i} months"));
    $lbl = date('M Y', strtotime($ym . '-01'));
    $m_labels[]  = $lbl;
    $inc = (float)($monthly_raw[$ym]['income']  ?? 0);
    $exp = (float)($monthly_raw[$ym]['expense'] ?? 0);
    $m_income[]  = round($inc, 2);
    $m_expense[] = round($exp, 2);
    $m_net[]     = round($inc - $exp, 2);
}

// Year-over-year: current year vs previous year by month
$yoy_stmt = $db->query(
    "SELECT YEAR(transaction_date) AS yr, MONTH(transaction_date) AS mo,
            SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS income,
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
     FROM finance_transactions
     WHERE YEAR(transaction_date) IN (YEAR(CURDATE()), YEAR(CURDATE())-1)
     GROUP BY yr, mo
     ORDER BY yr, mo"
);
$curr_yr = (int)date('Y');
$prev_yr = $curr_yr - 1;
$yoy_data = [$curr_yr => [], $prev_yr => []];
foreach ($yoy_stmt->fetchAll() as $r) {
    $yoy_data[(int)$r['yr']][(int)$r['mo']] = [
        'income'  => round((float)$r['income'],  2),
        'expense' => round((float)$r['expense'], 2),
    ];
}
$month_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$yoy_income_curr = [];
$yoy_income_prev = [];
$yoy_expense_curr = [];
$yoy_expense_prev = [];
for ($mo = 1; $mo <= 12; $mo++) {
    $yoy_income_curr[]  = round($yoy_data[$curr_yr][$mo]['income']  ?? 0, 2);
    $yoy_income_prev[]  = round($yoy_data[$prev_yr][$mo]['income']  ?? 0, 2);
    $yoy_expense_curr[] = round($yoy_data[$curr_yr][$mo]['expense'] ?? 0, 2);
    $yoy_expense_prev[] = round($yoy_data[$prev_yr][$mo]['expense'] ?? 0, 2);
}

// Category breakdown — current year
$cat_stmt = $db->prepare(
    "SELECT category, type,
            SUM(amount) AS total
     FROM finance_transactions
     WHERE YEAR(transaction_date) = ?
     GROUP BY category, type
     ORDER BY total DESC
     LIMIT 20"
);
$cat_stmt->execute([$curr_yr]);
$cats = $cat_stmt->fetchAll();
$inc_cats = array_filter($cats, fn($r) => $r['type'] === 'income');
$exp_cats = array_filter($cats, fn($r) => $r['type'] === 'expense');

// Encode for JS
$js_m_labels        = json_encode($m_labels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$js_m_income        = json_encode($m_income,  JSON_THROW_ON_ERROR);
$js_m_expense       = json_encode($m_expense, JSON_THROW_ON_ERROR);
$js_m_net           = json_encode($m_net,     JSON_THROW_ON_ERROR);
$js_month_names     = json_encode($month_names, JSON_THROW_ON_ERROR);
$js_yoy_inc_curr    = json_encode($yoy_income_curr,  JSON_THROW_ON_ERROR);
$js_yoy_inc_prev    = json_encode($yoy_income_prev,  JSON_THROW_ON_ERROR);
$js_yoy_exp_curr    = json_encode($yoy_expense_curr, JSON_THROW_ON_ERROR);
$js_yoy_exp_prev    = json_encode($yoy_expense_prev, JSON_THROW_ON_ERROR);
$js_inc_cat_labels  = json_encode(array_values(array_column(array_values($inc_cats), 'category')), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$js_inc_cat_vals    = json_encode(array_values(array_map(fn($r) => round((float)$r['total'],2), array_values($inc_cats))), JSON_THROW_ON_ERROR);
$js_exp_cat_labels  = json_encode(array_values(array_column(array_values($exp_cats), 'category')), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$js_exp_cat_vals    = json_encode(array_values(array_map(fn($r) => round((float)$r['total'],2), array_values($exp_cats))), JSON_THROW_ON_ERROR);

// Palette for pie charts
$palette_green = ['#166534','#15803D','#16A34A','#22C55E','#4ADE80','#86EFAC','#BBF7D0','#DCFCE7'];
$palette_red   = ['#7F1D1D','#991B1B','#DC2626','#EF4444','#F87171','#FCA5A5','#FECACA','#FEE2E2'];
$js_g_palette  = json_encode($palette_green, JSON_THROW_ON_ERROR);
$js_r_palette  = json_encode($palette_red,   JSON_THROW_ON_ERROR);

// Summary KPIs
$yr_kpi = $db->prepare(
    "SELECT SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS income,
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
     FROM finance_transactions WHERE YEAR(transaction_date) = ?"
);
$yr_kpi->execute([$curr_yr]);
$yr_row = $yr_kpi->fetch();
$yr_net = (float)$yr_row['income'] - (float)$yr_row['expense'];

$prev_yr_kpi = $db->prepare(
    "SELECT SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS income,
            SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
     FROM finance_transactions WHERE YEAR(transaction_date) = ?"
);
$prev_yr_kpi->execute([$prev_yr]);
$prev_yr_row = $prev_yr_kpi->fetch();
$prev_yr_net = (float)$prev_yr_row['income'] - (float)$prev_yr_row['expense'];

$yoy_change = $prev_yr_net != 0
    ? round(($yr_net - $prev_yr_net) / abs($prev_yr_net) * 100, 1)
    : null;

$extra_js  = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'];
$inline_js = <<<JS
(function () {
    var tk = function(v){ return '৳ ' + parseFloat(v).toLocaleString('en-BD', {minimumFractionDigits:2}); };
    var chartOpts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: true } },
        scales: {
            x: { ticks: { font:{size:11} }, grid:{color:'rgba(0,0,0,.04)'} },
            y: { beginAtZero: true, ticks: { font:{size:11}, callback: function(v){ return '৳'+parseFloat(v).toLocaleString('en-BD'); } }, grid:{color:'rgba(0,0,0,.04)'} }
        }
    };

    // 1. Monthly income vs expense (bar chart)
    new Chart(document.getElementById('monthlyChart'), {
        type: 'bar',
        data: {
            labels: {$js_m_labels},
            datasets: [
                { label: 'Income',  data: {$js_m_income},  backgroundColor: 'rgba(22,163,74,.75)',  borderRadius:3 },
                { label: 'Expense', data: {$js_m_expense}, backgroundColor: 'rgba(220,38,38,.75)',  borderRadius:3 }
            ]
        },
        options: Object.assign({}, chartOpts, { plugins: { legend: { position:'top' } } })
    });

    // 2. Net profit line
    new Chart(document.getElementById('netChart'), {
        type: 'line',
        data: {
            labels: {$js_m_labels},
            datasets: [{
                label: 'Net Profit',
                data: {$js_m_net},
                borderColor: '#2563EB',
                backgroundColor: 'rgba(37,99,235,.08)',
                fill: true,
                tension: 0.35,
                pointRadius: 4,
            }]
        },
        options: Object.assign({}, chartOpts, {
            plugins: { legend: { display:false } },
            scales: Object.assign({}, chartOpts.scales, {
                y: Object.assign({}, chartOpts.scales.y, {
                    ticks: { callback: function(v){ return (v>=0?'+':'')+'৳'+parseFloat(Math.abs(v)).toLocaleString('en-BD'); } }
                })
            })
        })
    });

    // 3. YoY income
    new Chart(document.getElementById('yoyIncChart'), {
        type: 'bar',
        data: {
            labels: {$js_month_names},
            datasets: [
                { label: '{$curr_yr}', data: {$js_yoy_inc_curr}, backgroundColor:'rgba(22,163,74,.8)',  borderRadius:3 },
                { label: '{$prev_yr}', data: {$js_yoy_inc_prev}, backgroundColor:'rgba(22,163,74,.3)',  borderRadius:3 }
            ]
        },
        options: Object.assign({}, chartOpts, { plugins: { legend: { position:'top' } } })
    });

    // 4. YoY expense
    new Chart(document.getElementById('yoyExpChart'), {
        type: 'bar',
        data: {
            labels: {$js_month_names},
            datasets: [
                { label: '{$curr_yr}', data: {$js_yoy_exp_curr}, backgroundColor:'rgba(220,38,38,.8)',  borderRadius:3 },
                { label: '{$prev_yr}', data: {$js_yoy_exp_prev}, backgroundColor:'rgba(220,38,38,.3)',  borderRadius:3 }
            ]
        },
        options: Object.assign({}, chartOpts, { plugins: { legend: { position:'top' } } })
    });

    // 5. Income category pie
    var icl = {$js_inc_cat_labels};
    if (icl.length > 0) {
        new Chart(document.getElementById('incCatChart'), {
            type: 'doughnut',
            data: { labels: icl, datasets: [{ data: {$js_inc_cat_vals}, backgroundColor: {$js_g_palette}.slice(0, icl.length), borderWidth:2, borderColor:'#fff' }] },
            options: { responsive:true, maintainAspectRatio:false, cutout:'55%', plugins:{ legend:{ position:'right', labels:{font:{size:11}} } } }
        });
    }

    // 6. Expense category pie
    var ecl = {$js_exp_cat_labels};
    if (ecl.length > 0) {
        new Chart(document.getElementById('expCatChart'), {
            type: 'doughnut',
            data: { labels: ecl, datasets: [{ data: {$js_exp_cat_vals}, backgroundColor: {$js_r_palette}.slice(0, ecl.length), borderWidth:2, borderColor:'#fff' }] },
            options: { responsive:true, maintainAspectRatio:false, cutout:'55%', plugins:{ legend:{ position:'right', labels:{font:{size:11}} } } }
        });
    }
})();
JS;

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Finance Charts</h2>
        <p class="text-sm text-muted">Monthly trends, year-over-year comparison &amp; category breakdown</p>
    </div>
    <a href="/modules/finance/index.php" class="btn btn-secondary">View Ledger</a>
</div>

<!-- Annual KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(165px,1fr));margin-bottom:1.5rem">
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label"><?= $curr_yr ?> Total Income</div>
        <div class="kpi-value" style="font-size:1.15rem"><?= e(formatCurrency((float)$yr_row['income'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label"><?= $curr_yr ?> Total Expense</div>
        <div class="kpi-value" style="font-size:1.15rem"><?= e(formatCurrency((float)$yr_row['expense'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $yr_net >= 0 ? '#2563EB' : '#DC2626' ?>;--kpi-soft:<?= $yr_net >= 0 ? '#EFF6FF' : '#FEF2F2' ?>">
        <div class="kpi-label"><?= $curr_yr ?> Net Profit</div>
        <div class="kpi-value" style="font-size:1.15rem;color:<?= $yr_net >= 0 ? 'var(--primary)' : 'var(--danger)' ?>">
            <?= ($yr_net >= 0 ? '+' : '') . e(formatCurrency(abs($yr_net))) ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $prev_yr_net >= 0 ? '#6B7280' : '#DC2626' ?>;--kpi-soft:#F3F4F6">
        <div class="kpi-label"><?= $prev_yr ?> Net Profit</div>
        <div class="kpi-value" style="font-size:1.15rem"><?= e(formatCurrency(abs($prev_yr_net))) ?></div>
    </div>
    <?php if ($yoy_change !== null): ?>
    <div class="kpi-card" style="--kpi-color:<?= $yoy_change >= 0 ? '#16A34A' : '#DC2626' ?>;--kpi-soft:<?= $yoy_change >= 0 ? '#F0FDF4' : '#FEF2F2' ?>">
        <div class="kpi-label">YoY Change</div>
        <div class="kpi-value" style="font-size:1.15rem;color:<?= $yoy_change >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
            <?= ($yoy_change >= 0 ? '+' : '') . $yoy_change ?>%
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Monthly Income vs Expense -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h4 class="card-title">Monthly Income vs Expense — Last 12 Months</h4></div>
    <div class="card-body">
        <div style="position:relative;height:260px"><canvas id="monthlyChart"></canvas></div>
    </div>
</div>

<!-- Net Profit Trend -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h4 class="card-title">Net Profit Trend — Last 12 Months</h4></div>
    <div class="card-body">
        <div style="position:relative;height:220px"><canvas id="netChart"></canvas></div>
    </div>
</div>

<!-- YoY Comparison -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
    <div class="card">
        <div class="card-header"><h4 class="card-title">Income: <?= $curr_yr ?> vs <?= $prev_yr ?></h4></div>
        <div class="card-body">
            <div style="position:relative;height:220px"><canvas id="yoyIncChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h4 class="card-title">Expense: <?= $curr_yr ?> vs <?= $prev_yr ?></h4></div>
        <div class="card-body">
            <div style="position:relative;height:220px"><canvas id="yoyExpChart"></canvas></div>
        </div>
    </div>
</div>

<!-- Category Breakdown -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
    <div class="card">
        <div class="card-header"><h4 class="card-title">Income by Category — <?= $curr_yr ?></h4></div>
        <div class="card-body">
            <?php if (empty($inc_cats)): ?>
            <p class="text-muted">No income data for <?= $curr_yr ?>.</p>
            <?php else: ?>
            <div style="position:relative;height:220px"><canvas id="incCatChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h4 class="card-title">Expense by Category — <?= $curr_yr ?></h4></div>
        <div class="card-body">
            <?php if (empty($exp_cats)): ?>
            <p class="text-muted">No expense data for <?= $curr_yr ?>.</p>
            <?php else: ?>
            <div style="position:relative;height:220px"><canvas id="expCatChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
