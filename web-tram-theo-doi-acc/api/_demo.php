<?php
/*
 * Dữ liệu mẫu: 12 acc giả + 30 ngày lịch sử, để xem web trước khi gắn acc thật.
 * Acc mẫu có demo = 1, không báo Discord, xoá được bằng 1 nút trong Cài đặt.
 */
declare(strict_types=1);
require_once __DIR__ . '/_core.php';

function qq_demo_names(): array
{
    return ['eebnah', 'RicksMaster69', 'DragoBoi_9265', 'JTSTA', 'Artox_73', 'noe863', 'Flow_YENA', 'MystixT', 'Tr1pl4', 'josephgreen54', 'NotDipanshu', 'BIGKDOG69'];
}

function qq_demo_create(): void
{
    qq_demo_clear();
    $pdo = db();
    $now = time();
    mt_srand(4242);
    $states = ['sell', 'sell', 'sell', 'idle', 'sell', 'off', 'sell', 'sell', 'crash', 'sell', 'sell', 'sell'];
    $pdo->beginTransaction();
    $hourTotals = [];
    $minTotals = [];
    $vpsInfo = [];
    foreach (qq_demo_names() as $i => $name) {
        $vps = 'VPS-DEMO-' . str_pad((string)(intdiv($i, 3) + 1), 2, '0', STR_PAD_LEFT);
        $inst = '1.21.11-SELL(' . ($i % 3 + 1) . ')';
        $rate = (0.18 + mt_rand(0, 34) / 100) * 1e6;
        $final = (12 + mt_rand(0, 520) / 10) * 1e6;
        $st = $states[$i];
        $home = 'home ' . (($i % 4) + 1);
        $state = [
            'v' => 'R491', 'online' => in_array($st, ['sell', 'idle'], true) ? 1 : 0, 'server' => 'donutsmp.net', 'region' => $i % 2 ? 'ASIA' : 'EU CENTRAL',
            'x' => mt_rand(-48000, 48000), 'y' => 64 + mt_rand(-20, 30), 'z' => mt_rand(-180000, 180000), 'dim' => 'minecraft:overworld',
            'hp' => 20.0, 'ramMb' => 1700 + mt_rand(0, 700), 'ramMax' => 2560, 'fps' => 30 + mt_rand(0, 30), 'tokenSec' => mt_rand(2, 23) * 3600 + mt_rand(0, 3599),
            'counting' => 1, 'sellMode' => 1, 'sellName' => 'AutoSell (Option1)', 'home' => $home, 'joinSec' => mt_rand(1800, 20000),
        ];
        if ($st === 'off') {
            $state['disc'] = 'Timed out';
            $state['discAgo'] = 840;
        }
        $lastSeen = $st === 'crash' ? $now - 300 : $now - mt_rand(2, 25);
        $gain = $st === 'idle' ? $now - 41 * 60 : $now - mt_rand(10, 200);
        $sql = 'INSERT INTO accounts (uuid, name, inst, vps, host, ip, sort, grp, created, last_seen, state, bal, bal_at, sid, cstart, last_gain_at, status, status_since, demo, cost, auto_restart)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 1)';
        $insertAccount = $pdo->prepare(qq_uses_postgres() ? $sql . ' RETURNING id' : $sql);
        $insertAccount->execute(['demo-' . $name, $name, $inst, $vps, 'WIN-' . strtoupper(substr(md5($vps), 0, 6)), '203.0.113.' . (20 + $i * 7), $i + 1, $i % 2 ? 'asia' : 'east',
            $now - 40 * 86400, $lastSeen, json_encode($state), $final, $now, 'demo', 1, $gain, $st, $now - mt_rand(60, 3000), 80000 + mt_rand(0, 4) * 10000]);
        $id = (int)(qq_uses_postgres() ? $insertAccount->fetchColumn() : $pdo->lastInsertId());
        $vpsInfo[$vps][] = ['name' => $inst, 'running' => $st === 'crash' ? 0 : 1, 'ramMb' => 2100 + mt_rand(0, 400), 'cpu' => 0, 'managed' => 1];

        // 30 ngày nến giờ, đi ngược từ số dư cuối
        $hours = 30 * 24;
        $h0 = intdiv($now, 3600) * 3600;
        $series = [];
        $v = $final;
        for ($k = 0; $k < $hours; $k++) {
            $t = $h0 - $k * 3600;
            $localH = (int)gmdate('G', $t + 7 * 3600);
            $busy = ($localH >= 18 || $localH <= 1) ? 1.35 : (($localH >= 3 && $localH <= 8) ? 0.55 : 1.0);
            $inc = $rate * $busy * (0.55 + mt_rand(0, 90) / 100);
            if (($st === 'off' || $st === 'crash') && $k < 2) {
                $inc = 0;
            }
            $spend = mt_rand(0, 100) < 9 ? $rate * (0.6 + mt_rand(0, 220) / 100) : 0.0;
            $open = $v - $inc + $spend;
            $series[$t] = ['o' => $open, 'c' => $v, 'h' => max($open, $v) + $rate * mt_rand(0, 25) / 100, 'l' => min($open, $v) - $rate * mt_rand(0, 25) / 100, 'inc' => $inc, 's' => $inc > 0 ? (int)round($inc / 40000) : 0];
            $v = $open;
        }
        $ins = $pdo->prepare('INSERT INTO h1 (acc_id, t, o, h, l, c, inc, sells) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($series as $t => $r) {
            $ins->execute([$id, $t, $r['o'], $r['h'], $r['l'], $r['c'], $r['inc'], $r['s']]);
            $ht = &$hourTotals[$t];
            $ht = $ht ?? ['o' => 0.0, 'h' => 0.0, 'l' => 0.0, 'c' => 0.0, 'inc' => 0.0, 's' => 0];
            foreach (['o', 'h', 'l', 'c', 'inc'] as $f) {
                $ht[$f] += $r[$f];
            }
            $ht['s'] += $r['s'];
            unset($ht);
        }
        // 2 ngày nến phút, chia nhỏ từng giờ
        $insm = $pdo->prepare('INSERT INTO m1 (acc_id, t, o, h, l, c, inc, sells) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($series as $t => $r) {
            if ($t < $now - 48 * 3600) {
                continue;
            }
            $cur = $r['o'];
            $step = ($r['c'] - $r['o']) / 60;
            for ($m = 0; $m < 60; $m++) {
                $mt = $t + $m * 60;
                if ($mt > $now) {
                    break;
                }
                $o = $cur;
                $c = $m === 59 ? $r['c'] : $cur + $step * (0.3 + mt_rand(0, 140) / 100);
                $inc = max(0.0, $r['inc'] / 60 * (0.2 + mt_rand(0, 160) / 100));
                $hi = max($o, $c) + abs($step) * mt_rand(0, 60) / 100;
                $lo = min($o, $c) - abs($step) * mt_rand(0, 60) / 100;
                $insm->execute([$id, $mt, $o, $hi, $lo, $c, $inc, $inc > 0 ? (mt_rand(0, 3) === 0 ? 1 : 0) : 0]);
                $mtot = &$minTotals[$mt];
                $mtot = $mtot ?? ['o' => 0.0, 'h' => 0.0, 'l' => 0.0, 'c' => 0.0, 'inc' => 0.0, 's' => 0];
                $mtot['o'] += $o;
                $mtot['h'] += $hi;
                $mtot['l'] += $lo;
                $mtot['c'] += $c;
                $mtot['inc'] += $inc;
                unset($mtot);
                $cur = $c;
            }
        }
        qq_event($id, 'join', 'Vào server donutsmp.net', null, $now - 5 * 3600);
        for ($e = 0; $e < 6; $e++) {
            qq_event($id, 'sell', 'Bán được tiền', $rate / 8 * (0.6 + mt_rand(0, 80) / 100), $now - $e * 420 - mt_rand(0, 200));
        }
        if ($st === 'off') {
            qq_event($id, 'disc', 'Mất kết nối: Timed out', null, $now - 840);
        }
        if ($st === 'crash') {
            qq_event($id, 'status', 'Game crash · Game đã tắt · VPS còn sống', null, $now - 240);
        }
    }
    $ins = $pdo->prepare(qq_uses_postgres()
        ? 'INSERT INTO h1 (acc_id, t, o, h, l, c, inc, sells) VALUES (0, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (acc_id, t) DO UPDATE SET o = EXCLUDED.o, h = EXCLUDED.h, l = EXCLUDED.l, c = EXCLUDED.c, inc = EXCLUDED.inc, sells = EXCLUDED.sells'
        : 'INSERT OR REPLACE INTO h1 (acc_id, t, o, h, l, c, inc, sells) VALUES (0, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($hourTotals as $t => $r) {
        $ins->execute([$t, $r['o'], $r['h'], $r['l'], $r['c'], $r['inc'], $r['s']]);
    }
    $ins = $pdo->prepare(qq_uses_postgres()
        ? 'INSERT INTO m1 (acc_id, t, o, h, l, c, inc, sells) VALUES (0, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (acc_id, t) DO UPDATE SET o = EXCLUDED.o, h = EXCLUDED.h, l = EXCLUDED.l, c = EXCLUDED.c, inc = EXCLUDED.inc, sells = EXCLUDED.sells'
        : 'INSERT OR REPLACE INTO m1 (acc_id, t, o, h, l, c, inc, sells) VALUES (0, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($minTotals as $t => $r) {
        $ins->execute([$t, $r['o'], $r['h'], $r['l'], $r['c'], $r['inc'], $r['s']]);
    }
    foreach ($vpsInfo as $vps => $insts) {
        $sql = qq_uses_postgres()
            ? 'INSERT INTO vps (name, host, ip, last_seen, info, cost, note) VALUES (?, ?, ?, ?, ?, 250000, ?) ON CONFLICT (name) DO UPDATE SET host = EXCLUDED.host, ip = EXCLUDED.ip, last_seen = EXCLUDED.last_seen, info = EXCLUDED.info, cost = EXCLUDED.cost, note = EXCLUDED.note'
            : 'INSERT OR REPLACE INTO vps (name, host, ip, last_seen, info, cost, note) VALUES (?, ?, ?, ?, ?, 250000, ?)';
        $pdo->prepare($sql)
            ->execute([$vps, 'WIN-' . strtoupper(substr(md5($vps), 0, 6)), '203.0.113.' . (100 + (int)substr($vps, -1)), $now - 20,
                json_encode(['ver' => '1.0', 'instances' => $insts, 'freeRamMb' => 600, 'totalRamMb' => 4096, 'cpu' => 38, 'uptimeSec' => 86400 * 3, 'prism' => 1]), 'VPS mẫu']);
    }
    setting_set('demo_at', (string)$now);
    $pdo->commit();
}

function qq_demo_clear(): void
{
    $pdo = db();
    $ids = $pdo->query('SELECT id FROM accounts WHERE demo = 1')->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        return;
    }
    $in = implode(',', array_map('intval', $ids));
    $pdo->beginTransaction();
    foreach (['m1', 'h1', 'events', 'cmds', 'restarts', 'shots'] as $t) {
        $pdo->exec("DELETE FROM $t WHERE acc_id IN ($in)");
    }
    $pdo->exec("DELETE FROM accounts WHERE id IN ($in)");
    $pdo->exec("DELETE FROM vps WHERE name LIKE 'VPS-DEMO-%'");
    $real = (int)$pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    if ($real === 0) {
        $pdo->exec('DELETE FROM m1 WHERE acc_id = 0');
        $pdo->exec('DELETE FROM h1 WHERE acc_id = 0');
    }
    $pdo->commit();
}

/** Cho acc mẫu "sống": mỗi lần mở web (tối đa 1 lần / 25 s) cộng thêm tiền như đang bán thật. */
function qq_demo_tick(int $now): void
{
    $last = (int)setting('demo_at', '0');
    if ($last === 0 || $now - $last < 25) {
        return;
    }
    $accs = db()->query("SELECT * FROM accounts WHERE demo = 1 AND status IN ('sell','idle','off')")->fetchAll();
    if (!$accs) {
        return;
    }
    setting_set('demo_at', (string)$now);
    $dt = min(600, $now - $last);
    $pdo = db();
    $pdo->beginTransaction();
    $sumInc = 0.0;
    $sumSells = 0;
    foreach ($accs as $a) {
        $prev = (float)$a['bal'];
        $inc = 0.0;
        if ($a['status'] === 'sell') {
            $rate = 0.3e6;
            $inc = $rate / 3600 * $dt * (0.4 + mt_rand(0, 120) / 100);
        }
        $bal = $prev + $inc - (mt_rand(0, 100) < 4 ? $inc * 3 : 0);
        $pdo->prepare('UPDATE accounts SET bal = ?, bal_at = ?, last_seen = ?, last_gain_at = CASE WHEN ? > 0 THEN ? ELSE last_gain_at END WHERE id = ?')
            ->execute([$bal, $now, $now - mt_rand(1, 15), $inc, $now, $a['id']]);
        qq_candle((int)$a['id'], $now, $bal, $prev, $inc, $inc > 0 ? 1 : 0);
        $sumInc += $inc;
        $sumSells += $inc > 0 ? 1 : 0;
    }
    $pdo->exec("UPDATE vps SET last_seen = $now WHERE name LIKE 'VPS-DEMO-%'");
    qq_candle(0, $now, qq_total_bal(), qq_last_close(0), $sumInc, $sumSells);
    $pdo->commit();
}
