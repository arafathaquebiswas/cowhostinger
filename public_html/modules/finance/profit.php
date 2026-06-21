<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireModule('finance');
if (!canAccess('finance.view')) requireAccess('finance.view');

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Core financial calculator ─────────────────────────────────────────────────
function computePeriodFinancials(PDO $db, string $from, string $to): array {
    $ff = farmFilter();

    $q = $db->prepare("SELECT COALESCE(SUM(milk_value),0) FROM milk_records WHERE {$ff} AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ?");
    $q->execute([$from,$to]); $rev_milk = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(sale_price),0) FROM cow_sales WHERE {$ff} AND sale_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $rev_cow = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(total_revenue),0) FROM meat_sales WHERE {$ff} AND sale_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $rev_meat = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE {$ff} AND type='income' AND transaction_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $rev_manual = (float)$q->fetchColumn();

    $rev_total = $rev_milk + $rev_cow + $rev_meat + $rev_manual;

    $q = $db->prepare("SELECT COALESCE(SUM(cost),0) FROM treatments WHERE {$ff} AND treatment_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $exp_treat = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(GREATEST(0,DATEDIFF(LEAST(IFNULL(termination_date,?),?),GREATEST(hire_date,?))+1)*salary/30.4375),0) FROM workers WHERE {$ff} AND hire_date<=? AND (termination_date IS NULL OR termination_date>=?)");
    $q->execute([$to,$to,$from,$to,$from]); $exp_workers = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(cost),0) FROM maintenance_logs WHERE {$ff} AND completed_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $exp_maint = (float)$q->fetchColumn();

    $q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE {$ff} AND type='expense' AND transaction_date BETWEEN ? AND ?");
    $q->execute([$from,$to]); $exp_manual = (float)$q->fetchColumn();

    $exp_total  = $exp_treat + $exp_workers + $exp_maint + $exp_manual;
    $net_profit = $rev_total - $exp_total;
    $margin     = $rev_total > 0 ? $net_profit / $rev_total * 100 : 0.0;

    return compact(
        'from','to',
        'rev_milk','rev_cow','rev_meat','rev_manual','rev_total',
        'exp_treat','exp_workers','exp_maint','exp_manual','exp_total',
        'net_profit','margin'
    );
}

// ── Parse a period from GET params ───────────────────────────────────────────
function parsePeriod(string $type, string $val, string $df, string $dt): ?array {
    if ($type === 'month' && preg_match('/^\d{4}-\d{2}$/', $val)) {
        $from  = $val . '-01';
        $to    = date('Y-m-t', strtotime($from));
        $label = date('F Y',   strtotime($from));
        return compact('from','to','label','type');
    }
    if ($type === 'year' && preg_match('/^\d{4}$/', $val)) {
        $cy    = (int)date('Y');
        $from  = $val . '-01-01';
        $to    = ((int)$val === $cy) ? date('Y-m-d') : $val . '-12-31';
        $label = 'Year ' . $val . ((int)$val === $cy ? ' (YTD)' : '');
        return compact('from','to','label','type');
    }
    if ($type === 'custom'
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        if ($df > $dt) [$df,$dt] = [$dt,$df];
        $from  = $df; $to = $dt;
        $label = date('d M Y', strtotime($df)) . ' – ' . date('d M Y', strtotime($dt));
        return compact('from','to','label','type');
    }
    return null;
}

// ── 12-month trend via GROUP BY (efficient: 12 queries, not 12×sources) ───────
function buildTrend(PDO $db): array {
    $months = []; $idx = [];
    for ($i = 11; $i >= 0; $i--) {
        $ts  = strtotime("-{$i} months");
        $yr  = (int)date('Y',$ts); $mo = (int)date('n',$ts);
        $k   = count($months);
        $idx[$yr][$mo] = $k;
        $months[$k] = ['label'=>date('M Y',$ts),'from'=>date('Y-m-01',$ts),'to'=>date('Y-m-t',$ts),'yr'=>$yr,'mo'=>$mo,'rev'=>0.0,'exp'=>0.0];
    }
    $min = $months[0]['from']; $max = $months[11]['to'];
    $ff  = farmFilter();

    $add = function(array $rows, string $f) use (&$months,$idx) {
        foreach ($rows as $r) {
            $i = $idx[(int)$r['yr']][(int)$r['mo']] ?? null;
            if ($i !== null) $months[$i][$f] += (float)$r['total'];
        }
    };

    $sources = [
        ["SELECT YEAR(DATE(recorded_at)) yr,MONTH(DATE(recorded_at)) mo,SUM(milk_value) total FROM milk_records WHERE {$ff} AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ? GROUP BY yr,mo",'rev'],
        ["SELECT YEAR(sale_date) yr,MONTH(sale_date) mo,SUM(sale_price) total FROM cow_sales WHERE {$ff} AND sale_date BETWEEN ? AND ? GROUP BY yr,mo",'rev'],
        ["SELECT YEAR(sale_date) yr,MONTH(sale_date) mo,SUM(total_revenue) total FROM meat_sales WHERE {$ff} AND sale_date BETWEEN ? AND ? GROUP BY yr,mo",'rev'],
        ["SELECT YEAR(transaction_date) yr,MONTH(transaction_date) mo,SUM(amount) total FROM finance_transactions WHERE {$ff} AND type='income' AND transaction_date BETWEEN ? AND ? GROUP BY yr,mo",'rev'],
        ["SELECT YEAR(treatment_date) yr,MONTH(treatment_date) mo,SUM(cost) total FROM treatments WHERE {$ff} AND treatment_date BETWEEN ? AND ? GROUP BY yr,mo",'exp'],
        ["SELECT YEAR(completed_date) yr,MONTH(completed_date) mo,SUM(cost) total FROM maintenance_logs WHERE {$ff} AND completed_date BETWEEN ? AND ? GROUP BY yr,mo",'exp'],
        ["SELECT YEAR(transaction_date) yr,MONTH(transaction_date) mo,SUM(amount) total FROM finance_transactions WHERE {$ff} AND type='expense' AND transaction_date BETWEEN ? AND ? GROUP BY yr,mo",'exp'],
    ];
    foreach ($sources as [$sql,$f]) { $q=$db->prepare($sql); $q->execute([$min,$max]); $add($q->fetchAll(),$f); }

    // Worker salaries — per-month proration (PHP loop is simple & correct)
    $ws = $db->query("SELECT salary,hire_date,termination_date FROM workers WHERE {$ff}")->fetchAll();
    foreach ($months as &$m) {
        foreach ($ws as $w) {
            if ($w['hire_date'] > $m['to']) continue;
            if ($w['termination_date'] !== null && $w['termination_date'] < $m['from']) continue;
            $s = max($w['hire_date'], $m['from']);
            $e = min($w['termination_date'] ?? $m['to'], $m['to']);
            $days = max(0, (int)((strtotime($e)-strtotime($s))/86400)+1);
            $m['exp'] += $days * (float)$w['salary'] / 30.4375;
        }
    }
    unset($m);
    return $months;
}

// ── POST: income / expense CRUD ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error','Invalid request.'); redirect('/modules/finance/profit.php');
    }
    requireNotBlocked();
    $action = $_POST['action'] ?? '';
    $redir  = '/modules/finance/profit.php?tab=' . ($_POST['f_tab'] ?? 'overview');

    if ($action === 'add_income') {
        $cat=sanitize($_POST['category']??'Other'); $amt=(float)($_POST['amount']??0); $dt=trim($_POST['income_date']??''); $ntx=sanitize($_POST['notes']??'');
        if ($amt<=0) flashMessage('error','Amount must be > 0.');
        elseif (!strtotime($dt)) flashMessage('error','Invalid date.');
        else { $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,transaction_date,notes,recorded_by) VALUES(?,?,?,?,?,?,?)")->execute([fid(),'income',$cat,$amt,$dt,$ntx?:null,$uid]); auditLog($uid,'ADD_INCOME','finance_transactions',(int)$db->lastInsertId(),null,compact('amt','cat')); flashMessage('success','Income recorded.'); }
    } elseif ($action === 'add_expense') {
        $cat=sanitize($_POST['category']??'Miscellaneous'); $amt=(float)($_POST['amount']??0); $dt=trim($_POST['expense_date']??''); $ntx=sanitize($_POST['notes']??'');
        if ($amt<=0) flashMessage('error','Amount must be > 0.');
        elseif (!strtotime($dt)) flashMessage('error','Invalid date.');
        else { $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,transaction_date,notes,recorded_by) VALUES(?,?,?,?,?,?,?)")->execute([fid(),'expense',$cat,$amt,$dt,$ntx?:null,$uid]); auditLog($uid,'ADD_EXPENSE','finance_transactions',(int)$db->lastInsertId(),null,compact('amt','cat')); flashMessage('success','Expense recorded.'); }
    } elseif ($action === 'delete_income' && hasRole(['admin'])) {
        $rid=(int)($_POST['row_id']??0); $r=$db->prepare("SELECT * FROM finance_transactions WHERE id=? AND type='income' AND ".farmFilter()); $r->execute([$rid]);
        if ($row=$r->fetch()) { $db->prepare("DELETE FROM finance_transactions WHERE id=? AND type='income' AND ".farmFilter())->execute([$rid]); auditLog($uid,'DELETE_INCOME','finance_transactions',$rid,$row,null); flashMessage('success','Deleted.'); }
    } elseif ($action === 'delete_expense' && hasRole(['admin'])) {
        $rid=(int)($_POST['row_id']??0); $r=$db->prepare("SELECT * FROM finance_transactions WHERE id=? AND type='expense' AND ".farmFilter()); $r->execute([$rid]);
        if ($row=$r->fetch()) { $db->prepare("DELETE FROM finance_transactions WHERE id=? AND type='expense' AND ".farmFilter())->execute([$rid]); auditLog($uid,'DELETE_EXPENSE','finance_transactions',$rid,$row,null); flashMessage('success','Deleted.'); }
    }
    redirect($redir);
}

// ── Routing: tab + preset ─────────────────────────────────────────────────────
$tab    = in_array($_GET['tab'] ?? '', ['overview','compare'], true) ? $_GET['tab'] : 'overview';
$preset = in_array($_GET['preset'] ?? '', ['monthly','yearly'], true) ? $_GET['preset'] : '';

// ── 4 fixed periods ───────────────────────────────────────────────────────────
$today = date('Y-m-d'); $year = (int)date('Y');

$fp = [
    'tm' => ['from'=>date('Y-m-01'),                                      'to'=>$today,                                         'label'=>'This Month',  'color'=>'#16a34a','soft'=>'#f0fdf4','icon'=>'▲'],
    'lm' => ['from'=>date('Y-m-01',strtotime('first day of last month')), 'to'=>date('Y-m-t',strtotime('last day of last month')),'label'=>'Last Month',  'color'=>'#d97706','soft'=>'#fffbeb','icon'=>'◉'],
    'ty' => ['from'=>date('Y-01-01'),                                     'to'=>$today,                                         'label'=>'This Year',   'color'=>'#0284c7','soft'=>'#eff6ff','icon'=>'●'],
    'ly' => ['from'=>($year-1).'-01-01',                                  'to'=>($year-1).'-12-31',                             'label'=>'Last Year',   'color'=>'#7c3aed','soft'=>'#f5f3ff','icon'=>'○'],
];

foreach ($fp as $key => $p) {
    $fp[$key] = array_merge($p, computePeriodFinancials($db, $p['from'], $p['to']));
}

// ── Last Year YTD (for accurate YoY smart insight) ────────────────────────────
$ly_ytd_to   = ($year-1) . substr($today, 4);
if ($ly_ytd_to > ($year-1).'-12-31') $ly_ytd_to = ($year-1).'-12-31';
$fin_ly_ytd  = computePeriodFinancials($db, ($year-1).'-01-01', $ly_ytd_to);

// ── 12-month trend ────────────────────────────────────────────────────────────
$trend = buildTrend($db);

// ── Per-cow profitability (this month) ────────────────────────────────────────
$cow_stmt = $db->prepare(
    "SELECT c.id, c.tag_number, c.breed AS cow_name,
        COALESCE(SUM(mr.milk_value),0) AS milk_rev,
        COALESCE((SELECT SUM(t.cost) FROM treatments t WHERE t.cow_id=c.id AND ".farmFilter('t')." AND t.treatment_date BETWEEN ? AND ?),0) AS treat_cost
    FROM cows c
    LEFT JOIN milk_records mr ON mr.cow_id=c.id AND ".farmFilter('mr')." AND mr.contamination_flag=0 AND DATE(mr.recorded_at) BETWEEN ? AND ?
    WHERE ".farmFilter('c')."
    GROUP BY c.id,c.tag_number,c.breed
    HAVING milk_rev>0 OR treat_cost>0
    ORDER BY (milk_rev-treat_cost) DESC LIMIT 30"
);
$tmf = $fp['tm']['from']; $tmt = $fp['tm']['to'];
$cow_stmt->execute([$tmf,$tmt, $tmf,$tmt]);
$cow_rows = $cow_stmt->fetchAll();
foreach ($cow_rows as &$cr) {
    $cr['net'] = $cr['milk_rev'] - $cr['treat_cost'];
}
unset($cr);

// ── Smart Insights ────────────────────────────────────────────────────────────
$insights = [];

// Revenue: TM vs LM
if ($fp['lm']['rev_total'] > 0) {
    $chg = ($fp['tm']['rev_total'] - $fp['lm']['rev_total']) / $fp['lm']['rev_total'] * 100;
    $up  = $chg >= 0;
    $insights[] = ['ok'=>$up, 'icon'=>$up?'📈':'📉', 'text'=>'Revenue is <strong>'.number_format(abs($chg),1).'% '.($up?'higher':'lower').'</strong> than last month'];
}
// Profit: TM vs LM
if ($fp['lm']['net_profit'] != 0) {
    $chg = ($fp['tm']['net_profit'] - $fp['lm']['net_profit']) / abs($fp['lm']['net_profit']) * 100;
    $up  = $chg >= 0;
    $insights[] = ['ok'=>$up, 'icon'=>$up?'💰':'⚠️', 'text'=>'Net profit <strong>'.($up?'improved':'declined').' by '.number_format(abs($chg),1).'%</strong> vs last month'];
}
// YoY
if ($fin_ly_ytd['net_profit'] != 0) {
    $chg = ($fp['ty']['net_profit'] - $fin_ly_ytd['net_profit']) / abs($fin_ly_ytd['net_profit']) * 100;
    $up  = $chg >= 0;
    $insights[] = ['ok'=>$up, 'icon'=>$up?'🚀':'📉', 'text'=>'YTD profit is <strong>'.number_format(abs($chg),1).'% '.($up?'ahead of':'behind').'</strong> the same period last year'];
}
// Top expense category
$exp_cats = ['Vet Treatments'=>$fp['tm']['exp_treat'],'Worker Salaries'=>$fp['tm']['exp_workers'],'Maintenance'=>$fp['tm']['exp_maint'],'Manual Expenses'=>$fp['tm']['exp_manual']];
if ($fp['tm']['exp_total'] > 0) {
    $max_cat = (string)array_search(max($exp_cats),$exp_cats);
    $max_pct = round(max($exp_cats)/$fp['tm']['exp_total']*100);
    $insights[] = ['ok'=>null,'icon'=>'🏷️','text'=>"<strong>{$max_cat}</strong> is your largest expense this month ({$max_pct}% of total costs)"];
}
// Best cow
if (!empty($cow_rows)) {
    $best = $cow_rows[0];
    if ($best['net'] > 0) {
        $name = $best['cow_name'] ?: '#'.$best['tag_number'];
        $insights[] = ['ok'=>true,'icon'=>'🐄','text'=>"Most profitable cow this month: <strong>".e($name)."</strong> (৳".number_format($best['net'],2)." net)"];
    }
}

// ── Comparison periods ────────────────────────────────────────────────────────
$cmp = []; $cmp_active = false;
$pa_type = 'month'; $pb_type = 'month'; $pc_type = '';
$pa_val  = date('Y-m'); $pb_val = date('Y-m',strtotime('last month')); $pc_val = '';
$pa_from = ''; $pa_to = ''; $pb_from = ''; $pb_to = ''; $pc_from = ''; $pc_to = '';

if ($tab === 'compare') {
    // Apply presets if no explicit params submitted
    $has_params = isset($_GET['pa_val']) || isset($_GET['pa_type']);

    if (!$has_params && $preset === 'monthly') {
        $pa_type='month'; $pa_val=date('Y-m');
        $pb_type='month'; $pb_val=date('Y-m',strtotime('last month'));
        $pc_type='month'; $pc_val=date('Y-m',strtotime('-2 months'));
    } elseif (!$has_params && $preset === 'yearly') {
        $pa_type='year'; $pa_val=(string)$year;
        $pb_type='year'; $pb_val=(string)($year-1);
        $pc_type='';
    } else {
        $pa_type = in_array($_GET['pa_type']??'',['month','year','custom'],true) ? $_GET['pa_type'] : 'month';
        $pb_type = in_array($_GET['pb_type']??'',['month','year','custom'],true) ? $_GET['pb_type'] : 'month';
        $pc_type = in_array($_GET['pc_type']??'',['month','year','custom'],true) ? $_GET['pc_type'] : '';
        $pa_val  = $_GET['pa_val']  ?? date('Y-m');
        $pb_val  = $_GET['pb_val']  ?? date('Y-m',strtotime('last month'));
        $pc_val  = $_GET['pc_val']  ?? '';
        $pa_from = $_GET['pa_from'] ?? ''; $pa_to = $_GET['pa_to'] ?? '';
        $pb_from = $_GET['pb_from'] ?? ''; $pb_to = $_GET['pb_to'] ?? '';
        $pc_from = $_GET['pc_from'] ?? ''; $pc_to = $_GET['pc_to'] ?? '';
    }

    $parsed_a = parsePeriod($pa_type, $pa_val, $pa_from, $pa_to);
    $parsed_b = parsePeriod($pb_type, $pb_val, $pb_from, $pb_to);
    $parsed_c = $pc_type ? parsePeriod($pc_type, $pc_val, $pc_from, $pc_to) : null;

    if ($parsed_a) $cmp['a'] = array_merge($parsed_a, computePeriodFinancials($db, $parsed_a['from'], $parsed_a['to']));
    if ($parsed_b) $cmp['b'] = array_merge($parsed_b, computePeriodFinancials($db, $parsed_b['from'], $parsed_b['to']));
    if ($parsed_c) $cmp['c'] = array_merge($parsed_c, computePeriodFinancials($db, $parsed_c['from'], $parsed_c['to']));
    $cmp_active = !empty($cmp['a']) && !empty($cmp['b']);
}

// ── Recent other income / misc expense for sidebar ────────────────────────────
$oi_rows = $db->prepare("SELECT id,category,amount,transaction_date AS income_date,notes FROM finance_transactions WHERE ".farmFilter()." AND type='income' ORDER BY transaction_date DESC LIMIT 8"); $oi_rows->execute(); $oi_rows=$oi_rows->fetchAll();
$me_rows = $db->prepare("SELECT id,category,amount,transaction_date AS expense_date,notes FROM finance_transactions WHERE ".farmFilter()." AND type='expense' ORDER BY transaction_date DESC LIMIT 8"); $me_rows->execute(); $me_rows=$me_rows->fetchAll();

// ── Chart JSON ────────────────────────────────────────────────────────────────
$chart_labels = json_encode(array_column($trend,'label'));
$chart_rev    = json_encode(array_map(fn($r)=>round($r['rev'],2),$trend));
$chart_exp    = json_encode(array_map(fn($r)=>round($r['exp'],2),$trend));
$chart_profit = json_encode(array_map(fn($r)=>round($r['rev']-$r['exp'],2),$trend));

// ── Active nav ────────────────────────────────────────────────────────────────
if ($tab === 'compare') {
    if ($preset === 'monthly')      $active_nav = 'profit_monthly';
    elseif ($preset === 'yearly')   $active_nav = 'profit_yearly';
    else                            $active_nav = 'profit_compare';
} else {
    $active_nav = 'profit_engine';
}

$page_title = 'Financial Intelligence';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

// ── View helpers ──────────────────────────────────────────────────────────────
function fmt(float $v): string { return '৳'.number_format(abs($v),2); }
function pct_change(float $base, float $new): ?float {
    if ($base == 0) return null;
    return ($new - $base) / abs($base) * 100;
}
function render_delta(?float $pct, bool $inverse = false): string {
    if ($pct === null) return '<span class="text-muted">—</span>';
    $good = $inverse ? ($pct <= 0) : ($pct >= 0);
    $col  = $good ? '#16a34a' : '#dc2626';
    $arrow = $pct >= 0 ? '▲' : '▼';
    return "<span style='color:{$col};font-weight:600;font-size:.82rem'>{$arrow} ".number_format(abs($pct),1)."%</span>";
}
?>

<!-- ── Page header + tab switcher ─────────────────────────────────────────── -->
<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div>
        <h2>Financial Intelligence</h2>
        <p class="text-muted text-sm">Farm-wide revenue, expenses &amp; profitability</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="?tab=overview" class="btn btn-sm <?= $tab==='overview' ? 'btn-primary' : 'btn-secondary' ?>">Overview</a>
        <a href="?tab=compare<?= $preset ? '&preset='.$preset : '' ?>" class="btn btn-sm <?= $tab==='compare' ? 'btn-primary' : 'btn-secondary' ?>">Compare Periods</a>
    </div>
</div>

<?php if ($tab === 'overview'): ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     OVERVIEW TAB
══════════════════════════════════════════════════════════════════════════════ -->

<!-- ── Smart Insights ─────────────────────────────────────────────────────── -->
<?php if (!empty($insights)): ?>
<div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.25rem">
<?php foreach ($insights as $ins): ?>
<div style="flex:1;min-width:220px;background:<?= $ins['ok']===true?'#f0fdf4':($ins['ok']===false?'#fef2f2':'#eff6ff') ?>;border:1px solid <?= $ins['ok']===true?'#86efac':($ins['ok']===false?'#fca5a5':'#bfdbfe') ?>;border-radius:8px;padding:.65rem .9rem;display:flex;gap:.6rem;align-items:flex-start">
    <span style="font-size:1.1rem;line-height:1"><?= $ins['icon'] ?></span>
    <span class="text-sm" style="color:var(--text-primary)"><?= $ins['text'] ?></span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── 4 KPI Group Cards ────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1.5rem">
<?php foreach ($fp as $key => $p):
    $is_profit = $p['net_profit'] >= 0;
    $lm_compare = null;
    if ($key === 'tm' && $fp['lm']['rev_total'] > 0) $lm_compare = pct_change($fp['lm']['net_profit'],$p['net_profit']);
    if ($key === 'ty' && $fin_ly_ytd['rev_total'] > 0) $lm_compare = pct_change($fin_ly_ytd['net_profit'],$p['net_profit']);
?>
<div class="card" style="border-top:3px solid <?= $p['color'] ?>">
    <div class="card-body" style="padding:.85rem 1rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.7rem">
            <span style="font-weight:700;font-size:.82rem;color:<?= $p['color'] ?>;text-transform:uppercase;letter-spacing:.04em"><?= $p['label'] ?></span>
            <?php if ($lm_compare !== null): echo render_delta($lm_compare); endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:.3rem">
            <div style="display:flex;justify-content:space-between;font-size:.88rem">
                <span class="text-muted">Revenue</span>
                <span style="font-weight:600;color:#15803d"><?= fmt($p['rev_total']) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.88rem">
                <span class="text-muted">Expenses</span>
                <span style="font-weight:600;color:#b91c1c"><?= fmt($p['exp_total']) ?></span>
            </div>
            <div style="border-top:1px solid var(--border);margin:.3rem 0"></div>
            <div style="display:flex;justify-content:space-between">
                <span style="font-weight:700;font-size:.88rem;color:<?= $is_profit?'#15803d':'#b91c1c' ?>"><?= $is_profit ? 'Net Profit' : 'Net Loss' ?></span>
                <div style="text-align:right">
                    <span style="font-weight:800;font-size:1.05rem;color:<?= $is_profit?'#15803d':'#b91c1c' ?>"><?= ($p['net_profit']<0?'-':'').fmt($p['net_profit']) ?></span>
                    <?php if ($p['rev_total'] > 0): ?>
                    <div style="font-size:.73rem;color:var(--text-secondary)"><?= number_format($p['margin'],1) ?>% margin</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── 12-Month Trend Chart ──────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">12-Month Revenue &amp; Profit Trend</h3>
        <div style="display:flex;gap:.5rem">
            <span style="font-size:.78rem;color:#16a34a;font-weight:600">■ Revenue</span>
            <span style="font-size:.78rem;color:#dc2626;font-weight:600">■ Expenses</span>
            <span style="font-size:.78rem;color:#7c3aed;font-weight:600">── Profit</span>
        </div>
    </div>
    <div style="padding:1rem 1rem .5rem">
        <canvas id="trendChart" height="75"></canvas>
    </div>
</div>

<!-- ── Expense Breakdown (This Month) ───────────────────────────────────── -->
<?php if ($fp['tm']['exp_total'] > 0): ?>
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h3>This Month — Expense Breakdown</h3></div>
    <div class="card-body" style="padding:.6rem 1rem">
        <?php
        $exp_items = [
            ['Vet Treatments',   $fp['tm']['exp_treat'],   '#7c3aed'],
            ['Worker Salaries',  $fp['tm']['exp_workers'], '#16a34a'],
            ['Maintenance',      $fp['tm']['exp_maint'],   '#6b7280'],
            ['Manual Expenses',  $fp['tm']['exp_manual'],  '#d97706'],
        ];
        foreach ($exp_items as [$lbl,$val,$col]):
            if ($val <= 0) continue;
            $w = min(100,round($val/$fp['tm']['exp_total']*100));
        ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.3rem 0;border-bottom:1px solid var(--border)">
            <div style="width:110px;font-size:.82rem;color:var(--text-secondary);flex-shrink:0"><?= $lbl ?></div>
            <div style="flex:1;height:6px;background:var(--border);border-radius:3px">
                <div style="height:6px;border-radius:3px;background:<?= $col ?>;width:<?= $w ?>%"></div>
            </div>
            <div style="width:80px;text-align:right;font-weight:600;color:<?= $col ?>;font-size:.85rem"><?= fmt($val) ?></div>
            <div style="width:36px;text-align:right;font-size:.75rem;color:var(--text-secondary)"><?= $w ?>%</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Monthly Trend Table ────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h3>Monthly Summary (Last 12 Months)</h3></div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem;min-width:560px">
            <thead>
                <tr>
                    <th>Month</th>
                    <th class="text-right">Revenue</th>
                    <th class="text-right">Expenses</th>
                    <th class="text-right">Net Profit</th>
                    <th class="text-right">Margin</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_reverse($trend) as $tm):
                $tp = $tm['rev'] - $tm['exp'];
                $mg = $tm['rev'] > 0 ? $tp/$tm['rev']*100 : 0;
            ?>
            <tr>
                <td><?= e($tm['label']) ?></td>
                <td class="text-right" style="color:#15803d"><?= fmt($tm['rev']) ?></td>
                <td class="text-right" style="color:#b91c1c"><?= fmt($tm['exp']) ?></td>
                <td class="text-right" style="font-weight:700;color:<?= $tp>=0?'#15803d':'#b91c1c' ?>"><?= ($tp<0?'-':'').fmt($tp) ?></td>
                <td class="text-right" style="color:<?= $mg>=0?'#15803d':'#b91c1c' ?>"><?= number_format($mg,1) ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Per-Cow Profitability (This Month) ──────────────────────────────── -->
<?php if (!empty($cow_rows)): ?>
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header">
        <h3>Per-Cow Profitability — <?= e($fp['tm']['label']) ?></h3>
    </div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem">
            <thead>
                <tr><th>Cow</th><th class="text-right">Milk Rev.</th><th class="text-right">Treatments</th><th class="text-right">Net</th></tr>
            </thead>
            <tbody>
            <?php foreach ($cow_rows as $cr): ?>
            <tr>
                <td><a href="/modules/cows/profile.php?id=<?= $cr['id'] ?>"><strong><?= e($cr['cow_name']?:'—') ?></strong> <span class="text-muted text-xs">#<?= e($cr['tag_number']) ?></span></a></td>
                <td class="text-right" style="color:#15803d"><?= fmt($cr['milk_rev']) ?></td>
                <td class="text-right" style="color:#7c3aed"><?= fmt($cr['treat_cost']) ?></td>
                <td class="text-right" style="font-weight:700;color:<?= $cr['net']>=0?'#15803d':'#b91c1c' ?>"><?= ($cr['net']<0?'-':'').fmt($cr['net']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Quick Add Forms ────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.25rem;margin-bottom:1.5rem">

    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #16a34a"><h3 style="margin:0">+ Other Income</h3></div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?><input type="hidden" name="action" value="add_income"><input type="hidden" name="f_tab" value="overview">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                    <div class="form-group" style="margin:0"><label class="form-label">Category</label>
                        <select name="category" class="form-control form-control-sm">
                            <option>Manure Sale</option><option>Skin Sale</option><option>Milk Byproduct</option><option>Subsidy</option><option>Other</option>
                        </select></div>
                    <div class="form-group" style="margin:0"><label class="form-label">Amount (৳)</label><input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" placeholder="0.00" required></div>
                    <div class="form-group" style="margin:0"><label class="form-label">Date</label><input type="date" name="income_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group" style="margin:0"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional"></div>
                </div>
                <button type="submit" class="btn btn-sm btn-success" style="margin-top:.65rem;width:100%">Record Income</button>
            </form>
            <?php foreach ($oi_rows as $oi): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.82rem;padding:.25rem 0;border-bottom:1px solid var(--border)">
                <div><span><?= e($oi['category']) ?></span> <span class="text-muted text-xs"><?= e(formatDate($oi['income_date'])) ?></span></div>
                <div style="display:flex;align-items:center;gap:.4rem">
                    <span style="color:#15803d;font-weight:600"><?= fmt((float)$oi['amount']) ?></span>
                    <?php if (hasRole(['admin'])): ?><form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_income"><input type="hidden" name="row_id" value="<?= $oi['id'] ?>"><input type="hidden" name="f_tab" value="overview"><button type="submit" class="btn btn-xs btn-danger">✕</button></form><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #dc2626"><h3 style="margin:0">+ Misc Expense</h3></div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?><input type="hidden" name="action" value="add_expense"><input type="hidden" name="f_tab" value="overview">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                    <div class="form-group" style="margin:0"><label class="form-label">Category</label>
                        <select name="category" class="form-control form-control-sm">
                            <option>Utility Bill</option><option>Transportation</option><option>Equipment Repair</option><option>Land Lease</option><option>Insurance</option><option>Miscellaneous</option>
                        </select></div>
                    <div class="form-group" style="margin:0"><label class="form-label">Amount (৳)</label><input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" placeholder="0.00" required></div>
                    <div class="form-group" style="margin:0"><label class="form-label">Date</label><input type="date" name="expense_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="form-group" style="margin:0"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional"></div>
                </div>
                <button type="submit" class="btn btn-sm btn-danger" style="margin-top:.65rem;width:100%">Record Expense</button>
            </form>
            <?php foreach ($me_rows as $me): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.82rem;padding:.25rem 0;border-bottom:1px solid var(--border)">
                <div><span><?= e($me['category']) ?></span> <span class="text-muted text-xs"><?= e(formatDate($me['expense_date'])) ?></span></div>
                <div style="display:flex;align-items:center;gap:.4rem">
                    <span style="color:#b91c1c;font-weight:600"><?= fmt((float)$me['amount']) ?></span>
                    <?php if (hasRole(['admin'])): ?><form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="row_id" value="<?= $me['id'] ?>"><input type="hidden" name="f_tab" value="overview"><button type="submit" class="btn btn-xs btn-danger">✕</button></form><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php else: /* ═══ COMPARE TAB ══════════════════════════════════════════════ */ ?>

<!-- ── Period Selector ─────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header">
        <h3>Select Periods to Compare</h3>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <a href="?tab=compare&preset=monthly" class="btn btn-sm <?= $preset==='monthly'?'btn-primary':'btn-secondary' ?>">This Month vs Last Month</a>
            <a href="?tab=compare&preset=yearly"  class="btn btn-sm <?= $preset==='yearly' ?'btn-primary':'btn-secondary' ?>">This Year vs Last Year</a>
            <button class="btn btn-sm btn-secondary" onclick="setQ1Preset()">Jan–Feb–Mar</button>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" id="cmpForm">
            <input type="hidden" name="tab" value="compare">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem">
                <?php foreach (['a'=>'A (Required)','b'=>'B (Required)','c'=>'C (Optional)'] as $pid=>$plabel): ?>
                <div style="border:1px solid var(--border);border-radius:8px;padding:.85rem">
                    <div style="font-weight:700;font-size:.85rem;margin-bottom:.6rem;color:var(--text-primary)">Period <?= $plabel ?></div>
                    <div class="form-group" style="margin-bottom:.5rem">
                        <label class="form-label" style="font-size:.8rem">Type</label>
                        <select name="p<?= $pid ?>_type" class="form-control form-control-sm" onchange="togglePeriodInputs('<?= $pid ?>')">
                            <option value="month"  <?= ($pid==='a'?$pa_type:($pid==='b'?$pb_type:$pc_type))==='month' ?'selected':'' ?>>Month</option>
                            <option value="year"   <?= ($pid==='a'?$pa_type:($pid==='b'?$pb_type:$pc_type))==='year'  ?'selected':'' ?>>Year</option>
                            <option value="custom" <?= ($pid==='a'?$pa_type:($pid==='b'?$pb_type:$pc_type))==='custom'?'selected':'' ?>>Custom Range</option>
                        </select>
                    </div>
                    <?php
                    $cur_type = $pid==='a'?$pa_type:($pid==='b'?$pb_type:$pc_type);
                    $cur_val  = $pid==='a'?$pa_val :($pid==='b'?$pb_val :$pc_val);
                    $cur_from = $pid==='a'?$pa_from:($pid==='b'?$pb_from:$pc_from);
                    $cur_to   = $pid==='a'?$pa_to  :($pid==='b'?$pb_to  :$pc_to);
                    ?>
                    <div id="prd-<?= $pid ?>-month" style="display:<?= $cur_type!=='custom'?'block':'none' ?>">
                        <label class="form-label" style="font-size:.8rem"><?= $cur_type==='year'?'Year':'Month (YYYY-MM)' ?></label>
                        <input type="<?= $cur_type==='year'?'number':'month' ?>" name="p<?= $pid ?>_val"
                               class="form-control form-control-sm"
                               value="<?= e($cur_val) ?>"
                               <?= $cur_type==='year' ? 'min="2000" max="2099"' : '' ?>
                               placeholder="<?= $cur_type==='year'?date('Y'):date('Y-m') ?>">
                    </div>
                    <div id="prd-<?= $pid ?>-custom" style="display:<?= $cur_type==='custom'?'block':'none' ?>">
                        <div style="display:flex;gap:.4rem;flex-direction:column">
                            <div><label class="form-label" style="font-size:.8rem">From</label><input type="date" name="p<?= $pid ?>_from" class="form-control form-control-sm" value="<?= e($cur_from) ?>"></div>
                            <div><label class="form-label" style="font-size:.8rem">To</label><input type="date" name="p<?= $pid ?>_to" class="form-control form-control-sm" value="<?= e($cur_to) ?>"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:1rem">Run Comparison</button>
        </form>
    </div>
</div>

<!-- ── Comparison Results ──────────────────────────────────────────────────── -->
<?php if ($cmp_active): ?>

<?php
$cols   = array_keys($cmp);  // ['a','b'] or ['a','b','c']
$col_labels = array_map(fn($k) => $cmp[$k]['label'], $cols);
$has_c  = isset($cmp['c']);
?>

<!-- Comparison KPI summary -->
<div style="display:grid;grid-template-columns:repeat(<?= count($cols) ?>,1fr);gap:1rem;margin-bottom:1.25rem">
<?php foreach ($cols as $cid):
    $cd = $cmp[$cid];
    $ip = $cd['net_profit'] >= 0;
?>
<div class="card" style="border-top:3px solid <?= $cid==='a'?'#0284c7':($cid==='b'?'#d97706':'#7c3aed') ?>">
    <div class="card-body" style="padding:.85rem 1rem">
        <div style="font-weight:700;font-size:.8rem;color:<?= $cid==='a'?'#0284c7':($cid==='b'?'#d97706':'#7c3aed') ?>;text-transform:uppercase;margin-bottom:.5rem">Period <?= strtoupper($cid) ?>: <?= e($cd['label']) ?></div>
        <div style="font-size:.88rem;color:var(--text-secondary)">Revenue</div>
        <div style="font-size:1.15rem;font-weight:700;color:#15803d;margin-bottom:.3rem"><?= fmt($cd['rev_total']) ?></div>
        <div style="font-size:.88rem;color:var(--text-secondary)">Expenses</div>
        <div style="font-size:1.15rem;font-weight:700;color:#b91c1c;margin-bottom:.3rem"><?= fmt($cd['exp_total']) ?></div>
        <div style="border-top:1px solid var(--border);padding-top:.4rem;margin-top:.3rem">
            <div style="font-size:.85rem;color:var(--text-secondary)"><?= $ip?'Net Profit':'Net Loss' ?></div>
            <div style="font-size:1.3rem;font-weight:800;color:<?= $ip?'#15803d':'#b91c1c' ?>"><?= ($cd['net_profit']<0?'-':'').fmt($cd['net_profit']) ?></div>
            <?php if ($cd['rev_total']>0): ?>
            <div style="font-size:.75rem;color:var(--text-secondary)"><?= number_format($cd['margin'],1) ?>% margin</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Comparison detail table -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h3>Detailed Comparison</h3></div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.83rem;min-width:500px">
            <thead>
                <tr>
                    <th>Metric</th>
                    <?php foreach ($cols as $cid): ?>
                    <th class="text-right" style="color:<?= $cid==='a'?'#0284c7':($cid==='b'?'#d97706':'#7c3aed') ?>">Period <?= strtoupper($cid) ?><br><span style="font-weight:400;font-size:.75rem"><?= e($cmp[$cid]['label']) ?></span></th>
                    <?php endforeach; ?>
                    <th class="text-right">Δ A→B</th>
                    <th class="text-right">% A→B</th>
                    <?php if ($has_c): ?><th class="text-right">% B→C</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $cmp_metrics = [
                ['Total Revenue',      'rev_total',   false, '#15803d'],
                ['  Milk Revenue',     'rev_milk',    false, '#6b7280'],
                ['  Cow Sales',        'rev_cow',     false, '#6b7280'],
                ['  Meat Sales',       'rev_meat',    false, '#6b7280'],
                ['  Manual Income',    'rev_manual',  false, '#6b7280'],
                ['Total Expenses',     'exp_total',   true,  '#b91c1c'],
                ['  Vet Treatments',   'exp_treat',   true,  '#6b7280'],
                ['  Worker Salaries',  'exp_workers', true,  '#6b7280'],
                ['  Maintenance',      'exp_maint',   true,  '#6b7280'],
                ['  Manual Expenses',  'exp_manual',  true,  '#6b7280'],
                ['Net Profit',         'net_profit',  false, '#7c3aed'],
            ];
            foreach ($cmp_metrics as [$lbl, $key, $inv, $col]):
                $va = $cmp['a'][$key] ?? 0;
                $vb = $cmp['b'][$key] ?? 0;
                $vc = $has_c ? ($cmp['c'][$key] ?? 0) : null;
                $is_indent = str_starts_with($lbl,'  ');
                $pct_ab = pct_change($va,$vb);
                $pct_bc = $has_c ? pct_change($vb,(float)$vc) : null;
                $diff_ab = $vb - $va;
            ?>
            <tr style="<?= $is_indent?'background:var(--surface-secondary,#f9fafb)':'' ?>">
                <td style="<?= $is_indent?'color:var(--text-secondary);padding-left:1.5rem':'' ?>"><?= ltrim($lbl) ?></td>
                <?php foreach ($cols as $cid): ?>
                <td class="text-right" style="color:<?= $col ?>;font-weight:<?= $is_indent?400:600 ?>"><?= ($cmp[$cid][$key]<0?'-':'').fmt((float)($cmp[$cid][$key]??0)) ?></td>
                <?php endforeach; ?>
                <td class="text-right" style="font-weight:600;color:<?= $diff_ab>=0?'#15803d':'#b91c1c' ?>"><?= ($diff_ab>=0?'+':'-').fmt($diff_ab) ?></td>
                <td class="text-right"><?= render_delta($pct_ab,$inv) ?></td>
                <?php if ($has_c): ?><td class="text-right"><?= render_delta($pct_bc,$inv) ?></td><?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Comparison bar chart -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>Comparison Chart</h3></div>
    <div style="padding:1rem">
        <canvas id="cmpChart" height="<?= $has_c?70:55 ?>"></canvas>
    </div>
</div>

<?php endif; // cmp_active ?>

<?php // Prompt to run comparison if no periods yet ?>
<?php if (!$cmp_active && (isset($_GET['pa_val'])||isset($_GET['pa_type']))): ?>
<div class="alert alert-danger">Could not parse one or more periods. Check the values and try again.</div>
<?php elseif (!isset($_GET['pa_val']) && !$preset): ?>
<div class="alert" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8">
    Select at least Period A and Period B above and click <strong>Run Comparison</strong>. Or use a quick preset above.
</div>
<?php endif; ?>

<?php endif; // tab compare ?>

<!-- ── Chart.js ──────────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const _tk = v => '৳' + Number(v).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});

<?php if ($tab === 'overview'): ?>
(function(){
    const ctx = document.getElementById('trendChart');
    if (!ctx) return;
    new Chart(ctx, {
        data: {
            labels: <?= $chart_labels ?>,
            datasets: [
                { type:'bar',  label:'Revenue',  data:<?= $chart_rev ?>,    backgroundColor:'rgba(22,163,74,.65)',  borderColor:'#16a34a', borderWidth:1, borderRadius:3 },
                { type:'bar',  label:'Expenses', data:<?= $chart_exp ?>,    backgroundColor:'rgba(220,38,38,.55)',  borderColor:'#dc2626', borderWidth:1, borderRadius:3 },
                { type:'line', label:'Net Profit',data:<?= $chart_profit ?>, borderColor:'#7c3aed', backgroundColor:'rgba(124,58,237,.08)', borderWidth:2, pointRadius:3, tension:.3, fill:true }
            ]
        },
        options:{ responsive:true, interaction:{mode:'index',intersect:false}, plugins:{legend:{position:'top'},tooltip:{callbacks:{label:c=>' '+_tk(c.raw)}}}, scales:{y:{ticks:{callback:v=>'৳'+Number(v).toLocaleString()}}} }
    });
})();
<?php endif; ?>

<?php if ($tab === 'compare' && $cmp_active): ?>
(function(){
    const ctx = document.getElementById('cmpChart');
    if (!ctx) return;
    const colors = ['#0284c7','#d97706','#7c3aed'];
    const periods = <?= json_encode(array_map(fn($k)=>$cmp[$k]['label'], $cols)) ?>;
    const rev  = <?= json_encode(array_map(fn($k)=>round($cmp[$k]['rev_total'],2), $cols)) ?>;
    const exp  = <?= json_encode(array_map(fn($k)=>round($cmp[$k]['exp_total'],2), $cols)) ?>;
    const prof = <?= json_encode(array_map(fn($k)=>round($cmp[$k]['net_profit'],2), $cols)) ?>;
    new Chart(ctx, {
        type:'bar',
        data:{
            labels:['Revenue','Expenses','Net Profit'],
            datasets: periods.map((lbl,i) => ({
                label: lbl,
                data: [rev[i], exp[i], prof[i]],
                backgroundColor: colors[i] + 'bb',
                borderColor: colors[i],
                borderWidth:1, borderRadius:4,
            }))
        },
        options:{ responsive:true, interaction:{mode:'index',intersect:false},
            plugins:{legend:{position:'top'},tooltip:{callbacks:{label:c=>' '+_tk(c.raw)}}},
            scales:{y:{ticks:{callback:v=>'৳'+Number(v).toLocaleString()}}}
        }
    });
})();
<?php endif; ?>

function togglePeriodInputs(pid) {
    const sel = document.querySelector(`[name="p${pid}_type"]`);
    if (!sel) return;
    const t = sel.value;
    const mDiv = document.getElementById(`prd-${pid}-month`);
    const cDiv = document.getElementById(`prd-${pid}-custom`);
    const inp  = mDiv ? mDiv.querySelector('input') : null;
    if (mDiv) mDiv.style.display = t !== 'custom' ? 'block' : 'none';
    if (cDiv) cDiv.style.display = t === 'custom'  ? 'block' : 'none';
    if (inp && t === 'year')  { inp.type='number'; inp.placeholder='<?= $year ?>'; }
    if (inp && t === 'month') { inp.type='month';  inp.placeholder='<?= date('Y-m') ?>'; }
}

function setQ1Preset() {
    const y = <?= $year ?>;
    const sets = [
        {pid:'a', type:'month', val:`${y}-01`},
        {pid:'b', type:'month', val:`${y}-02`},
        {pid:'c', type:'month', val:`${y}-03`},
    ];
    sets.forEach(({pid,type,val}) => {
        const tSel = document.querySelector(`[name="p${pid}_type"]`);
        const vInp = document.querySelector(`[name="p${pid}_val"]`);
        if (tSel) { tSel.value = type; togglePeriodInputs(pid); }
        if (vInp) vInp.value = val;
    });
    document.getElementById('cmpForm')?.submit();
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
