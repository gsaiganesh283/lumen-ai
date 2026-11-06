<?php
/** Public invoice verification (from the QR code on every invoice). Shows no personal data. */
require __DIR__ . '/_init.php';
$no = input('no');
$code = strtoupper(input('c'));
$o = null;
if ($no !== '' && $code !== '' && rate_limit('invcheck:' . client_ip(), 30, 600) && hash_equals(invoice_check_code($no), $code)) {
    $o = row("SELECT order_no, invoice_no, plan_name, plan_days, amount_paise, status, paid_at, user_id FROM orders WHERE order_no = ? AND invoice_no IS NOT NULL", [$no]);
}
$name = $o ? (string)val('SELECT name FROM users WHERE id = ?', [$o['user_id']]) : '';
$masked = $name !== '' ? mb_substr($name, 0, 1) . str_repeat('•', max(2, mb_strlen($name) - 2)) . mb_substr($name, -1) : '—';
auth_start('Verify invoice', 'Invoice verification');
?>
<div class="center">
  <div class="big-emoji"><?= $o ? ($o['status'] === 'paid' ? '✅' : '⚠️') : '❌' ?></div>
  <h1><?= $o ? ($o['status'] === 'paid' ? 'Genuine invoice' : 'Invoice found — ' . e($o['status'])) : 'Not verified' ?></h1>
  <?php if ($o): ?>
    <div class="card" style="text-align:left;margin-top:16px"><dl class="kv">
      <dt>Invoice</dt><dd><b><?= e($o['invoice_no']) ?></b></dd><dt>Order</dt><dd><?= e($o['order_no']) ?></dd>
      <dt>Issued by</dt><dd><?= e(setting('company_name') ?: setting('site_name')) ?></dd><dt>Billed to</dt><dd><?= e($masked) ?></dd>
      <dt>Plan</dt><dd><?= e($o['plan_name']) ?> · <?= (int)$o['plan_days'] ?> days</dd><dt>Amount</dt><dd><b><?= money($o['amount_paise']) ?></b></dd>
      <dt>Paid on</dt><dd><?= local_dt($o['paid_at'], false) ?></dd><dt>Status</dt><dd><?= badge($o['status']) ?></dd></dl></div>
  <?php else: ?>
    <p class="muted">This invoice number and verification code don't match our records. Check the QR code or contact <?= e(setting('support_email') ?: 'support') ?>.</p>
  <?php endif; ?>
  <a class="btn btn-ghost" href="index.php" style="margin-top:12px">← <?= e(setting('site_name')) ?> home</a>
</div>
<?php auth_end();
