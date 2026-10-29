<?php
/* Cài đặt (chỉ chủ web): Discord, ngưỡng báo động, mục tiêu, giá quy đổi, key kết nối mod, dữ liệu mẫu. */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$u = qq_require_user('owner');
$keys = ['site_name', 'tz', 'discord_webhook', 'discord_mention', 'alert_offline', 'alert_crash', 'alert_idle', 'alert_drop', 'alert_token', 'alert_ram',
    'alert_recover', 'alert_repeat_min', 'idle_min', 'drop_pct', 'drop_min_m', 'report_time', 'goal_m', 'vnd_per_m', 'keep_m1_days', 'restart_max_hour', 'default_server'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = qq_body();
    if (!empty($b['set'])) {
        foreach ((array)$b['set'] as $k => $v) {
            if (!in_array($k, $keys, true)) {
                continue;
            }
            $v = mb_substr(trim((string)$v), 0, 300);
            if ($k === 'tz') {
                try {
                    new DateTimeZone($v);
                } catch (Throwable $e) {
                    qq_fail(400, 'Múi giờ không hợp lệ');
                }
            }
            if ($k === 'discord_webhook' && $v !== '' && !preg_match('~^https://(?:\w+\.)?(discord|discordapp)\.com/api/webhooks/~', $v)) {
                qq_fail(400, 'Link webhook Discord phải bắt đầu bằng https://discord.com/api/webhooks/');
            }
            if ($k === 'report_time' && $v !== '' && !preg_match('~^\d{1,2}:\d{2}$~', $v)) {
                qq_fail(400, 'Giờ báo cáo dạng 22:00');
            }
            setting_set($k, $v);
        }
        qq_audit($u['username'], 'sửa cài đặt', implode(', ', array_keys((array)$b['set'])));
    } elseif (!empty($b['regenKey'])) {
        setting_set('api_key', bin2hex(random_bytes(20)));
        qq_audit($u['username'], 'đổi key kết nối mod');
    } elseif (!empty($b['testDiscord'])) {
        $ok = qq_discord(['title' => '✅ Kết nối Discord thành công', 'description' => 'Web ' . setting('site_name') . ' sẽ báo động vào kênh này.', 'color' => 0x8F5CF7]);
        if (!$ok) {
            qq_fail(400, 'Không gửi được. Kiểm tra lại link webhook.');
        }
    } elseif (!empty($b['demo'])) {
        require_once __DIR__ . '/_demo.php';
        if ($b['demo'] === 'create') {
            qq_demo_create();
        } else {
            qq_demo_clear();
        }
        qq_audit($u['username'], $b['demo'] === 'create' ? 'tạo dữ liệu mẫu' : 'xoá dữ liệu mẫu');
    }
    qq_json(['ok' => 1]);
    exit;
}

$out = [];
foreach ($keys as $k) {
    $out[$k] = setting($k);
}
$base = qq_base_url();
$dbSize = @filesize(qq_db_path()) ?: 0;
qq_json([
    'ok' => 1, 'settings' => $out, 'apiKey' => qq_api_key(), 'baseUrl' => $base,
    'webTxt' => "url=$base\nkey=" . qq_api_key() . "\nvps=\nevery=30",
    'info' => ['version' => QQ_VERSION, 'php' => PHP_VERSION, 'sqlite' => db()->query('select sqlite_version()')->fetchColumn(), 'dbMb' => round($dbSize / 1048576, 1),
        'fastcgi' => function_exists('fastcgi_finish_request'), 'curl' => function_exists('curl_init'), 'writable' => is_writable(QQ_DATA)],
]);
