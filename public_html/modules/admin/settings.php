<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
requireRole(['admin']);

$page_title = 'Module Settings';
$active_nav = 'admin_settings';

$db = getDB();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!verifyCsrfToken($token)) {
        flashMessage('error', 'Invalid CSRF token. Please try again.');
        redirect('/modules/admin/settings.php');
    }

    $modules = $_POST['modules'] ?? [];
    if (!is_array($modules)) $modules = [];

    // Server-side safety: alerts must always remain enabled
    if (!in_array('alerts', $modules, true)) {
        $modules[] = 'alerts';
    }

    // Get all module names
    $all_modules = $db->query("SELECT module_name FROM module_settings ORDER BY module_name")->fetchAll(PDO::FETCH_COLUMN);

    $update = $db->prepare("UPDATE module_settings SET is_enabled = ?, updated_by = ? WHERE module_name = ?");
    $user_id = (int)$_SESSION['user_id'];

    foreach ($all_modules as $mod) {
        $enabled = in_array($mod, $modules, true) ? 1 : 0;
        $update->execute([$enabled, $user_id, $mod]);
        auditLog($user_id, 'MODULE_TOGGLE', 'module_settings', null, null, ['module' => $mod, 'enabled' => $enabled]);
    }

    flashMessage('success', 'Module settings saved successfully.');
    redirect('/modules/admin/settings.php');
}

// Load all modules with descriptions
$module_info = [
    'cows'         => ['label' => 'Cow Management',    'desc' => 'Manage cows, health status, weight logs, and cow details.'],
    'milk'         => ['label' => 'Milk Production',   'desc' => 'Record daily milk output, fat %, quality flags, and revenue.'],
    'sales'        => ['label' => 'Sales',             'desc' => 'Cow sales and meat sales with profit/loss calculation.'],
    'workers'      => ['label' => 'Worker Management', 'desc' => 'Employee records, salaries, and task assignments.'],
    'feed_medicine'=> ['label' => 'Feed & Medicine',   'desc' => 'Inventory management for feed and medicine with low-stock alerts.'],
    'equipment'    => ['label' => 'Equipment',         'desc' => 'Equipment CRUD, status tracking, and maintenance schedule.'],
    'maintenance'  => ['label' => 'Farm Maintenance',  'desc' => 'Area/infrastructure maintenance logs and purchase tracking.'],
    'finance'      => ['label' => 'Finance',           'desc' => 'Income/expense tracking, net profit, and financial reports.'],
    'diagnosis'    => ['label' => 'Cow Diagnosis',     'desc' => 'Veterinarian-only symptom entry, vital signs, and diagnosis records.'],
    'breeding'     => ['label' => 'Breeding Management','desc'=> 'Heat cycle, insemination, calving countdown, and calf records.'],
    'reports'      => ['label' => 'Reports',           'desc' => 'PDF and Excel exports for all modules.'],
    'alerts'       => ['label' => 'Alerts',            'desc' => 'System-wide alert aggregation — cannot be disabled by convention.'],
];

$modules_stmt = $db->query("SELECT module_name, is_enabled, updated_at FROM module_settings ORDER BY module_name");
$modules_data = [];
foreach ($modules_stmt->fetchAll() as $row) {
    $modules_data[$row['module_name']] = $row;
}

require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2>Module Settings</h2>
        <p class="text-sm text-muted">Enable or disable system modules. Disabled modules are hidden from all users.</p>
    </div>
</div>

<form method="POST" action="/modules/admin/settings.php">
    <?= csrfField() ?>

    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-header">
            <span class="card-title">System Modules</span>
            <div class="d-flex gap-1">
                <button type="button" class="btn btn-secondary btn-sm" id="enableAllBtn">Enable All</button>
                <button type="button" class="btn btn-secondary btn-sm" id="disableAllBtn">Disable All</button>
            </div>
        </div>

        <?php foreach ($module_info as $key => $info):
            $row     = $modules_data[$key] ?? null;
            $enabled = $row ? (bool)$row['is_enabled'] : true;
            $updated = $row ? $row['updated_at'] : null;
            $locked  = $key === 'alerts'; // alerts cannot be disabled
        ?>
        <div class="toggle-wrap">
            <div class="toggle-info">
                <div class="toggle-name">
                    <?= e($info['label']) ?>
                    <?php if ($locked): ?>
                    <span class="badge badge-blue" style="font-size:.68rem;margin-left:.4rem">System</span>
                    <?php endif; ?>
                </div>
                <div class="toggle-desc"><?= e($info['desc']) ?></div>
                <?php if ($updated): ?>
                <div class="toggle-desc" style="margin-top:.15rem">Last changed: <?= e(formatDateTime($updated)) ?></div>
                <?php endif; ?>
            </div>
            <label class="toggle-switch" title="<?= $locked ? 'This module cannot be disabled' : '' ?>">
                <input
                    type="checkbox"
                    name="modules[]"
                    value="<?= e($key) ?>"
                    <?= $enabled || $locked ? 'checked' : '' ?>
                    <?= $locked ? 'disabled' : '' ?>
                    class="module-toggle"
                >
                <?php if ($locked): ?>
                <input type="hidden" name="modules[]" value="<?= e($key) ?>">
                <?php endif; ?>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex gap-1">
        <button type="submit" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Settings
        </button>
        <a href="/dashboard.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<script>
document.getElementById('enableAllBtn').addEventListener('click', function() {
    document.querySelectorAll('.module-toggle:not(:disabled)').forEach(function(cb) { cb.checked = true; });
});
document.getElementById('disableAllBtn').addEventListener('click', function() {
    if (!confirm('Disable all modules except Alerts? Users will lose access to all features.')) return;
    document.querySelectorAll('.module-toggle:not(:disabled)').forEach(function(cb) { cb.checked = false; });
});
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
