<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'veterinarian']);
requireFarmScope();
requireNotBlocked();

$page_title = 'Death Records';
$active_nav = 'cows';
$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// ── POST: record death ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/cows/death_record.php');
    }

    $action         = $_POST['action']        ?? '';
    $cow_id         = (int)($_POST['cow_id']  ?? 0);
    $death_date     = trim($_POST['death_date']     ?? '');
    $cause          = trim($_POST['cause']           ?? 'unknown');
    $disease_name   = trim($_POST['disease_name']   ?? '');
    $vet_notes      = trim($_POST['vet_notes']       ?? '');
    $financial_loss = trim($_POST['financial_loss'] ?? '');

    if ($action === 'record_death') {
        $errs = [];

        if ($cow_id <= 0) {
            $errs[] = 'Select a cow.';
        } else {
            $cv = $db->prepare("SELECT id, status FROM cows WHERE id=? AND " . farmFilter());
            $cv->execute([$cow_id]);
            $cow_row = $cv->fetch();
            if (!$cow_row) {
                $errs[] = 'Cow not found.';
            } elseif ($cow_row['status'] === 'deceased') {
                $errs[] = 'Cow is already marked as deceased.';
            } elseif ($cow_row['status'] === 'sold') {
                $errs[] = 'Cow has already been sold — cannot mark as deceased.';
            }
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $death_date) || $death_date > date('Y-m-d'))
            $errs[] = 'Invalid death date.';

        $valid_causes = ['disease','accident','old_age','calving_complication','unknown','other'];
        if (!in_array($cause, $valid_causes)) $cause = 'unknown';

        $loss = ($financial_loss !== '' && is_numeric($financial_loss)) ? (float)$financial_loss : null;
        if ($financial_loss !== '' && ($loss === null || $loss < 0))
            $errs[] = 'Financial loss must be a positive number.';

        // Check no duplicate death record
        if ($cow_id > 0 && empty($errs)) {
            $dup = $db->prepare("SELECT id FROM cow_death_records WHERE cow_id=? AND farm_id=?");
            $dup->execute([$cow_id, fid()]);
            if ($dup->fetch()) $errs[] = 'A death record already exists for this cow.';
        }

        if (!empty($errs)) {
            flashMessage('error', implode(' ', $errs));
        } else {
            $db->beginTransaction();
            try {
                // Insert death record
                $db->prepare(
                    "INSERT INTO cow_death_records
                       (farm_id,cow_id,death_date,cause,disease_name,vet_notes,financial_loss,recorded_by)
                     VALUES (?,?,?,?,?,?,?,?)"
                )->execute([
                    fid(), $cow_id, $death_date, $cause,
                    $disease_name !== '' ? $disease_name : null,
                    $vet_notes    !== '' ? $vet_notes    : null,
                    $loss, $uid,
                ]);
                $dr_id = (int)$db->lastInsertId();

                // Update cow status to deceased
                $db->prepare("UPDATE cows SET status='deceased' WHERE id=? AND " . farmFilter())
                   ->execute([$cow_id]);

                $db->commit();
                auditLog($uid, 'COW_DEATH', 'cow_death_records', $dr_id, null,
                    ['cow_id' => $cow_id, 'cause' => $cause, 'date' => $death_date]);

                flashMessage('success', 'Death recorded and cow status updated to deceased.');
                redirect('/modules/cows/death_record.php');
            } catch (Throwable $e) {
                $db->rollBack();
                error_log('[DEATH_RECORD] ' . $e->getMessage());
                flashMessage('error', 'Failed to save death record. Please try again.');
            }
        }
    }

    if ($action === 'delete_death' && hasRole(['admin', 'manager'])) {
        $dr_id = (int)($_POST['dr_id'] ?? 0);
        if ($dr_id > 0) {
            $dr = $db->prepare("SELECT cow_id FROM cow_death_records WHERE id=? AND farm_id=?");
            $dr->execute([$dr_id, fid()]);
            $dr_row = $dr->fetch();
            if ($dr_row) {
                $db->beginTransaction();
                $db->prepare("DELETE FROM cow_death_records WHERE id=? AND farm_id=?")->execute([$dr_id, fid()]);
                $db->prepare("UPDATE cows SET status='active' WHERE id=? AND " . farmFilter())->execute([$dr_row['cow_id']]);
                $db->commit();
                auditLog($uid, 'DELETE_DEATH_RECORD', 'cow_death_records', $dr_id);
                flashMessage('success', 'Death record removed and cow status restored to active.');
            }
        }
        redirect('/modules/cows/death_record.php');
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
// Active/grace cows that can be marked as deceased
$living_cows = $db->prepare(
    "SELECT c.id, c.tag_number, c.breed, c.purchase_price, c.birth_date
     FROM cows c
     WHERE " . farmFilter('c') . " AND c.status NOT IN ('sold','deceased')
       AND c.id NOT IN (SELECT cow_id FROM cow_death_records WHERE farm_id = ?)
     ORDER BY c.tag_number ASC"
);
$living_cows->execute([fid()]);
$living_cows = $living_cows->fetchAll();

// Existing death records
$deaths = $db->prepare(
    "SELECT dr.*, c.tag_number, c.breed, c.purchase_price AS cow_purchase_price,
       u.name AS recorder
     FROM cow_death_records dr
     JOIN cows c ON c.id = dr.cow_id
     LEFT JOIN users u ON u.id = dr.recorded_by
     WHERE dr.farm_id = ?
     ORDER BY dr.death_date DESC, dr.id DESC"
);
$deaths->execute([fid()]);
$deaths = $deaths->fetchAll();

// KPIs
$kpi = $db->prepare(
    "SELECT COUNT(*) AS total_deaths,
       COALESCE(SUM(financial_loss),0) AS total_loss,
       COUNT(CASE WHEN YEAR(death_date)=YEAR(CURDATE()) THEN 1 END) AS deaths_this_year
     FROM cow_death_records WHERE farm_id=?"
);
$kpi->execute([fid()]);
$kpi = $kpi->fetch();

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Death Records</h2>
        <p class="text-sm text-muted">Record and track cow deaths with financial loss</p>
    </div>
    <a href="/modules/cows/index.php" class="btn btn-secondary">All Cows</a>
</div>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Total Deaths</div>
        <div style="font-size:1.8rem;font-weight:700"><?= $kpi['total_deaths'] ?></div>
    </div>
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">This Year</div>
        <div style="font-size:1.8rem;font-weight:700;color:var(--warning,#e65100)"><?= $kpi['deaths_this_year'] ?></div>
    </div>
    <div class="card" style="padding:1rem">
        <div style="font-size:.74rem;color:var(--text-muted);text-transform:uppercase">Financial Loss</div>
        <div style="font-size:1.8rem;font-weight:700;color:var(--danger)">৳<?= number_format($kpi['total_loss'], 0) ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:380px 1fr;gap:1.25rem;align-items:start">

    <!-- Record Form -->
    <div class="card">
        <div class="card-header"><span class="card-title">Record Cow Death</span></div>
        <div class="card-body">
            <?php if (empty($living_cows)): ?>
            <p style="color:var(--text-muted);font-size:.85rem">No active cows available to mark as deceased.</p>
            <?php else: ?>
            <div class="alert" style="background:#fff3cd;border-left:3px solid #f90;padding:.6rem .9rem;font-size:.82rem;margin-bottom:1rem">
                This will permanently set the cow's status to <strong>deceased</strong>.
            </div>
            <form method="POST" action="/modules/cows/death_record.php" novalidate
                  onsubmit="return confirm('Mark this cow as deceased? This action cannot be undone without admin intervention.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="record_death">

                <div class="form-group">
                    <label class="form-label">Cow <span style="color:var(--danger)">*</span></label>
                    <select name="cow_id" class="form-control" required onchange="prefillLoss(this)">
                        <option value="">— Select cow —</option>
                        <?php foreach ($living_cows as $c): ?>
                        <option value="<?= $c['id'] ?>"
                                data-price="<?= $c['purchase_price'] ?? 0 ?>">
                            #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                            <?= $c['purchase_price'] ? ' (৳'.number_format($c['purchase_price'],0).')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                    <div class="form-group">
                        <label class="form-label">Death Date <span style="color:var(--danger)">*</span></label>
                        <input type="date" name="death_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cause <span style="color:var(--danger)">*</span></label>
                        <select name="cause" class="form-control" id="cause_sel" onchange="toggleDisease()">
                            <option value="disease">Disease</option>
                            <option value="accident">Accident</option>
                            <option value="old_age">Old Age</option>
                            <option value="calving_complication">Calving Complication</option>
                            <option value="unknown" selected>Unknown</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="disease_field" style="display:none">
                    <label class="form-label">Disease / Condition Name</label>
                    <input type="text" name="disease_name" class="form-control" maxlength="200"
                           placeholder="e.g. Foot-and-mouth disease">
                </div>

                <div class="form-group">
                    <label class="form-label">Financial Loss (৳)</label>
                    <input type="number" name="financial_loss" id="f_loss" class="form-control"
                           step="0.01" min="0" placeholder="Auto-filled from purchase price">
                    <span class="form-hint">Leave blank or enter estimated market value</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Vet Notes / Details</label>
                    <textarea name="vet_notes" class="form-control" rows="3"
                              placeholder="Symptoms, treatment attempted, observations..."></textarea>
                </div>

                <button type="submit" class="btn btn-danger btn-sm">Record Death</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Death History -->
    <div class="card">
        <div class="card-header"><span class="card-title">Death History</span></div>
        <div style="overflow-x:auto">
            <table class="table table-sm">
                <thead>
                    <tr><th>Date</th><th>Cow</th><th>Cause</th><th>Disease</th><th>Financial Loss</th><th>Notes</th><th>By</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (empty($deaths)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:2rem">No death records.</td></tr>
                <?php else: ?>
                    <?php foreach ($deaths as $d): ?>
                    <tr>
                        <td><?= e($d['death_date']) ?></td>
                        <td>
                            <strong>#<?= e($d['tag_number']) ?></strong>
                            <span style="font-size:.75rem;color:var(--text-muted)"><?= e($d['breed']) ?></span>
                        </td>
                        <td>
                            <span class="badge badge-<?= match($d['cause']) {
                                'disease' => 'danger', 'accident' => 'warning',
                                'old_age' => 'secondary', 'calving_complication' => 'info',
                                default => 'secondary'
                            } ?>">
                                <?= e(str_replace('_',' ',ucfirst($d['cause']))) ?>
                            </span>
                        </td>
                        <td style="font-size:.78rem"><?= e($d['disease_name'] ?? '—') ?></td>
                        <td><?= $d['financial_loss'] ? '<strong style="color:var(--danger)">৳'.number_format($d['financial_loss'],0).'</strong>' : '—' ?></td>
                        <td style="font-size:.75rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                            title="<?= e($d['vet_notes'] ?? '') ?>"><?= e(mb_strimwidth($d['vet_notes'] ?? '—', 0, 60, '…')) ?></td>
                        <td style="font-size:.78rem"><?= e($d['recorder'] ?? '—') ?></td>
                        <td>
                            <?php if (hasRole(['admin', 'manager'])): ?>
                            <form method="POST" onsubmit="return confirm('Restore this cow to active and delete the death record?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_death">
                                <input type="hidden" name="dr_id"  value="<?= $d['id'] ?>">
                                <button type="submit" class="btn btn-secondary btn-sm" style="font-size:.72rem">Undo</button>
                            </form>
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

<script>
function prefillLoss(sel) {
    var opt = sel.options[sel.selectedIndex];
    var price = parseFloat(opt.getAttribute('data-price')) || 0;
    if (price > 0) document.getElementById('f_loss').value = price.toFixed(2);
}
function toggleDisease() {
    var c = document.getElementById('cause_sel').value;
    document.getElementById('disease_field').style.display = (c === 'disease' || c === 'other') ? '' : 'none';
}
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
