<?php
require __DIR__ . '/_init.php';
if (!$USER) redirect('login.php');
if (!$SESSION['twofa_pending']) after_login_redirect();

$error = '';
if (is_post()) {
    if (!rate_limit('2fa:' . $SESSION['id'], 6, 600)) {
        session_end('user');
        flash('Too many wrong codes. Please sign in again.', 'danger');
        redirect('login.php');
    }
    if (totp_verify(dec($USER['totp_secret']), input('code'))) {
        q('UPDATE sessions SET twofa_pending = 0 WHERE id = ?', [$SESSION['id']]);
        track('login_2fa', ['user_id' => (int)$USER['id'], 'label' => '2FA code accepted']);
        after_login_redirect();
    }
    $error = 'That code is not correct. Use the current 6-digit code from your authenticator app.';
    track('login_failed', ['user_id' => (int)$USER['id'], 'label' => 'Wrong 2FA code']);
}
auth_start('Two-step verification');
?>
<div>
  <div class="big-emoji">🔐</div>
  <h1>Two-step verification</h1>
  <p class="muted center">Enter the 6-digit code from your authenticator app.</p>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e(input('next')) ?>">
    <input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autofocus required autocomplete="one-time-code"
      style="font-size:28px;text-align:center;letter-spacing:8px">
    <br><br><button class="btn btn-block">Verify</button></form>
  <p class="center small"><a href="logout.php">Cancel and sign out</a></p>
</div>
<?php auth_end();
