<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin', 'accountant']);
requireModule('reports');

$page_title = 'Reports';
$active_nav = 'reports';
$db = getDB();

// Live record counts
$cow_count = (int)$db->query("SELECT COUNT(*) FROM cows")->fetchColumn();
$milk_count= (int)$db->query("SELECT COUNT(*) FROM milk_records")->fetchColumn();
$fin_count = (int)$db->query("SELECT COUNT(*) FROM finance_transactions")->fetchColumn();
$cs_count  = (int)$db->query("SELECT COUNT(*) FROM cow_sales")->fetchColumn();
$ms_count  = (int)$db->query("SELECT COUNT(*) FROM meat_sales")->fetchColumn();
$br_count  = (int)$db->query("SELECT COUNT(*) FROM breeding_records")->fetchColumn();
$tr_count  = (int)$db->query("SELECT COUNT(*) FROM treatments")->fetchColumn();
$cow_statuses = $db->query("SELECT DISTINCT status FROM cows ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
$worker_count = hasRole(['admin'])
    ? (int)$db->query("SELECT COUNT(*) FROM workers")->fetchColumn()
    : 0;

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Reports</h2>
        <p class="text-sm text-muted">Export data as CSV, Excel (.xlsx), or PDF</p>
    </div>
</div>

<?php
// Helper: render the three download buttons
$btn = static function (string $type, array $extra = []): string {
    $base = '/modules/reports/export.php?type=' . $type;
    foreach ($extra as $k => $v) $base .= "&{$k}={$v}";
    return '<div style="display:flex;gap:.4rem;margin-top:.75rem;flex-wrap:wrap">'
         . '<button type="submit" name="fmt" value="csv"  class="btn btn-sm btn-secondary" style="flex:1;min-width:70px">'
         . '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> CSV</button>'
         . '<button type="submit" name="fmt" value="xlsx" class="btn btn-sm btn-primary" style="flex:1;min-width:70px">'
         . '📊 Excel</button>'
         . '<button type="submit" name="fmt" value="pdf"  class="btn btn-sm btn-danger" style="flex:1;min-width:70px">'
         . '📄 PDF</button>'
         . '</div>';
};
?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.25rem">

    <!-- COWS -->
    <div class="card">
        <div style="padding:.85rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.65rem">
            <span style="width:34px;height:34px;border-radius:var(--radius);background:#EFF6FF;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">🐄</span>
            <div><div style="font-weight:600">Cow Inventory</div>
            <div class="text-muted" style="font-size:.8rem"><?= number_format($cow_count) ?> records</div></div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:.85rem 1.1rem">
            <input type="hidden" name="type" value="cows">
            <div class="form-group">
                <label class="form-label" style="font-size:.8rem">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <?php foreach ($cow_statuses as $s): ?>
                    <option value="<?= e($s) ?>"><?= e(ucfirst(str_replace('_',' ',$s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">Created From</label>
                    <input type="date" name="date_from" class="form-control">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">Created To</label>
                    <input type="date" name="date_to" class="form-control">
                </div>
            </div>
            <?= $btn('cows') ?>
        </form>
    </div>

    <!-- MILK -->
    <div class="card">
        <div style="padding:.85rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.65rem">
            <span style="width:34px;height:34px;border-radius:var(--radius);background:#F0FDF4;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">🥛</span>
            <div><div style="font-weight:600">Milk Production</div>
            <div class="text-muted" style="font-size:.8rem"><?= number_format($milk_count) ?> records</div></div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:.85rem 1.1rem">
            <input type="hidden" name="type" value="milk">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group"><label class="form-label" style="font-size:.78rem">From</label><input type="date" name="date_from" class="form-control"></div>
                <div class="form-group"><label class="form-label" style="font-size:.78rem">To</label><input type="date" name="date_to" class="form-control"></div>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:.78rem">Contamination</label>
                <select name="contamination" class="form-control">
                    <option value="">All</option>
                    <option value="0">Clean Only</option>
                    <option value="1">Contaminated Only</option>
                </select>
            </div>
            <?= $btn('milk') ?>
        </form>
    </div>

    <!-- FINANCE -->
    <div class="card">
        <div style="padding:.85rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.65rem">
            <span style="width:34px;height:34px;border-radius:var(--radius);background:#F5F3FF;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">💰</span>
            <div><div style="font-weight:600">Finance Transactions</div>
            <div class="text-muted" style="font-size:.8rem"><?= number_format($fin_count) ?> records</div></div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:.85rem 1.1rem">
            <input type="hidden" name="type" value="finance">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group"><label class="form-label" style="font-size:.78rem">From</label><input type="date" name="date_from" class="form-control"></div>
                <div class="form-group"><label class="form-label" style="font-size:.78rem">To</label><input type="date" name="date_to" class="form-control"></div>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:.78rem">Type</label>
                <select name="txn_type" class="form-control">
                    <option value="">Income &amp; Expense</option>
                    <option value="income">Income Only</option>
                    <option value="expense">Expense Only</option>
                </select>
            </div>
            <?= $btn('finance') ?>
        </form>
    </div>

    <!-- TREATMENTS -->
    <div class="card">
        <div style="padding:.85rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.65rem">
            <span style="width:34px;height:34px;border-radius:var(--radius);background:#FEF2F2;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">💉</span>
            <div><div style="font-weight:600">Treatments</div>
            <div class="text-muted" style="font-size:.8rem"><?= number_format($tr_count) ?> records</div></div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:.85rem 1.1rem">
            <input type="hidden" name="type" value="treatments">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group"><label class="form-label" style="font-size:.78rem">From</label><input type="date" name="date_from" class="form-control"></div>
                <div class="form-group"><label class="form-label" style="font-size:.78rem">To</label><input type="date" name="date_to" class="form-control"></div>
            </div>
            <?= $btn('treatments') ?>
        </form>
    </div>

    <!-- SALES -->
    <div class="card">
        <div style="padding:.85rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.65rem">
            <span style="width:34px;height:34px;border-radius:var(--radius);background:#FFF7ED;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">🏷️</span>
            <div><div style="font-weight:600">Sales</div>
            <div class="text-muted" style="font-size:.8rem"><?= number_format($cs_count) ?> cow · <?= number_format($ms_count) ?> meat</div></div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:.85rem 1.1rem">
            <input type="hidden" name="type" value="sales">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group"><label class="form-label" style="font-size:.78rem">From</label><input type="date" name="date_from" class="form-control"></div>
                <div class="form-group"><label class="form-label" style="font-size:.78rem">To</label><input type="date" name="date_to" class="form-control"></div>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:.78rem">Sale Type</label>
                <select name="sale_type" class="form-control">
                    <option value="both">Cow &amp; Meat Sales</option>
                    <option value="cow">Cow Sales Only</option>
                    <option value="meat">Meat Sales Only</option>
                </select>
            </div>
            <?= $btn('sales') ?>
        </form>
    </div>

    <!-- BREEDING -->
    <div class="card">
        <div style="padding:.85rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.65rem">
            <span style="width:34px;height:34px;border-radius:var(--radius);background:#FDF4FF;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">🐂</span>
            <div><div style="font-weight:600">Breeding Records</div>
            <div class="text-muted" style="font-size:.8rem"><?= number_format($br_count) ?> records</div></div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:.85rem 1.1rem">
            <input type="hidden" name="type" value="breeding">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group"><label class="form-label" style="font-size:.78rem">From</label><input type="date" name="date_from" class="form-control"></div>
                <div class="form-group"><label class="form-label" style="font-size:.78rem">To</label><input type="date" name="date_to" class="form-control"></div>
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label" style="font-size:.78rem">Status</label>
                <select name="br_status" class="form-control">
                    <option value="">All</option>
                    <option value="heat">Heat</option>
                    <option value="inseminated">Inseminated</option>
                    <option value="pregnant">Pregnant</option>
                    <option value="calved">Calved</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
            <?= $btn('breeding') ?>
        </form>
    </div>

    <!-- WORKERS (admin only) -->
    <?php if (hasRole(['admin'])): ?>
    <div class="card">
        <div style="padding:.85rem 1.1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.65rem">
            <span style="width:34px;height:34px;border-radius:var(--radius);background:#ECFDF5;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0">👷</span>
            <div><div style="font-weight:600">Workers &amp; Tasks</div>
            <div class="text-muted" style="font-size:.8rem"><?= $worker_count ?> workers</div></div>
        </div>
        <form method="GET" action="/modules/reports/export.php" style="padding:.85rem 1.1rem">
            <input type="hidden" name="type" value="workers">
            <div class="form-group" style="margin-bottom:.75rem">
                <label class="form-label" style="font-size:.78rem">Status</label>
                <select name="worker_status" class="form-control">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="terminated">Terminated</option>
                </select>
            </div>
            <?= $btn('workers') ?>
        </form>
    </div>
    <?php endif; ?>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
