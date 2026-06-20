<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin', 'worker', 'veterinarian']);
requireModule('milk');

$page_title = 'Record Milk';
$active_nav = 'milk';
$db = getDB();

// Pre-select cow if coming from cow detail page
$preselect_cow = (int)($_GET['cow_id'] ?? 0);

$errors = [];
$form = [
    'cow_id'            => $preselect_cow ?: '',
    'liters'            => '',
    'fat_percentage'    => '',
    'contamination_flag'=> 0,
    'recorded_at'       => date('Y-m-d\TH:i'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid CSRF token. Please try again.');
        redirect('/modules/milk/record.php');
    }

    $form['cow_id']             = (int)($_POST['cow_id']           ?? 0);
    $form['liters']             = trim($_POST['liters']             ?? '');
    $form['fat_percentage']     = trim($_POST['fat_percentage']     ?? '');
    $form['contamination_flag'] = isset($_POST['contamination_flag']) ? 1 : 0;
    $form['recorded_at']        = trim($_POST['recorded_at']        ?? '');

    // Validate
    if ($form['cow_id'] <= 0) {
        $errors[] = 'Please select a cow.';
    } else {
        $chk = $db->prepare("SELECT id FROM cows WHERE id = ? AND status NOT IN ('sold','deceased')");
        $chk->execute([$form['cow_id']]);
        if (!$chk->fetch()) $errors[] = 'Selected cow is invalid or no longer active.';
    }

    $liters = $form['liters'] !== '' ? (float)$form['liters'] : null;
    if ($liters === null || $liters <= 0 || $liters > 999) {
        $errors[] = 'Liters must be between 0.01 and 999.';
    }

    $fat = null;
    if ($form['fat_percentage'] !== '') {
        $fat = (float)$form['fat_percentage'];
        if ($fat < 0 || $fat > 10) $errors[] = 'Fat percentage must be between 0 and 10.';
    }

    $recorded_at = $form['recorded_at'] !== '' ? $form['recorded_at'] : date('Y-m-d H:i:s');
    // Normalise datetime-local input format
    if (str_contains($recorded_at, 'T')) {
        $recorded_at = str_replace('T', ' ', $recorded_at) . ':00';
    }
    if (strtotime($recorded_at) === false) {
        $errors[] = 'Invalid recorded date/time.';
    } elseif (strtotime($recorded_at) > time() + 60) {
        $errors[] = 'Recorded date/time cannot be in the future.';
    }

    if (empty($errors)) {
        $user_id = (int)$_SESSION['user_id'];
        $db->prepare(
            "INSERT INTO milk_records (cow_id, liters, fat_percentage, contamination_flag, recorded_at, recorded_by)
             VALUES (?,?,?,?,?,?)"
        )->execute([$form['cow_id'], $liters, $fat, $form['contamination_flag'], $recorded_at, $user_id]);

        $new_id = (int)$db->lastInsertId();
        auditLog($user_id, 'CREATE_MILK_RECORD', 'milk_records', $new_id, null, [
            'cow_id' => $form['cow_id'], 'liters' => $liters, 'recorded_at' => $recorded_at,
        ]);
        flashMessage('success', "Milk record saved — {$liters} L recorded.");
        redirect('/modules/milk/index.php');
    }
}

// Load active cows for select
$cows = $db->query(
    "SELECT id, tag_number, breed, status FROM cows
     WHERE status NOT IN ('sold','deceased')
     ORDER BY tag_number ASC"
)->fetchAll();

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Record Milk</h2>
        <p class="text-sm text-muted">Add a new milk production entry</p>
    </div>
    <a href="/modules/milk/index.php" class="btn btn-secondary">Cancel</a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:1.25rem">
    <strong>Please fix the following errors:</strong>
    <ul style="margin:.4rem 0 0 1.2rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card" style="max-width:580px">
    <div class="card-header"><span class="card-title">Milk Entry</span></div>
    <div class="card-body">
        <form method="POST" action="/modules/milk/record.php" novalidate>
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label" for="cow_id">Cow <span style="color:var(--danger)">*</span></label>
                <select id="cow_id" name="cow_id" class="form-control" required>
                    <option value="">— Select cow —</option>
                    <?php foreach ($cows as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)$form['cow_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                        #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                        (<?= e(str_replace('_',' ',ucfirst($c['status']))) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
                <div class="form-group">
                    <label class="form-label" for="liters">Liters <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="liters" name="liters" class="form-control"
                           value="<?= e($form['liters']) ?>"
                           step="0.01" min="0.01" max="999" placeholder="e.g. 12.50" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="fat_percentage">Fat Percentage (%)</label>
                    <input type="number" id="fat_percentage" name="fat_percentage" class="form-control"
                           value="<?= e($form['fat_percentage']) ?>"
                           step="0.1" min="0" max="10" placeholder="e.g. 3.5">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="recorded_at">Date &amp; Time</label>
                <input type="datetime-local" id="recorded_at" name="recorded_at" class="form-control"
                       value="<?= e($form['recorded_at']) ?>"
                       max="<?= date('Y-m-d\TH:i') ?>">
                <span class="form-hint">Defaults to now if left unchanged.</span>
            </div>

            <div class="form-group" style="display:flex;align-items:center;gap:.75rem">
                <input type="checkbox" id="contamination_flag" name="contamination_flag" value="1"
                       <?= $form['contamination_flag'] ? 'checked' : '' ?>
                       style="width:18px;height:18px;cursor:pointer">
                <label for="contamination_flag" class="form-label" style="margin:0;cursor:pointer">
                    Contaminated / rejected batch
                </label>
            </div>

            <div style="display:flex;gap:.75rem;margin-top:.5rem">
                <button type="submit" class="btn btn-primary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save Record
                </button>
                <a href="/modules/milk/index.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
