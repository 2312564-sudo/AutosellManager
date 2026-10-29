<?php
/* Mod (WebSync) gửi trạng thái acc lên đây mỗi 30 giây. Trả về lệnh chờ chạy. */
declare(strict_types=1);
require __DIR__ . '/_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    qq_fail(405, 'Chỉ nhận POST');
}
qq_require_key();
try {
    qq_json(qq_heartbeat(qq_body()));
} catch (Throwable $e) {
    qq_fail(500, 'Lỗi máy chủ: ' . $e->getMessage());
}
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_flush();
    @flush();
}
try {
    qq_maintenance();
} catch (Throwable $e) {
    error_log('qqweb maintenance: ' . $e->getMessage());
}
