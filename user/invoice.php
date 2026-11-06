<?php
require __DIR__ . '/_init.php';
$u = require_login();
$o = row("SELECT * FROM orders WHERE order_no = ? AND user_id = ? AND status IN ('paid','refunded')", [input('no'), $u['id']]);
if (!$o) { flash('Invoice not found.', 'danger'); redirect('orders.php'); }

if (is_post() && input('action') === 'email') {
    if (rate_limit('invmail:' . $u['id'], 5, 3600)) {
        send_mail($u['email'], 'invoice_copy', ['name' => $u['name'] ?: 'there', 'invoice_no' => $o['invoice_no'], 'plan' => $o['plan_name'],
            'amount' => money($o['amount_paise']), 'date' => local_dt($o['paid_at'], false), 'code' => invoice_check_code($o['order_no']),
            'link' => site_url('/invoice.php?no=' . $o['order_no'])], (int)$u['id']);
        track('invoice_emailed', ['user_id' => (int)$u['id'], 'label' => "Emailed invoice {$o['invoice_no']}"]);
        flash("Invoice emailed to {$u['email']} ✓", 'success');
    } else flash('Please wait before sending more emails.', 'danger');
    redirect('orders.php');
}
track('invoice_view', ['user_id' => (int)$u['id'], 'label' => "Viewed invoice {$o['invoice_no']}"]);
$actions = '<form method="post">' . csrf_field() . '<input type="hidden" name="no" value="' . e($o['order_no']) . '"><button name="action" value="email">✉️ Email me a copy</button></form>'
    . '<a href="' . e(invoice_check_url($o)) . '" target="_blank">✔ Verify online</a>'
    . ($o['license_id'] ? '<a href="certificate.php?id=' . (int)$o['license_id'] . '">🎓 License certificate</a>' : '');
doc_render('Invoice ' . $o['invoice_no'], doc_invoice($o, $u), [
    'back' => 'orders.php', 'qr' => invoice_check_url($o), 'actions_html' => $actions,
    'stamp' => $o['status'] === 'refunded' ? 'REFUNDED' : 'PAID', 'stamp_color' => $o['status'] === 'refunded' ? '#d23a4a' : '#1fa971',
]);
