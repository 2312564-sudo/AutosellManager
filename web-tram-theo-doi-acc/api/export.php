<?php
/* Xuất CSV (mở bằng Excel): mỗi dòng = 1 acc × 1 ngày. */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$u = qq_require_user('view');
$now = time();
$days = max(1, min(120, (int)($_GET['days'] ?? 30)));
$accs = qq_accounts_for($u);
$ids = array_map(fn($a) => (int)$a['id'], $accs);
$d0 = qq_day_start($now);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="loi-nhuan-' . qq_today($now) . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Ngày', 'Acc', 'Nhóm', 'VPS', 'Số dư cuối ngày', 'Lời (đổi số dư)', 'Tiền bán (Count_Money)', 'Số lần bán']);
for ($i = $days - 1; $i >= 0; $i--) {
    $from = $d0 - $i * 86400;
    $m = qq_profit_map($ids, $from, $from + 86400);
    $date = qq_today($from + 3600);
    foreach ($accs as $a) {
        $id = (int)$a['id'];
        $c = db()->prepare('SELECT c FROM h1 WHERE acc_id = ? AND t < ? AND c IS NOT NULL ORDER BY t DESC LIMIT 1');
        $c->execute([$id, $from + 86400]);
        $close = $c->fetchColumn();
        if ($close === false && !isset($m[$id])) {
            continue;
        }
        fputcsv($out, [$date, $a['name'], $a['grp'], $a['vps'], $close === false ? '' : round((float)$close), round($m[$id]['net'] ?? 0), round($m[$id]['inc'] ?? 0), $m[$id]['sells'] ?? 0]);
    }
}
fclose($out);
