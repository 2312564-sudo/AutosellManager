<?php
/* Quản lý người dùng (chỉ chủ web): tạo / sửa quyền / xoá. Quyền: owner (chủ), control (xem + điều khiển), view (chỉ xem). */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$u = qq_require_user('owner');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = qq_body();
    $roles = ['owner', 'control', 'view'];
    if (!empty($b['create'])) {
        $c = (array)$b['create'];
        $name = trim((string)($c['username'] ?? ''));
        if (!preg_match('~^[\p{L}\p{N}_.-]{3,32}$~u', $name)) {
            qq_fail(400, 'Tên đăng nhập 3–32 ký tự, không dấu cách');
        }
        if (mb_strlen((string)($c['password'] ?? '')) < 6) {
            qq_fail(400, 'Mật khẩu ít nhất 6 ký tự');
        }
        $role = in_array($c['role'] ?? '', $roles, true) ? $c['role'] : 'view';
        try {
            $pdo->prepare('INSERT INTO users (username, pass, role, "groups", created) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, password_hash((string)$c['password'], PASSWORD_DEFAULT), $role, mb_substr(trim((string)($c['groups'] ?? '')), 0, 300), time()]);
        } catch (Throwable $e) {
            qq_fail(400, 'Tên đăng nhập đã có');
        }
        qq_audit($u['username'], 'tạo người dùng', $name . ' (' . $role . ')');
    } elseif (!empty($b['update'])) {
        $c = (array)$b['update'];
        $id = (int)($c['id'] ?? 0);
        if ($id === (int)$u['id'] && ($c['role'] ?? 'owner') !== 'owner') {
            qq_fail(400, 'Không tự hạ quyền của chính mình được');
        }
        if (isset($c['role']) && in_array($c['role'], $roles, true)) {
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$c['role'], $id]);
        }
        if (isset($c['groups'])) {
            $pdo->prepare('UPDATE users SET "groups" = ? WHERE id = ?')->execute([mb_substr(trim((string)$c['groups']), 0, 300), $id]);
        }
        if (!empty($c['password'])) {
            if (mb_strlen((string)$c['password']) < 6) {
                qq_fail(400, 'Mật khẩu ít nhất 6 ký tự');
            }
            $pdo->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash((string)$c['password'], PASSWORD_DEFAULT), $id]);
        }
        qq_audit($u['username'], 'sửa người dùng', '#' . $id);
    } elseif (!empty($b['delete'])) {
        $id = (int)$b['delete'];
        if ($id === (int)$u['id']) {
            qq_fail(400, 'Không xoá chính mình được');
        }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        qq_audit($u['username'], 'xoá người dùng', '#' . $id);
    }
    qq_json(['ok' => 1]);
    exit;
}
$users = $pdo->query('SELECT id, username, role, "groups", created, last_login FROM users ORDER BY id')->fetchAll();
$audit = $pdo->query('SELECT t, "user", action, detail FROM audit ORDER BY id DESC LIMIT 80')->fetchAll();
qq_json(['ok' => 1, 'users' => $users, 'audit' => $audit, 'me' => (int)$u['id']]);
