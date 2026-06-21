<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireAuth();
requireFarmScope();

$page_title = 'Subscription & Plans';
$active_nav = 'subscription';
$db  = getDB();
$fid = fid();

$plan  = farmPlan();
$usage = farmAllUsage();

// Load all available plans
$plans_stmt = $db->query("SELECT * FROM plans WHERE is_active=1 ORDER BY price_monthly ASC");
$all_plans  = $plans_stmt->fetchAll();

// Load payment history
$pay_stmt = $db->prepare(
    "SELECT py.*, p.name AS plan_name FROM payments py
     JOIN plans p ON p.id = py.plan_id
     WHERE py.farm_id = ?
     ORDER BY py.created_at DESC LIMIT 20"
);
$pay_stmt->execute([$fid]);
$payments = $pay_stmt->fetchAll();

// Load current subscription
$sub_stmt = $db->prepare(
    "SELECT s.*, p.name AS plan_name FROM subscriptions s
     JOIN plans p ON p.id = s.plan_id
     WHERE s.farm_id = ?
     ORDER BY s.id DESC LIMIT 1"
);
$sub_stmt->execute([$fid]);
$subscription = $sub_stmt->fetch();

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Subscription &amp; Plans</h2>
        <p class="text-sm text-muted">Manage your farm's subscription and upgrade your plan</p>
    </div>
</div>

<!-- Current Plan Status -->
<div class="card" style="margin-bottom:1.5rem;border:2px solid <?= $plan['is_blocked'] ? '#FECACA' : ($plan['is_free'] ? '#E9D5FF' : '#BBF7D0') ?>">
    <div class="card-body" style="padding:1.5rem">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem">
            <div>
                <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
                    <span style="font-size:2rem"><?= $plan['is_blocked'] ? '🔒' : ($plan['is_free'] ? '🆓' : '⭐') ?></span>
                    <div>
                        <div style="font-size:1.2rem;font-weight:700;color:#111827"><?= e($plan['name'] ?? 'Free') ?> Plan</div>
                        <div style="font-size:.82rem;color:#6B7280">
                            Status: <strong style="color:<?= $plan['is_blocked'] ? '#DC2626' : '#059669' ?>"><?= ucfirst($plan['sub_status'] ?? 'trial') ?></strong>
                            <?php if ($subscription && $subscription['end_date']): ?>
                            &nbsp;·&nbsp; Expires: <strong><?= e($subscription['end_date']) ?></strong>
                            <?php if ($plan['days_left'] !== null): ?>
                            (<?= $plan['days_left'] > 0 ? $plan['days_left'] . ' days left' : 'Expired' ?>)
                            <?php endif; ?>
                            <?php else: ?>
                            &nbsp;·&nbsp; <span style="color:#059669">No expiry</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Usage summary -->
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;margin-top:1rem;max-width:700px">
                    <?php
                    $usage_items = [
                        ['Cows',      $usage['cows'],      $plan['cows_limit']      ?? null, '/modules/cows/index.php'],
                        ['Workers',   $usage['workers'],   $plan['workers_limit']   ?? null, '/modules/workers/index.php'],
                        ['Equipment', $usage['equipment'], $plan['equipment_limit'] ?? null, '/modules/equipment/index.php'],
                        ['Feed Items',$usage['feed'],      $plan['feed_limit']      ?? null, '/modules/feed_medicine/index.php?tab=feed'],
                        ['Medicine',  $usage['medicine'],  $plan['medicine_limit']  ?? null, '/modules/feed_medicine/index.php?tab=medicine'],
                    ];
                    foreach ($usage_items as [$label, $cur, $max, $href]):
                        $pct = $max ? min(100, round($cur / $max * 100)) : 0;
                        $col = $pct >= 100 ? '#DC2626' : ($pct >= 80 ? '#D97706' : '#059669');
                    ?>
                    <div style="background:var(--bg-muted);border-radius:8px;padding:.6rem .8rem">
                        <div style="font-size:.72rem;color:#6B7280;margin-bottom:.2rem"><?= e($label) ?></div>
                        <div style="font-size:.95rem;font-weight:700;color:<?= $col ?>"><?= $cur ?><?= $max ? " / {$max}" : '' ?></div>
                        <?php if ($max): ?>
                        <div class="usage-meter" style="margin-top:.3rem"><div class="usage-meter-fill" data-pct="<?= $pct ?>" style="width:<?= $pct ?>%"></div></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($plan['is_blocked']): ?>
            <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:1rem;text-align:center;max-width:220px">
                <div style="font-size:1.5rem;margin-bottom:.4rem">🔒</div>
                <div style="font-weight:700;color:#DC2626;margin-bottom:.4rem">Access Blocked</div>
                <div style="font-size:.8rem;color:#6B7280;margin-bottom:.8rem">
                    <?= $plan['is_suspended'] ? 'Account suspended. Contact support.' : 'Subscription expired.' ?>
                </div>
                <a href="mailto:support@abit.com.bd" class="btn btn-sm btn-danger">Contact Support</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Feature Access Table -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><h4 class="card-title">Feature Access</h4></div>
    <div class="card-body" style="padding:0">
        <table class="table" style="margin:0">
            <thead><tr><th>Feature</th><th>Your Plan</th></tr></thead>
            <tbody>
            <?php
            $features = [
                ['Finance Module',    $plan['can_finance']         ?? 0],
                ['Reports',          $plan['can_reports']         ?? 0],
                ['Milk Analytics',   $plan['can_milk_analytics']  ?? 0],
                ['Data Export',      $plan['can_export']          ?? 0],
                ['Advanced Charts',  $plan['can_analytics']       ?? 0],
                ['Breeding Records', 1],
                ['Alerts',           1],
                ['Diagnosis',        1],
            ];
            foreach ($features as [$feat, $allowed]):
            ?>
            <tr>
                <td><?= e($feat) ?></td>
                <td>
                    <?php if ($allowed): ?>
                    <span class="badge badge-green">Included</span>
                    <?php else: ?>
                    <span class="badge badge-gray">Locked</span>
                    <button type="button" class="btn btn-sm btn-upgrade" style="margin-left:.5rem"
                            onclick="showUpgradeModal('Upgrade to unlock <strong><?= e(addslashes($feat)) ?></strong>.')">Upgrade</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pricing Plans -->
<h3 style="margin-bottom:1rem;font-size:1.1rem;font-weight:700">Available Plans</h3>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem">
<?php foreach ($all_plans as $pl): ?>
<?php $is_current = ($plan['name'] ?? '') === $pl['name']; ?>
<div class="card" style="border:2px solid <?= $is_current ? '#7C3AED' : 'var(--border)' ?>;position:relative<?= $pl['name']==='Pro'?';box-shadow:0 4px 20px rgba(124,58,237,.15)':'' ?>">
    <?php if ($pl['name'] === 'Pro'): ?>
    <div style="position:absolute;top:-1px;right:16px;background:#7C3AED;color:#fff;font-size:.65rem;font-weight:700;letter-spacing:.05em;padding:.2rem .6rem;border-radius:0 0 6px 6px;text-transform:uppercase">Most Popular</div>
    <?php endif; ?>
    <div class="card-body" style="padding:1.25rem">
        <div style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:.25rem"><?= e($pl['name']) ?></div>
        <div style="font-size:1.6rem;font-weight:800;color:#111827;margin-bottom:.1rem">
            <?= $pl['price_monthly'] > 0 ? '৳' . number_format($pl['price_monthly']) : '<span style="color:#059669">Free</span>' ?>
            <?php if ($pl['price_monthly'] > 0): ?><span style="font-size:.75rem;font-weight:400;color:#6B7280">/month</span><?php endif; ?>
        </div>
        <div style="font-size:.72rem;color:#9CA3AF;margin-bottom:1rem">
            <?= $pl['billing_days'] ? $pl['billing_days'] . ' day billing cycle' : 'No expiry' ?>
        </div>
        <ul style="list-style:none;padding:0;margin:0 0 1.25rem;font-size:.8rem;color:#374151;display:flex;flex-direction:column;gap:.4rem">
            <li>🐄 Cows: <strong><?= $pl['cows_limit'] ?? 'Unlimited' ?></strong></li>
            <li>👷 Workers: <strong><?= $pl['workers_limit'] ?? 'Unlimited' ?></strong></li>
            <li>⚙️ Equipment: <strong><?= $pl['equipment_limit'] ?? 'Unlimited' ?></strong></li>
            <li style="color:<?= $pl['can_finance'] ? '#059669' : '#9CA3AF' ?>"><?= $pl['can_finance'] ? '✓' : '✗' ?> Finance Module</li>
            <li style="color:<?= $pl['can_reports'] ? '#059669' : '#9CA3AF' ?>"><?= $pl['can_reports'] ? '✓' : '✗' ?> Reports</li>
            <li style="color:<?= $pl['can_export'] ? '#059669' : '#9CA3AF' ?>"><?= $pl['can_export'] ? '✓' : '✗' ?> Export Data</li>
            <li style="color:<?= $pl['can_milk_analytics'] ? '#059669' : '#9CA3AF' ?>"><?= $pl['can_milk_analytics'] ? '✓' : '✗' ?> Milk Analytics</li>
        </ul>
        <?php if ($is_current): ?>
        <div class="btn btn-primary btn-block" style="background:#7C3AED;border:none;text-align:center">Current Plan</div>
        <?php elseif ($pl['price_monthly'] > 0): ?>
        <a href="mailto:support@abit.com.bd?subject=Upgrade Request — <?= e($pl['name']) ?> Plan&body=Farm Code: <?= e($farm['farm_code'] ?? '') ?>%0ARequested Plan: <?= e($pl['name']) ?>%0AAmount: ৳<?= number_format($pl['price_monthly']) ?>/month%0A%0APlease process my upgrade request."
           class="btn btn-primary btn-block" style="background:linear-gradient(135deg,#7C3AED,#A855F7);border:none">
            Upgrade to <?= e($pl['name']) ?>
        </a>
        <?php else: ?>
        <div class="btn btn-secondary btn-block" style="text-align:center;cursor:default">Current Free Plan</div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Contact Info -->
<div class="card" style="margin-bottom:1.5rem;background:linear-gradient(135deg,#F5F3FF,#EDE9FE)">
    <div class="card-body" style="padding:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
        <span style="font-size:2rem">📞</span>
        <div>
            <div style="font-weight:700;color:#4C1D95;margin-bottom:.2rem">Need help? Contact AB IT</div>
            <div style="font-size:.85rem;color:#6B7280">
                WhatsApp / Call: <strong>+880-XXX-XXXXXX</strong> &nbsp;·&nbsp;
                Email: <strong>support@abit.com.bd</strong> &nbsp;·&nbsp;
                We accept bKash, Nagad, Rocket &amp; Bank Transfer
            </div>
        </div>
    </div>
</div>

<!-- Payment History -->
<?php if (!empty($payments)): ?>
<div class="card">
    <div class="card-header"><h4 class="card-title">Payment History</h4></div>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Method</th><th>Ref</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $py): ?>
        <tr>
            <td><?= e(formatDate($py['created_at'])) ?></td>
            <td><?= e($py['plan_name']) ?></td>
            <td>৳<?= number_format((float)$py['amount'], 2) ?></td>
            <td><?= e(ucfirst($py['method'])) ?></td>
            <td><?= e($py['transaction_ref'] ?? '—') ?></td>
            <td><span class="badge <?= $py['status']==='completed'?'badge-green':($py['status']==='pending'?'badge-yellow':'badge-red') ?>"><?= ucfirst($py['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
