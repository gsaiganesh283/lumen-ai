<?php
require __DIR__ . '/_init.php';
if (!$ADMIN) redirect('login.php');
if (!$ASESSION['twofa_pending']) redirect('index.php');
$error = '';
if (is_post()) {
    if (!rate_limit('a2fa:' . $ASESSION['id'], 5, 600)) {
        session_end('admin');
        flash('Too many wrong codes. Sign in again.', 'danger');
        redirect('login.php');
    }
    if (totp_verify(dec($ADMIN['totp_secret']), input('code'))) {
        q('UPDATE sessions SET twofa_pending = 0 WHERE id = ?', [$ASESSION['id']]);
        audit('login_2fa', 'admin', $ADMIN['id']);
        redirect('index.php');
    }
    log_login_attempt('admin', $ADMIN['email'], false, 'wrong 2FA code');
    $error = 'Wrong code.';
}
$GLOBALS['ADMIN'] = null;   // show the signed-out layout
admin_start('Two-step verification');
?>
<div class="auth-card"><div class="big-emoji">🔐</div><h1>Enter your code</h1>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input name="code" inputmode="numeric" maxlength="7" autofocus required autocomplete="one-time-code" style="font-size:28px;text-align:center;letter-spacing:8px">
    <br><br><button class="btn btn-block">Verify</button></form>
  <p class="center small"><a href="logout.php">Cancel</a></p></div>
<?php admin_end();
