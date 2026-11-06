<?php
require __DIR__ . '/_init.php';
$token = input('token');
$t = $token !== '' ? row('SELECT * FROM one_time_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
    [token_hash($token), 'reset', now()]) : null;
$errors = [];
if ($t && is_post()) {
    $u = user_by_id((int)$t['owner_id']);
    $pw = $_POST['password'] ?? '';
    if ($p = password_problems($pw, $u['email'])) $errors[] = 'Password must have ' . implode(', ', $p) . '.';
    if ($pw !== ($_POST['password2'] ?? '')) $errors[] = "Passwords don't match.";
    if (!$errors) {
        consume_token('reset', $token);
        update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'failed_logins' => 0, 'locked_until' => null,
            'email_verified' => 1, 'email_verified_at' => $u['email_verified_at'] ?: now()], 'id = ?', [$u['id']]);
        q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id = ?", [$u['id']]);   // sign out everywhere
        send_mail($u['email'], 'password_changed', ['name' => $u['name'] ?: 'there', 'ip' => client_ip()], (int)$u['id']);
        track('password_reset', ['user_id' => (int)$u['id'], 'label' => 'Password reset completed']);
        flash('Password updated. Please sign in with your new password.', 'success');
        redirect('login.php');
    }
}
auth_start('Choose a new password');
?>
<div>
  <h1>New password</h1>
  <?php if (!$t): ?>
    <div class="alert alert-danger">This reset link is invalid or has expired.</div>
    <p class="center"><a class="btn" href="forgot.php">Get a new link</a></p>
  <?php else: ?>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <label for="password">New password</label>
      <input id="password" name="password" type="password" required data-strength autocomplete="new-password">
      <div class="strength"><div></div></div>
      <label for="password2">Confirm password</label>
      <input id="password2" name="password2" type="password" required autocomplete="new-password">
      <br><br><button class="btn btn-block">Save password</button></form>
  <?php endif; ?>
</div>
<?php auth_end();
