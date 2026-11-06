<?php
/** Printable documents (A4): invoices, license certificates, statements, reports, user files.
 *  Used by both portals. Each document has a print/PDF toolbar, a branded header,
 *  stamps, amount in words and a QR code that verifies the document online. */

function invoice_check_code(string $order_no): string {
    return strtoupper(substr(hash_hmac('sha256', 'invoice:' . $order_no, app_key()), 0, 10));
}

function invoice_check_url(array $o): string {
    return site_url('/invoice_check.php?no=' . rawurlencode($o['order_no']) . '&c=' . invoice_check_code($o['order_no']));
}

/** 123456 → "One Lakh Twenty Three Thousand Four Hundred Fifty Six" */
function words_in(int $n): string {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
        'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = fn($x) => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
    $three = fn($x) => trim(($x >= 100 ? $ones[intdiv($x, 100)] . ' Hundred ' : '') . $two($x % 100));
    if ($n === 0) return 'Zero';
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $name]) {
        if ($n >= $div) { $parts[] = ($div === 10000000 ? words_in(intdiv($n, $div)) : $two(intdiv($n, $div))) . " $name"; $n %= $div; }
    }
    if ($n) $parts[] = $three($n);
    return trim(implode(' ', $parts));
}

function amount_in_words(int $paise): string {
    $r = intdiv($paise, 100);
    $p = $paise % 100;
    return 'Rupees ' . words_in($r) . ($p ? ' and ' . words_in($p) . ' Paise' : '') . ' Only';
}

/** Full standalone page for a document. $opts: back, stamp, stamp_color, qr, actions_html, landscape */
function doc_render(string $title, string $body, array $opts = []): never {
    $site = setting('site_name');
    $stamp = $opts['stamp'] ?? '';
    $stampColor = $opts['stamp_color'] ?? '#1fa971';
    $qr = $opts['qr'] ?? '';
    end_buffers();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($site) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<style>
  :root { --ink: #1a1b2e; --muted: #6b6e8f; --line: #e6e7f2; --brand1: #5b4bdb; --brand2: #a347c9; --soft: #f5f4ff; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { margin: 0; background: #e9eaf3; font: 13.5px/1.55 "Plus Jakarta Sans", "Segoe UI", Arial, sans-serif; color: var(--ink); }
  .toolbar { position: sticky; top: 0; z-index: 10; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: center; padding: 12px;
    background: rgba(20,21,45,.92); backdrop-filter: blur(10px); }
  .toolbar a, .toolbar button { display: inline-flex; align-items: center; gap: 6px; border: 0; cursor: pointer; padding: 9px 16px; border-radius: 10px; font: 600 13px inherit;
    font-family: inherit; color: #fff; text-decoration: none; background: rgba(255,255,255,.12); transition: background .2s, transform .15s; }
  .toolbar a:hover, .toolbar button:hover { background: rgba(255,255,255,.22); transform: translateY(-1px); }
  .toolbar .primary { background: linear-gradient(135deg, var(--brand1), var(--brand2)); }
  .toolbar form { margin: 0; }
  .sheet { position: relative; width: 210mm; min-height: 297mm; margin: 24px auto; background: #fff; box-shadow: 0 20px 60px rgba(20,20,60,.18); border-radius: 6px; overflow: hidden;
    padding: 0 0 26mm; animation: up .5s cubic-bezier(.2,.8,.2,1); }
  @keyframes up { from { opacity: 0; transform: translateY(20px); } }
  .band { height: 8px; background: linear-gradient(90deg, var(--brand1), var(--brand2), #29b8e8); }
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; padding: 26px 34px 18px; }
  .brand { display: flex; gap: 12px; align-items: center; }
  .logo { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; font-size: 24px; background: linear-gradient(135deg, var(--brand1), var(--brand2)); color: #fff; }
  .brand b { font-size: 20px; letter-spacing: -.02em; display: block; } .brand small { color: var(--muted); }
  .doc-title { text-align: right; } .doc-title h1 { margin: 0; font-size: 26px; letter-spacing: .06em; color: var(--brand1); text-transform: uppercase; }
  .doc-title div { color: var(--muted); font-size: 12.5px; } .doc-title b { color: var(--ink); }
  .body { padding: 0 34px; }
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin: 10px 0 20px; }
  .box { border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; background: #fcfcff; }
  .box h4 { margin: 0 0 6px; font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
  .box p { margin: 0; }
  table { width: 100%; border-collapse: collapse; margin: 6px 0 16px; }
  th { text-align: left; font-size: 10.5px; letter-spacing: .1em; text-transform: uppercase; color: #fff; background: var(--brand1); padding: 10px 12px; }
  th:first-child { border-radius: 8px 0 0 8px; } th:last-child { border-radius: 0 8px 8px 0; }
  td { padding: 11px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
  tbody tr:nth-child(even) td { background: #fafaff; }
  .r { text-align: right; } .muted { color: var(--muted); } .small { font-size: 11.5px; }
  .totals { margin-left: auto; width: 300px; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
  .totals div { display: flex; justify-content: space-between; padding: 8px 14px; border-bottom: 1px solid var(--line); }
  .totals div:last-child { border: 0; background: var(--soft); font-size: 16px; font-weight: 800; color: var(--brand1); }
  .words { margin: 14px 0; padding: 10px 14px; border-left: 4px solid var(--brand1); background: var(--soft); border-radius: 0 10px 10px 0; font-weight: 600; }
  .stamp { position: absolute; top: 120mm; right: 26mm; transform: rotate(-16deg); border: 4px solid currentColor; color: <?= e($stampColor) ?>; border-radius: 14px;
    padding: 8px 22px; font-size: 40px; font-weight: 800; letter-spacing: .12em; opacity: .22; pointer-events: none; z-index: 5; }
  .qr-box { display: flex; gap: 14px; align-items: center; margin-top: 18px; padding: 12px; border: 1px dashed var(--line); border-radius: 12px; }
  .qr-box #qr { width: 92px; height: 92px; } .qr-box #qr img, .qr-box #qr canvas { width: 92px !important; height: 92px !important; }
  .foot { position: absolute; left: 0; right: 0; bottom: 0; padding: 12px 34px; font-size: 11px; color: var(--muted); border-top: 1px solid var(--line); display: flex; justify-content: space-between; gap: 12px; background: #fcfcff; }
  .kv { display: grid; grid-template-columns: max-content 1fr; gap: 6px 18px; margin: 0; } .kv dt { color: var(--muted); } .kv dd { margin: 0; font-weight: 600; }
  .pill { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background: var(--soft); color: var(--brand1); }
  .pill.green { background: #e3f7ee; color: #148a5a; } .pill.red { background: #fde8ea; color: #c22a3a; } .pill.amber { background: #fdf3df; color: #a36b06; }
  .cert { margin: 18px 34px; padding: 34px; border-radius: 18px; text-align: center; position: relative; background: radial-gradient(circle at top, #f6f4ff, #fff 60%);
    border: 2px solid transparent; background-clip: padding-box; box-shadow: 0 0 0 2px var(--brand1) inset, 0 0 0 8px #fff inset, 0 0 0 9px #d9d5ff inset; }
  .cert h2 { font-size: 30px; margin: 4px 0; letter-spacing: -.02em; }
  .cert .key { display: inline-block; margin: 16px 0; padding: 14px 22px; font: 800 22px "Cascadia Mono", Consolas, monospace; letter-spacing: 2px; color: var(--brand1);
    background: var(--soft); border: 2px dashed var(--brand1); border-radius: 12px; }
  .grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 18px 0; } .grid3 .box { text-align: center; } .grid3 b { display: block; font-size: 18px; }
  .steps { counter-reset: s; list-style: none; padding: 0; margin: 10px 0; display: grid; gap: 8px; text-align: left; }
  .steps li { counter-increment: s; position: relative; padding-left: 32px; min-height: 22px; }
  .steps li::before { content: counter(s); position: absolute; left: 0; top: 0; width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; background: var(--brand1); color: #fff; font-size: 12px; font-weight: 800; }
  .bars { display: grid; gap: 6px; } .bars div { display: grid; grid-template-columns: 90px 1fr 90px; gap: 10px; align-items: center; font-size: 12px; }
  .bars i { display: block; height: 12px; border-radius: 6px; background: linear-gradient(90deg, var(--brand1), var(--brand2)); }
  h3.sec { font-size: 13px; letter-spacing: .1em; text-transform: uppercase; color: var(--brand1); margin: 22px 0 8px; border-bottom: 2px solid var(--soft); padding-bottom: 6px; }
  @media (max-width: 860px) { .sheet { width: 100%; margin: 0; border-radius: 0; min-height: auto; } .row, .grid3 { grid-template-columns: 1fr; } .stamp { top: 70mm; } }
  @media print {
    @page { size: A4; margin: 0; }
    body { background: #fff; } .toolbar { display: none !important; }
    .sheet { margin: 0; box-shadow: none; border-radius: 0; width: auto; animation: none; }
    .foot { position: fixed; }
    tr, .box, .totals { break-inside: avoid; }
  }
</style></head><body>
<div class="toolbar">
  <?php if (!empty($opts['back'])): ?><a href="<?= e($opts['back']) ?>">← Back</a><?php endif; ?>
  <button class="primary" onclick="window.print()">🖨 Print / Save as PDF</button>
  <?= $opts['actions_html'] ?? '' ?>
</div>
<div class="sheet">
  <div class="band"></div>
  <?php if ($stamp): ?><div class="stamp"><?= e($stamp) ?></div><?php endif; ?>
  <?= $body ?>
  <div class="foot"><span><?= e(setting('company_name') ?: $site) ?><?= setting('company_gstin') ? ' · GSTIN ' . e(setting('company_gstin')) : '' ?></span>
    <span>Generated <?= local_dt(now()) ?></span><span><?= e(setting('support_email')) ?></span></div>
</div>
<?php if ($qr): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>new QRCode(document.getElementById('qr'), { text: <?= json_encode($qr) ?>, width: 184, height: 184, correctLevel: QRCode.CorrectLevel.M });</script>
<?php endif; ?>
<?php if (isset($_GET['print'])): ?><script>addEventListener('load', () => setTimeout(() => print(), 600));</script><?php endif; ?>
</body></html>
<?php
    exit;
}

function doc_head(string $title, array $meta): string {
    $site = setting('site_name');
    $h = '<div class="head"><div class="brand"><div class="logo">🧠</div><div><b>' . e(setting('company_name') ?: $site) . '</b><small>'
        . nl2br(e(setting('company_address') ?: setting('tagline'))) . '</small></div></div><div class="doc-title"><h1>' . e($title) . '</h1>';
    foreach ($meta as $k => $v) $h .= '<div>' . e($k) . ': <b>' . e($v) . '</b></div>';
    return $h . '</div></div>';
}

function doc_invoice(array $o, array $u): string {
    $status = $o['status'];
    $h = doc_head('Tax Invoice', ['Invoice no' => $o['invoice_no'] ?: '—', 'Order no' => $o['order_no'], 'Date' => local_dt($o['paid_at'] ?: $o['created_at'], false)]);
    $h .= '<div class="body"><div class="row">';
    $h .= '<div class="box"><h4>Billed to</h4><p><b>' . e($u['name'] ?: $u['email']) . '</b><br>' . e($u['email']) . ($u['phone'] ? '<br>' . e($u['phone']) : '')
        . '<br>' . e($u['country']) . '</p></div>';
    $h .= '<div class="box"><h4>Payment</h4><dl class="kv"><dt>Status</dt><dd><span class="pill ' . ($status === 'paid' ? 'green' : ($status === 'refunded' ? 'red' : 'amber')) . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span></dd>'
        . '<dt>Method</dt><dd>' . e(strtoupper($o['method'] ?: '—')) . '</dd>'
        . ($o['gateway_payment_id'] ? '<dt>Payment ID</dt><dd>' . e($o['gateway_payment_id']) . '</dd>' : '')
        . ($o['utr'] ? '<dt>UTR</dt><dd>' . e($o['utr']) . '</dd>' : '')
        . '<dt>Paid on</dt><dd>' . local_dt($o['paid_at']) . '</dd></dl></div></div>';
    $h .= '<table><thead><tr><th>#</th><th>Description</th><th>Period</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead><tbody>'
        . '<tr><td>1</td><td><b>' . e(setting('site_name')) . ' — ' . e($o['plan_name']) . ' plan</b><div class="muted small">AI interview assistant license · 1 device · SAC 998314</div></td>'
        . '<td>' . (int)$o['plan_days'] . ' days</td><td class="r">1</td><td class="r">' . money($o['base_paise']) . '</td></tr></tbody></table>';
    $h .= '<div class="totals"><div><span>Subtotal</span><span>' . money($o['base_paise']) . '</span></div>'
        . ($o['discount_paise'] ? '<div><span>Discount ' . e($o['coupon_code']) . '</span><span>− ' . money($o['discount_paise']) . '</span></div>' : '')
        . ($o['tax_paise'] ? '<div><span>GST (' . (int)setting('gst_percent') . '%)</span><span>' . money($o['tax_paise']) . '</span></div>' : '')
        . '<div><span>Total</span><span>' . money($o['amount_paise']) . '</span></div></div>';
    $h .= '<div class="words">' . e(amount_in_words((int)$o['amount_paise'])) . '</div>';
    $h .= '<div class="qr-box"><div id="qr"></div><div><b>Verify this invoice</b><div class="muted small">Scan the QR code or open<br>' . e(invoice_check_url($o))
        . '</div><div class="small" style="margin-top:6px">Verification code: <b>' . invoice_check_code($o['order_no']) . '</b></div></div></div>';
    $h .= '<p class="muted small" style="margin-top:18px">Thank you for choosing ' . e(setting('site_name')) . '. This is a computer-generated invoice and needs no signature. '
        . 'Plans do not renew automatically. Questions? ' . e(setting('support_email') ?: 'Use Support in your portal') . '.</p></div>';
    return $h;
}

function doc_certificate(array $l, array $u): string {
    $plan = $l['plan_id'] ? val('SELECT name FROM plans WHERE id = ?', [$l['plan_id']]) : null;
    $dev = $l['device_pk'] ? row('SELECT name, device_id FROM devices WHERE id = ?', [$l['device_pk']]) : null;
    $st = effective_status($l);
    $left = days_left($l);
    $h = doc_head('License Certificate', ['Certificate no' => 'LC-' . str_pad((string)$l['id'], 6, '0', STR_PAD_LEFT), 'Issued' => local_dt($l['issued_at'], false)]);
    $h .= '<div class="cert"><div class="muted" style="letter-spacing:.2em;text-transform:uppercase;font-size:11px">This certifies that</div>'
        . '<h2>' . e($u['name'] ?: $u['email']) . '</h2><div class="muted">' . e($u['email']) . '</div>'
        . '<p style="margin:16px 0 0">holds a licence to use <b>' . e(setting('site_name')) . '</b> on one device with the activation key</p>'
        . '<div class="key">' . e($l['license_key']) . '</div>'
        . '<div class="grid3"><div class="box"><span class="muted small">Plan</span><b>' . e($plan ?: ucfirst($l['source'])) . '</b></div>'
        . '<div class="box"><span class="muted small">Duration</span><b>' . (int)$l['days'] . ' days</b></div>'
        . '<div class="box"><span class="muted small">Status</span><b>' . e(ucfirst($st)) . ($left !== null ? ' · ' . $left . 'd left' : '') . '</b></div></div>'
        . '<dl class="kv" style="text-align:left;max-width:420px;margin:0 auto"><dt>Device</dt><dd>' . ($dev ? e($dev['name']) . ' · ' . e($dev['device_id']) : 'Linked on first activation') . '</dd>'
        . '<dt>Activated</dt><dd>' . local_dt($l['activated_at']) . '</dd><dt>Valid until</dt><dd>' . ($l['expires_at'] ? local_dt($l['expires_at']) : (int)$l['days'] . ' days after activation') . '</dd></dl></div>';
    $h .= '<div class="body"><h3 class="sec">How to activate</h3><ol class="steps"><li>Install the ' . e(setting('site_name')) . ' desktop app (Portal → Download app).</li>'
        . '<li>Click the 🔑 badge at the top left of the app.</li><li>Paste the activation key above and click <b>Activate</b>.</li>'
        . '<li>The badge shows how many days are left. Renew any time in the portal — days are added on top.</li></ol>'
        . '<p class="muted small">Keep this key private. It works on one device at a time; you can move it to another of your devices from the portal.</p></div>';
    return $h;
}

function doc_statement(array $u, string $from, string $to): string {
    $off = setting('timezone_offset_minutes') * 60;
    $f = gmdate('Y-m-d H:i:s', strtotime("$from 00:00:00 UTC") - $off);
    $t = gmdate('Y-m-d H:i:s', strtotime("$to 23:59:59 UTC") - $off);
    $orders = rows("SELECT * FROM orders WHERE user_id = ? AND created_at BETWEEN ? AND ? AND status IN ('paid','refunded','pending_review','rejected') ORDER BY created_at", [$u['id'], $f, $t]);
    $lics = rows('SELECT l.*, p.name AS pname FROM licenses l LEFT JOIN plans p ON p.id = l.plan_id WHERE l.user_id = ? ORDER BY l.id', [$u['id']]);
    $paid = array_sum(array_map(fn($o) => $o['status'] === 'paid' ? (int)$o['amount_paise'] : 0, $orders));
    $refunded = array_sum(array_map(fn($o) => $o['status'] === 'refunded' ? (int)$o['amount_paise'] : 0, $orders));
    $h = doc_head('Account Statement', ['Period' => local_dt($f, false) . ' – ' . local_dt($t, false), 'Account' => '#' . $u['id']]);
    $h .= '<div class="body"><div class="row"><div class="box"><h4>Account holder</h4><p><b>' . e($u['name'] ?: $u['email']) . '</b><br>' . e($u['email'])
        . '<br>Member since ' . local_dt($u['created_at'], false) . '</p></div>'
        . '<div class="box"><h4>Summary</h4><dl class="kv"><dt>Payments</dt><dd>' . count($orders) . '</dd><dt>Total paid</dt><dd>' . money($paid) . '</dd>'
        . '<dt>Refunded</dt><dd>' . money($refunded) . '</dd><dt>Net</dt><dd>' . money($paid) . '</dd></dl></div></div>';
    $h .= '<h3 class="sec">Transactions</h3><table><thead><tr><th>Date</th><th>Order / invoice</th><th>Plan</th><th>Method</th><th>Status</th><th class="r">Amount</th></tr></thead><tbody>';
    foreach ($orders as $o) {
        $h .= '<tr><td>' . local_dt($o['created_at'], false) . '</td><td>' . e($o['order_no']) . '<div class="muted small">' . e($o['invoice_no'] ?: '') . '</div></td><td>'
            . e($o['plan_name']) . ' · ' . (int)$o['plan_days'] . 'd</td><td>' . e(strtoupper($o['method'])) . '</td><td><span class="pill ' . ($o['status'] === 'paid' ? 'green' : ($o['status'] === 'refunded' ? 'red' : 'amber'))
            . '">' . e(ucwords(str_replace('_', ' ', $o['status']))) . '</span></td><td class="r">' . money($o['amount_paise']) . '</td></tr>';
    }
    if (!$orders) $h .= '<tr><td colspan="6" class="muted" style="text-align:center">No payments in this period.</td></tr>';
    $h .= '</tbody></table><h3 class="sec">Licenses</h3><table><thead><tr><th>Key</th><th>Plan</th><th>Status</th><th>Activated</th><th>Expires</th></tr></thead><tbody>';
    foreach ($lics as $l) {
        $st = effective_status($l);
        $h .= '<tr><td style="font-family:Consolas,monospace">' . e($l['license_key']) . '</td><td>' . e($l['pname'] ?: ucfirst($l['source'])) . ' · ' . (int)$l['days'] . 'd</td><td><span class="pill '
            . ($st === 'active' ? 'green' : ($st === 'issued' ? '' : 'red')) . '">' . e(ucfirst($st)) . '</span></td><td>' . local_dt($l['activated_at'], false) . '</td><td>' . local_dt($l['expires_at'], false) . '</td></tr>';
    }
    if (!$lics) $h .= '<tr><td colspan="5" class="muted" style="text-align:center">No licenses.</td></tr>';
    return $h . '</tbody></table></div>';
}
