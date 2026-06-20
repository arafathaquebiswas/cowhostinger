<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();
requireModule('breeding');

$page_title = 'Breeding';
$active_nav = 'breeding';
$db = getDB();

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/breeding/index.php');
    }
    $action  = $_POST['action']  ?? '';
    $user_id = (int)$_SESSION['user_id'];

    if ($action === 'delete_breeding' && hasRole(['admin'])) {
        $br_id = (int)($_POST['br_id'] ?? 0);
        if ($br_id > 0) {
            $sel = $db->prepare("SELECT * FROM breeding_records WHERE id = ?");
            $sel->execute([$br_id]);
            $br = $sel->fetch();
            if ($br) {
                $db->prepare("DELETE FROM breeding_records WHERE id = ?")->execute([$br_id]);
                auditLog($user_id, 'DELETE_BREEDING_RECORD', 'breeding_records', $br_id, $br, null);
                flashMessage('success', 'Breeding record deleted.');
            }
        }
    }

    redirect('/modules/breeding/index.php?' . http_build_query(array_filter([
        'status' => $_POST['f_status'] ?? '',
        'cow_id' => $_POST['f_cow_id'] ?? '',
    ])));
}

// Filters
$valid_statuses = ['heat', 'inseminated', 'pregnant', 'calved', 'failed'];
$filter_status  = in_array($_GET['status'] ?? '', $valid_statuses, true) ? $_GET['status'] : '';
$filter_cow     = (int)($_GET['cow_id'] ?? 0);
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 20;

$where  = ['1=1'];
$params = [];
if ($filter_status !== '') { $where[] = 'br.status = ?'; $params[] = $filter_status; }
if ($filter_cow > 0)       { $where[] = 'br.cow_id = ?'; $params[] = $filter_cow; }
$where_sql = implode(' AND ', $where);

// KPIs (always all records)
$kpi = $db->query(
    "SELECT
       SUM(status='pregnant')                                                          AS pregnant_cnt,
       SUM(status IN ('heat','inseminated'))                                           AS active_cnt,
       SUM(status='calved' AND YEAR(COALESCE(actual_calving_date,created_at))=YEAR(CURDATE())) AS calved_year,
       SUM(status='failed')                                                            AS failed_cnt,
       SUM(status='pregnant' AND expected_calving_date IS NOT NULL
           AND DATEDIFF(expected_calving_date, CURDATE()) BETWEEN 0 AND 14)           AS calving_soon
     FROM breeding_records"
)->fetch();

// Status counts for filter pills
$sc_rows = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM breeding_records GROUP BY status"
)->fetchAll();
$status_counts = [];
foreach ($sc_rows as $r) $status_counts[$r['status']] = (int)$r['cnt'];
$total_all = (int)array_sum($status_counts);

// Paginated list
$count_stmt = $db->prepare("SELECT COUNT(*) FROM breeding_records br WHERE {$where_sql}");
$count_stmt->execute($params);
$total  = (int)$count_stmt->fetchColumn();
$pager  = paginate($total, $per_page, $page);

$stmt = $db->prepare(
    "SELECT br.id, br.cow_id, br.heat_cycle_date, br.insemination_date, br.breeding_date,
            br.expected_calving_date, br.actual_calving_date, br.status, br.notes, br.created_at,
            DATEDIFF(br.expected_calving_date, CURDATE()) AS days_until_calving,
            c.tag_number, c.breed, c.is_pregnant,
            u.name AS recorded_by,
            (SELECT COUNT(*) FROM calf_records cr WHERE cr.breeding_record_id = br.id) AS calf_count
     FROM breeding_records br
     JOIN cows  c ON c.id = br.cow_id
     JOIN users u ON u.id = br.recorded_by
     WHERE {$where_sql}
     ORDER BY FIELD(br.status,'pregnant','inseminated','heat','calved','failed'),
              br.expected_calving_date ASC, br.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$per_page, $pager['offset']]));
$records = $stmt->fetchAll();

$cow_list = $db->query("SELECT id, tag_number, breed FROM cows ORDER BY tag_number ASC")->fetchAll();

function breeding_status_badge(string $s): string {
    return match($s) {
        'heat'        => '<span class="badge badge-orange">Heat</span>',
        'inseminated' => '<span class="badge badge-blue">Inseminated</span>',
        'pregnant'    => '<span class="badge badge-purple">Pregnant</span>',
        'calved'      => '<span class="badge badge-green">Calved</span>',
        'failed'      => '<span class="badge badge-red">Failed</span>',
        default       => '<span class="badge badge-gray">' . e(ucfirst($s)) . '</span>',
    };
}

function calving_countdown(mixed $days): string {
    if ($days === null) return '—';
    $days = (int)$days;
    if ($days < 0)  return '<span class="badge badge-red">Overdue ' . abs($days) . 'd</span>';
    if ($days === 0) return '<span class="badge badge-red">Today!</span>';
    if ($days <= 7)  return '<span class="badge badge-red">' . $days . ' days</span>';
    if ($days <= 14) return '<span class="badge badge-yellow">' . $days . ' days</span>';
    return '<span class="text-muted" style="font-size:.85rem">' . $days . ' days</span>';
}

$qs = static fn(array $p): string =>
    '/modules/breeding/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null && $v !== 0));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Breeding</h2>
        <p class="text-sm text-muted">Heat cycles, insemination, pregnancy &amp; calving</p>
    </div>
    <?php if (hasRole(['admin','veterinarian','reception'])): ?>
    <a href="/modules/breeding/form.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Record
    </a>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(145px,1fr));margin-bottom:1.25rem">
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-label">Pregnant</div>
        <div class="kpi-value" style="color:#7C3AED"><?= (int)$kpi['pregnant_cnt'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">Calving Soon (≤14d)</div>
        <div class="kpi-value" style="color:var(--danger)"><?= (int)$kpi['calving_soon'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-label">Active Cycles</div>
        <div class="kpi-value"><?= (int)$kpi['active_cnt'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Calved This Year</div>
        <div class="kpi-value"><?= (int)$kpi['calved_year'] ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#6B7280;--kpi-soft:#F9FAFB">
        <div class="kpi-label">Failed</div>
        <div class="kpi-value" style="color:var(--text-muted)"><?= (int)$kpi['failed_cnt'] ?></div>
    </div>
</div>

<!-- Quick filter pills -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem;align-items:center">
    <a href="<?= e($qs(['cow_id'=>$filter_cow])) ?>"
       class="btn btn-sm <?= $filter_status===''?'btn-primary':'btn-secondary' ?>">
       All <span style="opacity:.7">(<?= $total_all ?>)</span>
    </a>
    <?php foreach (['heat'=>'Heat','inseminated'=>'Inseminated','pregnant'=>'Pregnant','calved'=>'Calved','failed'=>'Failed'] as $sv=>$sl): ?>
    <a href="<?= e($qs(['status'=>$sv,'cow_id'=>$filter_cow])) ?>"
       class="btn btn-sm <?= $filter_status===$sv?'btn-primary':'btn-secondary' ?>">
       <?= $sl ?> <span style="opacity:.7">(<?= $status_counts[$sv]??0 ?>)</span>
    </a>
    <?php endforeach; ?>

    <!-- Cow filter -->
    <form method="GET" style="margin-left:auto;display:flex;gap:.5rem;align-items:center">
        <?php if ($filter_status): ?>
        <input type="hidden" name="status" value="<?= e($filter_status) ?>">
        <?php endif; ?>
        <select name="cow_id" class="form-control" style="min-width:180px" onchange="this.form.submit()">
            <option value="">All Cows</option>
            <?php foreach ($cow_list as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filter_cow===$c['id']?'selected':'' ?>>
                #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php if ($filter_cow): ?>
        <a href="<?= e($qs(['status'=>$filter_status])) ?>" class="btn btn-sm btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($records)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <h3>No breeding records found</h3>
        <?php if (hasRole(['admin','veterinarian','reception'])): ?>
        <p><a href="/modules/breeding/form.php">Add the first breeding record.</a></p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Cow</th>
                <th>Status</th>
                <th>Heat Date</th>
                <th>Insemination</th>
                <th>Breeding</th>
                <th>Expected Calving</th>
                <th>Countdown</th>
                <th>Actual Calving</th>
                <th>Calves</th>
                <th style="width:110px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $br): ?>
        <tr>
            <td>
                <a href="/modules/cows/view.php?id=<?= $br['cow_id'] ?>&tab=breeding" style="font-weight:600">
                    #<?= e($br['tag_number']) ?>
                </a>
                <div class="text-muted" style="font-size:.79rem"><?= e($br['breed']) ?></div>
            </td>
            <td><?= breeding_status_badge($br['status']) ?></td>
            <td style="white-space:nowrap;font-size:.84rem"><?= $br['heat_cycle_date']   ? e(formatDate($br['heat_cycle_date']))   : '—' ?></td>
            <td style="white-space:nowrap;font-size:.84rem"><?= $br['insemination_date'] ? e(formatDate($br['insemination_date'])) : '—' ?></td>
            <td style="white-space:nowrap;font-size:.84rem"><?= $br['breeding_date']     ? e(formatDate($br['breeding_date']))     : '—' ?></td>
            <td style="white-space:nowrap;font-size:.84rem"><?= $br['expected_calving_date'] ? e(formatDate($br['expected_calving_date'])) : '—' ?></td>
            <td>
                <?php if ($br['status'] === 'pregnant' && $br['expected_calving_date']): ?>
                <?= calving_countdown($br['days_until_calving']) ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td style="white-space:nowrap;font-size:.84rem"><?= $br['actual_calving_date'] ? e(formatDate($br['actual_calving_date'])) : '—' ?></td>
            <td style="text-align:center">
                <?php if ($br['calf_count'] > 0): ?>
                <span class="badge badge-green"><?= $br['calf_count'] ?></span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <?php if (hasRole(['admin','veterinarian','reception'])): ?>
                    <a href="/modules/breeding/form.php?id=<?= $br['id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if (hasRole(['admin','veterinarian']) && in_array($br['status'], ['pregnant','inseminated','heat'], true)): ?>
                    <a href="/modules/breeding/calf_form.php?br_id=<?= $br['id'] ?>"
                       class="btn btn-sm btn-success" title="Record Calving" style="font-size:.72rem;padding:.2rem .45rem">
                        Calving
                    </a>
                    <?php endif; ?>
                    <?php if (hasRole(['admin'])): ?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this breeding record and all linked calf records?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"   value="delete_breeding">
                        <input type="hidden" name="br_id"    value="<?= $br['id'] ?>">
                        <input type="hidden" name="f_status" value="<?= e($filter_status) ?>">
                        <input type="hidden" name="f_cow_id" value="<?= $filter_cow ?>">
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
    <a href="<?= e($qs(['status'=>$filter_status,'cow_id'=>$filter_cow,'page'=>$pager['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p=max(1,$pager['current_page']-2);$p<=min($pager['total_pages'],$pager['current_page']+2);$p++): ?>
    <a href="<?= e($qs(['status'=>$filter_status,'cow_id'=>$filter_cow,'page'=>$p])) ?>"
       class="page-btn <?= $p===$pager['current_page']?'active':'' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($qs(['status'=>$filter_status,'cow_id'=>$filter_cow,'page'=>$pager['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
