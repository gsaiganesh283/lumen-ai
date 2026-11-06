<?php
require __DIR__ . '/_init.php';
$u = require_login();
$o = row('SELECT * FROM orders WHERE order_no = ? AND user_id = ?', [input('order'), $u['id']]);
if (!$o) { flash('Order not found.', 'danger'); redirect('orders.php'); }
if ($o['status'] === 'paid') redirect('licenses.php');
$error = '';

if (is_post() && in_array($o['status'], ['created', 'failed', 'rejected'], true)) {
    block_if_impersonating();
    $utr = strtoupper(preg_replace('/\s+/', '', input('utr')));
    if (!preg_match('/^[A-Z0-9]{10,22}$/', $utr)) {
        $error = 'Enter the 12-digit UPI transaction ID / UTR from your UPI app (letters and numbers only).';
    } elseif (val("SELECT 1 FROM orders WHERE utr = ? AND id <> ? AND status IN ('pending_review','paid')", [$utr, $o['id']])) {
        $error = 'This transaction ID was already submitted for another order.';
    } else {
        $file = '';
        if (!empty($_FILES['proof']['tmp_name']) && is_uploaded_file($_FILES['proof']['tmp_name'])) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['proof']['tmp_name']);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
            if (!$ext || $_FILES['proof']['size'] > 4 * 1024 * 1024) {
                $error = 'The screenshot must be a PNG, JPG, WEBP or PDF under 4 MB.';
            } else {
                $dir = HM_DATA . '/uploads/proofs';
                if (!is_dir($dir)) mkdir($dir, 0700, true);
                $file = $o['order_no'] . '_' . random_code(6) . '.' . $ext;
                move_uploaded_file($_FILES['proof']['tmp_name'], "$dir/$file");
            }
        }
        if (!$error) {
            update('orders', ['status' => 'pending_review', 'utr' => $utr, 'proof_file' => $file, 'method' => 'upi',
                'payer_note' => mb_substr(input('note'), 0, 300)], 'id = ?', [$o['id']]);
            track('upi_submitted', ['user_id' => (int)$u['id'], 'label' => "UPI submitted {$o['order_no']} UTR $utr", 'meta' => ['order' => $o['order_no']]]);
            send_mail($u['email'], 'payment_pending', ['name' => $u['name'] ?: 'there', 'utr' => $utr, 'amount' => money($o['amount_paise']),
                'order_no' => $o['order_no']], (int)$u['id']);
            notify((int)$u['id'], 'UPI payment submitted', "We're verifying UTR $utr for order {$o['order_no']}.", 'info', 'orders.php');
            flash('Thanks! We\'ll verify your payment and email your key shortly.', 'success');
            redirect('orders.php');
        }
    }
}
$link = upi_link($o);
page_start('Pay by UPI', 'plans.php');
?>
<div class="grid grid-2">
  <div class="card center">
    <h2>1. Scan & pay <?= money($o['amount_paise']) ?></h2>
    <div class="qr" id="qr"></div>
    <p class="muted small">Order <b><?= e($o['order_no']) ?></b> · Pay exactly <?= money($o['amount_paise']) ?></p>
    <p>UPI ID: <b class="mono"><?= e(setting('upi_id')) ?></b> <button class="icon-btn" data-copy="<?= e(setting('upi_id')) ?>">📋</button></p>
    <a class="btn btn-ghost mobile-only" href="<?= e($link) ?>">Open UPI app</a>
  </div>
  <div class="card">
    <h2>2. Submit transaction ID</h2>
    <?php if ($o['status'] === 'pending_review'): ?>
      <div class="alert alert-info">Submitted ✓ — UTR <b><?= e($o['utr']) ?></b> is being verified.</div>
    <?php else: ?>
      <?php if ($o['status'] === 'rejected'): ?><div class="alert alert-danger">Previous submission was rejected: <?= e($o['admin_note']) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="order" value="<?= e($o['order_no']) ?>">
        <label for="utr">UPI transaction ID / UTR</label>
        <input id="utr" name="utr" required maxlength="22" placeholder="e.g. 412345678901" value="<?= e(input('utr')) ?>">
        <div class="hint">Find it in your UPI app under the payment details (12 digits).</div>
        <label for="proof">Payment screenshot <span class="muted">(optional, speeds up approval)</span></label>
        <input id="proof" name="proof" type="file" accept="image/png,image/jpeg,image/webp,application/pdf">
        <label for="note">Note <span class="muted">(optional)</span></label>
        <input id="note" name="note" maxlength="300">
        <br><br><button class="btn btn-block">Submit for verification</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>new QRCode(document.getElementById('qr'), { text: <?= json_encode($link) ?>, width: 220, height: 220 });</script>
<?php page_end();
