<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin', 'accountant']);
requireModule('sales');

$page_title = 'Sales';
$active_nav = 'sales';
$db = getDB();

// Handle POST (delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/sales/index.php');
    }
    if (!hasRole(['admin'])) {
        flashMessage('error', 'Insufficient permissions.');
        redirect('/modules/sales/index.php');
    }

    $action  = $_POST['action']  ?? '';
    $user_id = (int)$_SESSION['user_id'];

    if ($action === 'delete_cow_sale') {
        $sale_id = (int)($_POST['sale_id'] ?? 0);
        if ($sale_id > 0) {
            $sel = $db->prepare("SELECT cs.*, c.tag_number FROM cow_sales cs JOIN cows c ON c.id=cs.cow_id WHERE cs.id=?");
            $sel->execute([$sale_id]);
            $sale = $sel->fetch();
            if ($sale) {
                $db->beginTransaction();
                try {
                    $db->prepare("DELETE FROM cow_sales WHERE id=?")->execute([$sale_id]);
                    $db->prepare("DELETE FROM finance_transactions WHERE related_module='sales' AND reference_id=?")->execute([$sale_id]);
                    $db->prepare("UPDATE cows SET status='ready_for_sale' WHERE id=?")->execute([$sale['cow_id']]);
                    auditLog($user_id, 'DELETE_COW_SALE', 'cow_sales', $sale_id, $sale, null);
                    $db->commit();
                    flashMessage('success', "Cow #{$sale['tag_number']} sale deleted and status restored.");
                } catch (PDOException $e) {
                    $db->rollBack();
                    flashMessage('error', 'Failed to delete sale record.');
                }
            }
        }
    }

    if ($action === 'delete_meat_sale') {
        $sale_id = (int)($_POST['sale_id'] ?? 0);
        if ($sale_id > 0) {
            $sel = $db->prepare("SELECT ms.*, c.tag_number FROM meat_sales ms JOIN cows c ON c.id=ms.cow_id WHERE ms.id=?");
            $sel->execute([$sale_id]);
            $sale = $sel->fetch();
            if ($sale) {
                $db->beginTransaction();
                try {
                    $db->prepare("DELETE FROM meat_sales WHERE id=?")->execute([$sale_id]);
                    $db->prepare("DELETE FROM finance_transactions WHERE related_module='meat_sales' AND reference_id=?")->execute([$sale_id]);
                    auditLog($user_id, 'DELETE_MEAT_SALE', 'meat_sales', $sale_id, $sale, null);
                    $db->commit();
                    flashMessage('success', "Meat sale for Cow #{$sale['tag_number']} deleted.");
                } catch (PDOException $e) {
                    $db->rollBack();
                    flashMessage('error', 'Failed to delete sale record.');
                }
            }
        }
    }

    redirect('/modules/sales/index.php?tab=' . (str_contains($_POST['action'] ?? '', 'meat') ? 'meat' : 'cow'));
}

$active_tab = in_array($_GET['tab'] ?? '', ['cow','meat'], true) ? $_GET['tab'] : 'cow';

// Pagination params
$page_cs = max(1, (int)($_GET['page_cs'] ?? 1));
$page_ms = max(1, (int)($_GET['page_ms'] ?? 1));
$per_page = 25;

// ---- Cow sales ----
$total_cs = (int)$db->query("SELECT COUNT(*) FROM cow_sales")->fetchColumn();
$pager_cs = paginate($total_cs, $per_page, $page_cs);

$stmt = $db->prepare(
    "SELECT cs.id, cs.buyer_name, cs.sale_price, cs.profit_loss, cs.sale_date, cs.notes,
            c.id AS cow_id, c.tag_number, c.breed, c.purchase_price,
            u.name AS approved_by_name
     FROM cow_sales cs
     JOIN cows c ON c.id = cs.cow_id
     LEFT JOIN users u ON u.id = cs.approved_by
     ORDER BY cs.sale_date DESC, cs.id DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([$per_page, $pager_cs['offset']]);
$cow_sales = $stmt->fetchAll();

$cs_totals = $db->query(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(sale_price),0) AS revenue,
            COALESCE(SUM(profit_loss),0) AS profit
     FROM cow_sales"
)->fetch();

// ---- Meat sales ----
$total_ms = (int)$db->query("SELECT COUNT(*) FROM meat_sales")->fetchColumn();
$pager_ms = paginate($total_ms, $per_page, $page_ms);

$stmt2 = $db->prepare(
    "SELECT ms.id, ms.kg_sold, ms.price_per_kg, ms.total_revenue, ms.event_type, ms.sale_date, ms.notes,
            c.id AS cow_id, c.tag_number, c.breed
     FROM meat_sales ms
     JOIN cows c ON c.id = ms.cow_id
     ORDER BY ms.sale_date DESC, ms.id DESC
     LIMIT ? OFFSET ?"
);
$stmt2->execute([$per_page, $pager_ms['offset']]);
$meat_sales = $stmt2->fetchAll();

$ms_totals = $db->query(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(total_revenue),0) AS revenue,
            COALESCE(SUM(kg_sold),0) AS kg
     FROM meat_sales"
)->fetch();

function event_type_badge(string $t): string {
    return match($t) {
        'eid'     => '<span class="badge badge-purple">Eid</span>',
        'gift'    => '<span class="badge badge-blue">Gift</span>',
        'regular' => '<span class="badge badge-gray">Regular</span>',
        default   => '<span class="badge badge-gray">' . e(ucfirst($t)) . '</span>',
    };
}

$qs_cs = static fn(int $p): string =>
    '/modules/sales/index.php?tab=cow&page_cs=' . $p;
$qs_ms = static fn(int $p): string =>
    '/modules/sales/index.php?tab=meat&page_ms=' . $p;

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Sales</h2>
        <p class="text-sm text-muted">Cow sales &amp; meat sales records</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/sales/cow_sale_form.php"  class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Cow Sale
        </a>
        <a href="/modules/sales/meat_sale_form.php" class="btn btn-secondary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Meat Sale
        </a>
    </div>
</div>

<!-- Summary KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(155px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-label">Cows Sold</div>
        <div class="kpi-value"><?= (int)$cs_totals['cnt'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Cow Revenue</div>
        <div class="kpi-value" style="font-size:1.2rem"><?= e(formatCurrency((float)$cs_totals['revenue'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= (float)$cs_totals['profit'] >= 0 ? '#16A34A' : '#DC2626' ?>;--kpi-soft:<?= (float)$cs_totals['profit'] >= 0 ? '#F0FDF4' : '#FEF2F2' ?>">
        <div class="kpi-label">Total P&amp;L</div>
        <div class="kpi-value" style="font-size:1.2rem;color:<?= (float)$cs_totals['profit'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
            <?= ((float)$cs_totals['profit'] >= 0 ? '+' : '') . e(formatCurrency(abs((float)$cs_totals['profit']))) ?>
        </div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-label">Meat Sales</div>
        <div class="kpi-value"><?= (int)$ms_totals['cnt'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Meat Revenue</div>
        <div class="kpi-value" style="font-size:1.2rem"><?= e(formatCurrency((float)$ms_totals['revenue'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-label">Meat Sold (kg)</div>
        <div class="kpi-value"><?= number_format((float)$ms_totals['kg'], 1) ?> kg</div>
    </div>
</div>

<!-- Tabs -->
<nav class="tab-nav" style="margin-bottom:1.25rem">
    <button class="tab-btn <?= $active_tab === 'cow'  ? 'active' : '' ?>" data-tab="tab_cow">
        Cow Sales <span class="badge badge-blue" style="margin-left:.3rem"><?= (int)$cs_totals['cnt'] ?></span>
    </button>
    <button class="tab-btn <?= $active_tab === 'meat' ? 'active' : '' ?>" data-tab="tab_meat">
        Meat Sales <span class="badge badge-purple" style="margin-left:.3rem"><?= (int)$ms_totals['cnt'] ?></span>
    </button>
</nav>

<!-- Cow Sales Tab -->
<div id="tab_cow" class="tab-panel <?= $active_tab === 'cow' ? 'active' : '' ?>">
    <div class="card" style="margin-bottom:1rem">
        <?php if (empty($cow_sales)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>
            </svg>
            <h3>No cow sales recorded</h3>
            <p><a href="/modules/sales/cow_sale_form.php">Record a cow sale.</a></p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Cow</th>
                    <th>Buyer</th>
                    <th>Sale Price</th>
                    <th>Purchase Price</th>
                    <th>Profit / Loss</th>
                    <th>Notes</th>
                    <?php if (hasRole(['admin'])): ?><th style="width:60px">Del</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cow_sales as $cs): ?>
            <tr>
                <td style="white-space:nowrap"><?= e(formatDate($cs['sale_date'])) ?></td>
                <td>
                    <a href="/modules/cows/view.php?id=<?= $cs['cow_id'] ?>" style="font-weight:600">
                        #<?= e($cs['tag_number']) ?>
                    </a>
                    <div class="text-muted" style="font-size:.79rem"><?= e($cs['breed']) ?></div>
                </td>
                <td><?= e($cs['buyer_name']) ?></td>
                <td style="font-weight:700"><?= e(formatCurrency((float)$cs['sale_price'])) ?></td>
                <td class="text-muted"><?= $cs['purchase_price'] ? e(formatCurrency((float)$cs['purchase_price'])) : '—' ?></td>
                <td style="font-weight:700;color:<?= (float)$cs['profit_loss'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                    <?= ((float)$cs['profit_loss'] >= 0 ? '+' : '') . e(formatCurrency(abs((float)$cs['profit_loss']))) ?>
                </td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem">
                    <?= $cs['notes'] ? e($cs['notes']) : '—' ?>
                </td>
                <?php if (hasRole(['admin'])): ?>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this sale record? The cow status will be restored to Ready for Sale.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="delete_cow_sale">
                        <input type="hidden" name="sale_id" value="<?= $cs['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                        </button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pager_cs['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pager_cs['has_prev']): ?>
        <a href="<?= e($qs_cs($pager_cs['current_page']-1)) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p=max(1,$pager_cs['current_page']-2);$p<=min($pager_cs['total_pages'],$pager_cs['current_page']+2);$p++): ?>
        <a href="<?= e($qs_cs($p)) ?>" class="page-btn <?= $p===$pager_cs['current_page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager_cs['has_next']): ?>
        <a href="<?= e($qs_cs($pager_cs['current_page']+1)) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Meat Sales Tab -->
<div id="tab_meat" class="tab-panel <?= $active_tab === 'meat' ? 'active' : '' ?>">
    <div class="card" style="margin-bottom:1rem">
        <?php if (empty($meat_sales)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>
            </svg>
            <h3>No meat sales recorded</h3>
            <p><a href="/modules/sales/meat_sale_form.php">Record a meat sale.</a></p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Cow</th>
                    <th>Event</th>
                    <th>Kg Sold</th>
                    <th>Price / Kg</th>
                    <th>Revenue</th>
                    <th>Notes</th>
                    <?php if (hasRole(['admin'])): ?><th style="width:60px">Del</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($meat_sales as $ms): ?>
            <tr>
                <td style="white-space:nowrap"><?= e(formatDate($ms['sale_date'])) ?></td>
                <td>
                    <a href="/modules/cows/view.php?id=<?= $ms['cow_id'] ?>" style="font-weight:600">
                        #<?= e($ms['tag_number']) ?>
                    </a>
                    <div class="text-muted" style="font-size:.79rem"><?= e($ms['breed']) ?></div>
                </td>
                <td><?= event_type_badge($ms['event_type']) ?></td>
                <td><?= number_format((float)$ms['kg_sold'], 2) ?> kg</td>
                <td class="text-muted"><?= e(formatCurrency((float)$ms['price_per_kg'])) ?>/kg</td>
                <td style="font-weight:700;color:var(--success)"><?= e(formatCurrency((float)$ms['total_revenue'])) ?></td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem">
                    <?= $ms['notes'] ? e($ms['notes']) : '—' ?>
                </td>
                <?php if (hasRole(['admin'])): ?>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this meat sale record?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="delete_meat_sale">
                        <input type="hidden" name="sale_id" value="<?= $ms['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                        </button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($pager_ms['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pager_ms['has_prev']): ?>
        <a href="<?= e($qs_ms($pager_ms['current_page']-1)) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p=max(1,$pager_ms['current_page']-2);$p<=min($pager_ms['total_pages'],$pager_ms['current_page']+2);$p++): ?>
        <a href="<?= e($qs_ms($p)) ?>" class="page-btn <?= $p===$pager_ms['current_page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager_ms['has_next']): ?>
        <a href="<?= e($qs_ms($pager_ms['current_page']+1)) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$init_tab = e($active_tab === 'meat' ? 'tab_meat' : 'tab_cow');
$inline_js = <<<JSEOF
(function() {
    var panels = document.querySelectorAll('.tab-panel');
    var btns   = document.querySelectorAll('.tab-btn');
    btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = btn.getAttribute('data-tab');
            panels.forEach(function(p) { p.classList.remove('active'); });
            btns.forEach(function(b)   { b.classList.remove('active'); });
            document.getElementById(target).classList.add('active');
            btn.classList.add('active');
        });
    });
    // Restore active tab from URL
    var initTab = document.getElementById('{$init_tab}');
    if (initTab) {
        panels.forEach(function(p) { p.classList.remove('active'); });
        btns.forEach(function(b)   { b.classList.remove('active'); });
        initTab.classList.add('active');
        document.querySelector('[data-tab="{$init_tab}"]').classList.add('active');
    }
})();
JSEOF;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
