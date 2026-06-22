<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'accountant']);
requireFarmScope();
requireAccess('payroll.create');
requireNotBlocked();

$page_title = 'Generate Payroll';
$active_nav = 'payroll';
$db  = getDB();
$ff  = farmFilter();
$uid = (int)$_SESSION['user_id'];

$errors       = [];
$step         = 1;
$workers      = [];
$period_label = '';
$period_from  = '';
$period_to    = '';
$working_days = 30;

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/payroll/generate.php');
    }

    $action = $_POST['action'] ?? '';

    // ── Step 1 → Step 2: Load workers for selected period ─────────────────────
    if ($action === 'load_workers') {
        $sel_year  = (int)($_POST['year']  ?? 0);
        $sel_month = (int)($_POST['month'] ?? 0);

        if ($sel_year < 2020 || $sel_year > 2099 || $sel_month < 1 || $sel_month > 12) {
            $errors[] = 'Please select a valid month and year.';
        } else {
            $period_from  = sprintf('%04d-%02d-01', $sel_year, $sel_month);
            $period_to    = date('Y-m-t', strtotime($period_from));
            $period_label = date('F Y',  strtotime($period_from));
            $working_days = (int)date('t', strtotime($period_from));

            $dup = $db->prepare("SELECT id FROM payroll_batches WHERE {$ff} AND period_from = ?");
            $dup->execute([$period_from]);
            if ($dup->fetch()) {
                $errors[] = "Payroll for {$period_label} already exists. <a href='/modules/payroll/index.php'>View it in the list.</a>";
            }
        }

        if (empty($errors)) {
            $wstmt = $db->prepare(
                "SELECT w.id AS worker_id, w.salary AS basic_salary,
                        u.name AS worker_name, u.email
                 FROM workers w
                 JOIN users u ON u.id = w.user_id
                 WHERE " . farmFilter('u') . "
                   AND w.status = 'active'
                   AND w.hire_date <= ?
                 ORDER BY u.name ASC"
            );
            $wstmt->execute([$period_to]);
            $workers = $wstmt->fetchAll();

            if (empty($workers)) {
                $errors[] = 'No active workers found hired on or before ' . date('d M Y', strtotime($period_to)) . '.';
            } else {
                $step = 2;
            }
        }
    }

    // ── Step 2 → Save: Create payroll batch + records ─────────────────────────
    if ($action === 'create_payroll') {
        $period_from  = sanitize($_POST['period_from']  ?? '');
        $period_to    = sanitize($_POST['period_to']    ?? '');
        $period_label = sanitize($_POST['period_label'] ?? '');
        $working_days = max(1, (int)($_POST['working_days'] ?? 30));

        if (!$period_from || !$period_to || !$period_label ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_from)) {
            flashMessage('error', 'Invalid period data. Please start over.');
            redirect('/modules/payroll/generate.php');
        }

        // Guard against duplicate on form resubmit
        $dup = $db->prepare("SELECT id FROM payroll_batches WHERE {$ff} AND period_from = ?");
        $dup->execute([$period_from]);
        if ($dup->fetch()) {
            flashMessage('error', "Payroll for {$period_label} already exists.");
            redirect('/modules/payroll/index.php');
        }

        $worker_ids    = $_POST['worker_id']     ?? [];
        $basic_arr     = $_POST['basic_salary']  ?? [];
        $present_arr   = $_POST['present_days']  ?? [];
        $overtime_arr  = $_POST['overtime_pay']  ?? [];
        $bonus_arr     = $_POST['bonuses']        ?? [];
        $deduct_arr    = $_POST['deductions']     ?? [];
        $method_arr    = $_POST['payment_method'] ?? [];
        $notes_arr     = $_POST['row_notes']      ?? [];

        if (empty($worker_ids)) {
            flashMessage('error', 'No workers included in payroll.');
            redirect('/modules/payroll/generate.php');
        }

        $records      = [];
        $total_amount = 0.0;

        foreach ($worker_ids as $i => $wid) {
            $wid = (int)$wid;
            // Verify worker belongs to this farm
            $wchk = $db->prepare(
                "SELECT w.id FROM workers w JOIN users u ON u.id = w.user_id WHERE w.id = ? AND " . farmFilter('u')
            );
            $wchk->execute([$wid]);
            if (!$wchk->fetch()) continue;

            $basic   = max(0.0, (float)($basic_arr[$i]    ?? 0));
            $present = max(0,   min($working_days, (int)($present_arr[$i]  ?? $working_days)));
            $overtime= max(0.0, (float)($overtime_arr[$i] ?? 0));
            $bonus   = max(0.0, (float)($bonus_arr[$i]    ?? 0));
            $deduct  = max(0.0, (float)($deduct_arr[$i]   ?? 0));
            $net     = max(0.0, round(($working_days > 0 ? $basic / $working_days : 0) * $present + $overtime + $bonus - $deduct, 2));
            $method  = in_array($method_arr[$i] ?? '', ['cash','bank','bkash','nagad','rocket'], true)
                       ? $method_arr[$i] : 'cash';
            $note    = sanitize($notes_arr[$i] ?? '');

            $records[] = compact('wid','basic','present','overtime','bonus','deduct','net','method','note');
            $total_amount += $net;
        }

        if (empty($records)) {
            flashMessage('error', 'No valid workers could be processed.');
            redirect('/modules/payroll/generate.php');
        }

        try {
            $db->beginTransaction();

            $db->prepare(
                "INSERT INTO payroll_batches
                 (farm_id, period_label, period_from, period_to, total_workers, total_amount, status, created_by)
                 VALUES (?,?,?,?,?,?,'draft',?)"
            )->execute([fid(), $period_label, $period_from, $period_to, count($records), $total_amount, $uid]);
            $batch_id = (int)$db->lastInsertId();

            $ins = $db->prepare(
                "INSERT INTO payroll_records
                 (farm_id, batch_id, worker_id, basic_salary, working_days, present_days,
                  overtime_pay, bonuses, deductions, net_salary, payment_method, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            foreach ($records as $r) {
                $ins->execute([
                    fid(), $batch_id, $r['wid'], $r['basic'], $working_days,
                    $r['present'], $r['overtime'], $r['bonus'], $r['deduct'],
                    $r['net'], $r['method'], $r['note'] ?: null,
                ]);
            }

            $db->commit();
            auditLog($uid, 'CREATE_PAYROLL_BATCH', 'payroll_batches', $batch_id, null, [
                'period' => $period_label, 'workers' => count($records), 'total' => $total_amount,
            ]);
            flashMessage('success',
                "Payroll for {$period_label} created — " . count($records) . " workers, total ৳" . number_format($total_amount, 2) . "."
            );
            redirect('/modules/payroll/view.php?id=' . $batch_id);

        } catch (PDOException $e) {
            $db->rollBack();
            flashMessage('error', 'Database error while creating payroll. Please try again.');
            redirect('/modules/payroll/generate.php');
        }
    }
}

// ── Default values for step 1 ─────────────────────────────────────────────────
$cur_year  = (int)date('Y');
$cur_month = (int)date('n');
$months_map = [
    1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
    7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December',
];

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
$flash = getFlashMessage();
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom:1rem">
    <?= $flash['message'] ?>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h2><?= $step === 2 ? e("Generate Payroll — {$period_label}") : 'Generate Payroll' ?></h2>
        <p class="text-sm text-muted">
            <?= $step === 2 ? "Adjust attendance, overtime and bonuses before saving ({$working_days} working days)" : 'Select the payroll month to load workers' ?>
        </p>
    </div>
    <a href="/modules/payroll/index.php" class="btn btn-secondary">← Back to List</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <?php foreach ($errors as $e): ?><p style="margin:.15rem 0"><?= $e ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($step === 1): ?>
<!-- ════════════════════════════════════════════════════════════
     STEP 1 — Period selection
     ════════════════════════════════════════════════════════════ -->
<div class="card" style="max-width:460px">
    <div class="card-header"><span class="card-title">Select Payroll Period</span></div>
    <div class="card-body">
        <form method="POST" action="/modules/payroll/generate.php" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="load_workers">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem">
                <div class="form-group" style="margin:0">
                    <label class="form-label" for="month">Month <span style="color:var(--danger)">*</span></label>
                    <select id="month" name="month" class="form-control" required>
                        <?php foreach ($months_map as $mn => $ml): ?>
                        <option value="<?= $mn ?>" <?= $mn === $cur_month ? 'selected' : '' ?>><?= $ml ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" for="year">Year <span style="color:var(--danger)">*</span></label>
                    <select id="year" name="year" class="form-control" required>
                        <?php for ($y = $cur_year; $y >= $cur_year - 4; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $cur_year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <span class="form-hint">Only workers hired on or before the last day of this month are included.</span>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:1.25rem">
                Load Workers →
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════
     STEP 2 — Worker payroll table
     ════════════════════════════════════════════════════════════ -->
<form method="POST" action="/modules/payroll/generate.php" id="payrollForm" novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="action"       value="create_payroll">
    <input type="hidden" name="period_from"  value="<?= e($period_from) ?>">
    <input type="hidden" name="period_to"    value="<?= e($period_to) ?>">
    <input type="hidden" name="period_label" value="<?= e($period_label) ?>">
    <input type="hidden" name="working_days" value="<?= (int)$working_days ?>">

    <div class="card" style="margin-bottom:1.25rem">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:.5rem">
            <div>
                <span style="font-weight:700;font-size:.95rem"><?= e($period_label) ?></span>
                <span class="text-muted" style="font-size:.8rem;margin-left:.75rem">
                    <?= $working_days ?> working days &nbsp;·&nbsp; <?= count($workers) ?> workers
                </span>
            </div>
            <div>
                <span style="font-size:.8rem;color:var(--text-secondary)">Grand Total:</span>
                <span id="grandTotal" style="font-size:1.15rem;font-weight:800;color:var(--primary);margin-left:.4rem">৳0</span>
            </div>
        </div>

        <div style="overflow-x:auto">
        <table class="table" style="margin:0;min-width:860px">
            <thead>
                <tr>
                    <th style="min-width:160px">Worker</th>
                    <th>Basic Salary (৳)</th>
                    <th title="Days present out of <?= $working_days ?>">Days Present</th>
                    <th>Overtime (৳)</th>
                    <th>Bonus (৳)</th>
                    <th>Deduction (৳)</th>
                    <th>Net Salary (৳)</th>
                    <th>Pay Via</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($workers as $i => $w): ?>
            <tr class="payroll-row">
                <td>
                    <input type="hidden" name="worker_id[]" value="<?= (int)$w['worker_id'] ?>">
                    <div style="font-weight:600;font-size:.875rem"><?= e($w['worker_name']) ?></div>
                    <div style="font-size:.72rem;color:var(--text-muted)"><?= e($w['email']) ?></div>
                </td>
                <td>
                    <input type="number" name="basic_salary[]"
                           class="form-control pr-input" data-role="basic"
                           value="<?= number_format((float)$w['basic_salary'], 2, '.', '') ?>"
                           step="0.01" min="0" max="9999999" required
                           style="width:105px">
                </td>
                <td>
                    <input type="number" name="present_days[]"
                           class="form-control pr-input" data-role="present"
                           value="<?= (int)$working_days ?>" min="0" max="<?= (int)$working_days ?>" required
                           style="width:65px">
                    <span style="font-size:.72rem;color:var(--text-muted)">/<?= $working_days ?></span>
                </td>
                <td>
                    <input type="number" name="overtime_pay[]"
                           class="form-control pr-input" data-role="overtime"
                           value="0" step="0.01" min="0"
                           style="width:85px">
                </td>
                <td>
                    <input type="number" name="bonuses[]"
                           class="form-control pr-input" data-role="bonus"
                           value="0" step="0.01" min="0"
                           style="width:85px">
                </td>
                <td>
                    <input type="number" name="deductions[]"
                           class="form-control pr-input" data-role="deduct"
                           value="0" step="0.01" min="0"
                           style="width:85px">
                </td>
                <td>
                    <span class="net-display" style="font-weight:700;font-size:.95rem;color:var(--primary);white-space:nowrap">৳<?= number_format((float)$w['basic_salary'], 2) ?></span>
                </td>
                <td>
                    <select name="payment_method[]" class="form-control" style="width:85px">
                        <option value="cash">Cash</option>
                        <option value="bkash">bKash</option>
                        <option value="nagad">Nagad</option>
                        <option value="rocket">Rocket</option>
                        <option value="bank">Bank</option>
                    </select>
                </td>
                <td>
                    <input type="text" name="row_notes[]" class="form-control"
                           placeholder="Optional" maxlength="200"
                           style="width:120px;font-size:.8rem">
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--bg-base)">
                    <td colspan="5" style="text-align:right;padding:.75rem 1rem;font-weight:700;font-size:.85rem;color:var(--text-secondary)">
                        Grand Total (<?= count($workers) ?> workers):
                    </td>
                    <td colspan="4" id="grandTotalFoot" style="padding:.75rem 1rem;font-weight:800;color:var(--primary);font-size:1.05rem">৳0</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Payroll Batch
        </button>
        <a href="/modules/payroll/generate.php" class="btn btn-secondary">← Change Period</a>
        <a href="/modules/payroll/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<script>
(function () {
    'use strict';
    var WD = <?= (int)$working_days ?>;

    function fmtBDT(n) {
        return '৳' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function calcRow(row) {
        var basic   = parseFloat(row.querySelector('[data-role="basic"]').value)   || 0;
        var present = parseFloat(row.querySelector('[data-role="present"]').value) || 0;
        var over    = parseFloat(row.querySelector('[data-role="overtime"]').value) || 0;
        var bonus   = parseFloat(row.querySelector('[data-role="bonus"]').value)   || 0;
        var deduct  = parseFloat(row.querySelector('[data-role="deduct"]').value)  || 0;
        var daily   = WD > 0 ? basic / WD : 0;
        var net     = Math.max(0, daily * present + over + bonus - deduct);
        var disp    = row.querySelector('.net-display');
        if (disp) disp.textContent = fmtBDT(net);
        return net;
    }

    function refreshTotal() {
        var rows  = document.querySelectorAll('.payroll-row');
        var total = 0;
        rows.forEach(function (r) { total += calcRow(r); });
        var fmt = fmtBDT(total);
        var gt  = document.getElementById('grandTotal');
        var gtf = document.getElementById('grandTotalFoot');
        if (gt)  gt.textContent  = fmt;
        if (gtf) gtf.textContent = fmt;
    }

    document.querySelectorAll('.pr-input').forEach(function (inp) {
        inp.addEventListener('input', refreshTotal);
    });

    refreshTotal();
})();
</script>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
