<?php
/* Script trên VPS (qq-watchdog.ps1) gửi lên đây mỗi phút: VPS còn sống, game nào đang chạy. Trả về lệnh mở / tắt game. */
declare(strict_types=1);
require __DIR__ . '/_core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    qq_fail(405, 'Chỉ nhận POST');
}
qq_require_key();
$p = qq_body();
$now = time();
$name = mb_substr(trim((string)($p['vps'] ?? '')), 0, 40);
if ($name === '') {
    $name = mb_substr(trim((string)($p['host'] ?? '')), 0, 40);
}
if ($name === '') {
    qq_fail(400, 'Thiếu tên VPS');
}
$inst = [];
foreach ((array)($p['instances'] ?? []) as $i) {
    if (!is_array($i) || empty($i['name'])) {
        continue;
    }
    $inst[] = [
        'name' => mb_substr((string)$i['name'], 0, 80), 'running' => !empty($i['running']) ? 1 : 0,
        'ramMb' => (int)($i['ramMb'] ?? 0), 'cpu' => round((float)($i['cpu'] ?? 0), 1), 'managed' => !empty($i['managed']) ? 1 : 0,
    ];
}
$info = [
    'ver' => mb_substr((string)($p['ver'] ?? ''), 0, 20), 'instances' => $inst,
    'freeRamMb' => (int)($p['freeRamMb'] ?? 0), 'totalRamMb' => (int)($p['totalRamMb'] ?? 0),
    'cpu' => round((float)($p['cpu'] ?? 0), 1), 'uptimeSec' => (int)($p['uptimeSec'] ?? 0), 'prism' => !empty($p['prism']) ? 1 : 0,
];
$pdo = db();
$insertVps = qq_uses_postgres()
    ? 'INSERT INTO vps (name) VALUES (?) ON CONFLICT (name) DO NOTHING'
    : 'INSERT OR IGNORE INTO vps (name) VALUES (?)';
$pdo->prepare($insertVps)->execute([$name]);
$pdo->prepare('UPDATE vps SET host = ?, ip = ?, last_seen = ?, info = ? WHERE name = ?')
    ->execute([mb_substr((string)($p['host'] ?? ''), 0, 40), qq_ip(), $now, json_encode($info, JSON_UNESCAPED_UNICODE), $name]);

foreach ((array)($p['acks'] ?? []) as $ack) {
    if (!is_array($ack) || empty($ack['id'])) {
        continue;
    }
    $ok = !empty($ack['ok']);
    $msg = mb_substr((string)($ack['msg'] ?? ''), 0, 200);
    $pdo->prepare("UPDATE cmds SET status = ?, result = ?, done_at = ? WHERE id = ? AND target = 'vps' AND vps = ?")
        ->execute([$ok ? 'done' : 'fail', $msg, $now, (int)$ack['id'], $name]);
    $c = $pdo->prepare('SELECT acc_id FROM cmds WHERE id = ?');
    $c->execute([(int)$ack['id']]);
    $accId = (int)$c->fetchColumn();
    if ($accId) {
        qq_event($accId, $ok ? 'cmd' : 'cmdfail', 'VPS: ' . $msg);
    }
}

$pdo->prepare("UPDATE cmds SET status = 'fail', result = 'Hết hạn (VPS không nhận lệnh trong 10 phút)', done_at = ? WHERE target = 'vps' AND vps = ? AND status IN ('new','sent') AND created < ?")
    ->execute([$now, $name, $now - 600]);
$cs = $pdo->prepare("SELECT id, t, a FROM cmds WHERE target = 'vps' AND vps = ? AND (status = 'new' OR (status = 'sent' AND sent_at < ?)) ORDER BY id LIMIT 10");
$cs->execute([$name, $now - 90]);
$cmds = [];
foreach ($cs->fetchAll() as $c) {
    $cmds[] = ['id' => (int)$c['id'], 't' => $c['t'], 'a' => $c['a']];
    $pdo->prepare("UPDATE cmds SET status = 'sent', sent_at = ? WHERE id = ?")->execute([$now, $c['id']]);
}
qq_json(['ok' => 1, 'server' => setting('default_server'), 'cmds' => $cmds]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
try {
    qq_maintenance();
} catch (Throwable $e) {
}
