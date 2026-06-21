<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['superadmin']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── POST: update plan price / limits ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/ceo/plans.php');
    }

    $action  = $_POST['action'] ?? '';
    $plan_id = (int)($_POST['plan_id'] ?? 0);

    if ($action === 'update_price' && $plan_id > 0) {
        $plan_stmt = $db->prepare("SELECT * FROM plans WHERE id = ?");
        $plan_stmt->execute([$plan_id]);
        $plan = $plan_stmt->fetch();

        if (!$plan) {
            flashMessage('error', 'Plan not found.');
            redirect('/modules/ceo/plans.php');
        }

        // Free plan price stays 0 — block any attempt to charge for it
        if ($plan['name'] === 'Free') {
            flashMessage('error', 'The Free plan price cannot be changed.');
            redirect('/modules/ceo/plans.php');
        }

        $new_price       = max(0, (float)($_POST['price_monthly'] ?? $plan['price_monthly']));
        $new_billing     = (int)($_POST['billing_days'] ?? $plan['billing_days']);
        $cows_limit      = trim($_POST['cows_limit'] ?? '') === '' ? null : (int)$_POST['cows_limit'];
        $users_limit     = trim($_POST['users_limit'] ?? '') === '' ? null : (int)$_POST['users_limit'];
        $workers_limit   = trim($_POST['workers_limit'] ?? '') === '' ? null : (int)$_POST['workers_limit'];
        $can_export      = isset($_POST['can_export'])      ? 1 : 0;
        $can_analytics   = isset($_POST['can_analytics'])   ? 1 : 0;
        $can_finance     = isset($_POST['can_finance'])     ? 1 : 0;
        $can_reports     = isset($_POST['can_reports'])     ? 1 : 0;
        $can_milk_anlytx = isset($_POST['can_milk_analytics']) ? 1 : 0;
        $notes           = sanitize($_POST['notes'] ?? '');

        $db->prepare(
            "UPDATE plans SET
                price_monthly=?, billing_days=?,
                cows_limit=?, users_limit=?, workers_limit=?,
                can_export=?, can_analytics=?, can_finance=?, can_reports=?, can_milk_analytics=?
             WHERE id=?"
        )->execute([
            $new_price, $new_billing ?: null,
            $cows_limit, $users_limit, $workers_limit,
            $can_export, $can_analytics, $can_finance, $can_reports, $can_milk_anlytx,
            $plan_id,
        ]);

        // Clear subscription engine cache so price changes take effect immediately
        unset($GLOBALS['_sub_engine_cache']);

        // Audit log
        auditLog($uid, 'CEO_PLAN_PRICE_CHANGE', 'plans', $plan_id,
            ['price_monthly' => $plan['price_monthly']],
            ['price_monthly' => $new_price, 'notes' => $notes]
        );

        // CEO grant audit (reuse table for plan-level changes)
        $db->prepare(
            "INSERT INTO ceo_grants
             (farm_id, farm_name, granted_by, action_type, old_plan_id, new_plan_id,
              old_plan_name, new_plan_name, duration_label, notes)
             VALUES (0, 'GLOBAL PRICING', ?, 'plan_change', ?, ?, ?, ?, ?, ?)"
        )->execute([
            $uid, $plan_id, $plan_id,
            $plan['name'] . ' @ ৳' . number_format($plan['price_monthly'], 2),
            $plan['name'] . ' @ ৳' . number_format($new_price, 2),
            'Price update',
            $notes ?: null,
        ]);

        flashMessage('success',
            "Plan <strong>{$plan['name']}</strong> updated — new price: ৳" . number_format($new_price, 2) . "/month."
        );
    } elseif ($action === 'toggle_active' && $plan_id > 0) {
        $db->prepare("UPDATE plans SET is_active = NOT is_active WHERE id = ? AND name != 'Free'")->execute([$plan_id]);
        auditLog($uid, 'CEO_PLAN_TOGGLE', 'plans', $plan_id, null, null);
        flashMessage('success', 'Plan availability toggled.');
    }

    redirect('/modules/ceo/plans.php');
}

// ── Fetch all plans ───────────────────────────────────────────────────────────
$plans = $db->query("SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id=p.id AND s.status IN ('active','trial')) AS active_subs FROM plans p ORDER BY p.price_monthly")->fetchAll();

// ── Price change history (last 20 from ceo_grants) ───────────────────────────
$price_history = $db->query(
    "SELECT cg.*, u.name AS ceo_name
     FROM ceo_grants cg
     LEFT JOIN users u ON u.id = cg.granted_by
     WHERE cg.farm_name = 'GLOBAL PRICING'
     ORDER BY cg.created_at DESC LIMIT 20"
)->fetchAll();

$page_title = 'Plans & Pricing';
$active_nav = 'ceo_plans';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Plans &amp; Pricing Manager</h2>
        <p class="text-muted text-sm">Change plan prices, limits, and features — takes effect immediately for all new subscriptions</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/ceo/index.php"           class="btn btn-secondary btn-sm">Control Center</a>
        <a href="/modules/ceo/subscriptions.php"   class="btn btn-primary btn-sm">Subscription Manager</a>
    </div>
</div>

<div class="alert" style="background:#fefce8;border-color:#fde047;color:#854d0e;margin-bottom:1.25rem">
    <strong>⚠️ CEO Only.</strong> Price changes apply to <em>new</em> subscriptions and renewals immediately.
    Existing active subscriptions run until their current end_date at the price they were billed.
    Lifetime members are never affected by pricing changes.
</div>

<!-- ── Plan Cards ──────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.25rem;margin-bottom:1.75rem">
<?php foreach ($plans as $p):
    $is_free = ($p['name'] === 'Free');
    $col = match($p['name']){
        'Free'=>'#6b7280','Basic'=>'#0284c7','Pro'=>'#7c3aed','Enterprise'=>'#d97706',default=>'#6b7280'
    };
?>
<div class="card" style="border-top:3px solid <?= $col ?>">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <div>
            <span style="font-weight:800;font-size:1rem;color:<?= $col ?>"><?= e($p['name']) ?></span>
            <?php if (!$p['is_active']): ?>
            <span class="badge badge-gray" style="margin-left:.4rem;font-size:.7rem">Inactive</span>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <div style="font-size:1.3rem;font-weight:800;color:<?= $col ?>">
                <?= $is_free ? 'Free' : '৳'.number_format($p['price_monthly'],2) ?>
            </div>
            <?php if (!$is_free): ?><div class="text-xs text-muted">/month</div><?php endif; ?>
        </div>
    </div>
    <div class="card-body" style="padding:.75rem 1rem">
        <!-- Current stats -->
        <div style="display:flex;gap:.75rem;margin-bottom:.9rem;flex-wrap:wrap">
            <div style="font-size:.8rem;color:var(--text-secondary)">Active Subs: <strong><?= $p['active_subs'] ?></strong></div>
            <div style="font-size:.8rem;color:var(--text-secondary)">Cows: <strong><?= $p['cows_limit'] ?? '∞' ?></strong></div>
            <div style="font-size:.8rem;color:var(--text-secondary)">Users: <strong><?= $p['users_limit'] ?? '∞' ?></strong></div>
        </div>

        <?php if (!$is_free): ?>
        <!-- Edit form -->
        <details>
            <summary style="cursor:pointer;font-size:.83rem;font-weight:600;color:<?= $col ?>;margin-bottom:.75rem;user-select:none">
                Edit Plan →
            </summary>
            <form method="POST" style="margin-top:.5rem">
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="update_price">
                <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.75rem">
                    <div class="form-group" style="margin:0">
                        <label class="form-label" style="font-size:.78rem">Monthly Price (৳) <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="price_monthly" class="form-control form-control-sm"
                               value="<?= e($p['price_monthly']) ?>" step="0.01" min="0" required>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label" style="font-size:.78rem">Billing Days</label>
                        <input type="number" name="billing_days" class="form-control form-control-sm"
                               value="<?= e($p['billing_days'] ?? '') ?>" min="1" placeholder="30">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label" style="font-size:.78rem">Max Cows (blank=∞)</label>
                        <input type="number" name="cows_limit" class="form-control form-control-sm"
                               value="<?= e($p['cows_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label" style="font-size:.78rem">Max Users (blank=∞)</label>
                        <input type="number" name="users_limit" class="form-control form-control-sm"
                               value="<?= e($p['users_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label" style="font-size:.78rem">Max Workers (blank=∞)</label>
                        <input type="number" name="workers_limit" class="form-control form-control-sm"
                               value="<?= e($p['workers_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                    </div>
                </div>

                <!-- Feature flags -->
                <div style="margin-bottom:.6rem">
                    <div style="font-size:.78rem;font-weight:600;color:var(--text-secondary);margin-bottom:.35rem">Features</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.25rem">
                        <?php foreach ([
                            ['can_export',         'Export',          $p['can_export']],
                            ['can_analytics',      'Analytics',       $p['can_analytics']],
                            ['can_finance',        'Finance',         $p['can_finance']],
                            ['can_reports',        'Reports',         $p['can_reports']],
                            ['can_milk_analytics', 'Milk Analytics',  $p['can_milk_analytics']],
                        ] as [$fname,$flabel,$fval]): ?>
                        <label style="display:flex;align-items:center;gap:.35rem;font-size:.8rem;cursor:pointer">
                            <input type="checkbox" name="<?= $fname ?>" value="1" <?= $fval?'checked':'' ?>>
                            <?= $flabel ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:.6rem">
                    <label class="form-label" style="font-size:.78rem">Change Reason (for audit log)</label>
                    <input type="text" name="notes" class="form-control form-control-sm" placeholder="e.g. Promotional pricing for Q3">
                </div>

                <div style="display:flex;gap:.5rem;align-items:center">
                    <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
                    <span style="font-size:.75rem;color:var(--text-secondary)">
                        Currently: <?= $p['active_subs'] ?> active subscriber<?= $p['active_subs']!==1?'s':'' ?>
                    </span>
                </div>
            </form>
        </details>
        <?php else: ?>
        <p class="text-xs text-muted">Free plan pricing is fixed at ৳0 and cannot be changed.</p>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Price Change History ────────────────────────────────────────────────── -->
<?php if (!empty($price_history)): ?>
<div class="card">
    <div class="card-header"><h3>Pricing Change History</h3></div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Plan</th>
                    <th>Old Price</th>
                    <th>New Price</th>
                    <th>Changed By</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($price_history as $h): ?>
            <tr>
                <td style="white-space:nowrap"><?= e(formatDateTime($h['created_at'])) ?></td>
                <td><strong><?= e(explode(' @', $h['new_plan_name'] ?? '')[0]) ?></strong></td>
                <td class="text-muted"><?= e($h['old_plan_name'] ?? '—') ?></td>
                <td style="font-weight:600;color:#0284c7"><?= e($h['new_plan_name'] ?? '—') ?></td>
                <td style="color:#7c3aed">👑 <?= e($h['ceo_name'] ?? 'CEO') ?></td>
                <td><?= e($h['notes'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
