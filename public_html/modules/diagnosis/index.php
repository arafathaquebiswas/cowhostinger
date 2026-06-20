<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireAuth();
requireFarmScope();
requireModule('diagnosis');

$page_title = 'Diagnosis';
$active_nav = 'diagnosis';
$db = getDB();

// POST: delete diagnosis or symptom (admin/vet)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        flashMessage('error', 'Invalid request.');
        redirect('/modules/diagnosis/index.php');
    }
    if (!hasRole(['admin', 'veterinarian'])) {
        flashMessage('error', 'Insufficient permissions.');
        redirect('/modules/diagnosis/index.php');
    }
    $action  = $_POST['action']  ?? '';
    $user_id = (int)$_SESSION['user_id'];

    if ($action === 'delete_diagnosis') {
        $diag_id = (int)($_POST['diag_id'] ?? 0);
        if ($diag_id > 0) {
            $sel = $db->prepare("SELECT * FROM diagnosis_records WHERE id = ? AND " . farmFilter());
            $sel->execute([$diag_id]);
            $diag = $sel->fetch();
            if ($diag) {
                $db->prepare("DELETE FROM diagnosis_records WHERE id = ? AND " . farmFilter())->execute([$diag_id]);
                auditLog($user_id, 'DELETE_DIAGNOSIS', 'diagnosis_records', $diag_id, $diag, null);
                flashMessage('success', 'Diagnosis record deleted.');
            }
        }
    }

    if ($action === 'delete_symptom') {
        $sym_id = (int)($_POST['sym_id'] ?? 0);
        if ($sym_id > 0) {
            $sel = $db->prepare("SELECT * FROM cow_symptoms WHERE id = ?");
            $sel->execute([$sym_id]);
            $sym = $sel->fetch();
            if ($sym) {
                $db->prepare("DELETE FROM cow_symptoms WHERE id = ?")->execute([$sym_id]);
                auditLog($user_id, 'DELETE_COW_SYMPTOM', 'cow_symptoms', $sym_id, $sym, null);
                flashMessage('success', 'Symptom entry deleted.');
            }
        }
    }

    redirect('/modules/diagnosis/index.php?' . http_build_query(array_filter([
        'cow_id'     => $_POST['f_cow_id']     ?? '',
        'confidence' => $_POST['f_confidence'] ?? '',
        'tab'        => $_POST['f_tab']        ?? 'diagnoses',
    ])));
}

$active_tab = in_array($_GET['tab'] ?? '', ['diagnoses','symptoms'], true) ? $_GET['tab'] : 'diagnoses';

// ---- Diagnoses ----
$filter_cow_d  = (int)($_GET['cow_id']     ?? 0);
$filter_conf   = in_array($_GET['confidence'] ?? '', ['low','medium','high'], true) ? $_GET['confidence'] : '';
$page_d        = max(1, (int)($_GET['page_d'] ?? 1));
$per_page      = 20;

$dw  = [farmFilter('dr')]; $dp = [];
if ($filter_cow_d > 0) { $dw[] = 'dr.cow_id = ?'; $dp[] = $filter_cow_d; }
if ($filter_conf  !== '') { $dw[] = 'dr.confidence_level = ?'; $dp[] = $filter_conf; }
$dw_sql = implode(' AND ', $dw);

$d_count = $db->prepare("SELECT COUNT(*) FROM diagnosis_records dr WHERE {$dw_sql}");
$d_count->execute($dp);
$d_total = (int)$d_count->fetchColumn();
$pager_d = paginate($d_total, $per_page, $page_d);

$d_stmt = $db->prepare(
    "SELECT dr.id, dr.diagnosis, dr.confidence_level, dr.recommended_action, dr.photo_url, dr.created_at,
            c.id AS cow_id, c.tag_number, c.breed,
            u.name AS vet_name
     FROM diagnosis_records dr
     JOIN cows  c ON c.id = dr.cow_id
     JOIN users u ON u.id = dr.veterinarian_id
     WHERE {$dw_sql}
     ORDER BY dr.created_at DESC
     LIMIT ? OFFSET ?"
);
$d_stmt->execute(array_merge($dp, [$per_page, $pager_d['offset']]));
$diagnoses = $d_stmt->fetchAll();

// ---- Symptoms ----
$filter_cow_s = (int)($_GET['cow_id_s'] ?? 0);
$filter_sev   = in_array($_GET['severity'] ?? '', ['mild','moderate','severe'], true) ? $_GET['severity'] : '';
$page_s       = max(1, (int)($_GET['page_s'] ?? 1));

$sw  = [farmFilter('c')]; $sp = [];
if ($filter_cow_s > 0) { $sw[] = 'cs.cow_id = ?'; $sp[] = $filter_cow_s; }
if ($filter_sev  !== '') { $sw[] = 'cs.severity = ?'; $sp[] = $filter_sev; }
$sw_sql = implode(' AND ', $sw);

$s_count = $db->prepare("SELECT COUNT(*) FROM cow_symptoms cs JOIN cows c ON c.id = cs.cow_id WHERE {$sw_sql}");
$s_count->execute($sp);
$s_total = (int)$s_count->fetchColumn();
$pager_s = paginate($s_total, $per_page, $page_s);

$s_stmt = $db->prepare(
    "SELECT cs.id, cs.symptom, cs.severity, cs.temperature, cs.heart_rate,
            cs.appetite_status, cs.stool_condition, cs.blood_in_milk, cs.recorded_at,
            c.id AS cow_id, c.tag_number, c.breed,
            u.name AS recorded_by_name
     FROM cow_symptoms cs
     JOIN cows  c ON c.id = cs.cow_id
     JOIN users u ON u.id = cs.recorded_by
     WHERE {$sw_sql}
     ORDER BY cs.recorded_at DESC
     LIMIT ? OFFSET ?"
);
$s_stmt->execute(array_merge($sp, [$per_page, $pager_s['offset']]));
$symptoms = $s_stmt->fetchAll();

// Cow list for filter dropdowns
$cow_list = $db->prepare("SELECT id, tag_number, breed FROM cows WHERE " . farmFilter() . " ORDER BY tag_number ASC");
$cow_list->execute();
$cow_list = $cow_list->fetchAll();

function confidence_badge(string $level): string {
    return match($level) {
        'high'   => '<span class="badge badge-green">High</span>',
        'medium' => '<span class="badge badge-yellow">Medium</span>',
        'low'    => '<span class="badge badge-red">Low</span>',
        default  => '<span class="badge badge-gray">' . e(ucfirst($level)) . '</span>',
    };
}

function severity_badge(string $s): string {
    return match($s) {
        'severe'   => '<span class="badge badge-red">Severe</span>',
        'moderate' => '<span class="badge badge-yellow">Moderate</span>',
        'mild'     => '<span class="badge badge-green">Mild</span>',
        default    => '<span class="badge badge-gray">' . e(ucfirst($s)) . '</span>',
    };
}

$qs = static fn(array $p): string =>
    '/modules/diagnosis/index.php?' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null && $v !== 0));

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Diagnosis</h2>
        <p class="text-sm text-muted">Symptom entries &amp; veterinary diagnoses</p>
    </div>
    <?php if (hasRole(['admin','veterinarian'])): ?>
    <div style="display:flex;gap:.5rem">
        <a href="/modules/diagnosis/symptom_form.php"  class="btn btn-secondary btn-sm">+ Symptoms</a>
        <a href="/modules/diagnosis/diagnosis_form.php" class="btn btn-primary btn-sm">+ Diagnosis</a>
    </div>
    <?php endif; ?>
</div>

<!-- Tabs -->
<nav class="tab-nav" style="margin-bottom:1.25rem">
    <button class="tab-btn <?= $active_tab==='diagnoses'?'active':'' ?>" data-tab="tab_diagnoses">
        Diagnoses <span class="badge badge-blue" style="margin-left:.3rem"><?= number_format($d_total) ?></span>
    </button>
    <button class="tab-btn <?= $active_tab==='symptoms'?'active':'' ?>" data-tab="tab_symptoms">
        Symptoms <span class="badge badge-gray" style="margin-left:.3rem"><?= number_format($s_total) ?></span>
    </button>
</nav>

<!-- ==================== TAB: DIAGNOSES ==================== -->
<div id="tab_diagnoses" class="tab-panel <?= $active_tab==='diagnoses'?'active':'' ?>">

    <form method="GET" action="/modules/diagnosis/index.php"
          style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
        <input type="hidden" name="tab" value="diagnoses">
        <div class="form-group" style="margin:0;min-width:180px">
            <label class="form-label" style="font-size:.78rem">Cow</label>
            <select name="cow_id" class="form-control">
                <option value="">All Cows</option>
                <?php foreach ($cow_list as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filter_cow_d===$c['id']?'selected':'' ?>>
                    #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label" style="font-size:.78rem">Confidence</label>
            <select name="confidence" class="form-control">
                <option value="">All</option>
                <option value="high"   <?= $filter_conf==='high'   ?'selected':'' ?>>High</option>
                <option value="medium" <?= $filter_conf==='medium' ?'selected':'' ?>>Medium</option>
                <option value="low"    <?= $filter_conf==='low'    ?'selected':'' ?>>Low</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($filter_cow_d || $filter_conf): ?>
        <a href="/modules/diagnosis/index.php?tab=diagnoses" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <div class="card" style="margin-bottom:1rem">
        <?php if (empty($diagnoses)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
            </svg>
            <h3>No diagnoses found</h3>
            <?php if (hasRole(['admin','veterinarian'])): ?><p><a href="/modules/diagnosis/diagnosis_form.php">Create the first diagnosis.</a></p><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Cow</th>
                    <th>Diagnosis</th>
                    <th>Confidence</th>
                    <th>Recommended Action</th>
                    <th>Veterinarian</th>
                    <?php if (hasRole(['admin','veterinarian'])): ?><th style="width:80px">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($diagnoses as $dr): ?>
            <tr>
                <td style="white-space:nowrap;font-size:.85rem"><?= e(formatDateTime($dr['created_at'])) ?></td>
                <td>
                    <a href="/modules/cows/view.php?id=<?= $dr['cow_id'] ?>&tab=diagnoses" style="font-weight:600">
                        #<?= e($dr['tag_number']) ?>
                    </a>
                    <div class="text-muted" style="font-size:.79rem"><?= e($dr['breed']) ?></div>
                </td>
                <td style="max-width:220px">
                    <div style="font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($dr['diagnosis']) ?>">
                        <?= e($dr['diagnosis']) ?>
                    </div>
                </td>
                <td><?= confidence_badge($dr['confidence_level']) ?></td>
                <td style="max-width:200px;font-size:.83rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= $dr['recommended_action'] ? e($dr['recommended_action']) : '—' ?>
                </td>
                <td class="text-muted" style="font-size:.85rem"><?= e($dr['vet_name']) ?></td>
                <?php if (hasRole(['admin','veterinarian'])): ?>
                <td>
                    <div style="display:flex;gap:.35rem">
                        <a href="/modules/diagnosis/diagnosis_form.php?id=<?= $dr['id'] ?>" class="btn btn-sm btn-secondary" title="Edit">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this diagnosis record?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"       value="delete_diagnosis">
                            <input type="hidden" name="diag_id"      value="<?= $dr['id'] ?>">
                            <input type="hidden" name="f_cow_id"     value="<?= $filter_cow_d ?>">
                            <input type="hidden" name="f_confidence" value="<?= e($filter_conf) ?>">
                            <input type="hidden" name="f_tab"        value="diagnoses">
                            <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                            </button>
                        </form>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($pager_d['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pager_d['has_prev']): ?>
        <a href="<?= e($qs(['tab'=>'diagnoses','cow_id'=>$filter_cow_d,'confidence'=>$filter_conf,'page_d'=>$pager_d['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p=max(1,$pager_d['current_page']-2);$p<=min($pager_d['total_pages'],$pager_d['current_page']+2);$p++): ?>
        <a href="<?= e($qs(['tab'=>'diagnoses','cow_id'=>$filter_cow_d,'confidence'=>$filter_conf,'page_d'=>$p])) ?>" class="page-btn <?= $p===$pager_d['current_page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager_d['has_next']): ?>
        <a href="<?= e($qs(['tab'=>'diagnoses','cow_id'=>$filter_cow_d,'confidence'=>$filter_conf,'page_d'=>$pager_d['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ==================== TAB: SYMPTOMS ==================== -->
<div id="tab_symptoms" class="tab-panel <?= $active_tab==='symptoms'?'active':'' ?>">

    <form method="GET" action="/modules/diagnosis/index.php"
          style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
        <input type="hidden" name="tab" value="symptoms">
        <div class="form-group" style="margin:0;min-width:180px">
            <label class="form-label" style="font-size:.78rem">Cow</label>
            <select name="cow_id_s" class="form-control">
                <option value="">All Cows</option>
                <?php foreach ($cow_list as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filter_cow_s===$c['id']?'selected':'' ?>>
                    #<?= e($c['tag_number']) ?> — <?= e($c['breed']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label class="form-label" style="font-size:.78rem">Severity</label>
            <select name="severity" class="form-control">
                <option value="">All</option>
                <option value="severe"   <?= $filter_sev==='severe'   ?'selected':'' ?>>Severe</option>
                <option value="moderate" <?= $filter_sev==='moderate' ?'selected':'' ?>>Moderate</option>
                <option value="mild"     <?= $filter_sev==='mild'     ?'selected':'' ?>>Mild</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <?php if ($filter_cow_s || $filter_sev): ?>
        <a href="/modules/diagnosis/index.php?tab=symptoms" class="btn btn-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </form>

    <div class="card" style="margin-bottom:1rem">
        <?php if (empty($symptoms)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
            </svg>
            <h3>No symptom entries found</h3>
            <?php if (hasRole(['admin','veterinarian'])): ?><p><a href="/modules/diagnosis/symptom_form.php">Record the first symptom entry.</a></p><?php endif; ?>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Cow</th>
                    <th>Symptom</th>
                    <th>Severity</th>
                    <th>Temp (°C)</th>
                    <th>HR (bpm)</th>
                    <th>Appetite</th>
                    <th>Blood in Milk</th>
                    <th>Recorded By</th>
                    <?php if (hasRole(['admin','veterinarian'])): ?><th style="width:60px">Del</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($symptoms as $sym): ?>
            <tr>
                <td style="white-space:nowrap;font-size:.83rem"><?= e(formatDateTime($sym['recorded_at'])) ?></td>
                <td>
                    <a href="/modules/cows/view.php?id=<?= $sym['cow_id'] ?>&tab=diagnoses" style="font-weight:600">
                        #<?= e($sym['tag_number']) ?>
                    </a>
                    <div class="text-muted" style="font-size:.79rem"><?= e($sym['breed']) ?></div>
                </td>
                <td style="max-width:180px;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($sym['symptom']) ?></td>
                <td><?= severity_badge($sym['severity']) ?></td>
                <td><?= $sym['temperature'] ? e(number_format((float)$sym['temperature'],1)) : '—' ?></td>
                <td><?= $sym['heart_rate']  ? e($sym['heart_rate']) : '—' ?></td>
                <td>
                    <?php if ($sym['appetite_status']): ?>
                    <span class="badge <?= $sym['appetite_status']==='normal'?'badge-green':($sym['appetite_status']==='reduced'?'badge-yellow':'badge-red') ?>">
                        <?= e(ucfirst($sym['appetite_status'])) ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ($sym['blood_in_milk']): ?>
                    <span class="badge badge-red">Yes</span>
                    <?php else: ?>
                    <span class="badge badge-gray">No</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.83rem"><?= e($sym['recorded_by_name']) ?></td>
                <?php if (hasRole(['admin','veterinarian'])): ?>
                <td>
                    <form method="POST" onsubmit="return confirm('Delete this symptom entry?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="delete_symptom">
                        <input type="hidden" name="sym_id"  value="<?= $sym['id'] ?>">
                        <input type="hidden" name="f_tab"   value="symptoms">
                        <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                        </button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($pager_s['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pager_s['has_prev']): ?>
        <a href="<?= e($qs(['tab'=>'symptoms','cow_id_s'=>$filter_cow_s,'severity'=>$filter_sev,'page_s'=>$pager_s['current_page']-1])) ?>" class="page-btn">&#8249; Prev</a>
        <?php endif; ?>
        <?php for ($p=max(1,$pager_s['current_page']-2);$p<=min($pager_s['total_pages'],$pager_s['current_page']+2);$p++): ?>
        <a href="<?= e($qs(['tab'=>'symptoms','cow_id_s'=>$filter_cow_s,'severity'=>$filter_sev,'page_s'=>$p])) ?>" class="page-btn <?= $p===$pager_s['current_page']?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pager_s['has_next']): ?>
        <a href="<?= e($qs(['tab'=>'symptoms','cow_id_s'=>$filter_cow_s,'severity'=>$filter_sev,'page_s'=>$pager_s['current_page']+1])) ?>" class="page-btn">Next &#8250;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$init_tab = e("tab_{$active_tab}");
$inline_js = <<<JSEOF
(function() {
    var panels = document.querySelectorAll('.tab-panel');
    var btns   = document.querySelectorAll('.tab-btn');
    btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = btn.getAttribute('data-tab');
            panels.forEach(function(p) { p.classList.remove('active'); });
            btns.forEach(function(b)   { b.classList.remove('active'); });
            document.getElementById(target).classList.add('active');
            btn.classList.add('active');
        });
    });
    var initEl = document.getElementById('{$init_tab}');
    if (initEl) {
        panels.forEach(function(p) { p.classList.remove('active'); });
        btns.forEach(function(b)   { b.classList.remove('active'); });
        initEl.classList.add('active');
        var btn = document.querySelector('[data-tab="{$init_tab}"]');
        if (btn) btn.classList.add('active');
    }
})();
JSEOF;
require_once dirname(__DIR__, 2) . '/includes/layout_footer.php';
?>
