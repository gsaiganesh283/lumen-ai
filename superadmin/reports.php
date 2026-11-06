<?php
require __DIR__ . '/_init.php';
require_admin('reports');
$from = input('from', local_date(null, '-29 days'));
$to = input('to', local_date());
$off = setting('timezone_offset_minutes');
$f = gmdate('Y-m-d H:i:s', strtotime("$from 00:00:00 UTC") - $off * 60);
$t = gmdate('Y-m-d H:i:s', strtotime("$to 23:59:59 UTC") - $off * 60);
$group = input('group', 'day');
$fmt = ['day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m'][$group] ?? '%Y-%m-%d';

$series = rows("SELECT DATE_FORMAT(DATE_ADD(paid_at, INTERVAL $off MINUTE), '$fmt') k, COUNT(*) n, SUM(amount_paise) s, SUM(discount_paise) d, SUM(tax_paise) tx
    FROM orders WHERE status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY k ORDER BY k", [$f, $t]);
$by_plan = rows("SELECT plan_name, COUNT(*) n, SUM(amount_paise) s FROM orders WHERE status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY plan_name ORDER BY s DESC", [$f, $t]);
$by_method = rows("SELECT method, COUNT(*) n, SUM(amount_paise) s FROM orders WHERE status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY method ORDER BY s DESC", [$f, $t]);
$by_coupon = rows("SELECT coupon_code, COUNT(*) n, SUM(discount_paise) d, SUM(amount_paise) s FROM orders WHERE status = 'paid' AND coupon_code <> '' AND paid_at BETWEEN ? AND ? GROUP BY coupon_code ORDER BY n DESC", [$f, $t]);
$top = rows("SELECT u.id, u.email, COUNT(*) n, SUM(o.amount_paise) s FROM orders o JOIN users u ON u.id = o.user_id WHERE o.status = 'paid' AND o.paid_at BETWEEN ? AND ? GROUP BY u.id ORDER BY s DESC LIMIT 10", [$f, $t]);
$funnel = [
    'Signed up' => (int)val('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?', [$f, $t]),
    'Verified email' => (int)val('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ? AND email_verified = 1', [$f, $t]),
    'Started checkout' => (int)val('SELECT COUNT(DISTINCT o.user_id) FROM orders o JOIN users u ON u.id = o.user_id WHERE u.created_at BETWEEN ? AND ?', [$f, $t]),
    'Paid' => (int)val("SELECT COUNT(DISTINCT o.user_id) FROM orders o JOIN users u ON u.id = o.user_id WHERE u.created_at BETWEEN ? AND ? AND o.status = 'paid'", [$f, $t]),
    'Activated app' => (int)val('SELECT COUNT(DISTINCT l.user_id) FROM licenses l JOIN users u ON u.id = l.user_id WHERE u.created_at BETWEEN ? AND ? AND l.activated_at IS NOT NULL', [$f, $t]),
];
$total = array_sum(array_column($series, 's'));
$orders_n = array_sum(array_column($series, 'n'));
$renewals = (int)val("SELECT COUNT(*) FROM orders o WHERE o.status = 'paid' AND o.paid_at BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM orders p WHERE p.user_id = o.user_id AND p.status = 'paid' AND p.id < o.id)", [$f, $t]);
$refunds = (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE status = 'refunded' AND paid_at BETWEEN ? AND ?", [$f, $t]);
$expired_not_renewed = (int)val("SELECT COUNT(*) FROM licenses l WHERE l.status = 'expired' AND l.expires_at BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM licenses x WHERE x.user_id = l.user_id AND x.status IN ('active','issued') AND x.id <> l.id)", [$f, $t]);

if (input('export') === 'csv') csv_download("revenue-$from-$to.csv", ['Period', 'Orders', 'Revenue (INR)', 'Discounts (INR)', 'GST (INR)'],
    array_map(fn($r) => [$r['k'], $r['n'], $r['s'] / 100, $r['d'] / 100, $r['tx'] / 100], $series));
admin_start('Reports');
?>
<form class="filters"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"><label>To</label><input type="date" name="to" value="<?= e($to) ?>">
  <?= filter_select('group', ['day' => 'By day', 'week' => 'By week', 'month' => 'By month'], 'Group') ?><button class="btn btn-sm">Update</button>
  <a class="btn btn-sm btn-green" href="document.php?type=report&from=<?= e($from) ?>&to=<?= e($to) ?>" target="_blank">🖨 Printable report</a>
  <a class="btn btn-sm btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">⬇ CSV</a>
  <?php foreach (['7' => '7d', '30' => '30d', '90' => '90d', '365' => '1y'] as $d => $l): ?><a class="btn btn-sm btn-ghost" href="?from=<?= local_date(null, "-$d days") ?>&to=<?= local_date() ?>&group=<?= $d > 90 ? 'month' : 'day' ?>"><?= $l ?></a><?php endforeach; ?></form>
<div class="grid grid-4">
  <?= stat_card('Revenue', money($total), $orders_n . ' orders') ?><?= stat_card('Average order', money($orders_n ? (int)($total / $orders_n) : 0)) ?>
  <?= stat_card('Renewals', $renewals, $orders_n ? round($renewals * 100 / $orders_n) . '% of orders' : '') ?><?= stat_card('Refunded', money($refunds), $expired_not_renewed . ' expired & not renewed (churn)') ?>
</div><br>
<div class="card"><h2>Revenue</h2><div class="chart-box"><canvas id="c1"></canvas></div></div>
<div class="grid grid-2">
  <div class="card"><h2>🔻 Conversion funnel (users who joined in this period)</h2>
    <?php $first = max(1, reset($funnel)); foreach ($funnel as $label => $n): ?><div style="margin:8px 0"><div class="small"><?= e($label) ?> — <b><?= $n ?></b> (<?= round($n * 100 / $first) ?>%)</div><div class="progress"><div style="width:<?= round($n * 100 / $first) ?>%"></div></div></div><?php endforeach; ?></div>
  <div class="card"><h2>💎 By plan</h2><table class="compact"><?php foreach ($by_plan as $r): ?><tr><td><?= e($r['plan_name']) ?></td><td><?= (int)$r['n'] ?></td><td><?= money($r['s']) ?></td></tr><?php endforeach; ?></table>
    <h2 style="margin-top:16px">💳 By payment method</h2><table class="compact"><?php foreach ($by_method as $r): ?><tr><td><?= e(strtoupper($r['method'])) ?></td><td><?= (int)$r['n'] ?></td><td><?= money($r['s']) ?></td></tr><?php endforeach; ?></table></div>
  <div class="card"><h2>🏷 Coupons used</h2><table class="compact"><thead><tr><th>Code</th><th>Uses</th><th>Discount</th><th>Revenue</th></tr></thead><?php foreach ($by_coupon as $r): ?><tr><td><code><?= e($r['coupon_code']) ?></code></td><td><?= (int)$r['n'] ?></td><td><?= money($r['d']) ?></td><td><?= money($r['s']) ?></td></tr><?php endforeach; ?></table></div>
  <div class="card"><h2>🏆 Top customers</h2><table class="compact"><?php foreach ($top as $r): ?><tr><td><?= user_link((int)$r['id'], $r['email']) ?></td><td><?= (int)$r['n'] ?> orders</td><td><?= money($r['s']) ?></td></tr><?php endforeach; ?></table></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--muted').trim();
new Chart(document.getElementById('c1'), { type: 'bar', data: { labels: <?= json_encode(array_column($series, 'k')) ?>, datasets: [
  { label: 'Revenue (₹)', data: <?= json_encode(array_map(fn($r) => $r['s'] / 100, $series)) ?>, backgroundColor: '#5b8cff', borderRadius: 4, yAxisID: 'y' },
  { label: 'Orders', type: 'line', data: <?= json_encode(array_map(fn($r) => (int)$r['n'], $series)) ?>, borderColor: '#2ecc8f', yAxisID: 'y1', tension: .3 }] },
  options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true }, y1: { beginAtZero: true, position: 'right', grid: { display: false } } } } });
</script>
<?php admin_end();
