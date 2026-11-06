<?php
require __DIR__ . '/_init.php';
$admin = require_admin('view');
$actor = 'admin:' . $admin['id'];

// ── Create a user ─────────────────────────────────────────────────────────────
if (isset($_GET['new'])) {
    if (admin_post('users')) {
        $email = strtolower(input('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Enter a valid email.', 'danger');
        elseif (val('SELECT 1 FROM users WHERE email = ?', [$email])) flash('That email already exists.', 'danger');
        else {
            $pw = $_POST['password'] ?? '';
            $id = create_user(['email' => $email, 'name' => input('name'), 'phone' => input('phone'), 'signup_method' => 'admin',
                'password_hash' => $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : null,
                'email_verified' => isset($_POST['verified']) ? 1 : 0, 'email_verified_at' => isset($_POST['verified']) ? now() : null]);
            $u = user_by_id($id);
            if ($pw === '') {
                $t = one_time_token('reset', $id, '+72 hours');
                send_mail($email, 'password_reset', ['name' => $u['name'] ?: 'there', 'link' => site_url('/reset.php?token=' . $t), 'button' => 'Set your password'], $id);
            }
            if (!$u['email_verified']) send_verification_email($u);
            audit('user_created', 'user', $id, $email);
            flash('User created.', 'success');
            redirect("user.php?id=$id");
        }
    }
    admin_start('New user', 'users.php'); ?>
    <div class="card" style="max-width:560px"><form method="post"><?= csrf_field() ?>
      <label>Email</label><input name="email" type="email" required value="<?= e(input('email')) ?>"><label>Name</label><input name="name" value="<?= e(input('name')) ?>">
      <label>Phone</label><input name="phone"><label>Password</label><input name="password" type="password" placeholder="Leave blank to email a set-password link">
      <label class="check"><input type="checkbox" name="verified" checked> Email already verified</label><br><button class="btn">Create user</button></form></div>
    <?php admin_end();
    exit;
}

$id = input_int('id');
$u = user_by_id($id);
if (!$u) { flash('User not found.', 'danger'); redirect('users.php'); }

if (input('export') === 'json') {   // full data export for this customer
    audit('user_export', 'user', $id);
    $data = ['user' => array_diff_key($u, array_flip(['password_hash', 'totp_secret'])),
        'licenses' => rows('SELECT * FROM licenses WHERE user_id = ?', [$id]), 'devices' => rows('SELECT * FROM devices WHERE user_id = ?', [$id]),
        'orders' => rows('SELECT * FROM orders WHERE user_id = ?', [$id]), 'tickets' => rows('SELECT * FROM tickets WHERE user_id = ?', [$id]),
        'notifications' => rows('SELECT * FROM notifications WHERE user_id = ?', [$id]),
        'activity' => rows('SELECT * FROM activity_events WHERE user_id = ? ORDER BY id DESC LIMIT 5000', [$id])];
    end_buffers();
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="customer-' . $id . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Actions ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = input('action');
    $perm = str_starts_with($act, 'license') || str_starts_with($act, 'order') ? (str_starts_with($act, 'order') ? 'payments' : 'licenses')
        : ($act === 'impersonate' ? 'impersonate' : 'users');
    admin_post($perm);
    $back = "user.php?id=$id&tab=" . urlencode(input('tab', 'overview'));
    $lic = input_int('license') ? row('SELECT * FROM licenses WHERE id = ? AND user_id = ?', [input_int('license'), $id]) : null;
    $dev = input_int('device') ? row('SELECT * FROM devices WHERE id = ? AND user_id = ?', [input_int('device'), $id]) : null;
    $msg = 'Done.';
    switch ($act) {
        case 'impersonate':
            if (in_array($u['status'], ['deleted'], true)) { flash('Cannot sign in as a deleted user.', 'danger'); redirect($back); }
            $t = one_time_token('impersonate', $id, '+60 seconds', (int)$admin['id']);
            audit('impersonate', 'user', $id, $u['email']);
            redirect(site_url('/impersonate.php?t=' . $t));
        case 'profile':
            $email = strtolower(input('email'));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) { flash('Email invalid or already used.', 'danger'); redirect($back); }
            update('users', ['email' => $email, 'name' => mb_substr(input('name'), 0, 120), 'phone' => mb_substr(input('phone'), 0, 30),
                'country' => mb_substr(input('country'), 0, 60), 'tags' => preg_replace('/[^a-z0-9_,-]/i', '', input('tags')),
                'admin_notes' => input('admin_notes'), 'reminder_emails' => isset($_POST['reminder_emails']) ? 1 : 0,
                'marketing_emails' => isset($_POST['marketing_emails']) ? 1 : 0], 'id = ?', [$id]);
            $msg = 'Profile saved.';
            break;
        case 'status':
            $st = in_array(input('status'), ['active', 'suspended', 'banned'], true) ? input('status') : 'active';
            update('users', ['status' => $st, 'status_reason' => input('reason')], 'id = ?', [$id]);
            if ($st !== 'active') q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id = ?", [$id]);
            if (isset($_POST['notify'])) send_mail($u['email'], 'account_status', ['name' => $u['name'] ?: 'there', 'status' => $st, 'reason' => input('reason')], $id);
            $msg = "Status set to $st.";
            break;
        case 'verify': mark_verified($u); $msg = 'Email marked verified.'; break;
        case 'unverify': update('users', ['email_verified' => 0, 'email_verified_at' => null], 'id = ?', [$id]); $msg = 'Email marked unverified.'; break;
        case 'resend_verify': send_verification_email($u); $msg = 'Verification email sent.'; break;
        case 'send_reset':
            $t = one_time_token('reset', $id, '+1 hour');
            send_mail($u['email'], 'password_reset', ['name' => $u['name'] ?: 'there', 'link' => site_url('/reset.php?token=' . $t)], $id);
            $msg = 'Password reset link sent.';
            break;
        case 'set_password':
            $pw = $_POST['password'] ?? '';
            if (strlen($pw) < 8) { flash('Password must be 8+ characters.', 'danger'); redirect($back); }
            update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'failed_logins' => 0, 'locked_until' => null], 'id = ?', [$id]);
            q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id = ?", [$id]);
            $msg = 'Password set and user signed out everywhere.';
            break;
        case 'unlock': update('users', ['failed_logins' => 0, 'locked_until' => null], 'id = ?', [$id]); $msg = 'Account unlocked.'; break;
        case 'disable_2fa': update('users', ['totp_enabled' => 0, 'totp_secret' => null], 'id = ?', [$id]); $msg = '2FA turned off.'; break;
        case 'logout_all': q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id = ?", [$id]); $msg = 'Signed out of all sessions.'; break;
        case 'session_revoke': q("UPDATE sessions SET revoked = 1 WHERE id = ? AND kind = 'user' AND owner_id = ?", [input_int('session'), $id]); $msg = 'Session revoked.'; break;
        case 'notify':
            notify($id, input('title'), input('body'), input('level', 'info'), input('link'));
            if (isset($_POST['email'])) send_mail($u['email'], 'broadcast', ['subject' => input('title'), 'message' => nl2br(e(input('body'))), 'name' => $u['name'],
                'link' => input('link') ? site_url('/' . ltrim(input('link'), '/')) : site_url('/dashboard.php')], $id);
            $msg = 'Notification sent.';
            break;
        case 'license_issue':
            $plan = input_int('plan') ? row('SELECT * FROM plans WHERE id = ?', [input_int('plan')]) : null;
            $days = $plan ? (int)$plan['days'] : max(1, input_int('days', 30));
            $new = issue_license($id, $days, $plan['id'] ?? null, input('source', 'admin') === 'trial' ? 'trial' : 'admin', $actor, $dev['id'] ?? null);
            if (isset($_POST['send_email'])) send_key_email($u, $new, $plan['name'] ?? 'Custom');
            notify($id, 'New activation key', "Key {$new['license_key']} ($days days) was added to your account.", 'success', 'licenses.php');
            $msg = "Issued {$new['license_key']}.";
            break;
        case 'license_extend':
            if ($lic) {
                $days = input_int('days');
                if ($days === 0) { flash('Enter days (negative to remove).', 'danger'); redirect($back); }
                $l2 = extend_license($lic, $days, $actor);
                if ($days > 0 && isset($_POST['send_email'])) send_mail($u['email'], 'license_extended', ['name' => $u['name'] ?: 'there', 'days' => $days,
                    'key' => $l2['license_key'], 'expires' => $l2['expires_at'] ? local_dt($l2['expires_at'], false) : 'after activation', 'link' => site_url('/licenses.php')], $id);
                $msg = ($days > 0 ? 'Added' : 'Removed') . ' ' . abs($days) . ' days.';
            }
            break;
        case 'license_set_expiry':
            if ($lic && input('expires')) {
                $local = strtotime(input('expires') . ' UTC') - setting('timezone_offset_minutes') * 60;
                update('licenses', ['expires_at' => gmdate('Y-m-d H:i:s', $local), 'status' => $local > time() ? 'active' : 'expired',
                    'activated_at' => $lic['activated_at'] ?: now()], 'id = ?', [$lic['id']]);
                q('DELETE FROM reminder_logs WHERE license_id = ?', [$lic['id']]);
                license_event((int)$lic['id'], 'expiry_set', 'to ' . input('expires') . ' (local)', $actor);
                $msg = 'Expiry date changed.';
            }
            break;
        case 'license_revoke':
            if ($lic) {
                update('licenses', ['status' => 'revoked', 'revoked_reason' => input('reason')], 'id = ?', [$lic['id']]);
                license_event((int)$lic['id'], 'revoked', input('reason'), $actor);
                if (isset($_POST['send_email'])) send_mail($u['email'], 'license_revoked', ['name' => $u['name'] ?: 'there', 'key' => $lic['license_key'],
                    'reason' => input('reason') ?: 'Policy', 'link' => site_url('/support.php')], $id);
                $msg = 'License revoked — the app stops at its next check-in.';
            }
            break;
        case 'license_suspend':
            if ($lic) { update('licenses', ['status' => 'suspended'], 'id = ?', [$lic['id']]); license_event((int)$lic['id'], 'suspended', input('reason'), $actor); $msg = 'License suspended.'; }
            break;
        case 'license_reactivate':
            if ($lic) {
                $st = $lic['activated_at'] ? ($lic['expires_at'] && $lic['expires_at'] > now() ? 'active' : 'expired') : 'issued';
                update('licenses', ['status' => $st, 'revoked_reason' => ''], 'id = ?', [$lic['id']]);
                license_event((int)$lic['id'], 'reactivated', "→ $st", $actor);
                $msg = "License is now $st.";
            }
            break;
        case 'license_reset_clock':
            if ($lic) {
                update('licenses', ['status' => 'issued', 'activated_at' => null, 'expires_at' => null], 'id = ?', [$lic['id']]);
                license_event((int)$lic['id'], 'reset', 'Back to unused (days restart on next activation)', $actor);
                $msg = 'License reset to unused.';
            }
            break;
        case 'license_move':
            if ($lic) {
                $to = input_int('to_device') ? row('SELECT * FROM devices WHERE id = ? AND user_id = ?', [input_int('to_device'), $id]) : null;
                update('licenses', ['device_pk' => $to['id'] ?? null], 'id = ?', [$lic['id']]);
                license_event((int)$lic['id'], 'transferred', '→ ' . ($to['device_id'] ?? 'unlinked'), $actor);
                $msg = $to ? "Moved to {$to['name']}." : 'Unlinked from device.';
            }
            break;
        case 'license_resend':
            if ($lic) { send_key_email($u, $lic, (string)(val('SELECT name FROM plans WHERE id = ?', [$lic['plan_id']]) ?: 'Custom')); $msg = 'Key emailed.'; }
            break;
        case 'license_note':
            if ($lic) { update('licenses', ['notes' => mb_substr(input('notes'), 0, 255)], 'id = ?', [$lic['id']]); $msg = 'Note saved.'; }
            break;
        case 'license_delete':
            if ($lic && $admin['role'] === 'superadmin') { q('DELETE FROM licenses WHERE id = ?', [$lic['id']]); $msg = 'License deleted.'; }
            break;
        case 'device_add':
            $code = normalize_code(input('device_id'));
            if (!valid_device_id($code) || val('SELECT 1 FROM devices WHERE device_id = ?', [$code])) { flash('Invalid or already-registered Device ID.', 'danger'); redirect($back); }
            insert('devices', ['device_id' => $code, 'user_id' => $id, 'name' => input('name') ?: 'PC', 'created_at' => now()]);
            $msg = 'Device added.';
            break;
        case 'device_block': if ($dev) { update('devices', ['status' => 'blocked'], 'id = ?', [$dev['id']]); $msg = 'Device blocked — the app stops at its next check-in.'; } break;
        case 'device_unblock': if ($dev) { update('devices', ['status' => 'active'], 'id = ?', [$dev['id']]); $msg = 'Device unblocked.'; } break;
        case 'device_rename': if ($dev) { update('devices', ['name' => mb_substr(input('name'), 0, 80)], 'id = ?', [$dev['id']]); $msg = 'Renamed.'; } break;
        case 'device_delete':
            if ($dev) { q('UPDATE licenses SET device_pk = NULL WHERE device_pk = ?', [$dev['id']]); q('DELETE FROM devices WHERE id = ?', [$dev['id']]); $msg = 'Device deleted.'; }
            break;
        case 'order_grant':
            $plan = row('SELECT * FROM plans WHERE id = ?', [input_int('plan')]);
            if ($plan) {
                $renew = input_int('renew') ? row('SELECT * FROM licenses WHERE id = ? AND user_id = ?', [input_int('renew'), $id]) : null;
                $o = create_order($id, $plan, null, $renew['id'] ?? null);
                $amount = input('amount') !== '' ? (int)round((float)input('amount') * 100) : (int)$o['amount_paise'];
                update('orders', ['amount_paise' => $amount, 'discount_paise' => max(0, (int)$o['base_paise'] - $amount), 'admin_note' => input('note') ?: 'Added by admin',
                    'reviewed_by' => $admin['id'], 'reviewed_at' => now(), 'utr' => strtoupper(input('utr'))], 'id = ?', [$o['id']]);
                fulfill_order((int)$o['id'], $actor, input('method', 'admin'));
                $msg = "Order {$o['order_no']} recorded as paid and key issued/extended.";
            }
            break;
        case 'soft_delete':
            update('users', ['status' => 'deleted', 'deleted_at' => now()], 'id = ?', [$id]);
            q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id = ?", [$id]);
            $msg = 'User marked deleted.';
            break;
        case 'hard_delete':
            if ($admin['role'] !== 'superadmin') { flash('Only superadmins can permanently delete.', 'danger'); redirect($back); }
            audit('user_hard_delete', 'user', $id, $u['email']);
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('User and all their data were deleted permanently.', 'success');
            redirect('users.php');
        default:
            flash('Unknown action.', 'danger');
            redirect($back);
    }
    audit($act, 'user', $id, array_diff_key($_POST, array_flip(['csrf', 'password'])));
    flash($msg, 'success');
    redirect($back);
}

// ── Page ──────────────────────────────────────────────────────────────────────
$tab = input('tab', 'overview');
$tabs = ['overview' => '👤 Overview', 'licenses' => '🔑 Licenses', 'devices' => '💻 Devices', 'orders' => '💰 Orders', 'activity' => '🛰 Activity',
    'sessions' => '🔐 Sessions & logins', 'emails' => '✉️ Emails', 'tickets' => '🎫 Tickets', 'notify' => '📣 Notify'];
$stats = [
    'spent' => (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE user_id = ? AND status = 'paid'", [$id]),
    'orders' => (int)val("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'paid'", [$id]),
    'events' => (int)val('SELECT COUNT(*) FROM activity_events WHERE user_id = ?', [$id]),
    'active' => (int)val("SELECT COUNT(*) FROM licenses WHERE user_id = ? AND status = 'active' AND expires_at > ?", [$id, now()]),
];
$plans = rows('SELECT * FROM plans ORDER BY sort, days');
$devices = rows('SELECT * FROM devices WHERE user_id = ? ORDER BY id DESC', [$id]);
$referrer = $u['referred_by'] ? val('SELECT email FROM users WHERE id = ?', [$u['referred_by']]) : null;
$hidden = fn($a, $extra = []) => csrf_field() . '<input type="hidden" name="action" value="' . e($a) . '"><input type="hidden" name="tab" value="' . e($tab) . '">'
    . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($extra), $extra));
admin_start($u['email'], 'users.php');
?>
<div class="card">
  <div class="card-head">
    <div style="display:flex;gap:14px;align-items:center"><div class="avatar" style="width:52px;height:52px;font-size:18px"><?php if ($u['avatar_url']): ?><img src="<?= e($u['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e(initials($u['name'], $u['email'])) ?><?php endif; ?></div>
      <div><h2 style="margin:0"><?= e($u['name'] ?: '(no name)') ?> <span class="muted small">#<?= $id ?></span></h2>
        <div class="small"><?= e($u['email']) ?> <button class="icon-btn" style="width:26px;height:26px" data-copy="<?= e($u['email']) ?>">📋</button> · <?= badge($u['status']) ?> <?= $u['email_verified'] ? badge('verified') : badge('unverified') ?>
          <?= $u['totp_enabled'] ? '<span class="badge badge-purple">2FA</span>' : '' ?> <?= $u['locked_until'] && $u['locked_until'] > now() ? '<span class="badge badge-red">Locked</span>' : '' ?></div></div></div>
    <div class="actions">
      <?php if (can('impersonate') && $u['status'] !== 'deleted'): ?>
        <form method="post" target="_blank"><?= $hidden('impersonate') ?><button class="btn" data-confirm="Open the user portal signed in as this user? This is logged.">🕵 Login as user</button></form>
      <?php endif; ?>
      <?php if (can('users')): ?>
        <form method="post"><?= $hidden('logout_all') ?><button class="btn btn-ghost btn-sm">🚪 Sign out everywhere</button></form>
        <?php if (!$u['email_verified']): ?><form method="post"><?= $hidden('verify') ?><button class="btn btn-ghost btn-sm">✅ Verify email</button></form><?php endif; ?>
        <form method="post"><?= $hidden('send_reset') ?><button class="btn btn-ghost btn-sm">🔁 Send reset link</button></form>
      <?php endif; ?>
      <details class="menu"><summary class="btn btn-ghost btn-sm">More ▾</summary><div class="menu-list">
        <a href="document.php?type=user&id=<?= $id ?>" target="_blank">🖨 Print customer file</a>
        <a href="document.php?type=statement&id=<?= $id ?>" target="_blank">📄 Account statement</a>
        <a href="user.php?id=<?= $id ?>&export=json">📦 Export all data (JSON)</a>
        <a href="tracking.php?user=<?= $id ?>&export=csv">🛰 Export activity (CSV)</a>
        <a href="user.php?id=<?= $id ?>&tab=notify">📣 Send message</a>
        <button type="button" data-copy="<?= e($u['email']) ?>">📋 Copy email</button>
        <button type="button" data-copy="<?= e($u['referral_code']) ?>">🎁 Copy referral code</button>
        <?php if ($u['phone']): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', $u['phone'])) ?>" target="_blank" rel="noopener">💬 WhatsApp user</a><?php endif; ?>
        <a href="mailto:<?= e($u['email']) ?>">✉️ Email from my mail app</a>
      </div></details>
    </div>
  </div>
  <div class="grid grid-4">
    <?= stat_card('Total spent', money($stats['spent']), $stats['orders'] . ' paid orders') ?>
    <?= stat_card('Active licenses', $stats['active']) ?>
    <?= stat_card('Tracked events', number_format($stats['events'])) ?>
    <?= stat_card('Last seen', time_ago($u['last_seen_at']), 'IP ' . e($u['last_login_ip'] ?: '—')) ?>
  </div>
</div>
<div class="tabs"><?php foreach ($tabs as $k => $label): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="user.php?id=<?= $id ?>&tab=<?= $k ?>"><?= $label ?></a><?php endforeach; ?></div>

<?php if ($tab === 'overview'): ?>
<div class="grid grid-2">
  <div class="card"><h2>Profile</h2>
    <form method="post"><?= $hidden('profile') ?>
      <div class="form-row"><div><label>Name</label><input name="name" value="<?= e($u['name']) ?>"></div><div><label>Email</label><input name="email" type="email" value="<?= e($u['email']) ?>"></div></div>
      <div class="form-row"><div><label>Phone</label><input name="phone" value="<?= e($u['phone']) ?>"></div><div><label>Country</label><input name="country" value="<?= e($u['country']) ?>"></div></div>
      <label>Tags <span class="muted">(comma separated)</span></label><input name="tags" value="<?= e($u['tags']) ?>">
      <label>Admin notes <span class="muted">(only admins see this)</span></label><textarea name="admin_notes"><?= e($u['admin_notes']) ?></textarea>
      <label class="check"><input type="checkbox" name="reminder_emails" <?= $u['reminder_emails'] ? 'checked' : '' ?>> Reminder emails</label>
      <label class="check"><input type="checkbox" name="marketing_emails" <?= $u['marketing_emails'] ? 'checked' : '' ?>> Marketing emails</label>
      <?php if (can('users')): ?><button class="btn">Save</button><?php endif; ?></form></div>
  <div>
    <div class="card"><h2>Details</h2><dl class="kv">
      <dt>Joined</dt><dd><?= local_dt($u['created_at']) ?> via <?= e($u['signup_method']) ?> · IP <?= e($u['signup_ip']) ?></dd>
      <dt>Email verified</dt><dd><?= $u['email_verified_at'] ? local_dt($u['email_verified_at']) : 'No' ?></dd>
      <dt>Google linked</dt><dd><?= $u['google_sub'] ? 'Yes' : 'No' ?> · Password: <?= $u['password_hash'] ? 'set' : 'none' ?></dd>
      <dt>Last login</dt><dd><?= local_dt($u['last_login_at']) ?> · <?= e($u['last_login_ip']) ?></dd>
      <dt>Failed logins</dt><dd><?= (int)$u['failed_logins'] ?><?= $u['locked_until'] ? ' · locked until ' . local_dt($u['locked_until']) : '' ?></dd>
      <dt>Referral code</dt><dd><code><?= e($u['referral_code']) ?></code> · <?= (int)val('SELECT COUNT(*) FROM users WHERE referred_by = ?', [$id]) ?> referred</dd>
      <dt>Referred by</dt><dd><?= $referrer ? user_link((int)$u['referred_by'], $referrer) : '—' ?></dd>
      <dt>Theme</dt><dd><?= e($u['theme']) ?></dd></dl></div>
    <?php if (can('users')): ?>
    <div class="card"><h2>Account status</h2>
      <form method="post"><?= $hidden('status') ?><div class="form-row"><div><label>Status</label><select name="status"><?php foreach (['active', 'suspended', 'banned'] as $s): ?><option <?= $u['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></div>
        <div><label>Reason (shown to user)</label><input name="reason" value="<?= e($u['status_reason']) ?>"></div></div>
        <label class="check"><input type="checkbox" name="notify"> Email the user</label><button class="btn btn-amber">Update status</button></form>
      <div class="actions" style="margin-top:14px">
        <form method="post"><?= $hidden('unlock') ?><button class="btn btn-sm btn-ghost">🔓 Unlock</button></form>
        <?php if ($u['totp_enabled']): ?><form method="post"><?= $hidden('disable_2fa') ?><button class="btn btn-sm btn-ghost" data-confirm="Turn off this user's 2FA?">Turn off 2FA</button></form><?php endif; ?>
        <?php if (!$u['email_verified']): ?><form method="post"><?= $hidden('resend_verify') ?><button class="btn btn-sm btn-ghost">✉️ Resend verification</button></form>
        <?php else: ?><form method="post"><?= $hidden('unverify') ?><button class="btn btn-sm btn-ghost">Mark unverified</button></form><?php endif; ?>
      </div>
      <form method="post" class="input-group" style="margin-top:12px"><?= $hidden('set_password') ?><input type="password" name="password" placeholder="Set a new password (8+)"><button class="btn btn-sm btn-ghost">Set password</button></form>
      <div class="actions" style="margin-top:14px">
        <form method="post" data-confirm="Mark this user as deleted? They can't sign in."><?= $hidden('soft_delete') ?><button class="btn btn-sm btn-danger">Mark deleted</button></form>
        <?php if ($admin['role'] === 'superadmin'): ?><form method="post" data-type-confirm="DELETE"><?= $hidden('hard_delete') ?><button class="btn btn-sm btn-danger">🗑 Delete forever</button></form><?php endif; ?>
      </div></div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($tab === 'licenses'):
  $lics = rows('SELECT l.*, d.name AS dname, d.device_id AS dcode, p.name AS pname FROM licenses l LEFT JOIN devices d ON d.id = l.device_pk LEFT JOIN plans p ON p.id = l.plan_id WHERE l.user_id = ? ORDER BY l.id DESC', [$id]); ?>
<?php if (can('licenses')): ?>
<div class="card"><h2>➕ Issue a license</h2>
  <form method="post" class="form-row" style="align-items:end"><?= $hidden('license_issue') ?>
    <div><label>Plan</label><select name="plan"><option value="0">Custom days →</option><?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= (int)$p['days'] ?>d)</option><?php endforeach; ?></select></div>
    <div><label>Custom days</label><input type="number" name="days" value="30" min="1"></div>
    <div><label>Bind to device</label><select name="device"><option value="0">None (binds on activation)</option><?php foreach ($devices as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?> · <?= e($d['device_id']) ?></option><?php endforeach; ?></select></div>
    <div><label>Type</label><select name="source"><option value="admin">Admin grant</option><option value="trial">Trial</option></select></div>
    <div><label class="check"><input type="checkbox" name="send_email" checked> Email key</label><button class="btn">Issue key</button></div></form></div>
<?php endif; ?>
<?php foreach ($lics as $l): $st = effective_status($l); ?>
<div class="card">
  <div class="card-head"><div><span class="key-box" style="font-size:15px"><?= e($l['license_key']) ?> <button class="icon-btn" data-copy="<?= e($l['license_key']) ?>">📋</button></span>
    <?= badge($st) ?> <span class="muted small"><?= e($l['pname'] ?: ucfirst($l['source'])) ?> · <?= (int)$l['days'] ?> days · issued <?= local_dt($l['issued_at']) ?></span></div>
    <div><b><?= $l['expires_at'] ? days_left($l) . ' days left' : 'Not activated' ?></b></div></div>
  <dl class="kv"><dt>Device</dt><dd><?= $l['dname'] ? e($l['dname']) . ' <code>' . e($l['dcode']) . '</code>' : '—' ?></dd>
    <dt>Activated</dt><dd><?= local_dt($l['activated_at']) ?></dd><dt>Expires</dt><dd><?= local_dt($l['expires_at']) ?></dd>
    <dt>Last app check</dt><dd><?= time_ago($l['last_check_at']) ?></dd><?php if ($l['revoked_reason']): ?><dt>Reason</dt><dd><?= e($l['revoked_reason']) ?></dd><?php endif; ?></dl>
  <?php if (can('licenses')): ?>
  <div class="actions" style="margin-top:12px">
    <form method="post" class="input-group"><?= $hidden('license_extend', ['license' => $l['id']]) ?><input type="number" name="days" value="7" style="width:80px"><label class="check small"><input type="checkbox" name="send_email"> email</label><button class="btn btn-sm">± Days</button></form>
    <form method="post" class="input-group"><?= $hidden('license_set_expiry', ['license' => $l['id']]) ?><input type="datetime-local" name="expires" value="<?= $l['expires_at'] ? gmdate('Y-m-d\TH:i', utc_ts($l['expires_at']) + setting('timezone_offset_minutes') * 60) : '' ?>"><button class="btn btn-sm btn-ghost">Set expiry</button></form>
    <form method="post" class="input-group"><?= $hidden('license_move', ['license' => $l['id']]) ?><select name="to_device"><option value="0">Unlink</option><?php foreach ($devices as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === (int)$l['device_pk'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-ghost">Move</button></form>
  </div>
  <div class="actions" style="margin-top:8px">
    <?php if (in_array($st, ['active', 'issued'], true)): ?>
      <form method="post" class="input-group"><?= $hidden('license_revoke', ['license' => $l['id']]) ?><input name="reason" placeholder="Reason"><label class="check small"><input type="checkbox" name="send_email"> email</label><button class="btn btn-sm btn-danger" data-confirm="Revoke this license?">Revoke</button></form>
      <form method="post"><?= $hidden('license_suspend', ['license' => $l['id']]) ?><button class="btn btn-sm btn-amber">Suspend</button></form>
    <?php else: ?><form method="post"><?= $hidden('license_reactivate', ['license' => $l['id']]) ?><button class="btn btn-sm btn-green">Reactivate</button></form><?php endif; ?>
    <form method="post"><?= $hidden('license_reset_clock', ['license' => $l['id']]) ?><button class="btn btn-sm btn-ghost" data-confirm="Reset to unused? Days restart when activated again.">Reset to unused</button></form>
    <form method="post"><?= $hidden('license_resend', ['license' => $l['id']]) ?><button class="btn btn-sm btn-ghost">✉️ Email key</button></form>
    <form method="post" class="input-group"><?= $hidden('license_note', ['license' => $l['id']]) ?><input name="notes" value="<?= e($l['notes']) ?>" placeholder="Note"><button class="btn btn-sm btn-ghost">Save note</button></form>
    <?php if ($admin['role'] === 'superadmin'): ?><form method="post" data-confirm="Delete this license permanently?"><?= $hidden('license_delete', ['license' => $l['id']]) ?><button class="btn btn-sm btn-danger">🗑</button></form><?php endif; ?>
  </div>
  <?php endif; ?>
  <details style="margin-top:8px"><summary class="small muted">History</summary><ul class="timeline"><?php foreach (rows('SELECT * FROM license_events WHERE license_id = ? ORDER BY id DESC', [$l['id']]) as $ev): ?>
    <li><b><?= e($ev['kind']) ?></b> <?= e($ev['detail']) ?> <span class="muted small">· <?= e($ev['actor']) ?> · <?= local_dt($ev['created_at']) ?></span></li><?php endforeach; ?></ul></details>
</div>
<?php endforeach; ?><?php if (!$lics): ?><div class="card empty">No licenses.</div><?php endif; ?>

<?php elseif ($tab === 'devices'): ?>
<div class="card"><?php if (can('users')): ?><form method="post" class="input-group" style="max-width:640px;margin-bottom:14px"><?= $hidden('device_add') ?><input name="device_id" placeholder="HMD-XXXX-XXXX-XXXX-XXXX" class="mono"><input name="name" placeholder="Name"><button class="btn btn-sm">Add device</button></form><?php endif; ?>
<div class="table-wrap"><table class="compact"><thead><tr><th>Device</th><th>App / PC</th><th>Last seen</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($devices as $d): ?><tr><td><code><?= e($d['device_id']) ?></code><form method="post" class="input-group"><?= $hidden('device_rename', ['device' => $d['id']]) ?><input name="name" value="<?= e($d['name']) ?>" style="padding:4px 8px"><button class="btn btn-sm btn-ghost">✓</button></form></td>
  <td>v<?= e($d['app_version'] ?: '?') ?> · <?= e($d['hostname']) ?><div class="small muted"><?= e($d['os']) ?></div></td>
  <td><?= time_ago($d['last_seen_at']) ?><div class="small muted"><?= e($d['last_ip']) ?> · added <?= local_dt($d['created_at'], false) ?></div></td><td><?= badge($d['status']) ?></td>
  <td class="actions"><?php if ($d['status'] === 'blocked'): ?><form method="post"><?= $hidden('device_unblock', ['device' => $d['id']]) ?><button class="btn btn-sm btn-green">Unblock</button></form>
    <?php else: ?><form method="post"><?= $hidden('device_block', ['device' => $d['id']]) ?><button class="btn btn-sm btn-amber" data-confirm="Block this device?">Block</button></form><?php endif; ?>
    <form method="post" data-confirm="Delete this device?"><?= $hidden('device_delete', ['device' => $d['id']]) ?><button class="btn btn-sm btn-ghost">🗑</button></form></td></tr><?php endforeach; ?>
<?php if (!$devices): ?><tr><td colspan="5" class="empty">No devices.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($tab === 'orders'): $orders = rows('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC', [$id]); ?>
<?php if (can('payments')): ?>
<div class="card"><h2>➕ Record a payment / grant a plan</h2>
  <form method="post" class="form-row" style="align-items:end"><?= $hidden('order_grant') ?>
    <div><label>Plan</label><select name="plan"><?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> · <?= money($p['price_paise']) ?></option><?php endforeach; ?></select></div>
    <div><label>Amount received (₹)</label><input name="amount" type="number" step="0.01" placeholder="Plan price"></div>
    <div><label>Method</label><select name="method"><option value="admin">Admin / complimentary</option><option value="upi">UPI</option><option value="razorpay">Razorpay</option><option value="cash">Cash / bank</option></select></div>
    <div><label>UTR / reference</label><input name="utr"></div>
    <div><label>Renew license</label><select name="renew"><option value="0">New key</option><?php foreach (rows("SELECT id, license_key FROM licenses WHERE user_id = ? AND status <> 'revoked'", [$id]) as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['license_key']) ?></option><?php endforeach; ?></select></div>
    <div><label>Note</label><input name="note"></div>
    <div><button class="btn" data-confirm="Record this as paid and issue/extend the license?">Record as paid</button></div></form></div>
<?php endif; ?>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Order</th><th>Plan</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach ($orders as $o): ?><tr><td><a href="orders.php?q=<?= e($o['order_no']) ?>"><?= e($o['order_no']) ?></a><div class="small muted"><?= e($o['invoice_no']) ?></div></td><td><?= e($o['plan_name']) ?></td><td><?= money($o['amount_paise']) ?><?= $o['coupon_code'] ? '<div class="small muted">' . e($o['coupon_code']) . '</div>' : '' ?></td>
  <td><?= e($o['method']) ?><?= $o['utr'] ? '<div class="small muted">UTR ' . e($o['utr']) . '</div>' : '' ?></td><td><?= badge($o['status']) ?></td><td class="small"><?= local_dt($o['created_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$orders): ?><tr><td colspan="6" class="empty">No orders.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($tab === 'activity'):
  $w = 'user_id = ?'; $p = [$id];
  if (($k = input('kind')) !== '') { $w .= ' AND kind = ?'; $p[] = $k; }
  if (($s = input('source')) !== '') { $w .= ' AND source = ?'; $p[] = $s; }
  $total = (int)val("SELECT COUNT(*) FROM activity_events WHERE $w", $p);
  [$lim, $off, $page, $pages] = paginate($total, 50);
  $events = rows("SELECT * FROM activity_events WHERE $w ORDER BY id DESC LIMIT $lim OFFSET $off", $p);
  $kinds = array_column(rows('SELECT DISTINCT kind FROM activity_events WHERE user_id = ? ORDER BY kind', [$id]), 'kind'); ?>
<div class="card"><form class="filters"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="activity">
  <?= filter_select('kind', array_combine($kinds, array_map('event_label', $kinds)), 'All events') ?><?= filter_select('source', ['web' => 'Web portal', 'desktop' => 'Desktop app', 'admin' => 'Admin', 'system' => 'System'], 'All sources') ?>
  <a class="btn btn-sm btn-ghost" href="tracking.php?user=<?= $id ?>&export=csv">⬇ Export all (CSV)</a></form>
<div class="table-wrap"><table class="compact"><thead><tr><th>Time</th><th>Event</th><th>Details</th><th>IP / client</th><th>ms</th></tr></thead><tbody>
<?php foreach ($events as $ev): ?><tr><td class="small" style="white-space:nowrap"><?= local_dt($ev['created_at']) ?></td><td><?= e(event_label($ev['kind'])) ?><div class="small muted"><?= e($ev['source']) ?></div></td>
  <td class="small"><?= e($ev['label'] ?: $ev['path']) ?><?= $ev['device_id'] ? '<div class="muted">' . e($ev['device_id']) . '</div>' : '' ?><?php if ($ev['meta']): ?><details><summary class="muted">meta</summary><code class="small"><?= e($ev['meta']) ?></code></details><?php endif; ?></td>
  <td class="small"><?= e($ev['ip']) ?><div class="muted"><?= e(short_agent($ev['user_agent'])) ?></div></td><td class="small"><?= (int)$ev['duration_ms'] ?: '' ?></td></tr><?php endforeach; ?>
<?php if (!$events): ?><tr><td colspan="5" class="empty">No events.</td></tr><?php endif; ?></tbody></table></div><?= pager($page, $pages, $total) ?></div>

<?php elseif ($tab === 'sessions'):
  $sess = rows("SELECT * FROM sessions WHERE kind = 'user' AND owner_id = ? ORDER BY id DESC LIMIT 50", [$id]);
  $att = rows("SELECT * FROM login_attempts WHERE kind = 'user' AND email = ? ORDER BY id DESC LIMIT 50", [$u['email']]); ?>
<div class="card"><h2>Sessions</h2><div class="table-wrap"><table class="compact"><thead><tr><th>Browser</th><th>IP</th><th>Created</th><th>Last seen</th><th>State</th><th></th></tr></thead><tbody>
<?php foreach ($sess as $s): $live = !$s['revoked'] && $s['expires_at'] > now(); ?><tr><td><?= e(short_agent($s['user_agent'])) ?><?= $s['impersonated_by'] ? ' <span class="badge badge-amber">admin #' . (int)$s['impersonated_by'] . '</span>' : '' ?></td><td><?= e($s['ip']) ?></td>
  <td class="small"><?= local_dt($s['created_at']) ?></td><td class="small"><?= time_ago($s['last_seen_at']) ?></td><td><?= $live ? badge('active') : badge('closed') ?></td>
  <td><?php if ($live): ?><form method="post"><?= $hidden('session_revoke', ['session' => $s['id']]) ?><button class="btn btn-sm btn-ghost">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h2>Login attempts</h2><div class="table-wrap"><table class="compact"><tbody>
<?php foreach ($att as $a): ?><tr><td class="small"><?= local_dt($a['created_at']) ?></td><td><?= $a['success'] ? badge('active') : '<span class="badge badge-red">Failed</span>' ?> <?= e($a['reason']) ?></td><td><?= e($a['ip']) ?></td><td class="small"><?= e(short_agent($a['user_agent'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<?php elseif ($tab === 'emails'): $mails = rows('SELECT id, subject, template, status, error, created_at FROM email_logs WHERE user_id = ? OR to_email = ? ORDER BY id DESC LIMIT 100', [$id, $u['email']]); ?>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Time</th><th>Subject</th><th>Template</th><th>Status</th></tr></thead><tbody>
<?php foreach ($mails as $m): ?><tr><td class="small"><?= local_dt($m['created_at']) ?></td><td><a href="emails.php?view=<?= (int)$m['id'] ?>"><?= e($m['subject']) ?></a></td><td><?= e($m['template']) ?></td><td><?= badge($m['status']) ?><div class="small muted"><?= e($m['error']) ?></div></td></tr><?php endforeach; ?>
<?php if (!$mails): ?><tr><td colspan="4" class="empty">No emails.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($tab === 'tickets'): $tk = rows('SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC', [$id]); ?>
<div class="card"><div class="table-wrap"><table class="compact"><tbody>
<?php foreach ($tk as $t): ?><tr><td>#<?= (int)$t['id'] ?></td><td><a href="ticket.php?id=<?= (int)$t['id'] ?>"><?= e($t['subject']) ?></a></td><td><?= badge($t['status']) ?></td><td><?= badge($t['priority']) ?></td><td class="small"><?= time_ago($t['updated_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$tk): ?><tr><td class="empty">No tickets.</td></tr><?php endif; ?></tbody></table></div></div>

<?php elseif ($tab === 'notify'): ?>
<div class="card" style="max-width:640px"><h2>Send this user a message</h2>
  <form method="post"><?= $hidden('notify') ?><label>Title</label><input name="title" required maxlength="150"><label>Message</label><textarea name="body"></textarea>
    <div class="form-row"><div><label>Type</label><select name="level"><option>info</option><option>success</option><option>warning</option><option>danger</option></select></div><div><label>Link (portal page)</label><input name="link" placeholder="e.g. plans.php"></div></div>
    <label class="check"><input type="checkbox" name="email" checked> Also send by email</label><button class="btn">Send</button></form></div>
<?php endif; ?>
<?php admin_end();
