<?php
/** Database backup and restore in plain SQL (gzip). One statement per line. */

const BACKUP_DIR = HM_DATA . '/backups';

function sql_literal($v): string {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return "'" . strtr((string)$v, ["\\" => "\\\\", "'" => "\\'", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"]) . "'";
}

function db_tables(): array {
    return array_column(rows('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = "BASE TABLE" ORDER BY table_name'), 't');
}

function backup_create(string $label = 'manual'): string {
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0700, true);
    $name = 'hiremind_' . gmdate('Ymd_His') . '_' . preg_replace('/[^a-z0-9]/i', '', $label) . '.sql.gz';
    $gz = gzopen(BACKUP_DIR . '/' . $name, 'wb6');
    gzwrite($gz, "-- InterviewLens backup " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach (db_tables() as $t) {
        $create = row("SHOW CREATE TABLE `$t`")['Create Table'];
        gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n" . str_replace("\n", ' ', $create) . ";\n");
        $st = db()->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $batch = [];
        while ($r = $st->fetch()) {
            $batch[] = '(' . implode(',', array_map('sql_literal', $r)) . ')';
            if (count($batch) >= 200) {
                gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(',', $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(',', $batch) . ";\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    return $name;
}

function backup_list(): array {
    $files = glob(BACKUP_DIR . '/*.sql.gz') ?: [];
    rsort($files);
    return array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
}

function backup_path(string $name): ?string {
    if (!preg_match('/^hiremind_[0-9_]+[a-z0-9]*\.sql\.gz$/i', $name)) return null;
    $p = BACKUP_DIR . '/' . $name;
    return is_file($p) ? $p : null;
}

function backup_restore(string $path): int {
    $gz = gzopen($path, 'rb');
    $n = 0;
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    while (($line = gzgets($gz)) !== false) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '--')) continue;
        $pdo->exec(rtrim($line, ';'));
        $n++;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    gzclose($gz);
    return $n;
}

function backup_prune(int $keep): void {
    $list = backup_list();
    foreach (array_slice($list, max(1, $keep)) as $b) @unlink(BACKUP_DIR . '/' . $b['name']);
}
