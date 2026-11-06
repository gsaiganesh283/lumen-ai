<?php
require __DIR__ . '/_init.php';
$u = require_login();

$plan = row('SELECT * FROM plans WHERE code = ? AND active = 1', [input('plan')]);
if (!$plan) { flash('Please choose a plan.', 'info'); redirect('plans.php'); }
$renew = input_int('renew');
$renew_lic = $renew ? row("SELECT * FROM licenses WHERE id = ? AND user_id = ? AND status <> 'revoked'", [$renew, $u['id']]) : null;
$devices = rows("SELECT * FROM devices WHERE user_id = ? AND status = 'active' ORDER BY id DESC", [$u['id']]);
[$coupon, $coupon_err] = check_coupon(input('coupon'), $plan, (int)$u['id']);
$price = price_for($plan, $coupon);
$action = input('action');

/** Common: make our order from the posted form. */
function make_order(array $u, array $plan, ?array $coupon, ?array $renew_lic): array {
    $dev = input_int('device');
    $dev_ok = $dev && val("SELECT 1 FROM devices WHERE id = ? AND user_id = ? AND status = 'active'", [$dev, $u['id']]);
    $o = create_order((int)$u['id'], $plan, $coupon, $renew_lic['id'] ?? null, $dev_ok ? $dev : null);
    track('order_created', ['user_id' => (int)$u['id'], 'label' => "Checkout {$o['order_no']} · {$plan['name']} · " . money($o['amount_paise']),
        'meta' => ['order' => $o['order_no'], 'coupon' => $o['coupon_code']]]);
    return $o;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) json_out(['ok' => false, 'error' => 'Session expired. Reload the page.'], 400);
    if (!rate_limit('checkout:' . $u['id'], 20, 600)) {
        if ($action === 'rzp_create') json_out(['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes.'], 429);
        flash('Too many attempts. Please wait a few minutes.', 'danger');
        redirect($_SERVER['REQUEST_URI']);
    }
    block_if_impersonating();

    if ($price['total'] === 0 && $action === 'free') {   // 100% coupon
        $o = make_order($u, $plan, $coupon, $renew_lic);
        fulfill_order((int)$o['id'], 'user', 'free');
        flash('Your plan is active — enjoy! 🎉', 'success');
        redirect('licenses.php');
    }

    if ($action === 'rzp_create') {
        if (!setting('razorpay_enabled')) json_out(['ok' => false, 'error' => 'Online payment is not available.'], 400);
        try {
            $o = make_order($u, $plan, $coupon, $renew_lic);
            $rz = razorpay_create_order($o);
            update('orders', ['gateway_order_id' => $rz['id'], 'method' => 'razorpay'], 'id = ?', [$o['id']]);
            json_out(['ok' => true, 'key' => setting('razorpay_key_id'), 'order_id' => $rz['id'], 'amount' => $o['amount_paise'],
                'name' => setting('site_name'), 'description' => $plan['name'] . ' · ' . $plan['days'] . ' days', 'order_no' => $o['order_no'],
                'prefill' => ['name' => $u['name'], 'email' => $u['email'], 'contact' => $u['phone']]]);
        } catch (Throwable $e) {
            error_log('razorpay create: ' . $e->getMessage());
            json_out(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    if ($action === 'rzp_verify') {
        $o = row("SELECT * FROM orders WHERE gateway_order_id = ? AND user_id = ?", [input('razorpay_order_id'), $u['id']]);
        if (!$o || !razorpay_verify_signature(input('razorpay_order_id'), input('razorpay_payment_id'), input('razorpay_signature'))) {
            if ($o) update('orders', ['status' => 'failed', 'admin_note' => 'Signature check failed'], 'id = ? AND status <> ?', [$o['id'], 'paid']);
            track('payment_failed', ['user_id' => (int)$u['id'], 'label' => 'Razorpay signature check failed']);
            flash('We could not confirm this payment. If money was deducted, contact support with your order number.', 'danger');
            redirect('orders.php');
        }
        update('orders', ['gateway_payment_id' => input('razorpay_payment_id')], 'id = ?', [$o['id']]);
        fulfill_order((int)$o['id'], 'razorpay', 'razorpay');
        flash('Payment successful! 🎉 Your activation key is ready.', 'success');
        redirect('licenses.php?new=1');
    }

    if ($action === 'upi') {
        if (!setting('upi_enabled') || setting('upi_id') === '') { flash('UPI payment is not available.', 'danger'); redirect($_SERVER['REQUEST_URI']); }
        $o = make_order($u, $plan, $coupon, $renew_lic);
        update('orders', ['method' => 'upi'], 'id = ?', [$o['id']]);
        redirect('pay_upi.php?order=' . urlencode($o['order_no']));
    }
}

$params = ['plan' => $plan['code'], 'renew' => $renew_lic['id'] ?? null, 'coupon' => $coupon['code'] ?? null];
page_start('Checkout', 'plans.php');
?>
<div class="grid grid-2">
  <div class="card">
    <h2>Order summary</h2>
    <dl class="kv">
      <dt>Plan</dt><dd><b><?= e($plan['name']) ?></b> · <?= (int)$plan['days'] ?> days</dd>
      <?php if ($renew_lic): ?><dt>Renewing</dt><dd><code><?= e($renew_lic['license_key']) ?></code></dd><?php endif; ?>
      <dt>Price</dt><dd><?= money($price['base']) ?></dd>
      <?php if ($price['discount']): ?><dt>Coupon <?= e($coupon['code']) ?></dt><dd style="color:var(--green)">− <?= money($price['discount']) ?></dd><?php endif; ?>
      <?php if ($price['tax']): ?><dt>GST (<?= (int)setting('gst_percent') ?>%)</dt><dd><?= money($price['tax']) ?></dd><?php endif; ?>
      <dt><b>Total</b></dt><dd style="font-size:24px;font-weight:800"><?= money($price['total']) ?></dd>
    </dl>
    <form method="get" style="margin-top:16px">
      <input type="hidden" name="plan" value="<?= e($plan['code']) ?>"><?php if ($renew_lic): ?><input type="hidden" name="renew" value="<?= (int)$renew_lic['id'] ?>"><?php endif; ?>
      <label for="coupon">Coupon code</label>
      <div class="input-group"><input id="coupon" name="coupon" value="<?= e(input('coupon')) ?>" placeholder="e.g. WELCOME10"><button class="btn btn-ghost">Apply</button></div>
      <?php if ($coupon_err): ?><div class="hint" style="color:var(--red)"><?= e($coupon_err) ?></div><?php elseif ($coupon): ?><div class="hint" style="color:var(--green)">✓ Coupon applied</div><?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2>Pay</h2>
    <?php if (!$renew_lic && $devices): ?>
      <label for="device">Use the key on</label>
      <select id="device" form="pay-upi" name="device" onchange="document.querySelectorAll('[name=device]').forEach(s=>s.value=this.value)">
        <option value="">Choose later</option>
        <?php foreach ($devices as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['device_id']) ?>)</option><?php endforeach; ?>
      </select><br><br>
    <?php endif; ?>

    <?php if ($price['total'] === 0): ?>
      <form method="post" action="checkout.php<?= e(qs([])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="free">
        <input type="hidden" name="device" value=""><button class="btn btn-block">Activate for free 🎉</button></form>
    <?php else: ?>
      <?php if (setting('razorpay_enabled') && setting('razorpay_key_id')): ?>
        <button class="btn btn-block" id="rzp-btn">💳 Pay <?= money($price['total']) ?> — UPI / Card / Netbanking</button>
        <p class="hint center">Instant activation · Secured by Razorpay</p>
        <form id="rzp-done" method="post" action="checkout.php<?= e(qs([])) ?>" class="hidden"><?= csrf_field() ?>
          <input type="hidden" name="action" value="rzp_verify"><input name="razorpay_order_id"><input name="razorpay_payment_id"><input name="razorpay_signature"></form>
      <?php endif; ?>
      <?php if (setting('upi_enabled') && setting('upi_id')): ?>
        <?php if (setting('razorpay_enabled') && setting('razorpay_key_id')): ?><div class="divider">or</div><?php endif; ?>
        <form id="pay-upi" method="post" action="checkout.php<?= e(qs([])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="upi">
          <button class="btn btn-ghost btn-block">📱 Pay by UPI app (manual verification)</button></form>
        <p class="hint center">Scan a QR with any UPI app, then submit the transaction ID. Activated after we verify (usually within a few hours).</p>
      <?php endif; ?>
      <?php if (!(setting('razorpay_enabled') && setting('razorpay_key_id')) && !(setting('upi_enabled') && setting('upi_id'))): ?>
        <div class="alert alert-warning">Payments are not set up yet. Please contact support.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php if (setting('razorpay_enabled') && setting('razorpay_key_id') && $price['total'] > 0): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
document.getElementById('rzp-btn').addEventListener('click', async function () {
  const btn = this; btn.disabled = true;
  const body = new URLSearchParams({ csrf: HM.csrf, action: 'rzp_create', device: document.getElementById('device')?.value || '' });
  try {
    const r = await fetch(location.href, { method: 'POST', body });
    const d = await r.json();
    if (!d.ok) { alert(d.error || 'Could not start payment'); btn.disabled = false; return; }
    const rzp = new Razorpay({
      key: d.key, amount: d.amount, currency: 'INR', name: d.name, description: d.description, order_id: d.order_id,
      prefill: d.prefill, notes: { order_no: d.order_no }, theme: { color: '#7b6cff' },
      handler: function (resp) {
        const f = document.getElementById('rzp-done');
        for (const k of ['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature']) f.elements[k].value = resp[k];
        f.submit();
      },
      modal: { ondismiss: () => { btn.disabled = false; } }
    });
    rzp.on('payment.failed', e => { alert('Payment failed: ' + (e.error?.description || 'please try again')); btn.disabled = false; });
    rzp.open();
  } catch (e) { alert('Network error, please try again.'); btn.disabled = false; }
});
</script>
<?php endif; ?>
<?php page_end();
