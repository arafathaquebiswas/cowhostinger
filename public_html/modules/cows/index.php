<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireAuth();
requireModule('cows');

$page_title = 'Cow Management';
$active_nav = 'cows';

$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request. Please try again.');
        redirect('/modules/cows/index.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && hasRole(['admin'])) {
        $del_id = (int)($_POST['cow_id'] ?? 0);
        if ($del_id > 0) {
            $sel = $db->prepare("SELECT id, tag_number FROM cows WHERE id = ?");
            $sel->execute([$del_id]);
            $del_cow = $sel->fetch();
            if ($del_cow) {
                try {
                    $db->prepare("DELETE FROM cows WHERE id = ?")->execute([$del_id]);
                    auditLog((int)$_SESSION['user_id'], 'DELETE_COW', 'cows', $del_id, $del_cow, null);
                    flashMessage('success', "Cow #{$del_cow['tag_number']} deleted permanently.");
                } catch (PDOException $e) {
                    flashMessage('error', "Cannot delete cow #{$del_cow['tag_number']} — it has sales or financial records attached.");
                }
            }
        }
    }

    redirect('/modules/cows/index.php');
}

// Filters
$search   = trim($_GET['search'] ?? '');
$status   = $_GET['status'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$valid_statuses = ['active','pregnant','lactating','dry','sick','quarantine','ready_for_sale','sold','deceased'];
if (!in_array($status, $valid_statuses, true)) {
    $status = '';
}

// Build WHERE clause
$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[]  = '(tag_number LIKE ? OR breed LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($status !== '') {
    $where[]  = 'status = ?';
    $params[] = $status;
}
$where_sql = implode(' AND ', $where);

// Count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM cows WHERE {$where_sql}");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();
$pager  = paginate($total, $per_page, $page);

// Fetch page of cows
$fetch_params = array_merge($params, [$per_page, $pager['offset']]);
$stmt = $db->prepare(
    "SELECT id, tag_number, breed, status, current_weight, health_status,
            is_pregnant, birth_date, photo_url
     FROM cows
     WHERE {$where_sql}
     ORDER BY created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute($fetch_params);
$cows = $stmt->fetchAll();

// Status counts for quick-filter pills
$sc_rows    = $db->query("SELECT status, COUNT(*) AS cnt FROM cows GROUP BY status")->fetchAll();
$status_counts = [];
foreach ($sc_rows as $r) {
    $status_counts[$r['status']] = (int)$r['cnt'];
}
$total_all = (int)$db->query("SELECT COUNT(*) FROM cows")->fetchColumn();

// Helpers
function cow_status_badge(string $s): string {
    return match($s) {
        'active'         => 'badge-green',
        'pregnant'       => 'badge-purple',
        'lactating'      => 'badge-blue',
        'dry'            => 'badge-orange',
        'sick'           => 'badge-red',
        'quarantine'     => 'badge-red',
        'ready_for_sale' => 'badge-yellow',
        'sold'           => 'badge-gray',
        'deceased'       => 'badge-gray',
        default          => 'badge-gray',
    };
}

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

$qs = static fn(array $p): string =>
    '/modules/cows/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null));
?>

<div class="page-header">
    <div>
        <h2>Cow Management</h2>
        <p class="text-sm text-muted"><?= number_format($total_all) ?> cow<?= $total_all !== 1 ? 's' : '' ?> on farm</p>
    </div>
    <?php if (hasRole(['admin'])): ?>
    <a href="/modules/cows/form.php" class="btn btn-primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Cow
    </a>
    <?php endif; ?>
</div>

<!-- Quick filter pills -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
    <?php
    $quick = [
        ''               => ['All',           $total_all],
        'active'         => ['Active',        $status_counts['active']         ?? 0],
        'sick'           => ['Sick',          $status_counts['sick']           ?? 0],
        'quarantine'     => ['Quarantine',    $status_counts['quarantine']     ?? 0],
        'pregnant'       => ['Pregnant',      $status_counts['pregnant']       ?? 0],
        'lactating'      => ['Lactating',     $status_counts['lactating']      ?? 0],
        'dry'            => ['Dry',           $status_counts['dry']            ?? 0],
        'ready_for_sale' => ['Ready for Sale',$status_counts['ready_for_sale'] ?? 0],
        'sold'           => ['Sold',          $status_counts['sold']           ?? 0],
    ];
    foreach ($quick as $sval => [$slabel, $scnt]):
        $is_active_pill = $status === $sval;
    ?>
    <a href="<?= e($qs(['status' => $sval, 'search' => $search])) ?>"
       class="btn btn-sm <?= $is_active_pill ? 'btn-primary' : 'btn-secondary' ?>">
        <?= e($slabel) ?>&nbsp;<span style="opacity:.7"><?= $scnt ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Search bar -->
<form method="GET" action="/modules/cows/index.php" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input type="text" name="search" class="form-control" placeholder="Search tag # or breed…"
           value="<?= e($search) ?>" style="max-width:280px">
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <?php if ($search !== ''): ?>
    <a href="<?= e($qs(['status' => $status])) ?>" class="btn btn-secondary btn-sm">Clear</a>
    <?php endif; ?>
    <span class="text-sm text-muted" style="margin-left:auto;align-self:center">
        <?= number_format($total) ?> result<?= $total !== 1 ? 's' : '' ?>
    </span>
</form>

<div class="card" style="margin-bottom:1.5rem">
    <?php if (empty($cows)): ?>
    <div class="empty-state">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4.03 3-9 3S3 13.66 3 12"/><path d="M3 5v14c0 1.66 4.03 3 9 3s9-1.34 9-3V5"/>
        </svg>
        <h3>No cows found</h3>
        <p>
            <?php if ($search !== '' || $status !== ''): ?>
            No cows match the current filters.
            <a href="/modules/cows/index.php">Clear filters</a>
            <?php elseif (hasRole(['admin'])): ?>
            No cows recorded yet. <a href="/modules/cows/form.php">Add the first cow.</a>
            <?php else: ?>
            No cows have been added to the system yet.
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>Tag #</th>
                <th>Breed</th>
                <th>Status</th>
                <th>Weight (kg)</th>
                <th>Health</th>
                <th>Pregnant</th>
                <th>Age</th>
                <th style="width:120px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cows as $cow):
            $age_str = '—';
            if ($cow['birth_date']) {
                $diff    = (new DateTime($cow['birth_date']))->diff(new DateTime());
                $age_str = $diff->y > 0
                    ? "{$diff->y}y {$diff->m}m"
                    : ($diff->m > 0 ? "{$diff->m} mo" : "{$diff->d}d");
            }
        ?>
        <tr>
            <td>
                <div style="display:flex;align-items:center;gap:.6rem">
                    <?php if ($cow['photo_url']): ?>
                    <img src="<?= e($cow['photo_url']) ?>" alt="Cow photo"
                         style="width:32px;height:32px;object-fit:cover;border-radius:var(--radius);border:1px solid var(--border);flex-shrink:0">
                    <?php else: ?>
                    <span style="width:32px;height:32px;border-radius:var(--radius);background:var(--primary-soft);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">🐄</span>
                    <?php endif; ?>
                    <a href="/modules/cows/view.php?id=<?= $cow['id'] ?>"
                       style="font-weight:600;color:var(--primary);white-space:nowrap">
                        #<?= e($cow['tag_number']) ?>
                    </a>
                </div>
            </td>
            <td><?= e($cow['breed']) ?></td>
            <td>
                <span class="badge <?= cow_status_badge($cow['status']) ?>">
                    <?= e(str_replace('_', ' ', ucfirst($cow['status']))) ?>
                </span>
            </td>
            <td><?= $cow['current_weight'] !== null ? e(number_format((float)$cow['current_weight'], 1)) : '—' ?></td>
            <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                title="<?= e($cow['health_status']) ?>"><?= e($cow['health_status']) ?></td>
            <td><?= $cow['is_pregnant'] ? '<span class="badge badge-purple">Yes</span>' : '<span class="text-muted">—</span>' ?></td>
            <td><?= e($age_str) ?></td>
            <td>
                <div style="display:flex;gap:.35rem">
                    <a href="/modules/cows/view.php?id=<?= $cow['id'] ?>"
                       class="btn btn-sm btn-secondary" title="View details">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                    <?php if (hasRole(['admin', 'veterinarian'])): ?>
                    <a href="/modules/cows/form.php?id=<?= $cow['id'] ?>"
                       class="btn btn-sm btn-secondary" title="Edit">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if (hasRole(['admin'])): ?>
                    <form method="POST" style="display:inline"
                          onsubmit="return confirm('Permanently delete cow #<?= e(addslashes($cow['tag_number'])) ?>? This cannot be undone.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="delete">
                        <input type="hidden" name="cow_id"  value="<?= $cow['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
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

<!-- Pagination -->
<?php if ($pager['total_pages'] > 1): ?>
<div class="pagination">
    <?php if ($pager['has_prev']): ?>
    <a href="<?= e($qs(['status' => $status, 'search' => $search, 'page' => $pager['current_page'] - 1])) ?>" class="page-btn">&#8249; Prev</a>
    <?php endif; ?>
    <?php for ($p = max(1, $pager['current_page'] - 2); $p <= min($pager['total_pages'], $pager['current_page'] + 2); $p++): ?>
    <a href="<?= e($qs(['status' => $status, 'search' => $search, 'page' => $p])) ?>"
       class="page-btn <?= $p === $pager['current_page'] ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
    <?php if ($pager['has_next']): ?>
    <a href="<?= e($qs(['status' => $status, 'search' => $search, 'page' => $pager['current_page'] + 1])) ?>" class="page-btn">Next &#8250;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
