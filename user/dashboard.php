<?php
require __DIR__ . '/_init.php';
require __DIR__ . '/_progress.php';
$u = require_login();
$st = onboarding_state((int)$u['id']);
$lics = rows("SELECT l.*, d.name AS device_name, d.device_id AS device_code, p.name AS plan_name FROM licenses l
    LEFT JOIN devices d ON d.id = l.device_pk LEFT JOIN plans p ON p.id = l.plan_id
    WHERE l.user_id = ? AND l.status IN ('active','issued','suspended') ORDER BY l.expires_at IS NULL, l.expires_at", [$u['id']]);
$devices = (int)val("SELECT COUNT(*) FROM devices WHERE user_id = ? AND status <> 'removed'", [$u['id']]);
$spent = (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE user_id = ? AND status = 'paid'", [$u['id']]);
$notes = rows('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$u['id']]);
$online = (int)val("SELECT COUNT(*) FROM devices WHERE user_id = ? AND last_seen_at > ?", [$u['id'], ts_plus('-' . (setting('heartbeat_minutes') * 2 + 1) . ' minutes')]);
$first = explode(' ', trim($u['name']))[0] ?: 'there';
page_start('Dashboard', 'dashboard.php');
?>
<h2>Hi <?= e($first) ?> 👋</h2>
<?php if ($st['current']): ?>
  <div class="card"><div class="card-head"><h2>🚀 Finish setting up (<?= $st['done'] ?>/5)</h2><a class="btn btn-sm" href="get-started.php">Continue</a></div>
  <?= onboarding_bar($st) ?></div>
<?php endif; ?>
<?php if ($st['pending_payment']): ?><div class="alert alert-info">🧾 Your UPI payment is being verified. You'll get your key by email as soon as it's approved.</div><?php endif; ?>
<?php foreach ($lics as $l): $left = days_left($l); if ($l['status'] === 'active' && $left !== null && $left <= setting('desktop_reminder_days')): ?>
  <div class="alert alert-warning">⏰ <b><?= e($l['license_key']) ?></b> expires in <b><?= $left ?> day<?= $left == 1 ? '' : 's' ?></b> (<?= local_dt($l['expires_at'], false) ?>). <a href="plans.php?renew=<?= (int)$l['id'] ?>">Renew now →</a></div>
<?php endif; endforeach; ?>

<div class="grid grid-4">
  <div class="stat"><div class="label">Active licenses</div><div class="value"><?= $st['active'] ?></div><div class="sub"><?= count($lics) ?> total usable</div></div>
  <div class="stat"><div class="label">Devices</div><div class="value"><?= $devices ?></div><div class="sub"><?= $online ?> online now</div></div>
  <div class="stat"><div class="label">Total spent</div><div class="value"><?= money($spent) ?></div><div class="sub"><a href="orders.php">View payments</a></div></div>
  <div class="stat"><div class="label">Member since</div><div class="value" style="font-size:18px"><?= local_dt($u['created_at'], false) ?></div><div class="sub"><?= $u['signup_method'] === 'google' ? 'Google account' : 'Email account' ?></div></div>
</div>
<br>
<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h2>🔑 Your plans</h2><a class="btn btn-sm btn-ghost" href="licenses.php">All licenses</a></div>
    <?php if (!$lics): ?><div class="empty">No active plan yet.<br><br><a class="btn" href="plans.php">Choose a plan</a></div><?php endif; ?>
    <?php foreach (array_slice($lics, 0, 3) as $l): $left = days_left($l); ?>
      <div class="lic" style="margin-bottom:14px">
        <?= license_days_ring($left, (int)$l['days']) ?>
        <div><div class="key-box" style="font-size:14px"><?= e($l['license_key']) ?> <button class="icon-btn" data-copy="<?= e($l['license_key']) ?>" title="Copy">📋</button></div>
          <div class="small muted" style="margin-top:6px"><?= e($l['plan_name'] ?: ucfirst($l['source'])) ?> · <?= badge(effective_status($l)) ?> ·
            <?= $l['device_name'] ? '💻 ' . e($l['device_name']) : 'No device yet' ?></div>
          <div class="small muted"><?= $l['expires_at'] ? 'Ends ' . local_dt($l['expires_at']) : 'Starts when you activate it in the app' ?></div>
          <?php if ($l['status'] === 'active'): ?><a class="btn btn-sm" style="margin-top:8px" href="plans.php?renew=<?= (int)$l['id'] ?>">Renew / add days</a><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <div class="card-head"><h2>🔔 Latest updates</h2><a class="btn btn-sm btn-ghost" href="notifications.php">See all</a></div>
    <?php if (!$notes): ?><div class="empty">Nothing yet.</div><?php endif; ?>
    <?php foreach ($notes as $n): ?>
      <div class="notif <?= $n['read_at'] ? '' : 'unread' ?>" style="padding:10px"><div><b><?= e($n['title']) ?></b><div class="small muted"><?= e($n['body']) ?> · <?= time_ago($n['created_at']) ?></div></div></div>
    <?php endforeach; ?>
  </div>
</div>
<div class="card"><div class="card-head"><h2>⚡ Quick actions</h2><button class="btn btn-sm btn-ghost" data-palette>More… <kbd>Ctrl K</kbd></button></div>
<div class="qa-grid">
  <?php $first_lic = $lics[0]['id'] ?? null;
  foreach (array_filter([
      ['plans.php', '💎', $lics ? 'Renew / add days' : 'Buy a plan', 'From ' . money((int)val('SELECT MIN(price_paise) FROM plans WHERE active = 1'))],
      ['download.php', '⬇️', 'Download app', 'Windows 10 / 11'],
      ['devices.php', '💻', 'Add a device', 'Enter the Device ID'],
      $first_lic ? ['certificate.php?id=' . $first_lic, '🎓', 'License certificate', 'Print your key'] : null,
      ['statement.php', '🖨', 'Account statement', 'Print or PDF'],
      ['orders.php', '🧾', 'Invoices', 'View, print, email'],
      ['referrals.php', '🎁', 'Refer friends', 'Earn free days'],
      ['support.php', '💬', 'Get help', 'Open a ticket'],
      ['security.php', '🔐', 'Security', $u['totp_enabled'] ? '2FA is on' : 'Turn on 2FA'],
      ['profile.php', '📷', 'Profile photo', 'Make it yours'],
  ]) as [$href, $ico, $t, $s]): ?>
    <a class="qa" href="<?= e($href) ?>"><span><?= $ico ?></span><?= e($t) ?><small><?= e($s) ?></small></a>
  <?php endforeach; ?>
</div></div>
<?php page_end();
