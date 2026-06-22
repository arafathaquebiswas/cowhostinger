<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'worker', 'veterinarian', 'accountant']);
requireFarmScope();
requireModule('feed_medicine');
requireNotBlocked();

$page_title = 'Feed & Medicine Log';
$active_nav = 'feed_log';
$db      = getDB();
$user_id = (int)$_SESSION['user_id'];
$errors  = [];

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Deduct stock and trigger low-stock alert if needed.
 * Must be called inside an open transaction.
 */
function _deductFeedStock(PDO $db, int $inv_id, float $total_kg): void {
    $db->prepare(
        "UPDATE feed_inventory SET quantity = GREATEST(0, quantity - ?) WHERE id = ? AND " . farmFilter()
    )->execute([$total_kg, $inv_id]);

    $inv = $db->prepare("SELECT item_name, quantity, unit, reorder_threshold FROM feed_inventory WHERE id = ? AND " . farmFilter());
    $inv->execute([$inv_id]);
    $inv = $inv->fetch();
    if (!$inv) return;

    if ((float)$inv['reorder_threshold'] > 0 && (float)$inv['quantity'] <= (float)$inv['reorder_threshold']) {
        $severity = (float)$inv['quantity'] <= 0 ? 'critical' : 'warning';
        $msg = (float)$inv['quantity'] <= 0
            ? "Feed OUT OF STOCK: {$inv['item_name']} — reorder immediately"
            : "Feed LOW STOCK: {$inv['item_name']} — only " . number_format((float)$inv['quantity'], 2) . " {$inv['unit']} remaining";
        createAlert('feed_stock', $severity, $msg, 'feed_inventory', $inv_id);
    }
}

function _deductMedicineStock(PDO $db, int $med_id, float $total_dosage): void {
    $db->prepare(
        "UPDATE medicine_inventory SET quantity = GREATEST(0, quantity - ?) WHERE id = ? AND " . farmFilter()
    )->execute([$total_dosage, $med_id]);

    $inv = $db->prepare("SELECT item_name, quantity, unit, reorder_threshold FROM medicine_inventory WHERE id = ? AND " . farmFilter());
    $inv->execute([$med_id]);
    $inv = $inv->fetch();
    if (!$inv) return;

    if ((float)$inv['reorder_threshold'] > 0 && (float)$inv['quantity'] <= (float)$inv['reorder_threshold']) {
        $severity = (float)$inv['quantity'] <= 0 ? 'critical' : 'warning';
        $msg = (float)$inv['quantity'] <= 0
            ? "Medicine OUT OF STOCK: {$inv['item_name']} — reorder immediately"
            : "Medicine LOW STOCK: {$inv['item_name']} — only " . number_format((float)$inv['quantity'], 2) . " {$inv['unit']} remaining";
        createAlert('medicine_stock', $severity, $msg, 'medicine_inventory', $med_id);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// POST HANDLERS
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/feed_medicine/feed_log.php');
    }

    $action = $_POST['action'] ?? '';

    // ── ADD FEED LOG ─────────────────────────────────────────────────────────
    if ($action === 'add_feed' && hasRole(['admin','worker','accountant'])) {
        $mode      = in_array($_POST['mode'] ?? '', ['single','multiple','all']) ? $_POST['mode'] : 'single';
        $inv_id    = (int)($_POST['feed_inventory_id'] ?? 0);
        $qty_kg    = trim($_POST['quantity_kg'] ?? '');
        $cost_per  = trim($_POST['cost_per_kg'] ?? '0');
        $feed_date = trim($_POST['feed_date'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');

        // Validate
        if ($inv_id <= 0) $errors[] = 'Select a feed item from inventory.';
        $qty  = is_numeric($qty_kg) && (float)$qty_kg > 0 ? (float)$qty_kg : null;
        if ($qty === null || $qty > 9999) $errors[] = 'Quantity per cow must be between 0.01 and 9999.';
        $cost = is_numeric($cost_per) ? (float)$cost_per : 0;
        if ($cost < 0) $errors[] = 'Cost per kg cannot be negative.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $feed_date) || $feed_date > date('Y-m-d'))
            $errors[] = 'Date is required and cannot be in the future.';

        // Resolve cows
        $cow_ids = [];
        if (empty($errors)) {
            if ($mode === 'single') {
                $cid = (int)($_POST['cow_id'] ?? 0);
                if ($cid <= 0) $errors[] = 'Select a cow.';
                else           $cow_ids  = [$cid];
            } elseif ($mode === 'multiple') {
                $raw     = array_map('intval', (array)($_POST['cow_ids'] ?? []));
                $cow_ids = array_values(array_filter($raw));
                if (empty($cow_ids)) $errors[] = 'Select at least one cow.';
            } else {
                $s = $db->prepare("SELECT id FROM cows WHERE " . farmFilter() . " AND status NOT IN ('sold','deceased')");
                $s->execute();
                $cow_ids = $s->fetchAll(PDO::FETCH_COLUMN);
                if (empty($cow_ids)) $errors[] = 'No active cows found.';
            }
        }

        // Validate cows belong to farm
        if (empty($errors)) {
            $placeholders = implode(',', array_fill(0, count($cow_ids), '?'));
            $v = $db->prepare("SELECT id FROM cows WHERE id IN ($placeholders) AND " . farmFilter() . " AND status NOT IN ('sold','deceased')");
            $v->execute($cow_ids);
            $valid_ids = $v->fetchAll(PDO::FETCH_COLUMN);
            $invalid   = array_diff($cow_ids, $valid_ids);
            if (!empty($invalid)) $errors[] = count($invalid) . ' cow(s) are invalid or inactive.';
            else $cow_ids = $valid_ids;
        }

        // Fetch and validate feed inventory stock
        $inv_row = null;
        if (empty($errors)) {
            $si = $db->prepare("SELECT id, item_name, quantity, unit, purchase_price FROM feed_inventory WHERE id=? AND " . farmFilter());
            $si->execute([$inv_id]);
            $inv_row = $si->fetch();
            if (!$inv_row) {
                $errors[] = 'Feed inventory item not found.';
            } else {
                $total_needed = round($qty * count($cow_ids), 3);
                if ((float)$inv_row['quantity'] < $total_needed) {
                    $errors[] = "Insufficient stock. Available: " . number_format($inv_row['quantity'], 2) . " {$inv_row['unit']}, Required: " . number_format($total_needed, 2) . " {$inv_row['unit']}.";
                }
            }
        }

        if (empty($errors)) {
            $batch_id = 'FEED-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
            $total_kg_batch   = 0;
            $total_cost_batch = 0;
            $effective_cost   = $cost > 0 ? $cost : (float)($inv_row['purchase_price'] ?? 0);

            $ins = $db->prepare(
                "INSERT INTO feed_logs (farm_id,cow_id,feed_inventory_id,batch_id,feed_type,quantity_kg,cost_per_kg,total_cost,feed_date,recorded_by,notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );

            $db->beginTransaction();
            try {
                foreach ($cow_ids as $cid) {
                    $line_cost = round($qty * $effective_cost, 2);
                    $ins->execute([
                        fid(), $cid, $inv_id, $batch_id,
                        $inv_row['item_name'], $qty, $effective_cost, $line_cost,
                        $feed_date, $user_id,
                        $notes !== '' ? $notes : null,
                    ]);
                    $total_kg_batch   += $qty;
                    $total_cost_batch += $line_cost;
                }
                _deductFeedStock($db, $inv_id, round($qty * count($cow_ids), 3));
                $db->commit();

                auditLog($user_id, 'CREATE_FEED_BATCH', 'feed_logs', 0, null, [
                    'batch_id' => $batch_id, 'mode' => $mode, 'cows' => count($cow_ids),
                    'item'     => $inv_row['item_name'], 'qty_each' => $qty, 'date' => $feed_date,
                ]);

                $cost_str = $total_cost_batch > 0 ? ', ৳' . number_format($total_cost_batch, 2) . ' total cost' : '';
                flashMessage('success',
                    count($cow_ids) . " cow(s) fed — " . number_format($total_kg_batch, 2) . " kg of {$inv_row['item_name']}{$cost_str}. Stock deducted."
                );
                redirect('/modules/feed_medicine/feed_log.php');
            } catch (Throwable $ex) {
                $db->rollBack();
                error_log('[FEED_LOG] ' . $ex->getMessage());
                $errors[] = 'Database error — please try again.';
            }
        }
    }

    // ── ADD MEDICINE LOG ─────────────────────────────────────────────────────
    if ($action === 'add_medicine' && hasRole(['admin','veterinarian'])) {
        $mode      = in_array($_POST['mode'] ?? '', ['single','multiple','all']) ? $_POST['mode'] : 'single';
        $med_id    = (int)($_POST['medicine_inv_id'] ?? 0);
        $dosage    = trim($_POST['dosage_per_cow'] ?? '');
        $med_date  = trim($_POST['medicine_date'] ?? '');
        $notes     = trim($_POST['notes_med'] ?? '');

        if ($med_id <= 0) $errors[] = 'Select a medicine from inventory.';
        $dose = is_numeric($dosage) && (float)$dosage > 0 ? (float)$dosage : null;
        if ($dose === null) $errors[] = 'Dosage per cow must be a positive number.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $med_date) || $med_date > date('Y-m-d'))
            $errors[] = 'Date is required and cannot be in the future.';

        // Resolve cows
        $cow_ids = [];
        if (empty($errors)) {
            if ($mode === 'single') {
                $cid = (int)($_POST['cow_id_med'] ?? 0);
                if ($cid <= 0) $errors[] = 'Select a cow.';
                else           $cow_ids  = [$cid];
            } elseif ($mode === 'multiple') {
                $raw     = array_map('intval', (array)($_POST['cow_ids_med'] ?? []));
                $cow_ids = array_values(array_filter($raw));
                if (empty($cow_ids)) $errors[] = 'Select at least one cow.';
            } else {
                $s = $db->prepare("SELECT id FROM cows WHERE " . farmFilter() . " AND status NOT IN ('sold','deceased')");
                $s->execute();
                $cow_ids = $s->fetchAll(PDO::FETCH_COLUMN);
                if (empty($cow_ids)) $errors[] = 'No active cows found.';
            }
        }

        if (empty($errors)) {
            $placeholders = implode(',', array_fill(0, count($cow_ids), '?'));
            $v = $db->prepare("SELECT id FROM cows WHERE id IN ($placeholders) AND " . farmFilter() . " AND status NOT IN ('sold','deceased')");
            $v->execute($cow_ids);
            $valid_ids = $v->fetchAll(PDO::FETCH_COLUMN);
            if (!empty(array_diff($cow_ids, $valid_ids))) $errors[] = 'Some cows are invalid or inactive.';
            else $cow_ids = $valid_ids;
        }

        // Fetch and validate medicine stock + expiry
        $med_row = null;
        if (empty($errors)) {
            $sm = $db->prepare(
                "SELECT id, item_name, quantity, unit, expiry_date, reorder_threshold, cost_per_unit
                 FROM medicine_inventory WHERE id=? AND " . farmFilter()
            );
            $sm->execute([$med_id]);
            $med_row = $sm->fetch();
            if (!$med_row) {
                $errors[] = 'Medicine not found in inventory.';
            } else {
                // Expiry check
                if ($med_row['expiry_date'] && $med_row['expiry_date'] < date('Y-m-d')) {
                    $errors[] = "Medicine expired on {$med_row['expiry_date']}. Expired medicine cannot be administered.";
                }
                // Stock check
                $total_dosage = round($dose * count($cow_ids), 3);
                if ((float)$med_row['quantity'] < $total_dosage) {
                    $errors[] = "Insufficient medicine stock. Available: " . number_format($med_row['quantity'], 3) . " {$med_row['unit']}, Required: " . number_format($total_dosage, 3) . " {$med_row['unit']}.";
                }
            }
        }

        if (empty($errors)) {
            $batch_id         = 'MED-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
            $total_cost_batch = 0;
            $cpu              = (float)($med_row['cost_per_unit'] ?? 0);

            $ins = $db->prepare(
                "INSERT INTO medicine_logs (farm_id,batch_id,cow_id,medicine_inv_id,medicine_name,dosage_per_cow,unit,cost_per_unit,total_cost,administered_at,notes,recorded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );

            $db->beginTransaction();
            try {
                foreach ($cow_ids as $cid) {
                    $line_cost = round($dose * $cpu, 2);
                    $ins->execute([
                        fid(), $batch_id, $cid, $med_id,
                        $med_row['item_name'], $dose, $med_row['unit'],
                        $cpu, $line_cost, $med_date,
                        $notes !== '' ? $notes : null, $user_id,
                    ]);
                    $total_cost_batch += $line_cost;
                }
                _deductMedicineStock($db, $med_id, round($dose * count($cow_ids), 3));
                $db->commit();

                auditLog($user_id, 'CREATE_MEDICINE_BATCH', 'medicine_logs', 0, null, [
                    'batch_id' => $batch_id, 'mode' => $mode, 'cows' => count($cow_ids),
                    'medicine' => $med_row['item_name'], 'dose_each' => $dose, 'date' => $med_date,
                ]);

                flashMessage('success',
                    count($cow_ids) . " cow(s) medicated — " . number_format($dose * count($cow_ids), 3) . " {$med_row['unit']} of {$med_row['item_name']} administered. Stock deducted."
                );
                redirect('/modules/feed_medicine/feed_log.php?tab=medicine');
            } catch (Throwable $ex) {
                $db->rollBack();
                error_log('[MED_LOG] ' . $ex->getMessage());
                $errors[] = 'Database error — please try again.';
            }
        }
    }

    // ── DELETE single feed entry ──────────────────────────────────────────────
    if ($action === 'delete_feed_log' && hasRole(['admin', 'manager'])) {
        $log_id = (int)($_POST['log_id'] ?? 0);
        if ($log_id > 0) {
            $db->prepare("DELETE FROM feed_logs WHERE id=? AND farm_id=?")->execute([$log_id, fid()]);
            auditLog($user_id, 'DELETE_FEED_LOG', 'feed_logs', $log_id);
            flashMessage('success', 'Feed entry deleted.');
        }
        redirect('/modules/feed_medicine/feed_log.php?tab=feed');
    }

    // ── DELETE single medicine entry ──────────────────────────────────────────
    if ($action === 'delete_med_log' && hasRole(['admin', 'manager'])) {
        $log_id = (int)($_POST['log_id'] ?? 0);
        if ($log_id > 0) {
            $db->prepare("DELETE FROM medicine_logs WHERE id=? AND farm_id=?")->execute([$log_id, fid()]);
            auditLog($user_id, 'DELETE_MED_LOG', 'medicine_logs', $log_id);
            flashMessage('success', 'Medicine entry deleted.');
        }
        redirect('/modules/feed_medicine/feed_log.php?tab=medicine');
    }

    // ── DELETE feed batch ─────────────────────────────────────────────────────
    if ($action === 'delete_batch_feed' && hasRole(['admin', 'manager'])) {
        $bid = trim($_POST['batch_id'] ?? '');
        if ($bid !== '' && preg_match('/^FEED-[\w\-]+$/', $bid)) {
            $del = $db->prepare("DELETE FROM feed_logs WHERE batch_id=? AND farm_id=?");
            $del->execute([$bid, fid()]);
            auditLog($user_id, 'DELETE_FEED_BATCH', 'feed_logs', 0, null, ['batch_id' => $bid, 'rows' => $del->rowCount()]);
            flashMessage('success', "Feed batch {$bid} deleted ({$del->rowCount()} entries).");
        }
        redirect('/modules/feed_medicine/feed_log.php?tab=feed');
    }

    // ── DELETE medicine batch ─────────────────────────────────────────────────
    if ($action === 'delete_batch_med' && hasRole(['admin', 'manager'])) {
        $bid = trim($_POST['batch_id'] ?? '');
        if ($bid !== '' && preg_match('/^MED-[\w\-]+$/', $bid)) {
            $del = $db->prepare("DELETE FROM medicine_logs WHERE batch_id=? AND farm_id=?");
            $del->execute([$bid, fid()]);
            auditLog($user_id, 'DELETE_MED_BATCH', 'medicine_logs', 0, null, ['batch_id' => $bid, 'rows' => $del->rowCount()]);
            flashMessage('success', "Medicine batch {$bid} deleted ({$del->rowCount()} entries).");
        }
        redirect('/modules/feed_medicine/feed_log.php?tab=medicine');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// DATA LOADING
// ─────────────────────────────────────────────────────────────────────────────
$active_tab  = in_array($_GET['tab'] ?? '', ['feed','medicine','batches']) ? $_GET['tab'] : 'feed';
$filter_from = $_GET['from'] ?? date('Y-m-01');
$filter_to   = $_GET['to']   ?? date('Y-m-d');
$filter_cow  = (int)($_GET['cow_id'] ?? 0);
$filter_batch = trim($_GET['batch'] ?? '');

// ── KPIs ─────────────────────────────────────────────────────────────────────
$feed_kpi = $db->prepare(
    "SELECT COUNT(*) AS entries, COUNT(DISTINCT batch_id) AS batches,
       COALESCE(SUM(quantity_kg),0) AS total_kg, COALESCE(SUM(total_cost),0) AS total_cost,
       COUNT(DISTINCT cow_id) AS cows_fed
     FROM feed_logs WHERE farm_id=? AND feed_date BETWEEN ? AND ?"
);
$feed_kpi->execute([fid(), $filter_from, $filter_to]);
$feed_kpi = $feed_kpi->fetch();

$med_kpi = $db->prepare(
    "SELECT COUNT(*) AS entries, COUNT(DISTINCT batch_id) AS batches,
       COALESCE(SUM(dosage_per_cow),0) AS total_dosage, COALESCE(SUM(total_cost),0) AS total_cost,
       COUNT(DISTINCT cow_id) AS cows_medicated
     FROM medicine_logs WHERE farm_id=? AND administered_at BETWEEN ? AND ?"
);
$med_kpi->execute([fid(), $filter_from, $filter_to]);
$med_kpi = $med_kpi->fetch();

// ── Feed efficiency ───────────────────────────────────────────────────────────
$eff = $db->prepare(
    "SELECT COALESCE(SUM(liters),0) AS milk_liters, COALESCE(SUM(milk_value),0) AS milk_value
     FROM milk_records WHERE farm_id=? AND DATE(recorded_at) BETWEEN ? AND ? AND contamination_flag=0"
);
$eff->execute([fid(), $filter_from, $filter_to]);
$eff = $eff->fetch();
$feed_efficiency = ($feed_kpi['total_kg'] > 0 && $eff['milk_liters'] > 0)
    ? round($eff['milk_liters'] / $feed_kpi['total_kg'], 3) : null;

// ── Inventory: only available items (quantity > 0) ───────────────────────────
$feed_inventory_stmt = $db->prepare(
    "SELECT id, item_name, quantity, unit, purchase_price, reorder_threshold
     FROM feed_inventory WHERE " . farmFilter() . " AND quantity > 0 ORDER BY item_name ASC"
);
$feed_inventory_stmt->execute();
$feed_inventory = $feed_inventory_stmt->fetchAll();

$med_inventory_stmt = $db->prepare(
    "SELECT id, item_name, quantity, unit, expiry_date, cost_per_unit, reorder_threshold,
            DATEDIFF(expiry_date, CURDATE()) AS days_to_expiry
     FROM medicine_inventory WHERE " . farmFilter() . " ORDER BY item_name ASC"
);
$med_inventory_stmt->execute();
$med_inventory = $med_inventory_stmt->fetchAll();

// Separate usable vs unusable medicine
$med_usable = array_filter($med_inventory, fn($m) => (!$m['expiry_date'] || $m['days_to_expiry'] >= 0) && $m['quantity'] > 0);
$med_expired = array_filter($med_inventory, fn($m) => $m['expiry_date'] && $m['days_to_expiry'] < 0);
$med_expiring_soon = array_filter($med_inventory, fn($m) => $m['expiry_date'] && $m['days_to_expiry'] >= 0 && $m['days_to_expiry'] <= 30);

// ── Active cows ───────────────────────────────────────────────────────────────
$active_cows_stmt = $db->prepare(
    "SELECT id, tag_number, breed FROM cows WHERE " . farmFilter() . " AND status NOT IN ('sold','deceased') ORDER BY tag_number ASC"
);
$active_cows_stmt->execute();
$active_cows = $active_cows_stmt->fetchAll();
$cow_count   = count($active_cows);

// ── Stock warnings (low feed / expiring medicine) ────────────────────────────
$low_feed_items = array_filter($feed_inventory, fn($fi) => (float)$fi['reorder_threshold'] > 0 && (float)$fi['quantity'] <= (float)$fi['reorder_threshold']);
$out_feed_items = array_filter($feed_inventory, fn($fi) => (float)$fi['quantity'] <= 0);

// ── Feed log entries ──────────────────────────────────────────────────────────
$feed_logs = [];
if ($active_tab === 'feed' || $active_tab === 'batches') {
    $wp  = ["fl.farm_id=?"];
    $par = [fid()];
    if ($filter_from) { $wp[] = "fl.feed_date >= ?"; $par[] = $filter_from; }
    if ($filter_to)   { $wp[] = "fl.feed_date <= ?"; $par[] = $filter_to; }
    if ($filter_cow)  { $wp[] = "fl.cow_id = ?";     $par[] = $filter_cow; }
    if ($filter_batch){ $wp[] = "fl.batch_id = ?";   $par[] = $filter_batch; }

    $fl_stmt = $db->prepare(
        "SELECT fl.*, c.tag_number, c.breed, u.name AS recorder_name,
                fi.item_name AS inv_item_name
         FROM feed_logs fl
         JOIN cows c ON c.id = fl.cow_id
         LEFT JOIN users u ON u.id = fl.recorded_by
         LEFT JOIN feed_inventory fi ON fi.id = fl.feed_inventory_id
         WHERE " . implode(' AND ', $wp) . "
         ORDER BY fl.feed_date DESC, fl.batch_id DESC, fl.id DESC
         LIMIT 300"
    );
    $fl_stmt->execute($par);
    $feed_logs = $fl_stmt->fetchAll();
}

// ── Medicine log entries ──────────────────────────────────────────────────────
$med_logs = [];
if ($active_tab === 'medicine' || $active_tab === 'batches') {
    $mwp  = ["ml.farm_id=?"];
    $mpar = [fid()];
    if ($filter_from) { $mwp[] = "ml.administered_at >= ?"; $mpar[] = $filter_from; }
    if ($filter_to)   { $mwp[] = "ml.administered_at <= ?"; $mpar[] = $filter_to; }
    if ($filter_cow)  { $mwp[] = "ml.cow_id = ?";           $mpar[] = $filter_cow; }
    if ($filter_batch){ $mwp[] = "ml.batch_id = ?";         $mpar[] = $filter_batch; }

    $ml_stmt = $db->prepare(
        "SELECT ml.*, c.tag_number, c.breed, u.name AS recorder_name
         FROM medicine_logs ml
         JOIN cows c ON c.id = ml.cow_id
         LEFT JOIN users u ON u.id = ml.recorded_by
         WHERE " . implode(' AND ', $mwp) . "
         ORDER BY ml.administered_at DESC, ml.batch_id DESC, ml.id DESC
         LIMIT 300"
    );
    $ml_stmt->execute($mpar);
    $med_logs = $ml_stmt->fetchAll();
}

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<style>
/* ── Type toggle ─────────────────────────────────────────────────────── */
.type-toggle{display:flex;gap:0;border:2px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:1rem}
.type-btn{flex:1;padding:.55rem .5rem;background:none;border:none;cursor:pointer;font-size:.83rem;font-weight:600;color:var(--text-muted);transition:.15s;text-align:center}
.type-btn.active-feed{background:var(--success);color:#fff}
.type-btn.active-med{background:#7C3AED;color:#fff}
.type-btn:not(.active-feed):not(.active-med):hover{background:rgba(0,0,0,.04)}
/* ── Mode cards ─────────────────────────────────────────────────────── */
.mode-card{border:2px solid var(--border);border-radius:8px;padding:.55rem .9rem;cursor:pointer;transition:.15s;display:flex;align-items:flex-start;gap:.55rem}
.mode-card:hover{border-color:var(--success);background:rgba(45,106,79,.04)}
.mode-card.selected{border-color:var(--success);background:rgba(45,106,79,.07)}
.mode-card.med.selected{border-color:#7C3AED;background:rgba(124,58,237,.06)}
.mode-card input[type=radio]{margin-top:.15rem;flex-shrink:0}
.mode-label{font-weight:600;font-size:.83rem}
.mode-desc{font-size:.72rem;color:var(--text-muted);margin-top:.08rem}
/* ── Cow checkbox list ──────────────────────────────────────────────── */
.cow-cb-list{max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:.35rem}
.cow-cb-item{display:flex;align-items:center;gap:.4rem;padding:.22rem .35rem;border-radius:4px;font-size:.8rem;cursor:pointer}
.cow-cb-item:hover{background:rgba(45,106,79,.06)}
.cow-cb-item input[type=checkbox]{accent-color:var(--success);flex-shrink:0}
/* ── Stock badge ─────────────────────────────────────────────────────── */
.stock-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:600;padding:.18rem .55rem;border-radius:20px}
.stock-ok{background:#f0fdf4;color:#15803d;border:1px solid #86efac}
.stock-low{background:#fffbeb;color:#92400e;border:1px solid #fcd34d}
.stock-out{background:#fef2f2;color:#991b1b;border:1px solid #fca5a5}
.stock-expired{background:#fef2f2;color:#7f1d1d;border:1px solid #fca5a5}
.stock-expiring{background:#fff7ed;color:#9a3412;border:1px solid #fdba74}
/* ── Batch pill ─────────────────────────────────────────────────────── */
.batch-pill{display:inline-block;font-size:.63rem;font-family:monospace;background:#f0fdf4;color:#166534;border:1px solid #86efac;border-radius:4px;padding:.04rem .4rem;cursor:pointer}
.batch-pill.med{background:#f5f3ff;color:#4c1d95;border-color:#c4b5fd}
.batch-pill:hover{opacity:.8}
/* ── Summary preview ─────────────────────────────────────────────────── */
.batch-preview{background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.45rem .75rem;font-size:.78rem;margin-bottom:.65rem}
.batch-preview.med{background:#f5f3ff;border-color:#c4b5fd}
/* ── Warning banner ─────────────────────────────────────────────────── */
.warn-stock{background:#fff7ed;border-left:3px solid #f97316;border-radius:0 6px 6px 0;padding:.5rem .8rem;font-size:.78rem;color:#7c2d12;margin-bottom:.6rem}
.warn-expired{background:#fef2f2;border-left:3px solid #dc2626;border-radius:0 6px 6px 0;padding:.5rem .8rem;font-size:.78rem;color:#7f1d1d;margin-bottom:.6rem}
</style>

<div class="page-header">
    <div>
        <h2>Feed &amp; Medicine Log</h2>
        <p class="text-sm text-muted">Unified consumption engine — real stock deduction on every entry</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/feed_medicine/index.php" class="btn btn-secondary btn-sm">Manage Stock</a>
        <?php if (hasRole(['admin', 'manager'])): ?>
        <a href="/modules/feed_medicine/feed_form.php"     class="btn btn-secondary btn-sm">+ Feed Stock</a>
        <a href="/modules/feed_medicine/medicine_form.php" class="btn btn-secondary btn-sm">+ Med Stock</a>
        <?php endif; ?>
    </div>
</div>

<!-- ── STOCK WARNINGS ──────────────────────────────────────────────────────── -->
<?php if (!empty($med_expired)): ?>
<div class="warn-expired" style="margin-bottom:.75rem">
    ⛔ <strong><?= count($med_expired) ?> expired medicine(s):</strong>
    <?= implode(', ', array_map(fn($m) => e($m['item_name']), $med_expired)) ?> — cannot be used.
    <a href="/modules/feed_medicine/index.php?tab=medicine" style="font-weight:600;color:inherit;margin-left:.5rem">View stock →</a>
</div>
<?php endif; ?>
<?php if (!empty($med_expiring_soon)): ?>
<div class="warn-stock" style="margin-bottom:.75rem">
    ⚠ <strong><?= count($med_expiring_soon) ?> medicine(s) expiring within 30 days:</strong>
    <?= implode(', ', array_map(fn($m) => e($m['item_name']) . ' (' . $m['days_to_expiry'] . 'd)', $med_expiring_soon)) ?>
</div>
<?php endif; ?>
<?php if (!empty($out_feed_items)): ?>
<div class="warn-stock" style="margin-bottom:.75rem">
    ⚠ <strong><?= count($out_feed_items) ?> feed item(s) OUT OF STOCK:</strong>
    <?= implode(', ', array_map(fn($f) => e($f['item_name']), $out_feed_items)) ?>
</div>
<?php endif; ?>

<!-- ── KPI CARDS ──────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:.85rem;margin-bottom:1.25rem">
    <div class="card" style="padding:.9rem">
        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Feed Used</div>
        <div style="font-size:1.5rem;font-weight:700;color:var(--success)"><?= number_format($feed_kpi['total_kg'],1) ?> kg</div>
        <div style="font-size:.72rem;color:var(--text-muted)"><?= $feed_kpi['entries'] ?> entries · <?= $feed_kpi['cows_fed'] ?> cows</div>
    </div>
    <div class="card" style="padding:.9rem">
        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Feed Cost</div>
        <div style="font-size:1.5rem;font-weight:700;color:var(--danger)">৳<?= number_format($feed_kpi['total_cost'],0) ?></div>
        <div style="font-size:.72rem;color:var(--text-muted)"><?= $feed_kpi['batches'] ?> batches</div>
    </div>
    <div class="card" style="padding:.9rem">
        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Medicine Cost</div>
        <div style="font-size:1.5rem;font-weight:700;color:#7C3AED">৳<?= number_format($med_kpi['total_cost'],0) ?></div>
        <div style="font-size:.72rem;color:var(--text-muted)"><?= $med_kpi['entries'] ?> entries · <?= $med_kpi['cows_medicated'] ?> cows</div>
    </div>
    <div class="card" style="padding:.9rem">
        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Milk / Feed</div>
        <div style="font-size:1.5rem;font-weight:700;color:<?= $feed_efficiency !== null && $feed_efficiency >= 1.5 ? 'var(--success)' : 'var(--warning,#e65100)' ?>">
            <?= $feed_efficiency !== null ? number_format($feed_efficiency,2) : '—' ?>
        </div>
        <div style="font-size:.72rem;color:var(--text-muted)">L / kg · <?= number_format($eff['milk_liters'],1) ?> L milk</div>
    </div>
    <div class="card" style="padding:.9rem">
        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;font-weight:600">Total Spend</div>
        <div style="font-size:1.5rem;font-weight:700">৳<?= number_format($feed_kpi['total_cost'] + $med_kpi['total_cost'],0) ?></div>
        <div style="font-size:.72rem;color:var(--text-muted)">Feed + Medicine</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:390px 1fr;gap:1.25rem;align-items:start">

<!-- ════════════════════════════════════════════════════════════════════════════
     LEFT PANEL — UNIFIED ADD FORM
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="card" style="position:sticky;top:1rem">
    <div class="card-header"><span class="card-title">Record Consumption</span></div>
    <div class="card-body">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="margin-bottom:.9rem">
            <ul style="margin:0;padding-left:1.2rem">
                <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Type Toggle -->
        <div class="type-toggle">
            <button type="button" class="type-btn active-feed" id="tbtn-feed" onclick="switchType('feed')">🌾 Feed</button>
            <?php if (hasRole(['admin','veterinarian'])): ?>
            <button type="button" class="type-btn" id="tbtn-med"  onclick="switchType('medicine')">💊 Medicine</button>
            <?php endif; ?>
        </div>

        <!-- ════════════════════ FEED FORM ════════════════════ -->
        <form id="feedForm" method="POST" action="/modules/feed_medicine/feed_log.php" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_feed">
            <input type="hidden" name="mode"   id="feed_mode_input" value="single">

            <!-- Cow Mode Selector -->
            <div class="form-group">
                <label class="form-label" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Cow Selection</label>
                <div style="display:flex;flex-direction:column;gap:.4rem">
                    <label class="mode-card selected" id="fc-single" onclick="setFeedMode('single')">
                        <input type="radio" name="_fm_radio" value="single" checked>
                        <div><div class="mode-label">Single Cow</div><div class="mode-desc">Feed one cow</div></div>
                    </label>
                    <label class="mode-card" id="fc-multiple" onclick="setFeedMode('multiple')">
                        <input type="radio" name="_fm_radio" value="multiple">
                        <div><div class="mode-label">Select Multiple</div><div class="mode-desc">Tick specific cows</div></div>
                    </label>
                    <label class="mode-card" id="fc-all" onclick="setFeedMode('all')">
                        <input type="radio" name="_fm_radio" value="all">
                        <div><div class="mode-label">All <?= $cow_count ?> Cows</div><div class="mode-desc">Apply to every active cow</div></div>
                    </label>
                </div>
            </div>

            <!-- Single cow -->
            <div id="fpanel-single" class="form-group">
                <label class="form-label">Cow <span style="color:var(--danger)">*</span></label>
                <select name="cow_id" id="f_single_cow" class="form-control" onchange="calcFeedSummary()">
                    <option value="">— Select —</option>
                    <?php foreach ($active_cows as $c): ?>
                    <option value="<?= $c['id'] ?>">#<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Multiple cows -->
            <div id="fpanel-multiple" style="display:none" class="form-group">
                <label class="form-label">Cows <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:.35rem;margin-bottom:.3rem;flex-wrap:wrap">
                    <input type="text" id="f_cow_search" class="form-control" placeholder="Filter…" style="flex:1;min-width:0;font-size:.78rem" oninput="filterFeedCows()">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleFeedCows(true)">All</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleFeedCows(false)">None</button>
                </div>
                <div class="cow-cb-list" id="f_cow_list">
                    <?php foreach ($active_cows as $c): ?>
                    <label class="cow-cb-item" data-tag="<?= strtolower($c['tag_number']) ?>" data-breed="<?= strtolower($c['breed']) ?>">
                        <input type="checkbox" name="cow_ids[]" value="<?= $c['id'] ?>" onchange="calcFeedSummary()">
                        <span><strong>#<?= e($c['tag_number']) ?></strong> <span style="color:var(--text-muted)"><?= e($c['breed']) ?></span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="f_multi_count" style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem">0 selected</div>
            </div>

            <!-- All cows warning -->
            <div id="fpanel-all" style="display:none">
                <div class="alert" style="background:#fff7ed;border-left:3px solid #f97316;padding:.55rem .8rem;font-size:.78rem;color:#7c2d12;margin-bottom:.65rem">
                    ⚠ Creates <strong><?= $cow_count ?> individual records</strong> in one batch.
                </div>
            </div>

            <!-- Feed inventory selection with live stock display -->
            <div class="form-group">
                <label class="form-label">Feed Item (from stock) <span style="color:var(--danger)">*</span></label>
                <?php if (empty($feed_inventory)): ?>
                <div class="warn-stock">No feed in stock. <a href="/modules/feed_medicine/feed_form.php">Add feed stock first →</a></div>
                <input type="hidden" name="feed_inventory_id" value="0">
                <?php else: ?>
                <select name="feed_inventory_id" id="f_inv_sel" class="form-control" required onchange="onFeedInvChange()">
                    <option value="">— Select feed item —</option>
                    <?php foreach ($feed_inventory as $fi): ?>
                    <?php $s = (float)$fi['reorder_threshold'] > 0 && (float)$fi['quantity'] <= (float)$fi['reorder_threshold'] ? ' ⚠' : ''; ?>
                    <option value="<?= $fi['id'] ?>"
                            data-qty="<?= $fi['quantity'] ?>"
                            data-unit="<?= e($fi['unit']) ?>"
                            data-cost="<?= $fi['purchase_price'] ?? 0 ?>"
                            data-threshold="<?= $fi['reorder_threshold'] ?>">
                        <?= e($fi['item_name']) ?><?= $s ?> (<?= number_format($fi['quantity'],2) ?> <?= e($fi['unit']) ?> available)
                    </option>
                    <?php endforeach; ?>
                </select>
                <!-- Live stock indicator -->
                <div id="f_stock_indicator" style="margin-top:.3rem"></div>
                <?php endif; ?>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem">
                <div class="form-group">
                    <label class="form-label">Qty / cow <span style="color:var(--danger)">*</span></label>
                    <div style="display:flex;align-items:center;gap:.3rem">
                        <input type="number" name="quantity_kg" id="f_qty" class="form-control"
                               step="0.01" min="0.01" max="9999" placeholder="e.g. 5.00" required
                               oninput="calcFeedSummary()">
                        <span id="f_unit_label" style="font-size:.78rem;color:var(--text-muted);white-space:nowrap">kg</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Cost/kg (৳)</label>
                    <input type="number" name="cost_per_kg" id="f_cost" class="form-control"
                           step="0.01" min="0" placeholder="auto-fill" oninput="calcFeedSummary()">
                </div>
            </div>

            <!-- Batch preview -->
            <div id="f_preview" class="batch-preview" style="display:none">
                <strong id="f_prev_cows">–</strong> cows ×
                <strong id="f_prev_qty">–</strong> =
                <strong id="f_prev_total_kg">–</strong>
                <span id="f_prev_cost_block" style="display:none"> · ৳<strong id="f_prev_cost">–</strong></span>
                <span id="f_stock_check" style="margin-left:.4rem"></span>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem">
                <div class="form-group">
                    <label class="form-label">Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="feed_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" maxlength="255" placeholder="Optional">
                </div>
            </div>

            <button type="submit" class="btn btn-success btn-sm" id="f_submit_btn">🌾 Save Feed Entry</button>
        </form>

        <!-- ════════════════════ MEDICINE FORM ════════════════════ -->
        <?php if (hasRole(['admin','veterinarian'])): ?>
        <form id="medForm" method="POST" action="/modules/feed_medicine/feed_log.php" novalidate style="display:none">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_medicine">
            <input type="hidden" name="mode"   id="med_mode_input" value="single">

            <!-- Cow Mode -->
            <div class="form-group">
                <label class="form-label" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em">Cow Selection</label>
                <div style="display:flex;flex-direction:column;gap:.4rem">
                    <label class="mode-card med selected" id="mc-single" onclick="setMedMode('single')">
                        <input type="radio" name="_mm_radio" value="single" checked>
                        <div><div class="mode-label">Single Cow</div><div class="mode-desc">Administer to one cow</div></div>
                    </label>
                    <label class="mode-card med" id="mc-multiple" onclick="setMedMode('multiple')">
                        <input type="radio" name="_mm_radio" value="multiple">
                        <div><div class="mode-label">Select Multiple</div><div class="mode-desc">Tick specific cows</div></div>
                    </label>
                    <label class="mode-card med" id="mc-all" onclick="setMedMode('all')">
                        <input type="radio" name="_mm_radio" value="all">
                        <div><div class="mode-label">All <?= $cow_count ?> Cows</div><div class="mode-desc">Apply to all active cows</div></div>
                    </label>
                </div>
            </div>

            <!-- Single cow (medicine) -->
            <div id="mpanel-single" class="form-group">
                <label class="form-label">Cow <span style="color:var(--danger)">*</span></label>
                <select name="cow_id_med" id="m_single_cow" class="form-control" onchange="calcMedSummary()">
                    <option value="">— Select —</option>
                    <?php foreach ($active_cows as $c): ?>
                    <option value="<?= $c['id'] ?>">#<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Multiple cows (medicine) -->
            <div id="mpanel-multiple" style="display:none" class="form-group">
                <label class="form-label">Cows <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:.35rem;margin-bottom:.3rem;flex-wrap:wrap">
                    <input type="text" id="m_cow_search" class="form-control" placeholder="Filter…" style="flex:1;min-width:0;font-size:.78rem" oninput="filterMedCows()">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleMedCows(true)">All</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleMedCows(false)">None</button>
                </div>
                <div class="cow-cb-list" id="m_cow_list">
                    <?php foreach ($active_cows as $c): ?>
                    <label class="cow-cb-item" data-tag="<?= strtolower($c['tag_number']) ?>" data-breed="<?= strtolower($c['breed']) ?>">
                        <input type="checkbox" name="cow_ids_med[]" value="<?= $c['id'] ?>" onchange="calcMedSummary()">
                        <span><strong>#<?= e($c['tag_number']) ?></strong> <span style="color:var(--text-muted)"><?= e($c['breed']) ?></span></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="m_multi_count" style="font-size:.72rem;color:var(--text-muted);margin-top:.2rem">0 selected</div>
            </div>

            <!-- All cows (medicine) -->
            <div id="mpanel-all" style="display:none">
                <div class="alert" style="background:#f5f3ff;border-left:3px solid #7C3AED;padding:.55rem .8rem;font-size:.78rem;color:#4c1d95;margin-bottom:.65rem">
                    ⚠ Creates <strong><?= $cow_count ?> individual medicine records</strong> in one batch.
                </div>
            </div>

            <!-- Medicine selection -->
            <div class="form-group">
                <label class="form-label">Medicine (from stock) <span style="color:var(--danger)">*</span></label>
                <?php if (empty($med_usable)): ?>
                <div class="warn-expired">No usable medicine in stock. <a href="/modules/feed_medicine/medicine_form.php">Add medicine →</a></div>
                <input type="hidden" name="medicine_inv_id" value="0">
                <?php else: ?>
                <select name="medicine_inv_id" id="m_inv_sel" class="form-control" required onchange="onMedInvChange()">
                    <option value="">— Select medicine —</option>
                    <?php foreach ($med_usable as $mi): ?>
                    <?php
                        $days = $mi['days_to_expiry'];
                        $tag = '';
                        if ($days !== null && $days <= 7)  $tag = ' ⚠ expires '.$days.'d';
                        elseif ($days !== null && $days <= 30) $tag = ' · '.$days.'d left';
                    ?>
                    <option value="<?= $mi['id'] ?>"
                            data-qty="<?= $mi['quantity'] ?>"
                            data-unit="<?= e($mi['unit']) ?>"
                            data-cost="<?= $mi['cost_per_unit'] ?? 0 ?>"
                            data-expiry="<?= e($mi['expiry_date'] ?? '') ?>"
                            data-days="<?= $days ?>">
                        <?= e($mi['item_name']) ?><?= $tag ?> (<?= number_format($mi['quantity'],3) ?> <?= e($mi['unit']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <div id="m_stock_indicator" style="margin-top:.3rem"></div>
                <?php endif; ?>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.65rem">
                <div class="form-group">
                    <label class="form-label">Dosage / cow <span style="color:var(--danger)">*</span></label>
                    <div style="display:flex;align-items:center;gap:.3rem">
                        <input type="number" name="dosage_per_cow" id="m_dose" class="form-control"
                               step="0.001" min="0.001" placeholder="e.g. 5.000" required
                               oninput="calcMedSummary()">
                        <span id="m_unit_label" style="font-size:.78rem;color:var(--text-muted);white-space:nowrap">units</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="medicine_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <!-- Medicine batch preview -->
            <div id="m_preview" class="batch-preview med" style="display:none">
                <strong id="m_prev_cows">–</strong> cows ×
                <strong id="m_prev_dose">–</strong> =
                <strong id="m_prev_total">–</strong>
                <span id="m_prev_cost_block" style="display:none"> · ৳<strong id="m_prev_cost">–</strong></span>
                <span id="m_stock_check" style="margin-left:.4rem"></span>
            </div>

            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes_med" class="form-control" maxlength="255" placeholder="Optional — symptoms, treatment reason…">
            </div>

            <button type="submit" class="btn btn-purple btn-sm" id="m_submit_btn">💊 Save Medicine Entry</button>
        </form>
        <?php endif; ?>

    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     RIGHT PANEL — LOG VIEW
     ════════════════════════════════════════════════════════════════════════════ -->
<div style="display:flex;flex-direction:column;gap:1rem">

    <!-- Filter bar -->
    <div class="card">
        <div class="card-body" style="padding:.65rem 1rem">
            <form method="GET" action="/modules/feed_medicine/feed_log.php" style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:flex-end">
                <div class="form-group" style="margin:0;min-width:110px">
                    <label class="form-label">From</label>
                    <input type="date" name="from" class="form-control" value="<?= e($filter_from) ?>">
                </div>
                <div class="form-group" style="margin:0;min-width:110px">
                    <label class="form-label">To</label>
                    <input type="date" name="to" class="form-control" value="<?= e($filter_to) ?>">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label">Cow</label>
                    <select name="cow_id" class="form-control" style="min-width:140px">
                        <option value="">All</option>
                        <?php foreach ($active_cows as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filter_cow === (int)$c['id'] ? 'selected' : '' ?>>
                            #<?= e($c['tag_number']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($filter_batch): ?>
                <div class="form-group" style="margin:0">
                    <label class="form-label">Batch</label>
                    <input type="text" name="batch" class="form-control" value="<?= e($filter_batch) ?>" style="width:170px">
                </div>
                <?php endif; ?>
                <input type="hidden" name="tab" value="<?= e($active_tab) ?>">
                <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:.05rem">Apply</button>
                <a href="/modules/feed_medicine/feed_log.php?tab=<?= e($active_tab) ?>" class="btn btn-secondary btn-sm" style="margin-bottom:.05rem">Reset</a>
                <div style="display:flex;gap:.25rem;margin-left:auto;margin-bottom:.05rem">
                    <a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&cow_id=<?= $filter_cow ?>&tab=feed"
                       class="btn btn-sm <?= $active_tab === 'feed' ? 'btn-primary' : 'btn-secondary' ?>">🌾 Feed</a>
                    <a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&cow_id=<?= $filter_cow ?>&tab=medicine"
                       class="btn btn-sm <?= $active_tab === 'medicine' ? 'btn-purple' : 'btn-secondary' ?>">💊 Medicine</a>
                    <a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&cow_id=<?= $filter_cow ?>&tab=batches"
                       class="btn btn-sm <?= $active_tab === 'batches' ? 'btn-primary' : 'btn-secondary' ?>">📦 Batches</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── FEED ENTRIES ────────────────────────────────────────────────────── -->
    <?php if ($active_tab === 'feed'): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">Feed Entries</span>
            <span style="font-size:.72rem;color:var(--text-muted)"><?= count($feed_logs) ?> records<?= $filter_batch ? ' — batch: ' . e($filter_batch) : '' ?></span>
        </div>
        <div style="overflow-x:auto">
            <table class="table table-sm" style="font-size:.82rem">
                <thead>
                    <tr><th>Date</th><th>Cow</th><th>Feed Item</th><th>Qty (kg)</th><th>Cost/kg</th><th>Total</th><th>Batch</th><th>By</th><?php if (hasRole(['admin', 'manager'])): ?><th></th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php if (empty($feed_logs)): ?>
                    <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:2rem">No feed entries for this period.</td></tr>
                <?php else: ?>
                    <?php $prev_batch = null; foreach ($feed_logs as $log): $bc = $log['batch_id'] !== $prev_batch; $prev_batch = $log['batch_id']; ?>
                    <tr<?= $bc && $log['batch_id'] ? ' style="border-top:2px solid var(--border)"' : '' ?>>
                        <td><?= e($log['feed_date']) ?></td>
                        <td><strong>#<?= e($log['tag_number']) ?></strong> <span style="color:var(--text-muted)"><?= e($log['breed']) ?></span></td>
                        <td><?= e($log['inv_item_name'] ?? $log['feed_type']) ?></td>
                        <td><?= number_format($log['quantity_kg'],2) ?></td>
                        <td><?= $log['cost_per_kg'] > 0 ? '৳'.number_format($log['cost_per_kg'],2) : '—' ?></td>
                        <td><?= $log['total_cost'] > 0 ? '<strong>৳'.number_format($log['total_cost'],2).'</strong>' : '—' ?></td>
                        <td>
                            <?php if ($log['batch_id']): ?>
                            <a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&batch=<?= urlencode($log['batch_id']) ?>&tab=feed"
                               class="batch-pill"><?= e(substr($log['batch_id'],5,13)) ?>…</a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= e($log['recorder_name'] ?? '—') ?></td>
                        <?php if (hasRole(['admin', 'manager'])): ?>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this entry?')" style="margin:0">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"  value="delete_feed_log">
                                <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="padding:.12rem .4rem;font-size:.7rem">×</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── MEDICINE ENTRIES ───────────────────────────────────────────────── -->
    <?php elseif ($active_tab === 'medicine'): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">Medicine Entries</span>
            <span style="font-size:.72rem;color:var(--text-muted)"><?= count($med_logs) ?> records<?= $filter_batch ? ' — batch: ' . e($filter_batch) : '' ?></span>
        </div>
        <div style="overflow-x:auto">
            <table class="table table-sm" style="font-size:.82rem">
                <thead>
                    <tr><th>Date</th><th>Cow</th><th>Medicine</th><th>Dosage</th><th>Unit</th><th>Cost</th><th>Batch</th><th>By</th><?php if (hasRole(['admin', 'manager'])): ?><th></th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php if (empty($med_logs)): ?>
                    <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:2rem">No medicine entries for this period.</td></tr>
                <?php else: ?>
                    <?php $prev_batch = null; foreach ($med_logs as $log): $bc = $log['batch_id'] !== $prev_batch; $prev_batch = $log['batch_id']; ?>
                    <tr<?= $bc ? ' style="border-top:2px solid var(--border)"' : '' ?>>
                        <td><?= e($log['administered_at']) ?></td>
                        <td><strong>#<?= e($log['tag_number']) ?></strong> <span style="color:var(--text-muted)"><?= e($log['breed']) ?></span></td>
                        <td><?= e($log['medicine_name']) ?></td>
                        <td><?= number_format($log['dosage_per_cow'],3) ?></td>
                        <td><?= e($log['unit'] ?? '—') ?></td>
                        <td><?= $log['total_cost'] > 0 ? '<strong style="color:#7C3AED">৳'.number_format($log['total_cost'],2).'</strong>' : '—' ?></td>
                        <td>
                            <a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&batch=<?= urlencode($log['batch_id']) ?>&tab=medicine"
                               class="batch-pill med"><?= e(substr($log['batch_id'],4,13)) ?>…</a>
                        </td>
                        <td><?= e($log['recorder_name'] ?? '—') ?></td>
                        <?php if (hasRole(['admin', 'manager'])): ?>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this entry?')" style="margin:0">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"  value="delete_med_log">
                                <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="padding:.12rem .4rem;font-size:.7rem">×</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── BATCHES VIEW ───────────────────────────────────────────────────── -->
    <?php elseif ($active_tab === 'batches'): ?>
    <!-- Feed batches -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">🌾 Feed Batches</span>
            <a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&tab=feed" class="btn btn-secondary btn-sm" style="font-size:.72rem">View entries</a>
        </div>
        <div style="overflow-x:auto">
            <table class="table table-sm" style="font-size:.82rem">
                <thead><tr><th>Batch</th><th>Date</th><th>Feed</th><th>Cows</th><th>Total kg</th><th>Total Cost</th><th>Avg/cow</th><?php if (hasRole(['admin', 'manager'])): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php
                $fb_stmt = $db->prepare(
                    "SELECT batch_id, feed_date, feed_type, COUNT(*) AS cow_count,
                       SUM(quantity_kg) AS total_kg, SUM(total_cost) AS total_cost,
                       AVG(quantity_kg) AS avg_kg,
                       GROUP_CONCAT(DISTINCT c.tag_number ORDER BY c.tag_number SEPARATOR ', ') AS cow_tags
                     FROM feed_logs fl JOIN cows c ON c.id=fl.cow_id
                     WHERE fl.farm_id=? AND fl.feed_date BETWEEN ? AND ?
                     GROUP BY batch_id, feed_date, feed_type
                     ORDER BY feed_date DESC, batch_id DESC LIMIT 100"
                );
                $fb_stmt->execute([fid(), $filter_from, $filter_to]);
                $feed_batches = $fb_stmt->fetchAll();
                ?>
                <?php if (empty($feed_batches)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:1.5rem">No feed batches.</td></tr>
                <?php else: ?>
                    <?php foreach ($feed_batches as $b): ?>
                    <tr>
                        <td><a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&batch=<?= urlencode($b['batch_id']) ?>&tab=feed" class="batch-pill"><?= e(substr($b['batch_id'],5,13)) ?>…</a></td>
                        <td><?= e($b['feed_date']) ?></td>
                        <td><strong><?= e($b['feed_type']) ?></strong></td>
                        <td><?= $b['cow_count'] ?></td>
                        <td><?= number_format($b['total_kg'],1) ?> kg</td>
                        <td><?= $b['total_cost'] > 0 ? '৳'.number_format($b['total_cost'],0) : '—' ?></td>
                        <td><?= number_format($b['avg_kg'],2) ?> kg</td>
                        <?php if (hasRole(['admin', 'manager'])): ?>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete all <?= $b['cow_count'] ?> entries in this batch?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"   value="delete_batch_feed">
                                <input type="hidden" name="batch_id" value="<?= e($b['batch_id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="font-size:.7rem;padding:.15rem .4rem">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Medicine batches -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">💊 Medicine Batches</span>
            <a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&tab=medicine" class="btn btn-secondary btn-sm" style="font-size:.72rem">View entries</a>
        </div>
        <div style="overflow-x:auto">
            <table class="table table-sm" style="font-size:.82rem">
                <thead><tr><th>Batch</th><th>Date</th><th>Medicine</th><th>Cows</th><th>Total Dose</th><th>Total Cost</th><?php if (hasRole(['admin', 'manager'])): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php
                $mb_stmt = $db->prepare(
                    "SELECT batch_id, administered_at, medicine_name, COUNT(*) AS cow_count,
                       SUM(dosage_per_cow) AS total_dose, SUM(total_cost) AS total_cost, unit
                     FROM medicine_logs WHERE farm_id=? AND administered_at BETWEEN ? AND ?
                     GROUP BY batch_id, administered_at, medicine_name, unit
                     ORDER BY administered_at DESC, batch_id DESC LIMIT 100"
                );
                $mb_stmt->execute([fid(), $filter_from, $filter_to]);
                $med_batches = $mb_stmt->fetchAll();
                ?>
                <?php if (empty($med_batches)): ?>
                    <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:1.5rem">No medicine batches.</td></tr>
                <?php else: ?>
                    <?php foreach ($med_batches as $b): ?>
                    <tr>
                        <td><a href="?from=<?= urlencode($filter_from) ?>&to=<?= urlencode($filter_to) ?>&batch=<?= urlencode($b['batch_id']) ?>&tab=medicine" class="batch-pill med"><?= e(substr($b['batch_id'],4,13)) ?>…</a></td>
                        <td><?= e($b['administered_at']) ?></td>
                        <td><strong><?= e($b['medicine_name']) ?></strong></td>
                        <td><?= $b['cow_count'] ?></td>
                        <td><?= number_format($b['total_dose'],3) ?> <?= e($b['unit'] ?? '') ?></td>
                        <td><?= $b['total_cost'] > 0 ? '<strong style="color:#7C3AED">৳'.number_format($b['total_cost'],0).'</strong>' : '—' ?></td>
                        <?php if (hasRole(['admin', 'manager'])): ?>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete all <?= $b['cow_count'] ?> medicine entries in this batch?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"   value="delete_batch_med">
                                <input type="hidden" name="batch_id" value="<?= e($b['batch_id']) ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="font-size:.7rem;padding:.15rem .4rem">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /right column -->
</div><!-- /grid -->

<script>
// ── Active inventory data passed from PHP ──────────────────────────────────
var _cowCount = <?= $cow_count ?>;
var _feedInvData = {
    <?php foreach ($feed_inventory as $fi): ?>
    <?= $fi['id'] ?>: {qty:<?= (float)$fi['quantity'] ?>,unit:'<?= addslashes($fi['unit']) ?>',cost:<?= (float)($fi['purchase_price']??0) ?>,threshold:<?= (float)$fi['reorder_threshold'] ?>},
    <?php endforeach; ?>
};
var _medInvData = {
    <?php foreach ($med_inventory as $mi): ?>
    <?= $mi['id'] ?>: {qty:<?= (float)$mi['quantity'] ?>,unit:'<?= addslashes($mi['unit']) ?>',cost:<?= (float)($mi['cost_per_unit']??0) ?>,days:<?= is_null($mi['days_to_expiry']) ? 'null' : (int)$mi['days_to_expiry'] ?>,expiry:'<?= addslashes($mi['expiry_date']??'') ?>'},
    <?php endforeach; ?>
};

// ── Type switcher ──────────────────────────────────────────────────────────
function switchType(type) {
    var isFeed = type === 'feed';
    document.getElementById('feedForm').style.display = isFeed ? '' : 'none';
    var mf = document.getElementById('medForm');
    if (mf) mf.style.display = isFeed ? 'none' : '';
    document.getElementById('tbtn-feed').className = 'type-btn ' + (isFeed ? 'active-feed' : '');
    var mbtn = document.getElementById('tbtn-med');
    if (mbtn) mbtn.className = 'type-btn ' + (!isFeed ? 'active-med' : '');
}

// ── FEED: mode selector ────────────────────────────────────────────────────
function setFeedMode(mode) {
    document.getElementById('feed_mode_input').value = mode;
    ['single','multiple','all'].forEach(function(m) {
        var card = document.getElementById('fc-' + m);
        var panel = document.getElementById('fpanel-' + m);
        card.classList.toggle('selected', m === mode);
        card.querySelector('input[type=radio]').checked = (m === mode);
        if (panel) panel.style.display = (m === mode) ? '' : 'none';
    });
    var btn = document.getElementById('f_submit_btn');
    btn.textContent = mode === 'all' ? '🌾 Apply to All ' + _cowCount + ' Cows' : '🌾 Save Feed Entry';
    btn.className = 'btn btn-sm ' + (mode === 'all' ? 'btn-danger' : 'btn-success');
    calcFeedSummary();
}

function filterFeedCows() {
    var q = document.getElementById('f_cow_search').value.toLowerCase();
    document.querySelectorAll('#f_cow_list .cow-cb-item').forEach(function(el) {
        el.style.display = (!q || el.dataset.tag.includes(q) || el.dataset.breed.includes(q)) ? '' : 'none';
    });
}
function toggleFeedCows(check) {
    document.querySelectorAll('#f_cow_list input[type=checkbox]').forEach(function(cb) {
        if (cb.closest('.cow-cb-item').style.display !== 'none') cb.checked = check;
    });
    calcFeedSummary();
}

// ── MEDICINE: mode selector ────────────────────────────────────────────────
function setMedMode(mode) {
    document.getElementById('med_mode_input').value = mode;
    ['single','multiple','all'].forEach(function(m) {
        var card = document.getElementById('mc-' + m);
        var panel = document.getElementById('mpanel-' + m);
        card.classList.toggle('selected', m === mode);
        card.querySelector('input[type=radio]').checked = (m === mode);
        if (panel) panel.style.display = (m === mode) ? '' : 'none';
    });
    var btn = document.getElementById('m_submit_btn');
    if (btn) {
        btn.textContent = mode === 'all' ? '💊 Administer to All ' + _cowCount + ' Cows' : '💊 Save Medicine Entry';
        btn.className = 'btn btn-sm ' + (mode === 'all' ? 'btn-danger' : 'btn-purple');
    }
    calcMedSummary();
}

function filterMedCows() {
    var q = document.getElementById('m_cow_search').value.toLowerCase();
    document.querySelectorAll('#m_cow_list .cow-cb-item').forEach(function(el) {
        el.style.display = (!q || el.dataset.tag.includes(q) || el.dataset.breed.includes(q)) ? '' : 'none';
    });
}
function toggleMedCows(check) {
    document.querySelectorAll('#m_cow_list input[type=checkbox]').forEach(function(cb) {
        if (cb.closest('.cow-cb-item').style.display !== 'none') cb.checked = check;
    });
    calcMedSummary();
}

// ── Feed: inventory selection → populate fields + stock indicator ──────────
function onFeedInvChange() {
    var sel = document.getElementById('f_inv_sel');
    if (!sel) return;
    var id  = parseInt(sel.value);
    var inv = _feedInvData[id];
    if (!inv) { document.getElementById('f_stock_indicator').innerHTML = ''; return; }
    // Unit label
    document.getElementById('f_unit_label').textContent = inv.unit || 'kg';
    // Auto-fill cost
    var costEl = document.getElementById('f_cost');
    if (costEl && inv.cost > 0 && !costEl.value) costEl.value = inv.cost.toFixed(2);
    // Stock indicator
    renderFeedStockBadge(inv);
    calcFeedSummary();
}

function renderFeedStockBadge(inv) {
    var el = document.getElementById('f_stock_indicator');
    if (!el) return;
    var cls, txt;
    if (inv.qty <= 0) { cls='stock-out'; txt='⛔ Out of stock'; }
    else if (inv.threshold > 0 && inv.qty <= inv.threshold) { cls='stock-low'; txt='⚠ Low: ' + inv.qty.toFixed(2) + ' ' + inv.unit + ' available'; }
    else { cls='stock-ok'; txt='✓ ' + inv.qty.toFixed(2) + ' ' + inv.unit + ' available'; }
    el.innerHTML = '<span class="stock-badge ' + cls + '">' + txt + '</span>';
}

function calcFeedSummary() {
    var mode  = document.getElementById('feed_mode_input').value;
    var qty   = parseFloat(document.getElementById('f_qty') ? document.getElementById('f_qty').value : 0) || 0;
    var cost  = parseFloat(document.getElementById('f_cost') ? document.getElementById('f_cost').value : 0) || 0;
    var invSel = document.getElementById('f_inv_sel');
    var inv   = invSel ? _feedInvData[parseInt(invSel.value)] : null;

    var cowCount = 0;
    if (mode === 'all') cowCount = _cowCount;
    else if (mode === 'multiple') cowCount = document.querySelectorAll('#f_cow_list input[type=checkbox]:checked').length;
    else cowCount = document.getElementById('f_single_cow') && document.getElementById('f_single_cow').value ? 1 : 0;

    // Update multi count label
    if (mode === 'multiple') {
        var lbl = document.getElementById('f_multi_count');
        if (lbl) lbl.textContent = cowCount + ' cow' + (cowCount !== 1 ? 's' : '') + ' selected';
    }

    var prev = document.getElementById('f_preview');
    if (!prev) return;
    if (cowCount > 0 && qty > 0) {
        var totalKg = cowCount * qty;
        prev.style.display = '';
        document.getElementById('f_prev_cows').textContent = cowCount;
        document.getElementById('f_prev_qty').textContent  = qty.toFixed(2) + ' kg';
        document.getElementById('f_prev_total_kg').textContent = totalKg.toFixed(2) + ' kg';
        // Cost
        var costBlock = document.getElementById('f_prev_cost_block');
        if (cost > 0) {
            costBlock.style.display = '';
            document.getElementById('f_prev_cost').textContent = (cowCount * qty * cost).toLocaleString();
        } else costBlock.style.display = 'none';
        // Stock check
        var sc = document.getElementById('f_stock_check');
        if (inv) {
            if (inv.qty < totalKg) sc.innerHTML = '<span style="color:#dc2626;font-weight:700">⛔ Need ' + totalKg.toFixed(2) + ' ' + inv.unit + ' but only ' + inv.qty.toFixed(2) + ' available!</span>';
            else sc.innerHTML = '<span style="color:#15803d">✓ Stock OK</span>';
        } else sc.innerHTML = '';
    } else {
        prev.style.display = 'none';
    }
}

// ── Medicine: inventory selection → stock + expiry indicator ──────────────
function onMedInvChange() {
    var sel = document.getElementById('m_inv_sel');
    if (!sel) return;
    var id  = parseInt(sel.value);
    var inv = _medInvData[id];
    if (!inv) { document.getElementById('m_stock_indicator').innerHTML = ''; return; }
    document.getElementById('m_unit_label').textContent = inv.unit || 'units';
    var costEl; // no separate cost input for medicine — taken from inventory
    renderMedStockBadge(inv);
    calcMedSummary();
}

function renderMedStockBadge(inv) {
    var el = document.getElementById('m_stock_indicator');
    if (!el) return;
    var parts = [];
    if (inv.qty <= 0) parts.push('<span class="stock-badge stock-out">⛔ Out of stock</span>');
    else parts.push('<span class="stock-badge stock-ok">✓ ' + inv.qty.toFixed(3) + ' ' + inv.unit + ' available</span>');
    if (inv.expiry) {
        if (inv.days !== null && inv.days < 0) parts.push('<span class="stock-badge stock-expired">⛔ Expired ' + inv.expiry + '</span>');
        else if (inv.days !== null && inv.days <= 7)  parts.push('<span class="stock-badge stock-expiring">⚠ Expires in ' + inv.days + ' days</span>');
        else if (inv.days !== null && inv.days <= 30) parts.push('<span class="stock-badge stock-expiring">' + inv.days + 'd to expiry</span>');
    }
    el.innerHTML = parts.join(' ');
}

function calcMedSummary() {
    var mode = document.getElementById('med_mode_input').value;
    var dose = parseFloat(document.getElementById('m_dose') ? document.getElementById('m_dose').value : 0) || 0;
    var invSel = document.getElementById('m_inv_sel');
    var inv  = invSel ? _medInvData[parseInt(invSel.value)] : null;

    var cowCount = 0;
    if (mode === 'all') cowCount = _cowCount;
    else if (mode === 'multiple') cowCount = document.querySelectorAll('#m_cow_list input[type=checkbox]:checked').length;
    else cowCount = document.getElementById('m_single_cow') && document.getElementById('m_single_cow').value ? 1 : 0;

    if (mode === 'multiple') {
        var lbl = document.getElementById('m_multi_count');
        if (lbl) lbl.textContent = cowCount + ' cow' + (cowCount !== 1 ? 's' : '') + ' selected';
    }

    var prev = document.getElementById('m_preview');
    if (!prev) return;
    if (cowCount > 0 && dose > 0) {
        var totalDose = cowCount * dose;
        prev.style.display = '';
        document.getElementById('m_prev_cows').textContent = cowCount;
        document.getElementById('m_prev_dose').textContent  = dose.toFixed(3) + (inv ? ' ' + inv.unit : '');
        document.getElementById('m_prev_total').textContent = totalDose.toFixed(3) + (inv ? ' ' + inv.unit : '');
        // Cost
        var costBlock = document.getElementById('m_prev_cost_block');
        if (inv && inv.cost > 0) {
            costBlock.style.display = '';
            document.getElementById('m_prev_cost').textContent = (cowCount * dose * inv.cost).toLocaleString();
        } else costBlock.style.display = 'none';
        // Stock check
        var sc = document.getElementById('m_stock_check');
        if (inv) {
            if (inv.days !== null && inv.days < 0) sc.innerHTML = '<span style="color:#dc2626;font-weight:700">⛔ Expired medicine!</span>';
            else if (inv.qty < totalDose) sc.innerHTML = '<span style="color:#dc2626;font-weight:700">⛔ Need ' + totalDose.toFixed(3) + ' but only ' + inv.qty.toFixed(3) + ' available!</span>';
            else sc.innerHTML = '<span style="color:#15803d">✓ Stock OK</span>';
        } else sc.innerHTML = '';
    } else {
        prev.style.display = 'none';
    }
}

// ── Init: if current tab is medicine, switch the form ─────────────────────
(function() {
    var tab = '<?= e($active_tab) ?>';
    if (tab === 'medicine') switchType('medicine');
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
