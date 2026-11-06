<?php
require __DIR__ . '/_init.php';
if ($ADMIN && !$ASESSION['twofa_pending']) redirect('index.php');
$error = '';
$email = strtolower(input('email'));

if (is_post()) {
    $ip = client_ip();
    if (!rate_limit("alogin:$ip", 10, 900)) {
        $error = 'Too many attempts. Wait 15 minutes.';
        log_login_attempt('admin', $email, false, 'rate limited');
    } else {
        $a = row('SELECT * FROM admins WHERE email = ?', [$email]);
        if ($a && $a['locked_until'] && $a['locked_until'] > now()) {
            $error = 'Account locked after failed attempts. Try again ' . time_ago($a['locked_until']) . '.';
            log_login_attempt('admin', $email, false, 'locked');
        } elseif (!$a || !$a['active'] || !password_verify($_POST['password'] ?? '', $a['password_hash'])) {
            $error = 'Wrong email or password.';
            if ($a) {
                $fails = (int)$a['failed_logins'] + 1;
                $lock = $fails >= setting('max_login_attempts') ? ts_plus('+' . setting('lockout_minutes') . ' minutes') : null;
                update('admins', ['failed_logins' => $lock ? 0 : $fails, 'locked_until' => $lock], 'id = ?', [$a['id']]);
            }
            log_login_attempt('admin', $email, false, $a ? ($a['active'] ? 'wrong password' : 'disabled') : 'unknown email');
            usleep(random_int(300000, 600000));
        } else {
            $s = session_create('admin', (int)$a['id'], max(1, setting('admin_session_hours')), ['twofa_pending' => $a['totp_enabled'] ? 1 : 0]);
            update('admins', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now(), 'last_login_ip' => $ip], 'id = ?', [$a['id']]);
            log_login_attempt('admin', $email, true, $a['totp_enabled'] ? 'password ok, 2FA next' : 'ok');
            $GLOBALS['ADMIN'] = $a;
            audit('login', 'admin', $a['id'], short_agent(user_agent()));
            if ($a['totp_enabled']) redirect('twofa.php');
            $next = input('next');
            redirect($next && str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : 'index.php');
        }
    }
}
admin_start('Superadmin sign in');
?>
<div class="auth-card">
  <div class="big-emoji">🛡</div><h1>Superadmin</h1>
  <p class="muted center"><?= e(setting('site_name')) ?> control center</p>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e(input('next')) ?>">
    <label>Email</label><input name="email" type="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
    <label>Password</label><div class="input-group"><input id="pw" name="password" type="password" required autocomplete="current-password"><button type="button" class="icon-btn" data-reveal="pw">👁</button></div>
    <br><button class="btn btn-block">Sign in</button></form>
  <p class="hint center">All sign-ins are logged. Forgot your password? Ask another superadmin to reset it.</p>
</div>
<?php admin_end();
