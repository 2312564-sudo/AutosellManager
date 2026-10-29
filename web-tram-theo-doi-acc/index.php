<?php
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';
require __DIR__ . '/api/_view.php';

if (!qq_installed()) {
    header('Location: setup.php');
    exit;
}
$u = qq_user();
if (!$u) {
    header('Location: login.php');
    exit;
}
header('Cache-Control: no-store');
qq_head(setting('site_name'));
qq_sky();
$boot = ['csrf' => qq_csrf(), 'me' => ['name' => $u['username'], 'role' => $u['role']], 'site' => setting('site_name'), 'v' => QQ_VERSION];
?>
<div class="app"><div class="wrap" id="app"><div class="empty"><div class="spin" style="margin:40px auto"></div>Đang tải…</div></div></div>
<div class="toast-box" id="toasts" aria-live="polite"></div>
<script>window.QQ = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/chart.js?v=<?= QQ_VERSION ?>"></script>
<script src="assets/app.js?v=<?= QQ_VERSION ?>"></script>
</body></html>
