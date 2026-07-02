        </main>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ── Sidebar open/close (mobile + tablet) ───────────────────── */
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggle  = document.getElementById('menuToggle');
    if (!sidebar || !toggle) return;

    function openSidebar()  {
        sidebar.classList.add('open');
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        document.body.style.overflow = '';
    }

    toggle.addEventListener('click', openSidebar);
    overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeSidebar(); closeUpgradeModal(); }
    });

    /* ── Sidebar collapse (desktop icon-mode) ───────────────────── */
    var collapseBtn  = document.getElementById('sidebarCollapseBtn');
    var mainContent  = document.getElementById('mainContent');
    var COLLAPSE_KEY = 'sidebar_collapsed';

    if (collapseBtn) {
        // Restore previous state
        if (localStorage.getItem(COLLAPSE_KEY) === '1') {
            sidebar.classList.add('collapsed');
            if (mainContent) mainContent.classList.add('sidebar-collapsed');
        }
        collapseBtn.addEventListener('click', function () {
            var isCollapsed = sidebar.classList.toggle('collapsed');
            if (mainContent) mainContent.classList.toggle('sidebar-collapsed', isCollapsed);
            localStorage.setItem(COLLAPSE_KEY, isCollapsed ? '1' : '0');
        });
    }

    /* ── Bottom nav active state ────────────────────────────────── */
    var bnItems = document.querySelectorAll('#bottomNav .bottom-nav-item[data-page]');
    var currentNav = (document.body.dataset.nav || '').trim();
    bnItems.forEach(function (item) {
        var pages = (item.dataset.page || '').split(',').map(function(p){ return p.trim(); });
        if (currentNav && pages.indexOf(currentNav) !== -1) {
            item.classList.add('active');
        }
    });

    /* ── Auto-wrap tables for horizontal scroll on mobile/tablet ── */
    if (window.innerWidth <= 1024) {
        document.querySelectorAll('table').forEach(function (tbl) {
            var parent = tbl.parentElement;
            if (!parent) return;
            var alreadyWrapped = parent.classList.contains('table-responsive') ||
                                 parent.classList.contains('table-wrap');
            if (!alreadyWrapped) {
                var wrap = document.createElement('div');
                wrap.className = 'table-responsive';
                parent.insertBefore(wrap, tbl);
                wrap.appendChild(tbl);
            }
        });
    }
}());

// ── Upgrade modal ──────────────────────────────────────────────────────────────
function showUpgradeModal(msg) {
    var el = document.getElementById('upgradeModalOverlay');
    var msgEl = document.getElementById('upgradeModalMsg');
    if (!el) return;
    if (msg && msgEl) msgEl.innerHTML = msg;
    el.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeUpgradeModal() {
    var el = document.getElementById('upgradeModalOverlay');
    if (el) el.classList.remove('active');
    document.body.style.overflow = '';
}
// Close modal when clicking overlay background
(function(){
    var el = document.getElementById('upgradeModalOverlay');
    if (!el) return;
    el.addEventListener('click', function(e){ if(e.target === el) closeUpgradeModal(); });
}());

// ── Usage meter bars — auto-color fill ────────────────────────────────────────
document.querySelectorAll('.usage-meter-fill').forEach(function(bar){
    var pct = parseFloat(bar.dataset.pct || 0);
    if (pct >= 100) bar.classList.add('full');
    else if (pct >= 80) bar.classList.add('warn');
    bar.style.width = Math.min(pct, 100) + '%';
});
</script>

<?php if (!empty($extra_js)): foreach ($extra_js as $_js_url): ?>
<script src="<?= e($_js_url) ?>"></script>
<?php endforeach; endif; ?>

<?php if (!empty($inline_js)): ?>
<script><?= $inline_js ?></script>
<?php endif; ?>

</body>
</html>
