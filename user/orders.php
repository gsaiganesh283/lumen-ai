<?php
require __DIR__ . '/_init.php';
$u = require_login();
if (is_post() && input('action') === 'cancel') {
    q("UPDATE orders SET status = 'cancelled' WHERE order_no = ? AND user_id = ? AND status = 'created'", [input('order'), $u['id']]);
    flash('Order cancelled.', 'info');
    redirect('orders.php');
}
$total = (int)val('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$u['id']]);
[$lim, $off, $page, $pages] = paginate($total, 15);
$orders = rows("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT $lim OFFSET $off", [$u['id']]);
$paid_total = (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE user_id = ? AND status = 'paid'", [$u['id']]);
page_start('Payments', 'orders.php');
?>
<div class="grid grid-3">
  <div class="stat"><div class="label">Total paid</div><div class="value"><?= money($paid_total) ?></div><div class="sub">all time</div></div>
  <div class="stat"><div class="label">Payments</div><div class="value"><?= (int)val("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'paid'", [$u['id']]) ?></div><div class="sub">successful</div></div>
  <a class="stat" href="statement.php"><div class="label">Account statement</div><div class="value">🖨</div><div class="sub">Print or save as PDF →</div></a>
</div><br>
<div class="card">
<div class="table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Plan</th><th>Amount</th><th>Method</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$orders): ?><tr><td colspan="7" class="empty">No payments yet. <a href="plans.php">Choose a plan</a></td></tr><?php endif; ?>
<?php foreach ($orders as $o): ?>
<tr><td><code><?= e($o['order_no']) ?></code></td><td><?= local_dt($o['created_at']) ?></td>
  <td><?= e($o['plan_name']) ?> · <?= (int)$o['plan_days'] ?>d<?= $o['coupon_code'] ? '<div class="small muted">Coupon ' . e($o['coupon_code']) . '</div>' : '' ?></td>
  <td><?= money($o['amount_paise']) ?></td><td><?= e(strtoupper($o['method'] ?: '—')) ?><?= $o['utr'] ? '<div class="small muted">UTR ' . e($o['utr']) . '</div>' : '' ?></td>
  <td><?= badge($o['status']) ?><?= $o['status'] === 'rejected' && $o['admin_note'] ? '<div class="small muted">' . e($o['admin_note']) . '</div>' : '' ?></td>
  <td><?php if (in_array($o['status'], ['paid', 'refunded'], true)): ?>
      <details class="menu"><summary class="btn btn-sm btn-ghost">🧾 Invoice ▾</summary><div class="menu-list">
        <a href="invoice.php?no=<?= e($o['order_no']) ?>">👁 View invoice</a>
        <a href="invoice.php?no=<?= e($o['order_no']) ?>&print=1" target="_blank">🖨 Print / save PDF</a>
        <form method="post" action="invoice.php"><?= csrf_field() ?><input type="hidden" name="no" value="<?= e($o['order_no']) ?>"><button name="action" value="email">✉️ Email me a copy</button></form>
        <a href="<?= e(invoice_check_url($o)) ?>" target="_blank">✔ Verify online</a>
        <?php if ($o['license_id']): ?><a href="certificate.php?id=<?= (int)$o['license_id'] ?>">🎓 License certificate</a><?php endif; ?>
        <button type="button" data-copy="<?= e($o['invoice_no']) ?>">📋 Copy invoice number</button></div></details>
    <?php elseif (in_array($o['status'], ['created', 'rejected'], true) && $o['method'] === 'upi'): ?><a class="btn btn-sm" href="pay_upi.php?order=<?= e($o['order_no']) ?>">Submit UTR</a>
    <?php endif; ?>
    <?php if ($o['status'] === 'created'): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="order" value="<?= e($o['order_no']) ?>"><button class="btn btn-sm btn-ghost" name="action" value="cancel">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?= pager($page, $pages, $total) ?>
</div>
<?php page_end();
