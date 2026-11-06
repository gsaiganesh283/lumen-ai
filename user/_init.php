<?php
/** Loaded first by every user-portal page. */
const HM_PORTAL = 'user';
require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/_layout.php';

security_headers();
header('Cache-Control: no-store');

if (!hm_ready()) {
    http_response_code(503);
    exit('<h2 style="font-family:Segoe UI">InterviewLens is almost ready.</h2><p style="font-family:Segoe UI">Start MySQL, then open the superadmin portal and finish <b>setup.php</b> (creates the tables and your superadmin account).</p>');
}

if (ip_blocked(client_ip())) {
    http_response_code(403);
    exit('Access from your network is blocked. Contact support if this is a mistake.');
}

schedule_cron();

$SESSION = session_current('user');
$USER = null;
if ($SESSION) {
    $USER = user_by_id((int)$SESSION['owner_id']);
    if (!$USER || in_array($USER['status'], ['banned', 'deleted'], true)) {
        session_end('user');
        $USER = $SESSION = null;
    } elseif (!$USER['last_seen_at'] || utc_ts($USER['last_seen_at']) < time() - 60) {
        q('UPDATE users SET last_seen_at = ? WHERE id = ?', [now(), $USER['id']]);
    }
}
$IMPERSONATING = $SESSION && $SESSION['impersonated_by'];

// Maintenance mode: only admins viewing as a user get through.
$__page = basename($_SERVER['SCRIPT_NAME']);
if (setting('maintenance_mode') && !$IMPERSONATING && !in_array($__page, ['webhook_razorpay.php'], true)
    && !str_contains($_SERVER['SCRIPT_NAME'], '/api/')) {
    http_response_code(503);
    page_start('Maintenance', '', true);
    echo '<div class="center-card"><div class="big-emoji">🛠️</div><h1>We\'ll be right back</h1><p class="muted">'
        . e(setting('maintenance_message')) . '</p></div>';
    page_end(true);
    exit;
}

/** Page views of signed-in users are tracked (excluding background calls). */
if ($USER && $_SERVER['REQUEST_METHOD'] === 'GET' && !in_array($__page, ['track.php', 'notifications_count.php'], true)
    && !str_contains($_SERVER['SCRIPT_NAME'], '/api/')) {
    register_shutdown_function(function () use ($USER, $SESSION, $IMPERSONATING) {
        track('page_view', ['user_id' => (int)$USER['id'], 'session_id' => (int)$SESSION['id'],
            'status' => http_response_code() ?: 200, 'duration_ms' => (int)((microtime(true) - HM_START) * 1000),
            'label' => ($IMPERSONATING ? '[admin view] ' : '') . $_SERVER['SCRIPT_NAME'],
            'meta' => $IMPERSONATING ? ['impersonated_by' => (int)$SESSION['impersonated_by']] : null]);
    });
}

function require_login(bool $verified = true): array {
    global $USER, $SESSION;
    if (!$USER) {
        flash('Please sign in to continue.', 'info');
        redirect('login.php?next=' . rawurlencode($_SERVER['REQUEST_URI']));
    }
    if ($SESSION['twofa_pending']) redirect('twofa.php');
    if ($verified && !$USER['email_verified'] && setting('require_email_verification')) redirect('verify.php');
    if ($USER['status'] === 'suspended') {
        page_start('Account suspended', '', true);
        echo '<div class="center-card"><div class="big-emoji">⏸️</div><h1>Account suspended</h1><p class="muted">'
            . e($USER['status_reason'] ?: 'Please contact support.') . '</p><a class="btn" href="support.php">Contact support</a> '
            . '<a class="btn btn-ghost" href="logout.php">Sign out</a></div>';
        page_end(true);
        exit;
    }
    return $USER;
}

/** No sensitive account changes while an admin is viewing as the user. */
function block_if_impersonating(): void {
    global $IMPERSONATING;
    if ($IMPERSONATING) {
        flash('Not allowed while viewing as this user (admin mode).', 'danger');
        redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
    }
}

/** Sign a user in: create the session, update stats, alert on new devices. */
function login_user(array $u, string $method = 'password'): void {
    $known = (bool)val('SELECT 1 FROM sessions WHERE kind = ? AND owner_id = ? AND user_agent = ? AND ip = ? LIMIT 1',
        ['user', $u['id'], user_agent(), client_ip()]);
    $first = !val('SELECT 1 FROM sessions WHERE kind = ? AND owner_id = ? LIMIT 1', ['user', $u['id']]);
    $s = session_create('user', (int)$u['id'], setting('session_days') * 24, ['twofa_pending' => $u['totp_enabled'] ? 1 : 0]);
    update('users', ['last_login_at' => now(), 'last_login_ip' => client_ip(), 'failed_logins' => 0, 'locked_until' => null], 'id = ?', [$u['id']]);
    log_login_attempt('user', $u['email'], true, $method);
    track($method === 'google' ? 'google_login' : 'login', ['user_id' => (int)$u['id'], 'session_id' => $s['id'], 'label' => "Signed in ($method)"]);
    if (!$known && !$first && setting('new_login_alerts') && $u['login_alerts']) {
        send_mail($u['email'], 'new_login', ['name' => $u['name'] ?: 'there', 'ip' => client_ip(), 'agent' => short_agent(user_agent()),
            'time' => local_dt(now()), 'link' => site_url('/security.php')], (int)$u['id']);
    }
}

function after_login_redirect(): never {
    $next = input('next');
    redirect($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : 'dashboard.php');
}
