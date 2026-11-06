<?php
require __DIR__ . '/_init.php';
if ($USER && !$SESSION['twofa_pending']) after_login_redirect();

$error = '';
$email = strtolower(input('email'));
if (is_post()) {
    $password = $_POST['password'] ?? '';
    $ip = client_ip();
    if (!rate_limit("login:$ip", 20, 900) || !rate_limit("login:$email", 10, 900)) {
        $error = 'Too many attempts. Please wait 15 minutes and try again.';
        log_login_attempt('user', $email, false, 'rate limited');
    } else {
        $u = row('SELECT * FROM users WHERE email = ?', [$email]);
        if ($u && $u['locked_until'] && $u['locked_until'] > now()) {
            $error = 'This account is locked after too many failed attempts. Try again ' . time_ago($u['locked_until']) . ' or reset your password.';
            log_login_attempt('user', $email, false, 'locked');
        } elseif (!$u || !$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
            if ($u && !$u['password_hash'] && $u['google_sub']) {
                $error = 'This account uses Google sign-in. Click "Continue with Google" below.';
            } else {
                $error = 'Wrong email or password.';
            }
            if ($u) {
                $fails = (int)$u['failed_logins'] + 1;
                $lock = $fails >= setting('max_login_attempts') ? ts_plus('+' . setting('lockout_minutes') . ' minutes') : null;
                update('users', ['failed_logins' => $lock ? 0 : $fails, 'locked_until' => $lock], 'id = ?', [$u['id']]);
                track('login_failed', ['user_id' => (int)$u['id'], 'label' => 'Wrong password' . ($lock ? ' — account locked' : '')]);
            }
            log_login_attempt('user', $email, false, $u ? 'wrong password' : 'unknown email');
            usleep(random_int(200000, 400000));   // slow down guessing
        } elseif (in_array($u['status'], ['banned', 'deleted'], true)) {
            $error = $u['status'] === 'banned' ? 'This account has been banned. Contact support.' : 'This account was deleted.';
            log_login_attempt('user', $email, false, $u['status']);
        } else {
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
            }
            login_user($u, 'password');
            if ($u['totp_enabled']) redirect('twofa.php' . (input('next') ? '?next=' . rawurlencode(input('next')) : ''));
            after_login_redirect();
        }
    }
}

auth_start('Sign in', 'Welcome back. Your next interview is waiting.');
?>
  <h1>Welcome back 👋</h1>
  <p class="auth-sub">Sign in to your <?= e(setting('site_name')) ?> account.</p>
  <?php if ($error): ?><div class="alert alert-danger shake"><?= e($error) ?></div><?php endif; ?>
  <?php if (settings_google_ready()): ?>
    <a class="google-btn" href="google.php?start=1<?= input('next') ? '&next=' . e(rawurlencode(input('next'))) : '' ?>"><?= google_icon() ?> Continue with Google</a>
    <div class="divider">or with email</div>
  <?php endif; ?>
  <form method="post" data-auth-form>
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e(input('next')) ?>">
    <div class="field"><input id="email" name="email" type="email" placeholder=" " value="<?= e($email) ?>" required autofocus autocomplete="email"><label for="email">Email address</label></div>
    <div class="field"><input id="password" name="password" type="password" placeholder=" " required autocomplete="current-password"><label for="password">Password</label><button type="button" class="eye" aria-label="Show password">👁</button></div>
    <div class="auth-row"><span></span><a href="forgot.php">Forgot password?</a></div>
    <button class="btn btn-xl btn-glow" type="submit"><span class="spin"></span> Sign in</button>
  </form>
  <p class="auth-sub center" style="margin-top:18px">New here? <a href="register.php">Create an account</a></p>
<?php auth_end();