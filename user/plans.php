<?php
require __DIR__ . '/_init.php';
$u = require_login();
$renew = input_int('renew');
$lic = $renew ? row('SELECT l.*, d.name AS device_name FROM licenses l LEFT JOIN devices d ON d.id = l.device_pk WHERE l.id = ? AND l.user_id = ?', [$renew, $u['id']]) : null;
$plans = rows('SELECT * FROM plans WHERE active = 1 ORDER BY sort, days');
page_start($lic ? 'Renew plan' : 'Plans', 'plans.php');
?>
<?php if ($lic): ?>
  <div class="alert alert-info">🔁 Renewing <b><?= e($lic['license_key']) ?></b><?= $lic['device_name'] ? ' on ' . e($lic['device_name']) : '' ?>.
    <?= $lic['expires_at'] && effective_status($lic) === 'active' ? 'New days are added after ' . local_dt($lic['expires_at'], false) . '.' : 'Days start again from today.' ?></div>
<?php else: ?>
  <p class="muted">One-time payment, no auto-renew. Already have a plan? <a href="licenses.php">Renew it from Licenses</a> to add days to the same key.</p>
<?php endif; ?>
<div class="plans">
  <?php foreach ($plans as $p) echo plan_card($p, 'checkout.php?plan=' . urlencode($p['code']) . ($lic ? '&renew=' . (int)$lic['id'] : ''), $lic ? 'Add ' . $p['days'] . ' days' : 'Choose plan'); ?>
</div>
<?php page_end();
