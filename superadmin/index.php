<?php
require __DIR__ . '/_init.php';
require_admin('view');

// Start of "today" in the display timezone, converted back to UTC.
$today =ts_plus('-' . setting('timezone_offset_minutes') . ' minutes', gmdate('Y-m-d 00:00:00', time() + setting('timezone_offset_minutes') * 60));
$m30 = ts_plus('-30 days');
$k = [
    'users' => (int)val("SELECT COUNT(*) FROM users WHERE status <> 'deleted'"),
    'users_today' => (int)val('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$today]),
    'verified' => (int)val("SELECT COUNT(*) FROM users WHERE email_verified = 1 AND status <> 'deleted'"),
    'active_lic' => (int)val("SELECT COUNT(*) FROM licenses WHERE status = 'active' AND expires_at > ?", [now()]),
    'issued_lic' => (int)val("SELECT COUNT(*) FROM licenses WHERE status = 'issued'"),
    'expiring' => (int)val("SELECT COUNT(*) FROM licenses WHERE status = 'active' AND expires_at BETWEEN ? AND ?", [now(), ts_plus('+7 days')]),
    'rev_today' => (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE status = 'paid' AND paid_at >= ?", [$today]),
    'rev_30' => (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE status = 'paid' AND paid_at >= ?", [$m30]),
    'rev_all' => (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE status = 'paid'"),
    'orders_30' => (int)val("SELECT COUNT(*) FROM orders WHERE status = 'paid' AND paid_at >= ?", [$m30]),
    'pending' => (int)val("SELECT COUNT(*) FROM orders WHERE status = 'pending_review'"),
    'tickets' => (int)val("SELECT COUNT(*) FROM tickets WHERE status = 'open'"),
    'online_web' => (int)val("SELECT COUNT(DISTINCT owner_id) FROM sessions WHERE kind = 'user' AND revoked = 0 AND last_seen_at > ?", [ts_plus('-5 minutes')]),
    'online_app' => (int)val('SELECT COUNT(*) FROM devices WHERE last_seen_at > ?', [ts_plus('-' . (setting('heartbeat_minutes') * 2 + 1) . ' minutes')]),
    'failed_logins' => (int)val('SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ?', [ts_plus('-24 hours')]),
    'emails_failed' => (int)val("SELECT COUNT(*) FROM email_logs WHERE status = 'failed' AND created_at >= ?", [ts_plus('-7 days')]),
];
$prev30 = (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE status = 'paid' AND paid_at BETWEEN ? AND ?", [ts_plus('-60 days'), $m30]);
$growth = $prev30 ? round(($k['rev_30'] - $prev30) * 100 / $prev30) : null;

// Daily series for charts (last 30 days, local dates).
$off = setting('timezone_offset_minutes');
$days = [];
for ($i = 29; $i >= 0; $i--) $days[gmdate('Y-m-d', time() + $off * 60 - $i * 86400)] = ['rev' => 0, 'users' => 0, 'act' => 0];
foreach (rows("SELECT DATE(DATE_ADD(paid_at, INTERVAL $off MINUTE)) d, SUM(amount_paise) s FROM orders WHERE status = 'paid' AND paid_at >= ? GROUP BY d", [$m30]) as $r)
    if (isset($days[$r['d']])) $days[$r['d']]['rev'] = $r['s'] / 100;
foreach (rows("SELECT DATE(DATE_ADD(created_at, INTERVAL $off MINUTE)) d, COUNT(*) c FROM users WHERE created_at >= ? GROUP BY d", [$m30]) as $r)
    if (isset($days[$r['d']])) $days[$r['d']]['users'] = (int)$r['c'];
foreach (rows("SELECT DATE(DATE_ADD(activated_at, INTERVAL $off MINUTE)) d, COUNT(*) c FROM licenses WHERE activated_at >= ? GROUP BY d", [$m30]) as $r)
    if (isset($days[$r['d']])) $days[$r['d']]['act'] = (int)$r['c'];
$by_plan = rows("SELECT plan_name n, COUNT(*) c, SUM(amount_paise) s FROM orders WHERE status = 'paid' GROUP BY plan_name ORDER BY s DESC");
$lic_status = rows("SELECT status n, COUNT(*) c FROM licenses GROUP BY status");
$recent_users = rows("SELECT id, email, name, signup_method, email_verified, created_at FROM users ORDER BY id DESC LIMIT 8");
$recent_orders = rows("SELECT o.*, u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.status IN ('paid','pending_review') ORDER BY o.id DESC LIMIT 8");
$expiring = rows("SELECT l.id, l.license_key, l.expires_at, l.user_id, u.email FROM licenses l JOIN users u ON u.id = l.user_id
    WHERE l.status = 'active' AND l.expires_at BETWEEN ? AND ? ORDER BY l.expires_at LIMIT 8", [now(), ts_plus('+7 days')]);
admin_start('Dashboard');
$hour = (int)gmdate('G', time() + setting('timezone_offset_minutes') * 60);
?>
<div class="hello"><div><h2><?= $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening') ?>, <?= e(explode(' ', $ADMIN['name'] ?: 'Admin')[0]) ?> 👋</h2>
  <p><?= $k['pending'] ? "{$k['pending']} payment(s) to approve · " : '' ?><?= $k['tickets'] ? "{$k['tickets']} open ticket(s) · " : '' ?><?= $k['online_web'] + $k['online_app'] ?> online now · <?= gmdate('l, d M Y', time() + setting('timezone_offset_minutes') * 60) ?></p></div>
  <div class="actions"><a class="btn btn-sm" href="live.php">🟢 Live monitor</a><a class="btn btn-sm" href="document.php?type=report" target="_blank">🖨 Print report</a><button class="btn btn-sm" data-palette>⚡ Ctrl K</button></div></div>
<div class="card"><div class="card-head"><h2>⚡ Quick actions</h2></div><div class="qa-grid">
  <?php foreach (array_filter([
      can('payments') ? ['orders.php?status=pending_review', '🧾', 'Approve payments', $k['pending'] . ' waiting'] : null,
      can('users') ? ['user.php?new=1', '➕', 'New user', 'Create an account'] : null,
      can('licenses') ? ['licenses.php#create', '🔑', 'Issue keys', 'Give a license'] : null,
      can('tickets') ? ['tickets.php', '🎫', 'Answer tickets', $k['tickets'] . ' open'] : null,
      can('coupons') ? ['coupons.php#form', '🏷', 'New coupon', 'Run a promotion'] : null,
      can('emails') ? ['notify.php', '📣', 'Broadcast', 'Email / notify users'] : null,
      can('announcements') ? ['announcements.php#form', '📢', 'Announcement', 'Portal + app banner'] : null,
      can('database') ? ['database.php?tab=backups', '💾', 'Backup now', 'Database'] : null,
      can('reports') ? ['reports.php', '📈', 'Reports', 'Revenue & funnel'] : null,
      can('security') ? ['security.php', '🛡', 'Security', $k['failed_logins'] . ' failed logins'] : null,
  ]) as [$href, $ico, $t, $s]): ?><a class="qa" href="<?= e($href) ?>"><span><?= $ico ?></span><?= e($t) ?><small><?= e((string)$s) ?></small></a><?php endforeach; ?>
</div></div>
<?php if ($k['pending']): ?><div class="alert alert-warning">🧾 <b><?= $k['pending'] ?></b> UPI payment<?= $k['pending'] > 1 ? 's' : '' ?> waiting for approval. <a href="orders.php?status=pending_review">Review now →</a></div><?php endif; ?>
<?php if (!setting('email_enabled')): ?><div class="alert alert-info">✉️ Real email sending is off — emails are only saved in the <a href="emails.php">Email log</a>. Turn it on in <a href="settings.php?tab=Email">Settings → Email</a>.</div><?php endif; ?>
<?php if (!setting('razorpay_enabled') && !setting('upi_id')): ?><div class="alert alert-warning">💳 No payment method is set up yet. Add Razorpay keys or a UPI ID in <a href="settings.php?tab=Payments">Settings → Payments</a>.</div><?php endif; ?>
<div class="grid grid-4">
  <?= stat_card('Revenue today', money($k['rev_today']), $k['orders_30'] . ' paid orders in 30d', 'orders.php?status=paid') ?>
  <?= stat_card('Revenue (30 days)', money($k['rev_30']), $growth === null ? 'All time ' . money($k['rev_all']) : '<span class="' . ($growth >= 0 ? 'kpi-up' : 'kpi-down') . '">' . ($growth >= 0 ? '▲' : '▼') . abs($growth) . '%</span> vs previous 30d', 'reports.php') ?>
  <?= stat_card('Users', number_format($k['users']), '+' . $k['users_today'] . ' today · ' . $k['verified'] . ' verified', 'users.php') ?>
  <?= stat_card('Active licenses', number_format($k['active_lic']), $k['issued_lic'] . ' unused · ' . $k['expiring'] . ' expire in 7d', 'licenses.php?status=active') ?>
  <?= stat_card('Online now', '<span class="live-dot"></span> ' . $k['online_web'] . ' / ' . $k['online_app'], 'portal users / desktop apps', 'live.php') ?>
  <?= stat_card('Pending UPI', $k['pending'], 'waiting for approval', 'orders.php?status=pending_review') ?>
  <?= stat_card('Open tickets', $k['tickets'], 'support queue', 'tickets.php') ?>
  <?= stat_card('Failed logins (24h)', $k['failed_logins'], $k['emails_failed'] . ' failed emails (7d)', 'security.php') ?>
</div><br>
<div class="grid grid-2">
  <div class="card"><h2>💰 Revenue — last 30 days</h2><div class="chart-box"><canvas id="c-rev"></canvas></div></div>
  <div class="card"><h2>👥 Sign-ups & activations</h2><div class="chart-box"><canvas id="c-users"></canvas></div></div>
  <div class="card"><h2>💎 Sales by plan</h2><div class="chart-box"><canvas id="c-plan"></canvas></div></div>
  <div class="card"><h2>🔑 Licenses by status</h2><div class="chart-box"><canvas id="c-lic"></canvas></div></div>
</div>
<div class="grid grid-2">
  <div class="card"><div class="card-head"><h2>🆕 Newest users</h2><a class="btn btn-sm btn-ghost" href="users.php">All</a></div>
    <div class="table-wrap"><table class="compact"><tbody><?php foreach ($recent_users as $r): ?>
      <tr><td><?= user_link((int)$r['id'], $r['email']) ?><div class="small muted"><?= e($r['name']) ?></div></td><td><?= $r['signup_method'] === 'google' ? '🟢 Google' : '✉️ Email' ?></td>
        <td><?= $r['email_verified'] ? badge('verified') : badge('unverified') ?></td><td class="small muted"><?= time_ago($r['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="card"><div class="card-head"><h2>🧾 Latest payments</h2><a class="btn btn-sm btn-ghost" href="orders.php">All</a></div>
    <div class="table-wrap"><table class="compact"><tbody><?php foreach ($recent_orders as $o): ?>
      <tr><td><a href="orders.php?q=<?= e($o['order_no']) ?>"><?= e($o['order_no']) ?></a><div class="small muted"><?= e($o['email']) ?></div></td><td><?= money($o['amount_paise']) ?></td>
        <td><?= badge($o['status']) ?></td><td class="small muted"><?= time_ago($o['paid_at'] ?: $o['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<div class="card"><div class="card-head"><h2>⏰ Expiring in 7 days</h2><a class="btn btn-sm btn-ghost" href="licenses.php?expiring=7">All</a></div>
  <div class="table-wrap"><table class="compact"><tbody><?php foreach ($expiring as $l): ?>
    <tr><td><code><?= e($l['license_key']) ?></code></td><td><?= user_link((int)$l['user_id'], $l['email']) ?></td><td><?= local_dt($l['expires_at']) ?></td><td><?= days_left($l) ?> days</td></tr>
  <?php endforeach; ?><?php if (!$expiring): ?><tr><td class="empty">None 🎉</td></tr><?php endif; ?></tbody></table></div></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  Chart.defaults.color = css('--muted'); Chart.defaults.borderColor = css('--line'); Chart.defaults.font.family = 'Segoe UI, system-ui';
  const labels = <?= json_encode(array_map(fn($d) => gmdate('d M', strtotime($d)), array_keys($days))) ?>;
  const d = <?= json_encode(array_values($days)) ?>;
  const opts = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true } }, scales: { y: { beginAtZero: true } } };
  new Chart(document.getElementById('c-rev'), { type: 'bar', data: { labels, datasets: [{ label: 'Revenue (₹)', data: d.map(x => x.rev), backgroundColor: '#5b8cff', borderRadius: 4 }] }, options: opts });
  new Chart(document.getElementById('c-users'), { type: 'line', data: { labels, datasets: [
    { label: 'Sign-ups', data: d.map(x => x.users), borderColor: '#7b6cff', backgroundColor: 'rgba(123,108,255,.15)', fill: true, tension: .3 },
    { label: 'Activations', data: d.map(x => x.act), borderColor: '#2ecc8f', tension: .3 }] }, options: opts });
  const pal = ['#5b8cff', '#7b6cff', '#2ecc8f', '#f5b342', '#ff5d6c', '#4aa8ff', '#b57bff'];
  new Chart(document.getElementById('c-plan'), { type: 'doughnut', data: { labels: <?= json_encode(array_column($by_plan, 'n')) ?>,
    datasets: [{ data: <?= json_encode(array_map(fn($r) => $r['s'] / 100, $by_plan)) ?>, backgroundColor: pal }] }, options: { responsive: true, maintainAspectRatio: false } });
  new Chart(document.getElementById('c-lic'), { type: 'pie', data: { labels: <?= json_encode(array_column($lic_status, 'n')) ?>,
    datasets: [{ data: <?= json_encode(array_map('intval', array_column($lic_status, 'c'))) ?>, backgroundColor: pal }] }, options: { responsive: true, maintainAspectRatio: false } });
})();
</script>
<?php admin_end();
