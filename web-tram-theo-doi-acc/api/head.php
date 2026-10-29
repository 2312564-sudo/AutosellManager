<?php
/* Ảnh head skin Minecraft (mặt + lớp mũ) theo uuid / tên, lưu đệm 24 giờ trong data/heads. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';

if (!qq_user()) {
    http_response_code(401);
    exit;
}
$uuid = preg_replace('~[^a-fA-F0-9-]~', '', (string)($_GET['u'] ?? ''));
$name = preg_replace('~[^A-Za-z0-9_]~', '', (string)($_GET['n'] ?? ''));
$size = (int)($_GET['s'] ?? 64);
$size = in_array($size, [32, 64, 128], true) ? $size : 64;
$id = strlen(str_replace('-', '', $uuid)) === 32 ? str_replace('-', '', $uuid) : $name;
if ($id === '') {
    $id = 'MHF_Steve';
}
$dir = qq_data_dir() . '/heads';
$file = $dir . '/' . md5($id . '|' . $size) . '.png';
$fresh = is_file($file) && time() - filemtime($file) < 24 * 3600;

function qq_fetch(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'qqweb/1.0']);
        $b = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200 && is_string($b) && substr($b, 0, 8) === "\x89PNG\r\n\x1a\n" ? $b : null;
    }
    $b = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'qqweb/1.0']]));
    return is_string($b) && substr($b, 0, 8) === "\x89PNG\r\n\x1a\n" ? $b : null;
}

// lấy hỏng thì 30 phút sau mới thử lại (khỏi bắt trang chờ mỗi lần tải)
$miss = $file . '.miss';
if (!$fresh && !(is_file($miss) && time() - filemtime($miss) < 1800)) {
    $img = qq_fetch('https://mc-heads.net/avatar/' . rawurlencode($id) . '/' . $size);
    if ($img === null && $name !== '') {
        $img = qq_fetch('https://minotar.net/helm/' . rawurlencode($name) . '/' . $size . '.png');
    }
    if ($img !== null) {
        @file_put_contents($file, $img);
        @unlink($miss);
        $fresh = true;
    } else {
        @touch($miss);
    }
}
header('Cache-Control: private, max-age=' . ($fresh ? 3600 : 300));
header('Content-Type: image/png');
if (is_file($file)) {
    readfile($file);
} else {
    readfile(QQ_ROOT . '/assets/head-default.png');
}
