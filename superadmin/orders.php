<?php
require __DIR__ . '/_init.php';
$admin = require_admin('payments');

// Serve a UPI payment screenshot to admins.
if (isset($_GET['proof'])) {
    $f = basename((string)$_GET['proof']);
    $path = HM_DATA . '/uploads/proofs/' . $f;
    if (!preg_match('/^[A-Z0-9]+_[A-Z0-9]+\.(png|jpg|webp|pdf)$/', $f) || !is_file($path)) { http_response_code(404); exit('Not found'); }
    end_buffers();
    header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($path));
    header('Content-Disposition: inline; filename="' . $f . '"');
    readfile($path);
    exit;
}

if (admin_post('payments')) {
    $o = row('SELECT * FROM orders WHERE id = ?', [input_int('order')]);
    $act = input('action');
    if (!$o) { flash('Order not found.', 'danger'); redirect($_SERVER['REQUEST_URI']); }
    $u = user_by_id((int)$o['user_id']);
    if ($act === 'approve' && in_array($o['status'], ['pending_review', 'created', 'failed', 'rejected'], true)) {
        update('orders', ['reviewed_by' => $admin['id'], 'reviewed_at' => now(), 'admin_note' => input('note')], 'id = ?', [$o['id']]);
        fulfill_order((int)$o['id'], 'admin:' . $admin['id'], $o['method'] ?: 'upi');
        flash("Approved {$o['order_no']} — key issued and emailed.", 'success');
    } elseif ($act === 'reject' && in_array($o['status'], ['pending_review', 'created'], true)) {
        $reason = input('note') ?: 'We could not find this payment.';
        update('orders', ['status' => 'rejected', 'reviewed_by' => $admin['id'], 'reviewed_at' => now(), 'admin_note' => $reason], 'id = ?', [$o['id']]);
        send_mail($u['email'], 'payment_rejected', ['name' => $u['name'] ?: 'there', 'order_no' => $o['order_no'], 'reason' => $reason, 'link' => site_url('/support.php')], (int)$u['id']);
        notify((int)$u['id'], 'Payment not verified', "Order {$o['order_no']}: $reason", 'danger', 'orders.php');
        flash("Rejected {$o['order_no']}.", 'success');
    } elseif ($act === 'refund' && $o['status'] === 'paid') {
        try {
            if ($o['method'] === 'razorpay' && $o['gateway_payment_id'] && isset($_POST['gateway'])) razorpay_refund($o['gateway_payment_id']);
            update('orders', ['status' => 'refunded', 'admin_note' => input('note') ?: 'Refunded'], 'id = ?', [$o['id']]);
            if (isset($_POST['revoke']) && $o['license_id']) {
                update('licenses', ['status' => 'revoked', 'revoked_reason' => 'Refunded'], 'id = ?', [$o['license_id']]);
                license_event((int)$o['license_id'], 'revoked', 'Order refunded', 'admin:' . $admin['id']);
            }
            flash("Order {$o['order_no']} marked refunded.", 'success');
        } catch (Throwable $e) { flash('Refund failed: ' . $e->getMessage(), 'danger'); }
    } elseif ($act === 'resend_key' && $o['status'] === 'paid') {
        $lic = $o['license_id'] ? row('SELECT * FROM licenses WHERE id = ?', [$o['license_id']]) : null;
        if ($lic) send_key_email($u, $lic, $o['plan_name']);
        send_mail($u['email'], 'payment_received', ['name' => $u['name'] ?: 'there', 'amount' => money($o['amount_paise']), 'plan' => $o['plan_name'],
            'days' => $o['plan_days'], 'order_no' => $o['order_no'], 'link' => site_url('/invoice.php?no=' . $o['order_no'])], (int)$u['id']);
        flash("Key and receipt re-sent to {$u['email']}.", 'success');
    } elseif ($act === 'note') {
        update('orders', ['admin_note' => input('note')], 'id = ?', [$o['id']]);
        flash('Note saved.', 'success');
    } elseif ($act === 'cancel' && in_array($o['status'], ['created', 'pending_review', 'failed'], true)) {
        update('orders', ['status' => 'cancelled'], 'id = ?', [$o['id']]);
        flash('Order cancelled.', 'success');
    }
    audit('order_' . $act, 'order', $o['order_no'], input('note'));
    redirect($_SERVER['REQUEST_URI']);
}

$where = ['1=1']; $p = [];
if (($s = input('q')) !== '') { $where[] = '(o.order_no LIKE ? OR o.invoice_no LIKE ? OR o.utr LIKE ? OR o.gateway_payment_id LIKE ? OR u.email LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%", "%$s%", "%$s%"); }
if (($v = input('status')) !== '') { $where[] = 'o.status = ?'; $p[] = $v; }
if (($v = input('method')) !== '') { $where[] = 'o.method = ?'; $p[] = $v; }
if (($v = input('coupon')) !== '') { $where[] = 'o.coupon_code = ?'; $p[] = strtoupper($v); }
if (($v = input('from')) !== '') { $where[] = 'o.created_at >= ?'; $p[] = "$v 00:00:00"; }
if (($v = input('to')) !== '') { $where[] = 'o.created_at <= ?'; $p[] = "$v 23:59:59"; }
$w = implode(' AND ', $where);
$base = "FROM orders o JOIN users u ON u.id = o.user_id WHERE $w";
if (input('export') === 'csv') {
    audit('export_orders', 'order', '', $w);
    $gen = (function () use ($base, $p) { foreach (q("SELECT o.*, u.email $base ORDER BY o.id DESC", $p) as $r)
        yield [$r['order_no'], $r['invoice_no'], $r['email'], $r['plan_name'], $r['plan_days'], $r['base_paise'] / 100, $r['discount_paise'] / 100, $r['tax_paise'] / 100, $r['amount_paise'] / 100,
            $r['coupon_code'], $r['method'], $r['status'], $r['utr'], $r['gateway_payment_id'], $r['created_at'], $r['paid_at']]; })();
    csv_download('orders-' . gmdate('Ymd') . '.csv', ['Order', 'Invoice', 'Email', 'Plan', 'Days', 'Price', 'Discount', 'GST', 'Total', 'Coupon', 'Method', 'Status', 'UTR', 'Payment ID', 'Created', 'Paid'], $gen);
}
$total = (int)val("SELECT COUNT(*) $base", $p);
$sum = (int)val("SELECT COALESCE(SUM(o.amount_paise),0) $base AND o.status = 'paid'", $p);
[$lim, $off, $page, $pages] = paginate($total, 25);
$list = rows("SELECT o.*, u.email, u.name $base ORDER BY (o.status = 'pending_review') DESC, o.id DESC LIMIT $lim OFFSET $off", $p);
admin_start('Orders & payments');
?>
<div class="grid grid-4">
  <?= stat_card('Pending UPI', (int)val("SELECT COUNT(*) FROM orders WHERE status = 'pending_review'"), 'need approval', 'orders.php?status=pending_review') ?>
  <?= stat_card('Paid (filtered)', money($sum), number_format($total) . ' orders shown') ?>
  <?= stat_card('Refunded', (int)val("SELECT COUNT(*) FROM orders WHERE status = 'refunded'"), money((int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE status = 'refunded'")), 'orders.php?status=refunded') ?>
  <?= stat_card('Abandoned checkouts (7d)', (int)val("SELECT COUNT(*) FROM orders WHERE status IN ('created','cancelled') AND created_at >= ?", [ts_plus('-7 days')]), 'started but not paid', 'orders.php?status=cancelled') ?>
</div><br>
<form class="filters"><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Order, invoice, UTR, payment ID, email">
  <?= filter_select('status', ['pending_review' => 'Pending UPI', 'paid' => 'Paid', 'created' => 'Started', 'failed' => 'Failed', 'rejected' => 'Rejected', 'refunded' => 'Refunded', 'cancelled' => 'Cancelled'], 'Any status') ?>
  <?= filter_select('method', ['razorpay' => 'Razorpay', 'upi' => 'UPI', 'admin' => 'Admin', 'free' => 'Free (coupon)', 'cash' => 'Cash/bank'], 'Any method') ?>
  <input name="coupon" value="<?= e(input('coupon')) ?>" placeholder="Coupon" style="min-width:100px"><input type="date" name="from" value="<?= e(input('from')) ?>"><input type="date" name="to" value="<?= e(input('to')) ?>">
  <button class="btn btn-sm">Filter</button><a class="btn btn-sm btn-ghost" href="orders.php">Reset</a><a class="btn btn-sm btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">⬇ CSV</a></form>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Order</th><th>User</th><th>Plan</th><th>Amount</th><th>Payment</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($list as $o): ?>
<tr><td><code><?= e($o['order_no']) ?></code><div class="small muted"><?= e($o['invoice_no']) ?></div></td><td><a href="user.php?id=<?= (int)$o['user_id'] ?>&tab=orders"><?= e($o['email']) ?></a></td>
  <td><?= e($o['plan_name']) ?> · <?= (int)$o['plan_days'] ?>d<?= $o['license_id'] && (int)val('SELECT COUNT(*) FROM orders WHERE license_id = ? AND id < ?', [$o['license_id'], $o['id']]) ? '<div class="small muted">renewal</div>' : '' ?></td>
  <td><b><?= money($o['amount_paise']) ?></b><?= $o['discount_paise'] ? '<div class="small muted">−' . money($o['discount_paise']) . ' ' . e($o['coupon_code']) . '</div>' : '' ?></td>
  <td class="small"><?= e(strtoupper($o['method'] ?: '—')) ?><?= $o['utr'] ? '<div>UTR <b class="mono">' . e($o['utr']) . '</b> <button type="button" class="icon-btn" style="width:24px;height:24px" data-copy="' . e($o['utr']) . '">📋</button></div>' : '' ?>
    <?= $o['gateway_payment_id'] ? '<div class="muted">' . e($o['gateway_payment_id']) . '</div>' : '' ?><?= $o['proof_file'] ? '<div><a target="_blank" href="orders.php?proof=' . e($o['proof_file']) . '">🖼 Screenshot</a></div>' : '' ?>
    <?= $o['payer_note'] ? '<div class="muted">“' . e($o['payer_note']) . '”</div>' : '' ?></td>
  <td><?= badge($o['status']) ?><?= $o['admin_note'] ? '<div class="small muted">' . e($o['admin_note']) . '</div>' : '' ?></td>
  <td class="small"><?= local_dt($o['created_at']) ?><?= $o['paid_at'] ? '<div class="muted">paid ' . time_ago($o['paid_at']) . '</div>' : '' ?></td>
  <td>
    <details class="menu" style="margin-bottom:6px"><summary class="kebab" title="More actions">⋯</summary><div class="menu-list">
      <?php if ($o['invoice_no']): ?>
        <a href="document.php?type=invoice&id=<?= (int)$o['id'] ?>" target="_blank">🧾 View invoice</a>
        <a href="document.php?type=invoice&id=<?= (int)$o['id'] ?>&print=1" target="_blank">🖨 Print invoice</a>
        <form method="post" action="document.php?type=invoice&id=<?= (int)$o['id'] ?>"><?= csrf_field() ?><button name="action" value="email">✉️ Email invoice to customer</button></form>
        <a href="<?= e(invoice_check_url($o)) ?>" target="_blank">✔ Public verify page</a>
      <?php endif; ?>
      <?php if ($o['license_id']): ?><a href="document.php?type=certificate&id=<?= (int)$o['license_id'] ?>" target="_blank">🎓 License certificate</a><?php endif; ?>
      <a href="user.php?id=<?= (int)$o['user_id'] ?>&tab=orders">👤 Open customer</a>
      <a href="document.php?type=statement&id=<?= (int)$o['user_id'] ?>" target="_blank">📄 Customer statement</a>
      <button type="button" data-copy="<?= e($o['order_no']) ?>">📋 Copy order number</button>
      <?php if ($o['status'] === 'paid'): ?><hr><form method="post"><?= csrf_field() ?><input type="hidden" name="order" value="<?= (int)$o['id'] ?>"><button name="action" value="resend_key">🔑 Re-send key + receipt</button></form><?php endif; ?>
    </div></details>
    <?php if (in_array($o['status'], ['pending_review', 'created', 'failed', 'rejected'], true)): ?>
      <form method="post" class="input-group" style="margin-bottom:4px"><?= csrf_field() ?><input type="hidden" name="order" value="<?= (int)$o['id'] ?>">
        <input name="note" placeholder="Note / reason" style="padding:5px 8px;min-width:110px">
        <button class="btn btn-sm btn-green" name="action" value="approve" data-confirm="Approve <?= e($o['order_no']) ?> for <?= e(money($o['amount_paise'])) ?>? Check the UTR in your bank/UPI app first.">✓ Approve</button>
        <?php if ($o['status'] !== 'rejected'): ?><button class="btn btn-sm btn-danger" name="action" value="reject">✕ Reject</button><?php endif; ?></form>
    <?php elseif ($o['status'] === 'paid'): ?>
      <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="order" value="<?= (int)$o['id'] ?>"><input name="note" placeholder="Refund reason" style="padding:5px 8px;width:120px">
        <?php if ($o['method'] === 'razorpay'): ?><label class="check small"><input type="checkbox" name="gateway" checked> via Razorpay</label><?php endif; ?>
        <label class="check small"><input type="checkbox" name="revoke" checked> revoke key</label>
        <button class="btn btn-sm btn-ghost" name="action" value="refund" data-confirm="Mark this order refunded?">↩ Refund</button></form>
    <?php endif; ?>
  </td></tr>
<?php endforeach; ?><?php if (!$list): ?><tr><td colspan="8" class="empty">No orders match.</td></tr><?php endif; ?>
</tbody></table></div><?= pager($page, $pages, $total) ?></div>
<?php admin_end();
