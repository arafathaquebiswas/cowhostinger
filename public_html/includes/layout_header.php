<?php
/**
 * Shared layout header — include AFTER requireAuth() / requireRole()
 *
 * Expected variables set before including this file:
 *   string $page_title   — shown in <title> and topbar
 *   string $active_nav   — key matching a nav item (e.g. 'cows', 'milk')
 *   array  $extra_css    — (optional) additional stylesheet URLs
 */

require_once __DIR__ . '/farm_guard.php';

$_layout_user        = currentUser();
$_layout_initials    = strtoupper(substr($_layout_user['name'], 0, 1));
$_layout_role        = $_layout_user['role'];
$_layout_alert_count = getUnreadAlertCount();
$_layout_flash       = getFlashMessage();
$_layout_farm        = currentFarm();
$_layout_farm_name   = $_layout_farm['farm_name'] ?? APP_NAME;
$_layout_plan        = farmPlan();
$_layout_expiry_html = farmExpiryBanner();
$_layout_is_free     = $_layout_plan['is_free'] ?? true;
$_layout_is_blocked  = $_layout_plan['is_blocked'] ?? false;

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
    <style>
    /* ── SaaS banners ──────────────────────────────────────────── */
    .saas-banner{padding:.55rem 1.25rem;font-size:.83rem;line-height:1.5;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
    .saas-banner-danger{background:#FEF2F2;color:#991B1B;border-bottom:1px solid #FECACA}
    .saas-banner-warning{background:#FFFBEB;color:#92400E;border-bottom:1px solid #FDE68A}
    .saas-banner-info{background:#EFF6FF;color:#1E40AF;border-bottom:1px solid #BFDBFE}
    .saas-banner-btn{display:inline-block;padding:.2rem .75rem;background:currentColor;color:#fff!important;border-radius:4px;font-size:.78rem;font-weight:600;text-decoration:none;opacity:1}
    .saas-banner-danger .saas-banner-btn{background:#DC2626;color:#fff!important}
    .saas-banner-warning .saas-banner-btn{background:#D97706;color:#fff!important}
    .saas-banner-info .saas-banner-btn{background:#2563EB;color:#fff!important}
    /* ── Plan badge ───────────────────────────────────────────── */
    .plan-badge{display:inline-block;font-size:.58rem;font-weight:700;letter-spacing:.05em;padding:.1rem .45rem;border-radius:50px;color:#fff;text-transform:uppercase;vertical-align:middle}
    /* ── Locked nav item ──────────────────────────────────────── */
    .nav-item-locked{cursor:pointer;opacity:.55;pointer-events:all}
    .nav-item-locked:hover{opacity:.8;background:var(--nav-hover)}
    /* ── Upgrade button ───────────────────────────────────────── */
    .btn-upgrade{background:linear-gradient(135deg,#7C3AED,#A855F7);color:#fff;border:none;font-size:.78rem}
    .btn-upgrade:hover{background:linear-gradient(135deg,#6D28D9,#9333EA);color:#fff}
    /* ── Upgrade modal ────────────────────────────────────────── */
    .upgrade-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:none;align-items:center;justify-content:center}
    .upgrade-modal-overlay.active{display:flex}
    .upgrade-modal{background:#fff;border-radius:16px;padding:2.5rem 2rem 2rem;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25);position:relative}
    .upgrade-modal-icon{font-size:2.8rem;margin-bottom:.5rem}
    .upgrade-modal h3{font-size:1.25rem;font-weight:700;color:#111827;margin:0 0 .5rem}
    .upgrade-modal p{color:#6B7280;font-size:.9rem;margin:0 0 1.5rem}
    .upgrade-modal-plans{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:1.25rem}
    .upgrade-plan-card{border:2px solid var(--border);border-radius:10px;padding:.75rem .5rem;cursor:pointer;transition:.15s}
    .upgrade-plan-card:hover,.upgrade-plan-card.selected{border-color:#7C3AED;background:#F5F3FF}
    .upgrade-plan-name{font-weight:700;font-size:.82rem;color:#1F2937}
    .upgrade-plan-price{font-size:.75rem;color:#6B7280;margin-top:.15rem}
    .upgrade-modal-close{position:absolute;top:.75rem;right:.75rem;background:none;border:none;font-size:1.4rem;cursor:pointer;color:#9CA3AF;line-height:1}
    /* ── AB IT watermark ──────────────────────────────────────────── */
    .saas-watermark{position:fixed;top:50%;right:-30px;transform:translateY(-50%) rotate(90deg);font-size:4rem;font-weight:900;color:rgba(45,106,79,.05);pointer-events:none;z-index:1;letter-spacing:.1em;user-select:none;white-space:nowrap}
    .abit-stamp{position:fixed;bottom:12px;right:14px;font-size:.65rem;font-weight:700;color:rgba(45,106,79,.4);letter-spacing:.08em;pointer-events:none;z-index:2;user-select:none}
    /* ── Usage meter bars ─────────────────────────────────────── */
    .usage-meter{height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin:.25rem 0 .1rem}
    .usage-meter-fill{height:100%;border-radius:3px;background:var(--primary);transition:.3s}
    .usage-meter-fill.warn{background:#D97706}
    .usage-meter-fill.full{background:#DC2626}
    /* ── Impersonation banner ─────────────────────────────────── */
    .impersonation-banner{background:#7C3AED;color:#fff;padding:.45rem 1.25rem;font-size:.8rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .impersonation-banner a{color:#E9D5FF;font-weight:600;text-decoration:underline}
    </style>
</head>
<body>
<div class="saas-watermark">AB IT</div>
<div class="abit-stamp">AB IT</div>

<?php if (isImpersonating()): ?>
<div class="impersonation-banner">
    <span>🔍 Impersonating: <strong><?= e($_layout_farm_name) ?></strong> — you are viewing as CEO</span>
    <form method="POST" action="/modules/super_admin/end_impersonation.php" style="margin:0;display:inline">
        <?= csrfField() ?>
        <button type="submit" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;padding:.25rem .75rem;border-radius:6px;cursor:pointer;font-size:.8rem;font-weight:600">
            Exit Impersonation
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Upgrade Modal -->
<div class="upgrade-modal-overlay" id="upgradeModalOverlay">
    <div class="upgrade-modal">
        <button class="upgrade-modal-close" onclick="closeUpgradeModal()">&#x2715;</button>
        <div class="upgrade-modal-icon">🚀</div>
        <h3>Upgrade Your Plan</h3>
        <p id="upgradeModalMsg">Upgrade your plan to unlock this feature.</p>
        <div class="upgrade-modal-plans">
            <div class="upgrade-plan-card selected">
                <div class="upgrade-plan-name">Basic</div>
                <div class="upgrade-plan-price">৳499/mo</div>
            </div>
            <div class="upgrade-plan-card">
                <div class="upgrade-plan-name">Pro</div>
                <div class="upgrade-plan-price">৳999/mo</div>
            </div>
            <div class="upgrade-plan-card">
                <div class="upgrade-plan-name">Enterprise</div>
                <div class="upgrade-plan-price">৳2499/mo</div>
            </div>
        </div>
        <a href="/modules/subscription/index.php" class="btn btn-primary btn-block" style="background:linear-gradient(135deg,#7C3AED,#A855F7);border:none">
            View Plans &amp; Upgrade
        </a>
        <p style="font-size:.75rem;color:#9CA3AF;margin:.75rem 0 0">Contact AB IT: <strong>support@abit.com.bd</strong></p>
    </div>
</div>

<div class="layout">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <a href="/dashboard.php" class="sidebar-brand">
            <div class="sidebar-brand-icon">🐄</div>
            <span class="sidebar-brand-text">
                <?= e($_layout_farm_name) ?>
                <?php if ($_layout_farm): ?>
                <br><span style="font-size:.62rem;font-weight:500;opacity:.7;letter-spacing:.02em"><?= e($_layout_farm['farm_code'] ?? '') ?></span>
                <?php endif; ?>
                <br><?= farmPlanBadge() ?>
            </span>
        </a>

        <nav class="sidebar-nav">

        <?php if (isSupportStaff()): ?>
            <!-- Support Staff Navigation -->
            <span class="nav-section-label">Support</span>
            <a href="/modules/support/dashboard.php" class="nav-item<?= $_nav_active('support_dashboard') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>
            <a href="/modules/support/index.php" class="nav-item<?= $_nav_active('support') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                Tickets
                <?php
                $_supp_open = (int)(getDB()->query("SELECT COUNT(*) FROM support_tickets WHERE assigned_to={$_layout_user['id']} AND status IN ('open','in_progress')")->fetchColumn() ?? 0);
                if ($_supp_open > 0):
                ?>
                <span class="nav-badge"><?= $_supp_open ?></span>
                <?php endif; ?>
            </a>
            <span class="nav-section-label">Tools</span>
            <a href="/modules/support/dashboard.php#farm-search" class="nav-item">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Farm Search
            </a>
        <?php else: ?>

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
            <?php endif; ?>
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

            <!-- Finance -->
            <span class="nav-section-label">Finance &amp; Reports</span>
            <?php if ($_can(['admin', 'accountant'])): ?>
            <?php if ($_module_enabled('finance') && canAccess('finance.view')): ?>
            <a href="/modules/finance/index.php" class="nav-item<?= $_nav_active('finance') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Finance
            </a>
            <a href="/modules/finance/charts.php" class="nav-item<?= $_nav_active('finance_charts') ?>" style="padding-left:2.25rem;font-size:.83rem">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Finance Charts
            </a>
            <?php else: ?>
            <?= lockedNavItem('Finance', '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>', 'Finance module') ?>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($_can(['admin', 'accountant'])): ?>
            <?php if ($_module_enabled('reports') && canAccess('report.view')): ?>
            <a href="/modules/reports/index.php" class="nav-item<?= $_nav_active('reports') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Reports
            </a>
            <?php else: ?>
            <?= lockedNavItem('Reports', '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>', 'Reports & Exports') ?>
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

            <!-- Support Tickets (farm users only) -->
            <?php if ($_can(['admin','accountant','veterinarian','reception'])): ?>
            <a href="/modules/support/index.php" class="nav-item<?= $_nav_active('support') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                Support
            </a>
            <?php endif; ?>

            <!-- Subscription (farm users only) -->
            <?php if (!isSaasUser()): ?>
            <a href="/modules/subscription/index.php" class="nav-item<?= $_nav_active('subscription') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Subscription <?= farmPlanBadge() ?>
            </a>
            <?php endif; ?>

            <!-- Super Admin -->
            <?php if ($_layout_role === 'superadmin'): ?>
            <span class="nav-section-label">Super Admin</span>
            <a href="/modules/super_admin/dashboard.php" class="nav-item<?= $_nav_active('ceo_dashboard') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                CEO Dashboard
            </a>
            <a href="/modules/super_admin/index.php" class="nav-item<?= $_nav_active('super_admin') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                All Farms
            </a>
            <a href="/modules/super_admin/revenue.php" class="nav-item<?= $_nav_active('revenue') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                Revenue
            </a>
            <a href="/modules/support/index.php" class="nav-item<?= $_nav_active('support') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                Tickets
            </a>
            <a href="/modules/admin/employees.php" class="nav-item<?= $_nav_active('employees') ?>">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                AB IT Team
            </a>
            <?php endif; ?>

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

        <?php endif; /* end support_staff else */ ?>

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
            <?php if ($_layout_expiry_html): ?>
            <?= $_layout_expiry_html ?>
            <?php endif; ?>
            <?php if ($_layout_flash): ?>
            <div class="alert alert-<?= e($_layout_flash['type']) ?>" role="alert" style="margin-bottom:1.25rem">
                <?= e($_layout_flash['message']) ?>
            </div>
            <?php endif; ?>
