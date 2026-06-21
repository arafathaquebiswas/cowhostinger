<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();

$page_title = 'Cow Profitability';
$active_nav = 'reports';
$db = getDB();

// ── Date range filter ────────────────────────────────────────────────────────
$filter_from = $_GET['from'] ?? date('Y-01-01');
$filter_to   = $_GET['to']   ?? date('Y-m-d');
$filter_cow  = (int)($_GET['cow_id'] ?? 0);

// ── Farm-level P&L summary ────────────────────────────────────────────────────
$farm_kpi = $db->prepare(
    "SELECT
       -- Revenue
       COALESCE(SUM(CASE WHEN DATE(mr.recorded_at) BETWEEN ? AND ? THEN mr.milk_value END), 0)   AS milk_revenue,
       COALESCE((SELECT SUM(cs.sale_price)
                 FROM cow_sales cs WHERE cs.farm_id=? AND cs.sale_date BETWEEN ? AND ?), 0)       AS cow_sale_revenue,
       COALESCE((SELECT SUM(ms.total_revenue)
                 FROM meat_sales ms WHERE ms.farm_id=? AND ms.sale_date BETWEEN ? AND ?), 0)      AS meat_revenue,
       -- Costs
       COALESCE((SELECT SUM(fl.total_cost)
                 FROM feed_logs fl WHERE fl.farm_id=? AND fl.feed_date BETWEEN ? AND ?), 0)       AS feed_cost,
       COALESCE((SELECT SUM(t.cost)
                 FROM treatments t WHERE t.farm_id=? AND t.treatment_date BETWEEN ? AND ?), 0)    AS medicine_cost,
       COALESCE((SELECT SUM(vv.amount)
                 FROM vet_visits vv WHERE vv.farm_id=? AND vv.visit_date BETWEEN ? AND ?), 0)     AS vet_cost,
       -- Milk stats
       COALESCE(SUM(CASE WHEN DATE(mr.recorded_at) BETWEEN ? AND ? AND mr.contamination_flag=0
                    THEN mr.liters END), 0)                                                         AS total_liters,
       COUNT(DISTINCT CASE WHEN DATE(mr.recorded_at) BETWEEN ? AND ? THEN mr.cow_id END)          AS active_cows
     FROM milk_records mr
     WHERE mr.farm_id = ?"
);
$p = fid();
$farm_kpi->execute([
    $filter_from, $filter_to,                            // milk_revenue
    $p, $filter_from, $filter_to,                        // cow_sale_revenue
    $p, $filter_from, $filter_to,                        // meat_revenue
    $p, $filter_from, $filter_to,                        // feed_cost
    $p, $filter_from, $filter_to,                        // medicine_cost
    $p, $filter_from, $filter_to,                        // vet_cost
    $filter_from, $filter_to,                            // total_liters
    $filter_from, $filter_to,                            // active_cows
    $p,
]);
$farm = $farm_kpi->fetch();

$total_revenue = $farm['milk_revenue'] + $farm['cow_sale_revenue'] + $farm['meat_revenue'];
$total_cost    = $farm['feed_cost']    + $farm['medicine_cost']    + $farm['vet_cost'];
$gross_profit  = $total_revenue - $total_cost;
$margin_pct    = $total_revenue > 0 ? round($gross_profit / $total_revenue * 100, 1) : 0;

// ── Per-cow profitability ─────────────────────────────────────────────────────
// Uses positional ? because named params cannot repeat in PDO without emulated prepares.
$fi = fid();
$fd = $filter_from;
$td = $filter_to;
$cf = $filter_cow;

$cow_pl = $db->prepare(
    "SELECT
       c.id, c.tag_number, c.breed, c.status,
       c.purchase_price,
       COALESCE(SUM(CASE WHEN DATE(mr.recorded_at) BETWEEN ? AND ?
                    THEN mr.milk_value END), 0)                           AS milk_revenue,
       COALESCE(SUM(CASE WHEN DATE(mr.recorded_at) BETWEEN ? AND ?
                    AND mr.contamination_flag=0 THEN mr.liters END), 0)  AS milk_liters,
       COALESCE((SELECT SUM(fl.total_cost) FROM feed_logs fl
                 WHERE fl.cow_id=c.id AND fl.farm_id=?
                   AND fl.feed_date BETWEEN ? AND ?), 0)                 AS feed_cost,
       COALESCE((SELECT SUM(fl.quantity_kg) FROM feed_logs fl
                 WHERE fl.cow_id=c.id AND fl.farm_id=?
                   AND fl.feed_date BETWEEN ? AND ?), 0)                 AS feed_kg,
       COALESCE((SELECT SUM(t.cost) FROM treatments t
                 WHERE t.cow_id=c.id AND t.farm_id=?
                   AND t.treatment_date BETWEEN ? AND ?), 0)             AS medicine_cost,
       COALESCE((SELECT SUM(cs.sale_price) FROM cow_sales cs
                 WHERE cs.cow_id=c.id AND cs.farm_id=?
                   AND cs.sale_date BETWEEN ? AND ?), 0)                 AS sale_revenue
     FROM cows c
     LEFT JOIN milk_records mr ON mr.cow_id = c.id AND mr.farm_id = ?
     WHERE c.farm_id = ?
       AND (? = 0 OR c.id = ?)
     GROUP BY c.id
     HAVING (milk_revenue + sale_revenue + feed_cost + medicine_cost) > 0
             OR c.status NOT IN ('sold','deceased')
     ORDER BY (milk_revenue + sale_revenue - feed_cost - medicine_cost) DESC
     LIMIT 100"
);
$cow_pl->execute([
    $fd, $td,         // milk_revenue BETWEEN
    $fd, $td,         // milk_liters BETWEEN
    $fi, $fd, $td,    // feed_cost subquery
    $fi, $fd, $td,    // feed_kg subquery
    $fi, $fd, $td,    // medicine_cost subquery
    $fi, $fd, $td,    // sale_revenue subquery
    $fi,              // JOIN mr.farm_id
    $fi,              // WHERE c.farm_id
    $cf, $cf,         // cow_filter check
]);
$cow_rows = $cow_pl->fetchAll();

// ── All cows for filter dropdown ──────────────────────────────────────────────
$all_cows = $db->prepare(
    "SELECT id, tag_number, breed FROM cows WHERE " . farmFilter() . " ORDER BY tag_number ASC"
);
$all_cows->execute();
$all_cows = $all_cows->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Cow Profitability</h2>
        <p class="text-sm text-muted">Per-cow P&amp;L and farm-level financial summary</p>
    </div>
    <a href="/modules/reports/index.php" class="btn btn-secondary">All Reports</a>
</div>

<!-- Date Filter -->
<div class="card" style="padding:.75rem 1rem;margin-bottom:1.25rem">
    <form method="GET" action="/modules/reports/profitability.php" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;min-width:130px">
            <label class="form-label">From</label>
            <input type="date" name="from" class="form-control" value="<?= e($filter_from) ?>">
        </div>
        <div class="form-group" style="margin:0;min-width:130px">
            <label class="form-label">To</label>
            <input type="date" name="to" class="form-control" value="<?= e($filter_to) ?>">
        </div>
        <div class="form-group" style="margin:0;min-width:160px">
            <label class="form-label">Cow</label>
            <select name="cow_id" class="form-control">
                <option value="0">All cows</option>
                <?php foreach ($all_cows as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filter_cow === (int)$c['id'] ? 'selected' : '' ?>>
                    #<?= e($c['tag_number']) ?> <?= e($c['breed']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:.1rem">Apply</button>
        <a href="/modules/reports/profitability.php" class="btn btn-secondary btn-sm" style="margin-bottom:.1rem">Reset</a>
        <!-- Quick ranges -->
        <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin-bottom:.1rem">
            <?php
            $ranges = [
                'This Month' => [date('Y-m-01'), date('Y-m-d')],
                'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))],
                'This Year'  => [date('Y-01-01'), date('Y-m-d')],
                'Last 30 d'  => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            ];
            foreach ($ranges as $label => [$f, $t]): ?>
            <a href="?from=<?= $f ?>&to=<?= $t ?>&cow_id=<?= $filter_cow ?>"
               class="btn btn-secondary btn-sm" style="font-size:.72rem"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </form>
</div>

<!-- Farm P&L KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="card" style="padding:1rem;border-top:3px solid var(--success)">
        <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase">Milk Revenue</div>
        <div style="font-size:1.5rem;font-weight:700;color:var(--success)">৳<?= number_format($farm['milk_revenue'],0) ?></div>
        <div style="font-size:.75rem;color:var(--text-muted)"><?= number_format($farm['total_liters'],1) ?> L</div>
    </div>
    <div class="card" style="padding:1rem;border-top:3px solid #1565c0">
        <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase">Sales Revenue</div>
        <div style="font-size:1.5rem;font-weight:700;color:#1565c0">৳<?= number_format($farm['cow_sale_revenue']+$farm['meat_revenue'],0) ?></div>
        <div style="font-size:.75rem;color:var(--text-muted)">Cow + Meat</div>
    </div>
    <div class="card" style="padding:1rem;border-top:3px solid var(--danger)">
        <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase">Feed Cost</div>
        <div style="font-size:1.5rem;font-weight:700;color:var(--danger)">৳<?= number_format($farm['feed_cost'],0) ?></div>
    </div>
    <div class="card" style="padding:1rem;border-top:3px solid #e65100">
        <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase">Health Cost</div>
        <div style="font-size:1.5rem;font-weight:700;color:#e65100">৳<?= number_format($farm['medicine_cost']+$farm['vet_cost'],0) ?></div>
        <div style="font-size:.75rem;color:var(--text-muted)">Medicine + Vet</div>
    </div>
    <div class="card" style="padding:1rem;border-top:3px solid <?= $gross_profit >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
        <div style="font-size:.72rem;color:var(--text-muted);text-transform:uppercase">Gross Profit</div>
        <div style="font-size:1.5rem;font-weight:700;color:<?= $gross_profit >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
            <?= $gross_profit >= 0 ? '' : '-' ?>৳<?= number_format(abs($gross_profit),0) ?>
        </div>
        <div style="font-size:.75rem;color:var(--text-muted)"><?= $margin_pct ?>% margin</div>
    </div>
</div>

<!-- Per-Cow Table -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Per-Cow Profitability</span>
        <span style="font-size:.78rem;color:var(--text-muted)"><?= e($filter_from) ?> – <?= e($filter_to) ?></span>
    </div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Cow</th>
                    <th>Breed</th>
                    <th>Status</th>
                    <th style="text-align:right">Milk (L)</th>
                    <th style="text-align:right">Milk Revenue</th>
                    <th style="text-align:right">Sale Income</th>
                    <th style="text-align:right">Feed Cost</th>
                    <th style="text-align:right">Medicine</th>
                    <th style="text-align:right;font-weight:700">Net P&amp;L</th>
                    <th>Efficiency</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($cow_rows)): ?>
                <tr><td colspan="10" style="text-align:center;color:var(--text-muted);padding:2rem">
                    No data for this period. Make sure milk records have price snapshots set.
                </td></tr>
            <?php else: ?>
                <?php foreach ($cow_rows as $row):
                    $revenue  = $row['milk_revenue'] + $row['sale_revenue'];
                    $cost     = $row['feed_cost']    + $row['medicine_cost'];
                    $net      = $revenue - $cost;
                    $eff      = $row['feed_kg'] > 0 ? round($row['milk_liters'] / $row['feed_kg'], 2) : null;
                    $roi      = $row['purchase_price'] > 0 ? round($net / $row['purchase_price'] * 100, 1) : null;
                ?>
                <tr style="<?= $net < 0 ? 'background:rgba(220,53,69,.04)' : '' ?>">
                    <td>
                        <a href="/modules/cows/view.php?id=<?= $row['id'] ?>" style="font-weight:700">#<?= e($row['tag_number']) ?></a>
                    </td>
                    <td style="font-size:.82rem"><?= e($row['breed']) ?></td>
                    <td><span class="badge badge-<?= match($row['status']) {
                        'active' => 'success', 'sold' => 'info', 'deceased' => 'danger', default => 'secondary'
                    } ?>"><?= e($row['status']) ?></span></td>
                    <td style="text-align:right;font-size:.88rem"><?= number_format($row['milk_liters'],1) ?></td>
                    <td style="text-align:right">
                        <?= $row['milk_revenue'] > 0 ? '৳'.number_format($row['milk_revenue'],0) : '<span style="color:var(--text-muted)">—</span>' ?>
                    </td>
                    <td style="text-align:right;font-size:.88rem">
                        <?= $row['sale_revenue'] > 0 ? '৳'.number_format($row['sale_revenue'],0) : '—' ?>
                    </td>
                    <td style="text-align:right;color:var(--danger);font-size:.88rem">
                        <?= $row['feed_cost'] > 0 ? '৳'.number_format($row['feed_cost'],0) : '—' ?>
                    </td>
                    <td style="text-align:right;color:#e65100;font-size:.88rem">
                        <?= $row['medicine_cost'] > 0 ? '৳'.number_format($row['medicine_cost'],0) : '—' ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:<?= $net >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                        <?= $net >= 0 ? '' : '-' ?>৳<?= number_format(abs($net),0) ?>
                        <?php if ($roi !== null): ?>
                        <div style="font-size:.7rem;font-weight:400;color:var(--text-muted)"><?= $roi ?>% ROI</div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.78rem">
                        <?php if ($eff !== null): ?>
                        <span style="color:<?= $eff >= 1.5 ? 'var(--success)' : '#e65100' ?>">
                            <?= $eff ?> L/kg
                        </span>
                        <?php else: ?>
                        <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- Totals row -->
                <?php
                $t_milk_r = array_sum(array_column($cow_rows,'milk_revenue'));
                $t_sale_r = array_sum(array_column($cow_rows,'sale_revenue'));
                $t_feed   = array_sum(array_column($cow_rows,'feed_cost'));
                $t_med    = array_sum(array_column($cow_rows,'medicine_cost'));
                $t_liters = array_sum(array_column($cow_rows,'milk_liters'));
                $t_net    = ($t_milk_r + $t_sale_r) - ($t_feed + $t_med);
                ?>
                <tr style="background:var(--bg-muted,#f5f7fa);font-weight:700;border-top:2px solid var(--border)">
                    <td colspan="3">Totals (<?= count($cow_rows) ?> cows)</td>
                    <td style="text-align:right"><?= number_format($t_liters,1) ?></td>
                    <td style="text-align:right">৳<?= number_format($t_milk_r,0) ?></td>
                    <td style="text-align:right">৳<?= number_format($t_sale_r,0) ?></td>
                    <td style="text-align:right;color:var(--danger)">৳<?= number_format($t_feed,0) ?></td>
                    <td style="text-align:right;color:#e65100">৳<?= number_format($t_med,0) ?></td>
                    <td style="text-align:right;color:<?= $t_net >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                        <?= $t_net >= 0 ? '' : '-' ?>৳<?= number_format(abs($t_net),0) ?>
                    </td>
                    <td></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($cow_rows)): ?>
<!-- Note on data completeness -->
<p style="margin-top:.75rem;font-size:.75rem;color:var(--text-muted)">
    * Milk Revenue uses price snapshots stored at record time (historical locking).
    Records without a price snapshot are excluded from revenue but still counted in liters.
    Feed and medicine costs require entries in Feed Log and Treatments respectively.
</p>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
