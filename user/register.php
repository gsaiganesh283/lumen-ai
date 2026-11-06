<?php
require __DIR__ . '/_init.php';
if ($USER) redirect('dashboard.php');

$errors = [];
$email = strtolower(input('email'));
$name = input('name');
$ref = normalize_code(input('ref', $_COOKIE['hm_ref'] ?? ''));
if (isset($_GET['ref'])) set_cookie('hm_ref', $ref, time() + 30 * 86400);
if (isset($_GET['plan'])) set_cookie('hm_plan', preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['plan'])), time() + 86400);

if (!setting('registration_open')) {
    page_start('Sign up', '', true);
    echo '<div class="center-card"><div class="big-emoji">🔒</div><h1>Sign-ups are paused</h1><p class="muted">New registrations are closed right now. Please check back soon.</p></div>';
    page_end(true);
    exit;
}

if (is_post()) {
    $password = $_POST['password'] ?? '';
    if (!rate_limit('register:' . client_ip(), max(1, setting('register_rate_per_hour')), 3600)) {
        $errors[] = 'Too many sign-ups from your network. Try again in an hour.';
    }
    if (input('website') !== '') $errors[] = 'Spam check failed.';   // honeypot field
    if (mb_strlen($name) < 2) $errors[] = 'Please enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    elseif (email_domain_blocked($email)) $errors[] = 'Please use a permanent email address (temporary emails are not allowed).';
    elseif (val('SELECT 1 FROM users WHERE email = ?', [$email])) $errors[] = 'An account with this email already exists. Sign in instead.';
    if ($p = password_problems($password, $email)) $errors[] = 'Password must have ' . implode(', ', $p) . '.';
    if (($password !== ($_POST['password2'] ?? ''))) $errors[] = "Passwords don't match.";
    if (empty($_POST['agree'])) $errors[] = 'Please accept the terms and privacy policy.';

    if (!$errors) {
        $referrer = $ref !== '' ? val('SELECT id FROM users WHERE referral_code = ?', [$ref]) : null;
        $id = create_user(['email' => $email, 'name' => mb_substr($name, 0, 120), 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'signup_method' => 'password', 'referred_by' => $referrer ?: null,
            'email_verified' => setting('require_email_verification') ? 0 : 1, 'email_verified_at' => setting('require_email_verification') ? null : now()]);
        $u = user_by_id($id);
        track('register', ['user_id' => $id, 'label' => "Registered: $email", 'meta' => ['ref' => $ref]]);
        if (setting('require_email_verification')) send_verification_email($u);
        else mark_verified(array_merge($u, ['email_verified' => 0]));
        login_user($u, 'password');
        redirect(setting('require_email_verification') ? 'verify.php?sent=1' : 'get-started.php');
    }
}

auth_start('Create your account', 'Your AI interview co-pilot is one minute away.');
?>
  <h1>Create your account ✨</h1>
  <p class="auth-sub">Start with <?= e(setting('site_name')) ?> in under a minute.</p>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger shake"><?= e($err) ?></div><?php endforeach; ?>
  <?php if (settings_google_ready()): ?>
    <a class="google-btn" href="google.php?start=1<?= $ref ? '&ref=' . e($ref) : '' ?>"><?= google_icon() ?> Sign up with Google</a>
    <div class="divider">or with email</div>
  <?php endif; ?>
  <form method="post" autocomplete="on" data-auth-form>
    <?= csrf_field() ?>
    <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
    <div class="field"><input id="name" name="name" type="text" placeholder=" " value="<?= e($name) ?>" required maxlength="120" autocomplete="name"><label for="name">Full name</label></div>
    <div class="field"><input id="email" name="email" type="email" placeholder=" " value="<?= e($email) ?>" required autocomplete="email"><label for="email">Email address</label></div>
    <div class="field"><input id="password" name="password" type="password" placeholder=" " required data-strength autocomplete="new-password"><label for="password">Password</label><button type="button" class="eye" aria-label="Show password">👁</button></div>
    <div class="strength"><div></div></div>
    <div class="hint">At least <?= (int)setting('password_min_length') ?> characters with letters and numbers.</div>
    <div class="field"><input id="password2" name="password2" type="password" placeholder=" " required autocomplete="new-password"><label for="password2">Confirm password</label></div>
    <div class="field"><input id="ref" name="ref" type="text" placeholder=" " value="<?= e($ref) ?>" maxlength="16"><label for="ref">Referral code (optional)</label></div>
    <label class="terms"><input type="checkbox" name="agree" value="1" required> <span>I agree to the <a href="terms.php" target="_blank">Terms</a> and <a href="privacy.php" target="_blank">Privacy Policy</a>, including activity tracking.</span></label>
    <button class="btn btn-xl btn-glow" type="submit"><span class="spin"></span> Create account</button>
  </form>
  <p class="auth-sub center" style="margin-top:18px">Already have an account? <a href="login.php">Sign in</a></p>
<?php auth_end();