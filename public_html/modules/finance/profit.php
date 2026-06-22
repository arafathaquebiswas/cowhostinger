<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireModule('finance');
if (!canAccess('finance.view')) requireAccess('finance.view');

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ═══════════════════════════════════════════════════════════════════════════
// CORE ACCOUNTING ENGINE
// Single source of truth: finance_transactions for ALL income/expense
// except milk income (milk_records.milk_value).
//
// DO NOT query cow_sales, meat_sales, treatments, maintenance_logs, or
// workers table for totals — they all write to finance_transactions, so
// querying both causes double-counting.
// ═══════════════════════════════════════════════════════════════════════════
function computePeriodFinancials(PDO $db, string $from, string $to): array {
    $ff = farmFilter();

    // ── INCOME ──────────────────────────────────────────────────────────────
    // Milk: in milk_records (production value, NOT in finance_transactions)
    $q = $db->prepare(
        "SELECT COALESCE(SUM(milk_value),0) FROM milk_records
         WHERE {$ff} AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ?"
    );
    $q->execute([$from,$to]);
    $rev_milk = (float)$q->fetchColumn();

    // All other income from finance_transactions — one query, CASE breakdown
    $q = $db->prepare(
        "SELECT
            SUM(CASE WHEN category='Cow Sales'      THEN amount ELSE 0 END) AS rev_cow,
            SUM(CASE WHEN category='Meat Sales'     THEN amount ELSE 0 END) AS rev_meat,
            SUM(CASE WHEN category='Byproduct Sales'THEN amount ELSE 0 END) AS rev_byproduct,
            SUM(CASE WHEN related_module='feed'     THEN amount ELSE 0 END) AS rev_feed,
            SUM(CASE WHEN related_module='medicine' THEN amount ELSE 0 END) AS rev_medicine,
            SUM(CASE WHEN related_module='equipment'THEN amount ELSE 0 END) AS rev_equipment,
            SUM(amount) AS rev_fin_total
         FROM finance_transactions
         WHERE {$ff} AND type='income' AND transaction_date BETWEEN ? AND ?"
    );
    $q->execute([$from,$to]);
    $inc = $q->fetch();

    $rev_cow       = (float)($inc['rev_cow']       ?? 0);
    $rev_meat      = (float)($inc['rev_meat']      ?? 0);
    $rev_byproduct = (float)($inc['rev_byproduct'] ?? 0);
    $rev_feed      = (float)($inc['rev_feed']      ?? 0);
    $rev_medicine  = (float)($inc['rev_medicine']  ?? 0);
    $rev_equipment = (float)($inc['rev_equipment'] ?? 0);
    $rev_fin_total = (float)($inc['rev_fin_total'] ?? 0);
    $rev_other     = max(0, $rev_fin_total - $rev_cow - $rev_meat - $rev_byproduct
                         - $rev_feed - $rev_medicine - $rev_equipment);
    $rev_total     = $rev_milk + $rev_fin_total;

    // ── EXPENSES ────────────────────────────────────────────────────────────
    $q = $db->prepare(
        "SELECT
            SUM(CASE WHEN related_module='feed'                                       THEN amount ELSE 0 END) AS exp_feed,
            SUM(CASE WHEN related_module='medicine'                                   THEN amount ELSE 0 END) AS exp_medicine,
            SUM(CASE WHEN related_module='equipment' AND category='Equipment Purchase' THEN amount ELSE 0 END) AS exp_equipment,
            SUM(CASE WHEN category='Veterinary Treatment'                             THEN amount ELSE 0 END) AS exp_treatment,
            SUM(CASE WHEN category='Maintenance Cost'                                 THEN amount ELSE 0 END) AS exp_maintenance,
            SUM(CASE WHEN category='Payroll' OR related_module='payroll'              THEN amount ELSE 0 END) AS exp_payroll,
            SUM(CASE WHEN category='Cow Death Loss'                                   THEN amount ELSE 0 END) AS exp_cow_death,
            SUM(CASE WHEN category='Family Transfer'                                  THEN amount ELSE 0 END) AS exp_transfer,
            SUM(amount) AS exp_fin_total
         FROM finance_transactions
         WHERE {$ff} AND type='expense' AND transaction_date BETWEEN ? AND ?"
    );
    $q->execute([$from,$to]);
    $exp = $q->fetch();

    $exp_feed        = (float)($exp['exp_feed']        ?? 0);
    $exp_medicine    = (float)($exp['exp_medicine']    ?? 0);
    $exp_equipment   = (float)($exp['exp_equipment']   ?? 0);
    $exp_treatment   = (float)($exp['exp_treatment']   ?? 0);
    $exp_maintenance = (float)($exp['exp_maintenance'] ?? 0);
    $exp_payroll     = (float)($exp['exp_payroll']     ?? 0);
    $exp_cow_death   = (float)($exp['exp_cow_death']   ?? 0);
    $exp_transfer    = (float)($exp['exp_transfer']    ?? 0);
    $exp_fin_total   = (float)($exp['exp_fin_total']   ?? 0);
    $exp_misc        = max(0, $exp_fin_total - $exp_feed - $exp_medicine - $exp_equipment
                         - $exp_treatment - $exp_maintenance - $exp_payroll
                         - $exp_cow_death - $exp_transfer);
    $exp_total       = $exp_fin_total;

    // ── MODULE NET MARGINS ───────────────────────────────────────────────────
    // Feed: sales revenue minus purchase cost
    $net_feed      = $rev_feed - $exp_feed;
    // Medicine: sales revenue minus purchase cost
    $net_medicine  = $rev_medicine - $exp_medicine;
    // Equipment: sales revenue minus purchase cost
    $net_equipment = $rev_equipment - $exp_equipment;
    // Livestock: cow + meat + byproduct sales (asset cost excluded since purchase was past expense)
    $net_livestock = $rev_cow + $rev_meat + $rev_byproduct;

    $net_profit = $rev_total - $exp_total;
    $margin     = $rev_total > 0 ? $net_profit / $rev_total * 100 : 0.0;

    return compact(
        'from','to',
        // Income
        'rev_milk','rev_cow','rev_meat','rev_byproduct',
        'rev_feed','rev_medicine','rev_equipment','rev_other',
        'rev_fin_total','rev_total',
        // Expense
        'exp_feed','exp_medicine','exp_equipment',
        'exp_treatment','exp_maintenance','exp_payroll',
        'exp_cow_death','exp_transfer','exp_misc',
        'exp_fin_total','exp_total',
        // Module margins
        'net_feed','net_medicine','net_equipment','net_livestock',
        // Summary
        'net_profit','margin'
    );
}

// ── Period label parser ───────────────────────────────────────────────────────
function parsePeriod(string $type, string $val, string $df, string $dt): ?array {
    if ($type === 'month' && preg_match('/^\d{4}-\d{2}$/', $val)) {
        $from  = $val . '-01';
        $to    = date('Y-m-t', strtotime($from));
        $label = date('F Y',   strtotime($from));
        return compact('from','to','label','type');
    }
    if ($type === 'year' && preg_match('/^\d{4}$/', $val)) {
        $cy   = (int)date('Y');
        $from = $val . '-01-01';
        $to   = ((int)$val === $cy) ? date('Y-m-d') : $val . '-12-31';
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

// ── 12-month trend (3 queries, no double-counting) ───────────────────────────
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

    // Milk income by month
    $q = $db->prepare(
        "SELECT YEAR(DATE(recorded_at)) yr, MONTH(DATE(recorded_at)) mo, SUM(milk_value) total
         FROM milk_records WHERE {$ff} AND contamination_flag=0 AND DATE(recorded_at) BETWEEN ? AND ?
         GROUP BY yr,mo"
    );
    $q->execute([$min,$max]);
    foreach ($q->fetchAll() as $r) {
        $i = $idx[(int)$r['yr']][(int)$r['mo']] ?? null;
        if ($i !== null) $months[$i]['rev'] += (float)$r['total'];
    }

    // All other income from finance_transactions by month
    $q = $db->prepare(
        "SELECT YEAR(transaction_date) yr, MONTH(transaction_date) mo, SUM(amount) total
         FROM finance_transactions WHERE {$ff} AND type='income' AND transaction_date BETWEEN ? AND ?
         GROUP BY yr,mo"
    );
    $q->execute([$min,$max]);
    foreach ($q->fetchAll() as $r) {
        $i = $idx[(int)$r['yr']][(int)$r['mo']] ?? null;
        if ($i !== null) $months[$i]['rev'] += (float)$r['total'];
    }

    // All expenses from finance_transactions by month
    $q = $db->prepare(
        "SELECT YEAR(transaction_date) yr, MONTH(transaction_date) mo, SUM(amount) total
         FROM finance_transactions WHERE {$ff} AND type='expense' AND transaction_date BETWEEN ? AND ?
         GROUP BY yr,mo"
    );
    $q->execute([$min,$max]);
    foreach ($q->fetchAll() as $r) {
        $i = $idx[(int)$r['yr']][(int)$r['mo']] ?? null;
        if ($i !== null) $months[$i]['exp'] += (float)$r['total'];
    }

    return $months;
}

// ── POST: manual income / expense CRUD ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error','Invalid request.'); redirect('/modules/finance/profit.php');
    }
    requireNotBlocked();
    $action = $_POST['action'] ?? '';
    $redir  = '/modules/finance/profit.php?tab=' . ($_POST['f_tab'] ?? 'overview');

    if ($action === 'add_income') {
        $cat = sanitize($_POST['category'] ?? 'Other Income');
        $amt = (float)($_POST['amount'] ?? 0);
        $dt  = trim($_POST['income_date'] ?? '');
        $ntx = sanitize($_POST['notes']   ?? '');
        if ($amt <= 0)         flashMessage('error','Amount must be > 0.');
        elseif (!strtotime($dt)) flashMessage('error','Invalid date.');
        else {
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,transaction_date,notes,recorded_by) VALUES(?,?,?,?,?,?,?)")
               ->execute([fid(),'income',$cat,$amt,$dt,$ntx?:null,$uid]);
            auditLog($uid,'ADD_INCOME','finance_transactions',(int)$db->lastInsertId(),null,compact('amt','cat'));
            flashMessage('success','Income recorded.');
        }
    } elseif ($action === 'add_expense') {
        $cat = sanitize($_POST['category'] ?? 'Farm Operations');
        $amt = (float)($_POST['amount'] ?? 0);
        $dt  = trim($_POST['expense_date'] ?? '');
        $ntx = sanitize($_POST['notes']    ?? '');
        if ($amt <= 0)           flashMessage('error','Amount must be > 0.');
        elseif (!strtotime($dt)) flashMessage('error','Invalid date.');
        else {
            $db->prepare("INSERT INTO finance_transactions (farm_id,type,category,amount,transaction_date,notes,recorded_by) VALUES(?,?,?,?,?,?,?)")
               ->execute([fid(),'expense',$cat,$amt,$dt,$ntx?:null,$uid]);
            auditLog($uid,'ADD_EXPENSE','finance_transactions',(int)$db->lastInsertId(),null,compact('amt','cat'));
            flashMessage('success','Expense recorded.');
        }
    } elseif ($action === 'delete_income' && hasRole(['admin'])) {
        $rid = (int)($_POST['row_id'] ?? 0);
        $r   = $db->prepare("SELECT * FROM finance_transactions WHERE id=? AND type='income' AND ".farmFilter());
        $r->execute([$rid]);
        if ($row=$r->fetch()) {
            $db->prepare("DELETE FROM finance_transactions WHERE id=? AND type='income' AND ".farmFilter())->execute([$rid]);
            auditLog($uid,'DELETE_INCOME','finance_transactions',$rid,$row,null);
            flashMessage('success','Deleted.');
        }
    } elseif ($action === 'delete_expense' && hasRole(['admin'])) {
        $rid = (int)($_POST['row_id'] ?? 0);
        $r   = $db->prepare("SELECT * FROM finance_transactions WHERE id=? AND type='expense' AND ".farmFilter());
        $r->execute([$rid]);
        if ($row=$r->fetch()) {
            $db->prepare("DELETE FROM finance_transactions WHERE id=? AND type='expense' AND ".farmFilter())->execute([$rid]);
            auditLog($uid,'DELETE_EXPENSE','finance_transactions',$rid,$row,null);
            flashMessage('success','Deleted.');
        }
    }
    redirect($redir);
}

// ── Routing ───────────────────────────────────────────────────────────────────
$tab    = in_array($_GET['tab'] ?? '', ['overview','compare','ledger'], true) ? $_GET['tab'] : 'overview';
$preset = in_array($_GET['preset'] ?? '', ['monthly','yearly'], true) ? $_GET['preset'] : '';

// ── 4 fixed periods ───────────────────────────────────────────────────────────
$today = date('Y-m-d'); $year = (int)date('Y');

$fp = [
    'tm' => ['from'=>date('Y-m-01'),                                        'to'=>$today,                                          'label'=>'This Month', 'color'=>'#16a34a','soft'=>'#f0fdf4'],
    'lm' => ['from'=>date('Y-m-01',strtotime('first day of last month')),   'to'=>date('Y-m-t',strtotime('last day of last month')),'label'=>'Last Month', 'color'=>'#d97706','soft'=>'#fffbeb'],
    'ty' => ['from'=>date('Y-01-01'),                                       'to'=>$today,                                          'label'=>'This Year',  'color'=>'#0284c7','soft'=>'#eff6ff'],
    'ly' => ['from'=>($year-1).'-01-01',                                    'to'=>($year-1).'-12-31',                              'label'=>'Last Year',  'color'=>'#7c3aed','soft'=>'#f5f3ff'],
];
foreach ($fp as $key => $p) $fp[$key] = array_merge($p, computePeriodFinancials($db, $p['from'], $p['to']));

// YoY comparison baseline
$ly_ytd_to  = ($year-1) . substr($today,4);
if ($ly_ytd_to > ($year-1).'-12-31') $ly_ytd_to = ($year-1).'-12-31';
$fin_ly_ytd = computePeriodFinancials($db, ($year-1).'-01-01', $ly_ytd_to);

// ── 12-month trend ────────────────────────────────────────────────────────────
$trend = buildTrend($db);

// ── Per-cow profitability (this month, milk vs treatments) ────────────────────
$tmf = $fp['tm']['from']; $tmt = $fp['tm']['to'];
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
$cow_stmt->execute([$tmf,$tmt,$tmf,$tmt]);
$cow_rows = $cow_stmt->fetchAll();
foreach ($cow_rows as &$cr) $cr['net'] = $cr['milk_rev'] - $cr['treat_cost'];
unset($cr);

// ── Smart Insights ────────────────────────────────────────────────────────────
$insights = [];
if ($fp['lm']['rev_total'] > 0) {
    $chg = ($fp['tm']['rev_total'] - $fp['lm']['rev_total']) / $fp['lm']['rev_total'] * 100;
    $up  = $chg >= 0;
    $insights[] = ['ok'=>$up,'icon'=>$up?'📈':'📉','text'=>'Revenue is <strong>'.number_format(abs($chg),1).'% '.($up?'higher':'lower').'</strong> than last month'];
}
if ($fp['lm']['net_profit'] != 0) {
    $chg = ($fp['tm']['net_profit'] - $fp['lm']['net_profit']) / abs($fp['lm']['net_profit']) * 100;
    $up  = $chg >= 0;
    $insights[] = ['ok'=>$up,'icon'=>$up?'💰':'⚠️','text'=>'Net profit <strong>'.($up?'improved':'declined').' by '.number_format(abs($chg),1).'%</strong> vs last month'];
}
if ($fin_ly_ytd['net_profit'] != 0) {
    $chg = ($fp['ty']['net_profit'] - $fin_ly_ytd['net_profit']) / abs($fin_ly_ytd['net_profit']) * 100;
    $up  = $chg >= 0;
    $insights[] = ['ok'=>$up,'icon'=>$up?'🚀':'📉','text'=>'YTD profit is <strong>'.number_format(abs($chg),1).'% '.($up?'ahead of':'behind').'</strong> the same period last year'];
}
// Cow death losses warning
if ($fp['tm']['exp_cow_death'] > 0) {
    $insights[] = ['ok'=>false,'icon'=>'⚰️','text'=>'Cow death losses this month: <strong>৳'.number_format($fp['tm']['exp_cow_death'],2).'</strong> — impacting net profit'];
}
// Largest expense category
$exp_cats = [
    'Feed Purchase'   => $fp['tm']['exp_feed'],
    'Medicine'        => $fp['tm']['exp_medicine'],
    'Vet Treatments'  => $fp['tm']['exp_treatment'],
    'Payroll'         => $fp['tm']['exp_payroll'],
    'Maintenance'     => $fp['tm']['exp_maintenance'],
    'Misc'            => $fp['tm']['exp_misc'],
];
if ($fp['tm']['exp_total'] > 0 && max($exp_cats) > 0) {
    $max_cat = (string)array_search(max($exp_cats),$exp_cats);
    $max_pct = round(max($exp_cats)/$fp['tm']['exp_total']*100);
    $insights[] = ['ok'=>null,'icon'=>'🏷️','text'=>"<strong>{$max_cat}</strong> is your largest expense this month ({$max_pct}% of costs)"];
}
// Best cow
if (!empty($cow_rows) && $cow_rows[0]['net'] > 0) {
    $name = $cow_rows[0]['cow_name'] ?: '#'.$cow_rows[0]['tag_number'];
    $insights[] = ['ok'=>true,'icon'=>'🐄','text'=>'Most profitable cow this month: <strong>'.e($name).'</strong> (৳'.number_format($cow_rows[0]['net'],2).' net)'];
}

// ── Comparison periods ────────────────────────────────────────────────────────
$cmp = []; $cmp_active = false; $cols = [];
$pa_type='month'; $pb_type='month'; $pc_type='';
$pa_val=date('Y-m'); $pb_val=date('Y-m',strtotime('last month')); $pc_val='';
$pa_from=''; $pa_to=''; $pb_from=''; $pb_to=''; $pc_from=''; $pc_to='';

if ($tab === 'compare') {
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
        $pa_val=$_GET['pa_val']??date('Y-m'); $pb_val=$_GET['pb_val']??date('Y-m',strtotime('last month')); $pc_val=$_GET['pc_val']??'';
        $pa_from=$_GET['pa_from']??''; $pa_to=$_GET['pa_to']??'';
        $pb_from=$_GET['pb_from']??''; $pb_to=$_GET['pb_to']??'';
        $pc_from=$_GET['pc_from']??''; $pc_to=$_GET['pc_to']??'';
    }
    $parsed_a = parsePeriod($pa_type,$pa_val,$pa_from,$pa_to);
    $parsed_b = parsePeriod($pb_type,$pb_val,$pb_from,$pb_to);
    $parsed_c = $pc_type ? parsePeriod($pc_type,$pc_val,$pc_from,$pc_to) : null;
    if ($parsed_a) $cmp['a'] = array_merge($parsed_a, computePeriodFinancials($db,$parsed_a['from'],$parsed_a['to']));
    if ($parsed_b) $cmp['b'] = array_merge($parsed_b, computePeriodFinancials($db,$parsed_b['from'],$parsed_b['to']));
    if ($parsed_c) $cmp['c'] = array_merge($parsed_c, computePeriodFinancials($db,$parsed_c['from'],$parsed_c['to']));
    $cmp_active = !empty($cmp['a']) && !empty($cmp['b']);
    if ($cmp_active) $cols = array_keys($cmp);
}

// ── Recent transactions for sidebar forms ─────────────────────────────────────
$oi_rows = $db->prepare("SELECT id,category,amount,transaction_date AS income_date,notes FROM finance_transactions WHERE ".farmFilter()." AND type='income' AND related_module IS NULL ORDER BY transaction_date DESC, id DESC LIMIT 8");
$oi_rows->execute(); $oi_rows = $oi_rows->fetchAll();
$me_rows = $db->prepare("SELECT id,category,amount,transaction_date AS expense_date,notes FROM finance_transactions WHERE ".farmFilter()." AND type='expense' AND related_module IS NULL ORDER BY transaction_date DESC, id DESC LIMIT 8");
$me_rows->execute(); $me_rows = $me_rows->fetchAll();

// ── Ledger tab ────────────────────────────────────────────────────────────────
$ledger_rows = [];
if ($tab === 'ledger') {
    $lf = $_GET['lf'] ?? date('Y-m-01');
    $lt = $_GET['lt'] ?? date('Y-m-d');
    $lt_stmt = $db->prepare(
        "SELECT ft.*, u.name AS recorder_name
         FROM finance_transactions ft
         LEFT JOIN users u ON u.id = ft.recorded_by
         WHERE ".farmFilter('ft')." AND ft.transaction_date BETWEEN ? AND ?
         ORDER BY ft.transaction_date DESC, ft.id DESC
         LIMIT 500"
    );
    $lt_stmt->execute([$lf,$lt]);
    $ledger_rows = $lt_stmt->fetchAll();
}

// ── Chart JSON ────────────────────────────────────────────────────────────────
$chart_labels = json_encode(array_column($trend,'label'));
$chart_rev    = json_encode(array_map(fn($r)=>round($r['rev'],2),$trend));
$chart_exp    = json_encode(array_map(fn($r)=>round($r['exp'],2),$trend));
$chart_profit = json_encode(array_map(fn($r)=>round($r['rev']-$r['exp'],2),$trend));

// ── Active nav ────────────────────────────────────────────────────────────────
$active_nav = match(true) {
    $tab==='compare' && $preset==='monthly' => 'profit_monthly',
    $tab==='compare' && $preset==='yearly'  => 'profit_yearly',
    $tab==='compare'                        => 'profit_compare',
    default                                 => 'profit_engine',
};
$page_title = 'Profit & Loss';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

// ── View helpers ──────────────────────────────────────────────────────────────
function fmt(float $v): string { return '৳'.number_format(abs($v),2); }
function fmts(float $v): string { return ($v<0?'-':'').fmt($v); }
function pct_change(float $base, float $new): ?float {
    if ($base == 0) return null;
    return ($new - $base) / abs($base) * 100;
}
function render_delta(?float $pct, bool $inverse = false): string {
    if ($pct === null) return '<span class="text-muted">—</span>';
    $good  = $inverse ? ($pct <= 0) : ($pct >= 0);
    $col   = $good ? '#16a34a' : '#dc2626';
    $arrow = $pct >= 0 ? '▲' : '▼';
    return "<span style='color:{$col};font-weight:600;font-size:.82rem'>{$arrow} ".number_format(abs($pct),1)."%</span>";
}
function pl_bar(float $v, float $max_v, string $color): string {
    $w = $max_v > 0 ? min(100,round(abs($v)/$max_v*100)) : 0;
    return "<div style='flex:1;height:5px;background:var(--border);border-radius:3px'>"
         . "<div style='height:5px;border-radius:3px;background:{$color};width:{$w}%'></div></div>";
}
?>

<!-- ── Page header + tab switcher ─────────────────────────────────────────── -->
<div class="page-header" style="flex-wrap:wrap;gap:.75rem">
    <div>
        <h2>Profit &amp; Loss</h2>
        <p class="text-muted text-sm">Accurate farm accounting — no double-counting</p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="?tab=overview" class="btn btn-sm <?= $tab==='overview' ? 'btn-primary' : 'btn-secondary' ?>">Overview</a>
        <a href="?tab=compare<?= $preset ? '&preset='.$preset : '' ?>" class="btn btn-sm <?= $tab==='compare' ? 'btn-primary' : 'btn-secondary' ?>">Compare</a>
        <a href="?tab=ledger"   class="btn btn-sm <?= $tab==='ledger'  ? 'btn-primary' : 'btn-secondary' ?>">Ledger</a>
    </div>
</div>

<?php if ($tab === 'overview'): ?>

<!-- ── Smart Insights ─────────────────────────────────────────────────────── -->
<?php if (!empty($insights)): ?>
<div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.25rem">
<?php foreach ($insights as $ins): ?>
<div style="flex:1;min-width:200px;background:<?= $ins['ok']===true?'#f0fdf4':($ins['ok']===false?'#fef2f2':'#eff6ff') ?>;border:1px solid <?= $ins['ok']===true?'#86efac':($ins['ok']===false?'#fca5a5':'#bfdbfe') ?>;border-radius:8px;padding:.6rem .85rem;display:flex;gap:.55rem;align-items:flex-start">
    <span style="font-size:1rem;line-height:1.2"><?= $ins['icon'] ?></span>
    <span class="text-sm"><?= $ins['text'] ?></span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── 4 Period KPI Cards ─────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1.5rem">
<?php foreach ($fp as $key => $p):
    $ip = $p['net_profit'] >= 0;
    $compare = null;
    if ($key === 'tm' && $fp['lm']['rev_total'] > 0) $compare = pct_change($fp['lm']['net_profit'],$p['net_profit']);
    if ($key === 'ty' && $fin_ly_ytd['rev_total'] > 0) $compare = pct_change($fin_ly_ytd['net_profit'],$p['net_profit']);
?>
<div class="card" style="border-top:3px solid <?= $p['color'] ?>">
    <div class="card-body" style="padding:.85rem 1rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.65rem">
            <span style="font-weight:700;font-size:.8rem;color:<?= $p['color'] ?>;text-transform:uppercase;letter-spacing:.04em"><?= $p['label'] ?></span>
            <?php if ($compare !== null) echo render_delta($compare); ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:.28rem;font-size:.86rem">
            <div style="display:flex;justify-content:space-between">
                <span class="text-muted">Revenue</span>
                <span style="font-weight:600;color:#15803d"><?= fmt($p['rev_total']) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between">
                <span class="text-muted">Expenses</span>
                <span style="font-weight:600;color:#b91c1c"><?= fmt($p['exp_total']) ?></span>
            </div>
            <?php if ($p['exp_cow_death'] > 0): ?>
            <div style="display:flex;justify-content:space-between;font-size:.78rem">
                <span style="color:#b91c1c">  incl. Cow Losses</span>
                <span style="color:#b91c1c"><?= fmt($p['exp_cow_death']) ?></span>
            </div>
            <?php endif; ?>
            <div style="border-top:1px solid var(--border);margin:.25rem 0"></div>
            <div style="display:flex;justify-content:space-between">
                <span style="font-weight:700;color:<?= $ip?'#15803d':'#b91c1c' ?>"><?= $ip ? 'Net Profit' : 'Net Loss' ?></span>
                <div style="text-align:right">
                    <span style="font-weight:800;font-size:1.05rem;color:<?= $ip?'#15803d':'#b91c1c' ?>"><?= fmts($p['net_profit']) ?></span>
                    <?php if ($p['rev_total']>0): ?>
                    <div style="font-size:.72rem;color:var(--text-secondary)"><?= number_format($p['margin'],1) ?>% margin</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── This Month: Full Income & Expense Breakdown ─────────────────────────── -->
<?php $tm = $fp['tm']; ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.25rem;margin-bottom:1.25rem">

    <!-- INCOME breakdown -->
    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #16a34a">
            <h3 style="margin:0">Income — <?= e($tm['label']) ?></h3>
            <span style="font-weight:700;color:#15803d"><?= fmt($tm['rev_total']) ?></span>
        </div>
        <div class="card-body" style="padding:.6rem 1rem">
        <?php
        $inc_items = [
            ['🥛 Milk Production',    $tm['rev_milk'],      '#0284c7', '/modules/milk/index.php'],
            ['🐄 Cow Sales',          $tm['rev_cow'],       '#16a34a', '/modules/cows/sell.php'],
            ['🍖 Meat Sales',         $tm['rev_meat'],      '#92400e', null],
            ['🫙 Byproducts',         $tm['rev_byproduct'], '#7c3aed', null],
            ['🌾 Feed Stock Sales',   $tm['rev_feed'],      '#65a30d', '/modules/inventory/feed.php'],
            ['💊 Medicine Sales',     $tm['rev_medicine'],  '#0891b2', '/modules/inventory/medicine.php'],
            ['🔧 Equipment Sales',    $tm['rev_equipment'], '#6366f1', '/modules/equipment/index.php'],
            ['💵 Other Income',       $tm['rev_other'],     '#6b7280', null],
        ];
        $max_inc = max(array_column(array_map(fn($x)=>['v'=>$x[1]],$inc_items),'v') + [0.01]);
        foreach ($inc_items as [$lbl,$val,$col,$link]):
            if ($val <= 0) continue;
            $pct = $tm['rev_total'] > 0 ? round($val/$tm['rev_total']*100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:.6rem;padding:.28rem 0;border-bottom:1px solid var(--border)">
            <div style="width:150px;font-size:.8rem;color:var(--text-secondary);flex-shrink:0">
                <?= $link ? "<a href='{$link}' style='color:inherit'>{$lbl}</a>" : $lbl ?>
            </div>
            <?= pl_bar($val,$max_inc,$col) ?>
            <div style="width:72px;text-align:right;font-weight:600;color:<?= $col ?>;font-size:.83rem"><?= fmt($val) ?></div>
            <div style="width:28px;text-align:right;font-size:.73rem;color:var(--text-secondary)"><?= $pct ?>%</div>
        </div>
        <?php endforeach; ?>
        <?php if ($tm['rev_total'] <= 0): ?>
        <p class="text-muted text-sm" style="padding:.5rem 0">No income recorded this month.</p>
        <?php endif; ?>
        </div>
    </div>

    <!-- EXPENSE breakdown -->
    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #dc2626">
            <h3 style="margin:0">Expenses — <?= e($tm['label']) ?></h3>
            <span style="font-weight:700;color:#b91c1c"><?= fmt($tm['exp_total']) ?></span>
        </div>
        <div class="card-body" style="padding:.6rem 1rem">
        <?php
        $exp_items = [
            ['🌾 Feed Purchase',      $tm['exp_feed'],        '#65a30d', '/modules/inventory/feed.php'],
            ['💊 Medicine Purchase',  $tm['exp_medicine'],    '#0891b2', '/modules/inventory/medicine.php'],
            ['🔧 Equipment Purchase', $tm['exp_equipment'],   '#6366f1', '/modules/equipment/index.php'],
            ['👷 Payroll / Salary',   $tm['exp_payroll'],     '#d97706', '/modules/payroll/index.php'],
            ['🩺 Vet Treatments',     $tm['exp_treatment'],   '#7c3aed', '/modules/treatments/index.php'],
            ['🛠️ Maintenance',        $tm['exp_maintenance'], '#6b7280', '/modules/maintenance/index.php'],
            ['⚰️ Cow Death Losses',   $tm['exp_cow_death'],   '#dc2626', '/modules/cows/death_record.php'],
            ['🎁 Asset Transfers',    $tm['exp_transfer'],    '#f59e0b', '/modules/cows/family_transfer.php'],
            ['📋 Farm Operations',    $tm['exp_misc'],        '#94a3b8', null],
        ];
        $max_exp = max(array_column(array_map(fn($x)=>['v'=>$x[1]],$exp_items),'v') + [0.01]);
        foreach ($exp_items as [$lbl,$val,$col,$link]):
            if ($val <= 0) continue;
            $pct = $tm['exp_total'] > 0 ? round($val/$tm['exp_total']*100) : 0;
        ?>
        <div style="display:flex;align-items:center;gap:.6rem;padding:.28rem 0;border-bottom:1px solid var(--border)">
            <div style="width:150px;font-size:.8rem;color:var(--text-secondary);flex-shrink:0">
                <?= $link ? "<a href='{$link}' style='color:inherit'>{$lbl}</a>" : $lbl ?>
            </div>
            <?= pl_bar($val,$max_exp,$col) ?>
            <div style="width:72px;text-align:right;font-weight:600;color:<?= $col ?>;font-size:.83rem"><?= fmt($val) ?></div>
            <div style="width:28px;text-align:right;font-size:.73rem;color:var(--text-secondary)"><?= $pct ?>%</div>
        </div>
        <?php endforeach; ?>
        <?php if ($tm['exp_total'] <= 0): ?>
        <p class="text-muted text-sm" style="padding:.5rem 0">No expenses recorded this month.</p>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Module Net Profit Summary ──────────────────────────────────────────── -->
<?php if ($tm['rev_total'] > 0 || $tm['exp_total'] > 0): ?>
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h3>Module P&amp;L — <?= e($tm['label']) ?></h3></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:0;border-top:1px solid var(--border)">
    <?php
    $modules = [
        ['🥛 Milk',        $tm['rev_milk'],     0,                     '#0284c7'],
        ['🐄 Livestock',   $tm['net_livestock'],null,                  '#16a34a'],
        ['🌾 Feed Stock',  $tm['net_feed'],     null,                  '#65a30d'],
        ['💊 Medicine',    $tm['net_medicine'], null,                  '#0891b2'],
        ['🔧 Equipment',   $tm['net_equipment'],null,                  '#6366f1'],
        ['👷 Payroll',     -$tm['exp_payroll'], null,                  '#d97706'],
        ['🩺 Treatments',  -$tm['exp_treatment'],null,                 '#7c3aed'],
        ['🛠️ Maintenance', -$tm['exp_maintenance'],null,               '#6b7280'],
        ['⚰️ Cow Losses',  -$tm['exp_cow_death'],null,                '#dc2626'],
    ];
    foreach ($modules as [$lbl,$net,$cost,$col]):
        if (abs($net ?? 0) < 0.01 && abs($cost ?? 0) < 0.01) continue;
        $net_v = (float)($net ?? 0);
        $is_pos = $net_v >= 0;
    ?>
    <div style="padding:.75rem 1rem;border-right:1px solid var(--border);border-bottom:1px solid var(--border)">
        <div style="font-size:.78rem;color:var(--text-secondary);margin-bottom:.2rem"><?= $lbl ?></div>
        <div style="font-size:1.05rem;font-weight:700;color:<?= $is_pos?'#15803d':'#b91c1c' ?>"><?= fmts($net_v) ?></div>
    </div>
    <?php endforeach; ?>
    <!-- Net total -->
    <?php $net_v = $tm['net_profit']; $is_pos = $net_v >= 0; ?>
    <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border);background:<?= $is_pos?'#f0fdf4':'#fef2f2' ?>">
        <div style="font-size:.78rem;font-weight:700;margin-bottom:.2rem;color:<?= $is_pos?'#15803d':'#b91c1c' ?>">NET PROFIT</div>
        <div style="font-size:1.1rem;font-weight:800;color:<?= $is_pos?'#15803d':'#b91c1c' ?>"><?= fmts($net_v) ?></div>
        <?php if ($tm['rev_total']>0): ?><div style="font-size:.72rem;color:var(--text-secondary)"><?= number_format($tm['margin'],1) ?>% margin</div><?php endif; ?>
    </div>
    </div>
</div>
<?php endif; ?>

<!-- ── 12-Month Trend Chart ──────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">12-Month Trend</h3>
        <div style="display:flex;gap:.5rem;font-size:.77rem;font-weight:600">
            <span style="color:#16a34a">■ Revenue</span>
            <span style="color:#dc2626">■ Expenses</span>
            <span style="color:#7c3aed">── Net Profit</span>
        </div>
    </div>
    <div style="padding:1rem 1rem .5rem"><canvas id="trendChart" height="75"></canvas></div>
</div>

<!-- ── Monthly Summary Table ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h3>Monthly Summary (Last 12 Months)</h3></div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem;min-width:560px">
            <thead><tr>
                <th>Month</th>
                <th class="text-right">Revenue</th>
                <th class="text-right">Expenses</th>
                <th class="text-right">Cow Losses</th>
                <th class="text-right">Net Profit</th>
                <th class="text-right">Margin</th>
            </tr></thead>
            <tbody>
            <?php foreach (array_reverse($trend) as $tm_row):
                $tp = $tm_row['rev'] - $tm_row['exp'];
                $mg = $tm_row['rev'] > 0 ? $tp/$tm_row['rev']*100 : 0;
                // Get cow death losses for this month from finance_transactions
                $loss_q = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE ".farmFilter()." AND category='Cow Death Loss' AND transaction_date BETWEEN ? AND ?");
                $loss_q->execute([$tm_row['from'], $tm_row['to']]);
                $month_losses = (float)$loss_q->fetchColumn();
            ?>
            <tr>
                <td><?= e($tm_row['label']) ?></td>
                <td class="text-right" style="color:#15803d"><?= fmt($tm_row['rev']) ?></td>
                <td class="text-right" style="color:#b91c1c"><?= fmt($tm_row['exp']) ?></td>
                <td class="text-right" style="color:#b91c1c;font-size:.78rem"><?= $month_losses > 0 ? fmt($month_losses) : '—' ?></td>
                <td class="text-right" style="font-weight:700;color:<?= $tp>=0?'#15803d':'#b91c1c' ?>"><?= fmts($tp) ?></td>
                <td class="text-right" style="color:<?= $mg>=0?'#15803d':'#b91c1c' ?>"><?= number_format($mg,1) ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Per-Cow Profitability ─────────────────────────────────────────────── -->
<?php if (!empty($cow_rows)): ?>
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h3>Per-Cow Profitability — <?= e($fp['tm']['label']) ?></h3></div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem">
            <thead><tr><th>Cow</th><th class="text-right">Milk Rev.</th><th class="text-right">Treatments</th><th class="text-right">Net</th></tr></thead>
            <tbody>
            <?php foreach ($cow_rows as $cr): ?>
            <tr>
                <td><a href="/modules/cows/view.php?id=<?= $cr['id'] ?>"><strong><?= e($cr['cow_name']?:'—') ?></strong> <span class="text-muted text-xs">#<?= e($cr['tag_number']) ?></span></a></td>
                <td class="text-right" style="color:#15803d"><?= fmt($cr['milk_rev']) ?></td>
                <td class="text-right" style="color:#7c3aed"><?= fmt($cr['treat_cost']) ?></td>
                <td class="text-right" style="font-weight:700;color:<?= $cr['net']>=0?'#15803d':'#b91c1c' ?>"><?= fmts($cr['net']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Quick Entry Forms ──────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.25rem;margin-bottom:1.5rem">

    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #16a34a"><h3 style="margin:0">+ Record Other Income</h3></div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="add_income">
                <input type="hidden" name="f_tab"   value="overview">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem">
                    <div class="form-group" style="margin:0"><label class="form-label">Category</label>
                        <select name="category" class="form-control form-control-sm">
                            <optgroup label="Milk">
                                <option>Milk Contract Income</option>
                            </optgroup>
                            <optgroup label="Farm Products">
                                <option>Manure / Compost Sale</option>
                                <option>Skin Sale</option>
                                <option>Bone / Organ Sale</option>
                            </optgroup>
                            <optgroup label="Other">
                                <option>Government Subsidy</option>
                                <option>Insurance Claim</option>
                                <option>Other Income</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0"><label class="form-label">Amount (৳)</label>
                        <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group" style="margin:0"><label class="form-label">Date</label>
                        <input type="date" name="income_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group" style="margin:0"><label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional">
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-success" style="margin-top:.6rem;width:100%">Record Income</button>
            </form>
            <?php foreach ($oi_rows as $oi): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.8rem;padding:.22rem 0;border-bottom:1px solid var(--border)">
                <div>
                    <span><?= e($oi['category']) ?></span>
                    <span class="text-muted text-xs"> <?= e(formatDate($oi['income_date'])) ?></span>
                    <?php if ($oi['notes']): ?><span class="text-muted text-xs"> — <?= e(mb_strimwidth($oi['notes'],0,40,'…')) ?></span><?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:.35rem">
                    <span style="color:#15803d;font-weight:600"><?= fmt((float)$oi['amount']) ?></span>
                    <?php if (hasRole(['admin'])): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="delete_income">
                        <input type="hidden" name="row_id"  value="<?= $oi['id'] ?>">
                        <input type="hidden" name="f_tab"   value="overview">
                        <button type="submit" class="btn btn-xs btn-danger">✕</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="border-bottom:2px solid #dc2626"><h3 style="margin:0">+ Record Expense</h3></div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_expense">
                <input type="hidden" name="f_tab"  value="overview">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.55rem">
                    <div class="form-group" style="margin:0"><label class="form-label">Category</label>
                        <select name="category" class="form-control form-control-sm">
                            <optgroup label="Staff">
                                <option>Payroll</option>
                                <option>Overtime Pay</option>
                                <option>Bonus</option>
                            </optgroup>
                            <optgroup label="Farm Operations">
                                <option>Electricity / Utilities</option>
                                <option>Water Bill</option>
                                <option>Transportation</option>
                                <option>Land Lease / Rent</option>
                                <option>Insurance</option>
                                <option>Security</option>
                            </optgroup>
                            <optgroup label="Other">
                                <option>Office / Admin</option>
                                <option>Marketing</option>
                                <option>Miscellaneous</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0"><label class="form-label">Amount (৳)</label>
                        <input type="number" name="amount" class="form-control form-control-sm" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <div class="form-group" style="margin:0"><label class="form-label">Date</label>
                        <input type="date" name="expense_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group" style="margin:0"><label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control form-control-sm" placeholder="Optional">
                    </div>
                </div>
                <button type="submit" class="btn btn-sm btn-danger" style="margin-top:.6rem;width:100%">Record Expense</button>
            </form>
            <?php foreach ($me_rows as $me): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.8rem;padding:.22rem 0;border-bottom:1px solid var(--border)">
                <div>
                    <span><?= e($me['category']) ?></span>
                    <span class="text-muted text-xs"> <?= e(formatDate($me['expense_date'])) ?></span>
                    <?php if ($me['notes']): ?><span class="text-muted text-xs"> — <?= e(mb_strimwidth($me['notes'],0,40,'…')) ?></span><?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:.35rem">
                    <span style="color:#b91c1c;font-weight:600"><?= fmt((float)$me['amount']) ?></span>
                    <?php if (hasRole(['admin'])): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="delete_expense">
                        <input type="hidden" name="row_id"  value="<?= $me['id'] ?>">
                        <input type="hidden" name="f_tab"   value="overview">
                        <button type="submit" class="btn btn-xs btn-danger">✕</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php elseif ($tab === 'compare'): ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     COMPARE TAB
══════════════════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header">
        <h3>Compare Periods</h3>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <a href="?tab=compare&preset=monthly" class="btn btn-sm <?= $preset==='monthly'?'btn-primary':'btn-secondary' ?>">This vs Last Month</a>
            <a href="?tab=compare&preset=yearly"  class="btn btn-sm <?= $preset==='yearly' ?'btn-primary':'btn-secondary' ?>">This vs Last Year</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" id="cmpForm">
            <input type="hidden" name="tab" value="compare">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1rem">
            <?php foreach (['a'=>'A (Required)','b'=>'B (Required)','c'=>'C (Optional)'] as $pid=>$plabel): ?>
            <div style="border:1px solid var(--border);border-radius:8px;padding:.8rem">
                <div style="font-weight:700;font-size:.83rem;margin-bottom:.5rem">Period <?= $plabel ?></div>
                <div class="form-group" style="margin-bottom:.45rem">
                    <label class="form-label" style="font-size:.78rem">Type</label>
                    <select name="p<?= $pid ?>_type" class="form-control form-control-sm" onchange="togglePeriodInputs('<?= $pid ?>')">
                        <option value="month"  <?= ($pid==='a'?$pa_type:($pid==='b'?$pb_type:$pc_type))==='month' ?'selected':'' ?>>Month</option>
                        <option value="year"   <?= ($pid==='a'?$pa_type:($pid==='b'?$pb_type:$pc_type))==='year'  ?'selected':'' ?>>Year</option>
                        <option value="custom" <?= ($pid==='a'?$pa_type:($pid==='b'?$pb_type:$pc_type))==='custom'?'selected':'' ?>>Custom Range</option>
                    </select>
                </div>
                <?php $ct=$pid==='a'?$pa_type:($pid==='b'?$pb_type:$pc_type); $cv=$pid==='a'?$pa_val:($pid==='b'?$pb_val:$pc_val); $cf=$pid==='a'?$pa_from:($pid==='b'?$pb_from:$pc_from); $cto=$pid==='a'?$pa_to:($pid==='b'?$pb_to:$pc_to); ?>
                <div id="prd-<?= $pid ?>-month" style="display:<?= $ct!=='custom'?'block':'none' ?>">
                    <input type="<?= $ct==='year'?'number':'month' ?>" name="p<?= $pid ?>_val" class="form-control form-control-sm" value="<?= e($cv) ?>" <?= $ct==='year'?'min="2000" max="2099"':'' ?> placeholder="<?= $ct==='year'?date('Y'):date('Y-m') ?>">
                </div>
                <div id="prd-<?= $pid ?>-custom" style="display:<?= $ct==='custom'?'block':'none' ?>">
                    <div style="display:flex;flex-direction:column;gap:.35rem">
                        <div><label class="form-label" style="font-size:.77rem">From</label><input type="date" name="p<?= $pid ?>_from" class="form-control form-control-sm" value="<?= e($cf) ?>"></div>
                        <div><label class="form-label" style="font-size:.77rem">To</label><input type="date" name="p<?= $pid ?>_to" class="form-control form-control-sm" value="<?= e($cto) ?>"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:1rem">Run Comparison</button>
        </form>
    </div>
</div>

<?php if ($cmp_active):
    $has_c = isset($cmp['c']);
?>
<div style="display:grid;grid-template-columns:repeat(<?= count($cols) ?>,1fr);gap:1rem;margin-bottom:1.25rem">
<?php foreach ($cols as $cid):
    $cd = $cmp[$cid]; $ip = $cd['net_profit'] >= 0;
    $colors = ['a'=>'#0284c7','b'=>'#d97706','c'=>'#7c3aed'];
?>
<div class="card" style="border-top:3px solid <?= $colors[$cid] ?>">
    <div class="card-body" style="padding:.85rem 1rem">
        <div style="font-weight:700;font-size:.8rem;color:<?= $colors[$cid] ?>;text-transform:uppercase;margin-bottom:.5rem">Period <?= strtoupper($cid) ?>: <?= e($cd['label']) ?></div>
        <div style="font-size:.86rem;color:var(--text-secondary)">Revenue</div>
        <div style="font-size:1.1rem;font-weight:700;color:#15803d;margin-bottom:.3rem"><?= fmt($cd['rev_total']) ?></div>
        <div style="font-size:.86rem;color:var(--text-secondary)">Expenses</div>
        <div style="font-size:1.1rem;font-weight:700;color:#b91c1c;margin-bottom:.3rem"><?= fmt($cd['exp_total']) ?></div>
        <div style="border-top:1px solid var(--border);padding-top:.4rem">
            <div style="font-size:.84rem;color:var(--text-secondary)"><?= $ip?'Net Profit':'Net Loss' ?></div>
            <div style="font-size:1.25rem;font-weight:800;color:<?= $ip?'#15803d':'#b91c1c' ?>"><?= fmts($cd['net_profit']) ?></div>
            <?php if ($cd['rev_total']>0): ?><div style="font-size:.73rem;color:var(--text-secondary)"><?= number_format($cd['margin'],1) ?>% margin</div><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="card" style="margin-bottom:1.25rem">
    <div class="card-header"><h3>Detailed Comparison</h3></div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem;min-width:500px">
            <thead><tr>
                <th>Metric</th>
                <?php foreach ($cols as $cid): ?><th class="text-right">Period <?= strtoupper($cid) ?><br><span style="font-weight:400;font-size:.73rem"><?= e($cmp[$cid]['label']) ?></span></th><?php endforeach; ?>
                <th class="text-right">Δ A→B</th><th class="text-right">% A→B</th>
                <?php if ($has_c): ?><th class="text-right">% B→C</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php
            $cmp_metrics = [
                ['INCOME',                  null,                 false, '#1e40af', true],
                ['  Milk Production',        'rev_milk',           false, '#0284c7', false],
                ['  Cow Sales',              'rev_cow',            false, '#16a34a', false],
                ['  Meat Sales',             'rev_meat',           false, '#92400e', false],
                ['  Byproducts',             'rev_byproduct',      false, '#7c3aed', false],
                ['  Feed Stock Sales',       'rev_feed',           false, '#65a30d', false],
                ['  Medicine Sales',         'rev_medicine',       false, '#0891b2', false],
                ['  Equipment Sales',        'rev_equipment',      false, '#6366f1', false],
                ['  Other Income',           'rev_other',          false, '#6b7280', false],
                ['Total Revenue',            'rev_total',          false, '#15803d', true],
                ['EXPENSES',                 null,                 true,  '#991b1b', true],
                ['  Feed Purchase',          'exp_feed',           true,  '#65a30d', false],
                ['  Medicine Purchase',      'exp_medicine',       true,  '#0891b2', false],
                ['  Equipment Purchase',     'exp_equipment',      true,  '#6366f1', false],
                ['  Payroll / Salary',       'exp_payroll',        true,  '#d97706', false],
                ['  Vet Treatments',         'exp_treatment',      true,  '#7c3aed', false],
                ['  Maintenance',            'exp_maintenance',    true,  '#6b7280', false],
                ['  Cow Death Losses',       'exp_cow_death',      true,  '#dc2626', false],
                ['  Asset Transfers',        'exp_transfer',       true,  '#f59e0b', false],
                ['  Farm Operations',        'exp_misc',           true,  '#94a3b8', false],
                ['Total Expenses',           'exp_total',          true,  '#b91c1c', true],
                ['NET PROFIT',               'net_profit',         false, '#7c3aed', true],
            ];
            foreach ($cmp_metrics as [$lbl,$key,$inv,$col,$bold]):
                if ($key === null) { // section header ?>
                <tr style="background:#f8fafc"><td colspan="<?= 4 + count($cols) + ($has_c?1:0) ?>" style="font-weight:700;font-size:.78rem;color:<?= $col ?>;padding:.4rem 1rem;text-transform:uppercase;letter-spacing:.05em"><?= ltrim($lbl) ?></td></tr>
                <?php continue; }
                $va = (float)($cmp['a'][$key] ?? 0);
                $vb = (float)($cmp['b'][$key] ?? 0);
                $vc = $has_c ? (float)($cmp['c'][$key] ?? 0) : null;
                $is_indent = str_starts_with($lbl,'  ');
                $diff_ab  = $vb - $va;
                $pct_ab   = pct_change($va,$vb);
                $pct_bc   = $has_c ? pct_change($vb,(float)$vc) : null;
            ?>
            <tr style="<?= $is_indent ? 'background:var(--surface-secondary,#f9fafb)' : '' ?>">
                <td style="<?= $is_indent ? 'padding-left:1.5rem;color:var(--text-secondary)' : 'font-weight:'.($bold?700:500) ?>"><?= ltrim($lbl) ?></td>
                <?php foreach ($cols as $cid): ?>
                <td class="text-right" style="color:<?= $col ?>;font-weight:<?= $bold?700:400 ?>"><?= fmts((float)($cmp[$cid][$key]??0)) ?></td>
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

<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>Comparison Chart</h3></div>
    <div style="padding:1rem"><canvas id="cmpChart" height="<?= $has_c?70:55 ?>"></canvas></div>
</div>

<?php endif; // cmp_active ?>
<?php if (!$cmp_active && (isset($_GET['pa_val'])||isset($_GET['pa_type']))): ?>
<div class="alert alert-danger">Could not parse one or more periods. Check the values and try again.</div>
<?php elseif (!isset($_GET['pa_val']) && !$preset): ?>
<div class="alert" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8">Select Period A and Period B above, then click <strong>Run Comparison</strong>.</div>
<?php endif; ?>

<?php elseif ($tab === 'ledger'): ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     LEDGER TAB — full transaction audit trail
══════════════════════════════════════════════════════════════════════════════ -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <input type="hidden" name="tab" value="ledger">
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">From</label>
        <input type="date" name="lf" class="form-control form-control-sm" value="<?= e($lf) ?>">
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">To</label>
        <input type="date" name="lt" class="form-control form-control-sm" value="<?= e($lt) ?>">
    </div>
    <div style="align-self:flex-end"><button type="submit" class="btn btn-sm btn-primary">Filter</button></div>
</form>

<?php
$ledger_inc = array_filter($ledger_rows, fn($r) => $r['type'] === 'income');
$ledger_exp = array_filter($ledger_rows, fn($r) => $r['type'] === 'expense');
$ledger_inc_total = array_sum(array_column(iterator_to_array((function($arr){foreach($arr as $v) yield $v;})($ledger_inc)), 'amount'));
$ledger_exp_total = array_sum(array_column(iterator_to_array((function($arr){foreach($arr as $v) yield $v;})($ledger_exp)), 'amount'));
$ledger_net = $ledger_inc_total - $ledger_exp_total;
?>

<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
    <div class="kpi-card" style="--kpi-color:#15803d;--kpi-soft:#f0fdf4;min-width:140px">
        <div class="kpi-label">Total Income</div>
        <div class="kpi-value" style="font-size:1.1rem">৳<?= number_format($ledger_inc_total,2) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#b91c1c;--kpi-soft:#fef2f2;min-width:140px">
        <div class="kpi-label">Total Expenses</div>
        <div class="kpi-value" style="font-size:1.1rem">৳<?= number_format($ledger_exp_total,2) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $ledger_net>=0?'#15803d':'#b91c1c' ?>;--kpi-soft:<?= $ledger_net>=0?'#f0fdf4':'#fef2f2' ?>;min-width:140px">
        <div class="kpi-label">Net</div>
        <div class="kpi-value" style="font-size:1.1rem"><?= fmts($ledger_net) ?></div>
    </div>
</div>

<div class="card">
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem;min-width:700px">
            <thead><tr>
                <th>Date</th><th>Type</th><th>Category</th><th>Module</th>
                <th class="text-right">Amount</th><th>Notes</th><th>By</th>
            </tr></thead>
            <tbody>
            <?php if (empty($ledger_rows)): ?>
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem">No transactions in this period.</td></tr>
            <?php else: ?>
            <?php foreach ($ledger_rows as $lr):
                $is_inc = $lr['type'] === 'income';
            ?>
            <tr>
                <td style="white-space:nowrap"><?= e($lr['transaction_date']) ?></td>
                <td><span class="badge <?= $is_inc?'badge-green':'badge-red' ?>"><?= $is_inc?'Income':'Expense' ?></span></td>
                <td><?= e($lr['category']) ?></td>
                <td style="font-size:.76rem;color:var(--text-secondary)"><?= e($lr['related_module'] ?? '—') ?></td>
                <td class="text-right" style="font-weight:600;color:<?= $is_inc?'#15803d':'#b91c1c' ?>">
                    <?= ($is_inc?'+':'-').fmt((float)$lr['amount']) ?>
                </td>
                <td style="font-size:.76rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="<?= e($lr['notes']??'') ?>"><?= e(mb_strimwidth($lr['notes']??'—',0,60,'…')) ?></td>
                <td style="font-size:.76rem"><?= e($lr['recorder_name']??'—') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; // tab ?>

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
                { type:'bar',  label:'Revenue',   data:<?= $chart_rev ?>,    backgroundColor:'rgba(22,163,74,.65)',  borderColor:'#16a34a', borderWidth:1, borderRadius:3 },
                { type:'bar',  label:'Expenses',  data:<?= $chart_exp ?>,    backgroundColor:'rgba(220,38,38,.55)',  borderColor:'#dc2626', borderWidth:1, borderRadius:3 },
                { type:'line', label:'Net Profit',data:<?= $chart_profit ?>, borderColor:'#7c3aed', backgroundColor:'rgba(124,58,237,.08)', borderWidth:2, pointRadius:3, tension:.35, fill:true }
            ]
        },
        options:{
            responsive:true,
            interaction:{mode:'index',intersect:false},
            plugins:{legend:{position:'top'},tooltip:{callbacks:{label:c=>' '+_tk(c.raw)}}},
            scales:{y:{ticks:{callback:v=>'৳'+Number(v).toLocaleString()}}}
        }
    });
})();
<?php endif; ?>

<?php if ($tab === 'compare' && $cmp_active): ?>
(function(){
    const ctx = document.getElementById('cmpChart');
    if (!ctx) return;
    const colors  = ['#0284c7','#d97706','#7c3aed'];
    const periods = <?= json_encode(array_map(fn($k)=>$cmp[$k]['label'], $cols)) ?>;
    const rev  = <?= json_encode(array_map(fn($k)=>round($cmp[$k]['rev_total'],2), $cols)) ?>;
    const exp  = <?= json_encode(array_map(fn($k)=>round($cmp[$k]['exp_total'],2), $cols)) ?>;
    const prof = <?= json_encode(array_map(fn($k)=>round($cmp[$k]['net_profit'],2), $cols)) ?>;
    new Chart(ctx, {
        type:'bar',
        data:{
            labels:['Revenue','Expenses','Net Profit'],
            datasets: periods.map((lbl,i) => ({
                label:lbl, data:[rev[i],exp[i],prof[i]],
                backgroundColor:colors[i]+'bb', borderColor:colors[i],
                borderWidth:1, borderRadius:4,
            }))
        },
        options:{
            responsive:true,
            interaction:{mode:'index',intersect:false},
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
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
