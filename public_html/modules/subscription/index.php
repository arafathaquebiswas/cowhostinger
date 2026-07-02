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

// Auto-migrate plans table columns if not yet run
(function(PDO $db) {
    $existing = array_column($db->query("SHOW COLUMNS FROM plans")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $needed = [
        "offer_price"  => "ALTER TABLE plans ADD COLUMN offer_price  DECIMAL(10,2) DEFAULT NULL AFTER price_monthly",
        "offer_active" => "ALTER TABLE plans ADD COLUMN offer_active TINYINT(1) NOT NULL DEFAULT 0 AFTER offer_price",
        "offer_label"  => "ALTER TABLE plans ADD COLUMN offer_label  VARCHAR(100) DEFAULT NULL AFTER offer_active",
        "offer_end"    => "ALTER TABLE plans ADD COLUMN offer_end    DATE DEFAULT NULL AFTER offer_label",
        "is_featured"  => "ALTER TABLE plans ADD COLUMN is_featured  TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active",
        "can_payroll"  => "ALTER TABLE plans ADD COLUMN can_payroll  TINYINT(1) NOT NULL DEFAULT 0 AFTER can_milk_analytics",
    ];
    foreach ($needed as $col => $sql) {
        if (!in_array($col, $existing)) {
            try { $db->exec($sql); } catch (Throwable $e) { error_log("[plans migration] $col: " . $e->getMessage()); }
        }
    }
})($db);

// Load all available plans
$all_plans = $db->query("SELECT * FROM plans WHERE is_active=1 ORDER BY price_monthly ASC")->fetchAll();

// Load current subscription
$sub_stmt = $db->prepare(
    "SELECT s.*, p.name AS plan_name FROM subscriptions s
     JOIN plans p ON p.id = s.plan_id
     WHERE s.farm_id = ?
     ORDER BY s.id DESC LIMIT 1"
);
$sub_stmt->execute([$fid]);
$subscription = $sub_stmt->fetch();

// ── PAYMENT REQUEST HANDLER ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payment_request') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request. Please try again.');
        redirect('/modules/subscription/index.php');
    }

    $plan_id  = (int)($_POST['plan_id']         ?? 0);
    $method   = $_POST['method']                 ?? '';
    $txn_ref  = trim($_POST['transaction_ref']   ?? '');
    $months   = max(1, min(12, (int)($_POST['months'] ?? 1)));
    $note     = trim(substr($_POST['note'] ?? '', 0, 500));
    $uid      = (int)currentUser()['id'];

    $allowed_methods = ['bkash', 'nagad', 'rocket', 'bank'];
    $plan_row = null;
    foreach ($all_plans as $p) {
        if ((int)$p['id'] === $plan_id && $p['price_monthly'] > 0) {
            $plan_row = $p;
            break;
        }
    }

    $errors = [];
    if (!$plan_row)                              $errors[] = 'Invalid plan selected.';
    if (!in_array($method, $allowed_methods, true)) $errors[] = 'Invalid payment method.';
    if ($txn_ref === '')                         $errors[] = 'Transaction ID is required.';
    if (strlen($txn_ref) < 4)                   $errors[] = 'Transaction ID is too short.';

    // Screenshot upload (required)
    $screenshot_path = null;
    if (!isset($_FILES['screenshot']) || $_FILES['screenshot']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Payment screenshot is required.';
    } elseif ($_FILES['screenshot']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Screenshot upload failed. Please try again.';
    } else {
        $screenshot_path = uploadImage($_FILES['screenshot'], 'payments');
        if (!$screenshot_path) {
            $errors[] = 'Screenshot must be a valid image (JPG/PNG/WEBP, max 5 MB).';
        }
    }

    // Check no pending request already exists for this farm + plan
    if (empty($errors)) {
        $existing = $db->prepare(
            "SELECT id FROM payments WHERE farm_id=? AND plan_id=? AND status='pending' LIMIT 1"
        );
        $existing->execute([$fid, $plan_id]);
        if ($existing->fetch()) {
            $errors[] = 'You already have a pending payment request for this plan. Please wait for CEO approval.';
        }
    }

    // Global flood guard — cap total pending requests per farm across all plans
    if (empty($errors)) {
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM payments WHERE farm_id=? AND status='pending'");
        $count_stmt->execute([$fid]);
        if ((int)$count_stmt->fetchColumn() >= 3) {
            $errors[] = 'You have too many pending payment requests. Please wait for existing requests to be reviewed.';
        }
    }

    if (!empty($errors)) {
        flashMessage('error', implode(' ', $errors));
        redirect('/modules/subscription/index.php#request-form');
    }

    $effective_price = ($plan_row['offer_active'] && $plan_row['offer_price'] > 0)
                       ? (float)$plan_row['offer_price']
                       : (float)$plan_row['price_monthly'];

    // Coupon validation
    $coupon_code     = strtoupper(trim($_POST['coupon_code'] ?? ''));
    $coupon_id       = null;
    $coupon_discount = null;
    if ($coupon_code !== '') {
        $tables = array_column($db->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
        if (in_array('coupons', $tables)) {
            $cstmt = $db->prepare("SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1");
            $cstmt->execute([$coupon_code]);
            $coupon = $cstmt->fetch();
            if ($coupon
                && (!$coupon['expires_at'] || $coupon['expires_at'] >= date('Y-m-d'))
                && ($coupon['max_uses'] === null || $coupon['used_count'] < $coupon['max_uses'])
                && ($coupon['plan_id'] === null || (int)$coupon['plan_id'] === $plan_id)
            ) {
                $coupon_id = (int)$coupon['id'];
                if ($coupon['discount_type'] === 'percent') {
                    $coupon_discount = round($effective_price * $months * $coupon['discount_value'] / 100, 2);
                } else {
                    $coupon_discount = min((float)$coupon['discount_value'], $effective_price * $months);
                }
            }
        }
    }

    $amount = max(0, ($effective_price * $months) - (float)($coupon_discount ?? 0));

    // Check payments table has coupon columns
    $pay_cols = array_column($db->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $has_coupon_cols = in_array('coupon_id', $pay_cols);

    if ($has_coupon_cols) {
        $db->prepare(
            "INSERT INTO payments (farm_id, plan_id, amount, currency, method, transaction_ref,
                                   status, months, notes, coupon_id, coupon_code, coupon_discount,
                                   screenshot_path, created_at)
             VALUES (?, ?, ?, 'BDT', ?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW())"
        )->execute([$fid, $plan_id, $amount, $method, $txn_ref, $months, $note ?: null,
                    $coupon_id, $coupon_code ?: null, $coupon_discount, $screenshot_path]);
    } else {
        $db->prepare(
            "INSERT INTO payments (farm_id, plan_id, amount, currency, method, transaction_ref,
                                   status, months, notes, screenshot_path, created_at)
             VALUES (?, ?, ?, 'BDT', ?, ?, 'pending', ?, ?, ?, NOW())"
        )->execute([$fid, $plan_id, $amount, $method, $txn_ref, $months, $note ?: null, $screenshot_path]);
    }

    // Increment coupon usage
    if ($coupon_id) {
        $db->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id=?")->execute([$coupon_id]);
    }

    auditLog($uid, 'PAYMENT_REQUEST', 'payments', (int)$db->lastInsertId(), null, [
        'plan' => $plan_row['name'], 'amount' => $amount, 'method' => $method
    ]);

    flashMessage('success', 'Payment request submitted! AB IT will review and activate your plan within 24 hours.');
    redirect('/modules/subscription/index.php');
}

// Load payment history (includes pending requests)
$pay_stmt = $db->prepare(
    "SELECT py.*, p.name AS plan_name FROM payments py
     JOIN plans p ON p.id = py.plan_id
     WHERE py.farm_id = ?
     ORDER BY py.created_at DESC LIMIT 20"
);
$pay_stmt->execute([$fid]);
$payments = $pay_stmt->fetchAll();

// Pending requests count (for UI)
$pending_requests = array_filter($payments, fn($p) => $p['status'] === 'pending');

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Subscription &amp; Plans</h2>
        <p class="text-sm text-muted">Manage your farm's subscription and upgrade your plan</p>
    </div>
</div>

<!-- Pending request notice -->
<?php if (!empty($pending_requests)): ?>
<div class="alert" style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
        <strong><?= count($pending_requests) ?> payment request<?= count($pending_requests) > 1 ? 's' : '' ?> pending review</strong>
        — AB IT will activate your plan within 24 hours after verification.
        <a href="#payment-history" style="color:#92400E;font-weight:600;margin-left:.5rem">View below ↓</a>
    </div>
</div>
<?php endif; ?>

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
                        ['Cows',       $usage['cows'],      $plan['cows_limit']      ?? null, '/modules/cows/index.php'],
                        ['Workers',    $usage['workers'],   $plan['workers_limit']   ?? null, '/modules/workers/index.php'],
                        ['Equipment',  $usage['equipment'], $plan['equipment_limit'] ?? null, '/modules/equipment/index.php'],
                        ['Feed Items', $usage['feed'],      $plan['feed_limit']      ?? null, '/modules/feed_medicine/index.php?tab=feed'],
                        ['Medicine',   $usage['medicine'],  $plan['medicine_limit']  ?? null, '/modules/feed_medicine/index.php?tab=medicine'],
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
                <a href="mailto:arafathaquebiswas@gmail.com" class="btn btn-sm btn-danger">Contact Support</a>
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
                ['Finance Module',   $plan['can_finance']        ?? 0],
                ['Reports',         $plan['can_reports']        ?? 0],
                ['Milk Analytics',  $plan['can_milk_analytics'] ?? 0],
                ['Data Export',     $plan['can_export']         ?? 0],
                ['Advanced Charts', $plan['can_analytics']      ?? 0],
                ['Payroll Management', $plan['can_payroll']     ?? 0],
                ['Breeding Records', 1],
                ['Alerts',          1],
                ['Diagnosis',       1],
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
<?php foreach ($all_plans as $pl):
    $is_current  = ($plan['name'] ?? '') === $pl['name'];
    $has_offer   = !empty($pl['offer_active']) && !empty($pl['offer_price']) && (float)$pl['offer_price'] > 0;
    $disp_price  = $has_offer ? (float)$pl['offer_price'] : (float)$pl['price_monthly'];
    $is_featured = !empty($pl['is_featured']);
?>
<div class="card" style="border:2px solid <?= $is_current ? '#7C3AED' : ($is_featured ? '#7C3AED' : 'var(--border)') ?>;position:relative<?= $is_featured ? ';box-shadow:0 4px 20px rgba(124,58,237,.15)' : '' ?>">
    <?php if ($is_featured): ?>
    <div style="position:absolute;top:-1px;right:16px;background:#7C3AED;color:#fff;font-size:.65rem;font-weight:700;letter-spacing:.05em;padding:.2rem .6rem;border-radius:0 0 6px 6px;text-transform:uppercase">Most Popular</div>
    <?php endif; ?>
    <?php if ($has_offer && $pl['offer_label']): ?>
    <div style="position:absolute;top:-1px;left:12px;background:#16a34a;color:#fff;font-size:.62rem;font-weight:700;padding:.2rem .55rem;border-radius:0 0 6px 6px;text-transform:uppercase"><?= e($pl['offer_label']) ?></div>
    <?php endif; ?>
    <div class="card-body" style="padding:1.25rem<?= $is_featured ? ';padding-top:1.6rem' : '' ?>">
        <div style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:.25rem"><?= e($pl['name']) ?></div>

        <!-- Price display -->
        <?php if ($pl['price_monthly'] > 0): ?>
            <?php if ($has_offer): ?>
            <div style="font-size:.8rem;color:#9CA3AF;text-decoration:line-through;line-height:1">৳<?= number_format($pl['price_monthly']) ?></div>
            <div style="font-size:1.6rem;font-weight:800;color:#16a34a;margin-bottom:.05rem">
                ৳<?= number_format($disp_price) ?><span style="font-size:.75rem;font-weight:400;color:#4b7c59">/month</span>
            </div>
            <div style="font-size:.7rem;color:#16a34a;font-weight:600;margin-bottom:.1rem">
                Save ৳<?= number_format($pl['price_monthly'] - $disp_price) ?>/month
                <?= $pl['offer_end'] ? '&nbsp;· expires ' . e($pl['offer_end']) : '' ?>
            </div>
            <?php else: ?>
            <div style="font-size:1.6rem;font-weight:800;color:#111827;margin-bottom:.1rem">
                ৳<?= number_format($pl['price_monthly']) ?><span style="font-size:.75rem;font-weight:400;color:#6B7280">/month</span>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="font-size:1.6rem;font-weight:800;color:#059669;margin-bottom:.1rem">Free</div>
        <?php endif; ?>

        <div style="font-size:.72rem;color:#9CA3AF;margin-bottom:1rem">
            <?= $pl['billing_days'] ? $pl['billing_days'] . ' day billing cycle' : 'No expiry' ?>
        </div>
        <ul style="list-style:none;padding:0;margin:0 0 1.25rem;font-size:.8rem;color:#374151;display:flex;flex-direction:column;gap:.4rem">
            <li>&#x1F404; Cows: <strong><?= $pl['cows_limit'] ?? 'Unlimited' ?></strong></li>
            <li>&#x1F477; Workers: <strong><?= $pl['workers_limit'] ?? 'Unlimited' ?></strong></li>
            <li>&#x2699;&#xFE0F; Equipment: <strong><?= $pl['equipment_limit'] ?? 'Unlimited' ?></strong></li>
            <li style="color:<?= $pl['can_finance']        ? '#059669' : '#9CA3AF' ?>"><?= $pl['can_finance']        ? '&#x2713;' : '&#x2717;' ?> Finance Module</li>
            <li style="color:<?= $pl['can_reports']        ? '#059669' : '#9CA3AF' ?>"><?= $pl['can_reports']        ? '&#x2713;' : '&#x2717;' ?> Reports</li>
            <li style="color:<?= $pl['can_export']         ? '#059669' : '#9CA3AF' ?>"><?= $pl['can_export']         ? '&#x2713;' : '&#x2717;' ?> Export Data</li>
            <li style="color:<?= $pl['can_milk_analytics'] ? '#059669' : '#9CA3AF' ?>"><?= $pl['can_milk_analytics'] ? '&#x2713;' : '&#x2717;' ?> Milk Analytics</li>
            <li style="color:<?= ($pl['can_payroll'] ?? 0) ? '#059669' : '#9CA3AF' ?>"><?= ($pl['can_payroll'] ?? 0) ? '&#x2713;' : '&#x2717;' ?> Payroll Management</li>
        </ul>
        <?php if ($is_current): ?>
        <div class="btn btn-primary btn-block" style="background:#7C3AED;border:none;text-align:center;cursor:default">Current Plan</div>
        <?php elseif ($pl['price_monthly'] > 0): ?>
        <button type="button" class="btn btn-primary btn-block"
                style="background:linear-gradient(135deg,<?= $is_featured ? '#7C3AED,#A855F7' : '#374151,#4B5563' ?>);border:none"
                onclick="openPaymentModal(<?= $pl['id'] ?>, '<?= e(addslashes($pl['name'])) ?>', <?= $disp_price ?>, <?= (float)$pl['price_monthly'] ?>)">
            Upgrade to <?= e($pl['name']) ?>
        </button>
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
                WhatsApp / Call: <strong>01316816333</strong> &nbsp;·&nbsp;
                Email: <strong>arafathaquebiswas@gmail.com</strong> &nbsp;·&nbsp;
                We accept bKash, Nagad, Rocket &amp; Bank Transfer
            </div>
        </div>
    </div>
</div>

<!-- Payment History -->
<div class="card" id="payment-history">
    <div class="card-header"><h4 class="card-title">Payment History</h4></div>
    <?php if (empty($payments)): ?>
    <div style="padding:2rem;text-align:center;color:#9CA3AF">No payments or requests yet.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr><th>Date</th><th>Plan</th><th>Amount</th><th>Months</th><th>Method</th><th>Transaction ID</th><th>Status</th><th>Screenshot</th></tr>
        </thead>
        <tbody>
        <?php foreach ($payments as $py): ?>
        <tr>
            <td class="text-xs text-muted"><?= e(formatDate($py['created_at'])) ?></td>
            <td><?= e($py['plan_name']) ?></td>
            <td style="font-weight:600">৳<?= number_format((float)$py['amount'], 2) ?></td>
            <td style="text-align:center"><?= (int)($py['months'] ?? 1) ?></td>
            <td><?= e(ucfirst($py['method'] ?? '—')) ?></td>
            <td class="text-xs text-muted"><?= e($py['transaction_ref'] ?? '—') ?></td>
            <td>
                <?php
                $sc = $py['status'];
                $badge = match($sc) {
                    'completed' => 'badge-green',
                    'pending'   => 'badge-orange',
                    'failed'    => 'badge-red',
                    default     => 'badge-gray',
                };
                $label = match($sc) {
                    'pending' => 'Awaiting Approval',
                    default   => ucfirst($sc),
                };
                ?>
                <span class="badge <?= $badge ?>"><?= $label ?></span>
            </td>
            <td>
                <?php if ($py['screenshot_path']): ?>
                <a href="<?= e($py['screenshot_path']) ?>" target="_blank"
                   style="font-size:.78rem;color:#7C3AED;font-weight:600">View</a>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Payment Request Modal ──────────────────────────────────────────────── -->
<div id="paymentModalOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9100;display:none;align-items:center;justify-content:center;padding:1rem">
<div style="background:#fff;border-radius:16px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">

    <div style="background:linear-gradient(135deg,#7C3AED,#A855F7);padding:1.25rem 1.5rem;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between">
        <div>
            <div style="color:#fff;font-size:1rem;font-weight:700" id="modalPlanName">Submit Payment</div>
            <div style="color:#E9D5FF;font-size:.8rem" id="modalPlanPrice"></div>
        </div>
        <button type="button" onclick="closePaymentModal()" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;font-size:1.1rem;line-height:1">&#x2715;</button>
    </div>

    <div style="padding:1.5rem">
        <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:.83rem;color:#166534">
            <strong>How it works:</strong> Send payment via bKash/Nagad/Rocket/Bank, then fill in your transaction details and upload a screenshot. AB IT will verify and activate your plan within 24 hours.
        </div>

        <form method="POST" action="/modules/subscription/index.php" enctype="multipart/form-data" id="paymentRequestForm" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="payment_request">
            <input type="hidden" name="plan_id" id="modalPlanId">

            <!-- Method -->
            <div class="form-group">
                <label class="form-label">Payment Method <span style="color:#DC2626">*</span></label>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem">
                    <?php foreach (['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket', 'bank' => 'Bank'] as $val => $lbl): ?>
                    <label style="border:2px solid var(--border);border-radius:8px;padding:.6rem .4rem;text-align:center;cursor:pointer;font-size:.78rem;font-weight:600;transition:.15s" id="method-<?= $val ?>">
                        <input type="radio" name="method" value="<?= $val ?>" style="display:none" onchange="selectMethod('<?= $val ?>')">
                        <?= $lbl ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Transaction ID -->
            <div class="form-group">
                <label class="form-label">Transaction ID / Reference <span style="color:#DC2626">*</span></label>
                <input type="text" name="transaction_ref" class="form-control"
                       placeholder="e.g. 8N7A2K3P5Q" maxlength="100" required>
                <span class="text-xs text-muted">The transaction ID you received after payment</span>
            </div>

            <!-- Months + calculated amount -->
            <div class="form-group">
                <label class="form-label">Subscription Duration</label>
                <div style="display:flex;gap:.75rem;align-items:center">
                    <select name="months" class="form-control" style="max-width:140px" onchange="updateAmount()">
                        <option value="1">1 Month</option>
                        <option value="2">2 Months</option>
                        <option value="3">3 Months</option>
                        <option value="6">6 Months</option>
                        <option value="12">12 Months</option>
                    </select>
                    <div style="font-size:.9rem;color:#374151">
                        Total: <strong id="totalAmount" style="color:#059669;font-size:1rem"></strong>
                    </div>
                </div>
            </div>

            <!-- Screenshot -->
            <div class="form-group">
                <label class="form-label">Payment Screenshot <span style="color:#DC2626">*</span></label>
                <div id="screenshotDrop" style="border:2px dashed var(--border);border-radius:8px;padding:1.5rem;text-align:center;cursor:pointer;transition:.15s;background:var(--bg-muted)" onclick="document.getElementById('screenshotInput').click()">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="2" style="margin:0 auto .5rem;display:block"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <div style="font-size:.83rem;color:#6B7280" id="screenshotLabel">Click to upload screenshot<br><span style="font-size:.72rem">JPG, PNG or WEBP — max 5 MB</span></div>
                </div>
                <input type="file" name="screenshot" id="screenshotInput" accept="image/jpeg,image/png,image/webp"
                       style="display:none" onchange="previewScreenshot(this)">
                <img id="screenshotPreview" src="" alt="Preview" style="display:none;margin-top:.75rem;max-width:100%;max-height:200px;border-radius:8px;border:1px solid var(--border)">
            </div>

            <!-- Coupon code -->
            <div class="form-group">
                <label class="form-label">Coupon Code <span style="color:#9CA3AF;font-weight:400">(optional)</span></label>
                <div style="display:flex;gap:.5rem">
                    <input type="text" name="coupon_code" id="couponCodeField" class="form-control"
                           placeholder="Enter code" maxlength="32"
                           style="text-transform:uppercase;font-family:monospace;font-weight:700;letter-spacing:.05em;flex:1"
                           oninput="resetCoupon()">
                    <button type="button" onclick="applyCoupon()"
                            style="padding:.5rem .9rem;border:1px solid #7c3aed;border-radius:8px;background:#f5f3ff;color:#7c3aed;font-weight:700;font-size:.82rem;cursor:pointer;white-space:nowrap">
                        Apply
                    </button>
                </div>
                <div id="couponMsg" style="margin-top:.35rem;font-size:.8rem;display:none"></div>
            </div>

            <!-- Optional note -->
            <div class="form-group">
                <label class="form-label">Note to AB IT <span style="color:#9CA3AF;font-weight:400">(optional)</span></label>
                <textarea name="note" class="form-control" rows="2" maxlength="500"
                          placeholder="e.g. Paid via wife's bKash number 01700-XXXXXX"></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block" id="submitPaymentBtn"
                    style="background:linear-gradient(135deg,#7C3AED,#A855F7);border:none;margin-top:.25rem">
                Submit Payment Request
            </button>
        </form>
    </div>
</div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>

<script>
var _planPrice   = 0;
var _planId      = 0;
var _couponDisc  = 0;   // absolute discount in ৳

function openPaymentModal(planId, planName, effectivePrice, originalPrice) {
    _planPrice  = effectivePrice;
    _planId     = planId;
    _couponDisc = 0;
    document.getElementById('modalPlanId').value = planId;
    document.getElementById('modalPlanName').textContent = 'Upgrade to ' + planName;
    var priceEl = document.getElementById('modalPlanPrice');
    if (originalPrice && originalPrice !== effectivePrice) {
        priceEl.innerHTML = '<span style="text-decoration:line-through;opacity:.6">৳' + Math.round(originalPrice).toLocaleString() + '</span>'
                          + ' <span style="color:#bbf7d0;font-weight:700">৳' + Math.round(effectivePrice).toLocaleString() + '/month (Offer)</span>';
    } else {
        priceEl.textContent = '৳' + Math.round(effectivePrice).toLocaleString() + '/month';
    }
    // Reset coupon UI
    document.getElementById('couponCodeField').value = '';
    resetCoupon();
    updateAmount();
    document.getElementById('paymentModalOverlay').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closePaymentModal() {
    document.getElementById('paymentModalOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

function updateAmount() {
    var months = parseInt(document.querySelector('[name="months"]').value || 1);
    var base   = _planPrice * months;
    var disc   = _couponDisc;  // already absolute
    var total  = Math.max(0, base - disc);
    var el     = document.getElementById('totalAmount');
    if (disc > 0) {
        el.innerHTML = '<span style="text-decoration:line-through;color:#9ca3af;font-size:.85rem">৳' + base.toLocaleString('en-BD') + '</span>'
                     + ' <span style="color:#16a34a">৳' + total.toLocaleString('en-BD') + '</span>';
    } else {
        el.textContent = '৳' + total.toLocaleString('en-BD');
    }
}

function applyCoupon() {
    var code = document.getElementById('couponCodeField').value.trim().toUpperCase();
    var msg  = document.getElementById('couponMsg');
    if (!code) { showCouponMsg('Enter a coupon code first.', false); return; }
    msg.style.display = 'block';
    msg.textContent   = 'Checking…';
    msg.style.color   = '#6b7280';

    fetch('/modules/subscription/validate_coupon.php?code=' + encodeURIComponent(code) + '&plan_id=' + _planId)
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.ok) {
                var months = parseInt(document.querySelector('[name="months"]').value || 1);
                var base   = _planPrice * months;
                if (d.discount_type === 'percent') {
                    _couponDisc = Math.round(base * d.discount_value) / 100;
                } else {
                    _couponDisc = Math.min(d.discount_value, base);
                }
                showCouponMsg('✓ ' + d.message, true);
                updateAmount();
            } else {
                _couponDisc = 0;
                showCouponMsg('✗ ' + d.message, false);
                updateAmount();
            }
        })
        .catch(function() {
            showCouponMsg('Could not validate coupon. Try again.', false);
        });
}

function resetCoupon() {
    _couponDisc = 0;
    var msg = document.getElementById('couponMsg');
    if (msg) { msg.style.display = 'none'; msg.textContent = ''; }
    updateAmount();
}

function showCouponMsg(text, ok) {
    var msg = document.getElementById('couponMsg');
    msg.style.display = 'block';
    msg.textContent   = text;
    msg.style.color   = ok ? '#15803d' : '#b91c1c';
    msg.style.fontWeight = '600';
}

function selectMethod(val) {
    document.querySelectorAll('[id^="method-"]').forEach(function(el) {
        el.style.borderColor = 'var(--border)';
        el.style.background  = '';
        el.style.color       = '';
    });
    var chosen = document.getElementById('method-' + val);
    if (chosen) {
        chosen.style.borderColor = '#7C3AED';
        chosen.style.background  = '#F5F3FF';
        chosen.style.color       = '#7C3AED';
    }
    document.querySelector('[name="method"][value="' + val + '"]').checked = true;
}

function previewScreenshot(input) {
    if (!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var img = document.getElementById('screenshotPreview');
        img.src = e.target.result;
        img.style.display = 'block';
        document.getElementById('screenshotLabel').textContent = input.files[0].name;
        document.getElementById('screenshotDrop').style.borderColor = '#7C3AED';
    };
    reader.readAsDataURL(input.files[0]);
}

// Close modal when clicking backdrop
document.getElementById('paymentModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePaymentModal();
});

// Pre-fill amount on page load
document.addEventListener('DOMContentLoaded', updateAmount);
</script>
