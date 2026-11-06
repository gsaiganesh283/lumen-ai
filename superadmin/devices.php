<?php
require __DIR__ . '/_init.php';
require_admin('view');

if (admin_post('users')) {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $act = input('bulk');
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($act === 'block') q("UPDATE devices SET status = 'blocked' WHERE id IN ($in)", $ids);
        if ($act === 'unblock') q("UPDATE devices SET status = 'active' WHERE id IN ($in)", $ids);
        if ($act === 'delete') { q("UPDATE licenses SET device_pk = NULL WHERE device_pk IN ($in)", $ids); q("DELETE FROM devices WHERE id IN ($in)", $ids); }
        audit('device_bulk_' . $act, 'device', implode(',', $ids));
        flash(ucfirst($act) . ' done for ' . count($ids) . ' device(s).', 'success');
    }
    redirect($_SERVER['REQUEST_URI']);
}
$hb = ts_plus('-' . (setting('heartbeat_minutes') * 2 + 1) . ' minutes');
$where = ['1=1']; $p = [];
if (($s = input('q')) !== '') { $where[] = '(d.device_id LIKE ? OR d.name LIKE ? OR d.hostname LIKE ? OR d.last_ip = ? OR u.email LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%", $s, "%$s%"); }
if (($v = input('status')) !== '') { $where[] = 'd.status = ?'; $p[] = $v; }
if (input('online') === '1') { $where[] = 'd.last_seen_at > ?'; $p[] = $hb; }
if (($v = input('version')) !== '') { $where[] = 'd.app_version = ?'; $p[] = $v; }
$w = implode(' AND ', $where);
$base = "FROM devices d JOIN users u ON u.id = d.user_id WHERE $w";
if (input('export') === 'csv') {
    $gen = (function () use ($base, $p) { foreach (q("SELECT d.*, u.email $base ORDER BY d.id DESC", $p) as $r) yield [$r['device_id'], $r['email'], $r['name'], $r['hostname'], $r['os'], $r['app_version'], $r['status'], $r['last_ip'], $r['last_seen_at'], $r['created_at']]; })();
    csv_download('devices-' . gmdate('Ymd') . '.csv', ['Device ID', 'Email', 'Name', 'Hostname', 'OS', 'App version', 'Status', 'Last IP', 'Last seen', 'Added'], $gen);
}
$total = (int)val("SELECT COUNT(*) $base", $p);
[$lim, $off, $page, $pages] = paginate($total, 30);
$list = rows("SELECT d.*, u.email, (SELECT license_key FROM licenses l WHERE l.device_pk = d.id ORDER BY FIELD(l.status,'active','issued','suspended','expired','revoked'), l.id DESC LIMIT 1) AS lkey $base ORDER BY d.last_seen_at IS NULL, d.last_seen_at DESC LIMIT $lim OFFSET $off", $p);
$versions = array_column(rows("SELECT app_version v, COUNT(*) c FROM devices WHERE app_version <> '' GROUP BY app_version ORDER BY c DESC"), 'c', 'v');
admin_start('Devices');
?>
<div class="grid grid-4">
  <?= stat_card('Total devices', number_format((int)val('SELECT COUNT(*) FROM devices'))) ?>
  <?= stat_card('Online now', (int)val('SELECT COUNT(*) FROM devices WHERE last_seen_at > ?', [$hb]), 'checked in recently', 'devices.php?online=1') ?>
  <?= stat_card('Blocked', (int)val("SELECT COUNT(*) FROM devices WHERE status = 'blocked'"), '', 'devices.php?status=blocked') ?>
  <?= stat_card('App versions', count($versions), implode(' · ', array_map(fn($v, $c) => "v$v: $c", array_keys(array_slice($versions, 0, 3, true)), array_slice($versions, 0, 3)))) ?>
</div><br>
<form class="filters"><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Device ID, name, hostname, IP, email">
  <?= filter_select('status', ['active' => 'Active', 'blocked' => 'Blocked', 'removed' => 'Removed'], 'Any status') ?>
  <?php if ($versions): ?><?= filter_select('version', array_combine(array_keys($versions), array_map(fn($v) => "v$v", array_keys($versions))), 'Any version') ?><?php endif; ?>
  <label class="check"><input type="checkbox" name="online" value="1" <?= input('online') ? 'checked' : '' ?> onchange="this.form.submit()"> Online now</label>
  <button class="btn btn-sm">Filter</button><a class="btn btn-sm btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">⬇ CSV</a></form>
<form method="post" class="card"><?= csrf_field() ?>
<?php if (can('users')): ?><div class="bulk-bar">With selected: <select name="bulk"><option value="block">Block</option><option value="unblock">Unblock</option><option value="delete">Delete</option></select><button class="btn btn-sm" data-confirm="Apply to selected devices?">Apply</button></div><?php endif; ?>
<div class="table-wrap"><table class="compact"><thead><tr><th><input type="checkbox" data-bulk-all></th><th>Device</th><th>User</th><th>PC / app</th><th>License</th><th>Last seen</th><th>Status</th></tr></thead><tbody>
<?php foreach ($list as $d): $on = $d['last_seen_at'] && $d['last_seen_at'] > $hb; ?>
<tr><td><input type="checkbox" name="ids[]" value="<?= (int)$d['id'] ?>" data-bulk></td><td><?= $on ? '<span class="live-dot"></span> ' : '' ?><code><?= e($d['device_id']) ?></code><div class="small muted"><?= e($d['name']) ?></div></td>
  <td><a href="user.php?id=<?= (int)$d['user_id'] ?>&tab=devices"><?= e($d['email']) ?></a></td><td class="small"><?= e($d['hostname']) ?> · v<?= e($d['app_version'] ?: '?') ?><div class="muted"><?= e($d['os']) ?></div></td>
  <td class="small"><code><?= e($d['lkey'] ?: '—') ?></code></td><td class="small"><?= time_ago($d['last_seen_at']) ?><div class="muted"><?= e($d['last_ip']) ?></div></td><td><?= badge($d['status']) ?></td></tr>
<?php endforeach; ?><?php if (!$list): ?><tr><td colspan="7" class="empty">No devices.</td></tr><?php endif; ?></tbody></table></div><?= pager($page, $pages, $total) ?></form>
<?php admin_end();
