<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/_boot.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/migrate-sqlite.php <sqlite-file-or-data-directory>\n");
    exit(2);
}
if (!qq_uses_postgres()) {
    fwrite(STDERR, "Set POSTGRES_URL to the destination PostgreSQL database first.\n");
    exit(2);
}
if ((string)getenv('BLOB_READ_WRITE_TOKEN') === '') {
    fwrite(STDERR, "Set BLOB_READ_WRITE_TOKEN to migrate screenshot images.\n");
    exit(2);
}

$source = realpath($argv[1]);
if ($source === false) {
    fwrite(STDERR, "The SQLite source path does not exist.\n");
    exit(2);
}
if (is_dir($source)) {
    $pointer = $source . DIRECTORY_SEPARATOR . 'dbname.php';
    if (!is_file($pointer)) {
        fwrite(STDERR, "No data/dbname.php pointer was found in the source directory.\n");
        exit(2);
    }
    $name = trim((string)file_get_contents($pointer));
    $name = preg_replace('~^<\?php exit; \?>~', '', $name);
    if (!is_string($name) || !preg_match('~^db_[a-f0-9]{24}\.sqlite$~', $name)) {
        fwrite(STDERR, "The SQLite database pointer is invalid.\n");
        exit(2);
    }
    $source = realpath($source . DIRECTORY_SEPARATOR . $name);
    if ($source === false) {
        fwrite(STDERR, "The SQLite file referenced by dbname.php was not found.\n");
        exit(2);
    }
}

$tables = ['settings', 'users', 'accounts', 'm1', 'h1', 'events', 'cmds', 'vps', 'shots', 'audit', 'logins', 'restarts'];
$sqlite = new PDO('sqlite:' . $source, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$target = db();

foreach ($tables as $table) {
    if ($table === 'settings') {
        $st = $target->query("SELECT COUNT(*) FROM settings WHERE k <> 'schema'");
    } else {
        $st = $target->query('SELECT COUNT(*) FROM "' . $table . '"');
    }
    if ((int)$st->fetchColumn() > 0) {
        fwrite(STDERR, "Destination is not empty (table: $table). Refusing to overwrite existing data.\n");
        exit(2);
    }
}

foreach ($tables as $table) {
    $exists = $sqlite->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $exists->execute([$table]);
    if (!$exists->fetchColumn()) {
        fwrite(STDERR, "The SQLite source is missing the required table: $table.\n");
        exit(2);
    }
}

$sqlite->beginTransaction();
$target->beginTransaction();
try {
    foreach ($tables as $table) {
        $rows = $sqlite->query('SELECT * FROM "' . $table . '"');
        $columns = [];
        for ($i = 0; $i < $rows->columnCount(); $i++) {
            $columns[] = $rows->getColumnMeta($i)['name'];
        }
        if (!$columns) {
            continue;
        }
        $quoted = array_map(static function (string $column): string {
            return '"' . str_replace('"', '""', $column) . '"';
        }, $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO "' . $table . '" (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')';
        if ($table === 'settings') {
            $sql .= ' ON CONFLICT (k) DO UPDATE SET v = EXCLUDED.v';
        }
        $insert = $target->prepare($sql);
        while ($row = $rows->fetch()) {
            $insert->execute(array_values($row));
        }
    }

    $shots = $target->query('SELECT acc_id, file, t FROM shots')->fetchAll();
    $uploadableShots = [];
    foreach ($shots as $shot) {
        if (time() - (int)$shot['t'] > 900) {
            $target->prepare('DELETE FROM shots WHERE acc_id = ?')->execute([$shot['acc_id']]);
            continue;
        }
        $file = (string)$shot['file'];
        if (!preg_match('~^https://~i', $file)) {
            $local = dirname($source) . DIRECTORY_SEPARATOR . 'shots' . DIRECTORY_SEPARATOR . basename($file);
            if (!is_file($local)) {
                throw new RuntimeException('Screenshot file is missing: ' . basename($file));
            }
        }
        $uploadableShots[] = $shot;
    }
    $upload = $target->prepare('UPDATE shots SET file = ? WHERE acc_id = ?');
    foreach ($uploadableShots as $shot) {
        $file = (string)$shot['file'];
        if (preg_match('~^https://~i', $file)) {
            continue;
        }
        $local = dirname($source) . DIRECTORY_SEPARATOR . 'shots' . DIRECTORY_SEPARATOR . basename($file);
        if (!is_file($local)) {
            throw new RuntimeException('Screenshot file is missing: ' . basename($file));
        }
        $url = qq_blob_upload('shots/' . basename($file), (string)file_get_contents($local), 'image/jpeg');
        $upload->execute([$url, $shot['acc_id']]);
    }

    foreach (['users', 'accounts', 'events', 'cmds', 'vps', 'audit'] as $table) {
        $target->query("SELECT setval(pg_get_serial_sequence('$table', 'id'), COALESCE((SELECT MAX(id) FROM \"$table\"), 1), EXISTS (SELECT 1 FROM \"$table\"))")->closeCursor();
    }
    $target->commit();
    $sqlite->commit();
} catch (Throwable $e) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    if ($sqlite->inTransaction()) {
        $sqlite->rollBack();
    }
    throw $e;
}

fwrite(STDOUT, "SQLite data migrated successfully. Keep the original data directory as a backup until you verify the deployment.\n");
