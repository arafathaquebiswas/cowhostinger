<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['superadmin']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/ceo/plans.php');
    }

    $action  = $_POST['action'] ?? '';
    $plan_id = (int)($_POST['plan_id'] ?? 0);

    // ── Update plan price + limits + features ─────────────────────────────────
    if ($action === 'update_price' && $plan_id > 0) {
        $plan_stmt = $db->prepare("SELECT * FROM plans WHERE id = ?");
        $plan_stmt->execute([$plan_id]);
        $plan = $plan_stmt->fetch();

        if (!$plan) {
            flashMessage('error', 'Plan not found.');
            redirect('/modules/ceo/plans.php');
        }

        if ($plan['name'] === 'Free') {
            flashMessage('error', 'The Free plan price cannot be changed.');
            redirect('/modules/ceo/plans.php');
        }

        $new_price        = max(0, (float)($_POST['price_monthly'] ?? $plan['price_monthly']));
        $new_billing      = (int)($_POST['billing_days'] ?? $plan['billing_days']);
        $cows_limit       = trim($_POST['cows_limit']       ?? '') === '' ? null : (int)$_POST['cows_limit'];
        $users_limit      = trim($_POST['users_limit']      ?? '') === '' ? null : (int)$_POST['users_limit'];
        $workers_limit    = trim($_POST['workers_limit']    ?? '') === '' ? null : (int)$_POST['workers_limit'];
        $equipment_limit  = trim($_POST['equipment_limit']  ?? '') === '' ? null : (int)$_POST['equipment_limit'];
        $feed_limit       = trim($_POST['feed_limit']       ?? '') === '' ? null : (int)$_POST['feed_limit'];
        $medicine_limit   = trim($_POST['medicine_limit']   ?? '') === '' ? null : (int)$_POST['medicine_limit'];
        $diagnosis_limit  = trim($_POST['diagnosis_limit']  ?? '') === '' ? null : (int)$_POST['diagnosis_limit'];
        $can_export       = isset($_POST['can_export'])         ? 1 : 0;
        $can_analytics    = isset($_POST['can_analytics'])      ? 1 : 0;
        $can_finance      = isset($_POST['can_finance'])        ? 1 : 0;
        $can_reports      = isset($_POST['can_reports'])        ? 1 : 0;
        $can_milk_anlytx  = isset($_POST['can_milk_analytics']) ? 1 : 0;
        $is_featured      = isset($_POST['is_featured'])        ? 1 : 0;
        $notes            = sanitize($_POST['notes'] ?? '');

        // Only one plan can be "Most Popular" — clear all others first
        if ($is_featured) {
            $db->prepare("UPDATE plans SET is_featured=0 WHERE id != ?")->execute([$plan_id]);
        }

        $db->prepare(
            "UPDATE plans SET
                price_monthly=?, billing_days=?,
                cows_limit=?, users_limit=?, workers_limit=?,
                equipment_limit=?, feed_limit=?, medicine_limit=?, diagnosis_limit=?,
                can_export=?, can_analytics=?, can_finance=?, can_reports=?, can_milk_analytics=?,
                is_featured=?
             WHERE id=?"
        )->execute([
            $new_price, $new_billing ?: null,
            $cows_limit, $users_limit, $workers_limit,
            $equipment_limit, $feed_limit, $medicine_limit, $diagnosis_limit,
            $can_export, $can_analytics, $can_finance, $can_reports, $can_milk_anlytx,
            $is_featured,
            $plan_id,
        ]);

        unset($GLOBALS['_sub_engine_cache']);

        auditLog($uid, 'CEO_PLAN_PRICE_CHANGE', 'plans', $plan_id,
            ['price_monthly' => $plan['price_monthly']],
            ['price_monthly' => $new_price, 'notes' => $notes]
        );

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
    }

    // ── Update offer / promotional price ──────────────────────────────────────
    elseif ($action === 'update_offer' && $plan_id > 0) {
        $plan_stmt = $db->prepare("SELECT * FROM plans WHERE id = ?");
        $plan_stmt->execute([$plan_id]);
        $plan = $plan_stmt->fetch();

        if (!$plan || $plan['name'] === 'Free') {
            flashMessage('error', 'Cannot set offer on this plan.');
            redirect('/modules/ceo/plans.php');
        }

        $offer_price  = trim($_POST['offer_price'] ?? '') === '' ? null : max(0, (float)$_POST['offer_price']);
        $offer_active = isset($_POST['offer_active']) ? 1 : 0;
        $offer_label  = sanitize(trim($_POST['offer_label'] ?? ''));
        $offer_label  = $offer_label !== '' ? $offer_label : null;
        $offer_end_raw = trim($_POST['offer_end'] ?? '');
        $offer_end     = ($offer_end_raw !== '' && strtotime($offer_end_raw)) ? $offer_end_raw : null;

        // Cannot activate offer without a price set
        if ($offer_active && $offer_price === null) {
            flashMessage('error', 'Set an offer price before activating the offer.');
            redirect('/modules/ceo/plans.php');
        }
        // Offer price must be less than regular price
        if ($offer_price !== null && $offer_price >= (float)$plan['price_monthly']) {
            flashMessage('error', 'Offer price must be lower than the regular price (৳' . number_format($plan['price_monthly'], 2) . ').');
            redirect('/modules/ceo/plans.php');
        }

        $db->prepare(
            "UPDATE plans SET offer_price=?, offer_active=?, offer_label=?, offer_end=? WHERE id=?"
        )->execute([$offer_price, $offer_active, $offer_label, $offer_end, $plan_id]);

        unset($GLOBALS['_sub_engine_cache']);

        auditLog($uid, 'CEO_PLAN_OFFER_CHANGE', 'plans', $plan_id,
            ['offer_price' => $plan['offer_price'], 'offer_active' => $plan['offer_active']],
            ['offer_price' => $offer_price, 'offer_active' => $offer_active, 'offer_label' => $offer_label]
        );

        $db->prepare(
            "INSERT INTO ceo_grants
             (farm_id, farm_name, granted_by, action_type, old_plan_id, new_plan_id,
              old_plan_name, new_plan_name, duration_label, notes)
             VALUES (0, 'GLOBAL PRICING', ?, 'plan_change', ?, ?, ?, ?, ?, ?)"
        )->execute([
            $uid, $plan_id, $plan_id,
            $plan['name'] . ' (offer off)',
            $plan['name'] . ($offer_active ? ' @ ৳' . number_format((float)$offer_price, 2) . ' OFFER' : ' (offer off)'),
            'Offer price update',
            $offer_label ?: null,
        ]);

        $label_part = $offer_label ? ' &mdash; "' . e($offer_label) . '"' : '';
        $end_part   = $offer_end   ? ' (expires ' . e($offer_end) . ')' : '';
        $msg = $offer_active
            ? 'Offer activated on <strong>' . e($plan['name']) . '</strong>: ৳'
              . number_format((float)$offer_price, 2) . '/month' . $label_part . $end_part . '.'
            : 'Offer removed from <strong>' . e($plan['name']) . '</strong>.';
        flashMessage('success', $msg);
    }

    // ── Toggle plan active/inactive ───────────────────────────────────────────
    elseif ($action === 'toggle_active' && $plan_id > 0) {
        $db->prepare("UPDATE plans SET is_active = NOT is_active WHERE id = ? AND name != 'Free'")->execute([$plan_id]);
        auditLog($uid, 'CEO_PLAN_TOGGLE', 'plans', $plan_id, null, null);
        flashMessage('success', 'Plan availability toggled.');
    }

    redirect('/modules/ceo/plans.php');
}

// ── Auto-expire offers whose offer_end has passed ────────────────────────────
$db->query("UPDATE plans SET offer_active=0 WHERE offer_active=1 AND offer_end IS NOT NULL AND offer_end < CURDATE()");

// ── Fetch all plans ───────────────────────────────────────────────────────────
$plans = $db->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id=p.id AND s.status IN ('active','trial')) AS active_subs
     FROM plans p ORDER BY p.price_monthly"
)->fetchAll();

// ── Price / offer change history ──────────────────────────────────────────────
$price_history = $db->query(
    "SELECT cg.*, u.name AS ceo_name
     FROM ceo_grants cg
     LEFT JOIN users u ON u.id = cg.granted_by
     WHERE cg.farm_name = 'GLOBAL PRICING'
     ORDER BY cg.created_at DESC LIMIT 30"
)->fetchAll();

$page_title = 'Plans & Pricing';
$active_nav = 'ceo_plans';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';

// Helper: effective display price
function effectivePrice(array $p): array {
    $has_offer = $p['offer_active'] && $p['offer_price'] !== null;
    return [
        'price'     => $has_offer ? (float)$p['offer_price'] : (float)$p['price_monthly'],
        'original'  => (float)$p['price_monthly'],
        'has_offer' => $has_offer,
        'savings'   => $has_offer ? ((float)$p['price_monthly'] - (float)$p['offer_price']) : 0,
        'pct_off'   => $has_offer && $p['price_monthly'] > 0
                       ? round((1 - $p['offer_price'] / $p['price_monthly']) * 100)
                       : 0,
    ];
}
?>

<div class="page-header">
    <div>
        <h2>Plans &amp; Pricing Manager</h2>
        <p class="text-muted text-sm">Change prices, set promotional offers, and configure plan limits — changes take effect immediately</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/ceo/index.php"         class="btn btn-secondary btn-sm">Control Center</a>
        <a href="/modules/ceo/subscriptions.php" class="btn btn-primary btn-sm">Subscription Manager</a>
    </div>
</div>

<div class="alert" style="background:#fefce8;border-color:#fde047;color:#854d0e;margin-bottom:1.25rem">
    <strong>CEO Only.</strong> Price changes apply to <em>new</em> subscriptions and renewals.
    Existing active subscriptions run until their current end_date.
    Offer prices are shown to customers as promotional discounts.
</div>

<!-- ── Plan Cards ──────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.25rem;margin-bottom:1.75rem">
<?php foreach ($plans as $p):
    $is_free = ($p['name'] === 'Free');
    $col = match($p['name']){
        'Free'=>'#6b7280','Basic'=>'#0284c7','Pro'=>'#7c3aed','Enterprise'=>'#d97706',default=>'#6b7280'
    };
    $ep = $is_free ? null : effectivePrice($p);
?>
<div class="card" style="border-top:3px solid <?= $col ?>">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start">
        <div>
            <span style="font-weight:800;font-size:1rem;color:<?= $col ?>"><?= e($p['name']) ?></span>
            <?php if (!$p['is_active']): ?>
                <span class="badge badge-gray" style="margin-left:.4rem;font-size:.7rem">Inactive</span>
            <?php endif; ?>
            <?php if (!$is_free && $ep['has_offer']): ?>
                <span style="background:#dcfce7;color:#166534;font-size:.7rem;font-weight:700;padding:.15rem .45rem;border-radius:99px;margin-left:.4rem">
                    OFFER LIVE
                </span>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <?php if ($is_free): ?>
                <div style="font-size:1.3rem;font-weight:800;color:<?= $col ?>">Free</div>
            <?php elseif ($ep['has_offer']): ?>
                <div style="font-size:.8rem;color:#6b7280;text-decoration:line-through">৳<?= number_format($ep['original'],2) ?></div>
                <div style="font-size:1.4rem;font-weight:800;color:#16a34a">৳<?= number_format($ep['price'],2) ?></div>
                <div style="font-size:.72rem;color:#16a34a;font-weight:600"><?= $ep['pct_off'] ?>% off&nbsp;·&nbsp;Save ৳<?= number_format($ep['savings'],2) ?></div>
                <?php if ($p['offer_label']): ?>
                <div style="font-size:.72rem;color:#0284c7;font-style:italic"><?= e($p['offer_label']) ?></div>
                <?php endif; ?>
                <?php if ($p['offer_end']): ?>
                <div style="font-size:.7rem;color:#dc2626">Expires <?= e($p['offer_end']) ?></div>
                <?php endif; ?>
            <?php else: ?>
                <div style="font-size:1.3rem;font-weight:800;color:<?= $col ?>">৳<?= number_format($p['price_monthly'],2) ?></div>
                <div class="text-xs text-muted">/month</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body" style="padding:.75rem 1rem">
        <!-- Current stats grid -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.3rem .6rem;margin-bottom:.85rem;font-size:.8rem">
            <div style="color:var(--text-secondary)">
                <span style="font-size:.9rem">&#x1F4CA;</span> Active Subs:
                <strong style="color:var(--text-primary)"><?= $p['active_subs'] ?></strong>
            </div>
            <div style="color:var(--text-secondary)">
                <span style="font-size:.9rem">&#x1F404;</span> Cows:
                <strong style="color:var(--text-primary)"><?= $p['cows_limit'] ?? '<span style="color:#6b7280">&#x221E;</span>' ?></strong>
            </div>
            <div style="color:var(--text-secondary)">
                <span style="font-size:.9rem">&#x1F477;</span> Workers:
                <strong style="color:var(--text-primary)"><?= $p['workers_limit'] ?? '<span style="color:#6b7280">&#x221E;</span>' ?></strong>
            </div>
            <div style="color:var(--text-secondary)">
                <span style="font-size:.9rem">&#x2699;&#xFE0F;</span> Equipment:
                <strong style="color:var(--text-primary)"><?= $p['equipment_limit'] ?? '<span style="color:#6b7280">&#x221E;</span>' ?></strong>
            </div>
            <div style="color:var(--text-secondary)">
                <span style="font-size:.9rem">&#x1F33F;</span> Feed logs:
                <strong style="color:var(--text-primary)"><?= $p['feed_limit'] ?? '<span style="color:#6b7280">&#x221E;</span>' ?></strong>
            </div>
            <div style="color:var(--text-secondary)">
                <span style="font-size:.9rem">&#x1F489;</span> Medicine:
                <strong style="color:var(--text-primary)"><?= $p['medicine_limit'] ?? '<span style="color:#6b7280">&#x221E;</span>' ?></strong>
            </div>
            <div style="color:var(--text-secondary)">
                <span style="font-size:.9rem">&#x1F3E5;</span> Diagnosis:
                <strong style="color:var(--text-primary)"><?= $p['diagnosis_limit'] ?? '<span style="color:#6b7280">&#x221E;</span>' ?></strong>
            </div>
            <div style="color:var(--text-secondary)">
                <span style="font-size:.9rem">&#x1F465;</span> Users:
                <strong style="color:var(--text-primary)"><?= $p['users_limit'] ?? '<span style="color:#6b7280">&#x221E;</span>' ?></strong>
            </div>
        </div>
        <!-- Feature flags -->
        <div style="display:flex;flex-wrap:wrap;gap:.3rem .8rem;margin-bottom:.85rem;font-size:.79rem">
            <?php foreach ([
                ['can_finance',        'Finance Module'],
                ['can_reports',        'Reports'],
                ['can_export',         'Export Data'],
                ['can_analytics',      'Analytics'],
                ['can_milk_analytics', 'Milk Analytics'],
            ] as [$fkey, $flabel]):
                $on = (bool)$p[$fkey]; ?>
            <span style="color:<?= $on?'#16a34a':'#dc2626' ?>;font-weight:600">
                <?= $on?'&#x2713;':'&#x2717;' ?> <?= $flabel ?>
            </span>
            <?php endforeach; ?>
        </div>

        <?php if (!$is_free): ?>

        <!-- ── Tab toggles ── -->
        <div style="display:flex;gap:.25rem;margin-bottom:.75rem" id="tabs-<?= $p['id'] ?>">
            <button type="button" onclick="switchTab(<?= $p['id'] ?>,'price')"
                    id="tab-price-<?= $p['id'] ?>"
                    style="flex:1;font-size:.78rem;font-weight:600;padding:.3rem .5rem;border:1px solid var(--primary);border-radius:6px;background:var(--primary);color:#fff;cursor:pointer">
                Price &amp; Limits
            </button>
            <button type="button" onclick="switchTab(<?= $p['id'] ?>,'offer')"
                    id="tab-offer-<?= $p['id'] ?>"
                    style="flex:1;font-size:.78rem;font-weight:600;padding:.3rem .5rem;border:1px solid <?= $ep['has_offer']?'#16a34a':'var(--border)' ?>;border-radius:6px;background:<?= $ep['has_offer']?'#dcfce7':'#f9fafb' ?>;color:<?= $ep['has_offer']?'#166534':'var(--text-secondary)' ?>;cursor:pointer">
                <?= $ep['has_offer'] ? 'Offer Active' : 'Set Offer' ?>
            </button>
        </div>

        <!-- ── Price & Limits panel ── -->
        <div id="panel-price-<?= $p['id'] ?>" style="display:block">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="update_price">
            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">

            <div style="font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em">Price</div>
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
            </div>

            <div style="font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em">Limits <span style="font-weight:400;text-transform:none">(blank = unlimited)</span></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.75rem">
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">&#x1F404; Max Cows</label>
                    <input type="number" name="cows_limit" class="form-control form-control-sm"
                           value="<?= e($p['cows_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">&#x1F477; Max Workers</label>
                    <input type="number" name="workers_limit" class="form-control form-control-sm"
                           value="<?= e($p['workers_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">&#x2699;&#xFE0F; Max Equipment</label>
                    <input type="number" name="equipment_limit" class="form-control form-control-sm"
                           value="<?= e($p['equipment_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">&#x1F465; Max Users</label>
                    <input type="number" name="users_limit" class="form-control form-control-sm"
                           value="<?= e($p['users_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">&#x1F33F; Max Feed Logs</label>
                    <input type="number" name="feed_limit" class="form-control form-control-sm"
                           value="<?= e($p['feed_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">&#x1F489; Max Medicine</label>
                    <input type="number" name="medicine_limit" class="form-control form-control-sm"
                           value="<?= e($p['medicine_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">&#x1F3E5; Max Diagnosis</label>
                    <input type="number" name="diagnosis_limit" class="form-control form-control-sm"
                           value="<?= e($p['diagnosis_limit'] ?? '') ?>" min="1" placeholder="Unlimited">
                </div>
            </div>

            <div style="margin-bottom:.6rem">
                <div style="font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:.35rem;text-transform:uppercase;letter-spacing:.04em">Features</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.25rem;margin-bottom:.5rem">
                    <?php foreach ([
                        ['can_export',         'Export Data',    $p['can_export']],
                        ['can_analytics',      'Analytics',      $p['can_analytics']],
                        ['can_finance',        'Finance Module', $p['can_finance']],
                        ['can_reports',        'Reports',        $p['can_reports']],
                        ['can_milk_analytics', 'Milk Analytics', $p['can_milk_analytics']],
                    ] as [$fname,$flabel,$fval]): ?>
                    <label style="display:flex;align-items:center;gap:.35rem;font-size:.8rem;cursor:pointer">
                        <input type="checkbox" name="<?= $fname ?>" value="1" <?= $fval?'checked':'' ?>>
                        <?= $flabel ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.8rem;cursor:pointer;background:#fef9c3;border:1px solid #fde047;border-radius:6px;padding:.35rem .55rem">
                    <input type="checkbox" name="is_featured" value="1" <?= ($p['is_featured'] ?? 0) ? 'checked' : '' ?>>
                    <span style="font-weight:600;color:#854d0e">&#11088; "Most Popular" badge on customer page</span>
                    <span style="font-size:.7rem;color:#a16207">(clears others)</span>
                </label>
            </div>

            <div class="form-group" style="margin-bottom:.6rem">
                <label class="form-label" style="font-size:.78rem">Change Reason (audit log)</label>
                <input type="text" name="notes" class="form-control form-control-sm" placeholder="e.g. Q4 pricing adjustment">
            </div>

            <div style="display:flex;gap:.5rem;align-items:center">
                <button type="submit" class="btn btn-sm btn-primary">Save Changes</button>
                <span style="font-size:.75rem;color:var(--text-secondary)"><?= $p['active_subs'] ?> active subscriber<?= $p['active_subs']!==1?'s':'' ?></span>
            </div>
        </form>
        </div>

        <!-- ── Offer / Promotional Price panel ── -->
        <div id="panel-offer-<?= $p['id'] ?>" style="display:none">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="update_offer">
            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">

            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.8rem;margin-bottom:.75rem;font-size:.8rem;color:#166534">
                Regular price: <strong>৳<?= number_format($p['price_monthly'],2) ?>/month</strong> —
                offer price must be lower than this.
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.75rem">
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">Offer Price (৳) <span style="color:var(--danger)">*</span></label>
                    <input type="number" name="offer_price" class="form-control form-control-sm"
                           value="<?= e($p['offer_price'] ?? '') ?>" step="0.01" min="0"
                           placeholder="e.g. <?= number_format($p['price_monthly'] * 0.8, 0) ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label" style="font-size:.78rem">Offer Expires On</label>
                    <input type="date" name="offer_end" class="form-control form-control-sm"
                           value="<?= e($p['offer_end'] ?? '') ?>"
                           min="<?= date('Y-m-d') ?>">
                    <span class="text-xs text-muted">Leave blank = manual control</span>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:.75rem">
                <label class="form-label" style="font-size:.78rem">Offer Label</label>
                <input type="text" name="offer_label" class="form-control form-control-sm"
                       value="<?= e($p['offer_label'] ?? '') ?>"
                       placeholder="e.g. Eid Special – 20% Off" maxlength="100">
                <span class="text-xs text-muted">Shown to customers as the promotion name</span>
            </div>

            <div style="margin-bottom:.9rem">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;font-weight:600">
                    <input type="checkbox" name="offer_active" value="1"
                           <?= $p['offer_active'] ? 'checked' : '' ?>
                           style="width:16px;height:16px">
                    <span style="color:<?= $p['offer_active']?'#16a34a':'var(--text-secondary)' ?>">
                        <?= $p['offer_active'] ? 'Offer is LIVE — customers see the discounted price' : 'Activate this offer (make it live)' ?>
                    </span>
                </label>
            </div>

            <?php if ($p['offer_active'] && $p['offer_price'] !== null): ?>
            <div style="background:#dcfce7;border:1px solid #86efac;border-radius:6px;padding:.6rem .8rem;font-size:.8rem;color:#166534;margin-bottom:.75rem">
                Currently live: <strong>৳<?= number_format($p['offer_price'],2) ?>/month</strong>
                <?= $p['offer_label'] ? '— "' . e($p['offer_label']) . '"' : '' ?>
                <?= $p['offer_end'] ? '· expires ' . e($p['offer_end']) : '' ?>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn-sm btn-primary">Save Offer</button>
                <?php if ($p['offer_active'] || $p['offer_price'] !== null): ?>
                <button type="submit" name="offer_active" value="" onclick="
                    this.form.querySelector('[name=offer_price]').value='';
                    this.form.querySelector('[name=offer_label]').value='';
                    this.form.querySelector('[name=offer_end]').value='';
                " class="btn btn-sm btn-secondary" style="color:var(--danger)">Remove Offer</button>
                <?php endif; ?>
            </div>
        </form>
        </div>

        <?php else: ?>
        <p class="text-xs text-muted">Free plan pricing is fixed at ৳0 and cannot be changed.</p>
        <?php endif; ?>
    </div>

    <?php if (!$is_free): ?>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 1rem;font-size:.78rem">
        <span class="text-muted">Status: <?= $p['is_active'] ? '<span style="color:#16a34a;font-weight:600">Active</span>' : '<span style="color:#dc2626;font-weight:600">Inactive</span>' ?></span>
        <form method="POST" style="margin:0">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="toggle_active">
            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-xs btn-secondary"
                    onclick="return confirm('Toggle availability for <?= e($p['name']) ?> plan?')">
                <?= $p['is_active'] ? 'Deactivate' : 'Activate' ?>
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- ── Change History ──────────────────────────────────────────────────────── -->
<?php if (!empty($price_history)): ?>
<div class="card">
    <div class="card-header"><h3>Pricing &amp; Offer Change History</h3></div>
    <div style="overflow-x:auto">
        <table class="table" style="font-size:.82rem">
            <thead>
                <tr>
                    <th>Date &amp; Time</th>
                    <th>Plan</th>
                    <th>Before</th>
                    <th>After</th>
                    <th>Changed By</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($price_history as $h): ?>
            <tr>
                <td style="white-space:nowrap"><?= e(formatDateTime($h['created_at'])) ?></td>
                <td><strong><?= e(explode(' @', $h['new_plan_name'] ?? $h['old_plan_name'] ?? '')[0]) ?></strong></td>
                <td class="text-muted"><?= e($h['old_plan_name'] ?? '—') ?></td>
                <td style="font-weight:600;color:#0284c7"><?= e($h['new_plan_name'] ?? '—') ?></td>
                <td style="color:#7c3aed">&#x1F451; <?= e($h['ceo_name'] ?? 'CEO') ?></td>
                <td><?= e($h['notes'] ?: '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function switchTab(planId, tab) {
    ['price','offer'].forEach(function(t) {
        var panel = document.getElementById('panel-' + t + '-' + planId);
        var btn   = document.getElementById('tab-' + t + '-' + planId);
        if (!panel || !btn) return;
        var active = (t === tab);
        panel.style.display = active ? 'block' : 'none';
        if (active) {
            btn.style.background = 'var(--primary)';
            btn.style.color = '#fff';
            btn.style.borderColor = 'var(--primary)';
        } else {
            btn.style.background = '#f9fafb';
            btn.style.color = 'var(--text-secondary)';
            btn.style.borderColor = 'var(--border)';
        }
    });
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
