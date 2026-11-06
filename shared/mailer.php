<?php
/** Emails: editable templates, Gmail/SMTP sending, queue and full log.
 *  Template placeholders: {{name}} (escaped), {{{html}}} (raw), {{button}} (link button). */

const EMAIL_TEMPLATES = [
    'verify_email' => ['Verify your {{site_name}} email',
        '<h2>Welcome, {{name}}!</h2><p>Please confirm your email address to activate your account.</p>{{button}}<p class="muted">This link expires in 24 hours. If you didn\'t create an account, ignore this email.</p>', 'Verify email'],
    'welcome' => ['Welcome to {{site_name}} 🎉',
        '<h2>You\'re all set, {{name}}!</h2><p>Next steps:</p><ol><li>Choose a plan</li><li>Download the desktop app</li><li>Enter the Device ID shown in the app</li><li>Activate with your key</li></ol>{{button}}', 'Get started'],
    'password_reset' => ['Reset your {{site_name}} password',
        '<h2>Password reset</h2><p>Hi {{name}}, click below to choose a new password.</p>{{button}}<p class="muted">This link expires in 1 hour. If you didn\'t ask for this, ignore this email.</p>', 'Reset password'],
    'password_changed' => ['Your {{site_name}} password was changed',
        '<h2>Password changed</h2><p>Hi {{name}}, your password was just changed (IP {{ip}}).</p><p>If this wasn\'t you, reset your password now and contact support.</p>', ''],
    'new_login' => ['New sign-in to your {{site_name}} account',
        '<h2>New sign-in</h2><p>Hi {{name}}, your account was signed in from a new device.</p><p><b>IP:</b> {{ip}}<br><b>Browser:</b> {{agent}}<br><b>Time:</b> {{time}}</p><p>If this wasn\'t you, change your password and sign out all sessions.</p>{{button}}', 'Review security'],
    'payment_received' => ['Payment received — order {{order_no}}',
        '<h2>Thank you, {{name}}!</h2><p>We received <b>{{amount}}</b> for the <b>{{plan}}</b> plan ({{days}} days).</p>{{button}}', 'View invoice'],
    'payment_pending' => ['We got your UPI payment details — order {{order_no}}',
        '<h2>Payment under review</h2><p>Hi {{name}}, we received UPI transaction ID <b>{{utr}}</b> for {{amount}}. We\'ll verify it shortly and email your activation key.</p>', ''],
    'payment_rejected' => ['Payment not verified — order {{order_no}}',
        '<h2>Payment not verified</h2><p>Hi {{name}}, we couldn\'t verify the payment for order {{order_no}}.</p><p><b>Reason:</b> {{reason}}</p>{{button}}', 'Contact support'],
    'license_key' => ['Your {{site_name}} activation key',
        '<h2>Your activation key</h2><p>Hi {{name}}, here is your key for the <b>{{plan}}</b> plan ({{days}} days):</p><p class="key">{{key}}</p><p>Open the desktop app, click the license badge at the top left, paste the key and click Activate.</p>{{button}}', 'Open my licenses'],
    'license_extended' => ['Your {{site_name}} plan was extended',
        '<h2>Plan extended</h2><p>Hi {{name}}, {{days}} days were added to {{key}}. It now ends on <b>{{expires}}</b>.</p>{{button}}', 'View licenses'],
    'expiry_reminder' => ['Your {{site_name}} plan expires in {{days_left}} day(s)',
        '<h2>Plan expiring soon</h2><p>Hi {{name}}, your plan on <b>{{device}}</b> ends on <b>{{expires}}</b> ({{days_left}} day(s) left).</p><p>Renew now to keep using {{site_name}} without interruption — renewing adds days on top.</p>{{button}}', 'Renew now'],
    'license_expired' => ['Your {{site_name}} plan has expired',
        '<h2>Plan expired</h2><p>Hi {{name}}, your plan on <b>{{device}}</b> ended on {{expires}}. The desktop app is now deactivated.</p>{{button}}', 'Renew plan'],
    'license_revoked' => ['Your {{site_name}} license was deactivated',
        '<h2>License deactivated</h2><p>Hi {{name}}, license {{key}} was deactivated.</p><p><b>Reason:</b> {{reason}}</p>{{button}}', 'Contact support'],
    'ticket_reply' => ['Re: {{subject}} [Ticket #{{ticket_id}}]',
        '<h2>New reply from support</h2><div style="white-space:pre-wrap">{{message}}</div>{{button}}', 'View ticket'],
    'account_status' => ['Your {{site_name}} account is now {{status}}',
        '<h2>Account {{status}}</h2><p>Hi {{name}}, your account status changed to <b>{{status}}</b>.</p><p>{{reason}}</p>', ''],
    'invoice_copy' => ['Your {{site_name}} invoice {{invoice_no}}',
        '<h2>Your invoice</h2><p>Hi {{name}}, here is a copy of invoice <b>{{invoice_no}}</b> for the <b>{{plan}}</b> plan.</p><p><b>Amount paid:</b> {{amount}}<br><b>Paid on:</b> {{date}}<br><b>Verification code:</b> {{code}}</p>{{button}}<p class="muted">Open the invoice and choose Print → Save as PDF to keep a copy.</p>', 'Open invoice'],
    'broadcast' => ['{{subject}}', '<h2>{{subject}}</h2><div>{{{message}}}</div>{{button}}', 'Open InterviewLens'],
    'test' => ['{{site_name}} test email', '<h2>It works ✅</h2><p>Your email settings are correct. Sent at {{time}}.</p>', ''],
    'manual_published' => ['Your {{site_name}} user manual is ready',
        '<h2>Your user manual is ready</h2><p>Hi {{name}},</p><p>Your latest {{site_name}} user manual is attached to this email as a PDF. It includes the instructions and screenshots you need to download and use the desktop application.</p><p>You can also open the same manual anytime from your User portal.</p>{{button}}<p style="color:#777;font-size:13px">Please keep this email for future reference.</p>', 'Open user manual'],
];

function email_template(string $key): array {
    $row = row('SELECT subject, body_html FROM email_templates WHERE `key` = ?', [$key]);
    $def = EMAIL_TEMPLATES[$key] ?? ['{{subject}}', '{{{message}}}', ''];
    // Replace the original built-in manual message while preserving intentional custom edits.
    if ($key === 'manual_published' && $row && str_contains((string)$row['body_html'], 'the latest user manual is attached as a PDF')) {
        return $def;
    }
    return [$row['subject'] ?? $def[0], $row['body_html'] ?? $def[1], $def[2]];
}

function render_placeholders(string $tpl, array $ctx, bool $html): string {
    $tpl = preg_replace_callback('/\{\{\{\s*(\w+)\s*\}\}\}/', fn($m) => (string)($ctx[$m[1]] ?? ''), $tpl);
    return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($m) use ($ctx, $html) {
        $v = (string)($ctx[$m[1]] ?? '');
        return $html ? e($v) : $v;
    }, $tpl);
}

function render_email(string $key, array $ctx): array {
    [$subject, $body, $btn] = email_template($key);
    $ctx += ['site_name' => setting('site_name'), 'support_email' => setting('support_email'), 'name' => 'there'];
    $button = '';
    if (!empty($ctx['link'])) {
        $button = '<p><a href="' . e($ctx['link']) . '" style="display:inline-block;background:#6d5efc;color:#fff;'
            . 'padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">'
            . e($ctx['button'] ?? ($btn ?: 'Open')) . '</a></p>';
    }
    $body = str_replace('{{button}}', '{{{__button}}}', $body);
    $content = render_placeholders($body, $ctx + ['__button' => $button], true);
    // Some saved templates contain these classes from the old embedded CSS block.
    // Inline styles are rendered reliably by Gmail and other mail clients.
    $content = preg_replace('/\sclass=["\']muted["\']/i', ' style="color:#888;font-size:13px"', $content) ?? $content;
    $content = preg_replace('/\sclass=["\']key["\']/i', ' style="font-family:Consolas,monospace;font-size:20px;background:#f1f0ff;padding:12px 16px;border-radius:8px;letter-spacing:1px;color:#4b3fd6;font-weight:700"', $content) ?? $content;
    $content = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $content) ?? $content;
    $subject = render_placeholders($subject, $ctx, false);
    $site = e($ctx['site_name']);
    $foot = 'You get this email because you have a ' . $site . ' account.'
        . ($ctx['support_email'] ? ' Need help? ' . e($ctx['support_email']) : '');
    $html = <<<HTML
<!doctype html><html><body style="margin:0;background:#f3f3fb;font-family:Segoe UI,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden">
<tr><td style="background:#6d5efc;background:linear-gradient(135deg,#6d5efc,#a35cf6);padding:18px 28px;color:#fff;font-size:20px;font-weight:700">🧠 $site</td></tr>
<tr><td style="padding:26px 28px;color:#222;font-size:15px;line-height:1.6">
$content</td></tr>
<tr><td style="padding:16px 28px;background:#fafafe;color:#999;font-size:12px">$foot</td></tr>
</table></td></tr></table></body></html>
HTML;
    return [trim($subject), $html];
}

/** Render + log an email. Sends right away unless $queue (then cron sends it). */
function send_mail(string $to, string $template, array $ctx = [], ?int $user_id = null, bool $queue = false, array $attachments = []): int {
    [$subject, $html] = render_email($template, $ctx);
    $enabled = setting('email_enabled') && setting('smtp_user') !== '';
    $id = insert('email_logs', ['user_id' => $user_id, 'to_email' => $to, 'subject' => mb_substr($subject, 0, 255),
        'template' => $template, 'body_html' => $html, 'status' => $enabled ? 'queued' : 'logged', 'created_at' => now()]);
    if ($enabled && !$queue) deliver_logged_email($id, $attachments);
    return $id;
}

function deliver_logged_email(int $id, array $attachments = []): bool {
    $m = row('SELECT * FROM email_logs WHERE id = ?', [$id]);
    if (!$m) return false;
    try {
        smtp_send($m['to_email'], $m['subject'], $m['body_html'], $attachments);
        update('email_logs', ['status' => 'sent', 'sent_at' => now(), 'error' => '', 'attempts' => $m['attempts'] + 1], 'id = ?', [$id]);
        return true;
    } catch (Throwable $e) {
        update('email_logs', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'attempts' => $m['attempts'] + 1], 'id = ?', [$id]);
        return false;
    }
}

// ── Minimal SMTP client (STARTTLS on 587 or SSL on 465, AUTH LOGIN) ──────────

function smtp_send(string $to, string $subject, string $html, array $attachments = []): void {
    $host = setting('smtp_host');
    $port = setting('smtp_port');
    $user = setting('smtp_user');
    $pass = setting('smtp_password');
    // Google shows App Passwords as "abcd efgh ijkl mnop"; the spaces aren't part of it.
    if (stripos($host, 'gmail') !== false || stripos($host, 'google') !== false) $pass = str_replace(' ', '', $pass);
    $from = setting('smtp_from_email') ?: $user;
    $fromName = setting('smtp_from_name') ?: setting('site_name');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid recipient address');

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
        'SNI_enabled' => true,
        'SNI_server_name' => $host,
    ]]);
    $remote = ($port == 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException(
            "Can't connect to $host:$port ($errstr). The hosting provider may block outbound SMTP; use its SMTP hostname and allowed port, or ask support to allow this connection."
        );
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read) {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        $resp = $read();
        if (!in_array((int)substr($resp, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP error: ' . trim($resp ?: 'no response'));
        }
        return $resp;
    };

    $cmd('', [220]);
    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
    $cmd($ehlo, [250]);
    if ($port != 465) {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException("Could not verify the TLS certificate for $host:$port. Try SMTP port 465 (SSL) instead of 587 (STARTTLS), or use an SMTP hostname supplied by your hosting provider. Do not disable certificate verification.");
        }
        $cmd($ehlo, [250]);
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        try {
            $cmd(base64_encode($pass), [235]);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Login failed — for Gmail use an App Password, not your normal password. ' . $e->getMessage());
        }
    }
    $cmd('MAIL FROM:<' . $from . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);

    $boundary = 'hm_' . bin2hex(random_bytes(8));
    $domain = substr(strrchr($from, '@') ?: '@hiremind.local', 1);
    $text = trim(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/h2|\/li)>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $headers = [
        'Date: ' . date('r'),
        'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];
    $body = implode("\r\n", $headers) . "\r\n\r\n"
        . "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n";
    foreach ($attachments as $attachment) {
        $path = $attachment['path'] ?? '';
        if (!is_file($path)) throw new RuntimeException('Email attachment is missing.');
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $attachment['name'] ?? basename($path));
        $type = $attachment['type'] ?? 'application/octet-stream';
        $body .= "--$boundary\r\nContent-Type: $type; name=\"$name\"\r\n"
            . "Content-Disposition: attachment; filename=\"$name\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode((string)file_get_contents($path))) . "\r\n";
    }
    $body .= "--$boundary--\r\n";
    fwrite($fp, $body . "\r\n.\r\n");
    $cmd('', [250]);
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
}
