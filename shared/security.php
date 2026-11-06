<?php
/** Security: escaping, tokens, CSRF, sessions, rate limits, 2FA, encryption, license signing. */

// Data folder (keys, backups, uploads). HM_DATA_DIR overrides it for automated tests.
define('HM_DATA', getenv('HM_DATA_DIR') ?: __DIR__ . '/../data');

function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function random_token(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function token_hash(string $t): string {
    return hash('sha256', $t);
}

const KEY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I

function random_code(int $n): string {
    $s = '';
    for ($i = 0; $i < $n; $i++) $s .= KEY_ALPHABET[random_int(0, strlen(KEY_ALPHABET) - 1)];
    return $s;
}

function new_activation_key(): string {
    return 'HM-' . implode('-', [random_code(5), random_code(5), random_code(5), random_code(5)]);
}

function normalize_code(string $s): string {
    return strtoupper(preg_replace('/\s+/', '', $s));
}

function valid_device_id(string $id): bool {
    return (bool)preg_match('/^HMD-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $id);
}

function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && setting('trust_proxy_headers'));
}

function client_ip(): string {
    if (setting('trust_proxy_headers')) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function user_agent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 400);
}

function set_cookie(string $name, string $value, int $expires = 0, bool $httponly = true): void {
    setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => is_https(),
        'httponly' => $httponly, 'samesite' => 'Lax']);
    $_COOKIE[$name] = $value;
}

function security_headers(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' "
        . "https://checkout.razorpay.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data: https: " . (preg_match('#^https?://[^/]+#', setting('site_url'), $m) ? $m[0] : '') . "; connect-src 'self' https://api.razorpay.com https://lumberjack.razorpay.com; "
        . "frame-src https://api.razorpay.com https://checkout.razorpay.com; form-action 'self' https://accounts.google.com; "
        . "base-uri 'self'; frame-ancestors 'none'");
    if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── Data-folder secrets ───────────────────────────────────────────────────────

function data_secret(string $name, callable $make): string {
    $path = HM_DATA . '/' . $name;
    if (!is_file($path)) {
        if (!is_dir(HM_DATA)) mkdir(HM_DATA, 0700, true);
        file_put_contents($path, $make(), LOCK_EX);
        @chmod($path, 0600);
    }
    return file_get_contents($path);
}

function app_key(): string {
    static $k = null;
    return $k ??= base64_decode(data_secret('app.key', fn() => base64_encode(random_bytes(32))));
}

/** Encrypt secrets stored in the database (AES-256-GCM). */
function enc(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $ct);
}

function dec(?string $stored): string {
    if (!$stored || !str_starts_with($stored, 'enc:')) return (string)$stored;
    $raw = base64_decode(substr($stored, 4));
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

function sign_value(string $v): string {
    return $v . '.' . hash_hmac('sha256', $v, app_key());
}

function unsign_value(?string $signed): ?string {
    if (!$signed || !str_contains($signed, '.')) return null;
    $pos = strrpos($signed, '.');
    $v = substr($signed, 0, $pos);
    return hash_equals(hash_hmac('sha256', $v, app_key()), substr($signed, $pos + 1)) ? $v : null;
}

// ── CSRF (double-submit cookie, one per portal) ───────────────────────────────

function csrf_cookie_name(): string {
    return defined('HM_PORTAL') && HM_PORTAL === 'admin' ? 'hm_acsrf' : 'hm_ucsrf';
}

function csrf_token(): string {
    $n = csrf_cookie_name();
    $t = $_COOKIE[$n] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $t)) {
        $t = random_token(32);
        set_cookie($n, $t);
    }
    return $t;
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $cookie = $_COOKIE[csrf_cookie_name()] ?? '';
    return $cookie !== '' && is_string($sent) && hash_equals($cookie, $sent);
}

function require_post_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
        http_response_code(400);
        flash('Your session form expired. Please try again.', 'danger');
        redirect($_SERVER['HTTP_REFERER'] ?? './');
    }
}

function is_post(): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    if (!csrf_valid()) {
        flash('Your form expired (security check). Please try again.', 'danger');
        redirect($_SERVER['REQUEST_URI']);
    }
    return true;
}

// ── Sessions (stored in the database; can be listed and revoked) ──────────────

function session_cookie(string $kind): string {
    return $kind === 'admin' ? 'hm_admin' : 'hm_user';
}

function session_create(string $kind, int $owner_id, int $hours, array $extra = []): array {
    $token = random_token(32);
    $data = array_merge([
        'kind' => $kind, 'token_hash' => token_hash($token), 'owner_id' => $owner_id,
        'csrf' => random_token(32), 'ip' => client_ip(), 'user_agent' => user_agent(),
        'created_at' => now(), 'last_seen_at' => now(), 'expires_at' => ts_plus("+$hours hours"),
    ], $extra);
    $data['id'] = insert('sessions', $data);
    set_cookie(session_cookie($kind), $token, time() + $hours * 3600);
    return $data;
}

function session_current(string $kind): ?array {
    static $cache = [];
    if (array_key_exists($kind, $cache)) return $cache[$kind];
    $token = $_COOKIE[session_cookie($kind)] ?? '';
    $s = null;
    if ($token !== '' && strlen($token) < 100) {
        $s = row('SELECT * FROM sessions WHERE token_hash = ? AND kind = ? AND revoked = 0 AND expires_at > ?',
            [token_hash($token), $kind, now()]);
        if ($s && utc_ts($s['last_seen_at']) < time() - 60) {
            q('UPDATE sessions SET last_seen_at = ?, ip = ? WHERE id = ?', [now(), client_ip(), $s['id']]);
        }
    }
    return $cache[$kind] = $s;
}

function session_end(string $kind): void {
    $s = session_current($kind);
    if ($s) q('UPDATE sessions SET revoked = 1 WHERE id = ?', [$s['id']]);
    set_cookie(session_cookie($kind), '', time() - 3600);
}

// ── Rate limiting, IP blocks, lockouts ────────────────────────────────────────

/** Returns false when more than $limit hits happened in $window seconds. */
function rate_limit(string $bucket, int $limit, int $window): bool {
    $bucket = substr($bucket, 0, 190);
    $now = time();
    q('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)
       ON DUPLICATE KEY UPDATE hits = IF(window_start < ?, 1, hits + 1),
                               window_start = IF(window_start < ?, VALUES(window_start), window_start)',
        [$bucket, $now, $now - $window, $now - $window]);
    return (int)val('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]) <= $limit;
}

function ip_blocked(string $ip): bool {
    return (bool)val('SELECT 1 FROM ip_blocks WHERE ip = ? AND (expires_at IS NULL OR expires_at > ?)',
        [$ip, now()]);
}

function log_login_attempt(string $kind, string $email, bool $ok, string $reason = ''): void {
    insert('login_attempts', ['kind' => $kind, 'email' => substr($email, 0, 191), 'ip' => client_ip(),
        'success' => $ok ? 1 : 0, 'reason' => $reason, 'user_agent' => user_agent(), 'created_at' => now()]);
}

// ── Passwords ─────────────────────────────────────────────────────────────────

function password_problems(string $pw, string $email = ''): array {
    $min = max(6, (int)setting('password_min_length'));
    $p = [];
    if (strlen($pw) < $min) $p[] = "at least $min characters";
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) $p[] = 'both letters and numbers';
    $common = ['password', '12345678', '123456789', 'qwerty123', 'password1', 'iloveyou', 'admin123', 'welcome1', 'abc12345'];
    if (in_array(strtolower($pw), $common, true)) $p[] = 'not a common password';
    $local = strtolower(explode('@', $email)[0] ?? '');
    if (strlen($local) > 3 && str_contains(strtolower($pw), $local)) $p[] = 'not contain your email name';
    return $p;
}

// ── TOTP two-factor codes (Google Authenticator compatible) ───────────────────

function base32_decode_str(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim($b32, '='));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_new_secret(): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    for ($i = 0; $i < 32; $i++) $s .= $alphabet[random_int(0, 31)];
    return $s;
}

function totp_code(string $secret, int $counter): string {
    $hash = hash_hmac('sha1', pack('N*', 0, $counter), base32_decode_str($secret), true);
    $off = ord($hash[19]) & 0x0F;
    $num = (unpack('N', substr($hash, $off, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad((string)$num, 6, '0', STR_PAD_LEFT);
}

function totp_verify(?string $secret, string $code): bool {
    $code = preg_replace('/\D/', '', $code);
    if (!$secret || strlen($code) !== 6) return false;
    $t = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) if (hash_equals(totp_code($secret, $t + $d), $code)) return true;
    return false;
}

function totp_uri(string $secret, string $account, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

// ── License signing for the desktop app (RSA-SHA256) ──────────────────────────

function openssl_cnf(): array {
    foreach ([getenv('OPENSSL_CONF') ?: '', dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
                 'C:/xampp/php/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'] as $p) {
        if ($p && is_file($p)) return ['config' => $p];
    }
    return [];
}

function license_private_pem(): string {
    return data_secret('license_private.pem', function () {
        $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + openssl_cnf();
        $key = openssl_pkey_new($opts);
        if (!$key) throw new RuntimeException('Could not create the license signing key: ' . openssl_error_string());
        openssl_pkey_export($key, $pem, null, openssl_cnf());
        return $pem;
    });
}

function license_public_pem(): string {
    $pub = openssl_pkey_get_details(openssl_pkey_get_private(license_private_pem()))['key'];
    $path = HM_DATA . '/license_public.pem';
    if (!is_file($path)) file_put_contents($path, $pub);
    return $pub;
}

function b64url(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function sign_license(array $payload): string {
    ksort($payload);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    openssl_sign($body, $sig, openssl_pkey_get_private(license_private_pem()), OPENSSL_ALGO_SHA256);
    return b64url($body) . '.' . b64url($sig);
}

function mask_secret(string $s, int $keep = 4): string {
    if ($s === '') return '';
    return str_repeat('•', max(4, strlen($s) - $keep)) . substr($s, -$keep);
}
