<?php
/** Site settings stored in the database, editable in Superadmin → Settings.
 *  key => [default, type, group, label, help]. Types: str, int, bool, secret, text. */

const SETTINGS = [
    // General
    'site_name' => ['InterviewLens', 'str', 'General', 'Site name', 'Shown in both portals, emails and the desktop app.'],
    'site_url' => ['http://127.0.0.1:8080', 'str', 'General', 'User portal URL', 'Public address of the user folder. Used in emails, Google sign-in and the desktop app. No trailing slash.'],
    'admin_url' => ['http://127.0.0.1:8081', 'str', 'General', 'Superadmin portal URL', 'Address of the superadmin folder. No trailing slash.'],
    'tagline' => ['Your AI co-pilot for interviews', 'str', 'General', 'Tagline', ''],
    'support_email' => ['', 'str', 'General', 'Support email', 'Shown to users for help.'],
    'support_whatsapp' => ['', 'str', 'General', 'Support WhatsApp number', 'With country code, e.g. 919876543210. Blank = hidden.'],
    'timezone_offset_minutes' => ['330', 'int', 'General', 'Display timezone offset (minutes)', '330 = India (IST).'],
    'maintenance_mode' => ['0', 'bool', 'General', 'Maintenance mode', 'Users see a maintenance page. Superadmin still works.'],
    'maintenance_message' => ['We are upgrading InterviewLens. Back shortly!', 'str', 'General', 'Maintenance message', ''],
    // Accounts
    'registration_open' => ['1', 'bool', 'Accounts', 'Allow new registrations', ''],
    'require_email_verification' => ['1', 'bool', 'Accounts', 'Require email verification', 'For email + password sign-ups. Google accounts are already verified.'],
    'google_login_enabled' => ['1', 'bool', 'Accounts', 'Enable Sign in with Google', 'Also needs the client ID and secret.'],
    'google_client_id' => ['', 'str', 'Accounts', 'Google OAuth client ID', 'console.cloud.google.com → APIs & Services → Credentials → OAuth client (Web). Redirect URI: <user portal URL>/google.php'],
    'google_client_secret' => ['', 'secret', 'Accounts', 'Google OAuth client secret', ''],
    'blocked_email_domains' => ['mailinator.com,tempmail.com,10minutemail.com,guerrillamail.com,yopmail.com,trashmail.com', 'text', 'Accounts', 'Blocked email domains', 'Comma separated (disposable email providers).'],
    'referral_bonus_days' => ['3', 'int', 'Accounts', 'Referral bonus (days)', 'Days added to the referrer\'s license when a referred user makes a first purchase. 0 = off.'],
    // Security
    'password_min_length' => ['8', 'int', 'Security', 'Minimum password length', ''],
    'max_login_attempts' => ['5', 'int', 'Security', 'Failed logins before lockout', ''],
    'lockout_minutes' => ['15', 'int', 'Security', 'Lockout time (minutes)', ''],
    'session_days' => ['14', 'int', 'Security', 'User session length (days)', ''],
    'admin_session_hours' => ['12', 'int', 'Security', 'Admin session length (hours)', ''],
    'admin_allowed_ips' => ['', 'text', 'Security', 'Superadmin IP allowlist', 'Comma separated. Blank = any IP.'],
    'require_admin_2fa' => ['0', 'bool', 'Security', 'Require 2FA for every admin', ''],
    'trust_proxy_headers' => ['0', 'bool', 'Security', 'Trust proxy headers (X-Forwarded-For)', 'Only behind nginx / Cloudflare.'],
    'new_login_alerts' => ['1', 'bool', 'Security', 'Email users about new sign-ins', ''],
    'api_rate_limit_per_min' => ['60', 'int', 'Security', 'Desktop API requests per minute per device', ''],
    'register_rate_per_hour' => ['5', 'int', 'Security', 'Registrations per IP per hour', ''],
    // Payments
    'currency_symbol' => ['₹', 'str', 'Payments', 'Currency symbol', ''],
    'razorpay_enabled' => ['0', 'bool', 'Payments', 'Enable Razorpay (automatic)', 'UPI, cards, netbanking. The key is issued instantly.'],
    'razorpay_key_id' => ['', 'str', 'Payments', 'Razorpay key ID', 'rzp_test_... or rzp_live_... (dashboard.razorpay.com → Settings → API keys)'],
    'razorpay_key_secret' => ['', 'secret', 'Payments', 'Razorpay key secret', ''],
    'razorpay_webhook_secret' => ['', 'secret', 'Payments', 'Razorpay webhook secret', 'Webhook URL: <user portal URL>/webhook_razorpay.php, event payment.captured.'],
    'upi_enabled' => ['1', 'bool', 'Payments', 'Enable manual UPI (admin approval)', 'User pays to your UPI ID, submits the UTR, and an admin approves.'],
    'upi_id' => ['', 'str', 'Payments', 'Your UPI ID', 'e.g. yourname@okhdfcbank'],
    'upi_payee_name' => ['InterviewLens', 'str', 'Payments', 'UPI payee name', ''],
    'gst_percent' => ['0', 'int', 'Payments', 'GST % added on top', '0 = no GST on invoices.'],
    'company_name' => ['InterviewLens', 'str', 'Payments', 'Invoice company name', ''],
    'company_address' => ['', 'text', 'Payments', 'Invoice address', ''],
    'company_gstin' => ['', 'str', 'Payments', 'GSTIN', ''],
    // Licenses
    'trial_enabled' => ['0', 'bool', 'Licenses', 'Free trial for new users', 'Issued automatically after email verification.'],
    'trial_days' => ['1', 'int', 'Licenses', 'Trial length (days)', ''],
    'license_starts_on' => ['activation', 'str', 'Licenses', 'Plan days start on', "'activation' = when the key is first used in the app; 'purchase' = immediately."],
    'device_changes_per_month' => ['2', 'int', 'Licenses', 'Device changes per month (user)', 'How often a user can move a license to another PC.'],
    'max_devices_per_user' => ['3', 'int', 'Licenses', 'Max devices per user', ''],
    'offline_grace_hours' => ['72', 'int', 'Licenses', 'Offline grace (hours)', 'How long the app keeps working without reaching the server.'],
    'heartbeat_minutes' => ['10', 'int', 'Licenses', 'Desktop check-in interval (minutes)', ''],
    'reminder_days' => ['7,3,1', 'str', 'Licenses', 'Reminder days before expiry', 'Emails + portal notifications. Comma separated.'],
    'desktop_reminder_days' => ['7', 'int', 'Licenses', 'Desktop warning starts at (days left)', ''],
    // Email
    'email_enabled' => ['0', 'bool', 'Email', 'Send real emails', 'Off = emails are only saved in the Email log (testing).'],
    'smtp_host' => ['smtp.gmail.com', 'str', 'Email', 'SMTP host', 'Gmail: smtp.gmail.com'],
    'smtp_port' => ['587', 'int', 'Email', 'SMTP port', '587 (STARTTLS) or 465 (SSL). If your host reports a Gmail certificate mismatch on 587, try 465.'],
    'smtp_user' => ['', 'str', 'Email', 'SMTP username', 'Your Gmail address.'],
    'smtp_password' => ['', 'secret', 'Email', 'SMTP password', 'Gmail: an App Password from myaccount.google.com/apppasswords (needs 2-Step Verification).'],
    'smtp_from_name' => ['InterviewLens', 'str', 'Email', 'From name', ''],
    'smtp_from_email' => ['', 'str', 'Email', 'From email', 'Blank = SMTP username.'],
    'emails_per_cron' => ['30', 'int', 'Email', 'Queued emails sent per run', 'For broadcasts.'],
    // Tracking & data
    'tracking_enabled' => ['1', 'bool', 'Tracking & data', 'Track user activity', 'Page views, logins, payments and desktop events.'],
    'track_clicks' => ['1', 'bool', 'Tracking & data', 'Track clicks and time on page', ''],
    'activity_retention_days' => ['365', 'int', 'Tracking & data', 'Keep activity for (days)', '0 = forever.'],
    'auto_backup_hours' => ['24', 'int', 'Tracking & data', 'Automatic database backup every (hours)', '0 = off.'],
    'backups_to_keep' => ['14', 'int', 'Tracking & data', 'Backups to keep', ''],
    // Desktop app
    'desktop_latest_version' => ['1.0.0', 'str', 'Desktop app', 'Latest app version', 'Older apps show an update notice.'],
    'desktop_min_version' => ['1.0.0', 'str', 'Desktop app', 'Minimum app version', 'Older apps must update to work.'],
    'download_url' => ['', 'str', 'Desktop app', 'External download URL', 'Optional. Blank = latest uploaded release.'],
    'kill_switch' => ['0', 'bool', 'Desktop app', 'Emergency: disable all desktop apps', 'Every app stops at its next check-in.'],
    // AI providers (managed from the dedicated Superadmin module)
    'ai_provider_keys' => ['', 'secret', '_internal', '', ''],
    'ai_provider_models' => ['', 'text', '_internal', '', ''],
    'ai_active_provider' => ['anthropic', 'str', '_internal', '', ''],
    'ai_fallback' => ['1', 'bool', '_internal', '', ''],
    'ai_custom_base_url' => ['', 'str', '_internal', '', ''],
    // Internal (not shown)
    'last_cron' => ['0', 'int', '_internal', '', ''],
    'last_backup' => ['0', 'int', '_internal', '', ''],
    'manual_published_at' => ['', 'str', '_internal', '', ''],
    'manual_published_title' => ['', 'str', '_internal', '', ''],
    'manual_published_intro' => ['', 'str', '_internal', '', ''],
    'manual_published_body_html' => ['', 'text', '_internal', '', ''],
    'manual_published_accent_color' => ['', 'str', '_internal', '', ''],
    'manual_title' => ['InterviewLens User Manual', 'str', '_internal', '', ''],
    'manual_intro' => ['Complete setup guide for downloading and using the Windows desktop application.', 'str', '_internal', '', ''],
    'manual_body_html' => ['', 'text', '_internal', '', ''],
    'manual_accent_color' => ['#e53935', 'str', '_internal', '', ''],
    'manual_pdf_orientation' => ['portrait', 'str', '_internal', '', ''],
    'manual_pdf_size' => ['A4', 'str', '_internal', '', ''],
];

function settings_all(): array {
    static $cache = null;
    if ($cache === null || isset($GLOBALS['hm_settings_dirty'])) {
        unset($GLOBALS['hm_settings_dirty']);
        $cache = [];
        try {
            foreach (rows('SELECT `key`, value FROM settings') as $r) $cache[$r['key']] = $r['value'];
        } catch (Throwable $e) {
        }
    }
    return $cache;
}

function setting(string $key) {
    $def = SETTINGS[$key] ?? ['', 'str'];
    $raw = settings_all()[$key] ?? $def[0];
    switch ($def[1]) {
        case 'bool': return in_array((string)$raw, ['1', 'true', 'on'], true);
        case 'int': return (int)$raw;
        case 'secret': return dec($raw);
        default: return (string)$raw;
    }
}

function settings_set(array $values): void {
    foreach ($values as $key => $v) {
        $type = SETTINGS[$key][1] ?? 'str';
        if ($type === 'bool') $v = in_array($v, [true, 1, '1', 'on', 'true'], true) ? '1' : '0';
        elseif ($type === 'secret') $v = $v === '' ? '' : enc((string)$v);
        elseif ($type === 'int') $v = (string)(int)$v;
        else $v = trim((string)$v);
        q('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $v]);
    }
    $GLOBALS['hm_settings_dirty'] = true;
}

function site_url(string $path = ''): string {
    return rtrim(setting('site_url'), '/') . $path;
}

function admin_url(string $path = ''): string {
    return rtrim(setting('admin_url'), '/') . $path;
}
