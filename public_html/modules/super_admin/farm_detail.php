<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['superadmin']);

$db      = getDB();
$farm_id = (int)($_GET['id'] ?? 0);
if ($farm_id <= 0) { redirect('/modules/super_admin/index.php'); }

// Load farm
$farm_stmt = $db->prepare(
    "SELECT f.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
            p.name AS plan_name, p.id AS plan_id,
            s.status AS sub_status, s.start_date AS sub_start, s.end_date AS sub_end
     FROM farms f
     LEFT JOIN users u ON u.id = f.owner_user_id
     LEFT JOIN subscriptions s ON s.farm_id = f.id AND s.status IN ('active','trial')
     LEFT JOIN plans p ON p.id = s.plan_id
     WHERE f.id = ? LIMIT 1"
);
$farm_stmt->execute([$farm_id]);
$farm = $farm_stmt->fetch();
if (!$farm) { flashMessage('error', 'Farm not found.'); redirect('/modules/super_admin/index.php'); }

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'CSRF mismatch.');
        redirect("/modules/super_admin/farm_detail.php?id={$farm_id}");
    }
    $action = $_POST['action'] ?? '';
    $uid    = (int)$_SESSION['user_id'];

    if ($action === 'update_farm') {
        $farm_name = sanitize($_POST['farm_name'] ?? '');
        $location  = sanitize($_POST['location']  ?? '');
        $status    = in_array($_POST['status']??'', ['active','suspended','trial'], true) ? $_POST['status'] : 'active';
        if ($farm_name !== '') {
            $db->prepare("UPDATE farms SET farm_name=?,location=?,status=? WHERE id=?")
               ->execute([$farm_name, $location ?: null, $status, $farm_id]);
            auditLog($uid, 'SUPERADMIN_UPDATE_FARM', 'farms', $farm_id);
            flashMessage('success', 'Farm updated.');
        }
    }

    if ($action === 'change_plan') {
        $plan_id = (int)($_POST['plan_id'] ?? 1);
        $sub_row = $db->prepare("SELECT id FROM subscriptions WHERE farm_id=? ORDER BY id DESC LIMIT 1");
        $sub_row->execute([$farm_id]);
        if ($r = $sub_row->fetch()) {
            $db->prepare("UPDATE subscriptions SET plan_id=?,status='active',start_date=CURDATE(),end_date=NULL WHERE id=?")
               ->execute([$plan_id, $r['id']]);
        } else {
            $db->prepare("INSERT INTO subscriptions (farm_id,plan_id,start_date,status) VALUES (?,?,CURDATE(),'active')")
               ->execute([$farm_id, $plan_id]);
        }
        auditLog($uid, 'SUPERADMIN_CHANGE_PLAN', 'farms', $farm_id, null, ['plan_id'=>$plan_id]);
        flashMessage('success', 'Plan updated.');
    }

    if ($action === 'reset_password') {
        $target_user_id = (int)($_POST['user_id'] ?? 0);
        $new_pwd = $_POST['new_password'] ?? '';
        if ($target_user_id > 0 && strlen($new_pwd) >= 8) {
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password_hash=? WHERE id=? AND farm_id=?")
               ->execute([$hash, $target_user_id, $farm_id]);
            auditLog($uid, 'SUPERADMIN_RESET_PASSWORD', 'users', $target_user_id);
            flashMessage('success', 'Password reset successfully.');
        } else {
            flashMessage('error', 'Invalid user or password must be at least 8 characters.');
        }
    }

    if ($action === 'impersonate') {
        // CEO impersonation — temporarily view system as this farm's admin
        $_SESSION['impersonating_as_farm_id'] = $farm_id;
        // Log impersonation start
        $db->prepare(
            "INSERT INTO impersonation_log (superadmin_id, target_farm_id, ip_address) VALUES (?,?,?)"
        )->execute([$uid, $farm_id, $_SERVER['REMOTE_ADDR'] ?? null]);
        auditLog($uid, 'IMPERSONATION_START', 'farms', $farm_id);
        flashMessage('info', "Now viewing as {$farm['farm_name']}. Click 'Exit Impersonation' to return.");
        redirect('/dashboard.php');
    }

    if ($action === 'record_payment') {
        $amount  = (float)($_POST['amount']  ?? 0);
        $method  = in_array($_POST['method'] ?? '', ['bkash','nagad','rocket','bank','manual'], true)
                   ? $_POST['method'] : 'manual';
        $ref     = sanitize($_POST['transaction_ref'] ?? '');
        $months  = max(1, (int)($_POST['months'] ?? 1));
        $notes   = sanitize($_POST['notes'] ?? '');
        $plan_id = (int)($_POST['plan_id'] ?? 1);

        if ($amount > 0) {
            $db->beginTransaction();
            try {
                // Record payment
                $db->prepare(
                    "INSERT INTO payments (farm_id, plan_id, amount, method, transaction_ref, status, months, paid_at, recorded_by, notes)
                     VALUES (?,?,?,?,?,'completed',?,NOW(),?,?)"
                )->execute([$farm_id, $plan_id, $amount, $method, $ref ?: null, $months, $uid, $notes ?: null]);

                // Update/insert subscription
                $end_date = date('Y-m-d', strtotime("+{$months} months"));
                $sub_row = $db->prepare("SELECT id FROM subscriptions WHERE farm_id=? ORDER BY id DESC LIMIT 1");
                $sub_row->execute([$farm_id]);
                if ($sr = $sub_row->fetch()) {
                    $db->prepare("UPDATE subscriptions SET plan_id=?,status='active',end_date=?,grace_end_date=NULL WHERE id=?")
                       ->execute([$plan_id, $end_date, $sr['id']]);
                } else {
                    $db->prepare("INSERT INTO subscriptions (farm_id,plan_id,status,start_date,end_date) VALUES (?,?,'active',CURDATE(),?)")
                       ->execute([$farm_id, $plan_id, $end_date]);
                }
                // Ensure farm is active
                $db->prepare("UPDATE farms SET status='active' WHERE id=?")->execute([$farm_id]);
                $db->commit();
                auditLog($uid, 'RECORD_PAYMENT', 'payments', (int)$db->lastInsertId(), null, ['amount'=>$amount,'plan_id'=>$plan_id]);
                flashMessage('success', "Payment of ৳" . number_format($amount, 2) . " recorded. Subscription extended to {$end_date}.");
            } catch (\Throwable $e) {
                $db->rollBack();
                flashMessage('error', 'Failed to record payment: ' . $e->getMessage());
            }
        } else {
            flashMessage('error', 'Amount must be greater than zero.');
        }
    }

    redirect("/modules/super_admin/farm_detail.php?id={$farm_id}");
}

// Farm stats
$cow_count = (int)$db->prepare("SELECT COUNT(*) FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')")->execute([$farm_id]) ? 0 : 0;
$cst = $db->prepare("SELECT COUNT(*) FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')");
$cst->execute([$farm_id]);
$cow_count = (int)$cst->fetchColumn();

$milk_month_stmt = $db->prepare("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE farm_id=? AND MONTH(recorded_at)=MONTH(CURDATE()) AND YEAR(recorded_at)=YEAR(CURDATE())");
$milk_month_stmt->execute([$farm_id]);
$milk_month = (float)$milk_month_stmt->fetchColumn();

$fin_stmt = $db->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM finance_transactions WHERE farm_id=? AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())");
$fin_stmt->execute([$farm_id]);
$net_profit = (float)$fin_stmt->fetchColumn();

// Users of this farm
$users_stmt = $db->prepare("SELECT id, name, email, phone, role, is_owner, status, created_at FROM users WHERE farm_id=? ORDER BY is_owner DESC, created_at");
$users_stmt->execute([$farm_id]);
$farm_users = $users_stmt->fetchAll();

$plans = $db->query("SELECT id, name, price_monthly FROM plans WHERE is_active=1 ORDER BY price_monthly")->fetchAll();

$page_title = 'Farm: ' . $farm['farm_name'];
$active_nav = 'super_admin';

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2><?= e($farm['farm_name']) ?></h2>
        <p class="text-sm text-muted">
            <code style="background:var(--bg-base);padding:.15rem .4rem;border-radius:4px"><?= e($farm['farm_code']) ?></code>
            &nbsp;·&nbsp; <?= e($farm['location'] ?? 'No location') ?>
            &nbsp;·&nbsp;
            <span class="badge <?= $farm['status']==='active'?'badge-green':($farm['status']==='suspended'?'badge-red':'badge-orange') ?>">
                <?= ucfirst($farm['status']) ?>
            </span>
        </p>
    </div>
    <div style="display:flex;gap:.5rem">
        <form method="POST" style="margin:0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="impersonate">
            <button type="submit" class="btn btn-warning"
                    onclick="return confirm('View system as this farm? You can exit via the banner on any page.')"
                    style="background:#D97706;border-color:#D97706;color:#fff">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><circle cx="12" cy="8" r="4"/><path d="M2 20c0-4 4-7 10-7s10 3 10 7"/></svg>
                Login As This Farm
            </button>
        </form>
        <a href="/modules/super_admin/index.php" class="btn btn-secondary">← Back</a>
    </div>
</div>

<!-- Stats row -->
<div class="kpi-grid" style="margin-bottom:2rem">
    <div class="kpi-card" style="--kpi-color:#2D6A4F;--kpi-soft:#D8F3DC">
        <div class="kpi-value"><?= number_format($cow_count) ?></div>
        <div class="kpi-label">Active Cows</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#0284C7;--kpi-soft:#F0F9FF">
        <div class="kpi-value"><?= number_format($milk_month, 0) ?> L</div>
        <div class="kpi-label">Milk This Month</div>
    </div>
    <div class="kpi-card" style="--kpi-color:<?= $net_profit>=0?'#059669':'#DC2626' ?>;--kpi-soft:<?= $net_profit>=0?'#F0FDF4':'#FEF2F2' ?>">
        <div class="kpi-value">৳ <?= number_format($net_profit, 0) ?></div>
        <div class="kpi-label">Net Profit (This Month)</div>
    </div>
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-value"><?= count($farm_users) ?></div>
        <div class="kpi-label">Total Users</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 360px;gap:1.5rem;align-items:start">

    <!-- Users list -->
    <div>
        <div class="card" style="margin-bottom:1.5rem">
            <div class="card-header">
                <span class="card-title">Farm Users</span>
            </div>
            <div style="overflow-x:auto">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Contact</th><th>Role</th><th>Status</th><th>Joined</th><th>Reset Password</th></tr>
                </thead>
                <tbody>
                <?php foreach ($farm_users as $fu): ?>
                <tr>
                    <td>
                        <span style="font-weight:600"><?= e($fu['name']) ?></span>
                        <?php if ($fu['is_owner']): ?> <span class="badge badge-orange" style="font-size:.65rem">Owner</span><?php endif; ?>
                    </td>
                    <td class="text-sm text-muted"><?= e($fu['phone'] ?? $fu['email'] ?? '—') ?></td>
                    <td><span class="badge badge-blue" style="font-size:.7rem"><?= e(ucfirst($fu['role'])) ?></span></td>
                    <td>
                        <span class="badge <?= $fu['status']==='active'?'badge-green':'badge-red' ?>" style="font-size:.7rem">
                            <?= ucfirst($fu['status']) ?>
                        </span>
                    </td>
                    <td class="text-xs text-muted"><?= e(formatDate($fu['created_at'])) ?></td>
                    <td>
                        <form method="POST" style="display:flex;gap:.35rem;align-items:center">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"  value="reset_password">
                            <input type="hidden" name="user_id" value="<?= $fu['id'] ?>">
                            <input type="password" name="new_password" class="form-control"
                                   placeholder="New password" minlength="8" style="width:140px;font-size:.8rem;padding:.3rem .5rem">
                            <button type="submit" class="btn btn-secondary btn-sm">Reset</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Right column: farm edit + plan -->
    <div style="display:flex;flex-direction:column;gap:1.25rem">

        <!-- Edit farm -->
        <div class="card">
            <div class="card-header"><span class="card-title">Farm Settings</span></div>
            <div style="padding:1.25rem">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_farm">
                    <div class="form-group">
                        <label class="form-label">Farm Name</label>
                        <input type="text" name="farm_name" class="form-control"
                               value="<?= e($farm['farm_name']) ?>" required maxlength="200">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Location</label>
                        <input type="text" name="location" class="form-control"
                               value="<?= e($farm['location'] ?? '') ?>" maxlength="300">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active"    <?= $farm['status']==='active'?'selected':''    ?>>Active</option>
                            <option value="trial"     <?= $farm['status']==='trial'?'selected':''     ?>>Trial</option>
                            <option value="suspended" <?= $farm['status']==='suspended'?'selected':'' ?>>Suspended</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%">Save Changes</button>
                </form>
            </div>
        </div>

        <!-- Change plan -->
        <div class="card">
            <div class="card-header"><span class="card-title">Subscription Plan</span></div>
            <div style="padding:1.25rem">
                <p class="text-sm text-muted" style="margin-bottom:1rem">
                    Current: <strong><?= e($farm['plan_name'] ?? 'None') ?></strong>
                    <?php if ($farm['sub_start']): ?>
                    · Since <?= e(formatDate($farm['sub_start'])) ?>
                    <?php endif; ?>
                    <?php if ($farm['sub_end']): ?>
                    · Expires <?= e(formatDate($farm['sub_end'])) ?>
                    <?php endif; ?>
                </p>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_plan">
                    <div class="form-group">
                        <label class="form-label">Change Plan</label>
                        <select name="plan_id" class="form-control">
                            <?php foreach ($plans as $pl): ?>
                            <option value="<?= $pl['id'] ?>" <?= ($farm['plan_id']==$pl['id'])?'selected':'' ?>>
                                <?= e($pl['name']) ?> (৳<?= number_format($pl['price_monthly'],0) ?>/mo)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="width:100%">Update Plan</button>
                </form>
            </div>
        </div>

        <!-- Record Payment -->
        <div class="card" style="border:1px solid #BBF7D0">
            <div class="card-header" style="background:#F0FDF4"><span class="card-title" style="color:#065F46">Record Payment</span></div>
            <div style="padding:1.25rem">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="record_payment">
                    <div class="form-group">
                        <label class="form-label">Plan</label>
                        <select name="plan_id" class="form-control">
                            <?php foreach ($plans as $pl): ?>
                            <?php if ($pl['price_monthly'] > 0): ?>
                            <option value="<?= $pl['id'] ?>" <?= ($farm['plan_id']==$pl['id'])?'selected':'' ?>>
                                <?= e($pl['name']) ?> — ৳<?= number_format($pl['price_monthly'],0) ?>/mo
                            </option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                        <div class="form-group">
                            <label class="form-label">Amount (৳)</label>
                            <input type="number" name="amount" class="form-control" min="1" step="0.01" placeholder="499.00" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Months</label>
                            <input type="number" name="months" class="form-control" min="1" max="24" value="1" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Method</label>
                        <select name="method" class="form-control">
                            <option value="bkash">bKash</option>
                            <option value="nagad">Nagad</option>
                            <option value="rocket">Rocket</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="manual">Manual</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Transaction Ref</label>
                        <input type="text" name="transaction_ref" class="form-control" placeholder="TXN123456" maxlength="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notes (optional)</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500" placeholder="Additional notes..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-success" style="width:100%;background:#059669;border-color:#059669">
                        ✓ Record Payment &amp; Activate
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
