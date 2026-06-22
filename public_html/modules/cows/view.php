<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireModule('cows');

$db     = getDB();
$cow_id = (int)($_GET['id'] ?? 0);
if ($cow_id <= 0) {
    flashMessage('error', 'Invalid cow ID.');
    redirect('/modules/cows/index.php');
}

// Load cow (scoped to current farm)
$stmt = $db->prepare("SELECT * FROM cows WHERE id = ? AND " . farmFilter());
$stmt->execute([$cow_id]);
$cow = $stmt->fetch();
if (!$cow) {
    flashMessage('error', 'Cow not found.');
    redirect('/modules/cows/index.php');
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request. Please try again.');
        redirect("/modules/cows/view.php?id={$cow_id}");
    }

    $action  = $_POST['action'] ?? '';
    $user_id = (int)$_SESSION['user_id'];

    // Add weight log
    if ($action === 'add_weight' && hasRole(['admin', 'manager', 'worker', 'veterinarian'])) {
        $weight = (float)trim($_POST['weight'] ?? '0');
        if ($weight <= 0 || $weight > 9999) {
            flashMessage('error', 'Weight must be between 0.01 and 9999 kg.');
        } else {
            $db->prepare(
                "INSERT INTO cow_weight_logs (farm_id, cow_id, weight, recorded_by) VALUES (?,?,?,?)"
            )->execute([fid(), $cow_id, $weight, $user_id]);
            $db->prepare(
                "UPDATE cows SET current_weight = ? WHERE id = ? AND " . farmFilter()
            )->execute([$weight, $cow_id]);
            auditLog($user_id, 'ADD_WEIGHT_LOG', 'cow_weight_logs', null, null, ['cow_id' => $cow_id, 'weight' => $weight]);
            flashMessage('success', "Weight of {$weight} kg recorded for cow #{$cow['tag_number']}.");
        }
        redirect("/modules/cows/view.php?id={$cow_id}&tab=weight");
    }

    // Mark for sale
    if ($action === 'mark_for_sale' && hasRole(['admin', 'manager', 'accountant'])) {
        if (in_array($cow['status'], ['sold', 'deceased'], true)) {
            flashMessage('error', 'This cow cannot be marked for sale (status: ' . $cow['status'] . ').');
        } else {
            $old_status = $cow['status'];
            $db->prepare("UPDATE cows SET status = 'ready_for_sale' WHERE id = ? AND " . farmFilter())->execute([$cow_id]);
            auditLog($user_id, 'MARK_FOR_SALE', 'cows', $cow_id, ['status' => $old_status], ['status' => 'ready_for_sale']);
            flashMessage('success', "Cow #{$cow['tag_number']} marked as Ready for Sale.");
        }
        redirect("/modules/cows/view.php?id={$cow_id}");
    }

    // Delete cow
    if ($action === 'delete' && hasRole(['admin', 'manager'])) {
        try {
            $db->prepare("DELETE FROM cows WHERE id = ? AND " . farmFilter())->execute([$cow_id]);
            auditLog($user_id, 'DELETE_COW', 'cows', $cow_id, $cow, null);
            flashMessage('success', "Cow #{$cow['tag_number']} deleted.");
            redirect('/modules/cows/index.php');
        } catch (PDOException $e) {
            flashMessage('error', "Cannot delete cow #{$cow['tag_number']} — it has linked sales or financial records.");
            redirect("/modules/cows/view.php?id={$cow_id}");
        }
    }

    redirect("/modules/cows/view.php?id={$cow_id}");
}

// Compute cow age
$age_str = 'Unknown';
if ($cow['birth_date']) {
    $diff = (new DateTime($cow['birth_date']))->diff(new DateTime());
    if ($diff->y > 0) {
        $age_str = "{$diff->y} yr" . ($diff->m > 0 ? " {$diff->m} mo" : '');
    } elseif ($diff->m > 0) {
        $age_str = "{$diff->m} months";
    } else {
        $age_str = "{$diff->d} days";
    }
}

// Fetch last 20 weight logs (cow_id already verified as farm-scoped above)
$wlogs = $db->prepare(
    "SELECT cwl.id, cwl.weight, cwl.recorded_at, u.name AS recorded_by_name
     FROM cow_weight_logs cwl
     JOIN users u ON u.id = cwl.recorded_by
     WHERE cwl.cow_id = ?
     ORDER BY cwl.recorded_at DESC
     LIMIT 20"
);
$wlogs->execute([$cow_id]);
$weight_logs = $wlogs->fetchAll();

// Fetch last 10 treatments
$trmt = $db->prepare(
    "SELECT t.id, t.treatment_date, t.dosage, t.cost, t.notes,
            mi.item_name AS medicine_name,
            u.name       AS vet_name
     FROM treatments t
     LEFT JOIN medicine_inventory mi ON mi.id = t.medicine_id
     JOIN  users u                   ON u.id  = t.administered_by
     WHERE t.cow_id = ? AND " . farmFilter('t') . "
     ORDER BY t.treatment_date DESC, t.created_at DESC
     LIMIT 10"
);
$trmt->execute([$cow_id]);
$treatments = $trmt->fetchAll();

// Fetch last 10 diagnoses
$diag = $db->prepare(
    "SELECT dr.id, dr.diagnosis, dr.confidence_level, dr.recommended_action, dr.created_at,
            u.name AS vet_name
     FROM diagnosis_records dr
     JOIN users u ON u.id = dr.veterinarian_id
     WHERE dr.cow_id = ? AND " . farmFilter('dr') . "
     ORDER BY dr.created_at DESC
     LIMIT 10"
);
$diag->execute([$cow_id]);
$diagnoses = $diag->fetchAll();

// Fetch last 5 breeding records with calf count
$bred = $db->prepare(
    "SELECT br.id, br.heat_cycle_date, br.insemination_date, br.breeding_date,
            br.expected_calving_date, br.actual_calving_date,
            br.status, br.notes, br.created_at,
            u.name AS recorded_by_name,
            (SELECT COUNT(*) FROM calf_records cr WHERE cr.breeding_record_id = br.id) AS calf_count
     FROM breeding_records br
     JOIN users u ON u.id = br.recorded_by
     WHERE br.cow_id = ? AND " . farmFilter('br') . "
     ORDER BY br.created_at DESC
     LIMIT 5"
);
$bred->execute([$cow_id]);
$breeding_records = $bred->fetchAll();

// Status badge helper
function view_status_badge(string $s): string {
    return match($s) {
        'active'         => 'badge-green',
        'pregnant'       => 'badge-purple',
        'lactating'      => 'badge-blue',
        'dry'            => 'badge-orange',
        'sick'           => 'badge-red',
        'quarantine'     => 'badge-red',
        'ready_for_sale' => 'badge-yellow',
        'sold'           => 'badge-gray',
        'deceased'       => 'badge-gray',
        default          => 'badge-gray',
    };
}

function breeding_status_badge(string $s): string {
    return match($s) {
        'heat'         => 'badge-yellow',
        'inseminated'  => 'badge-blue',
        'pregnant'     => 'badge-purple',
        'calved'       => 'badge-green',
        'failed'       => 'badge-red',
        default        => 'badge-gray',
    };
}

// Active tab (from redirect hint or default)
$active_tab = in_array($_GET['tab'] ?? '', ['overview','weight','treatments','diagnoses','breeding'], true)
    ? $_GET['tab']
    : 'overview';

$page_title = "Cow #" . $cow['tag_number'];
$active_nav = 'cows';
$extra_js   = ['https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'];

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--text-secondary);margin-bottom:1rem">
    <a href="/modules/cows/index.php" style="color:var(--primary)">Cows</a>
    <span>›</span>
    <span>#<?= e($cow['tag_number']) ?></span>
</div>

<!-- Cow Profile Card -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="cow-profile">
        <!-- Photo -->
        <div class="cow-photo">
            <?php if ($cow['photo_url']): ?>
            <img src="<?= e($cow['photo_url']) ?>" alt="Cow #<?= e($cow['tag_number']) ?>">
            <?php else: ?>
            🐄
            <?php endif; ?>
        </div>

        <!-- Info -->
        <div class="cow-profile-info">
            <div class="cow-tag">#<?= e($cow['tag_number']) ?></div>
            <div class="cow-meta">
                <span style="color:var(--text-secondary);font-size:.9rem"><?= e($cow['breed']) ?></span>
                <span class="badge <?= view_status_badge($cow['status']) ?>">
                    <?= e(str_replace('_', ' ', ucfirst($cow['status']))) ?>
                </span>
                <?php if ($cow['is_pregnant']): ?>
                <span class="badge badge-purple">Pregnant</span>
                <?php endif; ?>
            </div>
            <div class="cow-stats">
                <div class="cow-stat">
                    <div class="cow-stat-val"><?= e($age_str) ?></div>
                    <div class="cow-stat-lbl">Age</div>
                </div>
                <div class="cow-stat">
                    <div class="cow-stat-val">
                        <?= $cow['current_weight'] !== null ? e(number_format((float)$cow['current_weight'], 1)) . ' kg' : '—' ?>
                    </div>
                    <div class="cow-stat-lbl">Weight</div>
                </div>
                <div class="cow-stat">
                    <div class="cow-stat-val"><?= e($cow['health_status']) ?></div>
                    <div class="cow-stat-lbl">Health</div>
                </div>
                <?php if ($cow['purchase_price'] !== null): ?>
                <div class="cow-stat">
                    <div class="cow-stat-val"><?= e(formatCurrency((float)$cow['purchase_price'])) ?></div>
                    <div class="cow-stat-lbl">Purchase Price</div>
                </div>
                <?php endif; ?>
                <?php if ($cow['birth_date']): ?>
                <div class="cow-stat">
                    <div class="cow-stat-val"><?= e(formatDate($cow['birth_date'])) ?></div>
                    <div class="cow-stat-lbl">Born</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="cow-actions">
            <?php if (hasRole(['admin', 'manager', 'veterinarian'])): ?>
            <a href="/modules/cows/form.php?id=<?= $cow_id ?>" class="btn btn-secondary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit
            </a>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'accountant']) && !in_array($cow['status'], ['ready_for_sale','sold','deceased'], true)): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Mark cow #<?= e(addslashes($cow['tag_number'])) ?> as Ready for Sale?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_for_sale">
                <button type="submit" class="btn btn-warning btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/></svg>
                    Mark for Sale
                </button>
            </form>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'accountant']) && !in_array($cow['status'], ['sold'], true)): ?>
            <a href="/modules/cows/sell.php?cow_id=<?= $cow_id ?>" class="btn btn-success btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.97-1.67L23 6H6"/></svg>
                Sell Options
            </a>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager'])): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Permanently delete cow #<?= e(addslashes($cow['tag_number'])) ?>? This cannot be undone.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-danger btn-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                    Delete
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tab-nav" role="tablist" id="cowTabNav">
        <button class="tab-btn <?= $active_tab === 'overview'    ? 'active' : '' ?>" data-tab="overview"    role="tab">Overview</button>
        <button class="tab-btn <?= $active_tab === 'weight'      ? 'active' : '' ?>" data-tab="weight"      role="tab">
            Weight History
            <?php if (count($weight_logs)): ?>
            <span class="badge badge-gray" style="font-size:.7rem"><?= count($weight_logs) ?></span>
            <?php endif; ?>
        </button>
        <button class="tab-btn <?= $active_tab === 'treatments'  ? 'active' : '' ?>" data-tab="treatments"  role="tab">
            Treatments
            <?php if (count($treatments)): ?>
            <span class="badge badge-gray" style="font-size:.7rem"><?= count($treatments) ?></span>
            <?php endif; ?>
        </button>
        <button class="tab-btn <?= $active_tab === 'diagnoses'   ? 'active' : '' ?>" data-tab="diagnoses"   role="tab">
            Diagnoses
            <?php if (count($diagnoses)): ?>
            <span class="badge badge-gray" style="font-size:.7rem"><?= count($diagnoses) ?></span>
            <?php endif; ?>
        </button>
        <button class="tab-btn <?= $active_tab === 'breeding'    ? 'active' : '' ?>" data-tab="breeding"    role="tab">
            Breeding
            <?php if (count($breeding_records)): ?>
            <span class="badge badge-gray" style="font-size:.7rem"><?= count($breeding_records) ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- TAB: Overview -->
    <div id="tab_overview" class="tab-panel <?= $active_tab === 'overview' ? 'active' : '' ?>" style="padding:1.25rem">
        <table class="table" style="margin:0">
            <tbody>
            <tr><th style="width:200px;font-weight:600;color:var(--text-secondary)">Tag Number</th><td>#<?= e($cow['tag_number']) ?></td></tr>
            <tr><th>Breed</th><td><?= e($cow['breed']) ?></td></tr>
            <tr><th>Status</th><td><span class="badge <?= view_status_badge($cow['status']) ?>"><?= e(str_replace('_',' ',ucfirst($cow['status']))) ?></span></td></tr>
            <tr><th>Health Status</th><td><?= e($cow['health_status']) ?></td></tr>
            <tr><th>Pregnant</th><td><?= $cow['is_pregnant'] ? '<span class="badge badge-purple">Yes</span>' : 'No' ?></td></tr>
            <tr><th>Current Weight</th><td><?= $cow['current_weight'] !== null ? e(number_format((float)$cow['current_weight'], 2)) . ' kg' : '—' ?></td></tr>
            <tr><th>Age</th><td><?= e($age_str) ?></td></tr>
            <tr><th>Birth Date</th><td><?= $cow['birth_date'] ? e(formatDate($cow['birth_date'])) : '—' ?></td></tr>
            <tr><th>Purchase Price</th><td><?= $cow['purchase_price'] !== null ? e(formatCurrency((float)$cow['purchase_price'])) : '—' ?></td></tr>
            <tr><th>Purchase Date</th><td><?= $cow['purchase_date'] ? e(formatDate($cow['purchase_date'])) : '—' ?></td></tr>
            <tr><th>Added to System</th><td><?= e(formatDateTime($cow['created_at'])) ?></td></tr>
            <?php if ($cow['notes']): ?>
            <tr><th>Notes</th><td style="white-space:pre-wrap"><?= e($cow['notes']) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- TAB: Weight History -->
    <div id="tab_weight" class="tab-panel <?= $active_tab === 'weight' ? 'active' : '' ?>" style="padding:1.25rem">

        <!-- Chart -->
        <div class="section-heading">Weight Trend (last 30 entries)</div>
        <div id="weight-chart-wrap" style="height:260px;margin-bottom:1.5rem;position:relative">
            <canvas id="weight-chart"></canvas>
        </div>

        <?php if (hasRole(['admin', 'manager', 'worker', 'veterinarian'])): ?>
        <!-- Add weight form -->
        <div class="section-heading" style="margin-top:1.5rem">Record New Weight</div>
        <form method="POST" action="/modules/cows/view.php?id=<?= $cow_id ?>&tab=weight"
              style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.5rem">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_weight">
            <div class="form-group" style="margin:0;min-width:180px">
                <label class="form-label" for="new_weight">Weight (kg)</label>
                <input type="number" id="new_weight" name="weight" class="form-control"
                       step="0.01" min="0.01" max="9999" placeholder="e.g. 455.50" required>
            </div>
            <button type="submit" class="btn btn-primary">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Record Weight
            </button>
        </form>
        <?php endif; ?>

        <!-- Weight log table -->
        <?php if (empty($weight_logs)): ?>
        <div class="empty-state" style="padding:2rem">
            <p>No weight entries recorded yet.</p>
        </div>
        <?php else: ?>
        <div class="section-heading">Recent Weight Entries</div>
        <div style="overflow-x:auto">
        <table class="table weight-table" style="margin:0">
            <thead>
                <tr><th>#</th><th>Weight (kg)</th><th>Date &amp; Time</th><th>Recorded By</th></tr>
            </thead>
            <tbody>
            <?php foreach ($weight_logs as $i => $wlog): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><strong><?= e(number_format((float)$wlog['weight'], 2)) ?> kg</strong></td>
                <td><?= e(formatDateTime($wlog['recorded_at'])) ?></td>
                <td><?= e($wlog['recorded_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- TAB: Treatments -->
    <div id="tab_treatments" class="tab-panel <?= $active_tab === 'treatments' ? 'active' : '' ?>" style="padding:1.25rem">
        <?php if (empty($treatments)): ?>
        <div class="empty-state" style="padding:2rem">
            <p>No treatments recorded for this cow.</p>
        </div>
        <?php else: ?>
        <?php foreach ($treatments as $t): ?>
        <div class="record-card">
            <div class="record-card-header">
                <div>
                    <div class="record-card-title">
                        <?= $t['medicine_name'] ? e($t['medicine_name']) : 'Treatment' ?>
                        <?php if ($t['dosage']): ?>
                        <span class="text-muted" style="font-weight:400"> — <?= e($t['dosage']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="record-card-meta">
                        <?= e(formatDate($t['treatment_date'])) ?> · Administered by <?= e($t['vet_name']) ?>
                        <?php if ($t['cost'] !== null): ?>
                        · Cost: <?= e(formatCurrency((float)$t['cost'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($t['notes']): ?>
            <div class="record-card-body"><?= e($t['notes']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TAB: Diagnoses -->
    <div id="tab_diagnoses" class="tab-panel <?= $active_tab === 'diagnoses' ? 'active' : '' ?>" style="padding:1.25rem">
        <?php if (empty($diagnoses)): ?>
        <div class="empty-state" style="padding:2rem">
            <p>No diagnosis records for this cow.</p>
        </div>
        <?php else: ?>
        <?php foreach ($diagnoses as $d): ?>
        <div class="record-card">
            <div class="record-card-header">
                <div>
                    <div class="record-card-meta">
                        <?= e(formatDateTime($d['created_at'])) ?> · Dr. <?= e($d['vet_name']) ?>
                    </div>
                    <div class="record-card-title" style="margin-top:.2rem">
                        <?= e($d['diagnosis']) ?>
                    </div>
                </div>
                <span class="badge <?= match($d['confidence_level']) {
                    'high'   => 'badge-green',
                    'medium' => 'badge-yellow',
                    'low'    => 'badge-red',
                    default  => 'badge-gray'
                } ?>">
                    <?= ucfirst(e($d['confidence_level'])) ?> confidence
                </span>
            </div>
            <?php if ($d['recommended_action']): ?>
            <div class="record-card-body">
                <strong>Recommended action:</strong> <?= e($d['recommended_action']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- TAB: Breeding -->
    <div id="tab_breeding" class="tab-panel <?= $active_tab === 'breeding' ? 'active' : '' ?>" style="padding:1.25rem">
        <?php if (empty($breeding_records)): ?>
        <div class="empty-state" style="padding:2rem">
            <p>No breeding records for this cow.</p>
        </div>
        <?php else: ?>
        <?php foreach ($breeding_records as $br): ?>
        <div class="record-card">
            <div class="record-card-header">
                <div>
                    <div class="record-card-meta">
                        Added <?= e(formatDate($br['created_at'])) ?> by <?= e($br['recorded_by_name']) ?>
                    </div>
                    <div style="display:flex;gap:.5rem;margin-top:.3rem;align-items:center;flex-wrap:wrap">
                        <span class="badge <?= breeding_status_badge($br['status']) ?>">
                            <?= e(ucfirst($br['status'])) ?>
                        </span>
                        <?php if ($br['calf_count'] > 0): ?>
                        <span class="badge badge-green"><?= (int)$br['calf_count'] ?> calf<?= $br['calf_count'] > 1 ? 'ves' : '' ?> recorded</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="record-card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.5rem .75rem;font-size:.84rem">
                    <?php if ($br['heat_cycle_date']): ?>
                    <div><span style="color:var(--text-secondary)">Heat cycle:</span> <?= e(formatDate($br['heat_cycle_date'])) ?></div>
                    <?php endif; ?>
                    <?php if ($br['insemination_date']): ?>
                    <div><span style="color:var(--text-secondary)">Insemination:</span> <?= e(formatDate($br['insemination_date'])) ?></div>
                    <?php endif; ?>
                    <?php if ($br['breeding_date']): ?>
                    <div><span style="color:var(--text-secondary)">Breeding date:</span> <?= e(formatDate($br['breeding_date'])) ?></div>
                    <?php endif; ?>
                    <?php if ($br['expected_calving_date']): ?>
                    <div><span style="color:var(--text-secondary)">Expected calving:</span> <?= e(formatDate($br['expected_calving_date'])) ?></div>
                    <?php endif; ?>
                    <?php if ($br['actual_calving_date']): ?>
                    <div><span style="color:var(--text-secondary)">Actual calving:</span> <?= e(formatDate($br['actual_calving_date'])) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($br['notes']): ?>
                <p style="margin-top:.5rem;margin-bottom:0"><?= e($br['notes']) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div><!-- .card -->

<?php
$inline_js = <<<JSEOF
// Tab switching
(function() {
    var btns   = document.querySelectorAll('#cowTabNav .tab-btn');
    var panels = document.querySelectorAll('.tab-panel');

    btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = this.dataset.tab;
            btns.forEach(function(b)   { b.classList.remove('active'); });
            panels.forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            document.getElementById('tab_' + target).classList.add('active');
            if (target === 'weight') loadWeightChart();
        });
    });

    // Weight chart (lazy-load on first view)
    var chartLoaded = false;
    function loadWeightChart() {
        if (chartLoaded) return;
        chartLoaded = true;
        var wrap = document.getElementById('weight-chart-wrap');

        fetch('/api/get_cow_weight_chart.php?cow_id={$cow_id}')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.labels || data.labels.length === 0) {
                    wrap.innerHTML = '<p style="text-align:center;color:var(--text-secondary);padding:3rem 1rem">No weight data recorded yet.</p>';
                    return;
                }
                var ctx = document.getElementById('weight-chart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Weight (kg)',
                            data: data.data,
                            borderColor: '#2563EB',
                            backgroundColor: 'rgba(37,99,235,.08)',
                            tension: .35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            fill: true,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                beginAtZero: false,
                                grid: { color: '#F3F4F6' },
                                ticks: { callback: function(v) { return v + ' kg'; } }
                            },
                            x: { grid: { display: false } }
                        }
                    }
                });
            })
            .catch(function() {
                wrap.innerHTML = '<p style="text-align:center;color:var(--danger);padding:2rem">Failed to load weight chart.</p>';
            });
    }

    // Auto-load chart if weight tab is initially active
    if (document.getElementById('tab_weight') && document.getElementById('tab_weight').classList.contains('active')) {
        loadWeightChart();
    }
})();
JSEOF;

require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
