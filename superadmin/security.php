<?php
require __DIR__ . '/_init.php';
$admin = require_admin('security');

if (admin_post('security')) {
    $act = input('action');
    if ($act === 'block') {
        $ip = trim(input('ip'));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) flash('Enter a valid IP address.', 'danger');
        elseif ($ip === client_ip()) flash("That's your own IP.", 'danger');
        else {
            $h = input_int('hours');
            q('INSERT INTO ip_blocks (ip, reason, expires_at, created_by, created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)',
                [$ip, input('reason'), $h > 0 ? ts_plus("+$h hours") : null, $admin['id'], now()]);
            audit('ip_block', 'ip', $ip, input('reason'));
            flash("Blocked $ip.", 'success');
        }
    } elseif ($act === 'unblock') { q('DELETE FROM ip_blocks WHERE id = ?', [input_int('id')]); audit('ip_unblock', 'ip', input_int('id')); flash('Unblocked.', 'success'); }
    elseif ($act === 'revoke') { q('UPDATE sessions SET revoked = 1 WHERE id = ?', [input_int('id')]); audit('session_revoke', 'session', input_int('id')); flash('Session revoked.', 'success'); }
    elseif ($act === 'revoke_all_users') { $n = q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND revoked = 0")->rowCount(); audit('sessions_revoke_all', 'session', 'user', "$n"); flash("Signed out $n user sessions.", 'success'); }
    elseif ($act === 'clear_rate') { q('DELETE FROM rate_limits'); flash('Rate-limit counters cleared.', 'success'); }
    elseif ($act === 'maintenance') { settings_set(['maintenance_mode' => !setting('maintenance_mode')]); audit('maintenance_toggle', 'settings'); flash('Maintenance mode ' . (setting('maintenance_mode') ? 'ON' : 'OFF'), 'success'); }
    elseif ($act === 'kill' && $admin['role'] === 'superadmin') { settings_set(['kill_switch' => !setting('kill_switch')]); audit('kill_switch_toggle', 'settings'); flash('Desktop kill switch ' . (setting('kill_switch') ? 'ON — all apps stop at next check-in' : 'OFF'), 'success'); }
    elseif ($act === 'registration') { settings_set(['registration_open' => !setting('registration_open')]); audit('registration_toggle', 'settings'); flash('Registration ' . (setting('registration_open') ? 'opened' : 'closed'), 'success'); }
    redirect('security.php');
}
$blocks = rows('SELECT b.*, a.name AS by_name FROM ip_blocks b LEFT JOIN admins a ON a.id = b.created_by ORDER BY b.id DESC');
$suspicious = rows('SELECT ip, COUNT(*) c, COUNT(DISTINCT email) emails, MAX(created_at) last FROM login_attempts WHERE success = 0 AND created_at >= ? GROUP BY ip HAVING c >= 5 ORDER BY c DESC LIMIT 15', [ts_plus('-24 hours')]);
$w = '1=1'; $p = [];
if (($v = input('kind')) !== '') { $w .= ' AND kind = ?'; $p[] = $v; }
if (input('failed') === '1') $w .= ' AND success = 0';
if (($v = input('q')) !== '') { $w .= ' AND (email LIKE ? OR ip = ?)'; array_push($p, "%$v%", $v); }
$total = (int)val("SELECT COUNT(*) FROM login_attempts WHERE $w", $p);
[$lim, $off, $page, $pages] = paginate($total, 30);
$attempts = rows("SELECT * FROM login_attempts WHERE $w ORDER BY id DESC LIMIT $lim OFFSET $off", $p);
$sessions = rows("SELECT s.*, COALESCE(u.email, a.email) AS email FROM sessions s LEFT JOIN users u ON s.kind = 'user' AND u.id = s.owner_id LEFT JOIN admins a ON s.kind = 'admin' AND a.id = s.owner_id
    WHERE s.revoked = 0 AND s.expires_at > ? ORDER BY s.last_seen_at DESC LIMIT 50", [now()]);
admin_start('Security');
?>
<div class="card"><h2>🚨 Quick switches</h2><div class="actions">
  <form method="post"><?= csrf_field() ?><button class="btn <?= setting('maintenance_mode') ? 'btn-amber' : 'btn-ghost' ?>" name="action" value="maintenance" data-confirm="Toggle maintenance mode?">🛠 Maintenance: <?= setting('maintenance_mode') ? 'ON' : 'off' ?></button></form>
  <form method="post"><?= csrf_field() ?><button class="btn <?= setting('registration_open') ? 'btn-ghost' : 'btn-amber' ?>" name="action" value="registration">🆕 Registration: <?= setting('registration_open') ? 'open' : 'CLOSED' ?></button></form>
  <?php if ($admin['role'] === 'superadmin'): ?><form method="post"><?= csrf_field() ?><button class="btn <?= setting('kill_switch') ? 'btn-danger' : 'btn-ghost' ?>" name="action" value="kill" data-confirm="Toggle the desktop app kill switch? When ON, every app stops working at its next check-in.">⛔ Desktop kill switch: <?= setting('kill_switch') ? 'ON' : 'off' ?></button></form><?php endif; ?>
  <form method="post"><?= csrf_field() ?><button class="btn btn-ghost" name="action" value="revoke_all_users" data-confirm="Sign out every user everywhere?">🚪 Sign out all users</button></form>
  <form method="post"><?= csrf_field() ?><button class="btn btn-ghost" name="action" value="clear_rate">♻ Clear rate limits</button></form></div></div>
<div class="grid grid-2">
<div class="card"><h2>⛔ Blocked IPs</h2>
  <form method="post" class="form-row" style="align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="block"><div><label>IP</label><input name="ip" required value="<?= e(input('ip')) ?>"></div><div><label>Reason</label><input name="reason"></div>
    <div><label>For</label><select name="hours"><option value="0">Forever</option><option value="1">1 hour</option><option value="24">24 hours</option><option value="168">7 days</option></select></div><div><button class="btn btn-danger">Block</button></div></form>
  <table class="compact" style="margin-top:12px"><?php foreach ($blocks as $b): ?><tr><td><code><?= e($b['ip']) ?></code></td><td class="small"><?= e($b['reason']) ?></td><td class="small"><?= $b['expires_at'] ? 'until ' . local_dt($b['expires_at']) : 'forever' ?> · <?= e($b['by_name']) ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unblock"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn-sm btn-ghost">Unblock</button></form></td></tr><?php endforeach; ?>
  <?php if (!$blocks): ?><tr><td class="muted">No blocked IPs.</td></tr><?php endif; ?></table></div>
<div class="card"><h2>🕵 Suspicious IPs (5+ failed logins, 24h)</h2><table class="compact"><?php foreach ($suspicious as $s): ?><tr><td><code><?= e($s['ip']) ?></code></td><td><?= (int)$s['c'] ?> fails · <?= (int)$s['emails'] ?> emails</td><td class="small"><?= time_ago($s['last']) ?></td>
  <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="block"><input type="hidden" name="ip" value="<?= e($s['ip']) ?>"><input type="hidden" name="reason" value="Brute force"><input type="hidden" name="hours" value="24"><button class="btn btn-sm btn-danger">Block 24h</button></form></td></tr><?php endforeach; ?>
  <?php if (!$suspicious): ?><tr><td class="muted">Nothing suspicious 👍</td></tr><?php endif; ?></table></div>
</div>
<div class="card"><h2>🔑 Sign-in attempts</h2>
  <form class="filters"><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Email or IP"><?= filter_select('kind', ['user' => 'Users', 'admin' => 'Admins'], 'Users + admins') ?>
    <label class="check"><input type="checkbox" name="failed" value="1" <?= input('failed') ? 'checked' : '' ?> onchange="this.form.submit()"> Failed only</label><button class="btn btn-sm">Filter</button></form>
  <div class="table-wrap"><table class="compact"><thead><tr><th>Time</th><th>Who</th><th>Result</th><th>IP</th><th>Browser</th></tr></thead><tbody>
  <?php foreach ($attempts as $a): ?><tr><td class="small"><?= local_dt($a['created_at']) ?></td><td><?= e($a['email']) ?> <span class="badge badge-gray"><?= e($a['kind']) ?></span></td>
    <td><?= $a['success'] ? badge('active') : '<span class="badge badge-red">Failed</span>' ?> <span class="small"><?= e($a['reason']) ?></span></td><td><a href="tracking.php?ip=<?= e(urlencode($a['ip'])) ?>"><?= e($a['ip']) ?></a></td><td class="small"><?= e(short_agent($a['user_agent'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?= pager($page, $pages, $total) ?></div>
<div class="card"><h2>💻 Active sessions (latest 50)</h2><div class="table-wrap"><table class="compact"><thead><tr><th>Who</th><th>Type</th><th>IP</th><th>Browser</th><th>Last active</th><th></th></tr></thead><tbody>
<?php foreach ($sessions as $s): ?><tr><td><?= $s['kind'] === 'user' ? user_link((int)$s['owner_id'], $s['email']) : e($s['email']) ?></td><td><?= e($s['kind']) ?><?= $s['impersonated_by'] ? ' <span class="badge badge-amber">view-as</span>' : '' ?></td><td><?= e($s['ip']) ?></td>
  <td class="small"><?= e(short_agent($s['user_agent'])) ?></td><td class="small"><?= time_ago($s['last_seen_at']) ?></td>
  <td><?php if ((int)$s['id'] !== (int)$ASESSION['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-ghost">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php admin_end();
