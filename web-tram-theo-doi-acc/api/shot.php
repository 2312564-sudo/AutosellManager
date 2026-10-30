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
    $old = $pdo->prepare('SELECT file FROM shots WHERE acc_id = ?');
    $old->execute([$accId]);
    $oldFile = $old->fetchColumn();
    $file = qq_blob_upload('shots/' . $accId . '_' . bin2hex(random_bytes(8)) . '.jpg', $raw, 'image/jpeg');
    $sql = qq_uses_postgres()
        ? 'INSERT INTO shots (acc_id, cmd_id, file, t) VALUES (?, ?, ?, ?) ON CONFLICT (acc_id) DO UPDATE SET cmd_id = EXCLUDED.cmd_id, file = EXCLUDED.file, t = EXCLUDED.t'
        : 'INSERT OR REPLACE INTO shots (acc_id, cmd_id, file, t) VALUES (?, ?, ?, ?)';
    $pdo->prepare($sql)->execute([$accId, (int)($_GET['cmd'] ?? 0), $file, time()]);
    if ($oldFile) {
        qq_blob_delete((string)$oldFile);
    }
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
            qq_blob_delete((string)$shot['file']);
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
    $ready = $shot && (int)$shot['cmd_id'] === $cmdId;
    qq_json(['ok' => 1, 'ready' => $ready, 'cmdStatus' => $cmd['status'], 'result' => $cmd['result'], 't' => $shot ? (int)$shot['t'] : 0]);
    exit;
}

if (!$shot) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: no-store');
echo qq_blob_read((string)$shot['file']);
