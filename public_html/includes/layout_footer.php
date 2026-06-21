        </main>
    </div>
</div>

<script>
(function () {
    'use strict';
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var toggle  = document.getElementById('menuToggle');
    if (!sidebar || !toggle) return;

    function open()  { sidebar.classList.add('open');  overlay.classList.add('open');  document.body.style.overflow = 'hidden'; }
    function close() { sidebar.classList.remove('open'); overlay.classList.remove('open'); document.body.style.overflow = ''; }

    toggle.addEventListener('click', open);
    overlay.addEventListener('click', close);

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); closeUpgradeModal(); } });
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
