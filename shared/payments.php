<?php
/** Razorpay (automatic) and UPI (manual approval) payments. */

function razorpay_request(string $method, string $path, ?array $body = null): array {
    $id = setting('razorpay_key_id');
    $secret = setting('razorpay_key_secret');
    if ($id === '' || $secret === '') throw new RuntimeException('Razorpay keys are not set (Superadmin → Settings → Payments).');
    $ch = curl_init('https://api.razorpay.com/v1' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => "$id:$secret", CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERAGENT => 'InterviewLens/1.0']);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if ($resp === false) throw new RuntimeException("Can't reach Razorpay: $err");
    $data = json_decode($resp, true) ?: [];
    if ($code >= 400) throw new RuntimeException('Razorpay: ' . ($data['error']['description'] ?? "HTTP $code"));
    return $data;
}

function razorpay_create_order(array $order): array {
    return razorpay_request('POST', '/orders', ['amount' => (int)$order['amount_paise'], 'currency' => 'INR',
        'receipt' => $order['order_no'], 'notes' => ['order_no' => $order['order_no'], 'user_id' => (string)$order['user_id']]]);
}

function razorpay_refund(string $payment_id, ?int $amount = null): array {
    return razorpay_request('POST', '/payments/' . rawurlencode($payment_id) . '/refund', $amount ? ['amount' => $amount] : []);
}

function razorpay_verify_signature(string $rzp_order_id, string $payment_id, string $signature): bool {
    $secret = setting('razorpay_key_secret');
    if ($secret === '') return false;
    return hash_equals(hash_hmac('sha256', "$rzp_order_id|$payment_id", $secret), $signature);
}

function razorpay_verify_webhook(string $body, string $signature): bool {
    $secret = setting('razorpay_webhook_secret');
    return $secret !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $signature);
}

function upi_link(array $order): string {
    return 'upi://pay?pa=' . rawurlencode(setting('upi_id')) . '&pn=' . rawurlencode(setting('upi_payee_name') ?: setting('site_name'))
        . '&am=' . number_format($order['amount_paise'] / 100, 2, '.', '') . '&cu=INR&tn='
        . rawurlencode('InterviewLens ' . $order['order_no']) . '&tr=' . rawurlencode($order['order_no']);
}
