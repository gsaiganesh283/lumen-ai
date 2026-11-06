<?php
/** Business logic shared by the user portal, superadmin portal and desktop API. */

const DEFAULT_PLANS = [
    // code, name, days, price ₹, MRP ₹, badge, description
    ['30D', 'Starter', 30, 450, 0, '', 'A focused month of interviews.'],
    ['60D', 'Pro', 60, 800, 900, 'Popular', 'Two months of interview prep and live help.'],
    ['90D', 'Ultimate', 90, 1200, 1350, 'Best value', 'Three months — the lowest price per day.'],
];
const PLAN_FEATURES = "Real-time AI answers from your resume\nHidden from screen share\nInterviewer audio capture (Bluetooth too)\nScreen OCR + Analyze\nClaude, GPT, Gemini, Groq and more\nEmail + in-app expiry reminders";

function seed_defaults(): void {
    if ((int)val('SELECT COUNT(*) FROM plans') === 0) {
        foreach (DEFAULT_PLANS as $i => [$code, $name, $days, $price, $mrp, $badge, $desc]) {
            insert('plans', ['code' => $code, 'name' => $name, 'days' => $days, 'price_paise' => $price * 100,
                'mrp_paise' => $mrp * 100, 'badge' => $badge, 'description' => $desc, 'features' => PLAN_FEATURES,
                'sort' => $i, 'created_at' => now()]);
        }
    }
    if ((int)val('SELECT COUNT(*) FROM canned_replies') === 0) {
        $replies = [
            ['Activation steps', "Open the InterviewLens desktop app → click the license badge at the top left → paste your activation key → Activate. Your Device ID must match the one in your portal."],
            ['Payment received', "We've verified your payment and your activation key is now in Portal → Licenses. Thanks for choosing InterviewLens!"],
            ['Device change', "We've reset your device binding. Enter the new Device ID in Portal → Devices, then activate the key in the app on the new PC."],
        ];
        foreach ($replies as [$t, $b]) insert('canned_replies', ['title' => $t, 'body' => $b, 'created_at' => now()]);
    }
}

// ── Users ─────────────────────────────────────────────────────────────────────

function user_by_id(int $id): ?array {
    return row('SELECT * FROM users WHERE id = ?', [$id]);
}

function new_referral_code(): string {
    do { $c = random_code(8); } while (val('SELECT 1 FROM users WHERE referral_code = ?', [$c]));
    return $c;
}

function email_domain_blocked(string $email): bool {
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    $blocked = array_filter(array_map('trim', explode(',', strtolower(setting('blocked_email_domains')))));
    return $domain === '' || in_array($domain, $blocked, true);
}

function create_user(array $data): int {
    $data += ['referral_code' => new_referral_code(), 'signup_ip' => client_ip(), 'created_at' => now()];
    $data['email'] = strtolower(trim($data['email']));
    return insert('users', $data);
}

function notify(int $user_id, string $title, string $body = '', string $level = 'info', string $link = ''): void {
    insert('notifications', ['user_id' => $user_id, 'title' => mb_substr($title, 0, 150), 'body' => $body,
        'level' => $level, 'link' => $link, 'created_at' => now()]);
}

function one_time_token(string $purpose, int $owner_id, string $ttl, ?int $admin_id = null): string {
    $t = random_token(32);
    insert('one_time_tokens', ['purpose' => $purpose, 'owner_id' => $owner_id, 'admin_id' => $admin_id,
        'token_hash' => token_hash($t), 'expires_at' => ts_plus($ttl), 'created_at' => now()]);
    return $t;
}

/** Use a token once. Returns the token row or null. */
function consume_token(string $purpose, string $token): ?array {
    $r = row('SELECT * FROM one_time_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
        [token_hash($token), $purpose, now()]);
    if ($r) q('UPDATE one_time_tokens SET used_at = ? WHERE id = ?', [now(), $r['id']]);
    return $r;
}

function send_verification_email(array $user): void {
    q('UPDATE one_time_tokens SET used_at = ? WHERE purpose = ? AND owner_id = ? AND used_at IS NULL', [now(), 'verify', $user['id']]);
    $t = one_time_token('verify', (int)$user['id'], '+24 hours');
    send_mail($user['email'], 'verify_email', ['name' => $user['name'] ?: 'there',
        'link' => site_url('/verify.php?token=' . $t)], (int)$user['id']);
}

function mark_verified(array $user): void {
    if ($user['email_verified']) return;
    update('users', ['email_verified' => 1, 'email_verified_at' => now()], 'id = ?', [$user['id']]);
    send_mail($user['email'], 'welcome', ['name' => $user['name'] ?: 'there', 'link' => site_url('/get-started.php')], (int)$user['id']);
    notify((int)$user['id'], 'Welcome to ' . setting('site_name') . '!', 'Choose a plan to get your activation key.', 'success', 'get-started.php');
    maybe_issue_trial((int)$user['id']);
}

function maybe_issue_trial(int $user_id): void {
    if (!setting('trial_enabled')) return;
    if (val("SELECT 1 FROM licenses WHERE user_id = ? AND source = 'trial'", [$user_id])) return;
    $lic = issue_license($user_id, max(1, setting('trial_days')), null, 'trial', 'system');
    notify($user_id, 'Free trial key ready', 'Use key ' . $lic['license_key'] . ' in the desktop app.', 'success', 'licenses.php');
}

// ── Coupons & orders ──────────────────────────────────────────────────────────

/** Returns [coupon|null, error message]. */
function check_coupon(string $code, array $plan, int $user_id): array {
    $code = normalize_code($code);
    if ($code === '') return [null, ''];
    $c = row('SELECT * FROM coupons WHERE code = ?', [$code]);
    $now = now();
    if (!$c || !$c['active']) return [null, "This coupon code isn't valid."];
    if ($c['valid_from'] && $now < $c['valid_from']) return [null, "This coupon isn't active yet."];
    if ($c['valid_to'] && $now > $c['valid_to']) return [null, 'This coupon has expired.'];
    if ($c['max_uses'] && $c['used_count'] >= $c['max_uses']) return [null, 'This coupon has been fully used.'];
    if ($c['plan_codes'] !== '' && !in_array($plan['code'], array_map('trim', explode(',', $c['plan_codes'])), true)) {
        return [null, "This coupon doesn't apply to this plan."];
    }
    if ($c['per_user_limit'] > 0) {
        $used = (int)val("SELECT COUNT(*) FROM orders WHERE user_id = ? AND coupon_code = ? AND status = 'paid'", [$user_id, $c['code']]);
        if ($used >= $c['per_user_limit']) return [null, "You've already used this coupon."];
    }
    return [$c, ''];
}

function price_for(array $plan, ?array $coupon): array {
    $base = (int)$plan['price_paise'];
    $discount = 0;
    if ($coupon) {
        $discount = $coupon['kind'] === 'percent' ? intdiv($base * (int)$coupon['value'], 100) : (int)$coupon['value'];
        $discount = max(0, min($discount, $base));
    }
    $sub = $base - $discount;
    $gst = setting('gst_percent');
    $tax = $gst > 0 ? intdiv($sub * $gst, 100) : 0;
    return ['base' => $base, 'discount' => $discount, 'tax' => $tax, 'total' => $sub + $tax];
}

function create_order(int $user_id, array $plan, ?array $coupon, ?int $renew_license_id = null, ?int $device_pk = null): array {
    $p = price_for($plan, $coupon);
    do { $no = 'HM' . random_code(10); } while (val('SELECT 1 FROM orders WHERE order_no = ?', [$no]));
    $id = insert('orders', ['order_no' => $no, 'user_id' => $user_id, 'plan_id' => $plan['id'], 'plan_name' => $plan['name'],
        'plan_days' => $plan['days'], 'base_paise' => $p['base'], 'discount_paise' => $p['discount'], 'tax_paise' => $p['tax'],
        'amount_paise' => $p['total'], 'coupon_code' => $coupon['code'] ?? '', 'license_id' => $renew_license_id,
        'device_pk' => $device_pk, 'ip' => client_ip(), 'created_at' => now()]);
    return row('SELECT * FROM orders WHERE id = ?', [$id]);
}

function next_invoice_no(): string {
    $y = gmdate('Y');
    $n = (int)val("SELECT COUNT(*) FROM orders WHERE invoice_no LIKE ?", ["INV-$y-%"]) + 1;
    while (val('SELECT 1 FROM orders WHERE invoice_no = ?', [sprintf('INV-%s-%05d', $y, $n)])) $n++;
    return sprintf('INV-%s-%05d', $y, $n);
}

/** Mark an order paid and issue or extend the license. Safe to call twice. */
function fulfill_order(int $order_id, string $actor = 'system', string $method = ''): array {
    $result = tx(function () use ($order_id, $actor, $method) {
        $o = row('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$order_id]);
        if (!$o) throw new RuntimeException('Order not found');
        if ($o['status'] === 'paid' && $o['invoice_no']) return [$o, null, false, true];
        $first_paid = !val("SELECT 1 FROM orders WHERE user_id = ? AND status = 'paid'", [$o['user_id']]);
        update('orders', ['status' => 'paid', 'paid_at' => $o['paid_at'] ?: now(), 'invoice_no' => $o['invoice_no'] ?: next_invoice_no(),
            'method' => $method ?: ($o['method'] ?: 'admin')], 'id = ?', [$o['id']]);
        if ($o['coupon_code'] !== '') q('UPDATE coupons SET used_count = used_count + 1 WHERE code = ?', [$o['coupon_code']]);

        $lic = $o['license_id'] ? row('SELECT * FROM licenses WHERE id = ?', [$o['license_id']]) : null;
        $renewed = false;
        if ($lic && (int)$lic['user_id'] === (int)$o['user_id'] && $lic['status'] !== 'revoked') {
            $lic = extend_license($lic, (int)$o['plan_days'], $actor);
            $renewed = true;
        } else {
            $lic = issue_license((int)$o['user_id'], (int)$o['plan_days'], $o['plan_id'] ? (int)$o['plan_id'] : null, 'purchase', $actor,
                $o['device_pk'] ? (int)$o['device_pk'] : null);
            update('orders', ['license_id' => $lic['id']], 'id = ?', [$o['id']]);
        }
        if ($first_paid) referral_bonus((int)$o['user_id']);
        return [row('SELECT * FROM orders WHERE id = ?', [$o['id']]), $lic, $renewed, false];
    });
    [$o, $lic, $renewed, $already] = $result;
    if ($already) return $o;

    $u = user_by_id((int)$o['user_id']);
    $name = $u['name'] ?: 'there';
    send_mail($u['email'], 'payment_received', ['name' => $name, 'amount' => money($o['amount_paise']), 'plan' => $o['plan_name'],
        'days' => $o['plan_days'], 'order_no' => $o['order_no'], 'link' => site_url('/invoice.php?no=' . $o['order_no'])], (int)$u['id']);
    if ($renewed) {
        $ends = $lic['expires_at'] ? local_dt($lic['expires_at'], false) : 'after activation';
        notify((int)$u['id'], "Plan renewed: +{$o['plan_days']} days", "{$lic['license_key']} now ends on $ends.", 'success', 'licenses.php');
        send_mail($u['email'], 'license_extended', ['name' => $name, 'days' => $o['plan_days'], 'key' => $lic['license_key'],
            'expires' => $ends, 'link' => site_url('/licenses.php')], (int)$u['id']);
    } else {
        send_key_email($u, $lic, $o['plan_name']);
        notify((int)$u['id'], 'Payment successful — your activation key is ready',
            "Key {$lic['license_key']} for the {$o['plan_name']} plan ({$o['plan_days']} days).", 'success', 'licenses.php');
    }
    track('payment_paid', ['user_id' => (int)$u['id'], 'source' => 'system', 'label' => "Paid {$o['order_no']} " . money($o['amount_paise']),
        'meta' => ['order' => $o['order_no'], 'method' => $o['method']]]);
    return $o;
}

function referral_bonus(int $user_id): void {
    $days = setting('referral_bonus_days');
    $ref = val('SELECT referred_by FROM users WHERE id = ?', [$user_id]);
    if ($days <= 0 || !$ref) return;
    $lic = row("SELECT * FROM licenses WHERE user_id = ? AND status IN ('active','issued') ORDER BY expires_at DESC LIMIT 1", [$ref]);
    if (!$lic) return;
    extend_license($lic, $days, 'referral');
    notify((int)$ref, "Referral bonus: +$days days", 'A friend you invited just bought a plan. Thank you!', 'success', 'referrals.php');
}

function send_key_email(array $u, array $lic, string $plan_name): void {
    send_mail($u['email'], 'license_key', ['name' => $u['name'] ?: 'there', 'key' => $lic['license_key'], 'plan' => $plan_name,
        'days' => $lic['days'], 'link' => site_url('/licenses.php')], (int)$u['id']);
}

// ── Licenses ──────────────────────────────────────────────────────────────────

function license_event(int $license_id, string $kind, string $detail = '', string $actor = 'system'): void {
    insert('license_events', ['license_id' => $license_id, 'kind' => $kind, 'detail' => mb_substr($detail, 0, 400),
        'actor' => $actor, 'created_at' => now()]);
}

function issue_license(int $user_id, int $days, ?int $plan_id, string $source, string $actor, ?int $device_pk = null): array {
    do { $key = new_activation_key(); } while (val('SELECT 1 FROM licenses WHERE license_key = ?', [$key]));
    $data = ['license_key' => $key, 'user_id' => $user_id, 'plan_id' => $plan_id, 'days' => $days, 'source' => $source,
        'status' => 'issued', 'device_pk' => $device_pk, 'issued_at' => now(), 'created_at' => now()];
    if (setting('license_starts_on') === 'purchase') {
        $data += ['activated_at' => now(), 'expires_at' => ts_plus("+$days days")];
        $data['status'] = 'active';
    }
    $id = insert('licenses', $data);
    license_event($id, 'issued', "$days days, source=$source", $actor);
    return row('SELECT * FROM licenses WHERE id = ?', [$id]);
}

function extend_license(array $lic, int $days, string $actor): array {
    $now = now();
    if ($lic['status'] === 'issued' && !$lic['expires_at']) {
        q('UPDATE licenses SET days = days + ? WHERE id = ?', [$days, $lic['id']]);
    } elseif ($lic['expires_at'] && $lic['expires_at'] > $now && in_array($lic['status'], ['active', 'suspended'], true)) {
        q('UPDATE licenses SET days = days + ?, expires_at = DATE_ADD(expires_at, INTERVAL ? DAY) WHERE id = ?', [$days, $days, $lic['id']]);
    } else {
        update('licenses', ['status' => 'active', 'activated_at' => $lic['activated_at'] ?: $now,
            'expires_at' => ts_plus("+$days days"), 'days' => (int)$lic['days'] + $days], 'id = ?', [$lic['id']]);
        q('DELETE FROM reminder_logs WHERE license_id = ?', [$lic['id']]);
    }
    license_event((int)$lic['id'], 'extended', ($days >= 0 ? '+' : '') . "$days days", $actor);
    return row('SELECT * FROM licenses WHERE id = ?', [$lic['id']]);
}

function effective_status(array $lic): string {
    if ($lic['status'] === 'active' && $lic['expires_at'] && $lic['expires_at'] <= now()) return 'expired';
    return $lic['status'];
}

/** Whole days left, rounded up. Null if not activated yet. */
function days_left(array $lic): ?int {
    if (!$lic['expires_at']) return null;
    $secs = utc_ts($lic['expires_at']) - time();
    return $secs <= 0 ? 0 : (int)ceil($secs / 86400);
}

function activate_on_device(array $lic, array $device, string $actor = 'desktop'): array {
    $changes = ['device_pk' => $device['id'], 'last_check_at' => now()];
    if ($lic['status'] === 'issued') {
        $changes += ['status' => 'active', 'activated_at' => now(), 'expires_at' => ts_plus('+' . (int)$lic['days'] . ' days')];
    }
    update('licenses', $changes, 'id = ?', [$lic['id']]);
    if ($lic['status'] === 'issued') {
        license_event((int)$lic['id'], 'activated', 'device ' . $device['device_id'], $actor);
        $lic = row('SELECT * FROM licenses WHERE id = ?', [$lic['id']]);
        notify((int)$lic['user_id'], 'License activated', "{$lic['license_key']} is active on {$device['name']} until "
            . local_dt($lic['expires_at'], false) . '.', 'success', 'licenses.php');
    }
    return row('SELECT * FROM licenses WHERE id = ?', [$lic['id']]);
}

function device_changes_this_month(int $user_id): int {
    return (int)val("SELECT COUNT(*) FROM license_events le JOIN licenses l ON l.id = le.license_id
        WHERE l.user_id = ? AND le.kind = 'transferred' AND le.actor = 'user' AND le.created_at >= ?",
        [$user_id, ts_plus('-30 days')]);
}

function best_license_for_device(int $device_pk): ?array {
    $lics = rows('SELECT * FROM licenses WHERE device_pk = ?', [$device_pk]);
    if (!$lics) return null;
    $rank = ['active' => 0, 'issued' => 1, 'suspended' => 2, 'expired' => 3, 'revoked' => 4];
    usort($lics, fn($a, $b) => [$rank[effective_status($a)] ?? 9, -utc_ts($a['expires_at'])]
        <=> [$rank[effective_status($b)] ?? 9, -utc_ts($b['expires_at'])]);
    return $lics[0];
}

function active_announcements(string $target): array {
    return rows("SELECT title, body, level FROM announcements WHERE active = 1 AND target IN (?, 'both')
        AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at >= ?) ORDER BY id DESC LIMIT 3",
        [$target, now(), now()]);
}

function ai_provider_config(): array {
    // Superadmin stores this encrypted configuration; the User API relays it
    // only after the device has a currently licensed account.
    $keys = json_decode((string)setting('ai_provider_keys'), true);
    $models = json_decode((string)setting('ai_provider_models'), true);
    return [
        'keys' => is_array($keys) ? $keys : [],
        'models' => is_array($models) ? $models : [],
        'active' => (string)setting('ai_active_provider'),
        'fallback' => (bool)setting('ai_fallback'),
        'custom_base_url' => (string)setting('ai_custom_base_url'),
    ];
}

/** Everything the desktop app needs after activation or a check-in. */
function desktop_payload(?array $lic, array $device): array {
    $kill = setting('kill_switch');
    $data = ['server_time' => gmdate('c'), 'site_name' => setting('site_name'), 'portal_url' => site_url(),
        'latest_version' => setting('desktop_latest_version'), 'min_version' => setting('desktop_min_version'),
        'heartbeat_minutes' => setting('heartbeat_minutes'), 'kill' => $kill, 'device_status' => $device['status'],
        'announcements' => active_announcements('desktop')];
    if (!$lic) {
        return $data + ['licensed' => false, 'status' => 'none', 'message' => 'No license for this device yet. Buy a plan in the portal.'];
    }
    $status = $device['status'] === 'blocked' ? 'blocked' : effective_status($lic);
    $left = days_left($lic);
    $licensed = $status === 'active' && !$kill;
    $exp = utc_ts($lic['expires_at']);
    $grace = time() + setting('offline_grace_hours') * 3600;
    $token = sign_license(['lic' => (int)$lic['id'], 'dev' => $device['device_id'], 'st' => $licensed ? 'active' : 'inactive',
        'exp' => $exp, 'iat' => time(), 'vu' => $licensed ? min($exp, $grace) : 0]);
    $plan = $lic['plan_id'] ? val('SELECT name FROM plans WHERE id = ?', [$lic['plan_id']]) : null;
    $messages = ['expired' => 'Your plan has expired. Renew in the portal to continue.',
        'revoked' => trim('This license was deactivated. ' . $lic['revoked_reason']),
        'suspended' => 'This license is suspended. Contact support.', 'blocked' => 'This device is blocked. Contact support.'];
    return $data + [
        'licensed' => $licensed, 'status' => $status,
        'key_hint' => substr($lic['license_key'], 0, 3) . '…' . substr($lic['license_key'], -5),
        'plan' => $plan ?: ($lic['source'] === 'trial' ? 'Trial' : 'Custom'),
        'days_total' => (int)$lic['days'], 'days_left' => $left,
        'activated_at' => $lic['activated_at'] ? gmdate('c', utc_ts($lic['activated_at'])) : null,
        'expires_at' => $lic['expires_at'] ? gmdate('c', $exp) : null,
        'token' => $token,
        'reminder' => ($licensed && $left !== null && $left <= setting('desktop_reminder_days'))
            ? "Your plan expires in $left day" . ($left == 1 ? '' : 's') . '. Renew in the portal.' : '',
        'message' => $kill ? 'The service is temporarily unavailable.' : ($messages[$status] ?? ''),
        'ai_providers' => $licensed ? ai_provider_config() : null,
    ];
}
