<?php
/* Trang web gửi lệnh cho acc (qua mod) hoặc cho VPS (qua script watchdog). */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$u = qq_require_user('control');
$b = qq_body();
$now = time();
$pdo = db();
$t = (string)($b['t'] ?? '');
$a = mb_substr(trim((string)($b['a'] ?? '')), 0, 200);
$accId = (int)($b['acc'] ?? 0);

$accCmds = ['shot', 'cmd', 'chat', 'sell', 'build', 'count'];
$vpsCmds = ['launch', 'kill', 'restart'];
if (!in_array($t, array_merge($accCmds, $vpsCmds), true)) {
    qq_fail(400, 'Lệnh không hợp lệ');
}
if (in_array($t, ['sell', 'build', 'count'], true) && !in_array($a, ['on', 'off'], true)) {
    qq_fail(400, 'Chọn bật hoặc tắt');
}
if (in_array($t, ['cmd', 'chat'], true) && $a === '') {
    qq_fail(400, 'Nhập nội dung lệnh');
}

$st = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
$st->execute([$accId]);
$acc = $st->fetch();
if (!$acc || !qq_can_see($u, $acc)) {
    qq_fail(404, 'Không thấy acc');
}
if ((int)$acc['demo']) {
    if ($t === 'shot') {
        $sql = "INSERT INTO cmds (target, acc_id, t, a, created, by_user, status, result, done_at) VALUES ('acc', ?, 'shot', '', ?, ?, 'fail', 'Acc mẫu không có game thật để chụp', ?)";
        $stmt = $pdo->prepare(qq_uses_postgres() ? $sql . ' RETURNING id' : $sql);
        $stmt->execute([$accId, $now, $u['username'], $now]);
        $id = (int)(qq_uses_postgres() ? $stmt->fetchColumn() : $pdo->lastInsertId());
        qq_json(['ok' => 1, 'id' => $id, 'demo' => 1]);
        exit;
    }
    qq_fail(400, 'Đây là acc mẫu, không gửi lệnh được');
}

if (in_array($t, $vpsCmds, true)) {
    if ($acc['vps'] === '' || $acc['inst'] === '') {
        qq_fail(400, 'Acc chưa có thông tin VPS / instance (cần mod R491 gửi lên ít nhất 1 lần)');
    }
    $v = qq_vps_row($acc['vps']);
    if (!$v || $now - (int)$v['last_seen'] > 180) {
        qq_fail(400, 'VPS ' . $acc['vps'] . ' chưa cài script watchdog hoặc đang mất tín hiệu, không mở / tắt game từ xa được');
    }
    $stt = json_decode($acc['state'] ?: '{}', true) ?: [];
    $server = trim((string)($stt['server'] ?? '')) ?: setting('default_server');
    $sql = "INSERT INTO cmds (target, acc_id, vps, t, a, created, by_user) VALUES ('vps', ?, ?, ?, ?, ?, ?)";
    $insert = $pdo->prepare(qq_uses_postgres() ? $sql . ' RETURNING id' : $sql);
    $insert->execute([$accId, $acc['vps'], $t, $acc['inst'] . '|' . $server, $now, $u['username']]);
} else {
    $sql = "INSERT INTO cmds (target, acc_id, t, a, created, by_user) VALUES ('acc', ?, ?, ?, ?, ?)";
    $insert = $pdo->prepare(qq_uses_postgres() ? $sql . ' RETURNING id' : $sql);
    $insert->execute([$accId, $t, $a, $now, $u['username']]);
    $pdo->prepare('UPDATE accounts SET watch_until = ? WHERE id = ?')->execute([$now + 150, $accId]);
}
$id = (int)(qq_uses_postgres() ? $insert->fetchColumn() : $pdo->lastInsertId());
$names = ['shot' => 'Chụp màn hình', 'cmd' => 'Gửi lệnh /' . ltrim($a, '/'), 'chat' => 'Chat: ' . $a, 'sell' => ($a === 'on' ? 'Bật' : 'Tắt') . ' AutoSell',
    'build' => ($a === 'on' ? 'Bật' : 'Tắt') . ' AutoBuild', 'count' => ($a === 'on' ? 'Bật' : 'Tắt') . ' đếm tiền', 'launch' => 'Mở game', 'kill' => 'Tắt game', 'restart' => 'Mở lại game'];
if ($t !== 'shot') {
    qq_event($accId, 'cmd', $u['username'] . ': ' . $names[$t] . ' (đang chờ)');
    qq_audit($u['username'], 'cmd', $acc['name'] . ' · ' . $names[$t]);
}
qq_json(['ok' => 1, 'id' => $id]);
