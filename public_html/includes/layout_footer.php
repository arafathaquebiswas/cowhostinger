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

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
}());
</script>

<?php if (!empty($extra_js)): foreach ($extra_js as $_js_url): ?>
<script src="<?= e($_js_url) ?>"></script>
<?php endforeach; endif; ?>

<?php if (!empty($inline_js)): ?>
<script><?= $inline_js ?></script>
<?php endif; ?>

</body>
</html>
