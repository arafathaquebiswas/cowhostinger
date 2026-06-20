<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'veterinarian']);
requireFarmScope();
requireModule('diagnosis');

$db = getDB();

$errors = [];

// Pre-fill cow_id from GET
$prefill_cow = (int)($_GET['cow_id'] ?? 0);

$form = [
    'cow_id'           => $prefill_cow > 0 ? (string)$prefill_cow : '',
    'symptom'          => '',
    'severity'         => 'mild',
    'temperature'      => '',
    'heart_rate'       => '',
    'appetite_status'  => '',
    'stool_condition'  => '',
    'milk_color'       => '',
    'milk_consistency' => '',
    'blood_in_milk'    => '0',
    'notes'            => '',
];

$cow_list = $db->prepare(
    "SELECT id, tag_number, breed FROM cows WHERE " . farmFilter() . " AND status NOT IN ('sold') ORDER BY tag_number ASC"
);
$cow_list->execute();
$cow_list = $cow_list->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/diagnosis/symptom_form.php');
    }

    $form['cow_id']           = trim($_POST['cow_id']           ?? '');
    $form['symptom']          = sanitize($_POST['symptom']          ?? '');
    $form['severity']         = sanitize($_POST['severity']         ?? 'mild');
    $form['temperature']      = trim($_POST['temperature']      ?? '');
    $form['heart_rate']       = trim($_POST['heart_rate']       ?? '');
    $form['appetite_status']  = sanitize($_POST['appetite_status']  ?? '');
    $form['stool_condition']  = sanitize($_POST['stool_condition']  ?? '');
    $form['milk_color']       = sanitize($_POST['milk_color']       ?? '');
    $form['milk_consistency'] = sanitize($_POST['milk_consistency'] ?? '');
    $form['blood_in_milk']    = isset($_POST['blood_in_milk']) ? '1' : '0';
    $form['notes']            = sanitize($_POST['notes']            ?? '');

    $cow_id = (int)$form['cow_id'];
    if ($cow_id <= 0) $errors[] = 'Please select a cow.';

    if ($form['symptom'] === '') $errors[] = 'Symptom description is required.';
    if (strlen($form['symptom']) > 255) $errors[] = 'Symptom is too long.';

    $valid_sev = ['mild', 'moderate', 'severe'];
    if (!in_array($form['severity'], $valid_sev, true)) $errors[] = 'Invalid severity.';

    $temperature = $form['temperature'] !== '' ? (float)$form['temperature'] : null;
    if ($temperature !== null && ($temperature < 35 || $temperature > 45)) $errors[] = 'Temperature must be between 35–45 °C.';

    $heart_rate = $form['heart_rate'] !== '' ? (int)$form['heart_rate'] : null;
    if ($heart_rate !== null && ($heart_rate < 20 || $heart_rate > 200)) $errors[] = 'Heart rate must be between 20–200 bpm.';

    $valid_appetite = ['normal', 'reduced', 'none', ''];
    if (!in_array($form['appetite_status'], $valid_appetite, true)) $errors[] = 'Invalid appetite status.';

    $cow = null;
    if (empty($errors)) {
        $sel = $db->prepare("SELECT id, tag_number FROM cows WHERE id = ? AND " . farmFilter());
        $sel->execute([$cow_id]);
        $cow = $sel->fetch();
        if (!$cow) $errors[] = 'Cow not found.';
    }

    if (empty($errors)) {
        $user_id          = (int)$_SESSION['user_id'];
        $appetite_val     = $form['appetite_status'] !== '' ? $form['appetite_status'] : null;
        $stool_val        = $form['stool_condition'] !== '' ? $form['stool_condition'] : null;
        $milk_color_val   = $form['milk_color']       !== '' ? $form['milk_color']      : null;
        $milk_cons_val    = $form['milk_consistency'] !== '' ? $form['milk_consistency'] : null;
        $notes_val        = $form['notes']            !== '' ? $form['notes']            : null;

        $db->prepare(
            "INSERT INTO cow_symptoms
             (cow_id, symptom, severity, temperature, heart_rate, appetite_status,
              stool_condition, milk_color, milk_consistency, blood_in_milk, notes, recorded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $cow_id, $form['symptom'], $form['severity'], $temperature, $heart_rate,
            $appetite_val, $stool_val, $milk_color_val, $milk_cons_val,
            (int)$form['blood_in_milk'], $notes_val, $user_id,
        ]);
        $new_sym_id = (int)$db->lastInsertId();
        auditLog($user_id, 'CREATE_COW_SYMPTOM', 'cow_symptoms', $new_sym_id, null, $form);

        flashMessage('success', "Symptom recorded for Cow #{$cow['tag_number']}. Create a diagnosis record if needed.");
        redirect("/modules/diagnosis/diagnosis_form.php?cow_id={$cow_id}");
    }
}

$page_title = 'Record Symptoms';
$active_nav = 'diagnosis';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Record Symptoms</h2>
        <p class="text-sm text-muted">Vital signs &amp; observations for veterinary assessment</p>
    </div>
    <a href="/modules/diagnosis/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="/modules/diagnosis/symptom_form.php" novalidate>
    <?= csrfField() ?>
    <div class="card" style="max-width:680px;margin-bottom:1.25rem">
        <div class="card-body">

            <div class="form-group">
                <label class="form-label" for="cow_id">Cow <span style="color:var(--danger)">*</span></label>
                <select id="cow_id" name="cow_id" class="form-control" required>
                    <option value="">— Select cow —</option>
                    <?php foreach ($cow_list as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (string)$c['id']===$form['cow_id']?'selected':'' ?>>
                        #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:start">
                <div class="form-group">
                    <label class="form-label" for="symptom">Symptom / Chief Complaint <span style="color:var(--danger)">*</span></label>
                    <input type="text" id="symptom" name="symptom" class="form-control"
                           value="<?= e($form['symptom']) ?>" maxlength="255"
                           placeholder="e.g. Lethargy, limping, nasal discharge" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="severity">Severity <span style="color:var(--danger)">*</span></label>
                    <select id="severity" name="severity" class="form-control">
                        <option value="mild"     <?= $form['severity']==='mild'     ?'selected':'' ?>>Mild</option>
                        <option value="moderate" <?= $form['severity']==='moderate' ?'selected':'' ?>>Moderate</option>
                        <option value="severe"   <?= $form['severity']==='severe'   ?'selected':'' ?>>Severe</option>
                    </select>
                </div>
            </div>

            <h4 style="font-size:.875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin:.75rem 0 .75rem">Vital Signs</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="temperature">Temperature (°C)</label>
                    <input type="number" id="temperature" name="temperature" class="form-control"
                           value="<?= e($form['temperature']) ?>" step="0.1" min="35" max="45" placeholder="38.5">
                    <span class="form-hint">Normal: 38.5–39.5 °C</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="heart_rate">Heart Rate (bpm)</label>
                    <input type="number" id="heart_rate" name="heart_rate" class="form-control"
                           value="<?= e($form['heart_rate']) ?>" min="20" max="200" placeholder="60">
                    <span class="form-hint">Normal: 48–84 bpm</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="appetite_status">Appetite</label>
                    <select id="appetite_status" name="appetite_status" class="form-control">
                        <option value=""       <?= $form['appetite_status']===''       ?'selected':'' ?>>— Not assessed —</option>
                        <option value="normal" <?= $form['appetite_status']==='normal' ?'selected':'' ?>>Normal</option>
                        <option value="reduced"<?= $form['appetite_status']==='reduced'?'selected':'' ?>>Reduced</option>
                        <option value="none"   <?= $form['appetite_status']==='none'   ?'selected':'' ?>>None</option>
                    </select>
                </div>
            </div>

            <h4 style="font-size:.875rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin:.75rem 0 .75rem">Observations</h4>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="stool_condition">Stool Condition</label>
                    <input type="text" id="stool_condition" name="stool_condition" class="form-control"
                           value="<?= e($form['stool_condition']) ?>"
                           placeholder="e.g. Watery, normal, blood-tinged" maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label" for="milk_color">Milk Color</label>
                    <input type="text" id="milk_color" name="milk_color" class="form-control"
                           value="<?= e($form['milk_color']) ?>"
                           placeholder="e.g. Normal, yellow, pink" maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label" for="milk_consistency">Milk Consistency</label>
                    <input type="text" id="milk_consistency" name="milk_consistency" class="form-control"
                           value="<?= e($form['milk_consistency']) ?>"
                           placeholder="e.g. Clotted, watery, normal" maxlength="100">
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:.6rem;padding-top:1.5rem">
                    <input type="checkbox" id="blood_in_milk" name="blood_in_milk" value="1"
                           <?= $form['blood_in_milk']==='1' ? 'checked' : '' ?>
                           style="width:18px;height:18px;cursor:pointer">
                    <label for="blood_in_milk" style="margin:0;cursor:pointer;font-weight:500;color:var(--danger)">Blood present in milk</label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="notes">Additional Notes</label>
                <textarea id="notes" name="notes" class="form-control" rows="2"
                          placeholder="Behavioural changes, environment, duration of symptoms…"><?= e($form['notes']) ?></textarea>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            Record Symptoms
        </button>
        <a href="/modules/diagnosis/index.php" class="btn btn-secondary">Cancel</a>
    </div>
    <p class="text-sm text-muted" style="margin-top:.75rem">After saving, you will be taken to the diagnosis form to record the veterinary conclusion.</p>
</form>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
