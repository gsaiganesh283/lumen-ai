<?php
require __DIR__ . '/_init.php';
require_admin('audit');
$w = '1=1'; $p = [];
if (($v = input_int('admin')) > 0) { $w .= ' AND l.admin_id = ?'; $p[] = $v; }
if (($v = input('action')) !== '') { $w .= ' AND l.action = ?'; $p[] = $v; }
if (($v = input('q')) !== '') { $w .= ' AND (l.detail LIKE ? OR l.target_id = ? OR l.ip = ?)'; array_push($p, "%$v%", $v, $v); }
if (($v = input('from')) !== '') { $w .= ' AND l.created_at >= ?'; $p[] = "$v 00:00:00"; }
if (($v = input('to')) !== '') { $w .= ' AND l.created_at <= ?'; $p[] = "$v 23:59:59"; }
$base = "FROM audit_logs l LEFT JOIN admins a ON a.id = l.admin_id WHERE $w";
if (input('export') === 'csv') {
    $gen = (function () use ($base, $p) { foreach (q("SELECT l.*, a.email $base ORDER BY l.id DESC", $p) as $r) yield [$r['created_at'], $r['email'], $r['action'], $r['target_type'], $r['target_id'], $r['detail'], $r['ip']]; })();
    csv_download('audit-' . gmdate('Ymd') . '.csv', ['Time (UTC)', 'Admin', 'Action', 'Target', 'Target ID', 'Detail', 'IP'], $gen);
}
$total = (int)val("SELECT COUNT(*) $base", $p);
[$lim, $off, $page, $pages] = paginate($total, 40);
$list = rows("SELECT l.*, a.email, a.name $base ORDER BY l.id DESC LIMIT $lim OFFSET $off", $p);
$actions = array_column(rows('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
$admins = rows('SELECT id, email FROM admins');
admin_start('Audit log');
?>
<p class="muted">Every admin action is recorded here and can't be edited from the portal.</p>
<form class="filters"><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Detail, target ID or IP"><?= filter_select('admin', array_column($admins, 'email', 'id'), 'All admins') ?>
  <?= filter_select('action', array_combine($actions, $actions), 'All actions') ?><input type="date" name="from" value="<?= e(input('from')) ?>"><input type="date" name="to" value="<?= e(input('to')) ?>">
  <button class="btn btn-sm">Filter</button><a class="btn btn-sm btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">⬇ CSV</a></form>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead><tbody>
<?php foreach ($list as $l): ?><tr><td class="small" style="white-space:nowrap"><?= local_dt($l['created_at']) ?></td><td class="small"><?= e($l['name'] ?: $l['email'] ?: 'system') ?></td><td><code><?= e($l['action']) ?></code></td>
  <td class="small"><?= $l['target_type'] === 'user' && is_numeric($l['target_id']) ? user_link((int)$l['target_id']) : e($l['target_type'] . ' ' . $l['target_id']) ?></td>
  <td class="small"><div class="cell-trunc" title="<?= e($l['detail']) ?>" style="max-width:420px"><?= e($l['detail']) ?></div></td><td class="small"><?= e($l['ip']) ?></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="6" class="empty">Nothing logged yet.</td></tr><?php endif; ?></tbody></table></div><?= pager($page, $pages, $total) ?></div>
<?php admin_end();
