<?php
/*
 * Trạm theo dõi acc — lõi dùng chung (cấu hình, database, đăng nhập, tiện ích).
 * Chạy PHP 7.4+ với PDO SQLite (có sẵn trên hầu hết hosting). Không cần tạo database.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

define('QQ_ROOT', dirname(__DIR__));
define('QQ_DATA', QQ_ROOT . '/data');
define('QQ_VERSION', '1.0');

if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return $n === '' || strpos((string)$h, (string)$n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return strncmp((string)$h, (string)$n, strlen((string)$n)) === 0; }
}

/* ───────────────────────── thư mục dữ liệu ───────────────────────── */

function qq_data_dir(): string
{
    if (!is_dir(QQ_DATA)) {
        @mkdir(QQ_DATA, 0755, true);
    }
    foreach (['shots', 'heads'] as $d) {
        if (!is_dir(QQ_DATA . '/' . $d)) {
            @mkdir(QQ_DATA . '/' . $d, 0755, true);
        }
    }
    if (!is_file(QQ_DATA . '/.htaccess')) {
        @file_put_contents(QQ_DATA . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file(QQ_DATA . '/index.html')) {
        @file_put_contents(QQ_DATA . '/index.html', '');
    }
    return QQ_DATA;
}

/** Tên file database có phần ngẫu nhiên → không ai đoán được để tải về kể cả khi hosting không chặn .htaccess. */
function qq_db_path(): string
{
    $dir = qq_data_dir();
    $ptr = $dir . '/dbname.php';
    if (is_file($ptr)) {
        $name = trim((string)@file_get_contents($ptr));
        $name = preg_replace('~^<\?php exit; \?>~', '', $name);
        if ($name !== '' && preg_match('~^db_[a-f0-9]{24}\.sqlite$~', $name)) {
            return $dir . '/' . $name;
        }
    }
    $name = 'db_' . bin2hex(random_bytes(12)) . '.sqlite';
    @file_put_contents($ptr, '<?php exit; ?>' . $name);
    return $dir . '/' . $name;
}

/* ───────────────────────── database ───────────────────────── */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        qq_fail(500, 'Hosting chưa bật PDO SQLite. Vào cPanel > Select PHP Version > bật pdo_sqlite.');
    }
    $pdo = new PDO('sqlite:' . qq_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 15,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA busy_timeout=15000');
    $pdo->exec('PRAGMA foreign_keys=OFF');
    qq_migrate($pdo);
    return $pdo;
}

function qq_migrate(PDO $pdo): void
{
    $v = 0;
    try {
        $v = (int)$pdo->query("SELECT v FROM settings WHERE k='schema'")->fetchColumn();
    } catch (Throwable $e) {
        $v = 0;
    }
    if ($v >= 1) {
        return;
    }
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, pass TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'view', groups TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL, last_login INTEGER
);
CREATE TABLE IF NOT EXISTS accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE NOT NULL, name TEXT NOT NULL DEFAULT '',
  inst TEXT NOT NULL DEFAULT '', vps TEXT NOT NULL DEFAULT '', host TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT '',
  sort INTEGER NOT NULL DEFAULT 0, grp TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
  checked_day TEXT NOT NULL DEFAULT '', hidden INTEGER NOT NULL DEFAULT 0, cost REAL NOT NULL DEFAULT 0, vps_expire TEXT NOT NULL DEFAULT '',
  auto_restart INTEGER NOT NULL DEFAULT 1, created INTEGER NOT NULL, last_seen INTEGER NOT NULL DEFAULT 0,
  state TEXT NOT NULL DEFAULT '{}', bal REAL, bal_at INTEGER NOT NULL DEFAULT 0, sid TEXT NOT NULL DEFAULT '', cstart INTEGER NOT NULL DEFAULT 0,
  inc_last REAL NOT NULL DEFAULT 0, sells_last INTEGER NOT NULL DEFAULT 0, last_gain_at INTEGER NOT NULL DEFAULT 0,
  build_placed INTEGER NOT NULL DEFAULT 0, build_prog_at INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'lost', status_since INTEGER NOT NULL DEFAULT 0, alert_state TEXT NOT NULL DEFAULT '', alert_at INTEGER NOT NULL DEFAULT 0,
  watch_until INTEGER NOT NULL DEFAULT 0, demo INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS m1 (acc_id INTEGER NOT NULL, t INTEGER NOT NULL, o REAL, h REAL, l REAL, c REAL, inc REAL NOT NULL DEFAULT 0, sells INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (acc_id, t));
CREATE TABLE IF NOT EXISTS h1 (acc_id INTEGER NOT NULL, t INTEGER NOT NULL, o REAL, h REAL, l REAL, c REAL, inc REAL NOT NULL DEFAULT 0, sells INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (acc_id, t));
CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, acc_id INTEGER NOT NULL DEFAULT 0, t INTEGER NOT NULL, kind TEXT NOT NULL, msg TEXT NOT NULL DEFAULT '', val REAL);
CREATE INDEX IF NOT EXISTS ev_acc ON events (acc_id, t);
CREATE TABLE IF NOT EXISTS cmds (
  id INTEGER PRIMARY KEY AUTOINCREMENT, target TEXT NOT NULL, acc_id INTEGER NOT NULL DEFAULT 0, vps TEXT NOT NULL DEFAULT '',
  t TEXT NOT NULL, a TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL, by_user TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'new', sent_at INTEGER NOT NULL DEFAULT 0, result TEXT NOT NULL DEFAULT '', done_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS cmd_acc ON cmds (acc_id, status);
CREATE TABLE IF NOT EXISTS vps (
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, host TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT '',
  last_seen INTEGER NOT NULL DEFAULT 0, info TEXT NOT NULL DEFAULT '{}', cost REAL NOT NULL DEFAULT 0, expire TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS shots (acc_id INTEGER PRIMARY KEY, cmd_id INTEGER NOT NULL, file TEXT NOT NULL, t INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, t INTEGER NOT NULL, user TEXT NOT NULL, action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS logins (ip TEXT NOT NULL, t INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS restarts (acc_id INTEGER NOT NULL, t INTEGER NOT NULL);
SQL);
    $pdo->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('schema', '1')");
}

/* ───────────────────────── cài đặt ───────────────────────── */

const QQ_DEFAULTS = [
    'site_name' => 'Trạm theo dõi acc',
    'tz' => 'Asia/Ho_Chi_Minh',
    'api_key' => '',
    'discord_webhook' => '',
    'discord_mention' => '',
    'alert_offline' => '1',
    'alert_crash' => '1',
    'alert_idle' => '1',
    'alert_drop' => '1',
    'alert_token' => '1',
    'alert_ram' => '1',
    'alert_recover' => '1',
    'alert_repeat_min' => '30',
    'idle_min' => '20',
    'drop_pct' => '20',
    'drop_min_m' => '5',
    'report_time' => '22:00',
    'report_last' => '',
    'goal_m' => '1000',
    'vnd_per_m' => '0',
    'keep_m1_days' => '14',
    'restart_max_hour' => '3',
    'maint_at' => '0',
    'prune_at' => '0',
    'default_server' => 'donutsmp.net',
];

function setting(string $k, ?string $def = null): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings') as $r) {
            $cache[$r['k']] = (string)$r['v'];
        }
    }
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    return $def ?? (QQ_DEFAULTS[$k] ?? '');
}

function setting_set(string $k, string $v): void
{
    db()->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

function qq_installed(): bool
{
    try {
        return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function qq_tz(): DateTimeZone
{
    static $tz = null;
    if (!$tz) {
        try {
            $tz = new DateTimeZone(setting('tz'));
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
        }
    }
    return $tz;
}

function qq_tz_offset(?int $at = null): int
{
    return qq_tz()->getOffset(new DateTime('@' . ($at ?? time())));
}

function qq_today(?int $at = null): string
{
    $d = new DateTime('@' . ($at ?? time()));
    $d->setTimezone(qq_tz());
    return $d->format('Y-m-d');
}

function qq_day_start(?int $at = null): int
{
    $d = new DateTime('@' . ($at ?? time()));
    $d->setTimezone(qq_tz());
    $d->setTime(0, 0, 0);
    return $d->getTimestamp();
}

/* ───────────────────────── trả lời / lỗi ───────────────────────── */

function qq_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function qq_fail(int $code, string $msg): void
{
    qq_json(['ok' => 0, 'error' => $msg], $code);
    exit;
}

function qq_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST ?: [];
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function qq_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h]) && filter_var($_SERVER[$h], FILTER_VALIDATE_IP)) {
            return $_SERVER[$h];
        }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function qq_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == '443');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (substr($dir, -4) === '/api') {
        $dir = substr($dir, 0, -4);
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/* ───────────────────────── khóa API (mod / VPS) ───────────────────────── */

function qq_api_key(): string
{
    $k = setting('api_key');
    if ($k === '') {
        $k = bin2hex(random_bytes(20));
        setting_set('api_key', $k);
    }
    return $k;
}

function qq_require_key(): void
{
    $got = $_SERVER['HTTP_X_QQ_KEY'] ?? ($_GET['key'] ?? '');
    if (!is_string($got) || $got === '' || !hash_equals(qq_api_key(), $got)) {
        qq_fail(403, 'Sai key');
    }
}

/* ───────────────────────── đăng nhập ───────────────────────── */

function qq_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('qqweb');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
    $sd = QQ_DATA . '/sessions';
    if (!is_dir($sd)) {
        @mkdir($sd, 0755, true);
    }
    if (is_dir($sd) && is_writable($sd)) {
        session_save_path($sd);
    }
    session_start();
}

function qq_user(): ?array
{
    qq_session();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $u = false;
    if ($u === false) {
        $st = db()->prepare('SELECT id, username, role, groups FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch() ?: null;
    }
    return $u;
}

function qq_require_user(string $need = 'view'): array
{
    $u = qq_user();
    if (!$u) {
        qq_fail(401, 'Chưa đăng nhập');
    }
    $rank = ['view' => 1, 'control' => 2, 'owner' => 3];
    if (($rank[$u['role']] ?? 0) < ($rank[$need] ?? 9)) {
        qq_fail(403, 'Tài khoản của bạn không có quyền này');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = $_SERVER['HTTP_X_CSRF'] ?? '';
        if (!is_string($tok) || !hash_equals((string)($_SESSION['csrf'] ?? ''), $tok)) {
            qq_fail(403, 'Phiên hết hạn, tải lại trang');
        }
    }
    return $u;
}

function qq_csrf(): string
{
    qq_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/** Nhóm acc người dùng được xem ('' = tất cả). */
function qq_user_groups(array $u): array
{
    if ($u['role'] === 'owner' || trim((string)$u['groups']) === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $u['groups']))));
}

function qq_can_see(array $u, array $acc): bool
{
    $g = qq_user_groups($u);
    return !$g || in_array((string)$acc['grp'], $g, true);
}

function qq_audit(string $user, string $action, string $detail = ''): void
{
    db()->prepare('INSERT INTO audit (t, user, action, detail) VALUES (?, ?, ?, ?)')->execute([time(), $user, $action, mb_substr($detail, 0, 500)]);
}

function qq_event(int $accId, string $kind, string $msg, ?float $val = null, ?int $t = null): void
{
    db()->prepare('INSERT INTO events (acc_id, t, kind, msg, val) VALUES (?, ?, ?, ?, ?)')->execute([$accId, $t ?? time(), $kind, mb_substr($msg, 0, 400), $val]);
}

/* ───────────────────────── tiền ───────────────────────── */

function qq_money(float $v): string
{
    $a = abs($v);
    $s = $v < 0 ? '-' : '';
    if ($a >= 1e9) {
        return $s . '$' . rtrim(rtrim(number_format($a / 1e9, 2, '.', ''), '0'), '.') . 'B';
    }
    if ($a >= 1e6) {
        return $s . '$' . rtrim(rtrim(number_format($a / 1e6, $a >= 1e8 ? 0 : 1, '.', ''), '0'), '.') . 'M';
    }
    if ($a >= 1e3) {
        return $s . '$' . round($a / 1e3) . 'K';
    }
    return $s . '$' . round($a);
}

/* ───────────────────────── Discord ───────────────────────── */

function qq_discord(array $embed, bool $ping = false): bool
{
    $url = trim(setting('discord_webhook'));
    if ($url === '' || !preg_match('~^https://(?:\w+\.)?(discord|discordapp)\.com/api/webhooks/~', $url)) {
        return false;
    }
    $mention = trim(setting('discord_mention'));
    $payload = ['username' => setting('site_name'), 'embeds' => [$embed]];
    if ($ping && $mention !== '') {
        $payload['content'] = '<@' . preg_replace('~\D~', '', $mention) . '>';
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 6]]);
    return @file_get_contents($url, false, $ctx) !== false;
}
