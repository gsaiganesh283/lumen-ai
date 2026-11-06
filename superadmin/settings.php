<?php
require __DIR__ . '/_init.php';
$admin = require_admin('settings');
$groups = [];
foreach (SETTINGS as $k => $d) if ($d[2] !== '_internal') $groups[$d[2]][$k] = $d;
$tab = isset($groups[input('tab')]) ? input('tab') : 'General';
$superOnly = ['Security', 'Desktop app'];

if (admin_post('settings')) {
    if (in_array($tab, $superOnly, true) && $admin['role'] !== 'superadmin') { flash('Only superadmins can change these settings.', 'danger'); redirect("settings.php?tab=$tab"); }
    $vals = [];
    $changed = [];
    foreach ($groups[$tab] as $k => [$def, $type]) {
        if ($type === 'bool') $v = isset($_POST[$k]) ? '1' : '0';
        elseif ($type === 'secret') { $v = $_POST[$k] ?? ''; if ($v === '' && !isset($_POST["clear_$k"])) continue; }
        else $v = trim((string)($_POST[$k] ?? ''));
        if ($k === 'site_url' || $k === 'admin_url' || $k === 'download_url') $v = rtrim($v, '/');
        if ($k === 'admin_allowed_ips' && $v !== '' && !in_array(client_ip(), array_map('trim', explode(',', $v)), true)) {
            flash('Your own IP (' . client_ip() . ') must be in the allowlist, or you would lock yourself out.', 'danger');
            redirect("settings.php?tab=$tab");
        }
        if ((string)setting($k) !== (string)($type === 'bool' ? ($v === '1') : $v)) $changed[] = $k;
        $vals[$k] = $v;
    }
    settings_set($vals);
    audit('settings_' . strtolower(str_replace(' ', '_', $tab)), 'settings', $tab, implode(', ', $changed));
    flash('Saved ' . count($changed) . ' change(s).', 'success');
    redirect("settings.php?tab=" . urlencode($tab));
}
admin_start('Settings');
?>
<div class="tabs"><?php foreach (array_keys($groups) as $g): ?><a class="<?= $g === $tab ? 'active' : '' ?>" href="settings.php?tab=<?= e(urlencode($g)) ?>"><?= e($g) ?></a><?php endforeach; ?></div>
<?php if (in_array($tab, $superOnly, true) && $admin['role'] !== 'superadmin'): ?><div class="alert alert-warning">Read-only: only superadmins can change <?= e($tab) ?> settings.</div><?php endif; ?>
<form method="post" class="card" style="max-width:900px"><?= csrf_field() ?>
<?php foreach ($groups[$tab] as $k => [$def, $type, , $label, $help]): $cur = setting($k); ?>
  <?php if ($type === 'bool'): ?>
    <label class="check" style="margin-top:14px"><input type="checkbox" name="<?= e($k) ?>" <?= $cur ? 'checked' : '' ?>> <b><?= e($label) ?></b></label>
  <?php else: ?>
    <label for="<?= e($k) ?>"><?= e($label) ?></label>
    <?php if ($type === 'text'): ?><textarea id="<?= e($k) ?>" name="<?= e($k) ?>" style="min-height:70px"><?= e($cur) ?></textarea>
    <?php elseif ($type === 'secret'): ?><div class="input-group"><input id="<?= e($k) ?>" name="<?= e($k) ?>" type="password" autocomplete="new-password" placeholder="<?= $cur !== '' ? 'Saved: ' . e(mask_secret($cur)) . ' — leave blank to keep' : 'Not set' ?>">
      <?php if ($cur !== ''): ?><label class="check small"><input type="checkbox" name="clear_<?= e($k) ?>"> clear</label><?php endif; ?></div>
    <?php elseif ($k === 'license_starts_on'): ?><select id="<?= e($k) ?>" name="<?= e($k) ?>"><option value="activation" <?= $cur === 'activation' ? 'selected' : '' ?>>First activation in the app</option><option value="purchase" <?= $cur === 'purchase' ? 'selected' : '' ?>>Right after purchase</option></select>
    <?php else: ?><input id="<?= e($k) ?>" name="<?= e($k) ?>" type="<?= $type === 'int' ? 'number' : 'text' ?>" value="<?= e($cur) ?>"><?php endif; ?>
  <?php endif; ?>
  <?php if ($help): ?><div class="hint"><?= e($help) ?></div><?php endif; ?>
<?php endforeach; ?>
<br><button class="btn">Save <?= e($tab) ?> settings</button>
<?php if ($tab === 'Email'): ?> <a class="btn btn-ghost" href="emails.php?tab=tools">Send a test email →</a><?php endif; ?>
<?php if ($tab === 'Accounts'): ?><p class="hint">Google sign-in redirect URI to add in Google Cloud: <code><?= e(site_url('/google.php')) ?></code></p><?php endif; ?>
<?php if ($tab === 'Payments'): ?><p class="hint">Razorpay webhook URL: <code><?= e(site_url('/webhook_razorpay.php')) ?></code> (events: payment.captured, payment.failed)</p><?php endif; ?>
<?php if ($tab === 'Desktop app'): ?><p class="hint">License public key for the desktop app (already built into interview.py — only needed if you reinstall with new keys):</p><div class="code-block"><?= e(license_public_pem()) ?></div><?php endif; ?>
</form>
<?php admin_end();
