<?php
require __DIR__ . '/_init.php';
page_start('Terms of Service', '', true);
?>
<div class="card" style="max-width:820px;margin:20px auto">
  <h1>Terms of Service</h1>
  <p class="muted">Last updated <?= gmdate('d M Y') ?></p>
  <ol>
    <li><b>Licenses.</b> Each activation key works on one device at a time for the number of days in your plan. Plan days start <?= setting('license_starts_on') === 'purchase' ? 'at purchase' : 'when the key is first activated in the app' ?>.</li>
    <li><b>Device changes.</b> You can move a license to another of your devices up to <?= (int)setting('device_changes_per_month') ?> times per month from the portal.</li>
    <li><b>Payments.</b> Prices include the plan only<?= setting('gst_percent') ? ' (GST is added at checkout)' : '' ?>. Plans don't auto-renew. Renewing adds days to your current plan.</li>
    <li><b>Refunds.</b> Contact support within 48 hours of purchase if the app does not work on your PC and the key was not activated.</li>
    <li><b>Fair use.</b> Sharing, reselling or tampering with keys or the app can lead to the license and account being suspended without refund.</li>
    <li><b>Your responsibility.</b> You are responsible for following the rules of any interview, exam or platform where you use the app.</li>
    <li><b>Availability.</b> AI answers can be wrong; always use your own judgement. The service may be paused for maintenance.</li>
  </ol>
</div>
<?php page_end(true);
