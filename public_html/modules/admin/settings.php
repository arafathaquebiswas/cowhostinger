<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireFarmScope();
requireRole(['admin']);

$page_title = 'Module Settings';
$active_nav = 'admin_settings';

$db = getDB();

// Logging helper — writes to project-level logs/ directory (outside web root)
function _settings_log(string $msg): void {
    $log_dir  = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    @file_put_contents(
        $log_dir . '/module_toggle_debug.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// ─── LOCKED MODULES (can never be disabled) ───────────────────────────────────
const LOCKED_MODULES = ['alerts'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!verifyCsrfToken($token)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            jsonResponse(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
        }
        flashMessage('error', 'Invalid CSRF token. Please try again.');
        redirect('/modules/admin/settings.php');
    }

    $user_id = (int)$_SESSION['user_id'];
    $action  = $_POST['action'] ?? '';

    // ── AJAX: single-module toggle ─────────────────────────────────────────────
    if ($action === 'toggle_single') {
        $mod     = sanitize($_POST['module'] ?? '');
        $enabled = (int)(bool)((int)($_POST['enabled'] ?? 0));

        // Enforce locked modules
        if (in_array($mod, LOCKED_MODULES, true)) $enabled = 1;

        // Verify module exists in DB before updating
        $exists_stmt = $db->prepare("SELECT id FROM module_settings WHERE module_name = ?");
        $exists_stmt->execute([$mod]);

        if (!$exists_stmt->fetch()) {
            _settings_log("AJAX toggle REJECTED — unknown module: {$mod} by user #{$user_id}");
            jsonResponse(['ok' => false, 'error' => 'Unknown module.'], 400);
        }

        // Read current value for logging
        $before_stmt = $db->prepare("SELECT is_enabled FROM module_settings WHERE module_name=?");
        $before_stmt->execute([$mod]);
        $before = (int)$before_stmt->fetchColumn();

        $db->prepare("UPDATE module_settings SET is_enabled=?, updated_by=? WHERE module_name=?")
           ->execute([$enabled, $user_id, $mod]);

        _settings_log("AJAX toggle — module={$mod} before={$before} after={$enabled} user_id={$user_id} ip=" . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        auditLog($user_id, 'MODULE_TOGGLE', 'module_settings', null,
            ['module' => $mod, 'enabled' => $before],
            ['module' => $mod, 'enabled' => $enabled]);

        jsonResponse(['ok' => true, 'module' => $mod, 'enabled' => $enabled]);
    }

    // ── Batch save (Save All button) ───────────────────────────────────────────
    $modules = $_POST['modules'] ?? [];
    if (!is_array($modules)) $modules = [];

    // Enforce locked modules
    foreach (LOCKED_MODULES as $lm) {
        if (!in_array($lm, $modules, true)) $modules[] = $lm;
    }

    $all_modules = $db->query("SELECT module_name FROM module_settings ORDER BY module_name")->fetchAll(PDO::FETCH_COLUMN);
    $update      = $db->prepare("UPDATE module_settings SET is_enabled=?, updated_by=? WHERE module_name=?");

    $log_parts = [];
    foreach ($all_modules as $mod) {
        $enabled = in_array($mod, $modules, true) ? 1 : 0;
        if (in_array($mod, LOCKED_MODULES, true)) $enabled = 1;
        $update->execute([$enabled, $user_id, $mod]);
        auditLog($user_id, 'MODULE_TOGGLE', 'module_settings', null, null, ['module' => $mod, 'enabled' => $enabled]);
        $log_parts[] = "{$mod}={$enabled}";
    }
    _settings_log("Batch save by user_id={$user_id}: " . implode(', ', $log_parts));

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
                <div class="toggle-name" style="display:flex;align-items:center;gap:.5rem">
                    <?= e($info['label']) ?>
                    <?php if ($locked): ?>
                    <span class="badge badge-blue" style="font-size:.68rem">System</span>
                    <?php endif; ?>
                    <span class="save-indicator" id="si_<?= e($key) ?>"
                          style="font-size:.75rem;font-weight:500;min-width:52px;display:inline-block"></span>
                </div>
                <div class="toggle-desc"><?= e($info['desc']) ?></div>
                <?php if ($updated): ?>
                <div class="toggle-desc" style="margin-top:.15rem">Last changed: <?= e(formatDateTime($updated)) ?></div>
                <?php endif; ?>
            </div>
            <label class="toggle-switch" title="<?= $locked ? 'This module cannot be disabled' : 'Click to enable/disable — saves automatically' ?>">
                <input
                    type="checkbox"
                    name="modules[]"
                    value="<?= e($key) ?>"
                    <?= $enabled || $locked ? 'checked' : '' ?>
                    <?= $locked ? 'disabled' : '' ?>
                    class="module-toggle"
                    data-module="<?= e($key) ?>"
                >
                <?php if ($locked): ?>
                <input type="hidden" name="modules[]" value="<?= e($key) ?>">
                <?php endif; ?>
                <span class="toggle-slider"></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex gap-1" style="align-items:center">
        <button type="submit" class="btn btn-secondary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save All
        </button>
        <a href="/dashboard.php" class="btn btn-secondary">Back</a>
        <span style="font-size:.8rem;color:var(--text-muted);margin-left:.5rem">
            Toggles auto-save on click. "Save All" is a manual batch save.
        </span>
    </div>
</form>

<script>
(function () {
    'use strict';

    var CSRF_VALUE = document.querySelector('input[name="<?= CSRF_TOKEN_NAME ?>"]').value;
    var CSRF_NAME  = '<?= CSRF_TOKEN_NAME ?>';

    function setIndicator(modKey, state) {
        var el = document.getElementById('si_' + modKey);
        if (!el) return;
        if (state === 'saving') {
            el.textContent = '⏳ saving…';
            el.style.color  = 'var(--text-muted)';
        } else if (state === 'ok') {
            el.textContent = '✓ saved';
            el.style.color  = 'var(--success)';
            setTimeout(function () { el.textContent = ''; }, 2500);
        } else {
            el.textContent = '✗ error';
            el.style.color  = 'var(--danger)';
            setTimeout(function () { el.textContent = ''; }, 4000);
        }
    }

    // Auto-save each toggle on change
    document.querySelectorAll('.module-toggle:not(:disabled)').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var mod     = this.dataset.module;
            var enabled = this.checked ? 1 : 0;
            setIndicator(mod, 'saving');

            var fd = new FormData();
            fd.append('action',  'toggle_single');
            fd.append('module',  mod);
            fd.append('enabled', enabled);
            fd.append(CSRF_NAME, CSRF_VALUE);

            fetch('/modules/admin/settings.php', {
                method: 'POST',
                body:   fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setIndicator(mod, data.ok ? 'ok' : 'error');
                if (!data.ok) {
                    // Revert the checkbox if the server rejected the change
                    cb.checked = !cb.checked;
                }
            })
            .catch(function () {
                setIndicator(mod, 'error');
                cb.checked = !cb.checked; // revert
            });
        });
    });

    // Enable All / Disable All — still trigger auto-save for each
    document.getElementById('enableAllBtn').addEventListener('click', function () {
        document.querySelectorAll('.module-toggle:not(:disabled)').forEach(function (cb) {
            if (!cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change')); }
        });
    });

    document.getElementById('disableAllBtn').addEventListener('click', function () {
        if (!confirm('Disable all modules except Alerts? Users will lose access to all features.')) return;
        document.querySelectorAll('.module-toggle:not(:disabled)').forEach(function (cb) {
            if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change')); }
        });
    });
})();
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
