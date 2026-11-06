<?php
require __DIR__ . '/_init.php';
require __DIR__ . '/_progress.php';
$u = require_login();
$st = onboarding_state((int)$u['id']);
$plan_cookie = $_COOKIE['hm_plan'] ?? '';
page_start('Get started', 'get-started.php');
?>
<?= onboarding_bar($st) ?>
<div class="card">
<?php switch ($st['current']):
  case 'plan': case 'pay': ?>
    <h2>Step 1–2 · Choose your plan</h2>
    <?php if ($st['pending_payment']): ?>
      <div class="alert alert-info">🧾 We're verifying your UPI payment. Your activation key will appear here and in your email once approved.</div>
      <a class="btn btn-ghost" href="orders.php">View payment status</a>
    <?php else: ?>
      <p class="muted">Pick how long you need <?= e(setting('site_name')) ?>. <?= setting('license_starts_on') === 'purchase' ? 'Days start right after payment.' : 'Days only start when you activate the key in the app.' ?></p>
      <div class="plans"><?php foreach (rows('SELECT * FROM plans WHERE active = 1 ORDER BY sort, days') as $p)
          echo plan_card($p, 'checkout.php?plan=' . urlencode($p['code']), $plan_cookie === $p['code'] ? 'Continue with this plan' : 'Choose plan'); ?></div>
    <?php endif; break;
  case 'download': ?>
    <h2>Step 3 · Download the desktop app</h2>
    <p>Your activation key is ready in <a href="licenses.php">Licenses</a>. Now install the app on the PC you'll use for interviews.</p>
    <a class="btn" href="download.php">⬇️ Go to download</a>
    <?php break;
  case 'device': ?>
    <h2>Step 4 · Add your Device ID</h2>
    <ol><li>Open <?= e(setting('site_name')) ?> on your PC.</li><li>Click the 🔑 license badge at the top left.</li>
      <li>Copy the <b>Device ID</b> (it looks like <code>HMD-AB12-CD34-EF56-GH78</code>).</li><li>Paste it on the Devices page.</li></ol>
    <a class="btn" href="devices.php">💻 Add device</a>
    <?php break;
  case 'activate': ?>
    <h2>Step 5 · Activate in the app</h2>
    <ol><li>Copy your key from <a href="licenses.php">Licenses</a>.</li><li>In the app, click the 🔑 badge at the top left, paste the key and click <b>Activate</b>.</li>
      <li>The badge then shows how many days are left.</li></ol>
    <a class="btn" href="licenses.php">🔑 Show my key</a>
    <?php break;
  default: ?>
    <div class="center"><div class="big-emoji">🎉</div><h2>You're all set!</h2><p class="muted">Your app is activated. We'll remind you before your plan ends.</p>
      <a class="btn" href="dashboard.php">Go to dashboard</a></div>
<?php endswitch; ?>
</div>
<?php page_end();
