<?php
/*
 * Trạm theo dõi acc — xử lý dữ liệu: nhận trạng thái từ mod, nến, trạng thái acc, báo Discord, dọn dữ liệu.
 */
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

const QQ_STATUS = [
    'sell' => ['Đang bán', 'good'],
    'build' => ['Đang xây', 'good'],
    'mine' => ['Đang đào', 'good'],
    'idle' => ['Đứng yên', 'warn'],
    'maint' => ['Server bảo trì', 'warn'],
    'off' => ['Offline', 'bad'],
    'crash' => ['Game crash', 'bad'],
    'lost' => ['Mất tín hiệu', 'bad'],
];

function qq_stale_sec(): int
{
    return 150;
}

/* ───────────────────────── nến ───────────────────────── */

/** Cập nhật nến 1 phút + 1 giờ của 1 acc (acc_id 0 = tổng tất cả acc). $bal null = không đọc được số dư lần này. */
function qq_candle(int $accId, int $now, ?float $bal, ?float $prevClose, float $inc, int $sells): void
{
    $pdo = db();
    foreach ([['m1', intdiv($now, 60) * 60], ['h1', intdiv($now, 3600) * 3600]] as [$tbl, $t]) {
        $st = $pdo->prepare("SELECT o, h, l, c FROM $tbl WHERE acc_id = ? AND t = ?");
        $st->execute([$accId, $t]);
        $row = $st->fetch();
        if ($row) {
            if ($bal !== null) {
                $pdo->prepare("UPDATE $tbl SET h = MAX(COALESCE(h, ?), ?), l = MIN(COALESCE(l, ?), ?), c = ?, o = COALESCE(o, ?), inc = inc + ?, sells = sells + ? WHERE acc_id = ? AND t = ?")
                    ->execute([$bal, $bal, $bal, $bal, $bal, $prevClose ?? $bal, $inc, $sells, $accId, $t]);
            } elseif ($inc > 0 || $sells > 0) {
                $pdo->prepare("UPDATE $tbl SET inc = inc + ?, sells = sells + ? WHERE acc_id = ? AND t = ?")->execute([$inc, $sells, $accId, $t]);
            }
        } else {
            $c = $bal ?? $prevClose;
            if ($c === null && $inc <= 0) {
                continue;
            }
            $o = $prevClose ?? $c;
            $pdo->prepare("INSERT INTO $tbl (acc_id, t, o, h, l, c, inc, sells) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$accId, $t, $o, $c === null ? null : max((float)$o, $c), $c === null ? null : min((float)$o, $c), $c, $inc, $sells]);
        }
    }
}

function qq_total_bal(): ?float
{
    $v = db()->query('SELECT SUM(bal) FROM accounts WHERE hidden = 0 AND bal IS NOT NULL')->fetchColumn();
    return $v === null || $v === false ? null : (float)$v;
}

function qq_last_close(int $accId): ?float
{
    $st = db()->prepare('SELECT c FROM m1 WHERE acc_id = ? AND c IS NOT NULL ORDER BY t DESC LIMIT 1');
    $st->execute([$accId]);
    $v = $st->fetchColumn();
    if ($v === false) {
        $st = db()->prepare('SELECT c FROM h1 WHERE acc_id = ? AND c IS NOT NULL ORDER BY t DESC LIMIT 1');
        $st->execute([$accId]);
        $v = $st->fetchColumn();
    }
    return $v === false || $v === null ? null : (float)$v;
}

/* ───────────────────────── trạng thái acc ───────────────────────── */

function qq_vps_row(string $name): ?array
{
    static $cache = [];
    if ($name === '') {
        return null;
    }
    if (!array_key_exists($name, $cache)) {
        $st = db()->prepare('SELECT * FROM vps WHERE name = ?');
        $st->execute([$name]);
        $cache[$name] = $st->fetch() ?: null;
    }
    return $cache[$name];
}

/** Trả về [mã trạng thái, ghi chú]. */
function qq_compute_status(array $a, int $now): array
{
    $s = json_decode($a['state'] ?: '{}', true) ?: [];
    $idle = max(3, (int)setting('idle_min')) * 60;
    $fresh = $a['last_seen'] > 0 && $now - (int)$a['last_seen'] <= qq_stale_sec();
    if (!$fresh) {
        $v = qq_vps_row((string)$a['vps']);
        $ago = $a['last_seen'] > 0 ? qq_ago($now - (int)$a['last_seen']) : 'chưa từng';
        if ($v && $now - (int)$v['last_seen'] <= 150) {
            $info = json_decode($v['info'] ?: '{}', true) ?: [];
            foreach (($info['instances'] ?? []) as $in) {
                if (($in['name'] ?? '') === $a['inst']) {
                    if (!empty($in['running'])) {
                        return ['lost', 'Game treo, không phản hồi ' . $ago];
                    }
                    return ['crash', 'Game đã tắt · VPS còn sống' . ((int)$a['auto_restart'] ? ' · sẽ tự mở lại' : '')];
                }
            }
            return ['lost', 'Không thấy tín hiệu từ game ' . $ago . ' · VPS còn sống'];
        }
        return ['lost', 'Mất tín hiệu ' . $ago . ($v ? ' · VPS cũng không phản hồi' : '')];
    }
    if (empty($s['online'])) {
        if (!empty($s['disc'])) {
            return ['off', 'Bị ngắt: ' . $s['disc']];
        }
        return ['off', 'Đang ở menu, chưa vào server'];
    }
    if (!empty($s['maint'])) {
        return ['maint', 'Server đang bảo trì'];
    }
    if (!empty($s['dead'])) {
        return ['idle', 'Nhân vật đã chết, đang chờ hồi sinh'];
    }
    if (!empty($s['build'])) {
        $since = max((int)$a['build_prog_at'], (int)$a['last_gain_at']);
        if ($since > 0 && $now - $since > $idle) {
            return ['idle', 'AutoBuild không đặt thêm block ' . qq_ago($now - $since)];
        }
        $pct = isset($s['buildPct']) && $s['buildPct'] >= 0 ? ' · ' . round((float)$s['buildPct'], 1) . '%' : '';
        return ['build', mb_substr((string)($s['buildSt'] ?? 'AutoBuild'), 0, 80) . $pct];
    }
    if (!empty($s['mine'])) {
        return ['mine', mb_substr((string)($s['mineSt'] ?? 'AutoMine'), 0, 80)];
    }
    $selling = !empty($s['sellMode']) || !empty($s['axe']);
    $gain = (int)$a['last_gain_at'];
    if ($selling) {
        if ($gain > 0 && $now - $gain <= $idle) {
            return ['sell', ($s['sellName'] ?? 'AutoSell') . ' · có tiền ' . qq_ago($now - $gain)];
        }
        $joined = (int)($s['joinSec'] ?? 0);
        if ($joined > 0 && $joined < $idle) {
            return ['sell', ($s['sellName'] ?? 'AutoSell') . ' · vừa vào ' . qq_ago($joined)];
        }
        return ['idle', 'Không có tiền vào ' . ($gain > 0 ? qq_ago($now - $gain) : 'từ lúc vào')];
    }
    if ($gain > 0 && $now - $gain <= $idle) {
        return ['sell', 'Có tiền vào ' . qq_ago($now - $gain)];
    }
    return ['idle', 'Online, chưa bật chức năng nào'];
}

function qq_ago(int $sec): string
{
    $sec = max(0, $sec);
    if ($sec < 60) {
        return $sec . ' giây';
    }
    if ($sec < 3600) {
        return intdiv($sec, 60) . ' phút';
    }
    if ($sec < 86400) {
        return intdiv($sec, 3600) . ' giờ ' . intdiv($sec % 3600, 60) . ' phút';
    }
    return intdiv($sec, 86400) . ' ngày';
}

function qq_set_status(array &$a, int $now): void
{
    [$st, $note] = qq_compute_status($a, $now);
    $a['_note'] = $note;
    if ($st !== $a['status']) {
        $old = $a['status'];
        db()->prepare('UPDATE accounts SET status = ?, status_since = ? WHERE id = ?')->execute([$st, $now, $a['id']]);
        $a['status'] = $st;
        $a['status_since'] = $now;
        if ($a['last_seen'] > 0) {
            qq_event((int)$a['id'], 'status', QQ_STATUS[$st][0] . ' · ' . $note);
        }
        unset($old);
    }
}

/* ───────────────────────── nhận trạng thái từ mod ───────────────────────── */

function qq_heartbeat(array $p): array
{
    $pdo = db();
    $now = time();
    $uuid = mb_substr(trim((string)($p['uuid'] ?? '')), 0, 64);
    $name = mb_substr(trim((string)($p['name'] ?? '')), 0, 32);
    if ($uuid === '' || $name === '') {
        qq_fail(400, 'Thiếu uuid / tên');
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM accounts WHERE uuid = ?');
        $st->execute([$uuid]);
        $a = $st->fetch();
        if (!$a) {
            $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM accounts')->fetchColumn();
            $pdo->prepare('INSERT INTO accounts (uuid, name, created, sort, status, status_since) VALUES (?, ?, ?, ?, ?, ?)')->execute([$uuid, $name, $now, $sort, 'lost', $now]);
            $st->execute([$uuid]);
            $a = $st->fetch();
            qq_event((int)$a['id'], 'status', 'Acc mới kết nối web');
        }
        $id = (int)$a['id'];
        $sid = mb_substr((string)($p['sid'] ?? ''), 0, 40);
        $cstart = (int)($p['cstart'] ?? 0);
        $inc = max(0.0, (float)($p['inc'] ?? 0));
        $sells = max(0, (int)($p['sells'] ?? 0));
        $d = 0.0;
        $ds = 0;
        if ($a['sid'] !== '') {
            if ($cstart === (int)$a['cstart'] && $cstart > 0) {
                $d = max(0.0, $inc - (float)$a['inc_last']);
                $ds = max(0, $sells - (int)$a['sells_last']);
            } elseif ($cstart > 0) {
                $d = $inc;
                $ds = $sells;
            }
        }
        if ($d > 5e10) {
            $d = 0.0; // số lạ, bỏ qua
        }
        $bal = isset($p['bal']) && is_numeric($p['bal']) && (float)$p['bal'] >= 0 ? (float)$p['bal'] : null;
        $prev = $a['bal'] === null ? null : (float)$a['bal'];
        $gain = (int)$a['last_gain_at'];
        if ($d > 0 || ($bal !== null && $prev !== null && $bal > $prev + 1)) {
            $gain = $now;
        }
        $placed = (int)($p['buildPlaced'] ?? 0);
        $prog = (int)$a['build_prog_at'];
        if (!empty($p['build']) && ($placed !== (int)$a['build_placed'] || $prog === 0)) {
            $prog = $now;
        }
        $vps = mb_substr(trim((string)($p['vps'] ?? '')), 0, 40);
        if ($vps === '') {
            $vps = mb_substr(trim((string)($p['host'] ?? '')), 0, 40);
        }
        $keep = ['v', 'online', 'joinSec', 'server', 'region', 'x', 'y', 'z', 'dim', 'hp', 'ramMb', 'ramMax', 'fps', 'tokenSec', 'maint',
            'disc', 'discAgo', 'dead', 'counting', 'perHour', 'lastMoneySec', 'sellMode', 'sellName', 'axe', 'home', 'build', 'buildSt',
            'buildPct', 'buildSchem', 'buildHome', 'buildPlaced', 'mine', 'mineSt', 'screen', 'inc', 'sells'];
        $state = [];
        foreach ($keep as $k) {
            if (array_key_exists($k, $p)) {
                $state[$k] = is_string($p[$k]) ? mb_substr($p[$k], 0, 200) : $p[$k];
            }
        }
        $state['at'] = $now;
        $old = json_decode($a['state'] ?: '{}', true) ?: [];
        if (!empty($old['online']) && empty($state['online']) && !empty($state['disc'])) {
            qq_event($id, 'disc', 'Mất kết nối: ' . $state['disc']);
        }
        if (empty($old['online']) && !empty($state['online'])) {
            qq_event($id, 'join', 'Vào server ' . ($state['server'] ?? ''));
        }
        if (empty($old['dead']) && !empty($state['dead'])) {
            qq_event($id, 'dead', 'Nhân vật chết');
        }
        $pdo->prepare('UPDATE accounts SET name = ?, inst = ?, vps = ?, host = ?, ip = ?, last_seen = ?, state = ?, bal = COALESCE(?, bal), bal_at = CASE WHEN ? IS NULL THEN bal_at ELSE ? END,
            sid = ?, cstart = ?, inc_last = ?, sells_last = ?, last_gain_at = ?, build_placed = ?, build_prog_at = ? WHERE id = ?')
            ->execute([$name, mb_substr((string)($p['inst'] ?? ''), 0, 80), $vps, mb_substr((string)($p['host'] ?? ''), 0, 40), qq_ip(), $now,
                json_encode($state, JSON_UNESCAPED_UNICODE), $bal, $bal, $now, $sid, $cstart, $inc, $sells, $gain, $placed, $prog, $id]);

        qq_candle($id, $now, $bal, $prev ?? $bal, $d, $ds);
        if ($bal !== null || $d > 0) {
            $prevTotal = qq_last_close(0);
            qq_candle(0, $now, qq_total_bal(), $prevTotal, $d, $ds);
        }
        if ($d > 0) {
            $last = $pdo->prepare("SELECT id, t, val FROM events WHERE acc_id = ? AND kind = 'sell' ORDER BY id DESC LIMIT 1");
            $last->execute([$id]);
            $le = $last->fetch();
            if ($le && $now - (int)$le['t'] < 300) {
                $pdo->prepare('UPDATE events SET val = val + ?, msg = ? WHERE id = ?')->execute([$d, 'Bán được tiền', $le['id']]);
            } else {
                qq_event($id, 'sell', 'Bán được tiền', $d);
            }
        }

        // lệnh đã chạy xong
        foreach (($p['acks'] ?? []) as $ack) {
            if (!is_array($ack) || empty($ack['id'])) {
                continue;
            }
            $cid = (int)$ack['id'];
            $ok = !empty($ack['ok']);
            $msg = mb_substr((string)($ack['msg'] ?? ''), 0, 200);
            $pdo->prepare("UPDATE cmds SET status = ?, result = ?, done_at = ? WHERE id = ? AND acc_id = ?")->execute([$ok ? 'done' : 'fail', $msg, $now, $cid, $id]);
            qq_event($id, $ok ? 'cmd' : 'cmdfail', $msg);
        }

        $st->execute([$uuid]);
        $a = $st->fetch();
        qq_set_status($a, $now);

        // lệnh chờ gửi xuống mod
        $pdo->prepare("UPDATE cmds SET status = 'fail', result = 'Hết hạn (acc không nhận lệnh trong 10 phút)', done_at = ? WHERE target = 'acc' AND acc_id = ? AND status IN ('new','sent') AND created < ?")
            ->execute([$now, $id, $now - 600]);
        $cs = $pdo->prepare("SELECT id, t, a FROM cmds WHERE target = 'acc' AND acc_id = ? AND (status = 'new' OR (status = 'sent' AND sent_at < ?)) ORDER BY id LIMIT 10");
        $cs->execute([$id, $now - 40]);
        $cmds = [];
        foreach ($cs->fetchAll() as $c) {
            $cmds[] = ['id' => (int)$c['id'], 't' => (string)$c['t'], 'a' => (string)$c['a']];
            $pdo->prepare("UPDATE cmds SET status = 'sent', sent_at = ? WHERE id = ?")->execute([$now, $c['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    qq_check_drop($a, $bal, $now);
    qq_check_misc($a, $state, $now);
    $fast = (int)$a['watch_until'] > $now || $cmds ? 1 : 0;
    return ['ok' => 1, 'fast' => $fast, 'cmds' => $cmds];
}

/* ───────────────────────── báo động ───────────────────────── */

function qq_acc_fields(array $a): array
{
    $s = json_decode($a['state'] ?: '{}', true) ?: [];
    $f = [
        ['name' => 'VPS', 'value' => ($a['vps'] ?: '—') . ' · ' . ($a['ip'] ?: '—'), 'inline' => true],
        ['name' => 'Home', 'value' => qq_home_text($a, $s) ?: '—', 'inline' => true],
    ];
    if ($a['bal'] !== null) {
        $f[] = ['name' => 'Số dư', 'value' => qq_money((float)$a['bal']), 'inline' => true];
    }
    return $f;
}

function qq_home_text(array $a, array $s): string
{
    $h = trim((string)($s['buildHome'] ?? ''));
    if ($h === '') {
        $h = trim((string)($s['home'] ?? ''));
    }
    $h = preg_replace('~^/~', '', $h);
    if (preg_match('~^home\s*(\S+)~i', $h, $m)) {
        return 'Home ' . $m[1];
    }
    return $h;
}

function qq_alert_once(string $key, int $everySec): bool
{
    $now = time();
    $last = (int)setting('al_' . $key, '0');
    if ($now - $last < $everySec) {
        return false;
    }
    setting_set('al_' . $key, (string)$now);
    return true;
}

function qq_check_drop(array $a, ?float $bal, int $now): void
{
    if ($bal === null || setting('alert_drop') !== '1') {
        return;
    }
    $st = db()->prepare('SELECT MAX(h) FROM m1 WHERE acc_id = ? AND t >= ?');
    $st->execute([$a['id'], $now - 600]);
    $hi = (float)$st->fetchColumn();
    $drop = $hi - $bal;
    $pct = $hi > 0 ? $drop / $hi * 100 : 0;
    if ($drop >= (float)setting('drop_min_m') * 1e6 && $pct >= (float)setting('drop_pct') && qq_alert_once('drop_' . $a['id'], 1800)) {
        qq_event((int)$a['id'], 'alert', 'Tiền giảm bất thường ' . qq_money(-$drop) . ' (' . round($pct) . '%) trong 10 phút', -$drop);
        qq_discord(['title' => '📉 ' . $a['name'] . ' · tiền giảm bất thường', 'color' => 0xE5484D,
            'description' => 'Giảm **' . qq_money($drop) . '** (' . round($pct) . '%) trong 10 phút: ' . qq_money($hi) . ' → ' . qq_money($bal),
            'fields' => qq_acc_fields($a)], true);
    }
}

function qq_check_misc(array $a, array $s, int $now): void
{
    if (setting('alert_token') === '1' && isset($s['tokenSec']) && (int)$s['tokenSec'] < 3600) {
        $left = (int)$s['tokenSec'];
        if (qq_alert_once('tok_' . $a['id'], 6 * 3600)) {
            qq_discord(['title' => '🔑 ' . $a['name'] . ' · token sắp hết hạn', 'color' => 0xF5A524,
                'description' => $left <= 0 ? 'Token **đã hết hạn**.' : 'Còn **' . qq_ago($left) . '**.', 'fields' => qq_acc_fields($a)], $left < 900);
        }
    }
    if (setting('alert_ram') === '1' && !empty($s['ramMax']) && (float)$s['ramMb'] / max(1.0, (float)$s['ramMax']) > 0.92) {
        if (qq_alert_once('ram_' . $a['id'], 3600)) {
            qq_discord(['title' => '🧠 ' . $a['name'] . ' · RAM game gần đầy', 'color' => 0xF5A524,
                'description' => 'Đang dùng ' . (int)$s['ramMb'] . ' / ' . (int)$s['ramMax'] . ' MB. Game dễ lag hoặc crash.', 'fields' => qq_acc_fields($a)]);
        }
    }
}

function qq_alert_status(array $a, int $now): void
{
    $st = $a['status'];
    $bad = in_array($st, ['off', 'crash', 'lost', 'idle'], true);
    $want = [
        'off' => setting('alert_offline') === '1', 'lost' => setting('alert_offline') === '1',
        'crash' => setting('alert_crash') === '1', 'idle' => setting('alert_idle') === '1',
    ];
    if ($bad && !empty($want[$st]) && (int)$a['demo'] === 0) {
        $grace = $st === 'idle' ? 0 : 120;
        $repeat = max(5, (int)setting('alert_repeat_min')) * 60;
        if ($now - (int)$a['status_since'] >= $grace && ($a['alert_state'] !== $st || $now - (int)$a['alert_at'] >= $repeat)) {
            [$label] = QQ_STATUS[$st];
            $icon = ['off' => '🔴', 'lost' => '⚫', 'crash' => '💥', 'idle' => '🟡'][$st];
            $note = $a['_note'] ?? qq_compute_status($a, $now)[1];
            qq_discord(['title' => $icon . ' ' . $a['name'] . ' · ' . $label, 'color' => $st === 'idle' ? 0xF5A524 : 0xE5484D,
                'description' => $note . ($a['alert_state'] === $st ? "\n(nhắc lại, đã " . qq_ago($now - (int)$a['status_since']) . ')' : ''),
                'fields' => qq_acc_fields($a), 'timestamp' => gmdate('c', $now)], $st !== 'idle');
            db()->prepare('UPDATE accounts SET alert_state = ?, alert_at = ? WHERE id = ?')->execute([$st, $now, $a['id']]);
            qq_event((int)$a['id'], 'alert', 'Đã báo Discord: ' . $label);
        }
    } elseif (!$bad && $a['alert_state'] !== '') {
        if (setting('alert_recover') === '1' && (int)$a['demo'] === 0) {
            qq_discord(['title' => '🟢 ' . $a['name'] . ' · chạy lại bình thường', 'color' => 0x3ECF7A,
                'description' => QQ_STATUS[$st][0] . ' · sau ' . qq_ago($now - (int)$a['alert_at']), 'fields' => qq_acc_fields($a)]);
        }
        db()->prepare("UPDATE accounts SET alert_state = '', alert_at = ? WHERE id = ?")->execute([$now, $a['id']]);
    }
}

/* ───────────────────────── việc chạy định kỳ ───────────────────────── */

/** Chạy tối đa 1 lần / 20 giây, gọi sau khi trả lời mod hoặc khi web được mở. Không cần cron. */
function qq_maintenance(bool $force = false): void
{
    $now = time();
    $st = db()->prepare("UPDATE settings SET v = ? WHERE k = 'maint_at' AND CAST(v AS INTEGER) < ?");
    db()->exec("INSERT OR IGNORE INTO settings (k, v) VALUES ('maint_at', '0')");
    $st->execute([(string)$now, $force ? $now + 1 : $now - 20]);
    if ($st->rowCount() !== 1) {
        return;
    }
    @set_time_limit(60);
    $accs = db()->query('SELECT * FROM accounts WHERE hidden = 0')->fetchAll();
    foreach ($accs as $a) {
        qq_set_status($a, $now);
        qq_alert_status($a, $now);
        qq_auto_restart($a, $now);
    }
    qq_daily_report($now);
    if ($now - (int)setting('prune_at', '0') > 3600) {
        setting_set('prune_at', (string)$now);
        qq_prune($now);
    }
}

function qq_auto_restart(array $a, int $now): void
{
    if ($a['status'] !== 'crash' || !(int)$a['auto_restart'] || $a['inst'] === '' || $a['vps'] === '' || (int)$a['demo']) {
        return;
    }
    if ($now - (int)$a['status_since'] < 30) {
        return;
    }
    $pend = db()->prepare("SELECT COUNT(*) FROM cmds WHERE target = 'vps' AND vps = ? AND (a = ? OR a LIKE ?) AND status IN ('new','sent') AND created > ?");
    $pend->execute([$a['vps'], $a['inst'], $a['inst'] . '|%', $now - 300]);
    if ((int)$pend->fetchColumn() > 0) {
        return;
    }
    $cnt = db()->prepare('SELECT COUNT(*) FROM restarts WHERE acc_id = ? AND t > ?');
    $cnt->execute([$a['id'], $now - 3600]);
    $n = (int)$cnt->fetchColumn();
    $max = max(0, (int)setting('restart_max_hour'));
    if ($n >= $max) {
        if ($n === $max && qq_alert_once('rsmax_' . $a['id'], 3600)) {
            qq_event((int)$a['id'], 'alert', 'Đã tự mở lại ' . $max . ' lần trong 1 giờ, dừng tự mở lại');
            qq_discord(['title' => '🛑 ' . $a['name'] . ' · crash liên tục', 'color' => 0xE5484D,
                'description' => 'Đã tự mở lại ' . $max . ' lần trong 1 giờ mà vẫn crash. Tạm dừng tự mở lại, cần xem tay.', 'fields' => qq_acc_fields($a)], true);
        }
        return;
    }
    db()->prepare('INSERT INTO restarts (acc_id, t) VALUES (?, ?)')->execute([$a['id'], $now]);
    $stt = json_decode($a['state'] ?: '{}', true) ?: [];
    $server = trim((string)($stt['server'] ?? '')) ?: setting('default_server');
    db()->prepare("INSERT INTO cmds (target, acc_id, vps, t, a, created, by_user) VALUES ('vps', ?, ?, 'launch', ?, ?, 'tự động')")->execute([$a['id'], $a['vps'], $a['inst'] . '|' . $server, $now]);
    qq_event((int)$a['id'], 'cmd', 'Tự mở lại game (lần ' . ($n + 1) . ' trong giờ này)');
}

function qq_daily_report(int $now): void
{
    $at = setting('report_time');
    if (!preg_match('~^(\d{1,2}):(\d{2})$~', $at, $m) || trim(setting('discord_webhook')) === '') {
        return;
    }
    $today = qq_today($now);
    if (setting('report_last') === $today) {
        return;
    }
    $due = qq_day_start($now) + (int)$m[1] * 3600 + (int)$m[2] * 60;
    if ($now < $due || $now - $due > 3 * 3600) {
        return;
    }
    setting_set('report_last', $today);
    $sum = qq_summary($now, null);
    $lines = [];
    foreach (array_slice($sum['top'], 0, 5) as $i => $t) {
        $lines[] = ($i + 1) . '. **' . $t['name'] . '** ' . qq_money($t['today']);
    }
    $bad = [];
    foreach ($sum['problems'] as $p) {
        $bad[] = '• ' . $p['name'] . ' — ' . $p['label'];
    }
    qq_discord(['title' => '📊 Báo cáo ngày ' . date('d/m', $now + qq_tz_offset($now)), 'color' => 0x8F5CF7,
        'fields' => [
            ['name' => 'Tổng tiền', 'value' => qq_money($sum['total']), 'inline' => true],
            ['name' => 'Profit hôm nay', 'value' => qq_money($sum['today']), 'inline' => true],
            ['name' => 'Profit / giờ', 'value' => qq_money($sum['rate']), 'inline' => true],
            ['name' => 'Acc chạy tốt', 'value' => $sum['good'] . ' / ' . $sum['count'], 'inline' => true],
            ['name' => 'Lời nhất hôm nay', 'value' => $lines ? implode("\n", $lines) : '—'],
            ['name' => 'Acc có vấn đề', 'value' => $bad ? mb_substr(implode("\n", $bad), 0, 1000) : 'Không có'],
        ]]);
}

function qq_prune(int $now): void
{
    $days = max(3, (int)setting('keep_m1_days'));
    db()->prepare('DELETE FROM m1 WHERE t < ?')->execute([$now - $days * 86400]);
    db()->prepare("DELETE FROM events WHERE t < ? AND kind IN ('sell','cmd','cmdfail','join')")->execute([$now - 30 * 86400]);
    db()->prepare('DELETE FROM events WHERE t < ?')->execute([$now - 120 * 86400]);
    db()->prepare("DELETE FROM cmds WHERE created < ?")->execute([$now - 7 * 86400]);
    db()->prepare('DELETE FROM restarts WHERE t < ?')->execute([$now - 86400]);
    db()->prepare('DELETE FROM logins WHERE t < ?')->execute([$now - 86400]);
    db()->prepare('DELETE FROM audit WHERE t < ?')->execute([$now - 180 * 86400]);
    foreach (db()->query('SELECT acc_id, file, t FROM shots')->fetchAll() as $s) {
        if ($now - (int)$s['t'] > 900) {
            @unlink(QQ_DATA . '/shots/' . basename($s['file']));
            db()->prepare('DELETE FROM shots WHERE acc_id = ?')->execute([$s['acc_id']]);
        }
    }
    foreach (glob(QQ_DATA . '/heads/*.png') ?: [] as $f) {
        if ($now - (int)@filemtime($f) > 7 * 86400) {
            @unlink($f);
        }
    }
    try {
        db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
    }
}

/* ───────────────────────── tổng hợp cho trang web ───────────────────────── */

/** Tổng quan; $accIds null = tất cả acc người dùng được xem. */
function qq_summary(int $now, ?array $user): array
{
    $accs = qq_accounts_for($user);
    $ids = array_map(fn($a) => (int)$a['id'], $accs);
    $day0 = qq_day_start($now);
    $total = 0.0;
    $count = 0;
    $good = 0;
    $stat = ['good' => 0, 'warn' => 0, 'bad' => 0];
    $byStatus = [];
    $problems = [];
    foreach ($accs as $a) {
        $count++;
        if ($a['bal'] !== null) {
            $total += (float)$a['bal'];
        }
        $cls = QQ_STATUS[$a['status']][1] ?? 'bad';
        $stat[$cls]++;
        $byStatus[$a['status']] = ($byStatus[$a['status']] ?? 0) + 1;
        if ($cls === 'good') {
            $good++;
        } else {
            $problems[] = ['name' => $a['name'], 'label' => QQ_STATUS[$a['status']][0]];
        }
    }
    $profit = qq_profit_map($ids, $day0, $now);
    $yday = qq_profit_map($ids, $day0 - 86400, $day0);
    $hour = qq_profit_map($ids, $now - 3600, $now);
    $todaySum = array_sum(array_column($profit, 'net'));
    $top = [];
    foreach ($accs as $a) {
        $top[] = ['id' => (int)$a['id'], 'name' => $a['name'], 'today' => $profit[$a['id']]['net'] ?? 0.0, 'rate' => $hour[$a['id']]['net'] ?? 0.0];
    }
    usort($top, fn($x, $y) => $y['today'] <=> $x['today']);
    return [
        'total' => $total, 'count' => $count, 'good' => $good, 'stat' => $stat, 'byStatus' => $byStatus,
        'today' => $todaySum, 'todayInc' => array_sum(array_column($profit, 'inc')),
        'yesterday' => array_sum(array_column($yday, 'net')),
        'rate' => array_sum(array_column($hour, 'net')), 'rateInc' => array_sum(array_column($hour, 'inc')),
        'top' => $top, 'problems' => $problems,
    ];
}

/** Lời theo acc trong [from, to): net = số dư cuối − số dư đầu, inc = tiền bán (Count_Money). */
function qq_profit_map(array $ids, int $from, int $to): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    // khoảng ngắn (vd 1 giờ qua) cần nến 1 phút cho chính xác; theo ngày thì nến 1 giờ là đủ và luôn còn dữ liệu
    $useM1 = $to - $from <= 6 * 3600 && $from >= time() - max(3, (int)setting('keep_m1_days')) * 86400;
    $tbl = $useM1 ? 'm1' : 'h1';
    $out = [];
    $sql = "SELECT acc_id,
              (SELECT c FROM $tbl x WHERE x.acc_id = g.acc_id AND x.t < ? AND x.c IS NOT NULL ORDER BY x.t DESC LIMIT 1) AS before_c,
              (SELECT o FROM $tbl x WHERE x.acc_id = g.acc_id AND x.t >= ? AND x.t < ? AND x.o IS NOT NULL ORDER BY x.t ASC LIMIT 1) AS first_o,
              (SELECT c FROM $tbl x WHERE x.acc_id = g.acc_id AND x.t >= ? AND x.t < ? AND x.c IS NOT NULL ORDER BY x.t DESC LIMIT 1) AS last_c,
              SUM(inc) AS inc, SUM(sells) AS sells
            FROM $tbl g WHERE acc_id IN ($in) AND t >= ? AND t < ? GROUP BY acc_id";
    $st = db()->prepare($sql);
    $st->execute([$from, $from, $to, $from, $to, $from, $to]);
    foreach ($st->fetchAll() as $r) {
        $start = $r['before_c'] ?? $r['first_o'];
        $net = ($r['last_c'] !== null && $start !== null) ? (float)$r['last_c'] - (float)$start : 0.0;
        $out[(int)$r['acc_id']] = ['net' => $net, 'inc' => (float)$r['inc'], 'sells' => (int)$r['sells']];
    }
    return $out;
}

function qq_accounts_for(?array $user): array
{
    $rows = db()->query('SELECT * FROM accounts WHERE hidden = 0 ORDER BY sort, id')->fetchAll();
    if ($user === null) {
        return $rows;
    }
    return array_values(array_filter($rows, fn($a) => qq_can_see($user, $a)));
}

/** Dữ liệu 1 acc gửi cho trang web (gọn). */
function qq_acc_public(array $a, int $now, array $profit, array $hour): array
{
    $s = json_decode($a['state'] ?: '{}', true) ?: [];
    [$label, $cls] = QQ_STATUS[$a['status']] ?? ['?', 'bad'];
    $note = qq_compute_status($a, $now)[1];
    return [
        'id' => (int)$a['id'], 'uuid' => $a['uuid'], 'name' => $a['name'], 'inst' => $a['inst'], 'vps' => $a['vps'], 'ip' => $a['ip'],
        'sort' => (int)$a['sort'], 'grp' => $a['grp'], 'tags' => $a['tags'], 'note' => $a['note'],
        'checked' => $a['checked_day'] === qq_today($now), 'cost' => (float)$a['cost'], 'vpsExpire' => $a['vps_expire'],
        'autoRestart' => (int)$a['auto_restart'], 'demo' => (int)$a['demo'],
        'status' => $a['status'], 'label' => $label, 'cls' => $cls, 'statusNote' => $note, 'statusSince' => (int)$a['status_since'],
        'lastSeen' => (int)$a['last_seen'], 'bal' => $a['bal'] === null ? null : (float)$a['bal'],
        'today' => $profit[$a['id']]['net'] ?? 0.0, 'todayInc' => $profit[$a['id']]['inc'] ?? 0.0, 'todaySells' => $profit[$a['id']]['sells'] ?? 0,
        'rate' => $hour[$a['id']]['net'] ?? 0.0, 'rateInc' => $hour[$a['id']]['inc'] ?? 0.0,
        'home' => qq_home_text($a, $s), 'pos' => isset($s['x']) ? $s['x'] . ' ' . $s['y'] . ' ' . $s['z'] : '',
        'server' => $s['server'] ?? '', 'region' => $s['region'] ?? '', 'mode' => qq_mode_text($s),
        'tokenSec' => isset($s['tokenSec']) ? (int)$s['tokenSec'] - max(0, $now - (int)($s['at'] ?? $now)) : null,
        'ramMb' => $s['ramMb'] ?? null, 'ramMax' => $s['ramMax'] ?? null, 'fps' => $s['fps'] ?? null, 'hp' => $s['hp'] ?? null,
        'online' => !empty($s['online']), 'ver' => $s['v'] ?? '', 'sellMode' => (int)($s['sellMode'] ?? 0), 'build' => !empty($s['build']),
        'buildPct' => $s['buildPct'] ?? null, 'counting' => (int)($s['counting'] ?? 0),
    ];
}

function qq_mode_text(array $s): string
{
    $m = [];
    if (!empty($s['sellMode'])) {
        $m[] = (string)($s['sellName'] ?? 'AutoSell');
    }
    if (!empty($s['axe'])) {
        $m[] = 'AxeSell';
    }
    if (!empty($s['build'])) {
        $m[] = 'AutoBuild';
    }
    if (!empty($s['mine'])) {
        $m[] = 'AutoMine';
    }
    return implode(' · ', $m);
}
