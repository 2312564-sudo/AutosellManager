<?php
/* Trang web lấy dữ liệu tổng quan + danh sách acc (gọi 10 giây / lần). */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$u = qq_require_user('view');
$now = time();
require_once __DIR__ . '/_demo.php';
qq_demo_tick($now);
$accs = qq_accounts_for($u);
foreach ($accs as &$a) {
    qq_set_status($a, $now); // trạng thái luôn khớp ghi chú (không chờ lượt bảo trì)
}
unset($a);
$ids = array_map(fn($a) => (int)$a['id'], $accs);
$profit = qq_profit_map($ids, qq_day_start($now), $now + 1);
$hour = qq_profit_map($ids, $now - 3600, $now + 1);
$list = [];
foreach ($accs as $a) {
    $list[] = qq_acc_public($a, $now, $profit, $hour);
}
$sum = qq_summary($now, $u);
unset($sum['top'], $sum['problems']);
$groups = array_values(array_unique(array_filter(array_map(fn($a) => $a['grp'], $accs))));
sort($groups);
$vpsCount = (int)db()->query('SELECT COUNT(*) FROM vps WHERE last_seen > ' . ($now - 180))->fetchColumn();
qq_json([
    'ok' => 1, 'now' => $now, 'tzOffset' => qq_tz_offset($now), 'site' => setting('site_name'),
    'me' => ['name' => $u['username'], 'role' => $u['role']],
    'summary' => $sum, 'accounts' => $list, 'groups' => $groups, 'vpsAlive' => $vpsCount,
    'goal' => (float)setting('goal_m') * 1e6, 'vndPerM' => (float)setting('vnd_per_m'), 'idleMin' => (int)setting('idle_min'),
    'hasDemo' => (int)db()->query('SELECT COUNT(*) FROM accounts WHERE demo = 1')->fetchColumn(),
    'hasDiscord' => trim(setting('discord_webhook')) !== '',
]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
try {
    qq_maintenance();
} catch (Throwable $e) {
    error_log('qqweb: ' . $e->getMessage());
}
