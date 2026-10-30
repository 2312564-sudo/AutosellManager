<?php
/* Danh sách VPS (từ script watchdog) + acc trên từng VPS; chủ web sửa tiền thuê / hạn / ghi chú. */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$now = time();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = qq_require_user('owner');
    $b = qq_body();
    $name = (string)($b['name'] ?? '');
    if (!empty($b['delete'])) {
        db()->prepare('DELETE FROM vps WHERE name = ?')->execute([$name]);
    } else {
        $s = (array)($b['set'] ?? []);
        $insertVps = qq_uses_postgres()
            ? 'INSERT INTO vps (name) VALUES (?) ON CONFLICT (name) DO NOTHING'
            : 'INSERT OR IGNORE INTO vps (name) VALUES (?)';
        db()->prepare($insertVps)->execute([$name]);
        if (isset($s['cost'])) {
            db()->prepare('UPDATE vps SET cost = ? WHERE name = ?')->execute([max(0, (float)$s['cost']), $name]);
        }
        if (isset($s['expire'])) {
            db()->prepare('UPDATE vps SET expire = ? WHERE name = ?')->execute([mb_substr((string)$s['expire'], 0, 20), $name]);
        }
        if (isset($s['note'])) {
            db()->prepare('UPDATE vps SET note = ? WHERE name = ?')->execute([mb_substr((string)$s['note'], 0, 300), $name]);
        }
    }
    qq_audit($u['username'], 'sửa VPS', $name);
    qq_json(['ok' => 1]);
    exit;
}
$u = qq_require_user('view');
$accs = qq_accounts_for($u);
$byVps = [];
foreach ($accs as $a) {
    $byVps[$a['vps']][] = ['id' => (int)$a['id'], 'name' => $a['name'], 'uuid' => $a['uuid'], 'inst' => $a['inst'], 'status' => $a['status'], 'label' => QQ_STATUS[$a['status']][0] ?? '', 'cls' => QQ_STATUS[$a['status']][1] ?? 'bad', 'ip' => $a['ip']];
}
$list = [];
$seen = [];
foreach (db()->query('SELECT * FROM vps ORDER BY name')->fetchAll() as $v) {
    if (!isset($byVps[$v['name']]) && qq_user_groups($u)) {
        continue;
    }
    $seen[$v['name']] = 1;
    $list[] = ['name' => $v['name'], 'host' => $v['host'], 'ip' => $v['ip'], 'lastSeen' => (int)$v['last_seen'], 'alive' => $now - (int)$v['last_seen'] <= 180,
        'watchdog' => true, 'info' => json_decode($v['info'] ?: '{}', true), 'cost' => (float)$v['cost'], 'expire' => $v['expire'], 'note' => $v['note'], 'accs' => $byVps[$v['name']] ?? []];
}
foreach ($byVps as $name => $as) {
    if ($name === '' || isset($seen[$name])) {
        continue;
    }
    $list[] = ['name' => $name, 'host' => '', 'ip' => $as[0]['ip'] ?? '', 'lastSeen' => 0, 'alive' => false, 'watchdog' => false, 'info' => new stdClass(), 'cost' => 0, 'expire' => '', 'note' => '', 'accs' => $as];
}
qq_json(['ok' => 1, 'vps' => $list, 'now' => $now]);
