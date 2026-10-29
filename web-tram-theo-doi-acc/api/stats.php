<?php
/* Thống kê: lời theo ngày, xếp hạng tuần, khung giờ bán chạy, giá bán trung bình, lời thật sau chi phí. */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$u = qq_require_user('view');
$now = time();
$accs = qq_accounts_for($u);
$ids = array_map(fn($a) => (int)$a['id'], $accs);
$byId = [];
foreach ($accs as $a) {
    $byId[(int)$a['id']] = $a;
}
$d0 = qq_day_start($now);
$days = max(7, min(90, (int)($_GET['days'] ?? 30)));

$daily = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $from = $d0 - $i * 86400;
    $m = qq_profit_map($ids, $from, $from + 86400);
    $daily[] = ['t' => $from + qq_tz_offset($from), 'net' => array_sum(array_column($m, 'net')), 'inc' => array_sum(array_column($m, 'inc')), 'sells' => array_sum(array_column($m, 'sells'))];
}

$rank = [];
$week = qq_profit_map($ids, $now - 7 * 86400, $now + 1);
$month = qq_profit_map($ids, $now - 30 * 86400, $now + 1);
$up = [];
if ($ids) {
    $in = implode(',', $ids);
    foreach (db()->query("SELECT acc_id, COUNT(*) n FROM h1 WHERE acc_id IN ($in) AND t >= " . ($now - 7 * 86400) . ' GROUP BY acc_id') as $r) {
        $up[(int)$r['acc_id']] = min(1.0, (int)$r['n'] / 168);
    }
}
$vnd = (float)setting('vnd_per_m');
foreach ($accs as $a) {
    $id = (int)$a['id'];
    $w = $week[$id] ?? ['net' => 0.0, 'inc' => 0.0, 'sells' => 0];
    $mo = $month[$id] ?? ['net' => 0.0, 'inc' => 0.0, 'sells' => 0];
    $rank[] = [
        'id' => $id, 'name' => $a['name'], 'uuid' => $a['uuid'], 'grp' => $a['grp'], 'net7' => $w['net'], 'inc7' => $w['inc'], 'sells7' => $w['sells'],
        'perHour7' => $w['net'] / 168, 'uptime7' => $up[$id] ?? 0.0, 'net30' => $mo['net'], 'cost' => (float)$a['cost'],
        'real30' => $vnd > 0 ? $mo['net'] / 1e6 * $vnd - (float)$a['cost'] : null,
    ];
}
usort($rank, fn($x, $y) => $y['net7'] <=> $x['net7']);

// khung giờ: lời trung bình theo giờ trong ngày (14 ngày)
$hours = array_fill(0, 24, 0.0);
$cnt = array_fill(0, 24, []);
if ($ids) {
    $in = implode(',', $ids);
    $off = qq_tz_offset($now);
    foreach (db()->query("SELECT t, o, c, inc FROM h1 WHERE acc_id IN ($in) AND t >= " . ($now - 14 * 86400)) as $r) {
        $lt = (int)$r['t'] + $off;
        $h = intdiv($lt % 86400, 3600);
        $v = (float)$r['inc'] > 0 ? (float)$r['inc'] : max(0.0, (float)$r['c'] - (float)$r['o']);
        $hours[$h] += $v;
        $cnt[$h][intdiv($lt, 86400)] = 1;
    }
}
$hourly = [];
for ($h = 0; $h < 24; $h++) {
    $hourly[] = ['h' => $h, 'avg' => count($cnt[$h]) ? $hours[$h] / count($cnt[$h]) : 0.0];
}

// giá bán trung bình mỗi lần bán theo ngày
$price = [];
foreach ($daily as $d) {
    $price[] = ['t' => $d['t'], 'avg' => $d['sells'] > 0 ? $d['inc'] / $d['sells'] : null];
}

$vpsCost = (float)db()->query('SELECT COALESCE(SUM(cost), 0) FROM vps')->fetchColumn();
$accCost = array_sum(array_map(fn($a) => (float)$a['cost'], $accs));
$net30 = array_sum(array_column($month, 'net'));
qq_json([
    'ok' => 1, 'daily' => $daily, 'rank' => $rank, 'hourly' => $hourly, 'price' => $price,
    'money' => ['net30' => $net30, 'vndPerM' => $vnd, 'cost' => $accCost + $vpsCost, 'real30' => $vnd > 0 ? $net30 / 1e6 * $vnd - ($accCost + $vpsCost) : null],
]);
