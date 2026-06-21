<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'accountant']);
requireFarmScope();
requireNotBlocked();

$page_title = 'Buyers';
$active_nav = 'buyers';
$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── Helper: find an existing buyer by phone or NID ────────────────────────────
function _findBuyerByIdentity(PDO $db, int $farm_id, string $phone, string $nid, int $exclude_id = 0): ?array
{
    $conditions = [];
    $params     = [];

    if ($phone !== '') {
        $conditions[] = 'phone = ?';
        $params[]     = $phone;
    }
    if ($nid !== '') {
        $conditions[] = 'nid = ?';
        $params[]     = $nid;
    }
    if (empty($conditions)) return null;

    $sql = "SELECT * FROM buyers WHERE farm_id = ? AND (" . implode(' OR ', $conditions) . ")";
    $p   = array_merge([$farm_id], $params);

    if ($exclude_id > 0) {
        $sql .= " AND id != ?";
        $p[]  = $exclude_id;
    }

    $stmt = $db->prepare($sql . " LIMIT 1");
    $stmt->execute($p);
    return $stmt->fetch() ?: null;
}

// ── Helper: merge incoming data onto existing buyer (never blank out valid data) ─
function _mergeBuyer(array $existing, string $name, string $phone, string $nid, string $address, string $notes): array
{
    return [
        'name'    => $name    !== '' ? $name    : $existing['name'],
        'phone'   => $phone   !== '' ? $phone   : ($existing['phone']   ?? ''),
        'nid'     => $nid     !== '' ? $nid     : ($existing['nid']     ?? ''),
        'address' => $address !== '' ? $address : ($existing['address'] ?? ''),
        'notes'   => $notes   !== '' ? $notes   : ($existing['notes']   ?? ''),
    ];
}

$flash_type = '';
$flash_msg  = '';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/sales/buyers.php');
    }

    $action   = $_POST['action']    ?? '';
    $buyer_id = (int)($_POST['buyer_id'] ?? 0);
    $name     = trim($_POST['name']     ?? '');
    $phone    = preg_replace('/\s+/', '', trim($_POST['phone'] ?? '')); // normalise whitespace
    $nid      = preg_replace('/\s+/', '', trim($_POST['nid']   ?? ''));
    $address  = trim($_POST['address']  ?? '');
    $notes    = trim($_POST['notes']    ?? '');

    // ── Save buyer (add or update) ────────────────────────────────────────────
    if ($action === 'save_buyer') {
        $errs = [];

        // At least one identity field required
        $nid_image_path = null;
        $has_image = isset($_FILES['nid_image']) && $_FILES['nid_image']['error'] === UPLOAD_ERR_OK;

        if ($phone === '' && $nid === '' && !$has_image) {
            $errs[] = 'Provide at least one identifier: phone number, NID, or NID image upload.';
        }
        if ($phone !== '' && !preg_match('/^[0-9\+\-]{7,20}$/', $phone)) {
            $errs[] = 'Phone number format is invalid.';
        }

        // Upload NID image if provided
        if ($has_image && empty($errs)) {
            $nid_image_path = uploadImage($_FILES['nid_image'], 'buyer_nid');
            if (!$nid_image_path) {
                $errs[] = 'NID image upload failed. Only JPG/PNG/GIF/WebP under 5 MB allowed.';
            }
        }

        if (!empty($errs)) {
            flashMessage('error', implode(' ', $errs));
            redirect('/modules/sales/buyers.php' . ($buyer_id > 0 ? "?edit={$buyer_id}" : ''));
        }

        // ── DUPLICATE DETECTION ───────────────────────────────────────────────
        $match = _findBuyerByIdentity($db, fid(), $phone, $nid, $buyer_id);

        if ($buyer_id === 0 && $match) {
            // ── NEW buyer request but identity already exists → MERGE ─────────
            $merged = _mergeBuyer($match, $name, $phone, $nid, $address, $notes);

            // Track address change
            $old_addr = $match['address'] ?? '';
            if ($address !== '' && $address !== $old_addr) {
                $db->prepare(
                    "INSERT INTO buyer_address_history (buyer_id,farm_id,old_address,new_address,changed_by) VALUES (?,?,?,?,?)"
                )->execute([$match['id'], fid(), $old_addr ?: null, $address, $uid]);
            }

            $db->prepare(
                "UPDATE buyers SET name=?,phone=?,nid=?,address=?,notes=?,nid_image_path=COALESCE(?,nid_image_path)
                 WHERE id=? AND farm_id=?"
            )->execute([
                $merged['name'],
                $merged['phone'] !== '' ? $merged['phone'] : null,
                $merged['nid']   !== '' ? $merged['nid']   : null,
                $merged['address'] !== '' ? $merged['address'] : null,
                $merged['notes']   !== '' ? $merged['notes']   : null,
                $nid_image_path,
                $match['id'], fid(),
            ]);
            auditLog($uid, 'MERGE_BUYER', 'buyers', $match['id'], null, ['reason' => 'duplicate_identity']);
            flashMessage('success', "Buyer already exists — record updated with new details (ID #{$match['id']}).");
            redirect('/modules/sales/buyers.php');
        }

        if ($buyer_id > 0) {
            // ── UPDATE existing buyer ─────────────────────────────────────────
            // Re-check identity conflict with OTHER buyers
            if ($match && (int)$match['id'] !== $buyer_id) {
                $which = ($match['phone'] === $phone && $phone !== '') ? "phone {$phone}" : "NID {$nid}";
                flashMessage('error', "Cannot update: {$which} belongs to buyer #{$match['id']} ({$match['name']}).");
                redirect("/modules/sales/buyers.php?edit={$buyer_id}");
            }

            $cv = $db->prepare("SELECT * FROM buyers WHERE id=? AND farm_id=?");
            $cv->execute([$buyer_id, fid()]);
            $existing = $cv->fetch();
            if (!$existing) {
                flashMessage('error', 'Buyer not found.');
                redirect('/modules/sales/buyers.php');
            }

            // Track address change if it changed
            $old_addr = $existing['address'] ?? '';
            if ($address !== '' && $address !== $old_addr) {
                $db->prepare(
                    "INSERT INTO buyer_address_history (buyer_id,farm_id,old_address,new_address,changed_by) VALUES (?,?,?,?,?)"
                )->execute([$buyer_id, fid(), $old_addr ?: null, $address, $uid]);
            }

            $db->prepare(
                "UPDATE buyers SET name=?,phone=?,nid=?,address=?,notes=?,
                   nid_image_path=COALESCE(?,nid_image_path)
                 WHERE id=? AND farm_id=?"
            )->execute([
                $name    !== '' ? $name    : $existing['name'],
                $phone   !== '' ? $phone   : ($existing['phone']   ?? null),
                $nid     !== '' ? $nid     : ($existing['nid']     ?? null),
                $address !== '' ? $address : ($existing['address'] ?? null),
                $notes   !== '' ? $notes   : ($existing['notes']   ?? null),
                $nid_image_path,
                $buyer_id, fid(),
            ]);
            auditLog($uid, 'UPDATE_BUYER', 'buyers', $buyer_id);
            flashMessage('success', 'Buyer updated.');
            redirect('/modules/sales/buyers.php');
        }

        // ── CREATE new buyer ──────────────────────────────────────────────────
        $db->prepare(
            "INSERT INTO buyers (farm_id,name,phone,nid,nid_image_path,address,notes)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            fid(),
            $name    !== '' ? $name    : null,
            $phone   !== '' ? $phone   : null,
            $nid     !== '' ? $nid     : null,
            $nid_image_path,
            $address !== '' ? $address : null,
            $notes   !== '' ? $notes   : null,
        ]);
        $new_id = (int)$db->lastInsertId();
        auditLog($uid, 'CREATE_BUYER', 'buyers', $new_id, null, ['phone' => $phone, 'nid' => $nid]);
        flashMessage('success', "Buyer added (ID #{$new_id}).");
        redirect('/modules/sales/buyers.php');
    }

    // ── Delete buyer ──────────────────────────────────────────────────────────
    if ($action === 'delete_buyer' && hasRole(['admin', 'manager'])) {
        $cs = $db->prepare("SELECT COUNT(*) FROM cow_sales  WHERE buyer_id=? AND farm_id=?"); $cs->execute([$buyer_id, fid()]);
        $ms = $db->prepare("SELECT COUNT(*) FROM meat_sales WHERE buyer_id=? AND farm_id=?"); $ms->execute([$buyer_id, fid()]);
        if ($cs->fetchColumn() + $ms->fetchColumn() > 0) {
            flashMessage('error', 'Cannot delete — buyer is linked to sales records. Update those records first.');
        } else {
            $db->prepare("DELETE FROM buyers WHERE id=? AND farm_id=?")->execute([$buyer_id, fid()]);
            $db->prepare("DELETE FROM buyer_address_history WHERE buyer_id=? AND farm_id=?")->execute([$buyer_id, fid()]);
            auditLog($uid, 'DELETE_BUYER', 'buyers', $buyer_id);
            flashMessage('success', 'Buyer deleted.');
        }
        redirect('/modules/sales/buyers.php');
    }
}

// ── Edit mode ─────────────────────────────────────────────────────────────────
$edit_buyer     = null;
$addr_history   = [];
if (isset($_GET['edit'])) {
    $eb = $db->prepare("SELECT * FROM buyers WHERE id=? AND farm_id=?");
    $eb->execute([(int)$_GET['edit'], fid()]);
    $edit_buyer = $eb->fetch() ?: null;

    if ($edit_buyer) {
        $ah = $db->prepare(
            "SELECT bah.*, u.name AS changed_by_name
             FROM buyer_address_history bah
             LEFT JOIN users u ON u.id = bah.changed_by
             WHERE bah.buyer_id=? AND bah.farm_id=?
             ORDER BY bah.changed_at DESC LIMIT 10"
        );
        $ah->execute([(int)$_GET['edit'], fid()]);
        $addr_history = $ah->fetchAll();
    }
}

// ── Buyer list with sales counts ──────────────────────────────────────────────
$buyers = $db->prepare(
    "SELECT b.*,
       (SELECT COUNT(*) FROM cow_sales  WHERE buyer_id=b.id AND farm_id=b.farm_id) AS cow_sales_count,
       (SELECT COUNT(*) FROM meat_sales WHERE buyer_id=b.id AND farm_id=b.farm_id) AS meat_sales_count,
       (SELECT COALESCE(SUM(sale_price),0)    FROM cow_sales  WHERE buyer_id=b.id AND farm_id=b.farm_id) AS cow_sales_total,
       (SELECT COALESCE(SUM(total_revenue),0) FROM meat_sales WHERE buyer_id=b.id AND farm_id=b.farm_id) AS meat_sales_total
     FROM buyers b
     WHERE b.farm_id = ?
     ORDER BY (b.name IS NULL), b.name ASC, b.id ASC"
);
$buyers->execute([fid()]);
$buyers = $buyers->fetchAll();

// Search
$search_q = trim($_GET['q'] ?? '');
if ($search_q !== '') {
    $buyers = array_filter($buyers, function ($b) use ($search_q) {
        $haystack = strtolower(($b['name'] ?? '') . ' ' . ($b['phone'] ?? '') . ' ' . ($b['nid'] ?? ''));
        return str_contains($haystack, strtolower($search_q));
    });
}

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Buyers</h2>
        <p class="text-sm text-muted">One buyer = one ID. Phone or NID uniqueness enforced per farm.</p>
    </div>
    <a href="/modules/sales/index.php" class="btn btn-secondary">Sales</a>
</div>

<!-- Smart identity rule notice -->
<div class="alert" style="background:#eff6ff;border-left:3px solid #2563eb;padding:.65rem 1rem;font-size:.82rem;margin-bottom:1.25rem;color:#1e3a8a">
    <strong>Identity rule:</strong> Phone number and NID are unique per farm.
    If a matching phone or NID already exists, the existing record is updated — no duplicate is created.
    At least one of <strong>phone, NID, or NID image</strong> is required.
</div>

<div style="display:grid;grid-template-columns:400px 1fr;gap:1.25rem;align-items:start">

    <!-- Add / Edit Form -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><?= $edit_buyer ? 'Edit Buyer #'.$edit_buyer['id'] : 'Add / Find Buyer' ?></span>
            <?php if ($edit_buyer): ?>
            <a href="/modules/sales/buyers.php" class="btn btn-secondary btn-sm">+ New</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="/modules/sales/buyers.php" novalidate enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action"   value="save_buyer">
                <input type="hidden" name="buyer_id" value="<?= $edit_buyer ? $edit_buyer['id'] : 0 ?>">

                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" class="form-control" maxlength="150"
                           value="<?= e($edit_buyer['name'] ?? '') ?>" placeholder="Buyer name (optional)">
                </div>

                <!-- Identity fields -->
                <fieldset style="border:1px solid var(--border);border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem">
                    <legend style="font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;padding:0 .4rem">
                        Identity — at least one required
                    </legend>
                    <div class="form-group">
                        <label class="form-label">
                            Phone Number
                            <span style="font-size:.7rem;color:var(--text-muted);font-weight:400">(unique per farm)</span>
                        </label>
                        <input type="tel" name="phone" class="form-control" maxlength="20"
                               value="<?= e($edit_buyer['phone'] ?? '') ?>"
                               placeholder="01700-000000"
                               oninput="checkIdentity()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            NID Number
                            <span style="font-size:.7rem;color:var(--text-muted);font-weight:400">(unique per farm)</span>
                        </label>
                        <input type="text" name="nid" class="form-control" maxlength="30"
                               value="<?= e($edit_buyer['nid'] ?? '') ?>"
                               placeholder="National ID number"
                               oninput="checkIdentity()">
                    </div>
                    <div class="form-group" style="margin-bottom:0">
                        <label class="form-label">
                            NID Image Upload
                            <span style="font-size:.7rem;color:var(--text-muted);font-weight:400">(fallback identity proof)</span>
                        </label>
                        <?php if ($edit_buyer && !empty($edit_buyer['nid_image_path'])): ?>
                        <div style="margin-bottom:.5rem;font-size:.82rem">
                            Current:
                            <a href="/<?= e($edit_buyer['nid_image_path']) ?>" target="_blank" style="font-weight:600">View NID image ↗</a>
                            | <a href="/<?= e($edit_buyer['nid_image_path']) ?>" download style="font-weight:600">Download ↓</a>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="nid_image" class="form-control"
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               style="padding:.35rem">
                        <span class="form-hint">JPG/PNG/WebP, max 5 MB. Replaces existing image.</span>
                    </div>
                </fieldset>

                <div class="form-group">
                    <label class="form-label">
                        Address
                        <span style="font-size:.7rem;color:var(--text-muted);font-weight:400">(always editable, history kept)</span>
                    </label>
                    <input type="text" name="address" class="form-control" maxlength="300"
                           value="<?= e($edit_buyer['address'] ?? '') ?>"
                           placeholder="Current address">
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" maxlength="500"
                           value="<?= e($edit_buyer['notes'] ?? '') ?>">
                </div>

                <!-- Duplicate preview (JS) -->
                <div id="dup_notice" style="display:none;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.5rem .75rem;font-size:.78rem;color:#92400e;margin-bottom:.75rem">
                    <strong>⚠ Match detected:</strong> Saving will <strong>merge</strong> into existing buyer rather than creating a duplicate.
                </div>

                <button type="submit" class="btn btn-primary btn-sm">
                    <?= $edit_buyer ? 'Update Buyer' : 'Save / Merge' ?>
                </button>
                <?php if ($edit_buyer): ?>
                <a href="/modules/sales/buyers.php" class="btn btn-secondary btn-sm">Cancel</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Address history panel -->
        <?php if ($edit_buyer && !empty($addr_history)): ?>
        <div class="card-header" style="border-top:1px solid var(--border)">
            <span class="card-title" style="font-size:.82rem">Address History</span>
        </div>
        <div class="card-body" style="padding-top:.5rem">
            <?php foreach ($addr_history as $ah): ?>
            <div style="font-size:.75rem;border-left:2px solid var(--border);padding:.3rem .6rem;margin-bottom:.4rem">
                <span style="color:var(--text-muted)"><?= e(substr($ah['changed_at'],0,16)) ?></span>
                by <strong><?= e($ah['changed_by_name'] ?? '—') ?></strong><br>
                <span style="color:var(--danger)">← <?= e($ah['old_address'] ?? '(empty)') ?></span><br>
                <span style="color:var(--success)">→ <?= e($ah['new_address'] ?? '(empty)') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Buyer table -->
    <div>
        <!-- Search -->
        <div class="card" style="padding:.65rem 1rem;margin-bottom:.75rem">
            <form method="GET" action="/modules/sales/buyers.php" style="display:flex;gap:.5rem;align-items:center">
                <input type="text" name="q" class="form-control" placeholder="Search by name, phone, or NID…"
                       value="<?= e($search_q) ?>" style="max-width:320px">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <?php if ($search_q): ?>
                <a href="/modules/sales/buyers.php" class="btn btn-secondary btn-sm">Clear</a>
                <?php endif; ?>
                <span style="font-size:.78rem;color:var(--text-muted);margin-left:auto"><?= count($buyers) ?> buyer<?= count($buyers) !== 1 ? 's' : '' ?></span>
            </form>
        </div>

        <div class="card">
            <div style="overflow-x:auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:32px">#</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>NID</th>
                            <th>Address</th>
                            <th>NID Doc</th>
                            <th style="text-align:right">Total Spent</th>
                            <th>Txns</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($buyers)): ?>
                        <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:2.5rem">
                            <?= $search_q ? "No buyers match \"{$search_q}\"." : 'No buyers added yet.' ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($buyers as $b):
                            $total_spent = (float)$b['cow_sales_total'] + (float)$b['meat_sales_total'];
                            $txn_count   = (int)$b['cow_sales_count']  + (int)$b['meat_sales_count'];
                        ?>
                        <tr <?= $edit_buyer && (int)$edit_buyer['id'] === (int)$b['id'] ? 'style="background:var(--success-soft,#e8f5e9)"' : '' ?>>
                            <td style="color:var(--text-muted);font-size:.78rem"><?= $b['id'] ?></td>
                            <td>
                                <a href="/modules/sales/buyers.php?edit=<?= $b['id'] ?>" style="font-weight:600">
                                    <?= $b['name'] ? e($b['name']) : '<span style="color:var(--text-muted);font-style:italic">No name</span>' ?>
                                </a>
                            </td>
                            <td style="font-size:.85rem;font-family:monospace"><?= e($b['phone'] ?? '—') ?></td>
                            <td style="font-size:.82rem"><?= e($b['nid'] ?? '—') ?></td>
                            <td style="font-size:.78rem;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                                title="<?= e($b['address'] ?? '') ?>"><?= e($b['address'] ?? '—') ?></td>
                            <td style="text-align:center">
                                <?php if (!empty($b['nid_image_path'])): ?>
                                <a href="/<?= e($b['nid_image_path']) ?>" target="_blank" title="View NID" style="color:var(--success);font-size:.78rem">📄</a>
                                <?php else: ?>
                                <span style="color:var(--text-muted);font-size:.75rem">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;font-weight:600">
                                <?= $total_spent > 0 ? '৳'.number_format($total_spent,0) : '—' ?>
                            </td>
                            <td style="font-size:.78rem;color:var(--text-muted)">
                                <?php if ($txn_count > 0): ?>
                                <?= $b['cow_sales_count'] > 0 ? $b['cow_sales_count'].' cow' : '' ?>
                                <?= $b['meat_sales_count'] > 0 ? ($b['cow_sales_count'] > 0 ? ', ' : '').$b['meat_sales_count'].' meat' : '' ?>
                                <?php else: ?>
                                <span style="color:var(--text-muted)">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <a href="/modules/sales/buyers.php?edit=<?= $b['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <?php if ($txn_count === 0 && hasRole(['admin', 'manager'])): ?>
                                <form method="POST" style="display:inline"
                                      onsubmit="return confirm('Delete buyer <?= e(addslashes($b['name'] ?? '#'.$b['id'])) ?>?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"   value="delete_buyer">
                                    <input type="hidden" name="buyer_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                                <?php elseif ($txn_count > 0): ?>
                                <span style="font-size:.7rem;color:var(--text-muted)"><?= $txn_count ?> sale<?= $txn_count > 1 ? 's' : '' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Client-side identity match hint (cosmetic only — server enforces the real rule)
var _existingBuyers = <?= json_encode(array_map(fn($b) => [
    'phone' => $b['phone'] ?? '',
    'nid'   => $b['nid']   ?? '',
], $buyers)) ?>;

var _editId = <?= $edit_buyer ? $edit_buyer['id'] : 0 ?>;

function checkIdentity() {
    var phone = document.querySelector('[name="phone"]').value.replace(/\s/g,'');
    var nid   = document.querySelector('[name="nid"]').value.replace(/\s/g,'');
    var notice = document.getElementById('dup_notice');

    if (_editId > 0) { notice.style.display = 'none'; return; }

    var match = _existingBuyers.some(function(b) {
        return (phone && b.phone === phone) || (nid && b.nid === nid);
    });
    notice.style.display = match ? '' : 'none';
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
