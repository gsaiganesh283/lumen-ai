<?php
/** Database controls: tables, browse/search/edit rows, SQL console, backups, maintenance. */
require __DIR__ . '/_init.php';
$admin = require_admin('database');
$tables = db_tables();
$tab = input('tab', 'tables');
$table = in_array(input('t'), $tables, true) ? input('t') : null;
$can_write = can('database_write');   // superadmin only
$SENSITIVE = ['password_hash', 'totp_secret', 'token_hash', 'csrf'];

function columns(string $t): array {
    return rows('SELECT column_name AS name, data_type AS type, column_key AS ckey, is_nullable AS nullable, extra, column_default AS def
        FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$t]);
}
function pk_of(string $t): ?string {
    foreach (columns($t) as $c) if ($c['ckey'] === 'PRI') return $c['name'];
    return null;
}

// Downloads
if (isset($_GET['download']) && ($p = backup_path((string)$_GET['download']))) {
    audit('backup_download', 'backup', basename($p));
    end_buffers();
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($p) . '"');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
}
if ($table && input('export') === 'csv') {
    audit('table_export', 'table', $table);
    $cols = array_column(columns($table), 'name');
    $gen = (function () use ($table, $cols, $SENSITIVE) { foreach (db()->query("SELECT * FROM `$table`") as $r) {
        foreach ($SENSITIVE as $s) if (isset($r[$s])) $r[$s] = '[hidden]';
        yield array_values($r); } })();
    csv_download("$table-" . gmdate('Ymd') . '.csv', $cols, $gen);
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = input('action');
    $read_only_acts = ['sql_read', 'check'];
    admin_post(in_array($act, $read_only_acts, true) ? 'database' : 'database_write');
    try {
        switch ($act) {
            case 'backup': $n = backup_create('manual'); audit('backup_create', 'backup', $n); flash("Backup created: $n", 'success'); break;
            case 'backup_delete': if ($p = backup_path(input('name'))) { unlink($p); audit('backup_delete', 'backup', input('name')); flash('Backup deleted.', 'success'); } break;
            case 'restore':
                $p = backup_path(input('name'));
                if ($p) { $safety = backup_create('before_restore'); $n = backup_restore($p); audit('backup_restore', 'backup', input('name'), "$n statements; safety copy $safety"); flash("Restored ($n statements). A safety backup was made first: $safety", 'success'); }
                break;
            case 'restore_upload':
                if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name']) || !str_ends_with($_FILES['file']['name'], '.sql.gz')) { flash('Upload a .sql.gz backup made by this portal.', 'danger'); break; }
                $name = 'hiremind_' . gmdate('Ymd_His') . '_uploaded.sql.gz';
                move_uploaded_file($_FILES['file']['tmp_name'], BACKUP_DIR . '/' . $name);
                flash("Uploaded as $name. Click Restore on it to apply.", 'success');
                break;
            case 'optimize': foreach ($tables as $t) db()->query("OPTIMIZE TABLE `$t`")->fetchAll(); audit('db_optimize', 'db'); flash('All tables optimized.', 'success'); break;
            case 'analyze': foreach ($tables as $t) db()->query("ANALYZE TABLE `$t`")->fetchAll(); audit('db_analyze', 'db'); flash('Table statistics updated.', 'success'); break;
            case 'check':
                $bad = [];
                foreach ($tables as $t) foreach (db()->query("CHECK TABLE `$t`")->fetchAll() as $r) if (($r['Msg_text'] ?? '') !== 'OK') $bad[] = "$t: {$r['Msg_text']}";
                flash($bad ? 'Problems: ' . implode('; ', $bad) : 'All tables OK ✅', $bad ? 'danger' : 'success');
                break;
            case 'truncate':
                if ($table && in_array($table, ['activity_events', 'email_logs', 'login_attempts', 'rate_limits', 'audit_logs'], true) && input('confirm') === $table) {
                    if ($table === 'audit_logs') { flash('The audit log cannot be emptied.', 'danger'); break; }
                    db()->exec("TRUNCATE TABLE `$table`"); audit('table_truncate', 'table', $table); flash("$table emptied.", 'success');
                } else flash('Only log tables can be emptied, and you must type the table name.', 'danger');
                break;
            case 'row_save':
                if (!$table) break;
                $cols = columns($table); $pk = pk_of($table);
                $data = [];
                foreach ($cols as $c) {
                    $n = $c['name'];
                    if (!array_key_exists("f_$n", $_POST) || in_array($n, $SENSITIVE, true) || str_contains($c['extra'], 'auto_increment')) continue;
                    $data[$n] = isset($_POST["null_$n"]) ? null : (string)$_POST["f_$n"];
                }
                if (input('pk') !== '') { update($table, $data, "`$pk` = ?", [input('pk')]); $id = input('pk'); }
                else $id = insert($table, $data);
                audit('row_save', $table, $id, array_keys($data));
                flash("Row saved in $table.", 'success');
                redirect("database.php?tab=browse&t=$table");
            case 'row_delete':
                if ($table && ($pk = pk_of($table))) { q("DELETE FROM `$table` WHERE `$pk` = ?", [input('pk')]); audit('row_delete', $table, input('pk')); flash('Row deleted.', 'success'); }
                redirect("database.php?tab=browse&t=$table");
        }
    } catch (Throwable $e) {
        flash('Database error: ' . $e->getMessage(), 'danger');
    }
    if (!in_array($act, ['sql_read', 'sql_write'], true)) redirect($_SERVER['REQUEST_URI']);
}

// SQL console (runs inline so results can be shown)
$sql_result = null; $sql_error = ''; $sql = $_POST['sql'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(input('action'), ['sql_read', 'sql_write'], true) && trim($sql) !== '') {
    $tab = 'sql';
    $first = strtoupper(strtok(ltrim($sql), " \n\t("));
    $is_read = in_array($first, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'], true);
    try {
        if (!$is_read && input('action') !== 'sql_write') throw new RuntimeException('Read-only mode: only SELECT / SHOW / DESCRIBE / EXPLAIN. Superadmins can tick "Allow changes".');
        if (preg_match('/\b(DROP\s+DATABASE|GRANT|REVOKE|CREATE\s+USER|INTO\s+OUTFILE|LOAD_FILE|LOAD\s+DATA)\b/i', $sql)) throw new RuntimeException('That statement is blocked in the console.');
        if (substr_count(rtrim(trim($sql), ';'), ';') > 0) throw new RuntimeException('Run one statement at a time.');
        $t0 = microtime(true);
        if ($is_read) {
            if ($first === 'SELECT' && !preg_match('/\bLIMIT\s+\d+/i', $sql)) $sql = rtrim(trim($sql), ';') . ' LIMIT 500';
            $st = db()->query($sql);
            $sql_result = ['rows' => $st->fetchAll(), 'ms' => round((microtime(true) - $t0) * 1000, 1)];
        } else {
            $backup = backup_create('before_sql');
            $n = db()->exec($sql);
            $sql_result = ['affected' => $n, 'ms' => round((microtime(true) - $t0) * 1000, 1), 'backup' => $backup];
        }
        audit($is_read ? 'sql_read' : 'sql_write', 'sql', '', mb_substr($sql, 0, 2000));
    } catch (Throwable $e) {
        $sql_error = $e->getMessage();
    }
}

admin_start('Database');
$size = rows('SELECT table_name AS t, table_rows AS r, ROUND((data_length + index_length) / 1024) AS kb, engine, update_time FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name');
$total_kb = array_sum(array_column($size, 'kb'));
?>
<div class="tabs"><?php foreach (['tables' => '🗂 Tables', 'browse' => '🔍 Browse & edit', 'sql' => '⌨ SQL console', 'backups' => '💾 Backups', 'maint' => '🧰 Maintenance'] as $k => $l): ?>
  <a class="<?= $tab === $k ? 'active' : '' ?>" href="database.php?tab=<?= $k ?><?= $table ? '&t=' . e($table) : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
<?php if (!$can_write): ?><div class="alert alert-info">Read-only access: only superadmins can change data, restore backups or run write SQL.</div><?php endif; ?>

<?php if ($tab === 'tables'): ?>
<div class="grid grid-4"><?= stat_card('Tables', count($size)) ?><?= stat_card('Database size', number_format($total_kb / 1024, 2) . ' MB') ?><?= stat_card('Server', e((string)val('SELECT VERSION()'))) ?><?= stat_card('Database', e((string)val('SELECT DATABASE()'))) ?></div><br>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Table</th><th>Rows (approx.)</th><th>Size</th><th>Engine</th><th></th></tr></thead><tbody>
<?php foreach ($size as $s): ?><tr><td><b><?= e($s['t']) ?></b></td><td><?= number_format((int)$s['r']) ?></td><td><?= number_format((int)$s['kb']) ?> KB</td><td><?= e($s['engine']) ?></td>
  <td class="actions"><a class="btn btn-sm btn-ghost" href="database.php?tab=browse&t=<?= e($s['t']) ?>">Browse</a><a class="btn btn-sm btn-ghost" href="database.php?t=<?= e($s['t']) ?>&export=csv">⬇ CSV</a>
    <a class="btn btn-sm btn-ghost" href="database.php?tab=browse&t=<?= e($s['t']) ?>&schema=1">Schema</a></td></tr><?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'browse'): ?>
<form class="filters"><input type="hidden" name="tab" value="browse"><select name="t" onchange="this.form.submit()"><option value="">Choose table…</option><?php foreach ($tables as $t): ?><option <?= $t === $table ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
  <?php if ($table): ?><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Search text columns"><button class="btn btn-sm">Search</button>
  <a class="btn btn-sm btn-ghost" href="database.php?t=<?= e($table) ?>&export=csv">⬇ CSV</a><?php if ($can_write): ?><a class="btn btn-sm btn-green" href="database.php?tab=browse&t=<?= e($table) ?>&edit=new">➕ Insert row</a><?php endif; ?><?php endif; ?></form>
<?php if ($table):
  $cols = columns($table); $pk = pk_of($table); $names = array_column($cols, 'name');
  if (isset($_GET['schema'])): ?>
    <div class="card"><h2>Schema: <?= e($table) ?></h2><table class="compact"><thead><tr><th>Column</th><th>Type</th><th>Key</th><th>Null</th><th>Default</th><th>Extra</th></tr></thead>
    <?php foreach ($cols as $c): ?><tr><td><b><?= e($c['name']) ?></b></td><td><?= e($c['type']) ?></td><td><?= e($c['ckey']) ?></td><td><?= e($c['nullable']) ?></td><td><?= e($c['def']) ?></td><td><?= e($c['extra']) ?></td></tr><?php endforeach; ?></table>
    <div class="code-block" style="margin-top:12px"><?= e(row("SHOW CREATE TABLE `$table`")['Create Table']) ?></div></div>
  <?php elseif (isset($_GET['edit'])):
    $r = $_GET['edit'] === 'new' ? [] : (row("SELECT * FROM `$table` WHERE `$pk` = ?", [$_GET['edit']]) ?? []); ?>
    <div class="card" style="max-width:820px"><h2><?= $r ? 'Edit row ' . e($pk) . ' = ' . e($r[$pk]) : 'Insert row' ?> in <?= e($table) ?></h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="row_save"><input type="hidden" name="t" value="<?= e($table) ?>"><input type="hidden" name="pk" value="<?= e($r[$pk] ?? '') ?>">
      <?php foreach ($cols as $c): $n = $c['name']; if (str_contains($c['extra'], 'auto_increment')) continue; ?>
        <label><?= e($n) ?> <span class="muted small"><?= e($c['type']) ?></span></label>
        <?php if (in_array($n, $SENSITIVE, true)): ?><input value="[hidden — change it from the user/admin pages]" disabled>
        <?php else: $v = $r[$n] ?? null; ?>
          <div class="input-group"><?php if (in_array($c['type'], ['text', 'mediumtext', 'longtext'], true)): ?><textarea name="f_<?= e($n) ?>"><?= e($v) ?></textarea><?php else: ?><input name="f_<?= e($n) ?>" value="<?= e($v) ?>"><?php endif; ?>
            <?php if ($c['nullable'] === 'YES'): ?><label class="check small"><input type="checkbox" name="null_<?= e($n) ?>" <?= $r && $v === null ? 'checked' : '' ?>> NULL</label><?php endif; ?></div>
        <?php endif; ?>
      <?php endforeach; ?><br><button class="btn" <?= $can_write ? '' : 'disabled' ?> data-confirm="Save this row directly to the database?">Save row</button> <a class="btn btn-ghost" href="database.php?tab=browse&t=<?= e($table) ?>">Cancel</a></form></div>
  <?php else:
    $w = '1=1'; $p = [];
    if (($s = input('q')) !== '') {
        $txt = array_column(array_filter($cols, fn($c) => in_array($c['type'], ['varchar', 'char', 'text', 'mediumtext'], true) && !in_array($c['name'], $SENSITIVE, true)), 'name');
        if ($txt) { $w = '(' . implode(' OR ', array_map(fn($n) => "`$n` LIKE ?", $txt)) . ')'; $p = array_fill(0, count($txt), "%$s%"); }
    }
    $order = in_array(input('sort'), $names, true) ? input('sort') : $pk;
    $dir = input('dir') === 'asc' ? 'ASC' : 'DESC';
    $total = (int)val("SELECT COUNT(*) FROM `$table` WHERE $w", $p);
    [$lim, $off, $page, $pages] = paginate($total, 25);
    $data = rows("SELECT * FROM `$table` WHERE $w" . ($order ? " ORDER BY `$order` $dir" : '') . " LIMIT $lim OFFSET $off", $p); ?>
    <div class="card"><div class="table-wrap"><table class="compact"><thead><tr><?php foreach ($names as $n): ?><th><a href="<?= e(qs(['sort' => $n, 'dir' => $order === $n && $dir === 'DESC' ? 'asc' : 'desc'])) ?>"><?= e($n) ?><?= $order === $n ? ($dir === 'DESC' ? ' ↓' : ' ↑') : '' ?></a></th><?php endforeach; ?><?php if ($pk): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($data as $r): ?><tr><?php foreach ($names as $n): ?><td class="small"><div class="cell-trunc" title="<?= in_array($n, $SENSITIVE, true) ? '' : e($r[$n]) ?>"><?= in_array($n, $SENSITIVE, true) ? ($r[$n] ? '•••' : '') : ($r[$n] === null ? '<span class="muted">NULL</span>' : e(mb_substr((string)$r[$n], 0, 120))) ?></div></td><?php endforeach; ?>
      <?php if ($pk): ?><td class="actions"><a class="btn btn-sm btn-ghost" href="database.php?tab=browse&t=<?= e($table) ?>&edit=<?= e(urlencode((string)$r[$pk])) ?>">Edit</a>
        <?php if ($can_write): ?><form method="post" data-confirm="Delete this row? Related rows may be deleted too (foreign keys)."><?= csrf_field() ?><input type="hidden" name="action" value="row_delete"><input type="hidden" name="t" value="<?= e($table) ?>"><input type="hidden" name="pk" value="<?= e($r[$pk]) ?>"><button class="btn btn-sm btn-ghost">🗑</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
    <?php if (!$data): ?><tr><td colspan="<?= count($names) + 1 ?>" class="empty">No rows.</td></tr><?php endif; ?></tbody></table></div><?= pager($page, $pages, $total) ?></div>
  <?php endif; endif; ?>

<?php elseif ($tab === 'sql'): ?>
<div class="card"><form method="post"><?= csrf_field() ?><textarea name="sql" class="sql-box" placeholder="SELECT * FROM users ORDER BY id DESC LIMIT 20"><?= e($_POST['sql'] ?? '') ?></textarea>
  <div class="actions" style="margin-top:8px"><button class="btn" name="action" value="sql_read">▶ Run (read-only)</button>
    <?php if ($can_write): ?><button class="btn btn-danger" name="action" value="sql_write" data-confirm="Run this statement with WRITE access? A backup is made first.">⚠ Run with changes allowed</button><?php endif; ?>
    <span class="hint">One statement. SELECTs are limited to 500 rows. Every query is saved in the audit log.</span></div></form>
  <div class="actions" style="margin-top:10px"><?php foreach ([
      'Expiring this week' => "SELECT l.license_key, u.email, l.expires_at FROM licenses l JOIN users u ON u.id = l.user_id WHERE l.status = 'active' AND l.expires_at < NOW() + INTERVAL 7 DAY ORDER BY l.expires_at",
      'Revenue by month' => "SELECT DATE_FORMAT(paid_at, '%Y-%m') month, COUNT(*) orders, SUM(amount_paise)/100 rupees FROM orders WHERE status = 'paid' GROUP BY month ORDER BY month DESC",
      'Users per day' => "SELECT DATE(created_at) day, COUNT(*) users FROM users GROUP BY day ORDER BY day DESC",
      'Most active users (7d)' => "SELECT u.email, COUNT(*) events FROM activity_events e JOIN users u ON u.id = e.user_id WHERE e.created_at > NOW() - INTERVAL 7 DAY GROUP BY u.email ORDER BY events DESC",
  ] as $label => $qsql): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="sql" value="<?= e($qsql) ?>"><button class="btn btn-sm btn-ghost" name="action" value="sql_read"><?= e($label) ?></button></form><?php endforeach; ?></div></div>
<?php if ($sql_error): ?><div class="alert alert-danger"><?= e($sql_error) ?></div><?php endif; ?>
<?php if ($sql_result): ?><div class="card">
  <?php if (isset($sql_result['rows'])): $rs = $sql_result['rows']; ?><p class="muted small"><?= count($rs) ?> row(s) · <?= $sql_result['ms'] ?> ms</p>
    <?php if ($rs): ?><div class="table-wrap"><table class="compact"><thead><tr><?php foreach (array_keys($rs[0]) as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($rs as $r): ?><tr><?php foreach ($r as $k => $v): ?><td class="small"><div class="cell-trunc"><?= in_array($k, $SENSITIVE, true) ? '•••' : ($v === null ? '<span class="muted">NULL</span>' : e(mb_substr((string)$v, 0, 200))) ?></div></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <?php else: ?><div class="alert alert-success"><?= (int)$sql_result['affected'] ?> row(s) changed in <?= $sql_result['ms'] ?> ms. Backup taken first: <?= e($sql_result['backup']) ?></div><?php endif; ?></div><?php endif; ?>

<?php elseif ($tab === 'backups'): $list = backup_list(); ?>
<div class="card"><div class="card-head"><h2>💾 Backups</h2><?php if ($can_write): ?><form method="post"><?= csrf_field() ?><button class="btn" name="action" value="backup">Create backup now</button></form><?php endif; ?></div>
  <p class="hint">Automatic backups every <?= (int)setting('auto_backup_hours') ?> h, keeping the newest <?= (int)setting('backups_to_keep') ?> (Settings → Tracking & data). Stored in <code>website/data/backups</code>. Last automatic: <?= setting('last_backup') ? local_dt(gmdate('Y-m-d H:i:s', setting('last_backup'))) : 'never' ?>.</p>
  <div class="table-wrap"><table class="compact"><thead><tr><th>File</th><th>Size</th><th>Created</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $b): ?><tr><td><code><?= e($b['name']) ?></code></td><td><?= number_format($b['size'] / 1024, 1) ?> KB</td><td><?= local_dt(gmdate('Y-m-d H:i:s', $b['time'])) ?></td>
    <td class="actions"><a class="btn btn-sm btn-ghost" href="database.php?download=<?= e(urlencode($b['name'])) ?>">⬇ Download</a>
      <?php if ($can_write): ?><form method="post" data-type-confirm="RESTORE"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm btn-amber">Restore</button></form>
      <form method="post" data-confirm="Delete this backup?"><?= csrf_field() ?><input type="hidden" name="action" value="backup_delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm btn-ghost">🗑</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="4" class="empty">No backups yet.</td></tr><?php endif; ?></tbody></table></div>
  <?php if ($can_write): ?><form method="post" enctype="multipart/form-data" class="input-group" style="margin-top:14px;max-width:560px"><?= csrf_field() ?><input type="hidden" name="action" value="restore_upload"><input type="file" name="file" accept=".gz" required><button class="btn btn-ghost">Upload backup</button></form><?php endif; ?></div>

<?php else: ?>
<div class="card"><h2>🧰 Maintenance</h2><div class="actions">
  <form method="post"><?= csrf_field() ?><button class="btn btn-ghost" name="action" value="check">✅ Check all tables</button></form>
  <?php if ($can_write): ?><form method="post"><?= csrf_field() ?><button class="btn btn-ghost" name="action" value="analyze">📊 Update statistics</button></form>
  <form method="post"><?= csrf_field() ?><button class="btn btn-ghost" name="action" value="optimize" data-confirm="Optimize all tables? This can take a moment.">⚡ Optimize tables</button></form><?php endif; ?></div>
  <?php if ($can_write): ?><h3 style="margin-top:20px">Empty a log table</h3><form method="post" class="input-group" style="max-width:620px"><?= csrf_field() ?><input type="hidden" name="action" value="truncate">
    <select name="t"><?php foreach (['activity_events', 'email_logs', 'login_attempts', 'rate_limits'] as $t): ?><option><?= $t ?></option><?php endforeach; ?></select><input name="confirm" placeholder="Type the table name"><button class="btn btn-danger">Empty</button></form><?php endif; ?></div>
<?php endif; ?>
<?php admin_end();
