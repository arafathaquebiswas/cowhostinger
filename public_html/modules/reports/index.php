<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin', 'accountant']);
requireModule('reports');

$page_title = 'Reports';
$active_nav = 'reports';

$db = getDB();

// Quick stats for each report card
$cow_count  = (int)$db->query("SELECT COUNT(*) FROM cows")->fetchColumn();
$milk_count = (int)$db->query("SELECT COUNT(*) FROM milk_records")->fetchColumn();
$fin_count  = (int)$db->query("SELECT COUNT(*) FROM finance_transactions")->fetchColumn();
$cs_count   = (int)$db->query("SELECT COUNT(*) FROM cow_sales")->fetchColumn();
$ms_count   = (int)$db->query("SELECT COUNT(*) FROM meat_sales")->fetchColumn();

$cow_statuses = $db->query("SELECT DISTINCT status FROM cows ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Reports</h2>
        <p class="text-sm text-muted">Export data to CSV for analysis and record-keeping</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.25rem">

    <!-- ======== COWS REPORT ======== -->
    <div class="card">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem">
            <span style="width:36px;height:36px;border-radius:var(--radius);background:#EFF6FF;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">🐄</span>
            <div>
                <div style="font-weight:600">Cow Inventory</div>
                <div class="text-muted" style="font-size:.8rem"><?= number_format($cow_count) ?> records</div>
            </div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:1rem 1.25rem">
            <input type="hidden" name="type" value="cows">
            <div class="form-group">
                <label class="form-label" style="font-size:.8rem">Status Filter</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <?php foreach ($cow_statuses as $s): ?>
                    <option value="<?= e($s) ?>"><?= e(ucfirst(str_replace('_',' ',$s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.8rem">Created From</label>
                    <input type="date" name="date_from" class="form-control">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.8rem">Created To</label>
                    <input type="date" name="date_to" class="form-control">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.85rem;width:100%">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download CSV
            </button>
        </form>
    </div>

    <!-- ======== MILK REPORT ======== -->
    <div class="card">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem">
            <span style="width:36px;height:36px;border-radius:var(--radius);background:#F0FDF4;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">🥛</span>
            <div>
                <div style="font-weight:600">Milk Production</div>
                <div class="text-muted" style="font-size:.8rem"><?= number_format($milk_count) ?> records</div>
            </div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:1rem 1.25rem">
            <input type="hidden" name="type" value="milk">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Date From</label>
                    <input type="date" name="date_from" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Date To</label>
                    <input type="date" name="date_to" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" style="font-size:.8rem">Contamination</label>
                <select name="contamination" class="form-control">
                    <option value="">All Records</option>
                    <option value="0">Clean Only</option>
                    <option value="1">Contaminated Only</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.25rem;width:100%">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download CSV
            </button>
        </form>
    </div>

    <!-- ======== FINANCE REPORT ======== -->
    <div class="card">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem">
            <span style="width:36px;height:36px;border-radius:var(--radius);background:#F5F3FF;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">💰</span>
            <div>
                <div style="font-weight:600">Finance Transactions</div>
                <div class="text-muted" style="font-size:.8rem"><?= number_format($fin_count) ?> records</div>
            </div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:1rem 1.25rem">
            <input type="hidden" name="type" value="finance">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Date From</label>
                    <input type="date" name="date_from" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Date To</label>
                    <input type="date" name="date_to" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" style="font-size:.8rem">Type</label>
                <select name="txn_type" class="form-control">
                    <option value="">Income &amp; Expense</option>
                    <option value="income">Income Only</option>
                    <option value="expense">Expense Only</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.25rem;width:100%">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download CSV
            </button>
        </form>
    </div>

    <!-- ======== SALES REPORT ======== -->
    <div class="card">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem">
            <span style="width:36px;height:36px;border-radius:var(--radius);background:#FFF7ED;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">🏷️</span>
            <div>
                <div style="font-weight:600">Sales</div>
                <div class="text-muted" style="font-size:.8rem">
                    <?= number_format($cs_count) ?> cow · <?= number_format($ms_count) ?> meat
                </div>
            </div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:1rem 1.25rem">
            <input type="hidden" name="type" value="sales">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Date From</label>
                    <input type="date" name="date_from" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Date To</label>
                    <input type="date" name="date_to" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" style="font-size:.8rem">Sale Type</label>
                <select name="sale_type" class="form-control">
                    <option value="both">Cow Sales &amp; Meat Sales</option>
                    <option value="cow">Cow Sales Only</option>
                    <option value="meat">Meat Sales Only</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.25rem;width:100%">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download CSV
            </button>
        </form>
    </div>

    <!-- ======== BREEDING REPORT ======== -->
    <div class="card">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem">
            <span style="width:36px;height:36px;border-radius:var(--radius);background:#FDF4FF;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">🐂</span>
            <div>
                <div style="font-weight:600">Breeding Records</div>
                <?php $br_count = (int)$db->query("SELECT COUNT(*) FROM breeding_records")->fetchColumn(); ?>
                <div class="text-muted" style="font-size:.8rem"><?= number_format($br_count) ?> records</div>
            </div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:1rem 1.25rem">
            <input type="hidden" name="type" value="breeding">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Date From</label>
                    <input type="date" name="date_from" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-size:.8rem">Date To</label>
                    <input type="date" name="date_to" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" style="font-size:.8rem">Status</label>
                <select name="br_status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="heat">Heat</option>
                    <option value="inseminated">Inseminated</option>
                    <option value="pregnant">Pregnant</option>
                    <option value="calved">Calved</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.25rem;width:100%">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download CSV
            </button>
        </form>
    </div>

    <!-- ======== WORKERS REPORT ======== -->
    <?php if (hasRole(['admin'])): ?>
    <div class="card">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem">
            <span style="width:36px;height:36px;border-radius:var(--radius);background:#ECFDF5;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">👷</span>
            <div>
                <div style="font-weight:600">Workers &amp; Tasks</div>
                <?php $w_count = (int)$db->query("SELECT COUNT(*) FROM workers")->fetchColumn(); ?>
                <div class="text-muted" style="font-size:.8rem"><?= number_format($w_count) ?> workers</div>
            </div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:1rem 1.25rem">
            <input type="hidden" name="type" value="workers">
            <div class="form-group">
                <label class="form-label" style="font-size:.8rem">Worker Status</label>
                <select name="worker_status" class="form-control">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="terminated">Terminated</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.85rem;width:100%">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download CSV
            </button>
        </form>
    </div>
    <?php endif; ?>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
