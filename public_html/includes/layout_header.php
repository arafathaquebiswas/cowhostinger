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

// Subscription expiry alert engine — throttled, farm-admin only
require_once __DIR__ . '/expiry_checker.php';

$_nav_active = function (string $key) use ($active_nav): string {
    return (($active_nav ?? '') === $key) ? ' active' : '';
};

$_can = function (array $roles) use ($_layout_role): bool {
    return in_array($_layout_role, $roles, true);
};

$_module_enabled = static fn(string $module): bool => isModuleEnabled($module);

// Accordion open detection — returns 'open' CSS class if current page is in this group
$_active_nav_str = $active_nav ?? '';
$_acc = function (array $keys) use ($_active_nav_str): string {
    return in_array($_active_nav_str, $keys, true) ? ' open' : '';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
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
    /* ── Upgrade button / modal ───────────────────────────────── */
    .btn-upgrade{background:linear-gradient(135deg,#7C3AED,#A855F7);color:#fff;border:none;font-size:.78rem}
    .btn-upgrade:hover{background:linear-gradient(135deg,#6D28D9,#9333EA);color:#fff}
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
    /* ── AB IT watermark ──────────────────────────────────────── */
    .saas-watermark{position:fixed;top:50%;right:-30px;transform:translateY(-50%) rotate(90deg);font-size:4rem;font-weight:900;color:rgba(45,106,79,.05);pointer-events:none;z-index:1;letter-spacing:.1em;user-select:none;white-space:nowrap}
    .abit-stamp{position:fixed;bottom:12px;right:14px;font-size:.65rem;font-weight:700;color:rgba(45,106,79,.4);letter-spacing:.08em;pointer-events:none;z-index:2;user-select:none}
    /* ── Usage meter ──────────────────────────────────────────── */
    .usage-meter{height:6px;background:var(--border);border-radius:3px;overflow:hidden;margin:.25rem 0 .1rem}
    .usage-meter-fill{height:100%;border-radius:3px;background:var(--primary);transition:.3s}
    .usage-meter-fill.warn{background:#D97706}.usage-meter-fill.full{background:#DC2626}
    /* ── Impersonation banner ─────────────────────────────────── */
    .impersonation-banner{background:#7C3AED;color:#fff;padding:.45rem 1.25rem;font-size:.8rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .impersonation-banner a{color:#E9D5FF;font-weight:600;text-decoration:underline}

    /* ══════════════════════════════════════════════════════════
       ACCORDION NAV
       ══════════════════════════════════════════════════════════ */
    .nav-acc{margin:.05rem 0}
    .nav-acc-hdr{display:flex;align-items:center;gap:.5rem;width:100%;padding:.42rem .75rem;background:none;border:none;text-align:left;cursor:pointer;color:rgba(255,255,255,.48);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;border-radius:6px;transition:.15s;line-height:1.4}
    .nav-acc-hdr:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.78)}
    .nav-acc.open>.nav-acc-hdr{color:rgba(255,255,255,.85);background:rgba(255,255,255,.05)}
    .nav-acc-hdr svg{flex-shrink:0;opacity:.7}
    .nav-acc-chv{margin-left:auto;font-size:.65rem;transition:transform .22s;opacity:.6}
    .nav-acc.open>.nav-acc-hdr .nav-acc-chv{transform:rotate(90deg);opacity:1}
    .nav-acc-body{display:none;padding:.1rem 0 .25rem}
    .nav-acc.open>.nav-acc-body{display:block}
    /* sub-items inside accordion */
    .nav-acc-body .nav-item{padding-left:1.9rem;font-size:.83rem}
    /* dashboard pinned link */
    .nav-pin{margin-bottom:.3rem}

    /* ══════════════════════════════════════════════════════════
       QUICK ACTION FAB
       ══════════════════════════════════════════════════════════ */
    .qaf{padding:.25rem .75rem .4rem;border-top:1px solid rgba(255,255,255,.1);display:flex;flex-direction:column;gap:.1rem}
    .qaf-trigger{width:100%;border-radius:8px;background:rgba(255,255,255,.12);color:rgba(255,255,255,.85);border:none;font-size:.83rem;font-weight:700;cursor:pointer;padding:.42rem .75rem;transition:.18s;display:flex;align-items:center;gap:.5rem;line-height:1}
    .qaf-trigger:hover{background:rgba(255,255,255,.2);color:#fff}
    .qaf-menu{display:none;flex-direction:column;gap:.05rem;padding:.1rem 0}
    .qaf-panel-hdr,.qaf-cust-hdr{display:none;align-items:center;gap:.4rem;padding:.55rem .75rem .3rem}
    .qaf-hdr-title{font-size:.8rem;font-weight:700;color:rgba(255,255,255,.9);letter-spacing:.03em}
    .qaf-hdr-btn{background:rgba(255,255,255,.1);border:none;color:rgba(255,255,255,.65);border-radius:5px;padding:.22rem .38rem;cursor:pointer;display:flex;align-items:center;font-size:.72rem;line-height:1;transition:.15s}
    .qaf-hdr-btn:hover{background:rgba(255,255,255,.22);color:#fff}
    .qaf-hdr-done{background:rgba(45,106,79,.55);border:none;color:#fff;border-radius:5px;padding:.22rem .55rem;cursor:pointer;font-size:.72rem;font-weight:700;transition:.15s}
    .qaf-hdr-done:hover{background:rgba(45,106,79,.85)}
    .qaf-item{display:flex;align-items:center;gap:.45rem;padding:.36rem .65rem;border-radius:6px;text-decoration:none;color:rgba(255,255,255,.72);font-size:.8rem;font-weight:500;transition:.15s;white-space:nowrap}
    .qaf-item:hover{background:rgba(255,255,255,.1);color:#fff;text-decoration:none}
    .qaf-ico{font-size:.88rem;line-height:1;flex-shrink:0}
    /* Open state — sidebar nav hides, QAF fills panel */
    .sidebar.qaf-open .sidebar-nav{display:none !important}
    .sidebar.qaf-open .qaf{flex:1;overflow-y:auto;padding:.25rem 0 .4rem;border-top:1px solid rgba(255,255,255,.1)}
    .sidebar.qaf-open .qaf-trigger{display:none}
    .sidebar.qaf-open .qaf-panel-hdr{display:flex}
    .sidebar.qaf-open .qaf-menu{display:flex;padding:.15rem .75rem;flex:1}
    /* Customize mode */
    .qaf.customizing .qaf-panel-hdr{display:none}
    .qaf.customizing .qaf-cust-hdr{display:flex}
    .qaf-item .qaf-chk{width:15px;height:15px;border-radius:3px;border:1.5px solid rgba(255,255,255,.35);flex-shrink:0;display:none;align-items:center;justify-content:center;font-size:.6rem;transition:.15s}
    .qaf.customizing .qaf-item .qaf-chk{display:flex}
    .qaf.customizing .qaf-item{opacity:.5;cursor:pointer !important;pointer-events:auto !important}
    .qaf.customizing .qaf-item.qaf-on{opacity:1}
    .qaf.customizing .qaf-item.qaf-on .qaf-chk{background:#2D6A4F;border-color:#2D6A4F;color:#fff}
    </style>
    <script>
    // Accordion toggle with localStorage persistence
    function toggleAcc(id) {
        var el = document.getElementById(id);
        if (!el) return;
        var opening = !el.classList.contains('open');
        el.classList.toggle('open', opening);
        try {
            var s = JSON.parse(localStorage.getItem('_farm_nav') || '{}');
            s[id] = opening;
            localStorage.setItem('_farm_nav', JSON.stringify(s));
        } catch(e){}
    }
    // On load: restore any additionally-opened sections from localStorage
    // (Active-page section is already open via PHP class)
    document.addEventListener('DOMContentLoaded', function() {
        try {
            var s = JSON.parse(localStorage.getItem('_farm_nav') || '{}');
            Object.keys(s).forEach(function(id) {
                if (s[id]) {
                    var el = document.getElementById(id);
                    if (el) el.classList.add('open');
                }
            });
        } catch(e){}
    });
    // ── Quick Actions ─────────────────────────────────────────
    var _qafBak = null;

    function toggleQAF() {
        var sb  = document.getElementById('sidebar');
        var qaf = document.getElementById('qaf');
        var opening = !sb.classList.contains('qaf-open');
        if (opening) {
            sb.classList.add('qaf-open');
        } else {
            sb.classList.remove('qaf-open');
            qaf.classList.remove('customizing');
            _qafBak = null;
        }
    }

    document.addEventListener('click', function(e) {
        var sb = document.getElementById('sidebar');
        if (sb && sb.classList.contains('qaf-open') && !sb.contains(e.target)) {
            sb.classList.remove('qaf-open');
            document.getElementById('qaf').classList.remove('customizing');
            _qafBak = null;
        }
    });

    function _qafGetHidden() {
        try { return JSON.parse(localStorage.getItem('qaf_hidden') || '[]'); } catch(e) { return []; }
    }

    function qafApplyPrefs() {
        var hidden = _qafGetHidden();
        document.querySelectorAll('.qaf-item[data-qaf-id]').forEach(function(el) {
            el.style.display = hidden.indexOf(el.dataset.qafId) !== -1 ? 'none' : '';
        });
    }

    function qafStartCustomize() {
        var hidden = _qafGetHidden();
        _qafBak = hidden.slice();
        document.querySelectorAll('.qaf-item[data-qaf-id]').forEach(function(el) {
            el.style.display = '';
            el.classList.toggle('qaf-on', hidden.indexOf(el.dataset.qafId) === -1);
        });
        document.getElementById('qaf').classList.add('customizing');
    }

    function _qafItemClick(e) {
        e.preventDefault();
        this.classList.toggle('qaf-on');
    }

    document.addEventListener('click', function(e) {
        var qaf = document.getElementById('qaf');
        if (qaf && qaf.classList.contains('customizing')) {
            var item = e.target.closest('.qaf-item[data-qaf-id]');
            if (item) { e.preventDefault(); item.classList.toggle('qaf-on'); }
        }
    }, true);

    function qafSave() {
        var hidden = [];
        document.querySelectorAll('.qaf-item[data-qaf-id]').forEach(function(el) {
            if (!el.classList.contains('qaf-on')) hidden.push(el.dataset.qafId);
        });
        localStorage.setItem('qaf_hidden', JSON.stringify(hidden));
        document.getElementById('qaf').classList.remove('customizing');
        _qafBak = null;
        qafApplyPrefs();
    }

    function qafCancel() {
        document.getElementById('qaf').classList.remove('customizing');
        _qafBak = null;
        qafApplyPrefs();
    }

    qafApplyPrefs();
    </script>
</head>
<body data-nav="<?= e($active_nav ?? '') ?>">
<div class="saas-watermark">AB IT</div>
<div class="abit-stamp">AB IT</div>

<?php if (isImpersonating()): ?>
<div class="impersonation-banner">
    <span>🔍 Impersonating: <strong><?= e($_layout_farm_name) ?></strong> — viewing as CEO</span>
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
            <div class="upgrade-plan-card selected"><div class="upgrade-plan-name">Basic</div><div class="upgrade-plan-price">৳499/mo</div></div>
            <div class="upgrade-plan-card"><div class="upgrade-plan-name">Pro</div><div class="upgrade-plan-price">৳999/mo</div></div>
            <div class="upgrade-plan-card"><div class="upgrade-plan-name">Enterprise</div><div class="upgrade-plan-price">৳2499/mo</div></div>
        </div>
        <a href="/modules/subscription/index.php" class="btn btn-upgrade btn-block">
            View Plans &amp; Upgrade
        </a>
        <p style="font-size:.75rem;color:#9CA3AF;margin:.75rem 0 0">Contact AB IT: <strong>support@abit.com.bd</strong></p>
    </div>
</div>

<div class="layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <aside class="sidebar" id="sidebar">
        <!-- Collapse toggle (desktop only) -->
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse sidebar" aria-label="Collapse sidebar">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <a href="<?= isSaasUser() ? '/modules/super_admin/dashboard.php' : '/dashboard.php' ?>" class="sidebar-brand">
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
            <!-- ── Support Staff ───────────────────────────────── -->
            <span class="nav-section-label">Support</span>
            <a href="/modules/support/dashboard.php" class="nav-item<?= $_nav_active('support_dashboard') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>
            <a href="/modules/support/index.php" class="nav-item<?= $_nav_active('support') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                Tickets
                <?php $_supp_q=getDB()->prepare("SELECT COUNT(*) FROM support_tickets WHERE assigned_to=? AND status IN ('open','in_progress')"); $_supp_q->execute([$_layout_user['id']]); $_supp_open=(int)($_supp_q->fetchColumn()??0); if($_supp_open>0): ?>
                <span class="nav-badge"><?= $_supp_open ?></span>
                <?php endif; ?>
            </a>
            <a href="/modules/support/dashboard.php#farm-search" class="nav-item">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Farm Search
            </a>

        <?php else: ?>

            <!-- ── Dashboard (always pinned) ──────────────────── -->
            <a href="/dashboard.php" class="nav-item nav-pin<?= $_nav_active('dashboard') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>
            <?php if ($_can(['admin','manager','accountant'])): ?>
            <a href="/modules/executive/dashboard.php" class="nav-item nav-pin<?= $_nav_active('executive_dashboard') ?>" style="background:<?= $_nav_active('executive_dashboard') ? '' : 'rgba(255,215,0,.07)' ?>;border:1px solid rgba(255,215,0,.15)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>
                <span style="color:#fbbf24;font-weight:700">Executive Dashboard</span>
            </a>
            <?php endif; ?>
            <?php if ($_module_enabled('workers') && $_can(['worker'])): ?>
            <a href="/modules/workers/my_tasks.php" class="nav-item nav-pin<?= $_nav_active('my_tasks') ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                My Tasks
            </a>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 🐄  COWS
                 ══════════════════════════════════════════════════ -->
            <?php if ($_module_enabled('cows')): ?>
            <div class="nav-acc<?= $_acc(['cows','breeding']) ?>" id="nacc-cows">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-cows')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="14" rx="8" ry="6"/><circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/></svg>
                    Cows
                    <span class="nav-acc-chv">›</span>
                </button>
                <div class="nav-acc-body">
                    <a href="/modules/cows/index.php" class="nav-item<?= $_nav_active('cows') ?>">All Cows</a>
                    <?php if ($_can(['admin','manager'])): ?>
                    <a href="/modules/cows/form.php" class="nav-item<?= $_nav_active('cow_form') ?>">Add Cow</a>
                    <a href="/modules/cows/purchases.php" class="nav-item<?= $_nav_active('cow_purchases') ?>">Purchases</a>
                    <?php endif; ?>
                    <?php if ($_can(['admin','manager','veterinarian'])): ?>
                    <a href="/modules/cows/death_record.php" class="nav-item<?= $_nav_active('cow_deaths') ?>">Deaths</a>
                    <?php endif; ?>
                    <?php if ($_module_enabled('breeding') && $_can(['admin','manager','veterinarian'])): ?>
                    <a href="/modules/breeding/index.php" class="nav-item<?= $_nav_active('breeding') ?>">Breeding</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 🥛  MILK
                 ══════════════════════════════════════════════════ -->
            <?php if ($_module_enabled('milk') && $_can(['admin','manager','milkman','worker','veterinarian','accountant'])): ?>
            <div class="nav-acc<?= $_acc(['milk','milk_analytics','milk_pricing']) ?>" id="nacc-milk">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-milk')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2h8l2 6H6L8 2z"/><path d="M6 8v12a2 2 0 002 2h8a2 2 0 002-2V8"/></svg>
                    Milk
                    <span class="nav-acc-chv">›</span>
                </button>
                <div class="nav-acc-body">
                    <?php if ($_can(['admin','manager','milkman','worker','veterinarian'])): ?>
                    <a href="/modules/milk/record.php" class="nav-item">Add Milk Entry</a>
                    <a href="/modules/milk/index.php" class="nav-item<?= $_nav_active('milk') ?>">Milk Records</a>
                    <?php endif; ?>
                    <?php if ($_can(['admin','manager','accountant'])): ?>
                    <a href="/modules/milk/pricing.php" class="nav-item<?= $_nav_active('milk_pricing') ?>">Pricing</a>
                    <a href="/modules/milk/analytics.php" class="nav-item<?= $_nav_active('milk_analytics') ?>">Analytics</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 🌾  FEED
                 ══════════════════════════════════════════════════ -->
            <?php if ($_module_enabled('feed_medicine') && $_can(['admin','manager','milkman','worker','veterinarian','accountant'])): ?>
            <div class="nav-acc<?= $_acc(['feed_medicine']) ?>" id="nacc-feed">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-feed')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                    Feed
                    <span class="nav-acc-chv">›</span>
                </button>
                <div class="nav-acc-body">
                    <a href="/modules/feed_medicine/feed_log.php" class="nav-item<?= $_nav_active('feed_log') ?>">Add Feed Entry</a>
                    <a href="/modules/feed_medicine/index.php" class="nav-item<?= $_nav_active('feed_medicine') ?>">Feed &amp; Medicine Stock</a>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 💉  HEALTH
                 ══════════════════════════════════════════════════ -->
            <?php if ($_can(['admin','manager','milkman','veterinarian','worker'])): ?>
            <div class="nav-acc<?= $_acc(['diagnosis','treatments','veterinarians']) ?>" id="nacc-health">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-health')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                    Health &amp; Treatment
                    <span class="nav-acc-chv">›</span>
                </button>
                <div class="nav-acc-body">
                    <?php if ($_module_enabled('cows') && $_can(['admin','manager','milkman','veterinarian','worker'])): ?>
                    <a href="/modules/treatments/index.php" class="nav-item<?= $_nav_active('treatments') ?>">Treatments</a>
                    <?php endif; ?>
                    <?php if ($_module_enabled('diagnosis') && $_can(['admin','manager','veterinarian'])): ?>
                    <a href="/modules/diagnosis/index.php" class="nav-item<?= $_nav_active('diagnosis') ?>">Diagnosis</a>
                    <?php endif; ?>
                    <?php if ($_can(['admin','manager','accountant'])): ?>
                    <a href="/modules/treatments/veterinarians.php" class="nav-item<?= $_nav_active('veterinarians') ?>">Veterinarians</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 💰  BUSINESS  (Sales, Buyers, Sellers)
                 ══════════════════════════════════════════════════ -->
            <?php if ($_module_enabled('sales') && $_can(['admin','manager','accountant'])): ?>
            <div class="nav-acc<?= $_acc(['sales','buyers','sellers']) ?>" id="nacc-biz">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-biz')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                    Business
                    <span class="nav-acc-chv">›</span>
                </button>
                <div class="nav-acc-body">
                    <a href="/modules/sales/index.php" class="nav-item<?= $_nav_active('sales') ?>">Sales</a>
                    <?php if ($_can(['admin','manager','accountant'])): ?>
                    <a href="/modules/sales/buyers.php" class="nav-item<?= $_nav_active('buyers') ?>">Buyers</a>
                    <a href="/modules/sales/sellers.php" class="nav-item<?= $_nav_active('sellers') ?>">Sellers</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 👷  WORKERS
                 ══════════════════════════════════════════════════ -->
            <?php if ($_module_enabled('workers') && $_can(['admin','manager','accountant'])): ?>
            <div class="nav-acc<?= $_acc(['workers','my_tasks','payroll']) ?>" id="nacc-workers">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-workers')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                    Workers
                    <span class="nav-acc-chv">›</span>
                </button>
                <div class="nav-acc-body">
                    <?php if ($_can(['admin','manager'])): ?>
                    <a href="/modules/workers/index.php" class="nav-item<?= $_nav_active('workers') ?>">Worker List</a>
                    <a href="/modules/workers/tasks.php" class="nav-item<?= $_nav_active('worker_tasks') ?>">Tasks</a>
                    <?php endif; ?>
                    <?php if ($_can(['admin','manager','accountant'])): ?>
                    <a href="/modules/payroll/index.php" class="nav-item<?= $_nav_active('payroll') ?>">Payroll</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 📊  FINANCE & REPORTS
                 ══════════════════════════════════════════════════ -->
            <?php if ($_can(['admin','manager','accountant'])): ?>
            <div class="nav-acc<?= $_acc(['finance','finance_summary','finance_charts','reports','profit_engine','profit_monthly','profit_yearly','profit_compare','profitability']) ?>" id="nacc-finance">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-finance')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    Finance &amp; Reports
                    <span class="nav-acc-chv">›</span>
                </button>
                <div class="nav-acc-body">
                    <?php if ($_module_enabled('finance') && canAccess('finance.view')): ?>
                    <a href="/modules/finance/summary.php" class="nav-item<?= $_nav_active('finance_summary') ?>">Financial Summary</a>
                    <a href="/modules/finance/profit.php" class="nav-item<?= $_nav_active('profit_engine') ?>">Financial Overview</a>
                    <a href="/modules/finance/profit.php?tab=compare&amp;preset=monthly" class="nav-item<?= $_nav_active('profit_monthly') ?>">Monthly Comparison</a>
                    <a href="/modules/finance/profit.php?tab=compare&amp;preset=yearly"  class="nav-item<?= $_nav_active('profit_yearly') ?>">Yearly Comparison</a>
                    <a href="/modules/finance/profit.php?tab=compare" class="nav-item<?= $_nav_active('profit_compare') ?>">Custom Comparison</a>
                    <a href="/modules/finance/index.php" class="nav-item<?= $_nav_active('finance') ?>">Finance Ledger</a>
                    <a href="/modules/finance/charts.php" class="nav-item<?= $_nav_active('finance_charts') ?>">Finance Charts</a>
                    <?php else: ?>
                    <?= lockedNavItem('Finance', '', 'Finance module') ?>
                    <?php endif; ?>
                    <?php if ($_module_enabled('reports') && canAccess('report.view')): ?>
                    <a href="/modules/reports/index.php" class="nav-item<?= $_nav_active('reports') ?>">Reports</a>
                    <a href="/modules/reports/profitability.php" class="nav-item<?= $_nav_active('profitability') ?>">Profitability</a>
                    <?php else: ?>
                    <?= lockedNavItem('Reports', '', 'Reports & Exports') ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 ⚙️  OPERATIONS  (Equipment, Maintenance)
                 ══════════════════════════════════════════════════ -->
            <?php if ($_module_enabled('equipment') || $_module_enabled('maintenance')): ?>
            <div class="nav-acc<?= $_acc(['equipment','maintenance']) ?>" id="nacc-ops">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-ops')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
                    Operations
                    <span class="nav-acc-chv">›</span>
                </button>
                <div class="nav-acc-body">
                    <?php if ($_module_enabled('equipment')): ?>
                    <a href="/modules/equipment/index.php" class="nav-item<?= $_nav_active('equipment') ?>">Equipment</a>
                    <?php endif; ?>
                    <?php if ($_module_enabled('maintenance')): ?>
                    <a href="/modules/maintenance/index.php" class="nav-item<?= $_nav_active('maintenance') ?>">Maintenance</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════
                 🔔  SYSTEM
                 ══════════════════════════════════════════════════ -->
            <div class="nav-acc<?= $_acc(['alerts','support','subscription','admin_users','admin_settings','audit_log']) ?>" id="nacc-sys">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-sys')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                    System
                    <?php if ($_layout_alert_count > 0): ?>
                    <span class="nav-badge" style="margin-left:auto;margin-right:.4rem"><?= $_layout_alert_count > 99 ? '99+' : $_layout_alert_count ?></span>
                    <?php endif; ?>
                    <span class="nav-acc-chv" style="<?= $_layout_alert_count > 0 ? 'margin-left:0' : '' ?>">›</span>
                </button>
                <div class="nav-acc-body">
                    <a href="/modules/alerts/index.php" class="nav-item<?= $_nav_active('alerts') ?>">
                        Alerts
                        <?php if ($_layout_alert_count > 0): ?>
                        <span class="nav-badge"><?= $_layout_alert_count > 99 ? '99+' : $_layout_alert_count ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if ($_can(['admin','manager','accountant','veterinarian'])): ?>
                    <a href="/modules/support/index.php" class="nav-item<?= $_nav_active('support') ?>">Support</a>
                    <?php endif; ?>
                    <?php if (!isSaasUser()): ?>
                    <a href="/modules/subscription/index.php" class="nav-item<?= $_nav_active('subscription') ?>">
                        Subscription <?= farmPlanBadge() ?>
                    </a>
                    <a href="/pricing.php" class="nav-item<?= $_nav_active('pricing') ?>">View Plans</a>
                    <?php endif; ?>
                    <?php if ($_can(['admin'])): ?>
                    <a href="/modules/admin/users.php"     class="nav-item<?= $_nav_active('admin_users') ?>">Users</a>
                    <a href="/modules/admin/settings.php"  class="nav-item<?= $_nav_active('admin_settings') ?>">Module Settings</a>
                    <a href="/modules/admin/audit_log.php" class="nav-item<?= $_nav_active('audit_log') ?>">Audit Log</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════
                 👑  SUPER ADMIN
                 ══════════════════════════════════════════════════ -->
            <?php if ($_layout_role === 'superadmin'): ?>
            <?php $_pending_payments=(int)(getDB()->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn()??0); ?>
            <div class="nav-acc<?= $_acc(['ceo_control','ceo_sub_mgr','ceo_audit','ceo_plans','ceo_dashboard','super_admin','revenue','payments','employees']) ?>" id="nacc-sadmin">
                <button class="nav-acc-hdr" onclick="toggleAcc('nacc-sadmin')" style="color:rgba(200,170,255,.75)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Super Admin
                    <?php if ($_pending_payments > 0): ?>
                    <span class="nav-badge" style="background:#f97316;margin-left:auto;margin-right:.4rem"><?= min($_pending_payments,99) ?></span>
                    <?php endif; ?>
                    <span class="nav-acc-chv" style="<?= $_pending_payments > 0 ? 'margin-left:0' : '' ?>">›</span>
                </button>
                <div class="nav-acc-body">
                    <div style="padding:.35rem .9rem .1rem;font-size:.68rem;font-weight:700;color:rgba(167,139,250,.8);text-transform:uppercase;letter-spacing:.08em">CEO Control Center</div>
                    <a href="/modules/ceo/index.php"         class="nav-item<?= $_nav_active('ceo_control') ?>" style="padding-left:1.4rem">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.7"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        Control Center
                    </a>
                    <a href="/modules/ceo/subscriptions.php" class="nav-item<?= $_nav_active('ceo_sub_mgr') ?>" style="padding-left:1.4rem">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.7"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        Subscription Manager
                    </a>
                    <a href="/modules/ceo/subscriptions.php?status=lifetime" class="nav-item<?= ($_nav_active('ceo_sub_mgr') && ($_GET['status']??'')==='lifetime') ? ' active' : '' ?>" style="padding-left:1.4rem">
                        <span style="font-size:.75rem;opacity:.7">♾</span> Lifetime Members
                    </a>
                    <a href="/modules/ceo/plans.php"         class="nav-item<?= $_nav_active('ceo_plans') ?>" style="padding-left:1.4rem">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.7"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                        Plans &amp; Pricing
                    </a>
                    <a href="/modules/ceo/audit.php"         class="nav-item<?= $_nav_active('ceo_audit') ?>" style="padding-left:1.4rem">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.7"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        CEO Audit Logs
                    </a>
                    <a href="/modules/ceo/coupons.php"       class="nav-item<?= $_nav_active('ceo_coupons') ?>" style="padding-left:1.4rem">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:.7"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        Coupons
                    </a>
                    <div style="padding:.35rem .9rem .1rem;margin-top:.25rem;font-size:.68rem;font-weight:700;color:rgba(167,139,250,.8);text-transform:uppercase;letter-spacing:.08em">System</div>
                    <a href="/modules/super_admin/dashboard.php" class="nav-item<?= $_nav_active('ceo_dashboard') ?>" style="padding-left:1.4rem">CEO Dashboard</a>
                    <a href="/modules/super_admin/index.php"     class="nav-item<?= $_nav_active('super_admin') ?>" style="padding-left:1.4rem">All Farms</a>
                    <a href="/modules/super_admin/revenue.php"   class="nav-item<?= $_nav_active('revenue') ?>" style="padding-left:1.4rem">Revenue</a>
                    <a href="/modules/super_admin/payments.php"  class="nav-item<?= $_nav_active('payments') ?>" style="padding-left:1.4rem">
                        Payments
                        <?php if ($_pending_payments > 0): ?><span class="nav-badge"><?= min($_pending_payments,99) ?></span><?php endif; ?>
                    </a>
                    <a href="/modules/support/index.php"    class="nav-item<?= $_nav_active('support') ?>" style="padding-left:1.4rem">Tickets</a>
                    <a href="/modules/admin/employees.php"  class="nav-item<?= $_nav_active('employees') ?>" style="padding-left:1.4rem">AB IT Team</a>
                </div>
            </div>
            <?php endif; ?>

        <?php endif; /* end support_staff else */ ?>

        </nav>

        <!-- Quick Actions -->
        <div class="qaf" id="qaf">
            <!-- Normal panel header (shown when open) -->
            <div class="qaf-panel-hdr">
                <span class="qaf-hdr-title">⚡ Quick Actions</span>
                <button class="qaf-hdr-btn" onclick="qafStartCustomize()" title="Customize" style="margin-left:auto">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                </button>
                <button class="qaf-hdr-btn" onclick="toggleQAF()" title="Close">✕</button>
            </div>
            <!-- Customize header -->
            <div class="qaf-cust-hdr">
                <span class="qaf-hdr-title">✏ Customize</span>
                <button class="qaf-hdr-done" onclick="qafSave()" style="margin-left:auto">Done</button>
                <button class="qaf-hdr-btn" onclick="qafCancel()">Cancel</button>
            </div>
            <!-- Action items -->
            <div class="qaf-menu">
                <?php if ($_can(['superadmin'])): ?>
                <a href="/modules/ceo/subscriptions.php" class="qaf-item" data-qaf-id="ceo_subs"><span class="qaf-chk">✓</span><span class="qaf-ico">📋</span> Subscriptions</a>
                <a href="/modules/ceo/subscriptions.php?status=expired" class="qaf-item" data-qaf-id="ceo_expired"><span class="qaf-chk">✓</span><span class="qaf-ico">⛔</span> Expired Farms</a>
                <a href="/modules/super_admin/payments.php" class="qaf-item" data-qaf-id="ceo_payments"><span class="qaf-chk">✓</span><span class="qaf-ico">💳</span> Pending Payments</a>
                <a href="/modules/ceo/plans.php" class="qaf-item" data-qaf-id="ceo_plans"><span class="qaf-chk">✓</span><span class="qaf-ico">💲</span> Plans & Pricing</a>
                <a href="/modules/ceo/coupons.php" class="qaf-item" data-qaf-id="ceo_coupons"><span class="qaf-chk">✓</span><span class="qaf-ico">🏷</span> Coupons</a>
                <a href="/modules/super_admin/index.php" class="qaf-item" data-qaf-id="ceo_farms"><span class="qaf-chk">✓</span><span class="qaf-ico">🏘</span> All Farms</a>
                <a href="/modules/ceo/audit.php" class="qaf-item" data-qaf-id="ceo_audit"><span class="qaf-chk">✓</span><span class="qaf-ico">📜</span> Audit Log</a>
                <a href="/modules/support/index.php" class="qaf-item" data-qaf-id="ceo_support"><span class="qaf-chk">✓</span><span class="qaf-ico">🎫</span> Support Tickets</a>
                <?php endif; ?>
                <?php if ($_can(['support'])): ?>
                <a href="/modules/support/index.php" class="qaf-item" data-qaf-id="support_tickets"><span class="qaf-chk">✓</span><span class="qaf-ico">🎫</span> Open Tickets</a>
                <a href="/modules/super_admin/index.php" class="qaf-item" data-qaf-id="support_farms"><span class="qaf-chk">✓</span><span class="qaf-ico">🏘</span> Farm List</a>
                <?php endif; ?>
                <?php if ($_can(['admin','milkman','worker','veterinarian','manager']) && $_module_enabled('milk')): ?>
                <a href="/modules/milk/record.php" class="qaf-item" data-qaf-id="milk"><span class="qaf-chk">✓</span><span class="qaf-ico">🥛</span> Add Milk</a>
                <?php endif; ?>
                <?php if ($_can(['admin','milkman','worker','accountant','manager']) && $_module_enabled('feed_medicine')): ?>
                <a href="/modules/feed_medicine/feed_log.php" class="qaf-item" data-qaf-id="feed"><span class="qaf-chk">✓</span><span class="qaf-ico">🌾</span> Add Feed</a>
                <?php endif; ?>
                <?php if ($_can(['admin','manager','veterinarian','worker'])): ?>
                <a href="/modules/treatments/form.php" class="qaf-item" data-qaf-id="treatment"><span class="qaf-chk">✓</span><span class="qaf-ico">💊</span> Add Treatment</a>
                <?php endif; ?>
                <?php if ($_can(['admin','manager']) && $_module_enabled('cows')): ?>
                <a href="/modules/cows/form.php" class="qaf-item" data-qaf-id="cow"><span class="qaf-chk">✓</span><span class="qaf-ico">🐄</span> Add Cow</a>
                <?php endif; ?>
                <?php if ($_can(['admin','accountant','manager']) && $_module_enabled('sales')): ?>
                <a href="/modules/sales/buyers.php" class="qaf-item" data-qaf-id="buyer"><span class="qaf-chk">✓</span><span class="qaf-ico">🛒</span> Add Buyer</a>
                <?php endif; ?>
                <?php if ($_can(['admin','accountant','manager']) && $_module_enabled('finance')): ?>
                <a href="/modules/finance/summary.php" class="qaf-item" data-qaf-id="fin_summary"><span class="qaf-chk">✓</span><span class="qaf-ico">💰</span> Financial Summary</a>
                <?php endif; ?>
                <?php if ($_can(['worker'])): ?>
                <a href="/modules/workers/my_tasks.php" class="qaf-item" data-qaf-id="tasks"><span class="qaf-chk">✓</span><span class="qaf-ico">✅</span> My Tasks</a>
                <?php endif; ?>
            </div>
            <button class="qaf-trigger" onclick="toggleQAF()" title="Quick actions" aria-label="Quick actions">
                <span style="font-size:1rem;line-height:1">+</span> Quick Actions
            </button>
        </div>

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

    <!-- ══════════════════════════════════════════════════════════
         📱 BOTTOM NAVIGATION — Mobile ≤600px only
         ══════════════════════════════════════════════════════════ -->
    <nav class="bottom-nav" id="bottomNav" aria-label="Mobile navigation">
        <?php
        $bn_home = isSaasUser() ? '/modules/super_admin/dashboard.php' : (hasRole(['worker']) ? '/modules/workers/my_tasks.php' : '/dashboard.php');
        ?>
        <a href="<?= e($bn_home) ?>" class="bottom-nav-item" data-page="dashboard,my_tasks,ceo_dashboard">
            <span class="bn-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </span>
            <span class="bn-label"><?= hasRole(['worker']) ? 'Tasks' : 'Home' ?></span>
        </a>

        <?php if (!isSaasUser() && $_module_enabled('cows')): ?>
        <a href="/modules/cows/index.php" class="bottom-nav-item" data-page="cows,cow_form,cow_view">
            <span class="bn-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="13" rx="8" ry="6"/><path d="M8 13c0-2.5 1-5 4-5s4 2.5 4 5"/><circle cx="9" cy="10" r="1" fill="currentColor"/><circle cx="15" cy="10" r="1" fill="currentColor"/><path d="M7 7c-1-2-1-4 1-4M17 7c1-2 1-4-1-4"/></svg>
            </span>
            <span class="bn-label">Cows</span>
        </a>
        <?php elseif (isSaasUser()): ?>
        <a href="/modules/super_admin/index.php" class="bottom-nav-item" data-page="super_admin">
            <span class="bn-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            </span>
            <span class="bn-label">Farms</span>
        </a>
        <?php endif; ?>

        <?php if ($_can(['admin','manager','milkman','worker','veterinarian']) && $_module_enabled('milk')): ?>
        <a href="/modules/milk/index.php" class="bottom-nav-item" data-page="milk,milk_analytics">
            <span class="bn-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2h8l2 6H6L8 2z"/><path d="M6 8v12a2 2 0 002 2h8a2 2 0 002-2V8"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
            </span>
            <span class="bn-label">Milk</span>
        </a>
        <?php elseif (isSaasUser()): ?>
        <a href="/modules/super_admin/revenue.php" class="bottom-nav-item" data-page="revenue">
            <span class="bn-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            </span>
            <span class="bn-label">Revenue</span>
        </a>
        <?php endif; ?>

        <?php if ($_can(['admin','manager','accountant'])): ?>
        <a href="/modules/finance/index.php" class="bottom-nav-item" data-page="finance,profit_engine">
            <span class="bn-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            </span>
            <span class="bn-label">Finance</span>
        </a>
        <?php elseif (isSaasUser()): ?>
        <a href="/modules/support/index.php" class="bottom-nav-item" data-page="support">
            <span class="bn-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </span>
            <span class="bn-label">Support</span>
        </a>
        <?php endif; ?>

        <button class="bottom-nav-item" id="bnMenuBtn" onclick="document.getElementById('menuToggle').click()" aria-label="Open menu">
            <span class="bn-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </span>
            <span class="bn-label">Menu</span>
        </button>
    </nav>

    <div class="main-content" id="mainContent">
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
