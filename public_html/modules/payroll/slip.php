<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireAccess('payroll.view');

$db = getDB();
$ff = farmFilter();

$record_id = (int)($_GET['id'] ?? 0);
if ($record_id <= 0) { redirect('/modules/payroll/index.php'); }

// Load record + batch + worker — farm scoped
$stmt = $db->prepare(
    "SELECT pr.*,
            pb.period_label, pb.period_from, pb.period_to, pb.status AS batch_status,
            u.name AS worker_name, u.email AS worker_email,
            w.hire_date, w.salary AS contract_salary,
            f.farm_name, f.owner_name
     FROM payroll_records pr
     JOIN payroll_batches pb ON pb.id = pr.batch_id
     JOIN workers w          ON w.id  = pr.worker_id
     JOIN users   u          ON u.id  = w.user_id
     JOIN farms   f          ON f.id  = pr.farm_id
     WHERE pr.id = ? AND pr.{$ff}"
);
$stmt->execute([$record_id]);
$rec = $stmt->fetch();

if (!$rec) {
    flashMessage('error', 'Pay slip not found.');
    redirect('/modules/payroll/index.php');
}

$daily_rate = $rec['working_days'] > 0
    ? round($rec['basic_salary'] / $rec['working_days'], 2)
    : 0;
$gross = round(($daily_rate * $rec['present_days']) + $rec['overtime_pay'] + $rec['bonuses'], 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Slip — <?= e($rec['worker_name']) ?> — <?= e($rec['period_label']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 13px;
            color: #1F2937;
            background: #f3f4f6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
        }
        /* Screen nav */
        .slip-nav {
            width: 100%;
            max-width: 680px;
            display: flex;
            gap: .5rem;
            margin-bottom: 1.25rem;
        }
        .slip-btn {
            padding: .4rem .9rem;
            border-radius: 6px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
        }
        .slip-btn-primary { background: #2D6A4F; color: #fff; }
        .slip-btn-secondary { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .slip-btn:hover { opacity: .88; }

        /* Slip card */
        .slip {
            width: 100%;
            max-width: 680px;
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
        }

        /* Header */
        .slip-header {
            background: linear-gradient(135deg, #1B4332 0%, #2D6A4F 100%);
            color: #fff;
            padding: 1.5rem 1.75rem 1.25rem;
        }
        .slip-company { font-size: 1.1rem; font-weight: 800; letter-spacing: -.01em; }
        .slip-subtitle { font-size: .72rem; color: rgba(255,255,255,.7); margin-top: .1rem; }
        .slip-title-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 1.1rem;
            flex-wrap: wrap;
            gap: .5rem;
        }
        .slip-period { font-size: .82rem; font-weight: 600; color: rgba(255,255,255,.85); }
        .slip-status {
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            padding: .2rem .65rem;
            border-radius: 50px;
        }
        .slip-status.paid    { background: #D1FAE5; color: #065F46; }
        .slip-status.pending { background: #FEF3C7; color: #92400E; }

        /* Employee info */
        .slip-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border-bottom: 1px solid #E5E7EB;
        }
        .slip-info-cell {
            padding: 1rem 1.75rem;
            border-right: 1px solid #E5E7EB;
        }
        .slip-info-cell:nth-child(even) { border-right: none; }
        .info-label {
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #9CA3AF;
            margin-bottom: .2rem;
        }
        .info-val { font-size: .88rem; font-weight: 600; color: #111827; }
        .info-sub { font-size: .72rem; color: #6B7280; margin-top: .08rem; }

        /* Earnings / Deductions table */
        .slip-body { padding: 1.25rem 1.75rem; }
        .slip-section-title {
            font-size: .65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #9CA3AF;
            margin-bottom: .6rem;
            padding-bottom: .35rem;
            border-bottom: 2px solid #F3F4F6;
        }
        .slip-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .42rem 0;
            border-bottom: 1px solid #F9FAFB;
            font-size: .84rem;
        }
        .slip-row:last-child { border-bottom: none; }
        .slip-row-label { color: #374151; }
        .slip-row-val   { font-weight: 600; color: #111827; }
        .slip-row-sub   { font-size: .72rem; color: #9CA3AF; margin-top: .05rem; }
        .deduct-val { color: #DC2626; }

        /* Totals */
        .slip-totals {
            background: #F9FAFB;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-top: 1.25rem;
        }
        .slip-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .85rem;
            padding: .3rem 0;
        }
        .slip-total-row.gross { color: #374151; }
        .slip-total-row.deduct { color: #DC2626; }
        .slip-total-row.net {
            margin-top: .5rem;
            padding-top: .65rem;
            border-top: 2px solid #E5E7EB;
            font-size: 1rem;
            font-weight: 800;
            color: #065F46;
        }

        /* Payment info */
        .slip-payment {
            margin-top: 1.25rem;
            padding: .85rem 1.25rem;
            background: <?= $rec['status'] === 'paid' ? '#ECFDF5' : '#FFFBEB' ?>;
            border-radius: 8px;
            border: 1px solid <?= $rec['status'] === 'paid' ? '#A7F3D0' : '#FDE68A' ?>;
            display: flex;
            align-items: center;
            gap: .75rem;
            font-size: .83rem;
        }
        .slip-payment-icon { font-size: 1.2rem; }
        .slip-payment-text { color: <?= $rec['status'] === 'paid' ? '#065F46' : '#92400E' ?>; }
        .slip-payment-text strong { font-weight: 700; }

        /* Footer */
        .slip-footer {
            padding: 1rem 1.75rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 1rem;
            border-top: 1px solid #F3F4F6;
        }
        .slip-sig {
            text-align: center;
            min-width: 140px;
        }
        .slip-sig-line {
            height: 1px;
            background: #D1D5DB;
            margin-bottom: .3rem;
        }
        .slip-sig-label { font-size: .68rem; color: #9CA3AF; }
        .slip-watermark {
            font-size: .65rem;
            color: #D1D5DB;
            text-align: center;
            margin-top: .25rem;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .slip-nav { display: none; }
            .slip {
                max-width: 100%;
                border-radius: 0;
                box-shadow: none;
            }
        }

        @media (max-width: 480px) {
            .slip-info { grid-template-columns: 1fr; }
            .slip-info-cell { border-right: none; border-bottom: 1px solid #E5E7EB; }
        }
    </style>
</head>
<body>

<!-- Screen only nav -->
<div class="slip-nav">
    <button class="slip-btn slip-btn-primary" onclick="window.print()">🖨 Print / Save PDF</button>
    <a href="/modules/payroll/view.php?id=<?= (int)$rec['batch_id'] ?>" class="slip-btn slip-btn-secondary">← Back to Batch</a>
    <a href="/modules/payroll/index.php" class="slip-btn slip-btn-secondary">Payroll List</a>
</div>

<!-- Pay Slip -->
<div class="slip">

    <!-- Header -->
    <div class="slip-header">
        <div class="slip-company"><?= e($rec['farm_name']) ?></div>
        <div class="slip-subtitle">PAY SLIP / SALARY STATEMENT</div>
        <div class="slip-title-row">
            <div class="slip-period">
                <?= e($rec['period_label']) ?> &nbsp;·&nbsp;
                <?= date('d M', strtotime($rec['period_from'])) ?> – <?= date('d M Y', strtotime($rec['period_to'])) ?>
            </div>
            <span class="slip-status <?= $rec['status'] ?>">
                <?= $rec['status'] === 'paid' ? '✓ Paid' : '⏳ Pending' ?>
            </span>
        </div>
    </div>

    <!-- Employee details -->
    <div class="slip-info">
        <div class="slip-info-cell">
            <div class="info-label">Employee Name</div>
            <div class="info-val"><?= e($rec['worker_name']) ?></div>
            <div class="info-sub"><?= e($rec['worker_email']) ?></div>
        </div>
        <div class="slip-info-cell">
            <div class="info-label">Pay Period</div>
            <div class="info-val"><?= e($rec['period_label']) ?></div>
            <div class="info-sub">Slip #<?= str_pad($rec['id'], 6, '0', STR_PAD_LEFT) ?></div>
        </div>
        <div class="slip-info-cell">
            <div class="info-label">Date of Joining</div>
            <div class="info-val"><?= $rec['hire_date'] ? date('d M Y', strtotime($rec['hire_date'])) : '—' ?></div>
        </div>
        <div class="slip-info-cell">
            <div class="info-label">Attendance</div>
            <div class="info-val"><?= $rec['present_days'] ?> / <?= $rec['working_days'] ?> days</div>
            <div class="info-sub"><?= $rec['working_days'] > 0 ? round($rec['present_days'] / $rec['working_days'] * 100) : 0 ?>% attendance</div>
        </div>
    </div>

    <!-- Earnings & Deductions -->
    <div class="slip-body">

        <div class="slip-section-title">Earnings</div>

        <div class="slip-row">
            <div>
                <div class="slip-row-label">Basic Salary</div>
                <div class="slip-row-sub">৳<?= number_format($daily_rate, 2) ?>/day × <?= $rec['present_days'] ?> days</div>
            </div>
            <div class="slip-row-val">৳<?= number_format($daily_rate * $rec['present_days'], 2) ?></div>
        </div>

        <?php if ($rec['overtime_pay'] > 0): ?>
        <div class="slip-row">
            <div class="slip-row-label">Overtime Pay</div>
            <div class="slip-row-val">৳<?= number_format($rec['overtime_pay'], 2) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($rec['bonuses'] > 0): ?>
        <div class="slip-row">
            <div class="slip-row-label">Bonus / Incentive</div>
            <div class="slip-row-val">৳<?= number_format($rec['bonuses'], 2) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($rec['deductions'] > 0): ?>
        <div style="margin-top:1rem">
            <div class="slip-section-title">Deductions</div>
            <div class="slip-row">
                <div class="slip-row-label">Deductions</div>
                <div class="slip-row-val deduct-val">- ৳<?= number_format($rec['deductions'], 2) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Totals -->
        <div class="slip-totals">
            <div class="slip-total-row gross">
                <span>Gross Earnings</span>
                <span>৳<?= number_format($gross, 2) ?></span>
            </div>
            <?php if ($rec['deductions'] > 0): ?>
            <div class="slip-total-row deduct">
                <span>Total Deductions</span>
                <span>- ৳<?= number_format($rec['deductions'], 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="slip-total-row net">
                <span>Net Salary Payable</span>
                <span>৳<?= number_format($rec['net_salary'], 2) ?></span>
            </div>
        </div>

        <!-- Payment status -->
        <div class="slip-payment">
            <span class="slip-payment-icon"><?= $rec['status'] === 'paid' ? '✅' : '⏳' ?></span>
            <span class="slip-payment-text">
                <?php if ($rec['status'] === 'paid'): ?>
                <strong>Payment Received</strong> — via <?= ucfirst($rec['payment_method']) ?>
                <?php if ($rec['payment_date']): ?>
                on <?= date('d M Y', strtotime($rec['payment_date'])) ?>
                <?php endif; ?>
                <?php else: ?>
                <strong>Payment Pending</strong> — via <?= ucfirst($rec['payment_method']) ?>
                <?php endif; ?>
            </span>
        </div>
        <?php if ($rec['notes']): ?>
        <p style="margin-top:.75rem;font-size:.78rem;color:#6B7280"><strong>Note:</strong> <?= e($rec['notes']) ?></p>
        <?php endif; ?>

    </div>

    <!-- Signature footer -->
    <div class="slip-footer">
        <div class="slip-sig">
            <div style="height:30px"></div>
            <div class="slip-sig-line"></div>
            <div class="slip-sig-label">Employee Signature</div>
        </div>
        <div style="text-align:center;font-size:.7rem;color:#9CA3AF">
            <div>Generated on <?= date('d M Y') ?></div>
            <div style="margin-top:.15rem"><?= e(APP_NAME) ?></div>
        </div>
        <div class="slip-sig">
            <div style="height:30px"></div>
            <div class="slip-sig-line"></div>
            <div class="slip-sig-label">Authorised Signature</div>
        </div>
    </div>
    <div class="slip-watermark">This is a computer-generated pay slip — no physical signature required when stamped.</div>
    <div style="height:.75rem"></div>
</div>

</body>
</html>
