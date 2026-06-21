<?php
require_once dirname(__DIR__, 2) . '/includes/saas_guard.php';
saasRequire([], false); // any authenticated user

// Role routing: CEO and support see all tickets; farm users see their own
$is_staff  = isSupportStaff() || isSuperAdmin();
$is_farmer = !$is_staff;

if ($is_farmer) {
    requireFarmScope();
    requirePermission('ticket.create');
}

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_status   = in_array($_GET['status']   ?? '', ['open','in_progress','resolved','closed',''], true) ? ($_GET['status'] ?? '') : '';
$filter_priority = in_array($_GET['priority'] ?? '', ['low','medium','high','critical',''], true)         ? ($_GET['priority'] ?? '') : '';
$filter_farm     = $is_staff ? (int)($_GET['farm_id'] ?? 0) : fid();

// ── Query ─────────────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($filter_farm > 0) {
    $where[]  = 't.farm_id = ?';
    $params[] = $filter_farm;
} elseif ($is_farmer) {
    // Farm users always scoped to their own farm
    $where[]  = 't.farm_id = ?';
    $params[] = fid();
}
if ($filter_status !== '') {
    $where[]  = 't.status = ?';
    $params[] = $filter_status;
}
if ($filter_priority !== '') {
    $where[]  = 't.priority = ?';
    $params[] = $filter_priority;
}
// Farm users only see their own tickets (or if assigned to them for staff)
if ($is_farmer) {
    $where[]  = 't.created_by = ?';
    $params[] = $uid;
}

$tickets = $db->prepare(
    "SELECT t.*, f.farm_name, f.farm_code,
            u.name AS creator_name,
            a.name AS assignee_name,
            (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id=t.id AND m.is_internal=0) AS reply_count
     FROM support_tickets t
     JOIN farms f ON f.id = t.farm_id
     JOIN users u ON u.id = t.created_by
     LEFT JOIN users a ON a.id = t.assigned_to
     WHERE " . implode(' AND ', $where) . "
     ORDER BY
       FIELD(t.priority,'critical','high','medium','low'),
       FIELD(t.status,'open','in_progress','resolved','closed'),
       t.created_at DESC
     LIMIT 100"
);
$tickets->execute($params);
$all_tickets = $tickets->fetchAll();

// Counts for tabs
$count_q = $db->prepare(
    "SELECT status, COUNT(*) AS cnt FROM support_tickets t
     WHERE " . ($is_farmer ? "farm_id=? AND created_by=?" : "1=1") . "
     GROUP BY status"
);
if ($is_farmer) $count_q->execute([fid(), $uid]);
else            $count_q->execute([]);
$counts = array_column($count_q->fetchAll(), 'cnt', 'status');

$page_title = 'Support Tickets';
$active_nav = 'support';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Support Tickets</h2>
        <p class="text-sm text-muted">
            <?= $is_staff ? 'All farm support requests' : 'Your support requests to AB IT' ?>
        </p>
    </div>
    <?php if ($is_farmer): ?>
    <a href="/modules/support/create.php" class="btn btn-primary">+ New Ticket</a>
    <?php endif; ?>
</div>

<!-- Status tabs -->
<div style="display:flex;gap:.5rem;margin-bottom:1.25rem;flex-wrap:wrap">
    <?php
    $tab_styles = ['open'=>'#DC2626','in_progress'=>'#D97706','resolved'=>'#059669','closed'=>'#6B7280'];
    $tabs = ['' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
    foreach ($tabs as $val => $label):
        $cnt     = $val === '' ? array_sum($counts) : ($counts[$val] ?? 0);
        $active  = $filter_status === $val;
        $col     = $tab_styles[$val] ?? '#6B7280';
        $url     = '?' . http_build_query(array_merge($_GET, ['status' => $val]));
    ?>
    <a href="<?= $url ?>" class="btn btn-sm" style="<?= $active ? "background:{$col};color:#fff;border-color:{$col}" : 'background:var(--bg-base)' ?>">
        <?= $label ?> <?php if ($cnt > 0): ?><span style="opacity:.75">(<?= $cnt ?>)</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Ticket list -->
<div class="card">
    <?php if (empty($all_tickets)): ?>
    <div style="padding:3rem;text-align:center;color:#9CA3AF">
        <div style="font-size:2.5rem;margin-bottom:.75rem">🎫</div>
        <div style="font-weight:600">No tickets found</div>
        <?php if ($is_farmer): ?>
        <div style="margin-top:.5rem;font-size:.85rem">
            <a href="/modules/support/create.php" class="btn btn-sm btn-primary" style="margin-top:.75rem">Create your first ticket</a>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <?php if ($is_staff): ?><th>Farm</th><?php endif; ?>
                <th>Subject</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Assigned</th>
                <th>Replies</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($all_tickets as $t):
            $pri_color = ['critical'=>'#DC2626','high'=>'#D97706','medium'=>'#0284C7','low'=>'#6B7280'][$t['priority']] ?? '#6B7280';
            $sta_color = ['open'=>'#DC2626','in_progress'=>'#D97706','resolved'=>'#059669','closed'=>'#9CA3AF'][$t['status']] ?? '#6B7280';
        ?>
        <tr>
            <td class="text-xs text-muted">#<?= $t['id'] ?></td>
            <?php if ($is_staff): ?>
            <td>
                <span style="font-weight:600;font-size:.82rem"><?= e($t['farm_name']) ?></span>
                <span class="text-xs text-muted d-block"><?= e($t['farm_code']) ?></span>
            </td>
            <?php endif; ?>
            <td style="max-width:260px">
                <a href="/modules/support/view.php?id=<?= $t['id'] ?>" style="font-weight:600;color:var(--primary)">
                    <?= e($t['subject']) ?>
                </a>
                <span class="text-xs text-muted d-block">by <?= e($t['creator_name']) ?></span>
            </td>
            <td><span class="badge" style="background:<?= $pri_color ?>;color:#fff;font-size:.68rem"><?= ucfirst($t['priority']) ?></span></td>
            <td><span class="badge" style="background:<?= $sta_color ?>;color:#fff;font-size:.68rem"><?= ucwords(str_replace('_',' ',$t['status'])) ?></span></td>
            <td class="text-xs text-muted"><?= $t['assignee_name'] ? e($t['assignee_name']) : '—' ?></td>
            <td style="text-align:center"><?= (int)$t['reply_count'] ?></td>
            <td class="text-xs text-muted"><?= e(date('d M Y', strtotime($t['created_at']))) ?></td>
            <td><a href="/modules/support/view.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-secondary">View</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
