<?php
/* Không bắt buộc. Nếu hosting có Cron Job: gọi https://ten-mien/duong-dan/api/cron.php?key=KHÓA mỗi phút để báo động chạy cả khi mọi VPS cùng tắt. */
declare(strict_types=1);
require __DIR__ . '/_core.php';

qq_require_key();
qq_maintenance(true);
qq_json(['ok' => 1, 't' => time()]);
