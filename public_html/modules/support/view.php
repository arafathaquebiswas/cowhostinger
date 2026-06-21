<?php
require_once dirname(__DIR__, 2) . '/includes/saas_guard.php';
saasRequire([], false);

$db        = getDB();
$uid       = (int)$_SESSION['user_id'];
$ticket_id = (int)($_GET['id'] ?? 0);
$is_staff  = isSupportStaff() || isSuperAdmin();

if ($ticket_id <= 0) redirect('/modules/support/index.php');

// Load ticket
$t_stmt = $db->prepare(
    "SELECT t.*, f.farm_name, f.farm_code,
            u.name AS creator_name, u.role AS creator_role,
            a.name AS assignee_name
     FROM support_tickets t
     JOIN farms f ON f.id = t.farm_id
     JOIN users u ON u.id = t.created_by
     LEFT JOIN users a ON a.id = t.assigned_to
     WHERE t.id = ? LIMIT 1"
);
$t_stmt->execute([$ticket_id]);
$ticket = $t_stmt->fetch();

if (!$ticket) {
    flashMessage('error', 'Ticket not found.');
    redirect('/modules/support/index.php');
}

// Farm users can only view tickets from their farm they created
if (!$is_staff && ((int)$ticket['farm_id'] !== fid() || (int)$ticket['created_by'] !== $uid)) {
    flashMessage('error', 'Access denied.');
    redirect('/modules/support/index.php');
}

// ── POST: reply / status change / assign ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'CSRF error.');
        redirect("/modules/support/view.php?id={$ticket_id}");
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        requirePermission('ticket.respond');
        $message     = sanitize($_POST['message'] ?? '');
        $is_internal = $is_staff && !empty($_POST['is_internal']);
        if (strlen($message) >= 2) {
            $db->prepare(
                "INSERT INTO support_ticket_messages (ticket_id, sender_id, message, is_internal) VALUES (?,?,?,?)"
            )->execute([$ticket_id, $uid, $message, $is_internal ? 1 : 0]);

            // Auto-set to in_progress on first staff reply
            if ($is_staff && $ticket['status'] === 'open') {
                $db->prepare("UPDATE support_tickets SET status='in_progress', updated_at=NOW() WHERE id=?")
                   ->execute([$ticket_id]);
            }

            logActivity('ticket.replied', ['ticket_id' => $ticket_id, 'internal' => $is_internal]);
            flashMessage('success', 'Reply posted.');
        }
    }

    if ($action === 'update_status' && $is_staff) {
        requirePermission('ticket.close');
        $new_status = in_array($_POST['status'] ?? '', ['open','in_progress','resolved','closed'], true)
                      ? $_POST['status'] : 'open';
        $closed_at  = in_array($new_status, ['resolved','closed'], true) ? date('Y-m-d H:i:s') : null;
        $db->prepare("UPDATE support_tickets SET status=?, closed_at=?, updated_at=NOW() WHERE id=?")
           ->execute([$new_status, $closed_at, $ticket_id]);
        logActivity('ticket.status_changed', ['ticket_id' => $ticket_id, 'status' => $new_status]);
        flashMessage('success', 'Status updated to: ' . ucwords(str_replace('_', ' ', $new_status)));
    }

    if ($action === 'assign' && $is_staff) {
        requirePermission('ticket.assign');
        $assign_to = (int)($_POST['assigned_to'] ?? 0);
        $db->prepare("UPDATE support_tickets SET assigned_to=?, updated_at=NOW() WHERE id=?")
           ->execute([$assign_to ?: null, $ticket_id]);
        logActivity('ticket.assigned', ['ticket_id' => $ticket_id, 'assigned_to' => $assign_to]);
        flashMessage('success', 'Ticket assigned.');
    }

    redirect("/modules/support/view.php?id={$ticket_id}");
}

// Load messages (staff see internal notes, farm users don't)
$msg_stmt = $db->prepare(
    "SELECT m.*, u.name AS sender_name, u.role AS sender_role
     FROM support_ticket_messages m
     JOIN users u ON u.id = m.sender_id
     WHERE m.ticket_id = ? " . (!$is_staff ? 'AND m.is_internal = 0' : '') . "
     ORDER BY m.created_at ASC"
);
$msg_stmt->execute([$ticket_id]);
$messages = $msg_stmt->fetchAll();

// Support staff list (for assign dropdown)
$staff_list = [];
if ($is_staff) {
    $staff_list = $db->query(
        "SELECT id, name FROM users WHERE role='support_staff' AND status='active' ORDER BY name"
    )->fetchAll();
}

$pri_color = ['critical'=>'#DC2626','high'=>'#D97706','medium'=>'#0284C7','low'=>'#6B7280'][$ticket['priority']] ?? '#6B7280';
$sta_color = ['open'=>'#DC2626','in_progress'=>'#D97706','resolved'=>'#059669','closed'=>'#9CA3AF'][$ticket['status']] ?? '#6B7280';

$page_title = 'Ticket #' . $ticket_id;
$active_nav = 'support';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Ticket #<?= $ticket_id ?>: <?= e($ticket['subject']) ?></h2>
        <p class="text-sm text-muted">
            <?= e($ticket['farm_name']) ?> &middot;
            Created by <?= e($ticket['creator_name']) ?> on <?= e(date('d M Y', strtotime($ticket['created_at']))) ?>
        </p>
    </div>
    <a href="/modules/support/index.php" class="btn btn-secondary">← Tickets</a>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:1.5rem;align-items:start">

    <!-- Messages thread -->
    <div>
        <div class="card">
            <div class="card-header">
                <span class="card-title">Conversation</span>
                <div style="display:flex;gap:.5rem">
                    <span class="badge" style="background:<?= $pri_color ?>;color:#fff;font-size:.7rem"><?= ucfirst($ticket['priority']) ?></span>
                    <span class="badge" style="background:<?= $sta_color ?>;color:#fff;font-size:.7rem"><?= ucwords(str_replace('_',' ',$ticket['status'])) ?></span>
                </div>
            </div>
            <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem">
                <?php foreach ($messages as $msg):
                    $is_own    = (int)$msg['sender_id'] === $uid;
                    $is_int    = (bool)$msg['is_internal'];
                    $is_st_msg = in_array($msg['sender_role'], ['superadmin','support_staff'], true);
                ?>
                <div style="
                    padding:1rem 1.1rem;border-radius:10px;
                    <?= $is_int ? 'background:#FFFBEB;border:1px dashed #FDE68A' :
                        ($is_st_msg ? 'background:#F0F9FF;border:1px solid #BAE6FD' : 'background:var(--bg-muted)') ?>
                ">
                    <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;font-size:.78rem;color:#6B7280">
                        <strong style="color:#111827"><?= e($msg['sender_name']) ?></strong>
                        <span>
                            <?php if ($is_int): ?>🔒 Internal note &middot; <?php endif; ?>
                            <?= e(date('d M Y H:i', strtotime($msg['created_at']))) ?>
                        </span>
                    </div>
                    <div style="white-space:pre-wrap;font-size:.88rem;color:#374151"><?= nl2br(e($msg['message'])) ?></div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($messages)): ?>
                <div class="text-center text-muted" style="padding:1rem;font-size:.85rem">No messages yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reply box -->
        <?php if (!in_array($ticket['status'], ['closed'], true)): ?>
        <div class="card" style="margin-top:1rem">
            <div class="card-header"><span class="card-title">Reply</span></div>
            <div style="padding:1.25rem">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reply">
                    <textarea name="message" class="form-control" rows="4" required
                              placeholder="Type your reply here..."
                              style="margin-bottom:.75rem"></textarea>
                    <?php if ($is_staff): ?>
                    <div style="margin-bottom:.75rem">
                        <label style="display:flex;align-items:center;gap:.5rem;font-size:.83rem;cursor:pointer">
                            <input type="checkbox" name="is_internal" value="1">
                            🔒 Internal note (not visible to farm owner)
                        </label>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">Send Reply</button>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="card" style="margin-top:1rem;background:#F9FAFB">
            <div style="padding:1.25rem;text-align:center;color:#6B7280;font-size:.85rem">
                This ticket is closed. <a href="/modules/support/create.php">Open a new ticket</a> if needed.
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar: info + actions -->
    <div style="display:flex;flex-direction:column;gap:1rem">

        <!-- Ticket info -->
        <div class="card">
            <div class="card-header"><span class="card-title">Ticket Info</span></div>
            <div style="padding:1rem">
                <table style="width:100%;font-size:.82rem;border-collapse:collapse">
                    <tr><td style="color:#6B7280;padding:.25rem 0">Farm</td>
                        <td style="font-weight:600"><?= e($ticket['farm_name']) ?></td></tr>
                    <tr><td style="color:#6B7280;padding:.25rem 0">Opened</td>
                        <td><?= e(date('d M Y', strtotime($ticket['created_at']))) ?></td></tr>
                    <tr><td style="color:#6B7280;padding:.25rem 0">Assigned</td>
                        <td><?= $ticket['assignee_name'] ? e($ticket['assignee_name']) : '<em style="color:#9CA3AF">Unassigned</em>' ?></td></tr>
                    <?php if ($ticket['closed_at']): ?>
                    <tr><td style="color:#6B7280;padding:.25rem 0">Closed</td>
                        <td><?= e(date('d M Y', strtotime($ticket['closed_at']))) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($is_staff): ?>

        <!-- Change status -->
        <div class="card">
            <div class="card-header"><span class="card-title">Update Status</span></div>
            <div style="padding:1rem">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_status">
                    <select name="status" class="form-control" style="margin-bottom:.5rem">
                        <?php foreach (['open','in_progress','resolved','closed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $ticket['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary" style="width:100%">Update</button>
                </form>
            </div>
        </div>

        <!-- Assign staff -->
        <div class="card">
            <div class="card-header"><span class="card-title">Assign To</span></div>
            <div style="padding:1rem">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="assign">
                    <select name="assigned_to" class="form-control" style="margin-bottom:.5rem">
                        <option value="">Unassigned</option>
                        <?php foreach ($staff_list as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $ticket['assigned_to']==$s['id']?'selected':'' ?>>
                            <?= e($s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary" style="width:100%">Assign</button>
                </form>
            </div>
        </div>

        <?php endif; ?>

        <!-- Farm owner: escalate -->
        <?php if (!$is_staff && $ticket['status'] === 'closed'): ?>
        <div class="card" style="background:#F0FDF4">
            <div style="padding:1rem;font-size:.82rem;color:#065F46">
                Issue resolved? <a href="/modules/support/create.php" style="font-weight:700">Open new ticket</a> for further help.
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
