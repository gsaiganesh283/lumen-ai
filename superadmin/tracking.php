<?php
/** User tracking explorer: every saved event (page views, clicks, logins, payments, desktop app events). */
require __DIR__ . '/_init.php';
require_admin('tracking');

if (admin_post('database') && input('action') === 'purge') {
    $days = max(1, input_int('days', 365));
    $n = q('DELETE FROM activity_events WHERE created_at < ?', [ts_plus("-$days days")])->rowCount();
    audit('tracking_purge', 'activity', '', "$n events older than $days days");
    flash("Deleted $n old events.", 'success');
    redirect('tracking.php');
}

$where = ['1=1']; $p = [];
if (($v = input_int('user')) > 0) { $where[] = 'e.user_id = ?'; $p[] = $v; }
if (($v = input('email')) !== '') { $where[] = 'u.email LIKE ?'; $p[] = "%$v%"; }
if (($v = input('kind')) !== '') { $where[] = 'e.kind = ?'; $p[] = $v; }
if (($v = input('source')) !== '') { $where[] = 'e.source = ?'; $p[] = $v; }
if (($v = input('ip')) !== '') { $where[] = 'e.ip = ?'; $p[] = $v; }
if (($v = input('device')) !== '') { $where[] = 'e.device_id = ?'; $p[] = strtoupper($v); }
if (($v = input('q')) !== '') { $where[] = '(e.label LIKE ? OR e.path LIKE ? OR e.meta LIKE ?)'; array_push($p, "%$v%", "%$v%", "%$v%"); }
if (($v = input('from')) !== '') { $where[] = 'e.created_at >= ?'; $p[] = "$v 00:00:00"; }
if (($v = input('to')) !== '') { $where[] = 'e.created_at <= ?'; $p[] = "$v 23:59:59"; }
if (input('errors') === '1') $where[] = "(e.kind IN ('error','login_failed','payment_failed','desktop_activate_failed') OR e.status >= 400)";
$w = implode(' AND ', $where);
$base = "FROM activity_events e LEFT JOIN users u ON u.id = e.user_id WHERE $w";

if (input('export') === 'csv') {
    audit('export_tracking', 'activity', '', $w);
    $gen = (function () use ($base, $p) { foreach (q("SELECT e.*, u.email $base ORDER BY e.id DESC LIMIT 200000", $p) as $r)
        yield [$r['id'], $r['created_at'], $r['email'], $r['source'], $r['kind'], $r['label'], $r['path'], $r['method'], $r['status'], $r['duration_ms'], $r['ip'], $r['user_agent'], $r['device_id'], $r['referrer'], $r['meta']]; })();
    csv_download('tracking-' . gmdate('Ymd-His') . '.csv', ['ID', 'Time (UTC)', 'Email', 'Source', 'Event', 'Label', 'Path', 'Method', 'Status', 'ms', 'IP', 'User agent', 'Device', 'Referrer', 'Meta'], $gen);
}
$total = (int)val("SELECT COUNT(*) $base", $p);
[$lim, $off, $page, $pages] = paginate($total, 50);
$list = rows("SELECT e.*, u.email $base ORDER BY e.id DESC LIMIT $lim OFFSET $off", $p);
$kinds = array_column(rows('SELECT kind, COUNT(*) c FROM activity_events GROUP BY kind ORDER BY c DESC'), 'c', 'kind');
$top_pages = rows("SELECT path, COUNT(*) c, COUNT(DISTINCT user_id) u FROM activity_events WHERE kind = 'page_view' AND created_at >= ? GROUP BY path ORDER BY c DESC LIMIT 8", [ts_plus('-7 days')]);
$top_ips = rows('SELECT ip, COUNT(*) c, COUNT(DISTINCT user_id) u FROM activity_events WHERE created_at >= ? GROUP BY ip ORDER BY c DESC LIMIT 8', [ts_plus('-7 days')]);
$shared_ips = rows('SELECT ip, COUNT(DISTINCT user_id) u FROM activity_events WHERE user_id IS NOT NULL AND created_at >= ? GROUP BY ip HAVING u > 2 ORDER BY u DESC LIMIT 8', [ts_plus('-30 days')]);
admin_start('User tracking');
?>
<div class="grid grid-4">
  <?= stat_card('Events saved', number_format((int)val('SELECT COUNT(*) FROM activity_events'))) ?>
  <?= stat_card('Last 24 hours', number_format((int)val('SELECT COUNT(*) FROM activity_events WHERE created_at >= ?', [ts_plus('-24 hours')]))) ?>
  <?= stat_card('Active users (24h)', number_format((int)val('SELECT COUNT(DISTINCT user_id) FROM activity_events WHERE created_at >= ?', [ts_plus('-24 hours')]))) ?>
  <?= stat_card('Problems (24h)', number_format((int)val("SELECT COUNT(*) FROM activity_events WHERE (kind IN ('error','login_failed','payment_failed','desktop_activate_failed') OR status >= 400) AND created_at >= ?", [ts_plus('-24 hours')])), 'errors, failed logins…', 'tracking.php?errors=1') ?>
</div><br>
<form class="filters">
  <input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Search label, path, details"><input name="email" value="<?= e(input('email')) ?>" placeholder="User email">
  <?= filter_select('kind', array_combine(array_keys($kinds), array_map(fn($k, $c) => event_label($k) . " ($c)", array_keys($kinds), $kinds)), 'All events') ?>
  <?= filter_select('source', ['web' => 'User portal', 'desktop' => 'Desktop app', 'admin' => 'Admin', 'system' => 'System'], 'All sources') ?>
  <input name="ip" value="<?= e(input('ip')) ?>" placeholder="IP" style="min-width:110px"><input name="device" value="<?= e(input('device')) ?>" placeholder="Device ID" style="min-width:120px">
  <input type="date" name="from" value="<?= e(input('from')) ?>"><input type="date" name="to" value="<?= e(input('to')) ?>">
  <label class="check"><input type="checkbox" name="errors" value="1" <?= input('errors') ? 'checked' : '' ?>> Problems only</label>
  <?php if (input_int('user')): ?><input type="hidden" name="user" value="<?= input_int('user') ?>"><span class="badge badge-blue">User #<?= input_int('user') ?></span><?php endif; ?>
  <button class="btn btn-sm">Filter</button><a class="btn btn-sm btn-ghost" href="tracking.php">Reset</a><a class="btn btn-sm btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">⬇ CSV</a></form>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Time</th><th>User</th><th>Event</th><th>Details</th><th>IP · client</th><th>ms</th></tr></thead><tbody>
<?php foreach ($list as $ev): ?><tr><td class="small" style="white-space:nowrap"><?= local_dt($ev['created_at']) ?></td>
  <td class="small"><?= $ev['user_id'] ? user_link((int)$ev['user_id'], $ev['email']) : ($ev['admin_id'] ? 'admin #' . (int)$ev['admin_id'] : '<span class="muted">guest</span>') ?></td>
  <td><?= e(event_label($ev['kind'])) ?><div class="small muted"><?= e($ev['source']) ?></div></td>
  <td class="small"><?= e($ev['label'] ?: $ev['path']) ?><?= $ev['device_id'] ? ' <code>' . e($ev['device_id']) . '</code>' : '' ?><?php if ($ev['meta']): ?><details><summary class="muted">details</summary><code><?= e($ev['meta']) ?></code></details><?php endif; ?></td>
  <td class="small"><a href="tracking.php?ip=<?= e(urlencode($ev['ip'])) ?>"><?= e($ev['ip']) ?></a><div class="muted"><?= e($ev['source'] === 'desktop' ? 'Desktop app' : short_agent($ev['user_agent'])) ?></div></td><td class="small"><?= (int)$ev['duration_ms'] ?: '' ?></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="6" class="empty">No events match.</td></tr><?php endif; ?></tbody></table></div><?= pager($page, $pages, $total) ?></div>
<div class="grid grid-3">
  <div class="card"><h2>📄 Top pages (7d)</h2><table class="compact"><?php foreach ($top_pages as $r): ?><tr><td class="small cell-trunc"><?= e($r['path']) ?></td><td><?= (int)$r['c'] ?></td><td class="small muted"><?= (int)$r['u'] ?> users</td></tr><?php endforeach; ?></table></div>
  <div class="card"><h2>🌐 Busiest IPs (7d)</h2><table class="compact"><?php foreach ($top_ips as $r): ?><tr><td><a href="tracking.php?ip=<?= e(urlencode($r['ip'])) ?>"><?= e($r['ip']) ?></a></td><td><?= (int)$r['c'] ?></td><td class="small muted"><?= (int)$r['u'] ?> users</td></tr><?php endforeach; ?></table></div>
  <div class="card"><h2>⚠️ IPs shared by 3+ users (30d)</h2><p class="hint">Can mean account sharing or one office network.</p><table class="compact"><?php foreach ($shared_ips as $r): ?><tr><td><a href="tracking.php?ip=<?= e(urlencode($r['ip'])) ?>"><?= e($r['ip']) ?></a></td><td><?= (int)$r['u'] ?> users</td></tr><?php endforeach; ?><?php if (!$shared_ips): ?><tr><td class="muted">None</td></tr><?php endif; ?></table></div>
</div>
<?php if (can('database')): ?><div class="card"><form method="post" class="input-group" style="max-width:520px"><?= csrf_field() ?><input type="hidden" name="action" value="purge"><input type="number" name="days" value="365" min="1"><button class="btn btn-ghost" data-confirm="Delete tracked events older than this?">🧹 Delete events older than (days)</button></form></div><?php endif; ?>
<?php admin_end();
