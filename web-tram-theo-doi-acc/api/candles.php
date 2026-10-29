<?php
/* Nến cho biểu đồ: acc=all | id, tf = 1m 5m 15m 30m 1h 4h 1d 3d 1w. Thời gian trả về đã cộng múi giờ (để biểu đồ hiện giờ địa phương). */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$u = qq_require_user('view');
$now = time();
$tfs = ['1m' => 60, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '4h' => 14400, '1d' => 86400, '3d' => 259200, '1w' => 604800];
$tf = (string)($_GET['tf'] ?? '1h');
if (!isset($tfs[$tf])) {
    $tf = '1h';
}
$size = $tfs[$tf];
$limit = max(20, min(1500, (int)($_GET['limit'] ?? 500)));
$before = (int)($_GET['before'] ?? 0);
$to = $before > 0 ? $before - qq_tz_offset($before) : $now + $size;
$from = $to - $size * $limit;
$tbl = $size < 3600 ? 'm1' : 'h1';
$accParam = (string)($_GET['acc'] ?? 'all');
$off = qq_tz_offset($now);

$ids = [];
if ($accParam === 'all') {
    if (qq_user_groups($u)) {
        foreach (qq_accounts_for($u) as $a) {
            $ids[] = (int)$a['id'];
        }
    } else {
        $ids = [0];
    }
} else {
    $st = db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([(int)$accParam]);
    $a = $st->fetch();
    if (!$a || !qq_can_see($u, $a)) {
        qq_fail(404, 'Không thấy acc');
    }
    $ids = [(int)$a['id']];
}

$bucket = function (int $t) use ($size, $off): int {
    if ($size >= 604800) {
        return intdiv($t + $off + 3 * 86400, $size) * $size - 3 * 86400;
    }
    if ($size >= 86400) {
        return intdiv($t + $off, $size) * $size;
    }
    return intdiv($t + $off, $size) * $size;
};

$out = [];
if (count($ids) === 1) {
    $st = db()->prepare("SELECT t, o, h, l, c, inc, sells FROM $tbl WHERE acc_id = ? AND t >= ? AND t < ? ORDER BY t");
    $st->execute([$ids[0], $from, $to]);
    foreach ($st as $r) {
        $b = $bucket((int)$r['t']);
        if (!isset($out[$b])) {
            if ($r['o'] === null) {
                $out[$b] = ['t' => $b, 'o' => null, 'h' => null, 'l' => null, 'c' => null, 'v' => 0.0, 's' => 0];
            } else {
                $out[$b] = ['t' => $b, 'o' => (float)$r['o'], 'h' => (float)$r['h'], 'l' => (float)$r['l'], 'c' => (float)$r['c'], 'v' => 0.0, 's' => 0];
            }
        } elseif ($r['o'] !== null) {
            $k = &$out[$b];
            if ($k['o'] === null) {
                $k['o'] = (float)$r['o'];
                $k['h'] = (float)$r['h'];
                $k['l'] = (float)$r['l'];
            }
            $k['h'] = max($k['h'], (float)$r['h']);
            $k['l'] = min($k['l'], (float)$r['l']);
            $k['c'] = (float)$r['c'];
            unset($k);
        }
        $out[$b]['v'] += (float)$r['inc'];
        $out[$b]['s'] += (int)$r['sells'];
    }
} elseif ($ids) {
    // người dùng chỉ xem 1 nhóm acc: cộng nến từng acc, acc không có dữ liệu trong khung giữ số dư cuối
    $in = implode(',', array_map('intval', $ids));
    $per = [];
    $st = db()->prepare("SELECT acc_id, t, o, h, l, c, inc, sells FROM $tbl WHERE acc_id IN ($in) AND t >= ? AND t < ? ORDER BY t");
    $st->execute([$from, $to]);
    foreach ($st as $r) {
        if ($r['c'] === null) {
            continue;
        }
        $b = $bucket((int)$r['t']);
        $id = (int)$r['acc_id'];
        if (!isset($per[$b][$id])) {
            $per[$b][$id] = ['o' => (float)$r['o'], 'h' => (float)$r['h'], 'l' => (float)$r['l'], 'c' => (float)$r['c'], 'v' => 0.0, 's' => 0];
        }
        $p = &$per[$b][$id];
        $p['h'] = max($p['h'], (float)$r['h']);
        $p['l'] = min($p['l'], (float)$r['l']);
        $p['c'] = (float)$r['c'];
        $p['v'] += (float)$r['inc'];
        $p['s'] += (int)$r['sells'];
        unset($p);
    }
    ksort($per);
    $last = [];
    foreach ($per as $b => $accs) {
        $o = $h = $l = $c = 0.0;
        $v = 0.0;
        $s = 0;
        foreach ($accs as $id => $p) {
            $last[$id] = $p['c'];
        }
        foreach ($last as $id => $lc) {
            if (isset($accs[$id])) {
                $p = $accs[$id];
                $o += $p['o'];
                $h += $p['h'];
                $l += $p['l'];
                $c += $p['c'];
                $v += $p['v'];
                $s += $p['s'];
            } else {
                $o += $lc;
                $h += $lc;
                $l += $lc;
                $c += $lc;
            }
        }
        $out[$b] = ['t' => $b, 'o' => $o, 'h' => $h, 'l' => $l, 'c' => $c, 'v' => $v, 's' => $s];
    }
}
// nối nến: giá mở = giá đóng nến trước (tránh khoảng trống khi game tắt)
ksort($out);
$res = [];
$prevC = null;
foreach ($out as $k) {
    if ($k['o'] === null) {
        if ($prevC === null) {
            continue;
        }
        $k['o'] = $k['h'] = $k['l'] = $k['c'] = $prevC;
    }
    $res[] = ['t' => $k['t'], 'o' => round($k['o'], 2), 'h' => round($k['h'], 2), 'l' => round($k['l'], 2), 'c' => round($k['c'], 2), 'v' => round($k['v'], 2), 's' => $k['s']];
    $prevC = $k['c'];
}
$more = false;
if ($ids) {
    $in = implode(',', array_map('intval', $ids));
    $more = (bool)db()->query("SELECT 1 FROM $tbl WHERE acc_id IN ($in) AND t < " . (int)$from . ' LIMIT 1')->fetchColumn();
}
qq_json(['ok' => 1, 'tf' => $tf, 'size' => $size, 'candles' => $res, 'more' => $more]);
