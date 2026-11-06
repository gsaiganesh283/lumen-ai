<?php
require __DIR__ . '/_init.php';
$u = require_login(false);

// Theme switch from the header button (fetch with CSRF header).
if (isset($_GET['theme']) && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid()) {
    update('users', ['theme' => $_GET['theme'] === 'light' ? 'light' : 'dark'], 'id = ?', [$u['id']]);
    http_response_code(204);
    exit;
}

if (isset($_GET['export'])) {   // download my data
    block_if_impersonating();
    $data = ['account' => array_diff_key($u, array_flip(['password_hash', 'totp_secret', 'admin_notes', 'tags'])),
        'licenses' => rows('SELECT license_key, status, days, activated_at, expires_at, created_at FROM licenses WHERE user_id = ?', [$u['id']]),
        'devices' => rows('SELECT device_id, name, hostname, app_version, last_seen_at, created_at FROM devices WHERE user_id = ?', [$u['id']]),
        'orders' => rows('SELECT order_no, invoice_no, plan_name, amount_paise, method, status, created_at, paid_at FROM orders WHERE user_id = ?', [$u['id']]),
        'tickets' => rows('SELECT id, subject, status, created_at FROM tickets WHERE user_id = ?', [$u['id']]),
        'activity_last_1000' => rows('SELECT kind, label, path, ip, user_agent, created_at FROM activity_events WHERE user_id = ? ORDER BY id DESC LIMIT 1000', [$u['id']])];
    track('data_export', ['user_id' => (int)$u['id'], 'label' => 'Downloaded own data']);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="hiremind-my-data.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_post()) {
    $action = input('action');
    if ($action === 'profile') {
        $phone = preg_replace('/[^0-9+\- ]/', '', input('phone'));
        update('users', ['name' => mb_substr(input('name'), 0, 120), 'phone' => mb_substr($phone, 0, 30), 'country' => mb_substr(input('country'), 0, 60)], 'id = ?', [$u['id']]);
        track('profile', ['user_id' => (int)$u['id'], 'label' => 'Updated profile']);
        flash('Profile saved.', 'success');
    } elseif ($action === 'avatar') {
        $f = $_FILES['photo'] ?? null;
        $mime = $f && is_uploaded_file($f['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if (!$ext || $f['size'] > 2 * 1024 * 1024 || !@getimagesize($f['tmp_name'])) {
            flash('Choose a PNG, JPG, WEBP or GIF image under 2 MB.', 'danger');
        } else {
            $dir = HM_DATA . '/uploads/avatars';
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            foreach (glob("$dir/{$u['id']}_*") ?: [] as $old) @unlink($old);
            $name = $u['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            move_uploaded_file($f['tmp_name'], "$dir/$name");
            update('users', ['avatar_url' => site_url('/avatar.php?f=' . $name)], 'id = ?', [$u['id']]);
            track('profile', ['user_id' => (int)$u['id'], 'label' => 'Uploaded a profile photo']);
            flash('Profile photo updated 📷', 'success');
        }
    } elseif ($action === 'avatar_remove') {
        foreach (glob(HM_DATA . "/uploads/avatars/{$u['id']}_*") ?: [] as $old) @unlink($old);
        update('users', ['avatar_url' => ''], 'id = ?', [$u['id']]);
        flash('Profile photo removed.', 'info');
    } elseif ($action === 'emails') {
        update('users', ['reminder_emails' => isset($_POST['reminder_emails']) ? 1 : 0, 'marketing_emails' => isset($_POST['marketing_emails']) ? 1 : 0,
            'login_alerts' => isset($_POST['login_alerts']) ? 1 : 0], 'id = ?', [$u['id']]);
        flash('Email preferences saved.', 'success');
    } elseif ($action === 'delete') {
        block_if_impersonating();
        $ok = $u['password_hash'] ? password_verify($_POST['password'] ?? '', $u['password_hash']) : input('confirm') === 'DELETE';
        if (!$ok) {
            flash($u['password_hash'] ? 'Wrong password.' : 'Type DELETE to confirm.', 'danger');
        } else {
            $anon = 'deleted+' . $u['id'] . '@deleted.invalid';
            update('users', ['status' => 'deleted', 'deleted_at' => now(), 'email' => $anon, 'name' => 'Deleted user', 'phone' => '',
                'password_hash' => null, 'google_sub' => null, 'avatar_url' => '', 'totp_secret' => null, 'totp_enabled' => 0], 'id = ?', [$u['id']]);
            q("UPDATE licenses SET status = 'revoked', revoked_reason = 'Account deleted' WHERE user_id = ? AND status IN ('issued','active','suspended')", [$u['id']]);
            q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id = ?", [$u['id']]);
            track('account_deleted', ['user_id' => (int)$u['id'], 'label' => 'User deleted their account']);
            set_cookie('hm_user', '', time() - 3600);
            flash('Your account was deleted. Sorry to see you go.', 'info');
            redirect('index.php');
        }
    }
    redirect('profile.php');
}
page_start('Profile', 'profile.php');
?>
<div class="grid grid-2">
<div class="card"><h2>👤 Your details</h2>
  <div style="display:flex;gap:18px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
    <div class="avatar-lg"><?php if ($u['avatar_url']): ?><img src="<?= e($u['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e(initials($u['name'], $u['email'])) ?><?php endif; ?></div>
    <div><form method="post" enctype="multipart/form-data" class="input-group"><?= csrf_field() ?><input type="hidden" name="action" value="avatar">
        <input type="file" name="photo" accept="image/png,image/jpeg,image/webp,image/gif" required onchange="this.form.requestSubmit()"></form>
      <?php if ($u['avatar_url']): ?><form method="post" style="margin-top:6px"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" name="action" value="avatar_remove">Remove photo</button></form><?php endif; ?>
      <div class="hint">PNG, JPG, WEBP or GIF · max 2 MB</div></div></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <label>Email</label><input value="<?= e($u['email']) ?>" disabled>
    <div class="hint"><?= $u['email_verified'] ? '✅ Verified' : '⚠️ Not verified' ?> · Signed up with <?= $u['signup_method'] === 'google' ? 'Google' : 'email' ?> on <?= local_dt($u['created_at'], false) ?></div>
    <label for="name">Name</label><input id="name" name="name" value="<?= e($u['name']) ?>" maxlength="120" required>
    <div class="form-row"><div><label for="phone">Phone</label><input id="phone" name="phone" type="tel" value="<?= e($u['phone']) ?>" maxlength="30"></div>
      <div><label for="country">Country</label><input id="country" name="country" value="<?= e($u['country']) ?>" maxlength="60"></div></div>
    <br><button class="btn">Save</button></form></div>
<div class="card"><h2>✉️ Email preferences</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="emails">
    <label class="check"><input type="checkbox" name="reminder_emails" <?= $u['reminder_emails'] ? 'checked' : '' ?>> Plan expiry reminders</label>
    <label class="check"><input type="checkbox" name="login_alerts" <?= $u['login_alerts'] ? 'checked' : '' ?>> New sign-in alerts</label>
    <label class="check"><input type="checkbox" name="marketing_emails" <?= $u['marketing_emails'] ? 'checked' : '' ?>> Offers and product news</label>
    <p class="hint">Receipts, keys and security emails are always sent.</p>
    <button class="btn">Save</button></form>
  <h2 style="margin-top:24px">📦 Your data</h2>
  <p class="muted small">Download everything we store about you (account, licenses, devices, payments, activity).</p>
  <a class="btn btn-ghost" href="profile.php?export=1">Download my data (JSON)</a></div>
</div>
<div class="card" style="border-color:rgba(255,93,108,.4)"><h2>🗑 Delete account</h2>
  <p class="muted small">This signs you out everywhere, deactivates your licenses and removes your personal details. This can't be undone.</p>
  <form method="post" data-confirm="Delete your account permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <div class="input-group" style="max-width:420px"><?php if ($u['password_hash']): ?><input type="password" name="password" placeholder="Your password" required>
      <?php else: ?><input name="confirm" placeholder="Type DELETE" required><?php endif; ?><button class="btn btn-danger">Delete</button></div></form></div>
<?php page_end();
