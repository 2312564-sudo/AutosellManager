<?php
/* Đổi mật khẩu của chính mình. */
declare(strict_types=1);
require __DIR__ . '/_boot.php';

$u = qq_require_user('view');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = qq_body();
    $st = db()->prepare('SELECT pass FROM users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify((string)($b['old'] ?? ''), (string)$st->fetchColumn())) {
        qq_fail(400, 'Mật khẩu cũ không đúng');
    }
    $new = (string)($b['new'] ?? '');
    if (mb_strlen($new) < 6) {
        qq_fail(400, 'Mật khẩu mới cần ít nhất 6 ký tự');
    }
    db()->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    qq_audit($u['username'], 'đổi mật khẩu');
    qq_json(['ok' => 1]);
    exit;
}
qq_json(['ok' => 1, 'me' => $u]);
