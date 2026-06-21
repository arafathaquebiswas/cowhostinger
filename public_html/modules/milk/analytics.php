<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireAuth();
requireFarmScope();
requireModule('milk');

if (!canAccess('milk.analytics')) {
    requireAccess('milk.analytics');
}

$page_title = 'Milk Analytics';
$active_nav = 'milk_analytics';
$db = getDB();

// Period selector: 7, 14, 30, 60, 90 days
$valid_periods = [7, 14, 30, 60, 90];
$period = (int)($_GET['period'] ?? 30);
if (!in_array($period, $valid_periods, true)) $period = 30;
$date_start = date('Y-m-d', strtotime("-{$period} days"));
$today      = date('Y-m-d');

// Summary KPIs for the period
$kpi = $db->prepare(
    "SELECT
        COALESCE(SUM(mr.liters), 0)                             AS total_liters,
        COUNT(*)                                                 AS total_sessions,
        COALESCE(AVG(mr.liters), 0)                             AS avg_liters_per_session,
        SUM(mr.contamination_flag = 1)                          AS contaminated,
        COALESCE(AVG(CASE WHEN mr.fat_percentage IS NOT NULL THEN mr.fat_percentage END), 0) AS avg_fat,
        COUNT(DISTINCT mr.cow_id)                               AS cows_recorded,
        COALESCE(SUM(mr.liters) / NULLIF(DATEDIFF(CURDATE(), ?)+1, 0), 0) AS avg_per_day
     FROM milk_records mr
     WHERE " . farmFilter('mr') . " AND DATE(mr.recorded_at) BETWEEN ? AND ?"
);
$kpi->execute([$date_start, $date_start, $today]);
$summary = $kpi->fetch();

$contamination_rate = $summary['total_sessions'] > 0
    ? round((int)$summary['contaminated'] / (int)$summary['total_sessions'] * 100, 1)
    : 0;

// Daily totals for line chart
$daily_stmt = $db->prepare(
    "SELECT DATE(recorded_at) AS day, COALESCE(SUM(liters), 0) AS total
     FROM milk_records
     WHERE " . farmFilter() . " AND DATE(recorded_at) BETWEEN ? AND ?
     GROUP BY day
     ORDER BY day ASC"
);
$daily_stmt->execute([$date_start, $today]);
$daily_raw = $daily_stmt->fetchAll();

// Build a complete day-by-day map (zero-fill missing days)
$daily_map = [];
foreach ($daily_raw as $r) {
    $daily_map[$r['day']] = (float)$r['total'];
}
$chart_labels = [];
$chart_data   = [];
for ($i = $period; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('j M', strtotime($d));
    $chart_data[]   = $daily_map[$d] ?? 0;
}

// Top 10 producing cows in period
$top_cows_stmt = $db->prepare(
    "SELECT c.id, c.tag_number, c.breed,
            SUM(mr.liters) AS total_liters,
            COUNT(*)       AS sessions,
            AVG(mr.liters) AS avg_liters,
            SUM(mr.contamination_flag=1) AS contaminated_sessions
     FROM milk_records mr
     JOIN cows c ON c.id = mr.cow_id
     WHERE " . farmFilter('mr') . " AND DATE(mr.recorded_at) BETWEEN ? AND ?
     GROUP BY c.id, c.tag_number, c.breed
     ORDER BY total_liters DESC
     LIMIT 10"
);
$top_cows_stmt->execute([$date_start, $today]);
$top_cows = $top_cows_stmt->fetchAll();

// Monthly breakdown (last 6 months)
$monthly_stmt = $db->prepare(
    "SELECT DATE_FORMAT(recorded_at, '%Y-%m') AS ym,
            DATE_FORMAT(recorded_at, '%b %Y')  AS label,
            SUM(liters)                         AS total,
            COUNT(*)                            AS sessions,
            SUM(contamination_flag=1)           AS contaminated
     FROM milk_records
     WHERE " . farmFilter() . " AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym, label
     ORDER BY ym DESC
     LIMIT 6"
);
$monthly_stmt->execute();
$monthly = $monthly_stmt->fetchAll();

// Bar chart: monthly totals
$bar_labels = [];
$bar_data   = [];
foreach (array_reverse($monthly) as $m) {
    $bar_labels[] = $m['label'];
    $bar_data[]   = round((float)$m['total'], 2);
}

// Per-session distribution for the period (hourly)
$hourly_stmt = $db->prepare(
    "SELECT HOUR(recorded_at) AS hr, COUNT(*) AS cnt
     FROM milk_records
     WHERE " . farmFilter() . " AND DATE(recorded_at) BETWEEN ? AND ?
     GROUP BY hr
     ORDER BY hr ASC"
);
$hourly_stmt->execute([$date_start, $today]);
$hourly_raw = $hourly_stmt->fetchAll();
$hourly_map = array_column($hourly_raw, 'cnt', 'hr');
$hour_labels = [];
$hour_data   = [];
for ($h = 0; $h < 24; $h++) {
    $hour_labels[] = sprintf('%02d:00', $h);
    $hour_data[]   = (int)($hourly_map[$h] ?? 0);
}

$extra_js  = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'];

// Pass PHP arrays to JS via JSON
$js_chart_labels   = json_encode($chart_labels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$js_chart_data     = json_encode($chart_data,   JSON_THROW_ON_ERROR);
$js_bar_labels     = json_encode($bar_labels,   JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$js_bar_data       = json_encode($bar_data,     JSON_THROW_ON_ERROR);
$js_hour_labels    = json_encode($hour_labels,  JSON_THROW_ON_ERROR);
$js_hour_data      = json_encode($hour_data,    JSON_THROW_ON_ERROR);

$inline_js = <<<JS
(function () {
    var defaultFont = { family: 'Inter, system-ui, sans-serif', size: 12 };

    // --- Daily trend line chart ---
    var trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: {$js_chart_labels},
            datasets: [{
                label: 'Liters',
                data: {$js_chart_data},
                borderColor: '#2563EB',
                backgroundColor: 'rgba(37,99,235,.1)',
                fill: true,
                tension: 0.35,
                pointRadius: {$period} <= 14 ? 4 : 2,
                pointHoverRadius: 5,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) { return ' ' + ctx.parsed.y.toFixed(2) + ' L'; }
                    }
                }
            },
            scales: {
                x: { ticks: { font: defaultFont, maxTicksLimit: 10, maxRotation: 45 },
                     grid: { color: 'rgba(0,0,0,.05)' } },
                y: { beginAtZero: true,
                     ticks: { font: defaultFont,
                              callback: function(v) { return v + ' L'; } },
                     grid: { color: 'rgba(0,0,0,.05)' } }
            }
        }
    });

    // --- Monthly bar chart ---
    var barCtx = document.getElementById('monthlyChart').getContext('2d');
    new Chart(barCtx, {
        type: 'bar',
        data: {
            labels: {$js_bar_labels},
            datasets: [{
                label: 'Total Liters',
                data: {$js_bar_data},
                backgroundColor: '#3B82F6',
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { font: defaultFont } },
                y: { beginAtZero: true,
                     ticks: { font: defaultFont,
                              callback: function(v) { return v + ' L'; } } }
            }
        }
    });

    // --- Hourly distribution bar chart ---
    var hourCtx = document.getElementById('hourlyChart').getContext('2d');
    new Chart(hourCtx, {
        type: 'bar',
        data: {
            labels: {$js_hour_labels},
            datasets: [{
                label: 'Sessions',
                data: {$js_hour_data},
                backgroundColor: '#8B5CF6',
                borderRadius: 2,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { font: defaultFont, maxRotation: 45 } },
                y: { beginAtZero: true, ticks: { font: defaultFont, stepSize: 1 } }
            }
        }
    });
})();
JS;

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Milk Analytics</h2>
        <p class="text-sm text-muted">Production trends, top performers &amp; session distribution</p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
        <span class="text-muted text-sm">Period:</span>
        <?php foreach ($valid_periods as $p): ?>
        <a href="/modules/milk/analytics.php?period=<?= $p ?>"
           class="btn btn-sm <?= $p===$period ? 'btn-primary' : 'btn-secondary' ?>">
            <?= $p ?>d
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- KPI cards -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(145px,1fr));margin-bottom:1.5rem">
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-label">Total Liters</div>
        <div class="kpi-value" style="font-size:1.3rem"><?= number_format((float)$summary['total_liters'], 1) ?>L</div>
        <div style="font-size:.73rem;color:var(--text-muted);margin-top:.15rem">last <?= $period ?> days</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Avg / Day</div>
        <div class="kpi-value" style="font-size:1.3rem"><?= number_format((float)$summary['avg_per_day'], 1) ?>L</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-label">Avg / Session</div>
        <div class="kpi-value" style="font-size:1.3rem"><?= number_format((float)$summary['avg_liters_per_session'], 1) ?>L</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-label">Avg Fat %</div>
        <div class="kpi-value" style="font-size:1.3rem"><?= number_format((float)$summary['avg_fat'], 1) ?>%</div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $contamination_rate > 10 ? '#DC2626' : '#0891B2' ?>;--kpi-soft:<?= $contamination_rate > 10 ? '#FEF2F2' : '#ECFEFF' ?>">
        <div class="kpi-label">Contamination</div>
        <div class="kpi-value" style="font-size:1.3rem"><?= $contamination_rate ?>%</div>
        <div style="font-size:.73rem;color:var(--text-muted);margin-top:.15rem"><?= (int)$summary['contaminated'] ?> of <?= (int)$summary['total_sessions'] ?> sessions</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#0891B2;--kpi-soft:#ECFEFF">
        <div class="kpi-label">Cows Milked</div>
        <div class="kpi-value" style="font-size:1.3rem"><?= (int)$summary['cows_recorded'] ?></div>
        <div style="font-size:.73rem;color:var(--text-muted);margin-top:.15rem">in this period</div>
    </div>
</div>

<!-- Daily trend chart -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h4 class="card-title">Daily Production — Last <?= $period ?> Days</h4>
        <a href="/modules/milk/index.php" class="btn btn-sm btn-secondary">View Records</a>
    </div>
    <div class="card-body" style="padding:1rem">
        <div style="position:relative;height:240px">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
</div>

<!-- Two-column: monthly bar + hourly distribution -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">
    <div class="card">
        <div class="card-header"><h4 class="card-title">Monthly Totals (Last 6 Months)</h4></div>
        <div class="card-body" style="padding:1rem">
            <div style="position:relative;height:200px">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h4 class="card-title">Sessions by Hour of Day</h4></div>
        <div class="card-body" style="padding:1rem">
            <div style="position:relative;height:200px">
                <canvas id="hourlyChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Top 10 cows table -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header">
        <h4 class="card-title">Top Producing Cows — Last <?= $period ?> Days</h4>
    </div>
    <?php if (empty($top_cows)): ?>
    <div class="empty-state">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <p>No milk records in this period.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th style="width:36px">#</th>
                <th>Cow</th>
                <th style="text-align:right">Total Liters</th>
                <th style="text-align:right">Sessions</th>
                <th style="text-align:right">Avg/Session</th>
                <th style="text-align:right">Contam.</th>
                <th>Production Bar</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $max_liters = !empty($top_cows) ? (float)$top_cows[0]['total_liters'] : 1;
        foreach ($top_cows as $rank => $cow):
            $pct = $max_liters > 0 ? round((float)$cow['total_liters'] / $max_liters * 100) : 0;
            $c_rate = $cow['sessions'] > 0
                ? round((int)$cow['contaminated_sessions'] / (int)$cow['sessions'] * 100, 1)
                : 0;
        ?>
        <tr>
            <td style="color:var(--text-muted);font-weight:600"><?= $rank + 1 ?></td>
            <td>
                <a href="/modules/cows/view.php?id=<?= $cow['id'] ?>" style="font-weight:600">
                    #<?= e($cow['tag_number']) ?>
                </a>
                <div class="text-muted" style="font-size:.79rem"><?= e($cow['breed']) ?></div>
            </td>
            <td style="text-align:right;font-weight:700;font-size:1rem">
                <?= number_format((float)$cow['total_liters'], 1) ?>L
            </td>
            <td style="text-align:right;color:var(--text-muted)"><?= (int)$cow['sessions'] ?></td>
            <td style="text-align:right"><?= number_format((float)$cow['avg_liters'], 2) ?>L</td>
            <td style="text-align:right">
                <?php if ($c_rate > 0): ?>
                <span class="badge badge-red" style="font-size:.73rem"><?= $c_rate ?>%</span>
                <?php else: ?>
                <span class="badge badge-green" style="font-size:.73rem">0%</span>
                <?php endif; ?>
            </td>
            <td style="width:180px">
                <div style="background:var(--bg-muted);border-radius:99px;height:8px;overflow:hidden">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $rank===0?'#2563EB':($rank<3?'#3B82F6':'#93C5FD') ?>;border-radius:99px;transition:width .3s"></div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Monthly breakdown table -->
<?php if (!empty($monthly)): ?>
<div class="card">
    <div class="card-header"><h4 class="card-title">Monthly Breakdown</h4></div>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Month</th>
                <th style="text-align:right">Total Liters</th>
                <th style="text-align:right">Sessions</th>
                <th style="text-align:right">Avg/Session</th>
                <th style="text-align:right">Contaminated</th>
                <th style="text-align:right">Clean Rate</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($monthly as $m):
            $avg = $m['sessions'] > 0 ? (float)$m['total'] / (int)$m['sessions'] : 0;
            $c_rate_m = $m['sessions'] > 0
                ? round((int)$m['contaminated'] / (int)$m['sessions'] * 100, 1) : 0;
            $clean_rate = 100 - $c_rate_m;
        ?>
        <tr>
            <td style="font-weight:600"><?= e($m['label']) ?></td>
            <td style="text-align:right"><?= number_format((float)$m['total'], 1) ?>L</td>
            <td style="text-align:right;color:var(--text-muted)"><?= (int)$m['sessions'] ?></td>
            <td style="text-align:right"><?= number_format($avg, 2) ?>L</td>
            <td style="text-align:right;color:var(--text-muted)"><?= (int)$m['contaminated'] ?></td>
            <td style="text-align:right">
                <span class="badge <?= $clean_rate >= 95 ? 'badge-green' : ($clean_rate >= 85 ? 'badge-yellow' : 'badge-red') ?>">
                    <?= $clean_rate ?>%
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
