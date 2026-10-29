<?php
/* Cài đặt lần đầu: kiểm tra hosting, tạo tài khoản chủ web. Sau khi đã có tài khoản thì trang này tự chuyển về đăng nhập. */
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';
require __DIR__ . '/api/_view.php';

$checks = [
    ['PHP 7.4 trở lên (đang có ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '7.4.0', '>=')],
    ['PDO SQLite (lưu dữ liệu, không cần tạo database)', class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true)],
    ['Thư mục data/ ghi được', (function () { qq_data_dir(); return is_writable(QQ_DATA); })()],
    ['cURL (gửi Discord, tải skin head)', function_exists('curl_init') || ini_get('allow_url_fopen')],
];
$allOk = !in_array(false, array_column($checks, 1), true);
if ($allOk && qq_installed()) {
    header('Location: login.php');
    exit;
}
$err = '';
if ($allOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['user'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    $site = trim((string)($_POST['site'] ?? '')) ?: 'Trạm theo dõi acc';
    if (!preg_match('~^[\p{L}\p{N}_.-]{3,32}$~u', $user)) {
        $err = 'Tên đăng nhập 3–32 ký tự, không có dấu cách.';
    } elseif (mb_strlen($pass) < 6) {
        $err = 'Mật khẩu cần ít nhất 6 ký tự.';
    } elseif ($pass !== (string)($_POST['pass2'] ?? '')) {
        $err = 'Hai lần nhập mật khẩu không giống nhau.';
    } else {
        db()->prepare('INSERT INTO users (username, pass, role, created) VALUES (?, ?, ?, ?)')->execute([$user, password_hash($pass, PASSWORD_DEFAULT), 'owner', time()]);
        setting_set('site_name', mb_substr($site, 0, 60));
        qq_api_key();
        if (!empty($_POST['demo'])) {
            require __DIR__ . '/api/_demo.php';
            qq_demo_create();
        }
        qq_session();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)db()->query("SELECT id FROM users WHERE username = " . db()->quote($user))->fetchColumn();
        header('Location: index.php#caidat');
        exit;
    }
}
qq_head('Cài đặt · Trạm theo dõi acc');
qq_sky();
?>
<main class="auth"><form method="post" class="glass2 box" autocomplete="off">
  <div>
    <h1>Cài đặt lần đầu</h1>
    <p class="muted" style="margin:6px 0 0">Tạo tài khoản chủ web. Sau bước này web dùng được ngay.</p>
  </div>
  <div class="checks">
    <?php foreach ($checks as [$label, $ok]): ?>
      <span class="<?= $ok ? 'good' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?> <?= htmlspecialchars($label) ?></span>
    <?php endforeach; ?>
  </div>
  <?php if (!$allOk): ?>
    <div class="err">Hosting còn thiếu mục đánh dấu ✗. Vào bảng điều khiển hosting (cPanel → Select PHP Version) bật pdo_sqlite / curl, hoặc đặt quyền ghi 755 cho thư mục <b>data</b>, rồi tải lại trang.</div>
  <?php else: ?>
    <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <label class="field"><span>Tên web</span><input class="input" name="site" value="<?= htmlspecialchars((string)($_POST['site'] ?? 'Trạm theo dõi acc')) ?>"></label>
    <label class="field"><span>Tên đăng nhập</span><input class="input" name="user" required minlength="3" maxlength="32" value="<?= htmlspecialchars((string)($_POST['user'] ?? '')) ?>"></label>
    <label class="field"><span>Mật khẩu (ít nhất 6 ký tự)</span><input class="input" type="password" name="pass" required minlength="6"></label>
    <label class="field"><span>Nhập lại mật khẩu</span><input class="input" type="password" name="pass2" required minlength="6"></label>
    <label class="check"><input type="checkbox" name="demo" value="1" checked> Tạo 12 acc mẫu để xem thử (xoá được trong Cài đặt)</label>
    <button class="btn pri" type="submit">Tạo tài khoản và vào web</button>
  <?php endif; ?>
</form></main>
</body></html>
