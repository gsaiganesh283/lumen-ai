<?php
/** Razorpay webhook (payment.captured / order.paid): activates orders even if the
 *  user closed the browser before returning. Set it up in the Razorpay dashboard. */
require __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => true, 'info' => 'Razorpay webhook endpoint. Razorpay sends signed POST requests here; opening it in a browser does nothing.',
        'configured' => setting('razorpay_webhook_secret') !== '', 'url' => site_url('/webhook_razorpay.php')]);
}
$body = file_get_contents('php://input');
if (!razorpay_verify_webhook($body, $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '')) json_out(['ok' => false], 400);
$ev = json_decode($body, true) ?: [];
$pay = $ev['payload']['payment']['entity'] ?? [];
$rzp_order = $pay['order_id'] ?? ($ev['payload']['order']['entity']['id'] ?? '');
if (in_array($ev['event'] ?? '', ['payment.captured', 'order.paid'], true) && $rzp_order) {
    $o = row('SELECT * FROM orders WHERE gateway_order_id = ?', [$rzp_order]);
    if ($o && $o['status'] !== 'paid' && (int)($pay['amount'] ?? $o['amount_paise']) >= (int)$o['amount_paise']) {
        if (!empty($pay['id'])) update('orders', ['gateway_payment_id' => $pay['id']], 'id = ?', [$o['id']]);
        fulfill_order((int)$o['id'], 'razorpay-webhook', 'razorpay');
    }
}
if (($ev['event'] ?? '') === 'payment.failed' && $rzp_order) {
    q("UPDATE orders SET status = 'failed', admin_note = ? WHERE gateway_order_id = ? AND status = 'created'",
        [mb_substr($pay['error_description'] ?? 'Payment failed', 0, 300), $rzp_order]);
}
json_out(['ok' => true]);
