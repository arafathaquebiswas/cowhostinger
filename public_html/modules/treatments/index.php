<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();
requireModule('cows');

$page_title = 'Treatments';
$active_nav = 'treatments';
$db = getDB();

// POST: delete treatment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/treatments/index.php');
    }
    if (!hasRole(['admin', 'veterinarian'])) {
        flashMessage('error', 'Insufficient permissions.');
        redirect('/modules/treatments/index.php');
    }
    $action = $_POST['action'] ?? '';
    $user_id = (int)$_SESSION['user_id'];

    if ($action === 'delete') {
        $t_id = (int)($_POST['treatment_id'] ?? 0);
        if ($t_id > 0) {
            $sel = $db->prepare("SELECT * FROM treatments WHERE id = ?");
            $sel->execute([$t_id]);
            $t = $sel->fetch();
            if ($t) {
                $db->prepare("DELETE FROM treatments WHERE id = ?")->execute([$t_id]);
                auditLog($user_id, 'DELETE_TREATMENT', 'treatments', $t_id, $t, null);
                flashMessage('success', 'Treatment record deleted.');
            }
        }
    }
    redirect('/modules/treatments/index.php?' . http_build_query(array_filter([
        'cow_id'    => $_POST['f_cow_id']    ?? '',
        'med_id'    => $_POST['f_med_id']    ?? '',
        'date_from' => $_POST['f_date_from'] ?? '',
        'date_to'   => $_POST['f_date_to']   ?? '',
    ])));
}

// Filters
$filter_cow  = (int)($_GET['cow_id']    ?? 0);
$filter_med  = (int)($_GET['med_id']    ?? 0);
$date_from   = trim($_GET['date_from']  ?? '');
$date_to     = trim($_GET['date_to']    ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 25;

$where  = ['1=1'];
$params = [];
if ($filter_cow > 0)                            { $where[] = 't.cow_id = ?';       $params[] = $filter_cow; }
if ($filter_med > 0)                            { $where[] = 't.medicine_id = ?';  $params[] = $filter_med; }
if ($date_from !== '' && strtotime($date_from)) { $where[] = 't.treatment_date >= ?'; $params[] = $date_from; }
if ($date_to   !== '' && strtotime($date_to))   { $where[] = 't.treatment_date <= ?'; $params[] = $date_to; }
$where_sql = implode(' AND ', $where);

// KPIs
$kpi = $db->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(cost), 0) AS total_cost,
            SUM(MONTH(treatment_date)=MONTH(CURDATE()) AND YEAR(treatment_date)=YEAR(CURDATE())) AS this_month,
            COALESCE(SUM(CASE WHEN MONTH(treatment_date)=MONTH(CURDATE()) AND YEAR(treatment_date)=YEAR(CURDATE()) THEN cost ELSE 0 END), 0) AS month_cost
     FROM treatments"
)->fetch();

// Count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM treatments t WHERE {$where_sql}");
$count_stmt->execute($params);
$total  = (int)$count_stmt->fetchColumn();
$pager  = paginate($total, $per_page, $page);

// Fetch
$stmt = $db->prepare(
    "SELECT t.id, t.cow_id, t.dosage, t.cost, t.treatment_date, t.notes, t.photo_url,
            c.tag_number, c.breed,
            mi.item_name AS medicine_name,
            u.name AS administered_by
     FROM treatments t
     JOIN cows  c ON c.id = t.cow_id
     LEFT JOIN medicine_inventory mi ON mi.id = t.medicine_id
     JOIN users u ON u.id = t.administered_by
     WHERE {$where_sql}
     ORDER BY t.treatment_date DESC, t.id DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$per_page, $pager['offset']]));
$treatments = $stmt->fetchAll();

// Dropdown lists for filters
$cow_list = $db->query("SELECT id, tag_number, breed FROM cows ORDER BY tag_number ASC")->fetchAll();
$med_list = $db->query("SELECT id, item_name FROM medicine_inventory ORDER BY item_name ASC")->fetchAll();

$qs = static fn(array $p): string =>
    '/modules/treatments/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null && $v !== 0));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Treatments</h2>
        <p class="text-sm text-muted">All veterinary treatment records</p>
    </div>
    <?php if (hasRole(['admin','veterinarian'])): ?>
    <a href="/modules/treatments/form.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Treatment
    </a>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(155px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-label">Total Records</div>
        <div class="kpi-value"><?= number_format((int)$kpi['total']) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">This Month</div>
        <div class="kpi-value"><?= (int)$kpi['this_month'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">This Month Cost</div>
        <div class="kpi-value" style="font-size:1.1rem"><?= e(formatCurrency((float)$kpi['month_cost'])) ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-label">All-Time Cost</div>
        <div class="kpi-value" style="font-size:1.1rem"><?= e(formatCurrency((float)$kpi['total_cost'])) ?></div>
    </div>
</div>

<!-- Filters -->
<form method="GET" action="/modules/treatments/index.php"
      style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem">
    <div class="form-group" style="margin:0;min-width:170px">
        <label class="form-label" style="font-size:.78rem">Cow</label>
        <select name="cow_id" class="form-control">
            <option value="">All Cows</option>
            <?php foreach ($cow_list as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filter_cow===$c['id']?'selected':'' ?>>
                #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0;min-width:170px">
        <label class="form-label" style="font-size:.78rem">Medicine</label>
        <select name="med_id" class="form-control">
            <option value="">All Medicines</option>
            <?php foreach ($med_list as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $filter_med===$m['id']?'selected':'' ?>>
                <?= e($m['item_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">From</label>
        <input type="date" name="date_from" class="form-control" value="<?= e($date_from) ?>">
    </div>
    <div class="form-group" style="margin:0">
        <label class="form-label" style="font-size:.78rem">To</label>
        <input type="date" name="date_to" class="form-control" value="<?= e($date_to) ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($filter_cow || $filter_med || $date_from || $date_to): ?>
    <a href="/modules/treatments/index.php" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
    <span class="text-sm text-muted" style="align-self:center;margin-left:auto">
        <?= number_format($total) ?> record<?= $total!==1?'s':'' ?>
    </span>
</form>

<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($treatments)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>
        </svg>
        <h3>No treatment records found</h3>
        <?php if (hasRole(['admin','veterinarian'])): ?>
        <p><a href="/modules/treatments/form.php">Record the first treatment.</a></p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Cow</th>
                <th>Medicine</th>
                <th>Dosage</th>
                <th>Cost</th>
                <th>Administered By</th>
                <th>Notes</th>
                <th style="width:80px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($treatments as $t): ?>
        <tr>
            <td style="white-space:nowrap"><?= e(formatDate($t['treatment_date'])) ?></td>
            <td>
                <a href="/modules/cows/view.php?id=<?= $t['cow_id'] ?>&tab=treatments" style="font-weight:600">
                    #<?= e($t['tag_number']) ?>
                </a>
                <div class="text-muted" style="font-size:.79rem"><?= e($t['breed']) ?></div>
            </td>
            <td>
                <?php if ($t['medicine_name']): ?>
                <span class="badge badge-blue" style="font-size:.75rem"><?= e($t['medicine_name']) ?></span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td style="font-size:.85rem"><?= $t['dosage'] ? e($t['dosage']) : '—' ?></td>
            <td style="font-weight:600"><?= $t['cost'] ? e(formatCurrency((float)$t['cost'])) : '—' ?></td>
            <td class="text-muted" style="font-size:.85rem"><?= e($t['administered_by']) ?></td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.83rem">
                <?= $t['notes'] ? e($t['notes']) : '—' ?>
            </td>
            <td>
                <div style="display:flex;gap:.35rem">
                    <?php if (hasRole(['admin','veterinarian'])): ?>
                    <a href="/modules/treatments/form.php?id=<?= $t['id'] ?>"
                       class="btn btn-sm btn-secondary" title="Edit">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this treatment record?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"       value="delete">
                        <input type="hidden" name="treatment_id" value="<?= $t['id'] ?>">
                        <input type="hidden" name="f_cow_id"     value="<?= $filter_cow ?>">
                        <input type="hidden" name="f_med_id"     value="<?= $filter_med ?>">
                        <input type="hidden" name="f_date_from"  value="<?= e($date_from) ?>">
                        <input type="hidden" name="f_date_to"    value="<?= e($date_to) ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="<?= e($qs(['cow_id'=>$filter_cow,'med_id'=>$filter_med,'date_from'=>$date_from,'date_to'=>$date_to,'page'=>$pager['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p=max(1,$pager['current_page']-2);$p<=min($pager['total_pages'],$pager['current_page']+2);$p++): ?>
    <a href="<?= e($qs(['cow_id'=>$filter_cow,'med_id'=>$filter_med,'date_from'=>$date_from,'date_to'=>$date_to,'page'=>$p])) ?>"
       class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($qs(['cow_id'=>$filter_cow,'med_id'=>$filter_med,'date_from'=>$date_from,'date_to'=>$date_to,'page'=>$pager['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
