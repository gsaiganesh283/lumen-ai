<?php
/** Sign in / sign up with Google (OAuth 2.0 authorization code flow). */
require __DIR__ . '/_init.php';

if (!settings_google_ready()) {
    flash('Google sign-in is not set up yet.', 'danger');
    redirect('login.php');
}
$redirect_uri = site_url('/google.php');

if (isset($_GET['start'])) {
    $state = random_token(24);
    set_cookie('hm_gstate', sign_value($state . '|' . input('next') . '|' . normalize_code(input('ref', $_COOKIE['hm_ref'] ?? ''))), time() + 600);
    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => setting('google_client_id'), 'redirect_uri' => $redirect_uri, 'response_type' => 'code',
        'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account', 'access_type' => 'online']));
}

$saved = unsign_value($_COOKIE['hm_gstate'] ?? null);
set_cookie('hm_gstate', '', time() - 3600);
[$state, $next, $ref] = array_pad(explode('|', (string)$saved, 3), 3, '');
if (!$saved || !hash_equals($state, (string)($_GET['state'] ?? '')) || empty($_GET['code'])) {
    flash(isset($_GET['error']) ? 'Google sign-in was cancelled.' : 'Google sign-in failed (security check). Please try again.', 'danger');
    redirect('login.php');
}

function google_post(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query($fields)]);
    $r = curl_exec($ch);
    return json_decode((string)$r, true) ?: [];
}

$tok = google_post('https://oauth2.googleapis.com/token', ['code' => $_GET['code'], 'client_id' => setting('google_client_id'),
    'client_secret' => setting('google_client_secret'), 'redirect_uri' => $redirect_uri, 'grant_type' => 'authorization_code']);
$info = [];
if (!empty($tok['id_token'])) {
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($tok['id_token']));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $info = json_decode((string)curl_exec($ch), true) ?: [];
}
if (($info['aud'] ?? '') !== setting('google_client_id') || empty($info['sub']) || empty($info['email'])
    || !in_array($info['email_verified'] ?? '', ['true', true], true)
    || !in_array($info['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
    error_log('Google login failed: ' . json_encode($tok['error_description'] ?? $tok['error'] ?? $info));
    flash('Could not verify your Google account. Please try again.', 'danger');
    redirect('login.php');
}

$email = strtolower($info['email']);
$u = row('SELECT * FROM users WHERE google_sub = ?', [$info['sub']]) ?: row('SELECT * FROM users WHERE email = ?', [$email]);
if ($u) {
    if (in_array($u['status'], ['banned', 'deleted'], true)) {
        log_login_attempt('user', $email, false, 'google ' . $u['status']);
        flash('This account is ' . $u['status'] . '. Contact support.', 'danger');
        redirect('login.php');
    }
    // Link Google to an existing email account; Google has verified the email.
    update('users', ['google_sub' => $info['sub'], 'avatar_url' => $u['avatar_url'] ?: ($info['picture'] ?? ''),
        'name' => $u['name'] ?: ($info['name'] ?? '')], 'id = ?', [$u['id']]);
    if (!$u['email_verified']) mark_verified($u);
} else {
    if (!setting('registration_open')) {
        flash('New sign-ups are paused right now.', 'danger');
        redirect('login.php');
    }
    $referrer = $ref !== '' ? val('SELECT id FROM users WHERE referral_code = ?', [$ref]) : null;
    $id = create_user(['email' => $email, 'name' => mb_substr($info['name'] ?? '', 0, 120), 'google_sub' => $info['sub'],
        'avatar_url' => $info['picture'] ?? '', 'signup_method' => 'google', 'referred_by' => $referrer ?: null]);
    $u = user_by_id($id);
    mark_verified($u);   // no email verification needed for Google
    track('register', ['user_id' => $id, 'label' => "Registered with Google: $email"]);
    $new_user = true;
}
$u = user_by_id((int)$u['id']);
login_user($u, 'google');
if ($u['totp_enabled']) redirect('twofa.php');
if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) redirect($next);
redirect(!empty($new_user) ? 'get-started.php' : 'dashboard.php');
