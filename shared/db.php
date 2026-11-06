<?php
/** Database access (PDO, MySQL/MariaDB). All times are stored in UTC. */

function hm_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        $cfg = is_file($file) ? (require $file) : [];
        // Optional override (used for automated tests so they never touch the real database).
        if (getenv('HM_DB_NAME')) $cfg['db_name'] = getenv('HM_DB_NAME');
    }
    return $cfg;
}

function hm_installed(): bool {
    return is_file(__DIR__ . '/config.php');
}

/** True when the tables exist and a superadmin was created (setup finished). */
function hm_ready(): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = hm_installed() && (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = hm_config();
        $host = $c['db_host'] ?? '127.0.0.1';
        $port = (int)($c['db_port'] ?? 3306);
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $c['db_name'] ?? 'hiremind');
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $c['db_user'] ?? 'root', $c['db_pass'] ?? '', $opts);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1049) throw $e;   // 1049 = unknown database
            // First run: create the database, then connect to it.
            $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $c['db_user'] ?? 'root', $c['db_pass'] ?? '', $opts);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$name`");
        }
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function row(string $sql, array $params = []): ?array {
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function rows(string $sql, array $params = []): array {
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = []) {
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Insert a row (column names come from code, never from users). Returns the new id. */
function insert(string $table, array $data): int {
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function update(string $table, array $data, string $where, array $params = []): int {
    $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($data)));
    return q("UPDATE `$table` SET $set WHERE $where", array_merge(array_values($data), $params))->rowCount();
}

function now(): string {
    return gmdate('Y-m-d H:i:s');
}

function ts_plus(string $interval, ?string $from = null): string {
    $d = new DateTimeImmutable($from ?? 'now', new DateTimeZone('UTC'));
    return $d->modify($interval)->format('Y-m-d H:i:s');
}

function utc_ts(?string $dt): int {
    return $dt ? (new DateTimeImmutable($dt, new DateTimeZone('UTC')))->getTimestamp() : 0;
}

function tx(callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
