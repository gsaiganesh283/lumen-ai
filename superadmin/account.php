<?php
require __DIR__ . '/_init.php';
$a = require_admin('view');

if (admin_post('view')) {
    $act = input('action');
    if ($act === 'profile') {
        update('admins', ['name' => mb_substr(input('name'), 0, 120)], 'id = ?', [$a['id']]);
        flash('Saved.', 'success');
    } elseif ($act === 'password') {
        $new = $_POST['password'] ?? '';
        if (!password_verify($_POST['current'] ?? '', $a['password_hash'])) flash('Current password is wrong.', 'danger');
        elseif (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) flash('Use 10+ characters with letters and numbers.', 'danger');
        elseif ($new !== ($_POST['password2'] ?? '')) flash("Passwords don't match.", 'danger');
        else {
            update('admins', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$a['id']]);
            q("UPDATE sessions SET revoked = 1 WHERE kind = 'admin' AND owner_id = ? AND id <> ?", [$a['id'], $ASESSION['id']]);
            audit('password_changed', 'admin', $a['id']);
            flash('Password changed. Your other sessions were signed out.', 'success');
        }
    } elseif ($act === '2fa_start') {
        update('admins', ['totp_secret' => enc(totp_new_secret()), 'totp_enabled' => 0], 'id = ?', [$a['id']]);
        redirect('account.php?setup=1');
    } elseif ($act === '2fa_confirm') {
        if (totp_verify(dec($a['totp_secret']), input('code'))) {
            update('admins', ['totp_enabled' => 1], 'id = ?', [$a['id']]);
            audit('2fa_enabled', 'admin', $a['id']);
            flash('2FA is on 🔐', 'success');
        } else { flash('Wrong code.', 'danger'); redirect('account.php?setup=1'); }
    } elseif ($act === '2fa_disable') {
        if (setting('require_admin_2fa')) flash('2FA is required for admins and cannot be turned off.', 'danger');
        elseif (password_verify($_POST['current'] ?? '', $a['password_hash'])) {
            update('admins', ['totp_enabled' => 0, 'totp_secret' => null], 'id = ?', [$a['id']]);
            audit('2fa_disabled', 'admin', $a['id']);
            flash('2FA turned off.', 'info');
        } else flash('Password is wrong.', 'danger');
    } elseif ($act === 'revoke_all') {
        q("UPDATE sessions SET revoked = 1 WHERE kind = 'admin' AND owner_id = ? AND id <> ?", [$a['id'], $ASESSION['id']]);
        flash('Other sessions signed out.', 'success');
    }
    redirect('account.php');
}
$a = row('SELECT * FROM admins WHERE id = ?', [$a['id']]);
$sessions = rows("SELECT * FROM sessions WHERE kind = 'admin' AND owner_id = ? AND revoked = 0 AND expires_at > ? ORDER BY last_seen_at DESC", [$a['id'], now()]);
admin_start('My account');
?>
<div class="grid grid-2">
<div class="card"><h2>👤 Profile</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
  <label>Email</label><input value="<?= e($a['email']) ?>" disabled><label>Name</label><input name="name" value="<?= e($a['name']) ?>">
  <p class="small muted">Role: <?= badge($a['role']) ?> · Last sign-in <?= local_dt($a['last_login_at']) ?> from <?= e($a['last_login_ip']) ?></p>
  <button class="btn">Save</button></form></div>
<div class="card"><h2>🔑 Password</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
  <label>Current</label><input type="password" name="current" required><label>New (10+ chars)</label><input type="password" name="password" required>
  <label>Confirm</label><input type="password" name="password2" required><br><br><button class="btn">Change</button></form></div>
</div>
<div class="card"><h2>🔐 Two-step verification</h2>
<?php if ($a['totp_enabled']): ?>
  <div class="alert alert-success">On.</div>
  <form method="post" class="input-group" style="max-width:420px"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_disable"><input type="password" name="current" placeholder="Password" required><button class="btn btn-ghost">Turn off</button></form>
<?php elseif (isset($_GET['setup']) && $a['totp_secret']): $secret = dec($a['totp_secret']); ?>
  <div class="qr" id="qr"></div><p class="small muted">Key: <code><?= e(chunk_split($secret, 4, ' ')) ?></code></p>
  <form method="post" class="input-group" style="max-width:360px"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_confirm"><input name="code" placeholder="6-digit code" required><button class="btn">Turn on</button></form>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>new QRCode(document.getElementById('qr'), { text: <?= json_encode(totp_uri($secret, $a['email'], setting('site_name') . ' Admin')) ?>, width: 180, height: 180 });</script>
<?php else: ?>
  <p class="muted">Strongly recommended for every admin.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_start"><button class="btn">Set up 2FA</button></form>
<?php endif; ?></div>
<div class="card"><div class="card-head"><h2>💻 My sessions</h2><form method="post"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" name="action" value="revoke_all">Sign out others</button></form></div>
<div class="table-wrap"><table class="compact"><thead><tr><th>Browser</th><th>IP</th><th>Started</th><th>Last active</th></tr></thead><tbody>
<?php foreach ($sessions as $s): ?><tr><td><?= e(short_agent($s['user_agent'])) ?><?= (int)$s['id'] === (int)$ASESSION['id'] ? ' ' . badge('active') : '' ?></td><td><?= e($s['ip']) ?></td><td><?= local_dt($s['created_at']) ?></td><td><?= time_ago($s['last_seen_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php admin_end();
