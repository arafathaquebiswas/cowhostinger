<?php
require_once __DIR__ . '/includes/role_guard.php';
requireAuth();

// Public users have their own dashboard
if (hasRole(['user'])) {
    redirect('/user_dashboard.php');
}

$page_title = 'Dashboard';
$active_nav = 'dashboard';

require_once __DIR__ . '/includes/layout_header.php';

$user        = currentUser();
$first_name  = explode(' ', $user['name'])[0];
$is_admin    = $user['role'] === 'admin';
$is_accountant = in_array($user['role'], ['admin', 'accountant'], true);
?>

<div class="page-header">
    <div>
        <h2>Welcome back, <?= e($first_name) ?></h2>
        <p class="text-muted text-sm"><?= date('l, d F Y') ?></p>
    </div>
    <?php if ($is_admin): ?>
    <div class="d-flex gap-1">
        <button class="btn btn-secondary btn-sm" id="refreshAlertsBtn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
                <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
            </svg>
            Refresh Alerts
        </button>
        <a href="/modules/admin/settings.php" class="btn btn-secondary btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
            </svg>
            Settings
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- KPI Cards -->
<div class="kpi-grid" id="kpiGrid">
    <a href="/modules/cows/index.php" class="kpi-card" style="--kpi-color:#2D6A4F;--kpi-soft:#D8F3DC">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="14" rx="8" ry="6"/><circle cx="8" cy="9" r="3"/><circle cx="16" cy="9" r="3"/></svg></div>
        <div class="kpi-value" id="kpi_total_cows"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Total Cows</div>
    </a>
    <a href="/modules/cows/index.php?status=active" class="kpi-card" style="--kpi-color:#059669;--kpi-soft:#F0FDF4">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div class="kpi-value" id="kpi_healthy_cows"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Healthy Cows</div>
    </a>
    <a href="/modules/cows/index.php?status=sick" class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
        <div class="kpi-value" id="kpi_sick_cows"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Sick Cows</div>
    </a>
    <a href="/modules/cows/index.php?status=pregnant" class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg></div>
        <div class="kpi-value" id="kpi_pregnant_cows"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Pregnant Cows</div>
    </a>
    <a href="/modules/milk/index.php" class="kpi-card" style="--kpi-color:#0284C7;--kpi-soft:#F0F9FF">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2h8l2 6H6L8 2z"/><path d="M6 8v12a2 2 0 002 2h8a2 2 0 002-2V8"/></svg></div>
        <div class="kpi-value" id="kpi_milk_today"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Today's Milk (L)</div>
    </a>
    <a href="/modules/milk/index.php?view=revenue" class="kpi-card" style="--kpi-color:#D4A017;--kpi-soft:#FFFBEB">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
        <div class="kpi-value" id="kpi_milk_revenue"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Monthly Milk Revenue</div>
    </a>
    <a href="/modules/feed_medicine/index.php?alert=feed" class="kpi-card" style="--kpi-color:#D97706;--kpi-soft:#FFFBEB">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg></div>
        <div class="kpi-value" id="kpi_feed_alerts"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Feed Stock Alerts</div>
    </a>
    <a href="/modules/feed_medicine/index.php?alert=medicine" class="kpi-card" style="--kpi-color:#C2410C;--kpi-soft:#FFF7ED">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
        <div class="kpi-value" id="kpi_med_alerts"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Medicine Stock Alerts</div>
    </a>
    <a href="/modules/equipment/index.php?status=maintenance" class="kpi-card" style="--kpi-color:#6B7280;--kpi-soft:#F3F4F6">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg></div>
        <div class="kpi-value" id="kpi_equip_maint"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Equipment Under Maintenance</div>
    </a>
    <a href="/modules/finance/index.php" class="kpi-card" style="--kpi-color:#059669;--kpi-soft:#F0FDF4">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></div>
        <div class="kpi-value" id="kpi_net_profit"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Net Profit This Month</div>
    </a>
    <a href="/modules/equipment/index.php?status=damaged" class="kpi-card" style="--kpi-color:#DC2626;--kpi-soft:#FEF2F2">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
        <div class="kpi-value" id="kpi_equip_damaged"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Damaged Equipment</div>
    </a>
    <a href="/modules/feed_medicine/index.php?tab=feed" class="kpi-card" style="--kpi-color:#7C3AED;--kpi-soft:#F5F3FF">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg></div>
        <div class="kpi-value" id="kpi_feed_cost"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Feed Cost This Month</div>
    </a>
    <a href="/modules/finance/index.php?category=Equipment+Sale" class="kpi-card" style="--kpi-color:#2563EB;--kpi-soft:#EFF6FF">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></div>
        <div class="kpi-value" id="kpi_equip_sales"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Equipment Sales (Month)</div>
    </a>
    <a href="/modules/finance/index.php" class="kpi-card" style="--kpi-color:#0891B2;--kpi-soft:#ECFEFF">
        <div class="kpi-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
        <div class="kpi-value" id="kpi_prev_profit"><span class="kpi-loader"></span></div>
        <div class="kpi-label">Prev Month Profit</div>
    </a>
</div>

<!-- Charts row -->
<div class="chart-grid">
    <!-- Milk Trend -->
    <div class="card chart-card">
        <div class="card-header">
            <span class="card-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:.35rem"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Milk Production — Last 7 Days
            </span>
            <a href="/modules/milk/index.php" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <div class="chart-wrap">
            <canvas id="milkChart"></canvas>
        </div>
    </div>

    <!-- Finance Overview -->
    <?php if ($is_accountant): ?>
    <div class="card chart-card">
        <div class="card-header">
            <span class="card-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:.35rem"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Finance Overview — Last 6 Months
            </span>
            <a href="/modules/finance/index.php" class="btn btn-secondary btn-sm">View All</a>
        </div>
        <div class="chart-wrap">
            <canvas id="financeChart"></canvas>
        </div>
    </div>
    <?php else: ?>
    <!-- Quick links card for non-finance roles -->
    <div class="card">
        <div class="card-header"><span class="card-title">Quick Actions</span></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:.6rem">
            <a href="/modules/cows/index.php" class="btn btn-outline-primary">View All Cows</a>
            <a href="/modules/milk/index.php" class="btn btn-outline-primary">Record Milk</a>
            <a href="/modules/breeding/index.php" class="btn btn-outline-primary">Breeding Records</a>
            <a href="/modules/alerts/index.php" class="btn btn-outline-primary">View All Alerts</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Alerts -->
<div class="card" style="margin-bottom:1.5rem">
    <div class="dash-alerts-header">
        <span class="dash-alerts-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:.35rem"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            Recent Unread Alerts
            <span id="dash_unread_badge" class="badge badge-red" style="margin-left:.4rem;display:none"></span>
        </span>
        <div class="d-flex gap-1">
            <button class="btn btn-secondary btn-sm" id="markAllReadBtn">Mark All Read</button>
            <a href="/modules/alerts/index.php" class="btn btn-secondary btn-sm">View All</a>
        </div>
    </div>
    <div id="dashAlertsList">
        <div style="padding:2rem;text-align:center;color:#9CA3AF">Loading alerts…</div>
    </div>
</div>

<style>
.kpi-loader {
    display:inline-block;width:20px;height:20px;border:2px solid #E5E0D5;
    border-top-color:#2D6A4F;border-radius:50%;animation:spin .7s linear infinite;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
'use strict';

// ── KPI Cards ──────────────────────────────────────────────────
fetch('/api/get_dashboard_kpis.php')
    .then(r => r.json())
    .then(d => {
        var set = function(id, val) {
            var el = document.getElementById(id);
            if (el) el.textContent = val;
        };
        set('kpi_total_cows',    d.total_cows);
        set('kpi_healthy_cows',  d.healthy_cows);
        set('kpi_sick_cows',     d.sick_cows);
        set('kpi_pregnant_cows', d.pregnant_cows);
        set('kpi_milk_today',    d.milk_today_l + ' L');
        set('kpi_milk_revenue',  '৳ ' + d.milk_revenue);
        set('kpi_feed_alerts',   d.feed_alerts);
        set('kpi_med_alerts',    d.med_alerts);
        set('kpi_equip_maint',    d.equip_maint);
        set('kpi_equip_damaged',  d.damaged_equipment);
        set('kpi_feed_cost',      '৳ ' + d.feed_cost_month);
        set('kpi_equip_sales',    '৳ ' + d.equip_sales_month);

        var profitEl = document.getElementById('kpi_net_profit');
        if (profitEl) {
            profitEl.textContent = '৳ ' + d.net_profit;
            profitEl.style.color = d.net_profit_raw < 0 ? 'var(--danger)' : 'var(--success)';
        }
        var prevEl = document.getElementById('kpi_prev_profit');
        if (prevEl) {
            prevEl.textContent = '৳ ' + d.prev_month_profit;
            prevEl.style.color = d.prev_month_profit_raw < 0 ? 'var(--danger)' : 'var(--success)';
        }
    })
    .catch(function() {
        document.querySelectorAll('[id^="kpi_"]').forEach(function(el) { el.textContent = 'N/A'; });
    });

// ── Milk Chart ─────────────────────────────────────────────────
fetch('/api/get_milk_chart.php')
    .then(r => r.json())
    .then(function(d) {
        var ctx = document.getElementById('milkChart');
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: d.labels,
                datasets: [{
                    label: 'Litres',
                    data:  d.data,
                    borderColor:     '#2D6A4F',
                    backgroundColor: 'rgba(45,106,79,.08)',
                    borderWidth: 2.5,
                    pointBackgroundColor: '#2D6A4F',
                    pointRadius: 4,
                    tension: 0.35,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return c.parsed.y + ' L'; } } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, ticks: { font: { size: 11 }, callback: function(v) { return v + ' L'; } }, grid: { color: 'rgba(0,0,0,.06)' } }
                }
            }
        });
    }).catch(function() {});

// ── Finance Chart (admin/accountant only) ──────────────────────
<?php if ($is_accountant): ?>
fetch('/api/get_finance_chart.php')
    .then(r => r.json())
    .then(function(d) {
        var ctx = document.getElementById('financeChart');
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: d.labels,
                datasets: [
                    { label: 'Income',  data: d.income,  backgroundColor: 'rgba(5,150,105,.75)',  borderRadius: 4 },
                    { label: 'Expense', data: d.expense, backgroundColor: 'rgba(220,38,38,.65)',  borderRadius: 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, usePointStyle: true, padding: 14 } } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, ticks: { font: { size: 11 } }, grid: { color: 'rgba(0,0,0,.06)' } }
                }
            }
        });
    }).catch(function() {});
<?php endif; ?>

// ── Recent Alerts ──────────────────────────────────────────────
function loadDashAlerts() {
    fetch('/api/get_alerts.php?limit=5&filter=unread')
        .then(r => r.json())
        .then(function(d) {
            var list   = document.getElementById('dashAlertsList');
            var badge  = document.getElementById('dash_unread_badge');
            if (!list) return;

            if (badge) {
                badge.textContent = d.unread_count;
                badge.style.display = d.unread_count > 0 ? '' : 'none';
            }

            // Update topbar badge too
            var topDot = document.querySelector('.alert-dot');
            if (topDot) topDot.textContent = d.unread_count > 99 ? '99+' : d.unread_count;

            if (!d.alerts || d.alerts.length === 0) {
                list.innerHTML = '<div style="padding:2rem;text-align:center;color:#9CA3AF">No unread alerts. All clear!</div>';
                return;
            }

            var colors = { critical:'#DC2626', high:'#D97706', medium:'#0284C7', low:'#059669' };
            var html = '';
            d.alerts.forEach(function(a) {
                var color = colors[a.severity] || '#6B7280';
                html += '<div class="alert-item unread ' + a.severity + '" data-id="' + a.id + '">' +
                    '<span class="severity-dot severity-' + a.severity + '" style="margin-top:.35rem"></span>' +
                    '<div class="alert-item-body">' +
                        '<div class="alert-item-msg">' + escHtml(a.message) + '</div>' +
                        '<div class="alert-item-meta">' +
                            '<span class="badge badge-' + severityBadge(a.severity) + '" style="font-size:.68rem;margin-right:.4rem">' + a.severity.toUpperCase() + '</span>' +
                            timeAgo(a.created_at) +
                        '</div>' +
                    '</div>' +
                    '<div class="alert-item-actions">' +
                        '<button class="btn btn-sm btn-secondary mark-read-btn" data-id="' + a.id + '" title="Mark as read">✓</button>' +
                    '</div>' +
                '</div>';
            });
            list.innerHTML = html;

            list.querySelectorAll('.mark-read-btn').forEach(function(btn) {
                btn.addEventListener('click', function() { markRead(parseInt(this.dataset.id)); });
            });
        }).catch(function() {
            document.getElementById('dashAlertsList').innerHTML = '<div style="padding:1.5rem;text-align:center;color:#DC2626">Failed to load alerts.</div>';
        });
}

function markRead(id) {
    fetch('/api/mark_alert_read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
    }).then(function() { loadDashAlerts(); });
}

var markAllBtn = document.getElementById('markAllReadBtn');
if (markAllBtn) {
    markAllBtn.addEventListener('click', function() {
        if (!confirm('Mark all alerts as read?')) return;
        fetch('/api/mark_alert_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ all: true })
        }).then(function() { loadDashAlerts(); });
    });
}

var refreshBtn = document.getElementById('refreshAlertsBtn');
if (refreshBtn) {
    refreshBtn.addEventListener('click', function() {
        refreshBtn.disabled = true;
        refreshBtn.textContent = 'Refreshing…';
        fetch('/api/generate_system_alerts.php')
            .then(r => r.json())
            .then(function(d) {
                loadDashAlerts();
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg> Refresh Alerts';
            }).catch(function() { refreshBtn.disabled = false; refreshBtn.textContent = 'Refresh Alerts'; });
    });
}

function severityBadge(sev) {
    return { critical:'red', high:'yellow', medium:'blue', low:'green' }[sev] || 'gray';
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function timeAgo(dt) {
    var diff = Math.floor((Date.now() - new Date(dt).getTime()) / 1000);
    if (diff < 60)   return 'just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}

loadDashAlerts();
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
