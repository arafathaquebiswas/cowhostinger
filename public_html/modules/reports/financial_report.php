<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireNotBlocked();

$db    = getDB();
$ff    = farmFilter();
$ff_ft = farmFilter('ft');
$farm  = currentFarm();
$user  = currentUser();
$today = date('Y-m-d');

// Ensure waste_records table exists before any JOIN uses it
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `waste_records` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `farm_id` INT UNSIGNED NOT NULL,
      `waste_date` DATE NOT NULL,
      `category` ENUM('milk','feed','medicine','animal','equipment','other') NOT NULL DEFAULT 'other',
      `item_name` VARCHAR(200) NOT NULL,
      `quantity` DECIMAL(10,3) DEFAULT NULL,
      `unit` VARCHAR(50) DEFAULT NULL,
      `unit_price` DECIMAL(12,2) DEFAULT NULL,
      `total_loss` DECIMAL(12,2) NOT NULL,
      `reason` TEXT DEFAULT NULL,
      `finance_transaction_id` INT UNSIGNED DEFAULT NULL,
      `recorded_by` INT UNSIGNED NOT NULL,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

// ── Report type & mode ────────────────────────────────────────────────────────
$report_type = in_array($_GET['type'] ?? '', ['weekly','monthly','yearly','custom'], true)
               ? $_GET['type'] : '';
$mode = ($_GET['mode'] ?? '') === 'print';

// ── Configuration form (shown inside the app layout) ─────────────────────────
if ($report_type === '' && !$mode) {
    $page_title = 'PDF Financial Report';
    $active_nav = 'financial_report';
    require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
    ?>
    <div class="page-header">
        <div>
            <h1 class="page-title">PDF Financial Report</h1>
            <p class="page-subtitle">Professional bank-statement style financial document — suitable for auditors, banks, and investors</p>
        </div>
    </div>
    <div class="card" style="max-width:580px">
        <div class="card-header"><span class="card-title">Report Configuration</span></div>
        <div class="card-body">
            <form method="GET">
                <input type="hidden" name="mode" value="print">
                <div class="form-group">
                    <label class="form-label">Report Type <span style="color:var(--danger)">*</span></label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                        <?php foreach ([
                            'monthly' => ['📅','Monthly Report','Current calendar month'],
                            'yearly'  => ['📆','Yearly Report','Current calendar year'],
                            'weekly'  => ['🗓','Weekly Report','Last 7 days'],
                            'custom'  => ['✏️','Custom Range','Pick any date range'],
                        ] as $k => [$ic,$lb,$ds]): ?>
                        <label style="display:flex;align-items:flex-start;gap:.6rem;border:2px solid var(--border);border-radius:10px;padding:.75rem;cursor:pointer;transition:.15s" id="rt-<?= $k ?>">
                            <input type="radio" name="type" value="<?= $k ?>" onchange="handleType()" style="margin-top:.15rem;accent-color:var(--primary)">
                            <div>
                                <div style="font-weight:700;font-size:.9rem"><?= $ic ?> <?= $lb ?></div>
                                <div style="font-size:.78rem;color:#6b7280;margin-top:.1rem"><?= $ds ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="custom_range" style="display:none">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                        <div class="form-group">
                            <label class="form-label">From Date</label>
                            <input type="date" name="from" class="form-control" value="<?= date('Y-m-01') ?>" max="<?= $today ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">To Date</label>
                            <input type="date" name="to"   class="form-control" value="<?= $today ?>" max="<?= $today ?>">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Custom Report Title (optional)</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Q2 2026 Financial Statement" maxlength="100">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.5rem">
                    Generate &amp; Preview Report →
                </button>
            </form>
        </div>
    </div>
    <script>
    function handleType() {
        var val = document.querySelector('input[name=type]:checked')?.value;
        document.getElementById('custom_range').style.display = val === 'custom' ? '' : 'none';
        document.querySelectorAll('[id^="rt-"]').forEach(function(el) {
            var active = el.id === 'rt-' + val;
            el.style.borderColor = active ? 'var(--primary)' : 'var(--border)';
            el.style.background  = active ? 'var(--success-soft,#e8f5e9)' : '';
        });
    }
    </script>
    <?php
    require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
    exit;
}

// ── Date range ────────────────────────────────────────────────────────────────
switch ($report_type) {
    case 'weekly':
        $from = date('Y-m-d', strtotime('-6 days'));
        $to   = $today;
        $range_label = 'Weekly Financial Report';
        $report_type_label = 'Weekly';
        break;
    case 'monthly':
        $from = date('Y-m-01');
        $to   = date('Y-m-t');
        $range_label = date('F Y') . ' Monthly Financial Report';
        $report_type_label = 'Monthly';
        break;
    case 'yearly':
        $from = date('Y-01-01');
        $to   = date('Y-12-31');
        $range_label = date('Y') . ' Annual Financial Report';
        $report_type_label = 'Yearly';
        break;
    default:
        $from = trim($_GET['from'] ?? date('Y-m-01'));
        $to   = trim($_GET['to']   ?? $today);
        if (!strtotime($from)) $from = date('Y-m-01');
        if (!strtotime($to))   $to   = $today;
        if ($from > $to) [$from, $to] = [$to, $from];
        $range_label = 'Financial Statement Report';
        $report_type_label = 'Custom';
}

$custom_title      = isset($_GET['title']) ? trim($_GET['title']) : '';
$report_title      = $custom_title ?: $range_label;
$report_id         = 'RPT-' . strtoupper($farm['farm_code'] ?? 'FARM') . '-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
$generated_at      = date('d M Y, H:i:s');
$date_range_label  = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));

// ── Fetch all transactions with supplemental data ─────────────────────────────
try {
    $q = $db->prepare("
        SELECT
            ft.id, ft.type, ft.category, ft.amount, ft.notes,
            ft.related_module, ft.reference_id, ft.transaction_date,
            u.name AS recorder,
            ms.liters_sold   AS ms_qty,
            ms.price_per_liter AS ms_unit_price,
            ms.customer_name AS ms_customer,
            wr.item_name     AS wr_item,
            wr.quantity      AS wr_qty,
            wr.unit          AS wr_unit,
            wr.unit_price    AS wr_unit_price,
            wr.category      AS wr_category
        FROM finance_transactions ft
        LEFT JOIN users u
               ON u.id = ft.recorded_by
        LEFT JOIN milk_sales ms
               ON ms.id = ft.reference_id AND ft.related_module = 'milk' AND ms.farm_id = ft.farm_id
        LEFT JOIN waste_records wr
               ON wr.id = ft.reference_id AND ft.related_module = 'waste' AND wr.farm_id = ft.farm_id
        WHERE {$ff_ft}
          AND DATE(ft.transaction_date) BETWEEN ? AND ?
        ORDER BY ft.transaction_date ASC, ft.id ASC
    ");
    $q->execute([$from, $to]);
    $all_txns = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $all_txns = [];
}

// ── Compute running balance & aggregates ──────────────────────────────────────
// Equipment Purchase = capital expenditure (asset). Excluded from operating P&L.
// It is shown in the ledger and in a separate CAPEX line, but does NOT reduce
// gross/net profit — only operating expenses and waste do.
$total_income  = 0.0;
$total_expense = 0.0; // operating expenses only
$total_waste   = 0.0;
$total_capex   = 0.0; // Equipment Purchase (tracked but excluded from P&L)
$running_bal   = 0.0; // running cash position (includes capex cash outflow)
$txn_count     = count($all_txns);

foreach ($all_txns as &$t) {
    $amt = (float)$t['amount'];
    if ($t['type'] === 'income') {
        $total_income += $amt;
        $running_bal  += $amt;
        $t['_debit']   = 0;
        $t['_credit']  = $amt;
        $t['_pl']      = $amt;
    } else {
        if ($t['category'] === 'Waste Loss')         { $total_waste   += $amt; }
        elseif ($t['category'] === 'Equipment Purchase') { $total_capex   += $amt; }
        else                                          { $total_expense  += $amt; }
        $running_bal  -= $amt;
        $t['_debit']   = $amt;
        $t['_credit']  = 0;
        $t['_pl']      = -$amt;
    }
    $t['_balance'] = $running_bal;
}
unset($t);

$gross_profit = $total_income - $total_expense;
$net_profit   = $gross_profit - $total_waste;
$margin       = $total_income > 0 ? round(($net_profit / $total_income) * 100, 2) : 0;

// ── Owner details ─────────────────────────────────────────────────────────────
try {
    $oq = $db->prepare("SELECT name, email, phone FROM users WHERE farm_id=? AND role='admin' ORDER BY id ASC LIMIT 1");
    $oq->execute([fid()]);
    $owner = $oq->fetch() ?: [];
} catch (Throwable $e) { $owner = []; }

$plan_info  = farmPlan() ?: [];
$plan_label = strtoupper($plan_info['plan'] ?? 'Standard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($report_title) ?> — <?= e($farm['farm_name'] ?? 'Farm') ?></title>
<style>
/* ── Base reset ───────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;font-size:10pt;color:#1a1a1a;background:#d9dde3;line-height:1.45}

/* ── Screen chrome ─────────────────────────────────────────────────────────── */
.screen-bar{
    position:sticky;top:0;z-index:200;
    background:#0d2618;color:#fff;
    padding:.6rem 1.5rem;
    display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;
}
.screen-bar-title{flex:1;font-weight:700;font-size:.9rem;letter-spacing:.01em}
.btn-print{
    background:#2D6A4F;color:#fff;border:none;
    padding:.5rem 1.4rem;border-radius:6px;
    font-size:.88rem;font-weight:700;cursor:pointer;
    display:inline-flex;align-items:center;gap:.4rem;
    transition:background .15s;
}
.btn-print:hover{background:#1B4332}
.btn-back{
    background:rgba(255,255,255,.12);color:rgba(255,255,255,.85);
    border:1px solid rgba(255,255,255,.25);
    padding:.45rem .9rem;border-radius:6px;font-size:.82rem;font-weight:600;
    text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;
}
.btn-back:hover{background:rgba(255,255,255,.22)}

/* ── Page wrapper ──────────────────────────────────────────────────────────── */
.page-wrap{
    max-width:210mm;margin:1.25rem auto 2rem;
    background:#fff;
    box-shadow:0 8px 48px rgba(0,0,0,.2);
}

/* ── Watermark ─────────────────────────────────────────────────────────────── */
.watermark{
    position:fixed;top:50%;left:50%;
    transform:translate(-50%,-50%) rotate(-42deg);
    font-size:88pt;font-weight:900;
    color:rgba(13,38,24,.065);
    white-space:nowrap;pointer-events:none;
    z-index:0;letter-spacing:.12em;text-transform:uppercase;
    user-select:none;
}

/* ── Report content ────────────────────────────────────────────────────────── */
.rpt{padding:12mm 14mm 10mm;position:relative;z-index:1}

/* ── TOP ACCENT BAND ───────────────────────────────────────────────────────── */
.top-band{
    background:#0d2618;
    margin:-12mm -14mm 0;
    padding:4mm 14mm;
    display:flex;align-items:center;justify-content:space-between;
    gap:1rem;margin-bottom:0;
}
.top-band-left{font-size:8pt;color:rgba(255,255,255,.55);letter-spacing:.05em}
.top-band-right{font-size:8pt;color:rgba(255,255,255,.55);letter-spacing:.05em;text-align:right}

/* ── HEADER: two-column ────────────────────────────────────────────────────── */
.rpt-header{
    display:grid;
    grid-template-columns:1fr auto;
    gap:1rem;
    padding:6mm 0 5mm;
    border-bottom:3px solid #0d2618;
    margin-bottom:5mm;
}
.hdr-left{display:flex;gap:.9rem;align-items:flex-start}
.hdr-logo{
    width:64px;height:64px;border-radius:10px;
    background:linear-gradient(135deg,#0d2618 0%,#2D6A4F 100%);
    display:flex;align-items:center;justify-content:center;
    font-size:2rem;flex-shrink:0;color:#fff;
    border:2px solid #e5e7eb;
}
.hdr-farm-name{font-size:15pt;font-weight:900;color:#0d2618;letter-spacing:-.02em;line-height:1.1;text-transform:uppercase}
.hdr-farm-sub{font-size:8pt;color:#4b5563;margin-top:.3rem;line-height:1.65}
.hdr-farm-sub span{display:block}
.hdr-right{text-align:right;font-size:8pt;color:#374151;min-width:200px}
.hdr-meta-row{display:flex;justify-content:flex-end;gap:.5rem;margin-bottom:.22rem;line-height:1.5}
.hdr-meta-lbl{color:#6b7280;font-weight:600;white-space:nowrap}
.hdr-meta-val{color:#0d2618;font-weight:700;white-space:nowrap}

/* ── REPORT TITLE ──────────────────────────────────────────────────────────── */
.rpt-title-block{
    text-align:center;
    padding:4mm 0 3mm;
    border-bottom:1px solid #e5e7eb;
    margin-bottom:5mm;
}
.rpt-title-main{
    font-size:15pt;font-weight:900;letter-spacing:.08em;
    text-transform:uppercase;color:#0d2618;
}
.rpt-title-sub{
    font-size:9pt;color:#6b7280;margin-top:.35rem;font-weight:500;letter-spacing:.02em;
}

/* ── SECTION LABELS ────────────────────────────────────────────────────────── */
.sec-lbl{
    font-size:7.5pt;font-weight:800;text-transform:uppercase;letter-spacing:.09em;
    color:#fff;background:#1B4332;
    padding:.3rem .8rem;border-radius:3px;
    display:inline-block;margin-bottom:.6rem;
}

/* ── TRANSACTION TABLE ─────────────────────────────────────────────────────── */
.ledger-wrap{overflow-x:auto;margin-bottom:6mm}
/* table-layout:auto lets the browser size columns by content — avoids
   the fixed-px overflow that crushes the description column on A4 */
.ledger{width:100%;border-collapse:collapse;font-size:7.5pt;table-layout:auto}
.ledger thead tr{background:#0d2618;color:#fff}
.ledger thead th{
    padding:.32rem .4rem;font-size:6.5pt;font-weight:700;
    text-transform:uppercase;letter-spacing:.04em;
    border-right:1px solid rgba(255,255,255,.12);white-space:nowrap;
}
.ledger thead th:last-child{border-right:none}
.ledger thead th.r{text-align:right}
.ledger thead th.c{text-align:center}
.ledger tbody tr:nth-child(odd){background:#f9fafb}
.ledger tbody tr:nth-child(even){background:#fff}
.ledger tbody tr:hover{background:#eff6ff}
.ledger td{padding:.3rem .4rem;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.ledger td.r{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ledger td.c{text-align:center}
.ledger td.mono{font-family:'Courier New',monospace;font-size:7pt;white-space:nowrap}
.credit{color:#15803d;font-weight:700}
.debit{color:#b91c1c;font-weight:700}
.waste-amt{color:#c05621;font-weight:700}
.profit-pos{color:#15803d;font-weight:600}
.profit-neg{color:#b91c1c;font-weight:600}
.balance-col{color:#1e3a8a;font-weight:700}
.bal-neg{color:#b91c1c}

/* Type badge */
.type-badge{
    display:inline-block;padding:.05rem .3rem;border-radius:3px;
    font-size:6pt;font-weight:800;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;
}
.type-inc{background:#dcfce7;color:#15803d}
.type-exp{background:#fee2e2;color:#b91c1c}
.type-wst{background:#fef3c7;color:#92400e}

/* table footer */
.ledger tfoot td{
    padding:.38rem .4rem;font-weight:800;font-size:7.5pt;
    border-top:2.5px solid #0d2618;background:#f0fdf4;white-space:nowrap;
}
.ledger tfoot td.r{text-align:right}

/* ── FINANCIAL SUMMARY ─────────────────────────────────────────────────────── */
.summary-outer{
    background:#0d2618;border-radius:8px;
    padding:5mm 6mm;margin-bottom:5mm;
    page-break-inside:avoid;break-inside:avoid;
}
.summary-title{
    font-size:10pt;font-weight:900;color:#fff;letter-spacing:.06em;
    text-transform:uppercase;text-align:center;
    padding-bottom:3mm;margin-bottom:3mm;
    border-bottom:1px solid rgba(255,255,255,.2);
}
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:.75rem}
.sum-card{
    background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);
    border-radius:6px;padding:.65rem .8rem;
}
.sum-card-lbl{font-size:7pt;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.55);font-weight:700;margin-bottom:.2rem}
.sum-card-val{font-size:12.5pt;font-weight:900;line-height:1}
.sum-green{color:#4ade80}
.sum-red{color:#f87171}
.sum-amber{color:#fbbf24}
.sum-blue{color:#93c5fd}
.sum-white{color:#fff}
.net-row{
    background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
    border-radius:6px;padding:.65rem 1rem;
    display:flex;align-items:center;justify-content:space-between;
    margin-top:.5rem;
}
.net-lbl{font-size:9pt;font-weight:800;color:rgba(255,255,255,.8);letter-spacing:.04em;text-transform:uppercase}
.net-val{font-size:15pt;font-weight:900}
.net-margin{font-size:7.5pt;color:rgba(255,255,255,.5);margin-top:.1rem}

/* ── FORMULA SECTION ───────────────────────────────────────────────────────── */
.formula-box{
    border:1.5px solid #d1d5db;border-left:4px solid #1B4332;
    border-radius:6px;padding:4mm 5mm;margin-bottom:5mm;
    background:#fafafa;
    page-break-inside:avoid;break-inside:avoid;
}
.formula-title{font-size:9pt;font-weight:800;color:#1B4332;margin-bottom:.6rem;text-transform:uppercase;letter-spacing:.05em}
.formula-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem}
.formula-item{font-size:8pt;color:#374151;line-height:1.7}
.formula-item strong{color:#0d2618;font-size:8.5pt;display:block;margin-bottom:.15rem}
.formula-sep{border:none;border-top:1.5px solid #d1fae5;margin:.25rem 0}
.formula-result{font-weight:800;color:#0d2618}

/* ── SIGNATURE ─────────────────────────────────────────────────────────────── */
.sig-section{
    display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;
    margin-top:6mm;margin-bottom:4mm;
    page-break-inside:avoid;break-inside:avoid;
}
.sig-box{border-top:2px solid #1B4332;padding-top:.5rem}
.sig-heading{font-size:8pt;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#1B4332;margin-bottom:.4rem}
.sig-line{height:36px;border-bottom:1px dashed #9ca3af;margin-bottom:.35rem}
.sig-field{font-size:7.5pt;color:#4b5563;margin-bottom:.2rem}
.sig-field span{color:#1a1a1a;font-weight:600}

/* ── BOTTOM FOOTER (print) ────────────────────────────────────────────────── */
.rpt-footer{
    border-top:2px solid #0d2618;padding-top:.55rem;margin-top:.5rem;
    display:flex;align-items:center;justify-content:space-between;
    font-size:7pt;color:#6b7280;
}
.rpt-footer strong{color:#0d2618}

/* ── Fixed page elements for print ────────────────────────────────────────── */
.print-page-hdr{display:none}
.print-page-ftr{display:none}

/* ── Empty state ────────────────────────────────────────────────────────────── */
.empty-ledger{text-align:center;padding:2rem;color:#9ca3af;font-size:9pt;border:1px dashed #e5e7eb;border-radius:6px;margin-bottom:6mm}

/* ═══════════════════ PRINT MEDIA ═════════════════════════════════════════ */
@page{
    size:A4;
    margin:10mm 12mm 14mm;
}
@media print{
    body{background:#fff;font-size:9pt}
    .screen-bar{display:none!important}
    .page-wrap{box-shadow:none;max-width:none;margin:0}
    .watermark{font-size:90pt}

    /* ── Critical table fixes ───────────────────────────────────────────── */
    /* overflow-x:auto clips rows in print — set visible so the full table
       flows across multiple pages instead of being truncated               */
    .ledger-wrap{overflow:visible!important}

    /* table-layout:auto adapts columns to content — avoids the fixed-px
       problem that collapses the description column to 0 on A4            */
    .ledger{table-layout:auto!important;width:100%;font-size:7pt}
    .ledger thead{display:table-header-group} /* repeat header on every page */
    .ledger tfoot{display:table-footer-group} /* totals stay at the bottom   */

    /* Allow tbody rows to break across pages — do NOT set avoid on all tr  */
    .ledger tbody tr{page-break-inside:auto;break-inside:auto}
    .ledger thead tr,.ledger tfoot tr{page-break-inside:avoid;break-inside:avoid}

    /* Prevent hover tint from printing */
    .ledger tbody tr:hover{background:inherit}

    .print-page-hdr{
        display:flex;position:running(header);
        justify-content:space-between;align-items:center;
        font-size:7pt;color:#6b7280;padding-bottom:2mm;
        border-bottom:1px solid #e5e7eb;
    }
    .print-page-ftr{
        display:flex;position:running(footer);
        justify-content:space-between;align-items:center;
        font-size:7pt;color:#6b7280;padding-top:2mm;
        border-top:1px solid #e5e7eb;
    }
    @page{
        @top-left   { content: element(header-left);   font-size:7pt; color:#6b7280; }
        @top-right  { content: element(header-right);  font-size:7pt; color:#6b7280; }
        @bottom-left{ content: element(footer-left);   font-size:7pt; color:#6b7280; }
        @bottom-center{content: "Page " counter(page) " of " counter(pages)" — Farm Internal Use Only"; font-size:7pt; color:#6b7280; }
        @bottom-right{content: element(footer-right);  font-size:7pt; color:#6b7280; }
    }
    .top-band{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .rpt-header,.ledger thead,.summary-outer,.formula-box{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .no-break{page-break-inside:avoid;break-inside:avoid}
    .page-break-before{page-break-before:always;break-before:page}
}
</style>
</head>
<body>

<!-- Watermark -->
<div class="watermark"><?= e(mb_strtoupper($farm['farm_name'] ?? 'FARM')) ?></div>

<!-- Screen toolbar -->
<div class="screen-bar">
    <span class="screen-bar-title">📄 <?= e($report_title) ?></span>
    <a href="/modules/reports/financial_report.php" class="btn-back">← Back</a>
    <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
</div>

<div class="page-wrap">
<div class="rpt">

<!-- ── TOP ACCENT BAND ───────────────────────────────────────────────────── -->
<div class="top-band">
    <div class="top-band-left">CONFIDENTIAL — FARM FINANCIAL DOCUMENT</div>
    <div class="top-band-right">Report ID: <?= e($report_id) ?> &nbsp;|&nbsp; Generated by AB IT</div>
</div>

<!-- ══ HEADER: two-column ════════════════════════════════════════════════════ -->
<div class="rpt-header">
    <!-- Left: Logo + Farm Info -->
    <div class="hdr-left">
        <div class="hdr-logo">🐄</div>
        <div>
            <div class="hdr-farm-name"><?= e($farm['farm_name'] ?? 'Farm') ?></div>
            <div class="hdr-farm-sub">
                <?php if (!empty($farm['address'])): ?>
                <span><?= e($farm['address']) ?></span>
                <?php endif; ?>
                <?php if (!empty($farm['phone'] ?? $owner['phone'] ?? '')): ?>
                <span>📞 <?= e($farm['phone'] ?? $owner['phone'] ?? '') ?></span>
                <?php endif; ?>
                <?php if (!empty($owner['email'] ?? '')): ?>
                <span>✉ <?= e($owner['email']) ?></span>
                <?php endif; ?>
                <?php if (!empty($farm['farm_code'])): ?>
                <span style="margin-top:.15rem;font-size:7.5pt;color:#9ca3af">Reg: <?= e($farm['farm_code']) ?> &nbsp;·&nbsp; Plan: <?= e($plan_label) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: Report Metadata -->
    <div class="hdr-right">
        <?php $meta = [
            'Farm Reg. ID'  => e($farm['farm_code']  ?? '—'),
            'Owner Name'    => e($owner['name']       ?? '—'),
            'Owner Phone'   => e($owner['phone'] ?? $farm['phone'] ?? '—'),
            'Report ID'     => e($report_id),
            'Report Type'   => e($report_type_label),
            'Date Range'    => e($date_range_label),
            'Generated On'  => e($generated_at),
        ];
        foreach ($meta as $lbl => $val): ?>
        <div class="hdr-meta-row">
            <span class="hdr-meta-lbl"><?= $lbl ?>:</span>
            <span class="hdr-meta-val"><?= $val ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ══ REPORT TITLE ══════════════════════════════════════════════════════════ -->
<div class="rpt-title-block">
    <div class="rpt-title-main"><?= e(strtoupper($report_title)) ?></div>
    <div class="rpt-title-sub">
        <?= e($date_range_label) ?>
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <?= $txn_count ?> Transactions
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <?= e($farm['farm_name'] ?? 'Farm') ?>
    </div>
</div>

<!-- ══ TRANSACTION LEDGER ════════════════════════════════════════════════════ -->
<div class="sec-lbl">Transaction Ledger</div>

<?php if (empty($all_txns)): ?>
<div class="empty-ledger">
    No financial transactions recorded for <strong><?= e($date_range_label) ?></strong>.<br>
    <span style="font-size:8pt">Transactions will appear here once recorded in the system.</span>
</div>
<?php else: ?>

<div class="ledger-wrap">
<table class="ledger">
    <thead>
        <tr>
            <th style="width:10%">Date</th>
            <th class="c" style="width:9%">Type</th>
            <th style="width:16%">Category</th>
            <th class="r" style="width:8%">Qty</th>
            <th class="r" style="width:10%">Unit Price</th>
            <th class="r" style="width:14%">Income (৳)</th>
            <th class="r" style="width:14%">Expense (৳)</th>
            <th class="r" style="width:12%">P / L</th>
            <th class="r" style="width:12%">Balance (৳)</th>
        </tr>
    </thead>
    <tbody>
    <?php
    foreach ($all_txns as $idx => $t):
        $amt   = (float)$t['amount'];
        $is_income  = ($t['type'] === 'income');
        $is_waste   = ($t['category'] === 'Waste Loss');

        // Qty
        if ($t['related_module'] === 'milk' && $t['ms_qty'] !== null) {
            $qty_str = number_format((float)$t['ms_qty'], 1) . ' L';
        } elseif ($t['related_module'] === 'waste' && $t['wr_qty'] !== null) {
            $qty_str = number_format((float)$t['wr_qty'], 2) . ($t['wr_unit'] ? ' ' . $t['wr_unit'] : '');
        } else {
            $qty_str = '—';
        }

        // Unit price
        if ($t['related_module'] === 'milk' && $t['ms_unit_price'] !== null) {
            $upr_str = '৳' . number_format((float)$t['ms_unit_price'], 2);
        } elseif ($t['related_module'] === 'waste' && $t['wr_unit_price'] !== null) {
            $upr_str = '৳' . number_format((float)$t['wr_unit_price'], 2);
        } else {
            $upr_str = '—';
        }

        // Badge
        if ($is_income) { $badge = '<span class="type-badge type-inc">Income</span>'; }
        elseif ($is_waste) { $badge = '<span class="type-badge type-wst">Loss</span>'; }
        else               { $badge = '<span class="type-badge type-exp">Expense</span>'; }

        $pl = $t['_pl'];
        $bal = $t['_balance'];
    ?>
    <tr>
        <td class="mono"><?= date('d M y', strtotime($t['transaction_date'])) ?></td>
        <td class="c"><?= $badge ?></td>
        <td style="color:#374151"><?= e($t['category']) ?></td>
        <td class="r mono" style="color:#6b7280"><?= $qty_str ?></td>
        <td class="r mono" style="color:#6b7280"><?= $upr_str ?></td>
        <td class="r<?= $is_income ? ' credit' : '' ?>"><?= $is_income ? '৳' . number_format($amt, 2) : '' ?></td>
        <td class="r<?= !$is_income ? ($is_waste ? ' waste-amt' : ' debit') : '' ?>"><?= !$is_income ? '৳' . number_format($amt, 2) : '' ?></td>
        <td class="r <?= $pl >= 0 ? 'profit-pos' : 'profit-neg' ?>">
            <?= ($pl >= 0 ? '+' : '−') ?>৳<?= number_format(abs($pl), 2) ?>
        </td>
        <td class="r <?= $bal >= 0 ? 'balance-col' : 'balance-col bal-neg' ?>">
            <?= $bal < 0 ? '(৳' . number_format(abs($bal), 2) . ')' : '৳' . number_format($bal, 2) ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" class="r" style="font-size:8.5pt;color:#1B4332;text-transform:uppercase;letter-spacing:.04em">Closing Balance / Net Position</td>
            <td class="r credit">৳<?= number_format($total_income, 2) ?></td>
            <td class="r debit">৳<?= number_format($total_expense + $total_waste + $total_capex, 2) ?></td>
            <td class="r <?= $net_profit >= 0 ? 'profit-pos' : 'profit-neg' ?>">
                <?= $net_profit >= 0 ? '+' : '−' ?>৳<?= number_format(abs($net_profit), 2) ?>
            </td>
            <td class="r <?= $running_bal >= 0 ? 'balance-col' : 'balance-col bal-neg' ?>">
                <?= $running_bal < 0 ? '(৳' . number_format(abs($running_bal), 2) . ')' : '৳' . number_format($running_bal, 2) ?>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<?php endif; ?>

<!-- ══ FINANCIAL SUMMARY ═════════════════════════════════════════════════════ -->
<div class="summary-outer no-break">
    <div class="summary-title">Financial Summary (P&amp;L)</div>
    <div class="summary-grid">
        <div class="sum-card">
            <div class="sum-card-lbl">Total Revenue</div>
            <div class="sum-card-val sum-green">৳<?= number_format($total_income, 2) ?></div>
        </div>
        <div class="sum-card">
            <div class="sum-card-lbl">Operating Expenses</div>
            <div class="sum-card-val sum-red">৳<?= number_format($total_expense, 2) ?></div>
        </div>
        <div class="sum-card">
            <div class="sum-card-lbl">Loss / Waste</div>
            <div class="sum-card-val sum-amber">৳<?= number_format($total_waste, 2) ?></div>
        </div>
        <div class="sum-card">
            <div class="sum-card-lbl">Gross Profit</div>
            <div class="sum-card-val <?= $gross_profit >= 0 ? 'sum-green' : 'sum-red' ?>">
                <?= $gross_profit < 0 ? '−' : '' ?>৳<?= number_format(abs($gross_profit), 2) ?>
            </div>
        </div>
        <div class="sum-card">
            <div class="sum-card-lbl">Profit Margin</div>
            <div class="sum-card-val <?= $margin >= 0 ? 'sum-blue' : 'sum-red' ?>"><?= $margin ?>%</div>
        </div>
        <div class="sum-card">
            <div class="sum-card-lbl">Equipment CAPEX</div>
            <div class="sum-card-val" style="color:#a78bfa;font-size:11pt">৳<?= number_format($total_capex, 2) ?></div>
        </div>
    </div>
    <div class="net-row">
        <div>
            <div class="net-lbl">Net Profit / (Loss)</div>
            <div class="net-margin">Income − Expenses − Losses for <?= e($date_range_label) ?></div>
        </div>
        <div style="text-align:right">
            <div class="net-val <?= $net_profit >= 0 ? 'sum-green' : 'sum-red' ?>">
                <?= $net_profit < 0 ? '(৳' . number_format(abs($net_profit), 2) . ')' : '৳' . number_format($net_profit, 2) ?>
            </div>
        </div>
    </div>
</div>

<!-- ══ FORMULA SECTION ═══════════════════════════════════════════════════════ -->
<div class="formula-box no-break">
    <div class="formula-title">Calculation Formulas</div>
    <div class="formula-grid">
        <div class="formula-item">
            <strong>Gross Profit</strong>
            Total Income<hr class="formula-sep">− Total Expenses<hr class="formula-sep">
            <span class="formula-result">= ৳<?= number_format($gross_profit, 2) ?></span>
        </div>
        <div class="formula-item">
            <strong>Net Profit</strong>
            Gross Profit<hr class="formula-sep">− Total Loss / Waste<hr class="formula-sep">
            <span class="formula-result">= <?= $net_profit < 0 ? '(৳' . number_format(abs($net_profit), 2) . ')' : '৳' . number_format($net_profit, 2) ?></span>
        </div>
        <div class="formula-item">
            <strong>Profit Margin</strong>
            (Net Profit ÷ Income)<hr class="formula-sep">× 100<hr class="formula-sep">
            <span class="formula-result">= <?= $margin ?>%</span>
        </div>
    </div>
</div>

<!-- ══ SIGNATURE SECTION ═════════════════════════════════════════════════════ -->
<div class="sig-section">
    <div class="sig-box">
        <div class="sig-heading">Prepared By</div>
        <div class="sig-line"></div>
        <div class="sig-field">Name: <span>_________________________________</span></div>
        <div class="sig-field">Date: <span>_________________________________</span></div>
        <div class="sig-field">Position: <span>Accountant / Manager</span></div>
    </div>
    <div class="sig-box">
        <div class="sig-heading">Approved By (Farm Owner)</div>
        <div class="sig-line" style="border-bottom-style:solid"></div>
        <div class="sig-field">Name: <span><?= e($owner['name'] ?? '_________________________________') ?></span></div>
        <div class="sig-field">Date: <span>_________________________________</span></div>
        <div class="sig-field">Signature &amp; Official Seal</div>
    </div>
</div>

<!-- ══ DOCUMENT FOOTER ═══════════════════════════════════════════════════════ -->
<div class="rpt-footer">
    <div>
        <strong><?= e($owner['name'] ?? $farm['farm_name'] ?? 'Farm Owner') ?></strong>
        &nbsp;·&nbsp; <?= e($farm['farm_name'] ?? '') ?>
        &nbsp;·&nbsp; Reg: <?= e($farm['farm_code'] ?? '—') ?>
    </div>
    <div style="text-align:center;font-size:6.5pt;color:#9ca3af">
        FARM INTERNAL USE ONLY &nbsp;|&nbsp; Report ID: <?= e($report_id) ?>
    </div>
    <div style="text-align:right">
        <strong>Generated by AB IT</strong><br>
        <?= $generated_at ?>
    </div>
</div>

</div><!-- .rpt -->
</div><!-- .page-wrap -->

<script>
if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
    window.addEventListener('load', function() { setTimeout(window.print, 600); });
}
</script>
</body>
</html>
