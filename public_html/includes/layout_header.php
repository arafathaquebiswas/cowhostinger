<?php
/**
 * Shared layout header — include AFTER requireAuth() / requireRole()
 *
 * Expected variables set before including this file:
 *   string $page_title   — shown in <title> and topbar
 *   string $active_nav   — key matching a nav item (e.g. 'cows', 'milk')
 *   array  $extra_css    — (optional) additional stylesheet URLs
 */

$_layout_user        = currentUser();
$_layout_initials    = strtoupper(substr($_layout_user['name'], 0, 1));
$_layout_role        = $_layout_user['role'];
$_layout_alert_count = getUnreadAlertCount();
$_layout_flash       = getFlashMessage();

$_nav_active = function (string $key) use ($active_nav): string {
    return (($active_nav ?? '') === $key) ? ' active' : '';
};

$_can = function (array $roles) use ($_layout_role): bool {
    return in_array($_layout_role, $roles, true);
};

$_module_enabled = static fn(string $module): bool => isModuleEnabled($module);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title ?? 'Page') ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if (!empty($extra_css)): foreach ($extra_css as $_css_url): ?>
    <link rel="stylesheet" href="<?= e($_css_url) ?>">
    <?php endforeach; endif; ?>
</head>
<body>
<div class="layout">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <a href="/dashboard.php" class="sidebar-brand">
            <div class="sidebar-brand-icon">🐄</div>
            <span class="sidebar-brand-text">Cow Mgmt<br>System</span>
        </a>

        <nav class="sidebar-nav">

            <!-- Overview -->
            <span class="nav-section-label">Overview</span>
            <a href="/dashboard.php" class="nav-item<?= $_nav_active('dashboard') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>
            <?php if ($_module_enabled('workers') && $_can(['worker'])): ?>
            <a href="/modules/workers/my_tasks.php" class="nav-item<?= $_nav_active('my_tasks') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                My Tasks
            </a>
            <?php endif; ?>

            <!-- Farm -->
            <span class="nav-section-label">Farm</span>
            <?php if ($_module_enabled('cows')): ?>
            <a href="/modules/cows/index.php" class="nav-item<?= $_nav_active('cows') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="14" rx="8" ry="6"/><circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/></svg>
                Cows
            </a>
            <?php endif; ?>

            <?php if ($_module_enabled('milk') && $_can(['admin', 'worker', 'veterinarian'])): ?>
            <a href="/modules/milk/index.php" class="nav-item<?= $_nav_active('milk') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2h8l2 6H6L8 2z"/><path d="M6 8v12a2 2 0 002 2h8a2 2 0 002-2V8"/></svg>
                Milk
            </a>
            <a href="/modules/milk/analytics.php" class="nav-item<?= $_nav_active('milk_analytics') ?>" style="padding-left:2.25rem;font-size:.83rem">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Analytics
            </a>
            <?php endif; ?>

            <?php if ($_module_enabled('breeding') && $_can(['admin', 'veterinarian', 'reception'])): ?>
            <a href="/modules/breeding/index.php" class="nav-item<?= $_nav_active('breeding') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                Breeding
            </a>
            <?php endif; ?>

            <!-- Health -->
            <span class="nav-section-label">Health</span>
            <?php if ($_module_enabled('feed_medicine') && $_can(['admin', 'veterinarian', 'worker'])): ?>
            <a href="/modules/feed_medicine/index.php" class="nav-item<?= $_nav_active('feed_medicine') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                Feed &amp; Medicine
            </a>
            <?php endif; ?>

            <?php if ($_module_enabled('diagnosis') && $_can(['admin', 'veterinarian'])): ?>
            <a href="/modules/diagnosis/index.php" class="nav-item<?= $_nav_active('diagnosis') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                Diagnosis
            </a>
            <?php endif; ?>
            <?php if ($_module_enabled('cows') && $_can(['admin', 'veterinarian', 'worker', 'reception'])): ?>
            <a href="/modules/treatments/index.php" class="nav-item<?= $_nav_active('treatments') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                Treatments
            </a>
            <?php endif; ?>

            <!-- Operations -->
            <span class="nav-section-label">Operations</span>
            <?php if ($_module_enabled('sales') && $_can(['admin', 'accountant', 'reception'])): ?>
            <a href="/modules/sales/index.php" class="nav-item<?= $_nav_active('sales') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                Sales
            </a>
            <?php endif; ?>

            <?php if ($_module_enabled('workers') && $_can(['admin'])): ?>
            <a href="/modules/workers/index.php" class="nav-item<?= $_nav_active('workers') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                Workers
            </a>
            <?php if ($_module_enabled('equipment')): ?>
            <a href="/modules/equipment/index.php" class="nav-item<?= $_nav_active('equipment') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 18.66l-1.41 1.41M21 12h-2M5 12H3M19.07 19.07l-1.41-1.41M5.34 5.34L3.93 3.93M12 3V1M12 23v-2"/></svg>
                Equipment
            </a>
            <?php endif; ?>
            <?php if ($_module_enabled('maintenance')): ?>
            <a href="/modules/maintenance/index.php" class="nav-item<?= $_nav_active('maintenance') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                Maintenance
            </a>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Finance -->
            <span class="nav-section-label">Finance &amp; Reports</span>
            <?php if ($_module_enabled('finance') && $_can(['admin', 'accountant'])): ?>
            <a href="/modules/finance/index.php" class="nav-item<?= $_nav_active('finance') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Finance
            </a>
            <a href="/modules/finance/charts.php" class="nav-item<?= $_nav_active('finance_charts') ?>" style="padding-left:2.25rem;font-size:.83rem">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Finance Charts
            </a>
            <?php if ($_module_enabled('reports')): ?>
            <a href="/modules/reports/index.php" class="nav-item<?= $_nav_active('reports') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Reports
            </a>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Alerts -->
            <span class="nav-section-label">Alerts</span>
            <a href="/modules/alerts/index.php" class="nav-item<?= $_nav_active('alerts') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                Alerts
                <?php if ($_layout_alert_count > 0): ?>
                <span class="nav-badge"><?= $_layout_alert_count > 99 ? '99+' : $_layout_alert_count ?></span>
                <?php endif; ?>
            </a>

            <!-- Admin only -->
            <?php if ($_can(['admin'])): ?>
            <span class="nav-section-label">Administration</span>
            <a href="/modules/admin/users.php" class="nav-item<?= $_nav_active('admin_users') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Users
            </a>
            <a href="/modules/admin/settings.php" class="nav-item<?= $_nav_active('admin_settings') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                Module Settings
            </a>
            <a href="/modules/admin/audit_log.php" class="nav-item<?= $_nav_active('audit_log') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg>
                Audit Log
            </a>
            <?php endif; ?>

        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-avatar"><?= e($_layout_initials) ?></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= e($_layout_user['name']) ?></div>
                    <div class="sidebar-user-role"><?= e(ucfirst(str_replace('_', ' ', $_layout_role))) ?></div>
                </div>
                <a href="/logout.php" class="sidebar-logout" title="Sign out">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </a>
            </div>
        </div>
    </aside>

    <div class="main-content">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle sidebar">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="3" y1="6"  x2="21" y2="6"/>
                    <line x1="3" y1="12" x2="21" y2="12"/>
                    <line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
            <span class="topbar-title"><?= e($page_title ?? 'Page') ?></span>
            <div class="topbar-actions">
                <a href="/modules/alerts/index.php" class="topbar-alert-btn" title="Alerts">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 01-3.46 0"/>
                    </svg>
                    <?php if ($_layout_alert_count > 0): ?>
                    <span class="alert-dot"><?= min($_layout_alert_count, 99) ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </header>

        <main class="page-content" id="pageContent">
            <?php if ($_layout_flash): ?>
            <div class="alert alert-<?= e($_layout_flash['type']) ?>" role="alert" style="margin-bottom:1.25rem">
                <?= e($_layout_flash['message']) ?>
            </div>
            <?php endif; ?>
