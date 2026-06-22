<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireNotBlocked();
requireModule('cows');
requireRole(['admin', 'manager', 'accountant']);

$db = getDB();

// ── Inline auto-migration ─────────────────────────────────────────────────────
(function (PDO $db) {
    $tables = $db->query("SHOW TABLES LIKE 'cow_byproduct_sales'")->fetchAll();
    if (empty($tables)) {
        $db->exec("CREATE TABLE IF NOT EXISTS `cow_byproduct_sales` (
          `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
          `farm_id`        INT UNSIGNED  NOT NULL,
          `cow_id`         INT UNSIGNED  NOT NULL,
          `sale_type`      ENUM('skin','bones','fat','organs','dung','semen','breeding_service','other') NOT NULL,
          `description`    VARCHAR(255)  DEFAULT NULL,
          `quantity`       DECIMAL(10,2) NOT NULL DEFAULT 1.00,
          `unit`           VARCHAR(20)   NOT NULL DEFAULT 'unit',
          `price_per_unit` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `total_amount`   DECIMAL(12,2) NOT NULL,
          `buyer_name`     VARCHAR(150)  DEFAULT NULL,
          `sale_date`      DATE NOT NULL,
          `notes`          TEXT          DEFAULT NULL,
          `recorded_by`    INT UNSIGNED  NOT NULL,
          `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_farm_cow`  (`farm_id`, `cow_id`),
          KEY `idx_sale_type` (`sale_type`),
          KEY `idx_sale_date` (`sale_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
})($db);

// ── Load cow ──────────────────────────────────────────────────────────────────
$cow_id = (int)($_GET['cow_id'] ?? 0);
if ($cow_id <= 0) {
    flashMessage('error', 'Invalid cow ID.');
    redirect('/modules/cows/index.php');
}

$stmt = $db->prepare("SELECT * FROM cows WHERE id = ? AND " . farmFilter());
$stmt->execute([$cow_id]);
$cow = $stmt->fetch();
if (!$cow) {
    flashMessage('error', 'Cow not found.');
    redirect('/modules/cows/index.php');
}

if ($cow['status'] === 'sold') {
    flashMessage('info', 'This cow has already been sold.');
    redirect("/modules/cows/view.php?id={$cow_id}");
}

$uid     = (int)$_SESSION['user_id'];
$tab     = in_array($_GET['tab'] ?? '', ['whole','meat','skin','byproduct','dung','reproductive'], true)
           ? $_GET['tab'] : 'whole';
$errors  = [];
$success = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request. Please try again.');
        redirect("/modules/cows/sell.php?cow_id={$cow_id}&tab={$tab}");
    }

    $action = $_POST['action'] ?? '';
    $tab    = $_POST['tab']    ?? $tab;

    // ── Whole cow sale ────────────────────────────────────────────────────────
    if ($action === 'sell_whole') {
        $buyer_name = trim($_POST['buyer_name'] ?? '');
        $buyer_id   = (int)($_POST['buyer_id'] ?? 0) ?: null;
        $weight     = (float)($_POST['weight_at_sale'] ?? 0);
        $price      = (float)($_POST['sale_price'] ?? 0);
        $sale_date  = trim($_POST['sale_date'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');

        if ($buyer_name === '') $errors[] = 'Buyer name is required.';
        if ($price <= 0)        $errors[] = 'Sale price must be greater than zero.';
        if ($sale_date === '')  $errors[] = 'Sale date is required.';

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $db->prepare("INSERT INTO cow_sales
                    (farm_id, cow_id, buyer_name, buyer_id, weight_at_sale, sale_price, sale_date, notes, approved_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([fid(), $cow_id, $buyer_name, $buyer_id, $weight ?: null, $price, $sale_date, $notes ?: null, $uid]);
                $sale_id = $db->lastInsertId();

                $db->prepare("UPDATE cows SET status = 'sold' WHERE id = ? AND " . farmFilter())
                   ->execute([$cow_id]);

                $db->prepare("INSERT INTO finance_transactions
                    (farm_id, type, category, amount, related_module, reference_id, transaction_date, recorded_by, notes)
                    VALUES (?, 'income', 'Cow Sales', ?, 'cows', ?, ?, ?, ?)")
                   ->execute([fid(), $price, $sale_id, $sale_date, $uid, "Whole cow sale — {$buyer_name}" . ($notes ? "; {$notes}" : '')]);

                auditLog($uid, 'SELL_WHOLE', 'cows', $cow_id, ['status' => $cow['status']], ['status' => 'sold', 'sale_price' => $price]);
                $db->commit();

                flashMessage('success', "Cow #{$cow['tag_number']} sold to {$buyer_name} for " . number_format($price, 2) . " BDT.");
                redirect("/modules/cows/view.php?id={$cow_id}");
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[sell_whole] ' . $e->getMessage());
                $errors[] = 'Database error. Please try again.';
            }
        }
    }

    // ── Meat sale ─────────────────────────────────────────────────────────────
    elseif ($action === 'sell_meat') {
        $buyer_name  = trim($_POST['buyer_name'] ?? '');
        $kg_sold     = (float)($_POST['kg_sold'] ?? 0);
        $price_per_kg = (float)($_POST['price_per_kg'] ?? 0);
        $event_type  = in_array($_POST['event_type'] ?? '', ['regular','eid','gift'], true)
                       ? $_POST['event_type'] : 'regular';
        $sale_date   = trim($_POST['sale_date'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $total       = round($kg_sold * $price_per_kg, 2);

        if ($kg_sold <= 0)       $errors[] = 'Weight (kg) must be greater than zero.';
        if ($price_per_kg <= 0)  $errors[] = 'Price per kg must be greater than zero.';
        if ($sale_date === '')   $errors[] = 'Sale date is required.';

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $db->prepare("INSERT INTO meat_sales
                    (farm_id, cow_id, buyer_id, kg_sold, price_per_kg, total_revenue, event_type, sale_date, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([fid(), $cow_id, null, $kg_sold, $price_per_kg, $total, $event_type, $sale_date, $notes ?: null]);
                $sale_id = $db->lastInsertId();

                $db->prepare("UPDATE cows SET status = 'deceased' WHERE id = ? AND " . farmFilter())
                   ->execute([$cow_id]);

                $db->prepare("INSERT INTO finance_transactions
                    (farm_id, type, category, amount, related_module, reference_id, transaction_date, recorded_by, notes)
                    VALUES (?, 'income', 'Meat Sales', ?, 'cows', ?, ?, ?, ?)")
                   ->execute([fid(), $total, $sale_id, $sale_date, $uid, "{$kg_sold} kg @ {$price_per_kg} BDT/kg" . ($buyer_name ? " — {$buyer_name}" : '')]);

                auditLog($uid, 'SELL_MEAT', 'cows', $cow_id, ['status' => $cow['status']], ['status' => 'deceased', 'total' => $total]);
                $db->commit();

                flashMessage('success', "Meat sale recorded — {$kg_sold} kg sold for " . number_format($total, 2) . " BDT.");
                redirect("/modules/cows/view.php?id={$cow_id}");
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[sell_meat] ' . $e->getMessage());
                $errors[] = 'Database error. Please try again.';
            }
        }
    }

    // ── Byproduct sale (skin / bones / fat / organs / dung / semen / breeding) ─
    elseif ($action === 'sell_byproduct') {
        $byp_type   = in_array($_POST['byp_type'] ?? '', ['skin','bones','fat','organs','dung','semen','breeding_service','other'], true)
                      ? $_POST['byp_type'] : 'other';
        $tab        = $_POST['tab'] ?? $byp_type; // keep correct tab on error
        $description = trim($_POST['description'] ?? '');
        $quantity    = (float)($_POST['quantity'] ?? 0);
        $unit        = trim($_POST['unit'] ?? 'unit');
        $price_per_u = (float)($_POST['price_per_unit'] ?? 0);
        $buyer_name  = trim($_POST['buyer_name'] ?? '');
        $sale_date   = trim($_POST['sale_date'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');
        $total       = round($quantity * $price_per_u, 2);

        if ($quantity <= 0)    $errors[] = 'Quantity must be greater than zero.';
        if ($price_per_u < 0) $errors[] = 'Price cannot be negative.';
        if ($sale_date === '') $errors[] = 'Sale date is required.';

        $type_labels = [
            'skin'             => 'Skin Sale',
            'bones'            => 'Bones',
            'fat'              => 'Fat/Tallow',
            'organs'           => 'Organs',
            'dung'             => 'Dung/Manure',
            'semen'            => 'Semen',
            'breeding_service' => 'Breeding Service',
            'other'            => 'Other Byproduct',
        ];

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                $db->prepare("INSERT INTO cow_byproduct_sales
                    (farm_id, cow_id, sale_type, description, quantity, unit, price_per_unit, total_amount, buyer_name, sale_date, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([fid(), $cow_id, $byp_type, $description ?: null, $quantity, $unit, $price_per_u, $total, $buyer_name ?: null, $sale_date, $notes ?: null, $uid]);
                $sale_id = $db->lastInsertId();

                if ($total > 0) {
                    $db->prepare("INSERT INTO finance_transactions
                        (farm_id, type, category, amount, related_module, reference_id, transaction_date, recorded_by, notes)
                        VALUES (?, 'income', 'Byproduct Sales', ?, 'cows', ?, ?, ?, ?)")
                       ->execute([fid(), $total, $sale_id, $sale_date, $uid, ($type_labels[$byp_type] ?? $byp_type) . " — Cow #{$cow['tag_number']}"]);
                }

                auditLog($uid, 'SELL_BYPRODUCT', 'cow_byproduct_sales', $sale_id, [], ['type' => $byp_type, 'total' => $total]);
                $db->commit();

                flashMessage('success', ($type_labels[$byp_type] ?? 'Byproduct') . " sale recorded — " . number_format($total, 2) . " BDT.");
                redirect("/modules/cows/sell.php?cow_id={$cow_id}&tab={$tab}");
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[sell_byproduct] ' . $e->getMessage());
                $errors[] = 'Database error. Please try again.';
            }
        }
    }
}

// ── Load sale history for this cow ────────────────────────────────────────────
$whole_sales = $db->prepare("SELECT cs.*, u.name AS recorded_by_name
    FROM cow_sales cs
    LEFT JOIN users u ON u.id = cs.approved_by
    WHERE cs.cow_id = ? AND cs.farm_id = ? ORDER BY cs.sale_date DESC");
$whole_sales->execute([$cow_id, fid()]);
$whole_sales = $whole_sales->fetchAll();

$meat_sales_hist = $db->prepare("SELECT ms.*, u.name AS recorded_by_name
    FROM meat_sales ms
    LEFT JOIN users u ON u.id = ms.buyer_id
    WHERE ms.cow_id = ? AND ms.farm_id = ? ORDER BY ms.sale_date DESC");
$meat_sales_hist->execute([$cow_id, fid()]);
$meat_sales_hist = $meat_sales_hist->fetchAll();

$byp_sales = $db->prepare("SELECT bps.*, u.name AS recorded_by_name
    FROM cow_byproduct_sales bps
    LEFT JOIN users u ON u.id = bps.recorded_by
    WHERE bps.cow_id = ? AND bps.farm_id = ? ORDER BY bps.sale_date DESC");
$byp_sales->execute([$cow_id, fid()]);
$byp_sales = $byp_sales->fetchAll();

$today = date('Y-m-d');
$page_title = "Sell Options — Cow #{$cow['tag_number']}";
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>
<style>
.sell-hero{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.sell-hero-info{flex:1}
.sell-hero-info h2{font-size:1.25rem;font-weight:700;margin:0 0 .2rem}
.sell-hero-info p{margin:0;color:#6b7280;font-size:.875rem}
.sell-status-badge{padding:.25rem .75rem;border-radius:999px;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.status-active{background:#dcfce7;color:#166534}
.status-lactating{background:#dbeafe;color:#1e40af}
.status-dry{background:#fef9c3;color:#713f12}
.status-pregnant{background:#fce7f3;color:#9d174d}
.status-ready_for_sale{background:#fed7aa;color:#9a3412}
.status-deceased{background:#f3f4f6;color:#374151}
.sell-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem;border-bottom:2px solid #e5e7eb;padding-bottom:0}
.sell-tab{padding:.6rem 1.1rem;border:none;background:transparent;border-bottom:3px solid transparent;margin-bottom:-2px;cursor:pointer;font-size:.875rem;font-weight:500;color:#6b7280;border-radius:0;transition:color .15s}
.sell-tab:hover{color:#111827}
.sell-tab.active{color:#2563eb;border-bottom-color:#2563eb;font-weight:600}
.sell-tab.disabled{color:#d1d5db;cursor:not-allowed}
.sell-panel{display:none}.sell-panel.active{display:block}
.sell-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;margin-bottom:1rem}
.sell-card h3{margin:0 0 1rem;font-size:1rem;font-weight:600;color:#111827;display:flex;align-items:center;gap:.5rem}
.sell-card h3 .icon{width:20px;height:20px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}
.form-group{margin-bottom:1rem}
.form-group label{display:block;font-size:.875rem;font-weight:500;color:#374151;margin-bottom:.35rem}
.form-group label span.req{color:#ef4444}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:.55rem .75rem;border:1px solid #d1d5db;border-radius:8px;font-size:.875rem;transition:border-color .15s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.form-group .hint{font-size:.75rem;color:#6b7280;margin-top:.3rem}
.total-preview{background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.75rem 1rem;font-size:.95rem;font-weight:600;color:#166534;margin-bottom:1rem}
.history-table{width:100%;border-collapse:collapse;font-size:.85rem}
.history-table th{background:#f9fafb;padding:.5rem .75rem;text-align:left;font-weight:600;color:#374151;border-bottom:2px solid #e5e7eb}
.history-table td{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;color:#374151}
.history-table tr:last-child td{border-bottom:none}
.no-history{text-align:center;padding:2rem;color:#9ca3af;font-size:.875rem}
.warning-box{background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:.75rem 1rem;color:#92400e;font-size:.875rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.5rem}
.info-box{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.75rem 1rem;color:#1e40af;font-size:.875rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:.5rem}
</style>

<div class="page-header" style="margin-bottom:1.5rem">
    <div>
        <h1 class="page-title">Sell Options</h1>
        <p class="page-subtitle">Record a sale or byproduct income for this cow</p>
    </div>
    <a href="/modules/cows/view.php?id=<?= $cow_id ?>" class="btn btn-secondary">← Back to Cow</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1rem">
    <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Cow Hero -->
<div class="sell-hero">
    <div>
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
    </div>
    <div class="sell-hero-info">
        <h2>Cow #<?= e($cow['tag_number']) ?><?= $cow['name'] ? ' — ' . e($cow['name']) : '' ?></h2>
        <p>
            <?= e(ucfirst($cow['breed'] ?? 'Unknown breed')) ?>
            &nbsp;·&nbsp;
            <?= $cow['gender'] === 'male' ? 'Bull' : 'Heifer/Cow' ?>
            <?php if ($cow['birth_date']): ?>
            &nbsp;·&nbsp; Born <?= e(formatDate($cow['birth_date'])) ?>
            <?php endif; ?>
        </p>
    </div>
    <span class="sell-status-badge status-<?= e($cow['status']) ?>"><?= e(str_replace('_', ' ', $cow['status'])) ?></span>
</div>

<!-- Tab nav -->
<div class="sell-tabs" role="tablist">
    <?php
    $tabs_cfg = [
        'whole'        => ['label' => '🐄 Whole Cow',          'icon' => ''],
        'meat'         => ['label' => '🥩 Meat (per kg)',       'icon' => ''],
        'skin'         => ['label' => '🧴 Skin',                'icon' => ''],
        'byproduct'    => ['label' => '🦴 Byproducts',          'icon' => ''],
        'dung'         => ['label' => '♻️ Dung / Manure',       'icon' => ''],
        'reproductive' => ['label' => '🧬 Reproductive Services','icon' => ''],
    ];
    foreach ($tabs_cfg as $t => $cfg):
    ?>
    <button class="sell-tab <?= $tab === $t ? 'active' : '' ?>"
            data-tab="<?= $t ?>"
            role="tab"
            aria-selected="<?= $tab === $t ? 'true' : 'false' ?>">
        <?= $cfg['label'] ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: WHOLE COW
════════════════════════════════════════════════════════════════════════════ -->
<div class="sell-panel <?= $tab === 'whole' ? 'active' : '' ?>" id="panel-whole">
    <?php if (!in_array($cow['status'], ['sold','deceased'], true)): ?>
    <div class="sell-card">
        <h3>
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/></svg>
            Record Whole Cow Sale
        </h3>
        <?php if ($cow['status'] !== 'ready_for_sale'): ?>
        <div class="warning-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span>This cow is currently <strong><?= e(str_replace('_', ' ', $cow['status'])) ?></strong>, not "Ready for Sale". You can still record the sale but it's recommended to mark the cow for sale first.</span>
        </div>
        <?php endif; ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell_whole">
            <input type="hidden" name="tab" value="whole">
            <div class="form-row">
                <div class="form-group">
                    <label>Buyer Name <span class="req">*</span></label>
                    <input type="text" name="buyer_name" value="<?= e($_POST['buyer_name'] ?? '') ?>" placeholder="Enter buyer's full name" required maxlength="150">
                </div>
                <div class="form-group">
                    <label>Sale Price (BDT) <span class="req">*</span></label>
                    <input type="number" name="sale_price" id="wp_price" value="<?= e($_POST['sale_price'] ?? '') ?>" placeholder="0.00" min="0.01" step="0.01" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Weight at Sale (kg)</label>
                    <input type="number" name="weight_at_sale" value="<?= e($_POST['weight_at_sale'] ?? '') ?>" placeholder="Optional" min="0" step="0.1">
                </div>
                <div class="form-group">
                    <label>Sale Date <span class="req">*</span></label>
                    <input type="date" name="sale_date" value="<?= e($_POST['sale_date'] ?? $today) ?>" max="<?= $today ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Any additional notes…" maxlength="500"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
            <div class="warning-box" style="background:#fef2f2;border-color:#fca5a5;color:#7f1d1d">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                <span>This will permanently mark the cow as <strong>Sold</strong> and cannot be undone.</span>
            </div>
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Sell cow #<?= e(addslashes($cow['tag_number'])) ?> as a whole cow? This will mark it as SOLD.')">
                Confirm Whole Cow Sale
            </button>
        </form>
    </div>
    <?php else: ?>
    <div class="info-box">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Whole cow sale is not available — cow is already <strong><?= e(str_replace('_', ' ', $cow['status'])) ?></strong>.</span>
    </div>
    <?php endif; ?>

    <!-- History -->
    <?php if (!empty($whole_sales)): ?>
    <div class="sell-card">
        <h3>Sale History</h3>
        <table class="history-table">
            <thead><tr><th>Date</th><th>Buyer</th><th>Weight</th><th>Price</th><th>Recorded By</th></tr></thead>
            <tbody>
            <?php foreach ($whole_sales as $s): ?>
            <tr>
                <td><?= e(formatDate($s['sale_date'])) ?></td>
                <td><?= e($s['buyer_name']) ?></td>
                <td><?= $s['weight_at_sale'] ? e($s['weight_at_sale']) . ' kg' : '—' ?></td>
                <td><strong><?= number_format($s['sale_price'], 2) ?> BDT</strong></td>
                <td><?= e($s['recorded_by_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: MEAT
════════════════════════════════════════════════════════════════════════════ -->
<div class="sell-panel <?= $tab === 'meat' ? 'active' : '' ?>" id="panel-meat">
    <?php if ($cow['status'] !== 'sold'): ?>
    <div class="sell-card">
        <h3>
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            Record Meat Sale (per kg)
        </h3>
        <div class="warning-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <span>Recording a meat sale will mark the cow as <strong>Deceased (Slaughtered)</strong>.</span>
        </div>
        <form method="POST" id="meatForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell_meat">
            <input type="hidden" name="tab" value="meat">
            <div class="form-row">
                <div class="form-group">
                    <label>Weight Sold (kg) <span class="req">*</span></label>
                    <input type="number" name="kg_sold" id="m_kg" value="<?= e($_POST['kg_sold'] ?? '') ?>" placeholder="e.g. 180" min="0.1" step="0.1" required>
                </div>
                <div class="form-group">
                    <label>Price per kg (BDT) <span class="req">*</span></label>
                    <input type="number" name="price_per_kg" id="m_ppkg" value="<?= e($_POST['price_per_kg'] ?? '') ?>" placeholder="e.g. 650" min="0.01" step="0.01" required>
                </div>
            </div>
            <div class="total-preview" id="meatTotal">Total Revenue: — BDT</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Event Type</label>
                    <select name="event_type">
                        <option value="regular" <?= ($_POST['event_type'] ?? 'regular') === 'regular' ? 'selected' : '' ?>>Regular Sale</option>
                        <option value="eid"     <?= ($_POST['event_type'] ?? '') === 'eid'     ? 'selected' : '' ?>>Eid Sacrifice</option>
                        <option value="gift"    <?= ($_POST['event_type'] ?? '') === 'gift'    ? 'selected' : '' ?>>Gift / Donation</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sale Date <span class="req">*</span></label>
                    <input type="date" name="sale_date" value="<?= e($_POST['sale_date'] ?? $today) ?>" max="<?= $today ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Buyer Name</label>
                <input type="text" name="buyer_name" value="<?= e($_POST['buyer_name'] ?? '') ?>" placeholder="Optional" maxlength="150">
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Optional notes…" maxlength="500"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Record meat sale for cow #<?= e(addslashes($cow['tag_number'])) ?>? It will be marked as DECEASED.')">
                Confirm Meat Sale
            </button>
        </form>
    </div>
    <?php else: ?>
    <div class="info-box">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Meat sale is not available — cow has already been sold.</span>
    </div>
    <?php endif; ?>

    <?php if (!empty($meat_sales_hist)): ?>
    <div class="sell-card">
        <h3>Meat Sale History</h3>
        <table class="history-table">
            <thead><tr><th>Date</th><th>Event</th><th>Kg Sold</th><th>Price/kg</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($meat_sales_hist as $s): ?>
            <tr>
                <td><?= e(formatDate($s['sale_date'])) ?></td>
                <td><?= e(ucfirst($s['event_type'])) ?></td>
                <td><?= e($s['kg_sold']) ?></td>
                <td><?= number_format($s['price_per_kg'], 2) ?></td>
                <td><strong><?= number_format($s['total_revenue'], 2) ?> BDT</strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: SKIN
════════════════════════════════════════════════════════════════════════════ -->
<div class="sell-panel <?= $tab === 'skin' ? 'active' : '' ?>" id="panel-skin">
    <div class="sell-card">
        <h3>Record Skin Sale</h3>
        <div class="info-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span>Skin is typically sold after slaughter. This does <strong>not</strong> change the cow's status automatically.</span>
        </div>
        <form method="POST" id="skinForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell_byproduct">
            <input type="hidden" name="byp_type" value="skin">
            <input type="hidden" name="tab" value="skin">
            <div class="form-row">
                <div class="form-group">
                    <label>Quantity <span class="req">*</span></label>
                    <input type="number" name="quantity" id="sk_qty" value="<?= e($_POST['quantity'] ?? '1') ?>" min="0.01" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <select name="unit">
                        <option value="piece" selected>Piece</option>
                        <option value="kg">kg</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Price per Unit (BDT) <span class="req">*</span></label>
                    <input type="number" name="price_per_unit" id="sk_ppu" value="<?= e($_POST['price_per_unit'] ?? '') ?>" placeholder="0.00" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Sale Date <span class="req">*</span></label>
                    <input type="date" name="sale_date" value="<?= e($_POST['sale_date'] ?? $today) ?>" max="<?= $today ?>" required>
                </div>
            </div>
            <div class="total-preview" id="skinTotal">Total: — BDT</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Buyer Name</label>
                    <input type="text" name="buyer_name" value="" placeholder="Optional" maxlength="150">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" value="" placeholder="e.g. Tanned hide, raw skin" maxlength="255">
                </div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Optional…" maxlength="500"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Record Skin Sale</button>
        </form>
    </div>
    <?php $skin_hist = array_filter($byp_sales, fn($r) => $r['sale_type'] === 'skin'); ?>
    <?php if (!empty($skin_hist)): ?>
    <div class="sell-card">
        <h3>Skin Sale History</h3>
        <?= renderByproductTable($skin_hist) ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: BYPRODUCTS (bones / fat / organs / other)
════════════════════════════════════════════════════════════════════════════ -->
<div class="sell-panel <?= $tab === 'byproduct' ? 'active' : '' ?>" id="panel-byproduct">
    <div class="sell-card">
        <h3>Record Byproduct Sale</h3>
        <form method="POST" id="bypForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell_byproduct">
            <input type="hidden" name="tab" value="byproduct">
            <div class="form-group">
                <label>Byproduct Type <span class="req">*</span></label>
                <select name="byp_type" id="bypType">
                    <option value="bones"  <?= ($_POST['byp_type'] ?? '') === 'bones'  ? 'selected' : '' ?>>Bones</option>
                    <option value="fat"    <?= ($_POST['byp_type'] ?? '') === 'fat'    ? 'selected' : '' ?>>Fat / Tallow</option>
                    <option value="organs" <?= ($_POST['byp_type'] ?? '') === 'organs' ? 'selected' : '' ?>>Organs</option>
                    <option value="other"  <?= ($_POST['byp_type'] ?? '') === 'other'  ? 'selected' : '' ?>>Other</option>
                </select>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" value="<?= e($_POST['description'] ?? '') ?>" placeholder="e.g. Leg bones, liver + kidney, tail fat" maxlength="255">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Quantity <span class="req">*</span></label>
                    <input type="number" name="quantity" id="byp_qty" value="<?= e($_POST['quantity'] ?? '1') ?>" min="0.01" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <select name="unit">
                        <option value="kg">kg</option>
                        <option value="unit">unit / piece</option>
                        <option value="litre">litre</option>
                        <option value="bag">bag</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Price per Unit (BDT) <span class="req">*</span></label>
                    <input type="number" name="price_per_unit" id="byp_ppu" value="<?= e($_POST['price_per_unit'] ?? '') ?>" placeholder="0.00" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Sale Date <span class="req">*</span></label>
                    <input type="date" name="sale_date" value="<?= e($_POST['sale_date'] ?? $today) ?>" max="<?= $today ?>" required>
                </div>
            </div>
            <div class="total-preview" id="bypTotal">Total: — BDT</div>
            <div class="form-group">
                <label>Buyer Name</label>
                <input type="text" name="buyer_name" value="<?= e($_POST['buyer_name'] ?? '') ?>" placeholder="Optional" maxlength="150">
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Optional…" maxlength="500"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Record Byproduct Sale</button>
        </form>
    </div>
    <?php $byp_hist = array_filter($byp_sales, fn($r) => in_array($r['sale_type'], ['bones','fat','organs','other'], true)); ?>
    <?php if (!empty($byp_hist)): ?>
    <div class="sell-card">
        <h3>Byproduct Sale History</h3>
        <?= renderByproductTable($byp_hist) ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: DUNG / MANURE
════════════════════════════════════════════════════════════════════════════ -->
<div class="sell-panel <?= $tab === 'dung' ? 'active' : '' ?>" id="panel-dung">
    <?php if (!in_array($cow['status'], ['sold','deceased'], true)): ?>
    <div class="sell-card">
        <h3>Record Dung / Manure Sale</h3>
        <div class="info-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span>Dung sales are recurring — this cow stays active after recording.</span>
        </div>
        <form method="POST" id="dungForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell_byproduct">
            <input type="hidden" name="byp_type" value="dung">
            <input type="hidden" name="tab" value="dung">
            <div class="form-row">
                <div class="form-group">
                    <label>Quantity <span class="req">*</span></label>
                    <input type="number" name="quantity" id="dg_qty" value="<?= e($_POST['quantity'] ?? '') ?>" placeholder="e.g. 50" min="0.01" step="0.1" required>
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <select name="unit">
                        <option value="kg">kg</option>
                        <option value="bag">bag</option>
                        <option value="litre">litre</option>
                        <option value="unit">unit / cart</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Price per Unit (BDT) <span class="req">*</span></label>
                    <input type="number" name="price_per_unit" id="dg_ppu" value="<?= e($_POST['price_per_unit'] ?? '') ?>" placeholder="0.00" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Sale Date <span class="req">*</span></label>
                    <input type="date" name="sale_date" value="<?= e($_POST['sale_date'] ?? $today) ?>" max="<?= $today ?>" required>
                </div>
            </div>
            <div class="total-preview" id="dungTotal">Total: — BDT</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Buyer Name</label>
                    <input type="text" name="buyer_name" value="" placeholder="Optional" maxlength="150">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" value="" placeholder="e.g. Fresh dung, composted, biogas slurry" maxlength="255">
                </div>
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Optional…" maxlength="500"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Record Dung Sale</button>
        </form>
    </div>
    <?php else: ?>
    <div class="info-box">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Dung sales are not available for a <?= e(str_replace('_', ' ', $cow['status'])) ?> cow.</span>
    </div>
    <?php endif; ?>

    <?php $dung_hist = array_filter($byp_sales, fn($r) => $r['sale_type'] === 'dung'); ?>
    <?php if (!empty($dung_hist)): ?>
    <div class="sell-card">
        <h3>Dung Sale History</h3>
        <?= renderByproductTable($dung_hist) ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TAB: REPRODUCTIVE SERVICES
════════════════════════════════════════════════════════════════════════════ -->
<div class="sell-panel <?= $tab === 'reproductive' ? 'active' : '' ?>" id="panel-reproductive">
    <?php if (!in_array($cow['status'], ['sold','deceased'], true)): ?>
    <div class="sell-card">
        <h3>Record Reproductive Service Income</h3>
        <form method="POST" id="reproForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sell_byproduct">
            <input type="hidden" name="tab" value="reproductive">
            <div class="form-group">
                <label>Service Type <span class="req">*</span></label>
                <select name="byp_type">
                    <option value="semen">Semen / Bull Stud Service</option>
                    <option value="breeding_service">Breeding Service (Natural)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" value="" placeholder="e.g. AI semen dose, stud fee for farm visit" maxlength="255">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Quantity / Doses <span class="req">*</span></label>
                    <input type="number" name="quantity" id="rp_qty" value="1" min="1" step="1" required>
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <select name="unit">
                        <option value="dose">dose</option>
                        <option value="service">service</option>
                        <option value="unit">unit</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Fee per Unit (BDT) <span class="req">*</span></label>
                    <input type="number" name="price_per_unit" id="rp_ppu" value="" placeholder="0.00" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Service Date <span class="req">*</span></label>
                    <input type="date" name="sale_date" value="<?= $today ?>" max="<?= $today ?>" required>
                </div>
            </div>
            <div class="total-preview" id="reproTotal">Total: — BDT</div>
            <div class="form-group">
                <label>Client / Buyer Name</label>
                <input type="text" name="buyer_name" value="" placeholder="Farm or individual name (optional)" maxlength="150">
            </div>
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="2" placeholder="Optional…" maxlength="500"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Record Service Income</button>
        </form>
    </div>
    <?php else: ?>
    <div class="info-box">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Reproductive services are not available for a <?= e(str_replace('_', ' ', $cow['status'])) ?> cow.</span>
    </div>
    <?php endif; ?>

    <?php $repro_hist = array_filter($byp_sales, fn($r) => in_array($r['sale_type'], ['semen','breeding_service'], true)); ?>
    <?php if (!empty($repro_hist)): ?>
    <div class="sell-card">
        <h3>Service Income History</h3>
        <?= renderByproductTable($repro_hist) ?>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    // Tab switching
    document.querySelectorAll('.sell-tab').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.sell-tab').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            document.querySelectorAll('.sell-panel').forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            const panel = document.getElementById('panel-' + this.dataset.tab);
            if (panel) panel.classList.add('active');
        });
    });

    // Live total calculators
    function calcTotal(qtyId, ppuId, displayId) {
        const qty = document.getElementById(qtyId);
        const ppu = document.getElementById(ppuId);
        const display = document.getElementById(displayId);
        if (!qty || !ppu || !display) return;
        function update() {
            const q = parseFloat(qty.value) || 0;
            const p = parseFloat(ppu.value) || 0;
            const t = q * p;
            display.textContent = t > 0 ? 'Total: ' + t.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' BDT' : 'Total: — BDT';
        }
        qty.addEventListener('input', update);
        ppu.addEventListener('input', update);
    }

    calcTotal('m_kg',   'm_ppkg',  'meatTotal');
    calcTotal('sk_qty', 'sk_ppu',  'skinTotal');
    calcTotal('byp_qty','byp_ppu', 'bypTotal');
    calcTotal('dg_qty', 'dg_ppu',  'dungTotal');
    calcTotal('rp_qty', 'rp_ppu',  'reproTotal');
})();
</script>

<?php
function renderByproductTable(array $rows): string {
    $type_labels = [
        'skin'             => 'Skin',
        'bones'            => 'Bones',
        'fat'              => 'Fat/Tallow',
        'organs'           => 'Organs',
        'dung'             => 'Dung',
        'semen'            => 'Semen',
        'breeding_service' => 'Breeding Service',
        'other'            => 'Other',
    ];
    $html = '<table class="history-table"><thead><tr>
        <th>Date</th><th>Type</th><th>Qty</th><th>Price/Unit</th><th>Total</th><th>Buyer</th><th>Recorded By</th>
    </tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s %s</td><td>%s</td><td><strong>%s BDT</strong></td><td>%s</td><td>%s</td></tr>',
            e(formatDate($r['sale_date'])),
            e($type_labels[$r['sale_type']] ?? ucfirst($r['sale_type'])),
            e($r['quantity']),
            e($r['unit']),
            number_format($r['price_per_unit'], 2),
            number_format($r['total_amount'], 2),
            e($r['buyer_name'] ?? '—'),
            e($r['recorded_by_name'] ?? '—')
        );
    }
    $html .= '</tbody></table>';
    return $html;
}

require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
