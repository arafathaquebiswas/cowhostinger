<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'accountant']);
requireFarmScope();
requireNotBlocked();

$page_title = 'Veterinarians';
$active_nav = 'veterinarians';
$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/treatments/veterinarians.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_vet') {
        $vet_id    = (int)($_POST['vet_id'] ?? 0);
        $name      = trim($_POST['name']      ?? '');
        $phone     = trim($_POST['phone']     ?? '');
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $specialty = trim($_POST['specialty'] ?? '');
        $address   = trim($_POST['address']   ?? '');
        $visit_fee = trim($_POST['visit_fee'] ?? '');
        $status    = $_POST['status'] ?? 'active';
        $notes     = trim($_POST['notes']     ?? '');

        $errs = [];
        if ($name === '') $errs[] = 'Name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'Invalid email.';
        $fee = ($visit_fee !== '' && is_numeric($visit_fee)) ? (float)$visit_fee : null;
        if ($visit_fee !== '' && ($fee === null || $fee < 0)) $errs[] = 'Visit fee must be a positive number.';
        if (!in_array($status, ['active','inactive'])) $status = 'active';

        if (!empty($errs)) {
            flashMessage('error', implode(' ', $errs));
        } else {
            $params_vals = [
                fid(), $name,
                $phone     !== '' ? $phone     : null,
                $email     !== '' ? $email     : null,
                $address   !== '' ? $address   : null,
                $specialty !== '' ? $specialty : null,
                $fee,
                $status,
                $notes !== '' ? $notes : null,
            ];

            if ($vet_id > 0) {
                // Update
                $chk = $db->prepare("SELECT id FROM veterinarians WHERE id = ? AND farm_id = ?");
                $chk->execute([$vet_id, fid()]);
                if ($chk->fetch()) {
                    $db->prepare(
                        "UPDATE veterinarians SET name=?,phone=?,email=?,address=?,specialty=?,visit_fee=?,status=?,notes=?
                         WHERE id=? AND farm_id=?"
                    )->execute([
                        $name,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                        $address !== '' ? $address : null,
                        $specialty !== '' ? $specialty : null,
                        $fee, $status,
                        $notes !== '' ? $notes : null,
                        $vet_id, fid(),
                    ]);
                    auditLog($uid, 'UPDATE_VET', 'veterinarians', $vet_id);
                    flashMessage('success', 'Veterinarian updated.');
                }
            } else {
                // Insert
                $db->prepare(
                    "INSERT INTO veterinarians (farm_id,name,phone,email,address,specialty,visit_fee,status,notes)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute($params_vals);
                $new_id = (int)$db->lastInsertId();
                auditLog($uid, 'CREATE_VET', 'veterinarians', $new_id, null, ['name' => $name]);
                flashMessage('success', "Veterinarian \"{$name}\" added.");
            }
        }
        redirect('/modules/treatments/veterinarians.php');
    }

    if ($action === 'add_visit') {
        $vet_id     = (int)($_POST['vet_id']     ?? 0);
        $cow_id     = (int)($_POST['cow_id']     ?? 0);
        $visit_date = trim($_POST['visit_date']   ?? '');
        $fee_type   = $_POST['fee_type']          ?? 'visit';
        $amount     = trim($_POST['amount']       ?? '');
        $notes      = trim($_POST['notes']        ?? '');

        $errs = [];
        if ($vet_id <= 0) $errs[] = 'Select a vet.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date) || $visit_date > date('Y-m-d'))
            $errs[] = 'Invalid visit date.';
        if (!is_numeric($amount) || (float)$amount < 0) $errs[] = 'Invalid amount.';
        if (!in_array($fee_type, ['visit','treatment','contract','emergency'])) $fee_type = 'visit';

        // Verify vet belongs to farm
        if ($vet_id > 0) {
            $cv = $db->prepare("SELECT id FROM veterinarians WHERE id=? AND farm_id=?");
            $cv->execute([$vet_id, fid()]);
            if (!$cv->fetch()) $errs[] = 'Vet not found.';
        }

        if (!empty($errs)) {
            flashMessage('error', implode(' ', $errs));
        } else {
            $db->prepare(
                "INSERT INTO vet_visits (farm_id,vet_id,cow_id,visit_date,fee_type,amount,notes,recorded_by)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([
                fid(), $vet_id, $cow_id > 0 ? $cow_id : null,
                $visit_date, $fee_type, (float)$amount,
                $notes !== '' ? $notes : null, $uid,
            ]);
            auditLog($uid, 'CREATE_VET_VISIT', 'vet_visits', (int)$db->lastInsertId(), null,
                ['vet_id' => $vet_id, 'amount' => $amount, 'date' => $visit_date]);
            flashMessage('success', "Vet visit recorded — ৳" . number_format((float)$amount, 2) . ".");
        }
        redirect('/modules/treatments/veterinarians.php');
    }

    if ($action === 'delete_visit') {
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        if ($visit_id > 0) {
            $db->prepare("DELETE FROM vet_visits WHERE id=? AND farm_id=?")->execute([$visit_id, fid()]);
            auditLog($uid, 'DELETE_VET_VISIT', 'vet_visits', $visit_id);
            flashMessage('success', 'Visit deleted.');
        }
        redirect('/modules/treatments/veterinarians.php');
    }
}

// ── Edit mode ─────────────────────────────────────────────────────────────────
$edit_vet = null;
if (isset($_GET['edit'])) {
    $ev = $db->prepare("SELECT * FROM veterinarians WHERE id=? AND farm_id=?");
    $ev->execute([(int)$_GET['edit'], fid()]);
    $edit_vet = $ev->fetch() ?: null;
}

// ── Vet list ──────────────────────────────────────────────────────────────────
$vets = $db->prepare(
    "SELECT v.*, COUNT(vv.id) AS visit_count, COALESCE(SUM(vv.amount),0) AS total_paid
     FROM veterinarians v
     LEFT JOIN vet_visits vv ON vv.vet_id = v.id AND vv.farm_id = v.farm_id
     WHERE v.farm_id = ?
     GROUP BY v.id
     ORDER BY v.status ASC, v.name ASC"
);
$vets->execute([fid()]);
$vets = $vets->fetchAll();

// ── Recent visits ─────────────────────────────────────────────────────────────
$visits = $db->prepare(
    "SELECT vv.*, v.name AS vet_name, c.tag_number, u.name AS recorder
     FROM vet_visits vv
     JOIN veterinarians v ON v.id = vv.vet_id
     LEFT JOIN cows c ON c.id = vv.cow_id
     LEFT JOIN users u ON u.id = vv.recorded_by
     WHERE vv.farm_id = ?
     ORDER BY vv.visit_date DESC, vv.id DESC
     LIMIT 100"
);
$visits->execute([fid()]);
$visits = $visits->fetchAll();

// ── KPIs ─────────────────────────────────────────────────────────────────────
$kpi = $db->prepare(
    "SELECT COUNT(*) AS visits_this_month, COALESCE(SUM(amount),0) AS cost_this_month
     FROM vet_visits
     WHERE farm_id=? AND MONTH(visit_date)=MONTH(CURDATE()) AND YEAR(visit_date)=YEAR(CURDATE())"
);
$kpi->execute([fid()]);
$kpi = $kpi->fetch();

$active_cows = $db->prepare(
    "SELECT id, tag_number, breed FROM cows WHERE " . farmFilter() . " AND status NOT IN ('sold','deceased') ORDER BY tag_number"
);
$active_cows->execute();
$active_cows = $active_cows->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Veterinarians</h2>
        <p class="text-sm text-muted">Manage vets and track visit costs</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('addVetPanel').style.display='block';this.style.display='none'">
        + Add Vet
    </button>
</div>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Vets Registered</div>
        <div style="font-size:1.8rem;font-weight:700;color:var(--success)"><?= count($vets) ?></div>
    </div>
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Visits This Month</div>
        <div style="font-size:1.8rem;font-weight:700"><?= $kpi['visits_this_month'] ?></div>
    </div>
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Vet Cost (Month)</div>
        <div style="font-size:1.8rem;font-weight:700;color:var(--danger)">৳<?= number_format($kpi['cost_this_month'], 0) ?></div>
    </div>
</div>

<!-- Add/Edit Vet Form -->
<div id="addVetPanel" style="<?= $edit_vet ? '' : 'display:none' ?>;margin-bottom:1.5rem">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><?= $edit_vet ? 'Edit Vet' : 'Add Veterinarian' ?></span>
            <?php if (!$edit_vet): ?>
            <button onclick="this.closest('#addVetPanel').style.display='none';document.querySelector('.page-header .btn').style.display=''"
                    class="btn btn-secondary btn-sm">Cancel</button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="/modules/treatments/veterinarians.php" novalidate>
                <?= csrfField() ?>
                <input type="hidden" name="action"  value="save_vet">
                <input type="hidden" name="vet_id"  value="<?= $edit_vet ? $edit_vet['id'] : 0 ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:var(--danger)">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="150"
                               value="<?= e($edit_vet['name'] ?? '') ?>" placeholder="Dr. Rahman">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" maxlength="30"
                               value="<?= e($edit_vet['phone'] ?? '') ?>" placeholder="01700-000000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="150"
                               value="<?= e($edit_vet['email'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Specialty</label>
                        <input type="text" name="specialty" class="form-control" maxlength="150"
                               value="<?= e($edit_vet['specialty'] ?? '') ?>" placeholder="e.g. Bovine medicine">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default Visit Fee (৳)</label>
                        <input type="number" name="visit_fee" class="form-control" step="0.01" min="0"
                               value="<?= e($edit_vet['visit_fee'] ?? '') ?>" placeholder="e.g. 500">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active"   <?= ($edit_vet['status'] ?? '') === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($edit_vet['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" maxlength="300"
                           value="<?= e($edit_vet['address'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($edit_vet['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><?= $edit_vet ? 'Update Vet' : 'Add Vet' ?></button>
                <?php if ($edit_vet): ?>
                <a href="/modules/treatments/veterinarians.php" class="btn btn-secondary btn-sm">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<!-- Vet list -->
<?php if (!empty($vets)): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card-header"><span class="card-title">Registered Vets</span></div>
    <div style="overflow-x:auto">
        <table class="table">
            <thead><tr><th>Name</th><th>Specialty</th><th>Phone</th><th>Default Fee</th><th>Visits</th><th>Total Paid</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($vets as $v): ?>
            <tr>
                <td><strong><?= e($v['name']) ?></strong></td>
                <td style="font-size:.82rem"><?= e($v['specialty'] ?? '—') ?></td>
                <td style="font-size:.82rem"><?= e($v['phone'] ?? '—') ?></td>
                <td><?= $v['visit_fee'] ? '৳'.number_format($v['visit_fee'],0) : '—' ?></td>
                <td><?= $v['visit_count'] ?></td>
                <td><strong>৳<?= number_format($v['total_paid'],0) ?></strong></td>
                <td><span class="badge <?= $v['status']==='active' ? 'badge-success' : 'badge-secondary' ?>"><?= $v['status'] ?></span></td>
                <td>
                    <a href="/modules/treatments/veterinarians.php?edit=<?= $v['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <button class="btn btn-primary btn-sm" onclick="openVisitModal(<?= $v['id'] ?>, <?= e(json_encode($v['name'])) ?>, <?= (float)$v['visit_fee'] ?>)">
                        + Visit
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Recent visits -->
<div class="card">
    <div class="card-header"><span class="card-title">Visit History</span></div>
    <div style="overflow-x:auto">
        <table class="table table-sm">
            <thead><tr><th>Date</th><th>Vet</th><th>Cow</th><th>Type</th><th>Amount</th><th>Notes</th><th>By</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($visits)): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:2rem">No vet visits recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($visits as $vv): ?>
                <tr>
                    <td><?= e($vv['visit_date']) ?></td>
                    <td><strong><?= e($vv['vet_name']) ?></strong></td>
                    <td><?= $vv['tag_number'] ? '#'.e($vv['tag_number']) : '<span style="color:var(--text-muted)">Farm visit</span>' ?></td>
                    <td><span class="badge badge-info" style="font-size:.7rem"><?= e($vv['fee_type']) ?></span></td>
                    <td><strong>৳<?= number_format($vv['amount'], 2) ?></strong></td>
                    <td style="font-size:.78rem"><?= e($vv['notes'] ?? '') ?></td>
                    <td style="font-size:.78rem"><?= e($vv['recorder'] ?? '—') ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('Delete this visit?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"   value="delete_visit">
                            <input type="hidden" name="visit_id" value="<?= $vv['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="padding:.2rem .5rem;font-size:.72rem">×</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Visit modal -->
<div id="visitModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center">
    <div class="card" style="width:460px;max-width:95vw;max-height:90vh;overflow-y:auto">
        <div class="card-header">
            <span class="card-title">Record Vet Visit</span>
            <button onclick="document.getElementById('visitModal').style.display='none'" class="btn btn-secondary btn-sm">✕</button>
        </div>
        <div class="card-body">
            <form method="POST" action="/modules/treatments/veterinarians.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_visit">
                <input type="hidden" name="vet_id" id="modal_vet_id" value="">
                <div class="form-group">
                    <label class="form-label">Vet</label>
                    <input type="text" id="modal_vet_name" class="form-control" readonly style="background:var(--bg-muted,#f5f5f5)">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Visit Date <span style="color:var(--danger)">*</span></label>
                        <input type="date" name="visit_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="fee_type" class="form-control">
                            <option value="visit">Regular Visit</option>
                            <option value="treatment">Treatment</option>
                            <option value="emergency">Emergency</option>
                            <option value="contract">Contract</option>
                        </select>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Amount (৳) <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="amount" id="modal_amount" class="form-control"
                               step="0.01" min="0" placeholder="e.g. 500" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cow (optional)</label>
                        <select name="cow_id" class="form-control">
                            <option value="">Farm-wide visit</option>
                            <?php foreach ($active_cows as $c): ?>
                            <option value="<?= $c['id'] ?>">#<?= e($c['tag_number']) ?> <?= e($c['breed']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Diagnosis, treatment given..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save Visit</button>
            </form>
        </div>
    </div>
</div>

<script>
function openVisitModal(vetId, vetName, defaultFee) {
    document.getElementById('modal_vet_id').value   = vetId;
    document.getElementById('modal_vet_name').value = vetName;
    if (defaultFee > 0) document.getElementById('modal_amount').value = defaultFee.toFixed(2);
    var m = document.getElementById('visitModal');
    m.style.display = 'flex';
}
document.getElementById('visitModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
