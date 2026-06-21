<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'manager', 'veterinarian']);
requireFarmScope();
requireNotBlocked();
requireModule('breeding');

$db    = getDB();
$br_id = (int)($_GET['id'] ?? 0);
$is_edit = $br_id > 0;
$existing = null;

$errors = [];
$prefill_cow = (int)($_GET['cow_id'] ?? 0);

$form = [
    'cow_id'                => $prefill_cow > 0 ? (string)$prefill_cow : '',
    'heat_cycle_date'       => '',
    'insemination_date'     => '',
    'breeding_date'         => '',
    'expected_calving_date' => '',
    'actual_calving_date'   => '',
    'status'                => 'heat',
    'notes'                 => '',
];

$cow_list = $db->prepare(
    "SELECT id, tag_number, breed FROM cows WHERE " . farmFilter() . " AND status NOT IN ('sold','deceased') ORDER BY tag_number ASC"
);
$cow_list->execute();
$cow_list = $cow_list->fetchAll();

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM breeding_records WHERE id = ? AND " . farmFilter());
    $sel->execute([$br_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Breeding record not found.');
        redirect('/modules/breeding/index.php');
    }
    $form = [
        'cow_id'                => $existing['cow_id'],
        'heat_cycle_date'       => $existing['heat_cycle_date']       ?? '',
        'insemination_date'     => $existing['insemination_date']     ?? '',
        'breeding_date'         => $existing['breeding_date']         ?? '',
        'expected_calving_date' => $existing['expected_calving_date'] ?? '',
        'actual_calving_date'   => $existing['actual_calving_date']   ?? '',
        'status'                => $existing['status'],
        'notes'                 => $existing['notes'] ?? '',
    ];
}

$valid_statuses = ['heat', 'inseminated', 'pregnant', 'calved', 'failed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/breeding/form.php' . ($is_edit ? "?id={$br_id}" : ''));
    }

    $form['cow_id']                = trim($_POST['cow_id']                ?? '');
    $form['heat_cycle_date']       = trim($_POST['heat_cycle_date']       ?? '');
    $form['insemination_date']     = trim($_POST['insemination_date']     ?? '');
    $form['breeding_date']         = trim($_POST['breeding_date']         ?? '');
    $form['expected_calving_date'] = trim($_POST['expected_calving_date'] ?? '');
    $form['actual_calving_date']   = trim($_POST['actual_calving_date']   ?? '');
    $form['status']                = sanitize($_POST['status']            ?? 'heat');
    $form['notes']                 = sanitize($_POST['notes']             ?? '');

    $cow_id = (int)$form['cow_id'];
    if ($cow_id <= 0) $errors[] = 'Please select a cow.';
    if (!in_array($form['status'], $valid_statuses, true)) $errors[] = 'Invalid status.';

    $date_fields = ['heat_cycle_date','insemination_date','breeding_date','expected_calving_date','actual_calving_date'];
    foreach ($date_fields as $df) {
        if ($form[$df] !== '' && !strtotime($form[$df])) {
            $errors[] = "Invalid date for " . str_replace('_', ' ', $df) . '.';
        }
    }

    if ($form['insemination_date'] !== '' && $form['heat_cycle_date'] !== ''
        && strtotime($form['insemination_date']) < strtotime($form['heat_cycle_date'])) {
        $errors[] = 'Insemination date cannot be before heat cycle date.';
    }

    if ($form['expected_calving_date'] !== '' && $form['breeding_date'] !== ''
        && strtotime($form['expected_calving_date']) < strtotime($form['breeding_date'])) {
        $errors[] = 'Expected calving date cannot be before breeding date.';
    }

    $cow = null;
    if (empty($errors)) {
        $sel = $db->prepare("SELECT id, tag_number, status, is_pregnant FROM cows WHERE id = ? AND " . farmFilter() . " AND status NOT IN ('sold','deceased')");
        $sel->execute([$cow_id]);
        $cow = $sel->fetch();
        if (!$cow) $errors[] = 'Selected cow is not available.';
    }

    if (empty($errors)) {
        $user_id = (int)$_SESSION['user_id'];
        $nullify = static fn(string $v): ?string => $v !== '' ? $v : null;

        $prev_status = $is_edit ? $existing['status'] : null;

        if ($is_edit) {
            $db->prepare(
                "UPDATE breeding_records SET cow_id=?, heat_cycle_date=?, insemination_date=?,
                 breeding_date=?, expected_calving_date=?, actual_calving_date=?, status=?, notes=? WHERE id=? AND " . farmFilter()
            )->execute([
                $cow_id, $nullify($form['heat_cycle_date']), $nullify($form['insemination_date']),
                $nullify($form['breeding_date']), $nullify($form['expected_calving_date']),
                $nullify($form['actual_calving_date']), $form['status'], $nullify($form['notes']), $br_id,
            ]);
            auditLog($user_id, 'UPDATE_BREEDING_RECORD', 'breeding_records', $br_id, $existing, $form);
            $record_id = $br_id;
        } else {
            $db->prepare(
                "INSERT INTO breeding_records
                 (farm_id, cow_id, heat_cycle_date, insemination_date, breeding_date,
                  expected_calving_date, actual_calving_date, status, recorded_by, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                fid(), $cow_id, $nullify($form['heat_cycle_date']), $nullify($form['insemination_date']),
                $nullify($form['breeding_date']), $nullify($form['expected_calving_date']),
                $nullify($form['actual_calving_date']), $form['status'], $user_id, $nullify($form['notes']),
            ]);
            $record_id = (int)$db->lastInsertId();
            auditLog($user_id, 'CREATE_BREEDING_RECORD', 'breeding_records', $record_id, null, $form);
        }

        // Sync cow pregnant/status based on breeding status
        if ($form['status'] === 'pregnant' && $prev_status !== 'pregnant') {
            $db->prepare("UPDATE cows SET is_pregnant=1, status='pregnant' WHERE id=? AND status NOT IN ('sold','deceased')")
               ->execute([$cow_id]);
        } elseif (in_array($form['status'], ['failed','calved'], true) && $prev_status === 'pregnant') {
            $db->prepare("UPDATE cows SET is_pregnant=0 WHERE id=?")->execute([$cow_id]);
        }

        // Create calving-soon alert if expected within 14 days and not already alerted
        $exp_date = $nullify($form['expected_calving_date']);
        if ($exp_date && $form['status'] === 'pregnant') {
            $days_left = (int)((strtotime($exp_date) - strtotime('today')) / 86400);
            if ($days_left <= 14 && $days_left >= 0) {
                $chk = $db->prepare(
                    "SELECT id FROM alerts WHERE related_table='breeding_records' AND related_id=? AND type='calving_soon'"
                );
                $chk->execute([$record_id]);
                if (!$chk->fetch()) {
                    $severity = $days_left <= 3 ? 'critical' : 'high';
                    $msg      = "Cow #{$cow['tag_number']} expected to calve on " . formatDate($exp_date)
                                . " ({$days_left} day" . ($days_left !== 1 ? 's' : '') . " away).";
                    createAlert('calving_soon', $severity, $msg, 'breeding_records', $record_id);
                }
            }
        }

        flashMessage('success', $is_edit ? 'Breeding record updated.' : 'Breeding record added.');
        redirect('/modules/breeding/index.php');
    }
}

$page_title = $is_edit ? 'Edit Breeding Record' : 'Add Breeding Record';
$active_nav = 'breeding';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div><h2><?= $is_edit ? 'Edit Breeding Record' : 'Add Breeding Record' ?></h2></div>
    <a href="/modules/breeding/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/breeding/form.php<?= $is_edit ? "?id={$br_id}" : '' ?>" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:680px;margin-bottom:1.25rem">
        <div class="card-body">

            <div class="form-group">
                <label class="form-label" for="cow_id">Cow <span style="color:var(--danger)">*</span></label>
                <?php if ($is_edit): ?>
                <?php
                $cur_cow = null;
                foreach ($cow_list as $c) { if ($c['id'] == $form['cow_id']) { $cur_cow = $c; break; } }
                // also check sold/deceased cows
                if (!$cur_cow) {
                    $sc = $db->prepare("SELECT id, tag_number, breed FROM cows WHERE id = ?");
                    $sc->execute([(int)$form['cow_id']]);
                    $cur_cow = $sc->fetch();
                }
                ?>
                <div style="padding:.55rem .85rem;background:var(--bg-muted);border:1px solid var(--border);border-radius:var(--radius);font-weight:600">
                    #<?= $cur_cow ? e($cur_cow['tag_number']) . ' — ' . e($cur_cow['breed']) : e($form['cow_id']) ?>
                </div>
                <input type="hidden" name="cow_id" value="<?= (int)$form['cow_id'] ?>">
                <?php else: ?>
                <select id="cow_id" name="cow_id" class="form-control" required>
                    <option value="">— Select cow —</option>
                    <?php foreach ($cow_list as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (string)$c['id']===$form['cow_id']?'selected':'' ?>>
                        #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label">Status</label>
                <div style="display:flex;gap:1rem;flex-wrap:wrap">
                    <?php foreach (['heat'=>'Heat','inseminated'=>'Inseminated','pregnant'=>'Pregnant','calved'=>'Calved','failed'=>'Failed'] as $sv=>$sl): ?>
                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer">
                        <input type="radio" name="status" value="<?= $sv ?>"
                               <?= $form['status']===$sv?'checked':'' ?>>
                        <?= $sl ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <h4 style="font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:.5rem 0 .75rem">Dates</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="heat_cycle_date">Heat Cycle Date</label>
                    <input type="date" id="heat_cycle_date" name="heat_cycle_date" class="form-control"
                           value="<?= e($form['heat_cycle_date'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="insemination_date">Insemination Date</label>
                    <input type="date" id="insemination_date" name="insemination_date" class="form-control"
                           value="<?= e($form['insemination_date'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="breeding_date">Breeding Date</label>
                    <input type="date" id="breeding_date" name="breeding_date" class="form-control"
                           value="<?= e($form['breeding_date'] ?? '') ?>"
                           id="breeding_date">
                </div>
                <div class="form-group">
                    <label class="form-label" for="expected_calving_date">
                        Expected Calving Date
                        <span class="text-muted" style="font-weight:400;font-size:.78rem">(auto-calc from breeding + 283d)</span>
                    </label>
                    <input type="date" id="expected_calving_date" name="expected_calving_date" class="form-control"
                           value="<?= e($form['expected_calving_date'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="actual_calving_date">Actual Calving Date</label>
                    <input type="date" id="actual_calving_date" name="actual_calving_date" class="form-control"
                           value="<?= e($form['actual_calving_date'] ?? '') ?>">
                    <span class="form-hint">Leave blank until calving occurs.</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="Bull ID, AI technician, semen batch, observations…"><?= e($form['notes'] ?? '') ?></textarea>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Add Record' ?>
        </button>
        <a href="/modules/breeding/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<?php
$inline_js = <<<'JS'
document.getElementById('breeding_date').addEventListener('change', function() {
    var ecd = document.getElementById('expected_calving_date');
    if (ecd.value !== '') return; // don't override if user already set it
    if (!this.value) return;
    var d = new Date(this.value);
    d.setDate(d.getDate() + 283);
    var y = d.getFullYear();
    var m = String(d.getMonth()+1).padStart(2,'0');
    var day = String(d.getDate()).padStart(2,'0');
    ecd.value = y + '-' + m + '-' + day;
});
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
