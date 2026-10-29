<?php
/*
 * Ảnh chụp màn hình.
 *  - Mod gửi ảnh lên (POST, có X-QQ-Key, body = JPEG).
 *  - Trang web xem ảnh (GET ?acc=ID), hỏi trạng thái (GET ?acc=ID&cmd=ID&info=1), xoá khi đóng (POST {acc, del:1}).
 * Mỗi acc chỉ giữ 1 ảnh; ảnh tự xoá sau 15 phút.
 */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_QQ_KEY'])) {
    qq_require_key();
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) < 100 || strlen($raw) > 4 * 1024 * 1024 || substr($raw, 0, 2) !== "\xFF\xD8") {
        qq_fail(400, 'Ảnh không hợp lệ');
    }
    $st = $pdo->prepare('SELECT id FROM accounts WHERE uuid = ?');
    $st->execute([(string)($_GET['uuid'] ?? '')]);
    $accId = (int)$st->fetchColumn();
    if (!$accId) {
        qq_fail(404, 'Không thấy acc');
    }
    qq_data_dir();
    $old = $pdo->prepare('SELECT file FROM shots WHERE acc_id = ?');
    $old->execute([$accId]);
    if ($f = $old->fetchColumn()) {
        @unlink(QQ_DATA . '/shots/' . basename((string)$f));
    }
    $file = $accId . '_' . bin2hex(random_bytes(8)) . '.jpg';
    if (@file_put_contents(QQ_DATA . '/shots/' . $file, $raw) === false) {
        qq_fail(500, 'Không ghi được ảnh (thư mục data/shots chưa cho ghi)');
    }
    $pdo->prepare('INSERT OR REPLACE INTO shots (acc_id, cmd_id, file, t) VALUES (?, ?, ?, ?)')->execute([$accId, (int)($_GET['cmd'] ?? 0), $file, time()]);
    qq_json(['ok' => 1]);
    exit;
}

$u = qq_require_user('view');
$accId = (int)($_GET['acc'] ?? (qq_body()['acc'] ?? 0));
$acc = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
$acc->execute([$accId]);
$a = $acc->fetch();
if (!$a || !qq_can_see($u, $a)) {
    qq_fail(404, 'Không thấy acc');
}
$st = $pdo->prepare('SELECT * FROM shots WHERE acc_id = ?');
$st->execute([$accId]);
$shot = $st->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = qq_body();
    if (!empty($b['del'])) {
        if ($shot) {
            @unlink(QQ_DATA . '/shots/' . basename($shot['file']));
            $pdo->prepare('DELETE FROM shots WHERE acc_id = ?')->execute([$accId]);
        }
        qq_json(['ok' => 1]);
        exit;
    }
    qq_fail(400, 'Thiếu thao tác');
}

if (!empty($_GET['info'])) {
    $cmdId = (int)($_GET['cmd'] ?? 0);
    $c = $pdo->prepare('SELECT status, result FROM cmds WHERE id = ? AND acc_id = ?');
    $c->execute([$cmdId, $accId]);
    $cmd = $c->fetch() ?: ['status' => '', 'result' => ''];
    $ready = $shot && (int)$shot['cmd_id'] === $cmdId && is_file(QQ_DATA . '/shots/' . basename($shot['file']));
    qq_json(['ok' => 1, 'ready' => $ready, 'cmdStatus' => $cmd['status'], 'result' => $cmd['result'], 't' => $shot ? (int)$shot['t'] : 0]);
    exit;
}

if (!$shot || !is_file(QQ_DATA . '/shots/' . basename($shot['file']))) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: no-store');
readfile(QQ_DATA . '/shots/' . basename($shot['file']));
