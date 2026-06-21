<?php
require_once dirname(__DIR__, 2) . '/includes/saas_guard.php';
saasRequire([], false);
requireFarmScope();
requirePermission('ticket.create');

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$fid = fid();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'Invalid request token.';
    } else {
        $subject  = sanitize($_POST['subject']  ?? '');
        $message  = sanitize($_POST['message']  ?? '');
        $priority = in_array($_POST['priority'] ?? '', ['low','medium','high','critical'], true)
                    ? $_POST['priority'] : 'medium';

        if (strlen($subject) < 5)  $errors[] = 'Subject must be at least 5 characters.';
        if (strlen($message) < 20) $errors[] = 'Please describe your issue in more detail (min 20 chars).';

        if (empty($errors)) {
            $db->beginTransaction();
            try {
                $db->prepare(
                    "INSERT INTO support_tickets (farm_id, created_by, subject, priority, status)
                     VALUES (?,?,?,?,'open')"
                )->execute([$fid, $uid, $subject, $priority]);

                $ticket_id = (int)$db->lastInsertId();

                $db->prepare(
                    "INSERT INTO support_ticket_messages (ticket_id, sender_id, message, is_internal)
                     VALUES (?,?,?,0)"
                )->execute([$ticket_id, $uid, $message]);

                $db->commit();

                logActivity('ticket.created', ['ticket_id' => $ticket_id, 'subject' => $subject]);
                auditLog($uid, 'TICKET_CREATED', 'support_tickets', $ticket_id);

                flashMessage('success', 'Support ticket #' . $ticket_id . ' created. Our team will respond soon.');
                redirect('/modules/support/view.php?id=' . $ticket_id);
            } catch (\Throwable $e) {
                $db->rollBack();
                $errors[] = 'Failed to create ticket. Please try again.';
            }
        }
    }
}

$page_title = 'Create Support Ticket';
$active_nav = 'support';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>New Support Ticket</h2>
        <p class="text-sm text-muted">Describe your issue and our AB IT team will assist you.</p>
    </div>
    <a href="/modules/support/index.php" class="btn btn-secondary">← Back to Tickets</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error" style="margin-bottom:1rem">
    <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" style="max-width:720px">
    <div class="card-header"><span class="card-title">Ticket Details</span></div>
    <div style="padding:1.5rem">
        <form method="POST">
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label">Subject <span style="color:#DC2626">*</span></label>
                <input type="text" name="subject" class="form-control"
                       value="<?= e($_POST['subject'] ?? '') ?>"
                       placeholder="Brief description of the issue" maxlength="200" required>
            </div>

            <div class="form-group">
                <label class="form-label">Priority</label>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem">
                    <?php foreach (['low'=>'#6B7280','medium'=>'#0284C7','high'=>'#D97706','critical'=>'#DC2626'] as $val => $col): ?>
                    <label style="cursor:pointer">
                        <input type="radio" name="priority" value="<?= $val ?>"
                               <?= (($_POST['priority'] ?? 'medium') === $val) ? 'checked' : '' ?> style="display:none">
                        <div class="priority-option" data-val="<?= $val ?>" style="
                            text-align:center;padding:.5rem;border-radius:8px;border:2px solid <?= $col ?>;
                            color:<?= $col ?>;font-size:.78rem;font-weight:700;
                            background:<?= (($_POST['priority'] ?? 'medium') === $val) ? $col : 'transparent' ?>;
                            color:<?= (($_POST['priority'] ?? 'medium') === $val) ? '#fff' : $col ?>">
                            <?= ucfirst($val) ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Describe your issue <span style="color:#DC2626">*</span></label>
                <textarea name="message" class="form-control" rows="7" required minlength="20"
                    placeholder="Please describe your issue in detail. Include any error messages, what you were trying to do, and what happened instead."><?= e($_POST['message'] ?? '') ?></textarea>
            </div>

            <div style="display:flex;gap:.75rem;align-items:center">
                <button type="submit" class="btn btn-primary">Submit Ticket</button>
                <a href="/modules/support/index.php" class="btn btn-secondary">Cancel</a>
                <span class="text-xs text-muted">Response time: usually within 24 hours</span>
            </div>
        </form>
    </div>
</div>

<script>
// Visual priority selector
document.querySelectorAll('.priority-option').forEach(el => {
    el.closest('label').addEventListener('click', () => {
        const colors = {low:'#6B7280',medium:'#0284C7',high:'#D97706',critical:'#DC2626'};
        document.querySelectorAll('.priority-option').forEach(o => {
            const c = colors[o.dataset.val];
            o.style.background = 'transparent';
            o.style.color = c;
        });
        const c = colors[el.dataset.val];
        el.style.background = c;
        el.style.color = '#fff';
    });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
