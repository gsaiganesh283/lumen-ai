<?php
require __DIR__ . '/_init.php';
$done = false;
if (is_post()) {
    $email = strtolower(input('email'));
    if (rate_limit('forgot:' . client_ip(), 5, 3600) && rate_limit("forgot:$email", 3, 3600)) {
        $u = row("SELECT * FROM users WHERE email = ? AND status IN ('active','suspended')", [$email]);
        if ($u) {
            q('UPDATE one_time_tokens SET used_at = ? WHERE purpose = ? AND owner_id = ? AND used_at IS NULL', [now(), 'reset', $u['id']]);
            $t = one_time_token('reset', (int)$u['id'], '+1 hour');
            send_mail($u['email'], 'password_reset', ['name' => $u['name'] ?: 'there', 'link' => site_url('/reset.php?token=' . $t)], (int)$u['id']);
            track('password_reset', ['user_id' => (int)$u['id'], 'label' => 'Reset link requested']);
        }
    }
    $done = true;   // same answer either way, so emails can't be guessed
}
auth_start('Forgot password');
?>
<div>
  <h1>Reset password</h1>
  <?php if ($done): ?>
    <div class="alert alert-success">If an account exists for that email, a reset link is on its way. It expires in 1 hour.</div>
    <p class="center"><a href="login.php">← Back to sign in</a></p>
  <?php else: ?>
    <p class="muted center">Enter your email and we'll send you a reset link.</p>
    <form method="post"><?= csrf_field() ?>
      <label for="email">Email</label><input id="email" name="email" type="email" required autofocus>
      <br><br><button class="btn btn-block">Send reset link</button></form>
    <p class="center small"><a href="login.php">← Back to sign in</a></p>
  <?php endif; ?>
</div>
<?php auth_end();
