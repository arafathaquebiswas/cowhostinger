<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['superadmin']);

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// Auto-migrate: create coupons table + payments columns if missing
(function(PDO $db) {
    $tables = array_column($db->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
    if (!in_array('coupons', $tables)) {
        $db->exec("CREATE TABLE IF NOT EXISTS `coupons` (
            `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `code`           VARCHAR(32) NOT NULL,
            `discount_type`  ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
            `discount_value` DECIMAL(10,2) NOT NULL,
            `plan_id`        INT UNSIGNED DEFAULT NULL,
            `max_uses`       INT UNSIGNED DEFAULT NULL,
            `used_count`     INT UNSIGNED NOT NULL DEFAULT 0,
            `expires_at`     DATE DEFAULT NULL,
            `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
            `created_by`     INT UNSIGNED NOT NULL,
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_code` (`code`),
            INDEX `idx_active` (`is_active`, `expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $pay_cols = array_column($db->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('coupon_id', $pay_cols))
        try { $db->exec("ALTER TABLE payments ADD COLUMN coupon_id INT UNSIGNED DEFAULT NULL AFTER notes"); } catch(Throwable $e){}
    if (!in_array('coupon_code', $pay_cols))
        try { $db->exec("ALTER TABLE payments ADD COLUMN coupon_code VARCHAR(32) DEFAULT NULL AFTER coupon_id"); } catch(Throwable $e){}
    if (!in_array('coupon_discount', $pay_cols))
        try { $db->exec("ALTER TABLE payments ADD COLUMN coupon_discount DECIMAL(10,2) DEFAULT NULL AFTER coupon_code"); } catch(Throwable $e){}
})($db);

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/ceo/coupons.php');
    }

    $action = $_POST['action'] ?? '';

    // Create coupon
    if ($action === 'create') {
        $code  = strtoupper(preg_replace('/[^A-Z0-9_\-]/i', '', trim($_POST['code'] ?? '')));
        $dtype = in_array($_POST['discount_type'] ?? '', ['percent','fixed']) ? $_POST['discount_type'] : 'percent';
        $dval  = max(0, (float)($_POST['discount_value'] ?? 0));
        $plan  = trim($_POST['plan_id'] ?? '') === '' ? null : (int)$_POST['plan_id'];
        $maxu  = trim($_POST['max_uses'] ?? '')    === '' ? null : (int)$_POST['max_uses'];
        $exp   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expires_at'] ?? '') ? $_POST['expires_at'] : null;

        if ($code === '') { flashMessage('error', 'Coupon code is required.'); redirect('/modules/ceo/coupons.php'); }
        if ($dval <= 0)   { flashMessage('error', 'Discount value must be greater than 0.'); redirect('/modules/ceo/coupons.php'); }
        if ($dtype === 'percent' && $dval > 100) { flashMessage('error', 'Percent discount cannot exceed 100.'); redirect('/modules/ceo/coupons.php'); }

        try {
            $db->prepare(
                "INSERT INTO coupons (code,discount_type,discount_value,plan_id,max_uses,expires_at,created_by)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([$code, $dtype, $dval, $plan, $maxu, $exp, $uid]);
            auditLog($uid, 'CEO_COUPON_CREATE', 'coupons', (int)$db->lastInsertId(), null,
                ['code'=>$code,'type'=>$dtype,'value'=>$dval]);
            flashMessage('success', "Coupon <strong>{$code}</strong> created.");
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate'))
                flashMessage('error', "Code <strong>{$code}</strong> already exists.");
            else
                flashMessage('error', 'Could not create coupon: ' . $e->getMessage());
        }
    }

    // Toggle active
    elseif ($action === 'toggle') {
        $cid = (int)($_POST['coupon_id'] ?? 0);
        $db->prepare("UPDATE coupons SET is_active = NOT is_active WHERE id=?")->execute([$cid]);
        auditLog($uid, 'CEO_COUPON_TOGGLE', 'coupons', $cid, null, null);
        flashMessage('success', 'Coupon status toggled.');
    }

    // Delete coupon (only if never used)
    elseif ($action === 'delete') {
        $cid = (int)($_POST['coupon_id'] ?? 0);
        $row = $db->prepare("SELECT code,used_count FROM coupons WHERE id=? LIMIT 1");
        $row->execute([$cid]);
        $coupon = $row->fetch();
        if (!$coupon) { flashMessage('error', 'Coupon not found.'); redirect('/modules/ceo/coupons.php'); }
        if ($coupon['used_count'] > 0) { flashMessage('error', 'Cannot delete a coupon that has been used. Deactivate it instead.'); redirect('/modules/ceo/coupons.php'); }
        $db->prepare("DELETE FROM coupons WHERE id=? AND used_count=0")->execute([$cid]);
        auditLog($uid, 'CEO_COUPON_DELETE', 'coupons', $cid, ['code'=>$coupon['code']], null);
        flashMessage('success', 'Coupon deleted.');
    }

    redirect('/modules/ceo/coupons.php');
}

// ── Data ──────────────────────────────────────────────────────────────────────
$plans   = $db->query("SELECT id, name FROM plans ORDER BY price_monthly")->fetchAll();
$coupons = $db->query(
    "SELECT c.*, p.name AS plan_name, u.name AS creator_name
     FROM coupons c
     LEFT JOIN plans p ON p.id = c.plan_id
     LEFT JOIN users u ON u.id = c.created_by
     ORDER BY c.created_at DESC"
)->fetchAll();

$page_title = 'Coupon Codes';
$active_nav = 'ceo_coupons';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Coupon Codes</h2>
        <p class="text-muted text-sm">Create and manage discount coupons for customers</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/ceo/plans.php"         class="btn btn-secondary btn-sm">Plans &amp; Pricing</a>
        <a href="/modules/ceo/subscriptions.php" class="btn btn-primary btn-sm">Subscription Manager</a>
    </div>
</div>

<!-- ── Create form ─────────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><h3>Create New Coupon</h3></div>
    <div class="card-body">
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;margin-bottom:.75rem">

            <div class="form-group" style="margin:0">
                <label class="form-label">Code <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:.35rem">
                    <input type="text" name="code" id="couponCodeInput" class="form-control form-control-sm"
                           placeholder="e.g. EID25" maxlength="32" required
                           style="text-transform:uppercase;font-family:monospace;font-weight:700;letter-spacing:.05em">
                    <button type="button" onclick="genCode()" title="Generate random code"
                            style="padding:.3rem .55rem;border:1px solid var(--border);border-radius:6px;background:#f9fafb;cursor:pointer;font-size:.8rem">&#x1F3B2;</button>
                </div>
            </div>

            <div class="form-group" style="margin:0">
                <label class="form-label">Discount Type <span style="color:var(--danger)">*</span></label>
                <select name="discount_type" class="form-control form-control-sm" onchange="updateDiscountLabel(this)">
                    <option value="percent">Percentage (%)</option>
                    <option value="fixed">Fixed Amount (৳)</option>
                </select>
            </div>

            <div class="form-group" style="margin:0">
                <label class="form-label" id="discountValLabel">Discount % <span style="color:var(--danger)">*</span></label>
                <input type="number" name="discount_value" class="form-control form-control-sm"
                       placeholder="e.g. 20" step="0.01" min="0.01" max="100" required>
            </div>

            <div class="form-group" style="margin:0">
                <label class="form-label">Valid For Plan</label>
                <select name="plan_id" class="form-control form-control-sm">
                    <option value="">All Plans</option>
                    <?php foreach ($plans as $pl): ?>
                    <option value="<?= $pl['id'] ?>"><?= e($pl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0">
                <label class="form-label">Max Uses</label>
                <input type="number" name="max_uses" class="form-control form-control-sm"
                       placeholder="Unlimited" min="1">
            </div>

            <div class="form-group" style="margin:0">
                <label class="form-label">Expires On</label>
                <input type="date" name="expires_at" class="form-control form-control-sm"
                       min="<?= date('Y-m-d') ?>">
            </div>

        </div>

        <button type="submit" class="btn btn-primary btn-sm">Create Coupon</button>
    </form>
    </div>
</div>

<!-- ── Coupons list ────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3>All Coupons <span style="font-size:.8rem;font-weight:400;color:var(--text-secondary)">(<?= count($coupons) ?>)</span></h3>
    </div>
    <?php if (empty($coupons)): ?>
    <div style="padding:2rem;text-align:center;color:var(--text-secondary)">No coupons yet. Create one above.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table" style="font-size:.83rem">
        <thead>
            <tr>
                <th>Code</th>
                <th>Discount</th>
                <th>Plan</th>
                <th>Uses</th>
                <th>Expires</th>
                <th>Status</th>
                <th>Created By</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($coupons as $c):
            $expired = $c['expires_at'] && $c['expires_at'] < date('Y-m-d');
            $exhausted = $c['max_uses'] !== null && $c['used_count'] >= $c['max_uses'];
            $live = $c['is_active'] && !$expired && !$exhausted;
        ?>
        <tr style="opacity:<?= $live ? 1 : .6 ?>">
            <td>
                <code style="background:#f3f4f6;padding:.2rem .5rem;border-radius:5px;font-size:.85rem;font-weight:700;letter-spacing:.05em;border:1px solid #e5e7eb;cursor:pointer;user-select:all"
                      onclick="navigator.clipboard.writeText(this.textContent)" title="Click to copy">
                    <?= e($c['code']) ?>
                </code>
            </td>
            <td>
                <?php if ($c['discount_type'] === 'percent'): ?>
                <span style="font-weight:700;color:#7c3aed"><?= number_format($c['discount_value'], 0) ?>% off</span>
                <?php else: ?>
                <span style="font-weight:700;color:#0284c7">৳<?= number_format($c['discount_value'], 2) ?> off</span>
                <?php endif; ?>
            </td>
            <td><?= $c['plan_name'] ? e($c['plan_name']) : '<span style="color:var(--text-secondary)">All plans</span>' ?></td>
            <td>
                <?= $c['used_count'] ?>
                <?= $c['max_uses'] ? '/ ' . $c['max_uses'] : '<span style="color:var(--text-secondary)"> / ∞</span>' ?>
            </td>
            <td>
                <?php if ($c['expires_at']): ?>
                <span style="color:<?= $expired ? '#dc2626' : '#374151' ?>"><?= e($c['expires_at']) ?></span>
                <?php else: ?>
                <span style="color:var(--text-secondary)">Never</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$c['is_active']): ?>
                <span style="background:#f1f5f9;color:#475569;font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:99px">Disabled</span>
                <?php elseif ($expired): ?>
                <span style="background:#fee2e2;color:#b91c1c;font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:99px">Expired</span>
                <?php elseif ($exhausted): ?>
                <span style="background:#fef3c7;color:#b45309;font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:99px">Exhausted</span>
                <?php else: ?>
                <span style="background:#dcfce7;color:#15803d;font-size:.72rem;font-weight:700;padding:.2rem .55rem;border-radius:99px">&#x2713; Live</span>
                <?php endif; ?>
            </td>
            <td style="color:var(--text-secondary)"><?= e($c['creator_name'] ?? '—') ?></td>
            <td>
                <div style="display:flex;gap:.35rem">
                    <form method="POST" style="margin:0">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"    value="toggle">
                        <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                        <button class="btn btn-xs btn-secondary"><?= $c['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <?php if ($c['used_count'] == 0): ?>
                    <form method="POST" style="margin:0"
                          onsubmit="return confirm('Delete coupon <?= e(addslashes($c['code'])) ?>? This cannot be undone.')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"    value="delete">
                        <input type="hidden" name="coupon_id" value="<?= $c['id'] ?>">
                        <button class="btn btn-xs" style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5">Delete</button>
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

<script>
function genCode() {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    var code  = '';
    for (var i = 0; i < 8; i++) code += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('couponCodeInput').value = code;
}
function updateDiscountLabel(sel) {
    var lbl   = document.getElementById('discountValLabel');
    var input = sel.closest('form').querySelector('[name="discount_value"]');
    if (sel.value === 'fixed') {
        lbl.innerHTML   = 'Discount ৳ <span style="color:var(--danger)">*</span>';
        input.max       = '';
        input.placeholder = 'e.g. 100';
    } else {
        lbl.innerHTML   = 'Discount % <span style="color:var(--danger)">*</span>';
        input.max       = '100';
        input.placeholder = 'e.g. 20';
    }
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
