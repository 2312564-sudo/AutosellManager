<?php
/* Chi tiết 1 acc (GET ?id=) và sửa thông tin acc (POST). */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$now = time();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = qq_require_user('view');
    $b = qq_body();
    if (isset($b['order']) && is_array($b['order'])) {
        if ($u['role'] === 'view') {
            qq_fail(403, 'Tài khoản chỉ xem không đổi thứ tự được');
        }
        $i = 1;
        foreach ($b['order'] as $id) {
            $pdo->prepare('UPDATE accounts SET sort = ? WHERE id = ?')->execute([$i++, (int)$id]);
        }
        qq_json(['ok' => 1]);
        exit;
    }
    if (isset($b['checkAll'])) {
        $day = $b['checkAll'] ? qq_today($now) : '';
        foreach (qq_accounts_for($u) as $a) {
            $pdo->prepare('UPDATE accounts SET checked_day = ? WHERE id = ?')->execute([$day, $a['id']]);
        }
        qq_json(['ok' => 1]);
        exit;
    }
    $st = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([(int)($b['id'] ?? 0)]);
    $a = $st->fetch();
    if (!$a || !qq_can_see($u, $a)) {
        qq_fail(404, 'Không thấy acc');
    }
    if (!empty($b['delete'])) {
        if ($u['role'] !== 'owner') {
            qq_fail(403, 'Chỉ chủ web mới xoá acc được');
        }
        foreach (['m1', 'h1', 'events', 'restarts'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE acc_id = ?")->execute([$a['id']]);
        }
        $pdo->prepare('DELETE FROM cmds WHERE acc_id = ?')->execute([$a['id']]);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$a['id']]);
        qq_audit($u['username'], 'xoá acc', $a['name']);
        qq_json(['ok' => 1]);
        exit;
    }
    $set = (array)($b['set'] ?? []);
    $map = ['grp' => 'grp', 'tags' => 'tags', 'note' => 'note', 'cost' => 'cost', 'vpsExpire' => 'vps_expire', 'autoRestart' => 'auto_restart', 'hidden' => 'hidden', 'checked' => 'checked_day'];
    foreach ($set as $k => $v) {
        if (!isset($map[$k])) {
            continue;
        }
        if ($k !== 'checked' && $u['role'] === 'view') {
            qq_fail(403, 'Tài khoản chỉ xem không sửa được');
        }
        if ($k === 'checked') {
            $v = $v ? qq_today($now) : '';
        } elseif (in_array($k, ['autoRestart', 'hidden'], true)) {
            $v = $v ? 1 : 0;
        } elseif ($k === 'cost') {
            $v = max(0, (float)$v);
        } else {
            $v = mb_substr(trim((string)$v), 0, $k === 'note' ? 1000 : 80);
        }
        $pdo->prepare('UPDATE accounts SET ' . $map[$k] . ' = ? WHERE id = ?')->execute([$v, $a['id']]);
    }
    if (array_diff(array_keys($set), ['checked'])) {
        qq_audit($u['username'], 'sửa acc', $a['name'] . ' · ' . implode(', ', array_keys($set)));
    }
    qq_json(['ok' => 1]);
    exit;
}

$u = qq_require_user('view');
$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
$st->execute([$id]);
$a = $st->fetch();
if (!$a || !qq_can_see($u, $a)) {
    qq_fail(404, 'Không thấy acc');
}
qq_set_status($a, $now);
// đang mở chi tiết → mod gửi nhanh 3 s / lần trong 2 phút (lệnh tới nhanh)
if ($u['role'] !== 'view' && (int)$a['watch_until'] < $now + 60) {
    $pdo->prepare('UPDATE accounts SET watch_until = ? WHERE id = ?')->execute([$now + 90, $id]);
}
$profit = qq_profit_map([$id], qq_day_start($now), $now + 1);
$hour = qq_profit_map([$id], $now - 3600, $now + 1);
$pub = qq_acc_public($a, $now, $profit, $hour);

$ev = $pdo->prepare('SELECT t, kind, msg, val FROM events WHERE acc_id = ? ORDER BY id DESC LIMIT 40');
$ev->execute([$id]);
$events = $ev->fetchAll();
foreach ($events as &$e) {
    $e['t'] = (int)$e['t'];
    $e['val'] = $e['val'] === null ? null : (float)$e['val'];
}
unset($e);

// 7 ngày: số dư cuối mỗi ngày + lời mỗi ngày
$days = [];
$d0 = qq_day_start($now);
for ($i = 6; $i >= 0; $i--) {
    $from = $d0 - $i * 86400;
    $p = qq_profit_map([$id], $from, $from + 86400)[$id] ?? ['net' => 0.0, 'inc' => 0.0, 'sells' => 0];
    $c = $pdo->prepare('SELECT c FROM h1 WHERE acc_id = ? AND t < ? AND c IS NOT NULL ORDER BY t DESC LIMIT 1');
    $c->execute([$id, $from + 86400]);
    $close = $c->fetchColumn();
    $days[] = ['t' => $from + qq_tz_offset($from), 'close' => $close === false ? null : (float)$close, 'net' => $p['net'], 'inc' => $p['inc'], 'sells' => $p['sells']];
}
$cm = $pdo->prepare('SELECT id, t, a, status, result, created, done_at, by_user FROM cmds WHERE acc_id = ? ORDER BY id DESC LIMIT 12');
$cm->execute([$id]);
$week = qq_profit_map([$id], $now - 7 * 86400, $now + 1)[$id] ?? ['net' => 0.0, 'inc' => 0.0, 'sells' => 0];
$v = $a['vps'] !== '' ? qq_vps_row($a['vps']) : null;
qq_json([
    'ok' => 1, 'acc' => $pub, 'events' => $events, 'days' => $days, 'cmds' => $cm->fetchAll(), 'week' => $week,
    'state' => json_decode($a['state'] ?: '{}', true),
    'vps' => $v ? ['name' => $v['name'], 'ip' => $v['ip'], 'alive' => $now - (int)$v['last_seen'] <= 180, 'lastSeen' => (int)$v['last_seen']] : null,
]);
