<?php
require_once __DIR__ . '/includes/role_guard.php';
requireAuth();

// This page is for 'user' role only
if (!hasRole(['user'])) {
    redirect('/dashboard.php');
}

$db   = getDB();
$user = currentUser();

// ── Handle profile update ─────────────────────────────────────────────────────
$profile_error   = '';
$profile_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $profile_error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            $new_name      = sanitize($_POST['name']      ?? '');
            $new_farm_name = sanitize($_POST['farm_name'] ?? '');
            $new_phone     = sanitize($_POST['phone']     ?? '');

            if ($new_name === '') {
                $profile_error = 'Full name is required.';
            } elseif (strlen($new_name) > 100) {
                $profile_error = 'Full name is too long.';
            } else {
                $db->prepare(
                    "UPDATE users SET name=?, farm_name=?, phone=? WHERE id=?"
                )->execute([
                    $new_name,
                    $new_farm_name !== '' ? $new_farm_name : null,
                    $new_phone     !== '' ? $new_phone     : null,
                    $user['id'],
                ]);
                auditLog($user['id'], 'UPDATE_PROFILE', 'users', $user['id']);
                $_SESSION['user_name'] = $new_name;
                $user['name'] = $new_name;
                $profile_success = true;
            }
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new_pwd = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $row = $db->prepare("SELECT password_hash FROM users WHERE id=?");
            $row->execute([$user['id']]);
            $hash = $row->fetchColumn();

            if (!password_verify($current, $hash)) {
                $profile_error = 'Current password is incorrect.';
            } elseif (strlen($new_pwd) < 8) {
                $profile_error = 'New password must be at least 8 characters.';
            } elseif ($new_pwd !== $confirm) {
                $profile_error = 'New passwords do not match.';
            } else {
                $new_hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$new_hash, $user['id']]);
                auditLog($user['id'], 'CHANGE_PASSWORD', 'users', $user['id']);
                $profile_success = true;
            }
        }
    }
}

// ── Farm statistics — scoped to this user's farm ─────────────────────────────
$fid = (int)($_SESSION['farm_id'] ?? 0);

$q = $db->prepare("SELECT COUNT(*) FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')");
$q->execute([$fid]); $total_cows = (int)$q->fetchColumn();

$q = $db->prepare("SELECT COUNT(*) FROM cows WHERE farm_id=? AND status IN ('active','lactating','dry')");
$q->execute([$fid]); $healthy_cows = (int)$q->fetchColumn();

$q = $db->prepare("SELECT COUNT(*) FROM cows WHERE farm_id=? AND status IN ('sick','quarantine')");
$q->execute([$fid]); $sick_cows = (int)$q->fetchColumn();

$q = $db->prepare("SELECT COUNT(*) FROM cows WHERE farm_id=? AND (status='pregnant' OR is_pregnant=1)");
$q->execute([$fid]); $pregnant_cows = (int)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE farm_id=? AND DATE(recorded_at)=CURDATE()");
$q->execute([$fid]); $milk_today = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COALESCE(SUM(liters),0) FROM milk_records WHERE farm_id=? AND MONTH(recorded_at)=MONTH(CURDATE()) AND YEAR(recorded_at)=YEAR(CURDATE())");
$q->execute([$fid]); $milk_month = (float)$q->fetchColumn();

$q = $db->prepare("SELECT COUNT(*) FROM alerts WHERE farm_id=? AND is_read=0");
$q->execute([$fid]); $alert_count = (int)$q->fetchColumn();

// Recent cows (farm-scoped)
$q = $db->prepare(
    "SELECT id, tag_number, breed, status, health_status, birth_date
     FROM cows WHERE farm_id=? AND status NOT IN ('sold','deceased','archived')
     ORDER BY created_at DESC LIMIT 8"
);
$q->execute([$fid]); $recent_cows = $q->fetchAll();

// User profile from DB (to get farm_name, phone)
$profile_row = $db->prepare("SELECT name, farm_name, phone FROM users WHERE id=?");
$profile_row->execute([$user['id']]);
$profile = $profile_row->fetch();

$page_title = 'My Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── User layout (no sidebar) ── */
        body { background: var(--bg-base); }

        .user-nav {
            background: var(--bg-sidebar);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.75rem;
            height: var(--header-h);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .user-nav-brand {
            display: flex;
            align-items: center;
            gap: .55rem;
            font-weight: 700;
            font-size: .95rem;
            color: #fff;
            text-decoration: none;
        }
        .user-nav-brand:hover { text-decoration: none; }
        .user-nav-right { display: flex; align-items: center; gap: .75rem; }
        .user-nav-name { font-size: .85rem; color: rgba(255,255,255,.8); }
        .user-nav-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-light);
            color: #fff;
            font-weight: 700;
            font-size: .85rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .user-nav-logout {
            color: rgba(255,255,255,.7);
            font-size: .82rem;
            padding: .35rem .7rem;
            border-radius: var(--radius-sm);
            border: 1px solid rgba(255,255,255,.2);
            text-decoration: none;
            transition: var(--transition);
        }
        .user-nav-logout:hover { color: #fff; background: rgba(255,255,255,.1); text-decoration: none; }

        .user-main {
            max-width: 1100px;
            margin: 0 auto;
            padding: 2rem 1.5rem 4rem;
        }

        .user-greeting {
            margin-bottom: 2rem;
        }
        .user-greeting h2 {
            font-size: 1.45rem;
            font-weight: 700;
            margin-bottom: .2rem;
        }

        /* ── KPI grid ── */
        .u-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .u-kpi {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.25rem 1.4rem;
            display: flex;
            flex-direction: column;
            gap: .35rem;
        }
        .u-kpi-val {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1;
            color: var(--c, var(--primary));
        }
        .u-kpi-lbl {
            font-size: .78rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .u-kpi-icon {
            width: 34px;
            height: 34px;
            border-radius: var(--radius);
            background: var(--cs, var(--primary-soft));
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: .3rem;
        }
        .u-kpi-icon svg { color: var(--c, var(--primary)); }

        /* ── Two-col layout ── */
        .u-cols {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 1.5rem;
            align-items: start;
        }
        @media (max-width: 760px) {
            .u-cols { grid-template-columns: 1fr; }
        }

        /* ── Tables ── */
        .u-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .u-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            font-size: .9rem;
        }
        .u-card-body { padding: 1.25rem; }

        /* ── Alert/success messages ── */
        .u-alert {
            padding: .75rem 1rem;
            border-radius: var(--radius);
            font-size: .875rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: .6rem;
        }
        .u-alert-success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }
        .u-alert-danger   { background: var(--danger-bg);  border: 1px solid var(--danger-border);  color: var(--danger); }

        /* ── Status badge ── */
        .cow-status { display: inline-block; padding: .18rem .55rem; border-radius: 50px; font-size: .7rem; font-weight: 600; }
        .cs-active   { background: #D8F3DC; color: #1B4332; }
        .cs-sick     { background: #FEF2F2; color: #DC2626; }
        .cs-quar     { background: #FEF2F2; color: #DC2626; }
        .cs-pregnant { background: #F5F3FF; color: #7C3AED; }
        .cs-lact     { background: #EFF6FF; color: #1D4ED8; }
        .cs-dry      { background: #FFF7ED; color: #C2410C; }
        .cs-other    { background: #F3F4F6; color: #374151; }
    </style>
</head>
<body>

<!-- ── Top nav ── -->
<nav class="user-nav">
    <a href="/user_dashboard.php" class="user-nav-brand">
        <span style="font-size:1.3rem">🐄</span>
        <?= e(APP_NAME) ?>
    </a>
    <div class="user-nav-right">
        <div class="user-nav-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
        <span class="user-nav-name"><?= e(explode(' ', $user['name'])[0]) ?></span>
        <a href="/logout.php" class="user-nav-logout">Sign Out</a>
    </div>
</nav>

<div class="user-main">

    <!-- Greeting -->
    <div class="user-greeting">
        <h2>Welcome, <?= e(explode(' ', $user['name'])[0]) ?></h2>
        <p class="text-sm text-muted"><?= date('l, d F Y') ?> &nbsp;·&nbsp; Farm overview</p>
    </div>

    <!-- KPI row -->
    <div class="u-kpi-grid">
        <div class="u-kpi">
            <div class="u-kpi-icon" style="--c:#2D6A4F;--cs:#D8F3DC">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="14" rx="8" ry="6"/><circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/></svg>
            </div>
            <div class="u-kpi-val" style="--c:#2D6A4F"><?= number_format($total_cows) ?></div>
            <div class="u-kpi-lbl">Total Cows</div>
        </div>
        <div class="u-kpi">
            <div class="u-kpi-icon" style="--c:#059669;--cs:#F0FDF4">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div class="u-kpi-val" style="--c:#059669"><?= number_format($healthy_cows) ?></div>
            <div class="u-kpi-lbl">Healthy</div>
        </div>
        <div class="u-kpi">
            <div class="u-kpi-icon" style="--c:#DC2626;--cs:#FEF2F2">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div class="u-kpi-val" style="--c:#DC2626"><?= number_format($sick_cows) ?></div>
            <div class="u-kpi-lbl">Sick / Quarantine</div>
        </div>
        <div class="u-kpi">
            <div class="u-kpi-icon" style="--c:#7C3AED;--cs:#F5F3FF">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
            </div>
            <div class="u-kpi-val" style="--c:#7C3AED"><?= number_format($pregnant_cows) ?></div>
            <div class="u-kpi-lbl">Pregnant</div>
        </div>
        <div class="u-kpi">
            <div class="u-kpi-icon" style="--c:#0284C7;--cs:#F0F9FF">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2h8l2 6H6L8 2z"/><path d="M6 8v12a2 2 0 002 2h8a2 2 0 002-2V8"/></svg>
            </div>
            <div class="u-kpi-val" style="--c:#0284C7"><?= number_format($milk_today, 1) ?><span style="font-size:.9rem;font-weight:500"> L</span></div>
            <div class="u-kpi-lbl">Milk Today</div>
        </div>
        <div class="u-kpi">
            <div class="u-kpi-icon" style="--c:#D97706;--cs:#FFFBEB">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="u-kpi-val" style="--c:#D97706"><?= number_format($milk_month, 0) ?><span style="font-size:.9rem;font-weight:500"> L</span></div>
            <div class="u-kpi-lbl">Milk This Month</div>
        </div>
        <?php if ($alert_count > 0): ?>
        <div class="u-kpi">
            <div class="u-kpi-icon" style="--c:#DC2626;--cs:#FEF2F2">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            </div>
            <div class="u-kpi-val" style="--c:#DC2626"><?= $alert_count ?></div>
            <div class="u-kpi-lbl">Active Alerts</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cows + Profile -->
    <div class="u-cols">

        <!-- Recent cows -->
        <div class="u-card">
            <div class="u-card-header">
                <span>Farm Cows</span>
                <span class="text-muted text-sm"><?= number_format($total_cows) ?> total</span>
            </div>
            <?php if (empty($recent_cows)): ?>
            <div class="u-card-body" style="text-align:center;padding:2.5rem;color:var(--text-muted)">
                <div style="font-size:2.5rem;margin-bottom:.75rem">🐄</div>
                <p>No cows have been added yet.</p>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
                <table class="table" style="margin:0">
                    <thead>
                        <tr>
                            <th>Tag #</th>
                            <th>Breed</th>
                            <th>Status</th>
                            <th>Health</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_cows as $cow):
                        $cs_class = match($cow['status']) {
                            'active'     => 'cs-active',
                            'sick'       => 'cs-sick',
                            'quarantine' => 'cs-quar',
                            'pregnant'   => 'cs-pregnant',
                            'lactating'  => 'cs-lact',
                            'dry'        => 'cs-dry',
                            default      => 'cs-other',
                        };
                    ?>
                    <tr>
                        <td style="font-weight:600">#<?= e($cow['tag_number']) ?></td>
                        <td><?= e($cow['breed']) ?></td>
                        <td>
                            <span class="cow-status <?= $cs_class ?>">
                                <?= e(str_replace('_', ' ', ucfirst($cow['status']))) ?>
                            </span>
                        </td>
                        <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-secondary);font-size:.83rem"
                            title="<?= e($cow['health_status']) ?>">
                            <?= e($cow['health_status'] ?: '—') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_cows > 8): ?>
            <div style="padding:.75rem 1.25rem;border-top:1px solid var(--border);font-size:.82rem;color:var(--text-muted)">
                Showing 8 of <?= number_format($total_cows) ?> cows
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Profile -->
        <div style="display:flex;flex-direction:column;gap:1.25rem">

            <!-- Profile update -->
            <div class="u-card">
                <div class="u-card-header">My Profile</div>
                <div class="u-card-body">
                    <?php if ($profile_success): ?>
                    <div class="u-alert u-alert-success">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                        Saved successfully.
                    </div>
                    <?php elseif ($profile_error !== ''): ?>
                    <div class="u-alert u-alert-danger">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <?= e($profile_error) ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" autocomplete="on">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= e($profile['name']) ?>" required maxlength="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Farm Name</label>
                            <input type="text" name="farm_name" class="form-control"
                                   value="<?= e($profile['farm_name'] ?? '') ?>"
                                   placeholder="Optional" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= e($profile['phone'] ?? '') ?>"
                                   placeholder="Optional" maxlength="30">
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label text-muted text-xs">Email</label>
                            <input type="text" class="form-control" value="<?= e($user['email']) ?>" disabled
                                   style="background:var(--bg-base);color:var(--text-muted)">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:1rem;width:100%">Save Profile</button>
                    </form>
                </div>
            </div>

            <!-- Change password -->
            <div class="u-card">
                <div class="u-card-header">Change Password</div>
                <div class="u-card-body">
                    <form method="POST" autocomplete="off">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control"
                                   placeholder="••••••••" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control"
                                   placeholder="Min 8 characters" required minlength="8">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control"
                                   placeholder="Repeat new password" required>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm" style="width:100%">Update Password</button>
                    </form>
                </div>
            </div>

        </div>
    </div>

</div>

</body>
</html>
