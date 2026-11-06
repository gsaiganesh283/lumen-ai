<?php
require __DIR__ . '/_init.php';
$u = require_login();
$devices = rows("SELECT * FROM devices WHERE user_id = ? AND status = 'active'", [$u['id']]);

if (is_post()) {
    block_if_impersonating();
    $lic = row('SELECT * FROM licenses WHERE id = ? AND user_id = ?', [input_int('license'), $u['id']]);
    if (!$lic) { flash('License not found.', 'danger'); redirect('licenses.php'); }
    if (input('action') === 'move') {
        $dev = row("SELECT * FROM devices WHERE id = ? AND user_id = ? AND status = 'active'", [input_int('device'), $u['id']]);
        $limit = setting('device_changes_per_month');
        if (!$dev) flash('Choose one of your devices.', 'danger');
        elseif ((int)$lic['device_pk'] === (int)$dev['id']) flash('The license is already on that device.', 'info');
        elseif (!in_array(effective_status($lic), ['active', 'issued'], true)) flash('Only active or unused licenses can be moved.', 'danger');
        elseif ($lic['device_pk'] && device_changes_this_month((int)$u['id']) >= $limit) flash("You can move licenses $limit times per 30 days. Contact support for help.", 'danger');
        else {
            $from = $lic['device_pk'] ? val('SELECT device_id FROM devices WHERE id = ?', [$lic['device_pk']]) : 'none';
            update('licenses', ['device_pk' => $dev['id']], 'id = ?', [$lic['id']]);
            license_event((int)$lic['id'], $lic['device_pk'] ? 'transferred' : 'assigned', "$from → {$dev['device_id']}", 'user');
            track('license_moved', ['user_id' => (int)$u['id'], 'label' => "Moved {$lic['license_key']} to {$dev['device_id']}"]);
            flash("License moved to {$dev['name']}. Activate the key in the app on that PC.", 'success');
        }
    } elseif (input('action') === 'resend') {
        if (rate_limit('keymail:' . $u['id'], 5, 3600)) {
            send_key_email($u, $lic, (string)(val('SELECT name FROM plans WHERE id = ?', [$lic['plan_id']]) ?: 'Custom'));
            flash('We emailed the key to ' . $u['email'] . '.', 'success');
        } else flash('Please wait before requesting more emails.', 'danger');
    }
    redirect('licenses.php');
}

$lics = rows("SELECT l.*, d.name AS device_name, d.device_id AS device_code, d.last_seen_at AS device_seen, p.name AS plan_name
    FROM licenses l LEFT JOIN devices d ON d.id = l.device_pk LEFT JOIN plans p ON p.id = l.plan_id
    WHERE l.user_id = ? ORDER BY FIELD(l.status,'active','issued','suspended','expired','revoked'), l.id DESC", [$u['id']]);
page_start('Licenses', 'licenses.php');
?>
<?php if (isset($_GET['new'])): ?><div class="alert alert-success">🎉 Your new key is below. Copy it and activate it in the desktop app.</div><?php endif; ?>
<div class="card"><h2>How to activate</h2>
  <ol class="muted" style="margin:0"><li>Open the desktop app → click the 🔑 badge at the top left.</li><li>Check the Device ID matches the device below (or add it on <a href="devices.php">Devices</a>).</li>
  <li>Paste your key → <b>Activate</b>. The badge then counts the days left.</li></ol></div>
<?php if (!$lics): ?><div class="card empty">You have no licenses yet.<br><br><a class="btn" href="plans.php">Choose a plan</a></div><?php endif; ?>
<?php foreach ($lics as $l): $status = effective_status($l); $left = days_left($l); ?>
<div class="card">
  <div class="lic">
    <?= license_days_ring($left, (int)$l['days']) ?>
    <div style="flex:1;min-width:260px">
      <div class="key-box"><?= e($l['license_key']) ?> <button class="icon-btn" data-copy="<?= e($l['license_key']) ?>" title="Copy key">📋</button></div>
      <dl class="kv" style="margin-top:12px">
        <dt>Status</dt><dd><?= badge($status) ?><?= $status === 'revoked' && $l['revoked_reason'] ? ' <span class="muted small">' . e($l['revoked_reason']) . '</span>' : '' ?></dd>
        <dt>Plan</dt><dd><?= e($l['plan_name'] ?: ucfirst($l['source'])) ?> · <?= (int)$l['days'] ?> days total</dd>
        <dt>Device</dt><dd><?= $l['device_name'] ? '💻 ' . e($l['device_name']) . ' <code class="small">' . e($l['device_code']) . '</code> · seen ' . time_ago($l['device_seen']) : '<span class="muted">Not linked — it links automatically when you activate</span>' ?></dd>
        <dt>Activated</dt><dd><?= $l['activated_at'] ? local_dt($l['activated_at']) : 'Not yet' ?></dd>
        <dt>Expires</dt><dd><?= $l['expires_at'] ? local_dt($l['expires_at']) : (setting('license_starts_on') === 'activation' ? "{$l['days']} days after activation" : '—') ?></dd>
      </dl>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php if (in_array($status, ['active', 'issued', 'expired'], true)): ?><a class="btn btn-sm" href="plans.php?renew=<?= (int)$l['id'] ?>">🔁 Renew / add days</a><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="license" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm btn-ghost btn-block" name="action" value="resend">✉️ Email me the key</button></form>
      <a class="btn btn-sm btn-ghost" href="certificate.php?id=<?= (int)$l['id'] ?>">🎓 Certificate / print</a>
      <button class="btn btn-sm btn-ghost" data-copy="<?= e("Activation key: {$l['license_key']}\n1. Open " . setting('site_name') . " → click the 🔑 badge (top left)\n2. Paste the key → Activate") ?>">📋 Copy setup steps</button>
      <?php if ($devices && in_array($status, ['active', 'issued'], true)): ?>
        <form method="post" data-confirm="Move this license to the selected device? The old PC stops working with it."><?= csrf_field() ?>
          <input type="hidden" name="license" value="<?= (int)$l['id'] ?>"><input type="hidden" name="action" value="move">
          <select name="device" style="padding:6px"><?php foreach ($devices as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === (int)$l['device_pk'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
          <button class="btn btn-sm btn-ghost btn-block" style="margin-top:4px">💻 Move to device</button></form>
      <?php endif; ?>
    </div>
  </div>
  <details style="margin-top:10px"><summary class="muted small">History</summary><ul class="timeline">
    <?php foreach (rows('SELECT * FROM license_events WHERE license_id = ? ORDER BY id DESC LIMIT 20', [$l['id']]) as $ev): ?>
      <li><b><?= e(ucfirst($ev['kind'])) ?></b> <?= e($ev['detail']) ?> <span class="muted small">· <?= local_dt($ev['created_at']) ?></span></li>
    <?php endforeach; ?></ul></details>
</div>
<?php endforeach; ?>
<?php page_end();
