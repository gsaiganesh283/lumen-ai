<?php
require __DIR__ . '/_init.php';
$u = require_login();
$errors = [];

if (is_post()) {
    block_if_impersonating();
    $action = input('action');
    if ($action === 'add') {
        $code = normalize_code(input('device_id'));
        $name = mb_substr(input('name') ?: 'My PC', 0, 80);
        $count = (int)val("SELECT COUNT(*) FROM devices WHERE user_id = ? AND status <> 'removed'", [$u['id']]);
        $existing = row('SELECT * FROM devices WHERE device_id = ?', [$code]);
        if (!valid_device_id($code)) $errors[] = 'That doesn\'t look like a Device ID. It should look like HMD-AB12-CD34-EF56-GH78 (copy it from the desktop app).';
        elseif ($existing && (int)$existing['user_id'] !== (int)$u['id']) $errors[] = 'This Device ID is registered to another account. Contact support if this is your PC.';
        elseif ($existing && $existing['status'] === 'blocked') $errors[] = 'This device is blocked. Contact support.';
        elseif (!$existing && $count >= setting('max_devices_per_user')) $errors[] = 'You can have up to ' . setting('max_devices_per_user') . ' devices. Remove an old one first.';
        if (!$errors) {
            if ($existing) {
                update('devices', ['status' => 'active', 'name' => $name], 'id = ?', [$existing['id']]);
                $dev_id = (int)$existing['id'];
            } else {
                $dev_id = insert('devices', ['device_id' => $code, 'user_id' => $u['id'], 'name' => $name, 'created_at' => now()]);
            }
            track('device_added', ['user_id' => (int)$u['id'], 'device_id' => $code, 'label' => "Added device $name ($code)"]);
            // Link an unused license to it if the user picked one.
            $lic = input_int('license') ? row("SELECT * FROM licenses WHERE id = ? AND user_id = ? AND device_pk IS NULL AND status IN ('issued','active')",
                [input_int('license'), $u['id']]) : null;
            if ($lic) {
                update('licenses', ['device_pk' => $dev_id], 'id = ?', [$lic['id']]);
                license_event((int)$lic['id'], 'assigned', "→ $code", 'user');
            }
            flash('Device added ✓' . ($lic ? " Key {$lic['license_key']} is linked to it — activate it in the app." : ''), 'success');
            redirect('devices.php');
        }
    } elseif ($action === 'rename') {
        update('devices', ['name' => mb_substr(input('name') ?: 'My PC', 0, 80)], 'id = ? AND user_id = ?', [input_int('id'), $u['id']]);
        flash('Device renamed.', 'success');
        redirect('devices.php');
    } elseif ($action === 'remove') {
        $d = row('SELECT * FROM devices WHERE id = ? AND user_id = ?', [input_int('id'), $u['id']]);
        if ($d) {
            if (val("SELECT 1 FROM licenses WHERE device_pk = ? AND status = 'active' AND expires_at > ?", [$d['id'], now()])) {
                flash('This device has an active license. Move the license to another device first (Licenses page).', 'danger');
            } else {
                q("UPDATE licenses SET device_pk = NULL WHERE device_pk = ? AND status = 'issued'", [$d['id']]);
                update('devices', ['status' => 'removed'], 'id = ?', [$d['id']]);
                track('device_removed', ['user_id' => (int)$u['id'], 'device_id' => $d['device_id'], 'label' => "Removed device {$d['name']}"]);
                flash('Device removed.', 'success');
            }
        }
        redirect('devices.php');
    }
}

$devices = rows("SELECT d.*, (SELECT license_key FROM licenses l WHERE l.device_pk = d.id ORDER BY FIELD(l.status,'active','issued','suspended','expired','revoked') LIMIT 1) AS lic_key
    FROM devices d WHERE d.user_id = ? AND d.status <> 'removed' ORDER BY d.id DESC", [$u['id']]);
$free_lics = rows("SELECT id, license_key, days FROM licenses WHERE user_id = ? AND device_pk IS NULL AND status IN ('issued','active')", [$u['id']]);
$online_after = ts_plus('-' . (setting('heartbeat_minutes') * 2 + 1) . ' minutes');
page_start('Devices', 'devices.php');
?>
<div class="grid grid-2">
<div class="card">
  <h2>➕ Add a device</h2>
  <p class="muted small">Open the desktop app and click the 🔑 badge at the top left. Copy the <b>Device ID</b> shown there.</p>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label for="device_id">Device ID</label>
    <input id="device_id" name="device_id" required placeholder="HMD-XXXX-XXXX-XXXX-XXXX" value="<?= e(input('device_id')) ?>" class="mono" style="text-transform:uppercase" maxlength="40">
    <label for="name">Device name</label>
    <input id="name" name="name" placeholder="e.g. Office laptop" value="<?= e(input('name')) ?>" maxlength="80">
    <?php if ($free_lics): ?><label for="license">Link a license</label>
      <select id="license" name="license"><?php foreach ($free_lics as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['license_key']) ?> (<?= (int)$l['days'] ?> days)</option><?php endforeach; ?><option value="0">Don't link now</option></select><?php endif; ?>
    <br><br><button class="btn">Add device</button>
  </form>
</div>
<div class="card"><h2>ℹ️ About devices</h2><ul class="muted small">
  <li>Each license works on one device at a time.</li>
  <li>The Device ID is random and created once when the app is installed.</li>
  <li>You can have up to <?= (int)setting('max_devices_per_user') ?> devices and move a license <?= (int)setting('device_changes_per_month') ?> times per 30 days.</li>
  <li>"Online" means the app checked in within the last <?= setting('heartbeat_minutes') * 2 ?> minutes.</li></ul></div>
</div>
<div class="card"><h2>💻 Your devices</h2>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Device ID</th><th>License</th><th>App</th><th>Last seen</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$devices): ?><tr><td colspan="7" class="empty">No devices yet.</td></tr><?php endif; ?>
<?php foreach ($devices as $d): $on = $d['last_seen_at'] && $d['last_seen_at'] > $online_after; ?>
  <tr><td><form method="post" class="input-group"><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
      <input name="name" value="<?= e($d['name']) ?>" style="padding:5px 8px;min-width:120px"><button class="btn btn-sm btn-ghost" title="Rename">✓</button></form></td>
    <td><code><?= e($d['device_id']) ?></code></td>
    <td><?= $d['lic_key'] ? '<code class="small">' . e($d['lic_key']) . '</code>' : '<span class="muted">—</span>' ?></td>
    <td><?= e($d['app_version'] ?: '—') ?><div class="small muted"><?= e($d['hostname']) ?></div></td>
    <td><?= $on ? '<span class="badge badge-green">● Online</span>' : time_ago($d['last_seen_at']) ?></td>
    <td><?= badge($d['status']) ?></td>
    <td><form method="post" data-confirm="Remove this device?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-ghost">Remove</button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php page_end();
