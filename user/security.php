<?php
require __DIR__ . '/_init.php';
$u = require_login(false);

if (is_post()) {
    block_if_impersonating();
    $action = input('action');
    if ($action === 'password') {
        $cur = $_POST['current'] ?? '';
        $new = $_POST['password'] ?? '';
        if ($u['password_hash'] && !password_verify($cur, $u['password_hash'])) flash('Your current password is wrong.', 'danger');
        elseif ($p = password_problems($new, $u['email'])) flash('New password must have ' . implode(', ', $p) . '.', 'danger');
        elseif ($new !== ($_POST['password2'] ?? '')) flash("New passwords don't match.", 'danger');
        else {
            update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
            q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id = ? AND id <> ?", [$u['id'], $SESSION['id']]);
            send_mail($u['email'], 'password_changed', ['name' => $u['name'] ?: 'there', 'ip' => client_ip()], (int)$u['id']);
            track('password_changed', ['user_id' => (int)$u['id'], 'label' => $u['password_hash'] ? 'Changed password' : 'Set a password']);
            flash('Password updated. Other devices were signed out.', 'success');
        }
    } elseif ($action === '2fa_start') {
        update('users', ['totp_secret' => enc(totp_new_secret()), 'totp_enabled' => 0], 'id = ?', [$u['id']]);
        redirect('security.php?setup=1');
    } elseif ($action === '2fa_confirm') {
        if (totp_verify(dec($u['totp_secret']), input('code'))) {
            update('users', ['totp_enabled' => 1], 'id = ?', [$u['id']]);
            track('2fa_enabled', ['user_id' => (int)$u['id'], 'label' => 'Turned on 2FA']);
            flash('Two-step verification is on 🔐', 'success');
        } else {
            flash('Wrong code — check your phone\'s time and try again.', 'danger');
            redirect('security.php?setup=1');
        }
    } elseif ($action === '2fa_disable') {
        if (totp_verify(dec($u['totp_secret']), input('code')) || ($u['password_hash'] && password_verify($_POST['password'] ?? '', $u['password_hash']))) {
            update('users', ['totp_enabled' => 0, 'totp_secret' => null], 'id = ?', [$u['id']]);
            track('2fa_disabled', ['user_id' => (int)$u['id'], 'label' => 'Turned off 2FA']);
            flash('Two-step verification is off.', 'info');
        } else flash('Enter a valid code or your password to turn off 2FA.', 'danger');
    } elseif ($action === 'revoke') {
        q("UPDATE sessions SET revoked = 1 WHERE id = ? AND kind = 'user' AND owner_id = ?", [input_int('id'), $u['id']]);
        flash('Session signed out.', 'success');
    } elseif ($action === 'revoke_all') {
        q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id = ? AND id <> ?", [$u['id'], $SESSION['id']]);
        track('sessions_revoked', ['user_id' => (int)$u['id'], 'label' => 'Signed out all other sessions']);
        flash('All other sessions were signed out.', 'success');
    }
    redirect('security.php');
}

$u = user_by_id((int)$u['id']);
$sessions = rows("SELECT * FROM sessions WHERE kind = 'user' AND owner_id = ? AND revoked = 0 AND expires_at > ? AND impersonated_by IS NULL ORDER BY last_seen_at DESC", [$u['id'], now()]);
$attempts = rows("SELECT * FROM login_attempts WHERE kind = 'user' AND email = ? ORDER BY id DESC LIMIT 15", [$u['email']]);
page_start('Security', 'security.php');
?>
<div class="grid grid-2">
<div class="card"><h2>🔑 <?= $u['password_hash'] ? 'Change password' : 'Set a password' ?></h2>
  <?php if (!$u['password_hash']): ?><p class="muted small">You sign in with Google. You can also set a password to sign in with your email.</p><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password">
    <?php if ($u['password_hash']): ?><label>Current password</label><input type="password" name="current" required autocomplete="current-password"><?php endif; ?>
    <label>New password</label><input type="password" name="password" required data-strength autocomplete="new-password"><div class="strength"><div></div></div>
    <label>Confirm new password</label><input type="password" name="password2" required autocomplete="new-password">
    <br><br><button class="btn">Save password</button></form></div>

<div class="card"><h2>🔐 Two-step verification</h2>
<?php if ($u['totp_enabled']): ?>
  <div class="alert alert-success">On — you'll be asked for a code from your authenticator app when signing in.</div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_disable">
    <label>Current code<?= $u['password_hash'] ? ' or password' : '' ?> to turn off</label>
    <div class="input-group"><input name="code" placeholder="123456" inputmode="numeric"><?php if ($u['password_hash']): ?><input type="password" name="password" placeholder="Password"><?php endif; ?></div>
    <br><button class="btn btn-ghost">Turn off</button></form>
<?php elseif (isset($_GET['setup']) && $u['totp_secret']): $secret = dec($u['totp_secret']); ?>
  <p>1. Scan this QR code with Google Authenticator, Microsoft Authenticator or Authy.</p>
  <div class="qr" id="qr"></div>
  <p class="small muted">Or enter this key: <code><?= e(chunk_split($secret, 4, ' ')) ?></code></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_confirm">
    <label>2. Enter the 6-digit code</label><div class="input-group"><input name="code" required inputmode="numeric" maxlength="7" autocomplete="one-time-code"><button class="btn">Turn on</button></div></form>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>new QRCode(document.getElementById('qr'), { text: <?= json_encode(totp_uri($secret, $u['email'], setting('site_name'))) ?>, width: 180, height: 180 });</script>
<?php else: ?>
  <p class="muted">Protect your account with a code from your phone in addition to your password.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_start"><button class="btn">Set up 2FA</button></form>
<?php endif; ?>
</div></div>

<div class="card"><div class="card-head"><h2>💻 Where you're signed in</h2>
  <form method="post" data-confirm="Sign out all other sessions?"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_all"><button class="btn btn-sm btn-ghost">Sign out all others</button></form></div>
<div class="table-wrap"><table><thead><tr><th>Browser</th><th>IP</th><th>Signed in</th><th>Last active</th><th></th></tr></thead><tbody>
<?php foreach ($sessions as $s): ?>
  <tr><td><?= e(short_agent($s['user_agent'])) ?> <?= (int)$s['id'] === (int)$SESSION['id'] ? '<span class="badge badge-green">This device</span>' : '' ?></td><td><?= e($s['ip']) ?></td>
    <td><?= local_dt($s['created_at']) ?></td><td><?= time_ago($s['last_seen_at']) ?></td>
    <td><?php if ((int)$s['id'] !== (int)$SESSION['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn btn-sm btn-ghost">Sign out</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>

<div class="card"><h2>📜 Recent sign-in attempts</h2>
<div class="table-wrap"><table><thead><tr><th>Time</th><th>Result</th><th>IP</th><th>Browser</th></tr></thead><tbody>
<?php if (!$attempts): ?><tr><td colspan="4" class="empty">No sign-in attempts recorded.</td></tr><?php endif; ?>
<?php foreach ($attempts as $a): ?>
  <tr><td><?= local_dt($a['created_at']) ?></td><td><?= $a['success'] ? badge('active') . ' ' . e($a['reason']) : '<span class="badge badge-red">Failed</span> ' . e($a['reason']) ?></td>
    <td><?= e($a['ip']) ?></td><td><?= e(short_agent($a['user_agent'])) ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php page_end();
