<?php
declare(strict_types=1);
require __DIR__ . '/api/_boot.php';
require __DIR__ . '/api/_view.php';

if (!qq_installed()) {
    header('Location: setup.php');
    exit;
}
if (qq_user()) {
    header('Location: index.php');
    exit;
}
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = qq_ip();
    $st = db()->prepare('SELECT COUNT(*) FROM logins WHERE ip = ? AND t > ?');
    $st->execute([$ip, time() - 900]);
    if ((int)$st->fetchColumn() >= 10) {
        $err = 'Sai quá nhiều lần. Thử lại sau 15 phút.';
    } else {
        $u = db()->prepare('SELECT id, pass FROM users WHERE username = ?');
        $u->execute([trim((string)($_POST['user'] ?? ''))]);
        $row = $u->fetch();
        if ($row && password_verify((string)($_POST['pass'] ?? ''), $row['pass'])) {
            qq_session();
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            db()->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([time(), $row['id']]);
            if (password_needs_rehash($row['pass'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash((string)$_POST['pass'], PASSWORD_DEFAULT), $row['id']]);
            }
            header('Location: index.php');
            exit;
        }
        db()->prepare('INSERT INTO logins (ip, t) VALUES (?, ?)')->execute([$ip, time()]);
        usleep(400000);
        $err = 'Sai tên đăng nhập hoặc mật khẩu.';
    }
}
qq_head('Đăng nhập · ' . setting('site_name'));
qq_sky();
?>
<main class="auth"><form method="post" class="glass2 box">
  <div>
    <h1><?= htmlspecialchars(setting('site_name')) ?></h1>
    <p class="muted" style="margin:6px 0 0">Đăng nhập để xem acc.</p>
  </div>
  <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <label class="field"><span>Tên đăng nhập</span><input class="input" name="user" required autocomplete="username" autofocus value="<?= htmlspecialchars((string)($_POST['user'] ?? '')) ?>"></label>
  <label class="field"><span>Mật khẩu</span><input class="input" type="password" name="pass" required autocomplete="current-password"></label>
  <button class="btn pri" type="submit">Đăng nhập</button>
</form></main>
</body></html>
