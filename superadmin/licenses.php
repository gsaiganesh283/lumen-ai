<?php
require __DIR__ . '/_init.php';
$admin = require_admin('view');

if (admin_post('licenses')) {
    $act = input('bulk');
    if ($act === 'create') {
        $u = row('SELECT * FROM users WHERE email = ?', [strtolower(input('email'))]);
        if (!$u) { flash('No user with that email.', 'danger'); redirect('licenses.php'); }
        $n = max(1, min(50, input_int('count', 1)));
        $keys = [];
        for ($i = 0; $i < $n; $i++) $keys[] = issue_license((int)$u['id'], max(1, input_int('days', 30)), input_int('plan') ?: null, 'admin', 'admin:' . $admin['id'])['license_key'];
        if (isset($_POST['send_email'])) foreach ($keys as $k) send_key_email($u, row('SELECT * FROM licenses WHERE license_key = ?', [$k]), 'Custom');
        audit('license_create', 'user', $u['id'], implode(', ', $keys));
        flash('Created: ' . implode(', ', $keys), 'success');
        redirect('licenses.php');
    }
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    if (!$ids) { flash('Select licenses first.', 'danger'); redirect($_SERVER['REQUEST_URI']); }
    foreach ($ids as $lid) {
        $l = row('SELECT * FROM licenses WHERE id = ?', [$lid]);
        if (!$l) continue;
        if ($act === 'extend') extend_license($l, input_int('days', 7), 'admin:' . $admin['id']);
        elseif ($act === 'revoke') { update('licenses', ['status' => 'revoked', 'revoked_reason' => input('reason')], 'id = ?', [$lid]); license_event($lid, 'revoked', input('reason'), 'admin:' . $admin['id']); }
        elseif ($act === 'suspend') { update('licenses', ['status' => 'suspended'], 'id = ?', [$lid]); license_event($lid, 'suspended', input('reason'), 'admin:' . $admin['id']); }
        elseif ($act === 'reactivate') {
            $st = $l['activated_at'] ? ($l['expires_at'] > now() ? 'active' : 'expired') : 'issued';
            update('licenses', ['status' => $st, 'revoked_reason' => ''], 'id = ?', [$lid]);
            license_event($lid, 'reactivated', "→ $st", 'admin:' . $admin['id']);
        } elseif ($act === 'unlink') { update('licenses', ['device_pk' => null], 'id = ?', [$lid]); license_event($lid, 'unlinked', 'by admin', 'admin:' . $admin['id']); }
    }
    audit('license_bulk_' . $act, 'license', implode(',', $ids), input('reason') ?: input('days'));
    flash(ucfirst($act) . ' applied to ' . count($ids) . ' license(s).', 'success');
    redirect($_SERVER['REQUEST_URI']);
}

$where = ['1=1']; $p = [];
if (($s = input('q')) !== '') { $where[] = '(l.license_key LIKE ? OR u.email LIKE ? OR d.device_id LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%"); }
if (($v = input('status')) !== '') {
    if ($v === 'active') { $where[] = "l.status = 'active' AND l.expires_at > ?"; $p[] = now(); }
    else { $where[] = 'l.status = ?'; $p[] = $v; }
}
if (($v = input('source')) !== '') { $where[] = 'l.source = ?'; $p[] = $v; }
if (($v = input('plan')) !== '') { $where[] = 'l.plan_id = ?'; $p[] = (int)$v; }
if (($v = input_int('expiring')) > 0) { $where[] = "l.status = 'active' AND l.expires_at BETWEEN ? AND ?"; array_push($p, now(), ts_plus("+$v days")); }
if (input('unlinked') === '1') $where[] = 'l.device_pk IS NULL';
$w = implode(' AND ', $where);
$base = "FROM licenses l JOIN users u ON u.id = l.user_id LEFT JOIN devices d ON d.id = l.device_pk LEFT JOIN plans p ON p.id = l.plan_id WHERE $w";
$sort = ['exp' => 'l.expires_at IS NULL, l.expires_at', 'new' => 'l.id DESC', 'check' => 'l.last_check_at DESC'][input('sort')] ?? 'l.id DESC';

if (input('export') === 'csv') {
    audit('export_licenses', 'license', '', $w);
    $gen = (function () use ($base, $p, $sort) {
        foreach (q("SELECT l.*, u.email, d.device_id AS dcode, p.name AS pname $base ORDER BY $sort", $p) as $r)
            yield [$r['license_key'], $r['email'], $r['pname'] ?: $r['source'], $r['days'], effective_status($r), $r['dcode'], $r['activated_at'], $r['expires_at'], $r['last_check_at'], $r['issued_at']];
    })();
    csv_download('licenses-' . gmdate('Ymd') . '.csv', ['Key', 'Email', 'Plan', 'Days', 'Status', 'Device', 'Activated', 'Expires', 'Last check', 'Issued'], $gen);
}
$total = (int)val("SELECT COUNT(*) $base", $p);
[$lim, $off, $page, $pages] = paginate($total, 30);
$list = rows("SELECT l.*, u.email, d.device_id AS dcode, d.name AS dname, p.name AS pname $base ORDER BY $sort LIMIT $lim OFFSET $off", $p);
$plans = rows('SELECT id, name, days FROM plans ORDER BY sort');
$counts = rows('SELECT status, COUNT(*) c FROM licenses GROUP BY status');
admin_start('Licenses');
?>
<div class="grid grid-4"><?php foreach ($counts as $c): ?><?= stat_card(ucfirst($c['status']), number_format($c['c']), '', 'licenses.php?status=' . $c['status']) ?><?php endforeach; ?></div><br>
<?php if (can('licenses')): ?>
<details class="card" id="create" <?= isset($_GET['create']) ? 'open' : '' ?>><summary><b>➕ Create license keys for a user</b></summary>
  <form method="post" class="form-row" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="bulk" value="create">
    <div><label>User email</label><input name="email" type="email" required></div>
    <div><label>Days</label><input name="days" type="number" value="30" min="1"></div>
    <div><label>Plan label</label><select name="plan"><option value="0">Custom</option><?php foreach ($plans as $pl): ?><option value="<?= (int)$pl['id'] ?>"><?= e($pl['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>How many keys</label><input name="count" type="number" value="1" min="1" max="50"></div>
    <div><label class="check"><input type="checkbox" name="send_email" checked> Email keys</label><button class="btn">Create</button></div></form></details>
<?php endif; ?>
<form class="filters"><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Key, email or Device ID">
  <?= filter_select('status', ['active' => 'Active', 'issued' => 'Unused', 'expired' => 'Expired', 'suspended' => 'Suspended', 'revoked' => 'Revoked'], 'Any status') ?>
  <?= filter_select('source', ['purchase' => 'Purchased', 'admin' => 'Admin', 'trial' => 'Trial'], 'Any source') ?>
  <?= filter_select('plan', array_column($plans, 'name', 'id'), 'Any plan') ?>
  <?= filter_select('expiring', ['1' => 'Expiring in 1 day', '3' => 'in 3 days', '7' => 'in 7 days', '30' => 'in 30 days'], 'Expiry') ?>
  <?= filter_select('sort', ['new' => 'Newest', 'exp' => 'Expires soonest', 'check' => 'Last app check'], 'Sort') ?>
  <label class="check"><input type="checkbox" name="unlinked" value="1" <?= input('unlinked') ? 'checked' : '' ?> onchange="this.form.submit()"> No device</label>
  <button class="btn btn-sm">Filter</button><a class="btn btn-sm btn-ghost" href="licenses.php">Reset</a><a class="btn btn-sm btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">⬇ CSV</a></form>
<form method="post" class="card"><?= csrf_field() ?>
<?php if (can('licenses')): ?><div class="bulk-bar">With selected:
  <select name="bulk"><option value="extend">± Add days</option><option value="suspend">Suspend</option><option value="revoke">Revoke</option><option value="reactivate">Reactivate</option><option value="unlink">Unlink device</option></select>
  <input name="days" type="number" value="7" style="width:80px"><input name="reason" placeholder="Reason"><button class="btn btn-sm" data-confirm="Apply to selected licenses?">Apply</button></div><?php endif; ?>
<div class="table-wrap"><table class="compact"><thead><tr><th><input type="checkbox" data-bulk-all></th><th>Key</th><th>User</th><th>Plan</th><th>Status</th><th>Days left</th><th>Device</th><th>Expires</th><th>Last check</th></tr></thead><tbody>
<?php foreach ($list as $l): $st = effective_status($l); ?>
<tr><td><input type="checkbox" name="ids[]" value="<?= (int)$l['id'] ?>" data-bulk></td><td><code><?= e($l['license_key']) ?></code>
    <div class="actions" style="margin-top:4px"><a class="small" href="document.php?type=certificate&id=<?= (int)$l['id'] ?>" target="_blank">🎓 certificate</a><a class="small" href="#" data-copy="<?= e($l['license_key']) ?>">📋 copy</a></div></td>
  <td><a href="user.php?id=<?= (int)$l['user_id'] ?>&tab=licenses"><?= e($l['email']) ?></a></td><td><?= e($l['pname'] ?: ucfirst($l['source'])) ?> · <?= (int)$l['days'] ?>d</td>
  <td><?= badge($st) ?></td><td><?= $l['expires_at'] ? days_left($l) : '—' ?></td><td class="small"><?= $l['dcode'] ? e($l['dname']) . '<div class="muted">' . e($l['dcode']) . '</div>' : '—' ?></td>
  <td class="small"><?= local_dt($l['expires_at']) ?></td><td class="small"><?= time_ago($l['last_check_at']) ?></td></tr>
<?php endforeach; ?><?php if (!$list): ?><tr><td colspan="9" class="empty">No licenses match.</td></tr><?php endif; ?>
</tbody></table></div><?= pager($page, $pages, $total) ?></form>
<?php admin_end();
