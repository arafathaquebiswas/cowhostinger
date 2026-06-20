<?php
require_once dirname(__DIR__, 2) . '/includes/role_guard.php';
require_once dirname(__DIR__, 2) . '/includes/farm_guard.php';
requireRole(['admin', 'reception']);
requireFarmScope();
requireModule('workers');

$db        = getDB();
$worker_id = (int)($_GET['id'] ?? 0);
if ($worker_id <= 0) { redirect('/modules/workers/index.php'); }

$stmt = $db->prepare(
    "SELECT w.id AS worker_id, w.salary, w.hire_date, w.termination_date, w.status AS worker_status,
            u.id AS user_id, u.name, u.email, u.role, u.status AS user_status, u.created_at AS user_created
     FROM workers w
     JOIN users u ON u.id = w.user_id
     WHERE w.id = ? AND " . farmFilter('u')
);
$stmt->execute([$worker_id]);
$worker = $stmt->fetch();
if (!$worker) { flashMessage('error', 'Worker not found.'); redirect('/modules/workers/index.php'); }

// Task summary counts
$task_counts = $db->prepare(
    "SELECT status, COUNT(*) AS cnt FROM worker_tasks WHERE worker_id = ? GROUP BY status"
);
$task_counts->execute([$worker_id]);
$task_summary = $task_counts->fetchAll(PDO::FETCH_KEY_PAIR);

$total_tasks     = array_sum($task_summary);
$completed_tasks = (int)($task_summary['completed']    ?? 0);
$open_tasks      = (int)($task_summary['pending']      ?? 0)
                 + (int)($task_summary['in_progress']  ?? 0);
$overdue_tasks   = (int)($task_summary['overdue']      ?? 0);
$completion_rate = $total_tasks > 0 ? round($completed_tasks / $total_tasks * 100) : 0;

// Recent tasks (last 20, active first)
$recent_stmt = $db->prepare(
    "SELECT id, task_type, description, assigned_date, completed_at, status
     FROM worker_tasks
     WHERE worker_id = ?
     ORDER BY FIELD(status,'overdue','in_progress','pending','completed'), assigned_date DESC
     LIMIT 20"
);
$recent_stmt->execute([$worker_id]);
$recent_tasks = $recent_stmt->fetchAll();

// Employment duration
$hire_ts  = $worker['hire_date'] ? strtotime($worker['hire_date']) : null;
$end_ts   = $worker['termination_date'] ? strtotime($worker['termination_date']) : time();
$emp_days = $hire_ts ? (int)(($end_ts - $hire_ts) / 86400) : null;

function task_badge_v(string $s): string {
    return match($s) {
        'pending'     => 'badge-yellow',
        'in_progress' => 'badge-blue',
        'completed'   => 'badge-green',
        'overdue'     => 'badge-red',
        default       => 'badge-gray',
    };
}

$page_title = 'Worker: ' . $worker['name'];
$active_nav = 'workers';
require_once dirname(__DIR__, 2) . '/includes/layout_header.php';
?>

<div class="page-header">
    <div>
        <h2><?= e($worker['name']) ?></h2>
        <p class="text-sm text-muted">Worker Profile</p>
    </div>
    <div style="display:flex;gap:.5rem">
        <?php if (hasRole(['admin'])): ?>
        <a href="/modules/workers/tasks.php#assign" class="btn btn-secondary">Assign Task</a>
        <a href="/modules/workers/form.php?id=<?= $worker['worker_id'] ?>" class="btn btn-secondary">Edit Profile</a>
        <?php endif; ?>
        <a href="/modules/workers/index.php" class="btn btn-secondary">Back to List</a>
    </div>
</div>

<!-- KPIs -->
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-bottom:1.5rem">
    <div class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-label">Monthly Salary</div>
        <div class="kpi-value" style="font-size:1.1rem"><?= formatCurrency((float)$worker['salary'], '৳') ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-label">Total Tasks</div>
        <div class="kpi-value"><?= $total_tasks ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Completed</div>
        <div class="kpi-value"><?= $completed_tasks ?></div>
    </div>
    <div class="kpi-card" style="--kpi-color:#0891B2;--kpi-soft:#ECFEFF">
        <div class="kpi-label">Open Tasks</div>
        <div class="kpi-value"><?= $open_tasks ?></div>
    </div>
    <?php if ($overdue_tasks > 0): ?>
    <div class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-label">Overdue</div>
        <div class="kpi-value"><?= $overdue_tasks ?></div>
    </div>
    <?php endif; ?>
    <div class="kpi-card" style="--kpi-color:#16A34A;--kpi-soft:#F0FDF4">
        <div class="kpi-label">Completion Rate</div>
        <div class="kpi-value"><?= $completion_rate ?>%</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:1.5rem;align-items:start">

    <!-- Left: Profile card -->
    <div>
        <div class="card">
            <div style="padding:1.25rem 1.25rem .75rem">
                <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem">
                    <div style="width:56px;height:56px;border-radius:50%;background:var(--primary-soft);display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:700;color:var(--primary);flex-shrink:0">
                        <?= mb_strtoupper(mb_substr($worker['name'], 0, 1)) ?>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:1.1rem"><?= e($worker['name']) ?></div>
                        <div class="text-muted" style="font-size:.85rem"><?= e($worker['email']) ?></div>
                    </div>
                </div>

                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <?php
                    $rows = [
                        ['Status',      '<span class="badge ' . match($worker['worker_status']) {
                                            'active'     => 'badge-green',
                                            'inactive'   => 'badge-yellow',
                                            'terminated' => 'badge-red',
                                            default      => 'badge-gray'
                                        } . '">' . ucfirst($worker['worker_status']) . '</span>'],
                        ['Role',        '<span class="role-badge role-' . e($worker['role']) . '">' . ucfirst(e($worker['role'])) . '</span>'],
                        ['Salary',      formatCurrency((float)$worker['salary'], '৳') . ' / month'],
                        ['Hire Date',   formatDate($worker['hire_date']) ?: '—'],
                        ['Employed',    $emp_days !== null ? number_format($emp_days) . ' days' : '—'],
                        ['Terminated',  $worker['termination_date'] ? formatDate($worker['termination_date']) : '—'],
                        ['Account',     $worker['user_status'] === 'active' ? '<span style="color:var(--success)">Active</span>' : '<span style="color:var(--danger)">Disabled</span>'],
                    ];
                    foreach ($rows as [$label, $val]):
                    ?>
                    <tr style="border-bottom:1px solid var(--border)">
                        <td style="padding:.55rem .35rem .55rem 0;color:var(--text-muted);white-space:nowrap;width:40%"><?= $label ?></td>
                        <td style="padding:.55rem 0"><?= $val ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <!-- Salary info -->
        <div class="card" style="margin-top:1rem">
            <div class="card-header">
                <span class="card-title">Salary Information</span>
            </div>
            <div style="padding:1rem 1.25rem">
                <?php
                $annual = (float)$worker['salary'] * 12;
                $daily  = $worker['salary'] > 0 ? round((float)$worker['salary'] / 30, 2) : 0;
                $rows2  = [
                    ['Monthly', formatCurrency((float)$worker['salary'], '৳')],
                    ['Annual Estimate', formatCurrency($annual, '৳')],
                    ['Daily Rate (est.)', formatCurrency($daily, '৳')],
                ];
                foreach ($rows2 as [$label, $val]):
                ?>
                <div style="display:flex;justify-content:space-between;padding:.45rem 0;border-bottom:1px solid var(--border);font-size:.875rem">
                    <span class="text-muted"><?= $label ?></span>
                    <span style="font-weight:600"><?= $val ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Task breakdown -->
        <?php if ($total_tasks > 0): ?>
        <div class="card" style="margin-top:1rem">
            <div class="card-header"><span class="card-title">Task Breakdown</span></div>
            <div style="padding:1rem 1.25rem">
                <?php
                $task_rows = [
                    ['Pending',     $task_summary['pending']     ?? 0, '#D97706'],
                    ['In Progress', $task_summary['in_progress'] ?? 0, '#2563EB'],
                    ['Completed',   $task_summary['completed']   ?? 0, '#16A34A'],
                    ['Overdue',     $task_summary['overdue']     ?? 0, '#DC2626'],
                ];
                foreach ($task_rows as [$label, $cnt, $color]):
                    if ($cnt == 0) continue;
                    $pct = round($cnt / $total_tasks * 100);
                ?>
                <div style="margin-bottom:.75rem">
                    <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.25rem">
                        <span><?= $label ?></span>
                        <span style="font-weight:600"><?= $cnt ?> (<?= $pct ?>%)</span>
                    </div>
                    <div style="height:6px;background:#E5E7EB;border-radius:3px;overflow:hidden">
                        <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:3px"></div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Completion rate progress -->
                <div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border)">
                    <div style="display:flex;justify-content:space-between;font-size:.82rem;margin-bottom:.35rem">
                        <strong>Completion Rate</strong>
                        <strong style="color:<?= $completion_rate >= 80 ? '#16A34A' : ($completion_rate >= 50 ? '#D97706' : '#DC2626') ?>">
                            <?= $completion_rate ?>%
                        </strong>
                    </div>
                    <div style="height:8px;background:#E5E7EB;border-radius:4px;overflow:hidden">
                        <div style="height:100%;width:<?= $completion_rate ?>%;background:<?= $completion_rate >= 80 ? '#16A34A' : ($completion_rate >= 50 ? '#D97706' : '#DC2626') ?>;border-radius:4px"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Tasks -->
    <div>
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
                <span class="card-title">Recent Tasks</span>
                <div style="display:flex;gap:.4rem">
                    <a href="/modules/workers/my_tasks.php?worker_id=<?= $worker['worker_id'] ?>"
                       class="btn btn-sm btn-secondary">All Tasks</a>
                    <?php if (hasRole(['admin'])): ?>
                    <a href="/modules/workers/tasks.php#assign" class="btn btn-sm btn-primary">Assign Task</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($recent_tasks)): ?>
            <div class="empty-state" style="padding:2rem">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
                </svg>
                <h3>No tasks assigned</h3>
                <?php if (hasRole(['admin'])): ?>
                <a href="/modules/workers/tasks.php#assign" class="btn btn-primary btn-sm" style="margin-top:.5rem">Assign First Task</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Assigned Date</th>
                        <th>Status</th>
                        <th>Completed</th>
                        <?php if (hasRole(['admin'])): ?>
                        <th style="width:80px">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_tasks as $t): ?>
                <tr>
                    <td>
                        <div style="font-weight:600"><?= e($t['task_type']) ?></div>
                        <?php if ($t['description']): ?>
                        <div class="text-muted" style="font-size:.8rem"><?= e(mb_strimwidth($t['description'], 0, 55, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap"><?= e(formatDate($t['assigned_date'])) ?></td>
                    <td>
                        <span class="badge <?= task_badge_v($t['status']) ?>">
                            <?= e(str_replace('_', ' ', ucfirst($t['status']))) ?>
                        </span>
                    </td>
                    <td style="font-size:.82rem"><?= $t['completed_at'] ? e(formatDate($t['completed_at'])) : '—' ?></td>
                    <?php if (hasRole(['admin'])): ?>
                    <td>
                        <a href="/modules/workers/edit_task.php?id=<?= $t['id'] ?>&back=<?= urlencode('/modules/workers/view.php?id=' . $worker['worker_id']) ?>"
                           class="btn btn-sm btn-secondary" title="Edit task">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if ($total_tasks > 20): ?>
            <div style="padding:.75rem 1rem;border-top:1px solid var(--border);text-align:center">
                <a href="/modules/workers/my_tasks.php?worker_id=<?= $worker['worker_id'] ?>"
                   class="btn btn-secondary btn-sm">View All <?= $total_tasks ?> Tasks</a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php require_once dirname(__DIR__, 2) . '/includes/layout_footer.php'; ?>
