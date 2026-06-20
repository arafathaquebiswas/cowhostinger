<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'veterinarian']);
requireFarmScope();
requireModule('diagnosis');

$db      = getDB();
$diag_id = (int)($_GET['id'] ?? 0);
$is_edit = $diag_id > 0;
$existing = null;

$errors = [];
$prefill_cow = (int)($_GET['cow_id'] ?? 0);

$form = [
    'cow_id'             => $prefill_cow > 0 ? (string)$prefill_cow : '',
    'diagnosis'          => '',
    'confidence_level'   => 'medium',
    'recommended_action' => '',
    'photo_url'          => null,
];

$cow_list = $db->prepare(
    "SELECT id, tag_number, breed FROM cows WHERE " . farmFilter() . " ORDER BY tag_number ASC"
);
$cow_list->execute();
$cow_list = $cow_list->fetchAll();

if ($is_edit) {
    $sel = $db->prepare("SELECT * FROM diagnosis_records WHERE id = ? AND " . farmFilter());
    $sel->execute([$diag_id]);
    $existing = $sel->fetch();
    if (!$existing) {
        flashMessage('error', 'Diagnosis record not found.');
        redirect('/modules/diagnosis/index.php');
    }
    // Vet can only edit their own records unless admin
    if (!hasRole(['admin']) && (int)$existing['veterinarian_id'] !== (int)$_SESSION['user_id']) {
        flashMessage('error', 'You can only edit your own diagnosis records.');
        redirect('/modules/diagnosis/index.php');
    }
    $form = [
        'cow_id'             => $existing['cow_id'],
        'diagnosis'          => $existing['diagnosis'],
        'confidence_level'   => $existing['confidence_level'],
        'recommended_action' => $existing['recommended_action'] ?? '',
        'photo_url'          => $existing['photo_url'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token.');
        redirect('/modules/diagnosis/diagnosis_form.php' . ($is_edit ? "?id={$diag_id}" : ''));
    }

    $form['cow_id']             = trim($_POST['cow_id']             ?? '');
    $form['diagnosis']          = sanitize($_POST['diagnosis']          ?? '');
    $form['confidence_level']   = sanitize($_POST['confidence_level']   ?? 'medium');
    $form['recommended_action'] = sanitize($_POST['recommended_action'] ?? '');

    $cow_id = (int)$form['cow_id'];
    if ($cow_id <= 0) $errors[] = 'Please select a cow.';
    if ($form['diagnosis'] === '') $errors[] = 'Diagnosis is required.';

    $valid_conf = ['low', 'medium', 'high'];
    if (!in_array($form['confidence_level'], $valid_conf, true)) $errors[] = 'Invalid confidence level.';

    $cow = null;
    if (empty($errors)) {
        $sel = $db->prepare("SELECT id, tag_number FROM cows WHERE id = ? AND " . farmFilter());
        $sel->execute([$cow_id]);
        $cow = $sel->fetch();
        if (!$cow) $errors[] = 'Cow not found.';
    }

    // Photo upload
    $photo_url = $form['photo_url'];
    if (!empty($_FILES['photo']['name'])) {
        $uploaded = uploadImage($_FILES['photo'], 'diagnosis');
        if ($uploaded === false) {
            $errors[] = 'Photo upload failed. Allowed: JPG, PNG, WebP. Max 5 MB.';
        } else {
            $photo_url = $uploaded;
        }
    }

    if (empty($errors)) {
        $user_id   = (int)$_SESSION['user_id'];
        $action_val = $form['recommended_action'] !== '' ? $form['recommended_action'] : null;

        if ($is_edit) {
            $db->prepare(
                "UPDATE diagnosis_records SET cow_id=?, diagnosis=?, confidence_level=?, recommended_action=?, photo_url=? WHERE id=? AND " . farmFilter()
            )->execute([$cow_id, $form['diagnosis'], $form['confidence_level'], $action_val, $photo_url, $diag_id]);
            auditLog($user_id, 'UPDATE_DIAGNOSIS', 'diagnosis_records', $diag_id, $existing, $form);
            flashMessage('success', "Diagnosis for Cow #{$cow['tag_number']} updated.");
        } else {
            $db->prepare(
                "INSERT INTO diagnosis_records (farm_id, cow_id, diagnosis, confidence_level, recommended_action, veterinarian_id, photo_url)
                 VALUES (?,?,?,?,?,?,?)"
            )->execute([fid(), $cow_id, $form['diagnosis'], $form['confidence_level'], $action_val, $user_id, $photo_url]);
            $new_id = (int)$db->lastInsertId();
            auditLog($user_id, 'CREATE_DIAGNOSIS', 'diagnosis_records', $new_id, null, $form);
            flashMessage('success', "Diagnosis recorded for Cow #{$cow['tag_number']}.");
        }
        redirect("/modules/cows/view.php?id={$cow_id}&tab=diagnoses");
    }
}

// Recent symptoms for context (for the selected cow)
$context_symptoms = [];
$context_cow_id   = $is_edit ? (int)$form['cow_id'] : $prefill_cow;
if ($context_cow_id > 0) {
    $sym_stmt = $db->prepare(
        "SELECT cs.symptom, cs.severity, cs.temperature, cs.heart_rate, cs.appetite_status,
                cs.blood_in_milk, cs.recorded_at, u.name AS recorded_by
         FROM cow_symptoms cs
         JOIN users u ON u.id = cs.recorded_by
         WHERE cs.cow_id = ?
         ORDER BY cs.recorded_at DESC
         LIMIT 5"
    );
    $sym_stmt->execute([$context_cow_id]);
    $context_symptoms = $sym_stmt->fetchAll();
}

$page_title = $is_edit ? 'Edit Diagnosis' : 'Record Diagnosis';
$active_nav = 'diagnosis';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2><?= $is_edit ? 'Edit Diagnosis' : 'Record Diagnosis' ?></h2>
        <p class="text-sm text-muted">Veterinary conclusion &amp; recommended action</p>
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

<div style="display:grid;grid-template-columns:1fr 340px;gap:1.5rem;align-items:start">

<form method="POST" action="/modules/diagnosis/diagnosis_form.php<?= $is_edit ? "?id={$diag_id}" : '' ?>"
      enctype="multipart/form-data" novalidate>
    <?= csrfField() ?>
    <div class="card" style="margin-bottom:1.25rem">
        <div class="card-body">

            <div class="form-group">
                <label class="form-label" for="cow_id">Cow <span style="color:var(--danger)">*</span></label>
                <?php if ($is_edit): ?>
                <?php
                $cur_cow = null;
                foreach ($cow_list as $c) { if ($c['id'] == $form['cow_id']) { $cur_cow = $c; break; } }
                ?>
                <div style="padding:.55rem .85rem;background:var(--bg-muted);border:1px solid var(--border);border-radius:var(--radius);font-weight:600">
                    #<?= $cur_cow ? e($cur_cow['tag_number']) . ' — ' . e($cur_cow['breed']) : e($form['cow_id']) ?>
                </div>
                <input type="hidden" name="cow_id" value="<?= (int)$form['cow_id'] ?>">
                <?php else: ?>
                <select id="cow_id" name="cow_id" class="form-control" onchange="loadSymptoms(this.value)" required>
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
                <label class="form-label" for="diagnosis">Diagnosis <span style="color:var(--danger)">*</span></label>
                <textarea id="diagnosis" name="diagnosis" class="form-control" rows="4"
                          placeholder="e.g. Bovine respiratory disease; mastitis (E. coli origin); foot-and-mouth disease…" required><?= e($form['diagnosis']) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="confidence_level">Confidence Level <span style="color:var(--danger)">*</span></label>
                <div style="display:flex;gap:1rem">
                    <?php foreach (['low'=>'Low','medium'=>'Medium','high'=>'High'] as $val=>$label): ?>
                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:500">
                        <input type="radio" name="confidence_level" value="<?= $val ?>"
                               <?= $form['confidence_level']===$val?'checked':'' ?>>
                        <?= $label ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="recommended_action">Recommended Action</label>
                <textarea id="recommended_action" name="recommended_action" class="form-control" rows="3"
                          placeholder="e.g. Administer penicillin 5 mg/kg for 5 days, isolate from herd, recheck in 3 days…"><?= e($form['recommended_action']) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="photo">Evidence Photo</label>
                <input type="file" id="photo" name="photo" class="form-control"
                       accept="image/jpeg,image/png,image/webp">
                <span class="form-hint">JPG, PNG, or WebP. Max 5 MB.</span>
                <div class="photo-preview-wrap">
                    <?php if ($form['photo_url']): ?>
                    <img src="<?= e($form['photo_url']) ?>" alt="Current photo" style="max-height:120px;border-radius:var(--radius)">
                    <span class="form-hint">Current photo. Upload new to replace.</span>
                    <?php endif; ?>
                    <img src="" class="photo-preview-new" id="newPhotoPreview" alt="">
                </div>
            </div>

        </div>
    </div>

    <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            <?= $is_edit ? 'Save Changes' : 'Record Diagnosis' ?>
        </button>
        <a href="/modules/diagnosis/index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<!-- Context: recent symptoms panel -->
<div>
    <div class="card" id="symptoms_panel">
        <div style="padding:1rem;border-bottom:1px solid var(--border)">
            <h4 style="margin:0;font-size:.9rem;font-weight:600">Recent Symptoms</h4>
            <p class="text-muted" style="font-size:.78rem;margin:.2rem 0 0">Last 5 entries for selected cow</p>
        </div>
        <div id="symptoms_list" style="padding:.75rem">
            <?php if (!empty($context_symptoms)): ?>
                <?php foreach ($context_symptoms as $sym): ?>
                <div class="record-card" style="margin-bottom:.6rem">
                    <div class="record-card-header">
                        <span style="font-size:.78rem;color:var(--text-muted)"><?= e(formatDateTime($sym['recorded_at'])) ?></span>
                        <span class="badge <?= $sym['severity']==='severe'?'badge-red':($sym['severity']==='moderate'?'badge-yellow':'badge-green') ?>">
                            <?= e(ucfirst($sym['severity'])) ?>
                        </span>
                    </div>
                    <div style="font-size:.85rem;font-weight:500;margin:.25rem 0"><?= e($sym['symptom']) ?></div>
                    <div class="text-muted" style="font-size:.78rem;display:flex;gap:.75rem;flex-wrap:wrap">
                        <?php if ($sym['temperature']): ?><span>Temp: <?= e($sym['temperature']) ?>°C</span><?php endif; ?>
                        <?php if ($sym['heart_rate']):  ?><span>HR: <?= e($sym['heart_rate']) ?> bpm</span><?php endif; ?>
                        <?php if ($sym['appetite_status']): ?><span>Appetite: <?= e(ucfirst($sym['appetite_status'])) ?></span><?php endif; ?>
                        <?php if ($sym['blood_in_milk']): ?><span style="color:var(--danger)">&#9679; Blood in milk</span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
            <p class="text-muted" style="font-size:.83rem;text-align:center;padding:.5rem 0" id="no_symptoms_msg">
                <?= $context_cow_id > 0 ? 'No recent symptoms recorded for this cow.' : 'Select a cow to see recent symptoms.' ?>
            </p>
            <?php endif; ?>
        </div>
        <?php if ($context_cow_id > 0): ?>
        <div style="padding:.5rem 1rem;border-top:1px solid var(--border)">
            <a href="/modules/diagnosis/symptom_form.php?cow_id=<?= $context_cow_id ?>"
               class="btn btn-secondary btn-sm" style="width:100%;text-align:center">+ Add Symptoms</a>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>

<?php
$context_cow_id_js = (int)$context_cow_id;
$inline_js = <<<'JS'
document.getElementById('photo') && document.getElementById('photo').addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var preview = document.getElementById('newPhotoPreview');
    var reader  = new FileReader();
    reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(file);
});

function loadSymptoms(cowId) {
    var list = document.getElementById('symptoms_list');
    if (!cowId) {
        list.innerHTML = '<p class="text-muted" style="font-size:.83rem;text-align:center;padding:.5rem 0">Select a cow to see recent symptoms.</p>';
        return;
    }
    list.innerHTML = '<p class="text-muted" style="font-size:.83rem;text-align:center;padding:.5rem 0">Loading…</p>';
    fetch('/api/get_cow_symptoms.php?cow_id=' + encodeURIComponent(cowId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.symptoms || data.symptoms.length === 0) {
                list.innerHTML = '<p class="text-muted" style="font-size:.83rem;text-align:center;padding:.5rem 0">No recent symptoms for this cow.</p>';
                return;
            }
            var html = '';
            data.symptoms.forEach(function(sym) {
                var sevClass = sym.severity === 'severe' ? 'badge-red' : (sym.severity === 'moderate' ? 'badge-yellow' : 'badge-green');
                html += '<div class="record-card" style="margin-bottom:.6rem">';
                html += '<div class="record-card-header"><span style="font-size:.78rem;color:var(--text-muted)">' + sym.recorded_at + '</span>';
                html += '<span class="badge ' + sevClass + '">' + sym.severity.charAt(0).toUpperCase() + sym.severity.slice(1) + '</span></div>';
                html += '<div style="font-size:.85rem;font-weight:500;margin:.25rem 0">' + sym.symptom + '</div>';
                var meta = [];
                if (sym.temperature) meta.push('Temp: ' + sym.temperature + '°C');
                if (sym.heart_rate)  meta.push('HR: ' + sym.heart_rate + ' bpm');
                if (sym.appetite_status) meta.push('Appetite: ' + sym.appetite_status.charAt(0).toUpperCase() + sym.appetite_status.slice(1));
                if (sym.blood_in_milk) meta.push('<span style="color:var(--danger)">● Blood in milk</span>');
                if (meta.length) html += '<div class="text-muted" style="font-size:.78rem;display:flex;gap:.75rem;flex-wrap:wrap">' + meta.map(function(m){return '<span>'+m+'</span>';}).join('') + '</div>';
                html += '</div>';
            });
            list.innerHTML = html;
        })
        .catch(function() {
            list.innerHTML = '<p class="text-muted" style="font-size:.83rem;text-align:center;padding:.5rem 0">Could not load symptoms.</p>';
        });
}
JS;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
