<?php
/** Printable documents for admins: invoice, certificate, statement, user file, revenue report. */
require __DIR__ . '/_init.php';
$admin = require_admin('view');
$type = input('type');
$off = setting('timezone_offset_minutes') * 60;

switch ($type) {
    case 'invoice':
        require_admin('payments');
        $o = row('SELECT * FROM orders WHERE id = ? OR order_no = ?', [input_int('id'), input('no')]);
        if (!$o || !$o['invoice_no']) { flash('This order has no invoice (not paid).', 'danger'); redirect('orders.php'); }
        $u = user_by_id((int)$o['user_id']);
        if (admin_post('payments') && input('action') === 'email') {
            send_mail($u['email'], 'invoice_copy', ['name' => $u['name'] ?: 'there', 'invoice_no' => $o['invoice_no'], 'plan' => $o['plan_name'],
                'amount' => money($o['amount_paise']), 'date' => local_dt($o['paid_at'], false), 'code' => invoice_check_code($o['order_no']),
                'link' => site_url('/invoice.php?no=' . $o['order_no'])], (int)$u['id']);
            audit('invoice_emailed', 'order', $o['order_no']);
            flash("Invoice emailed to {$u['email']}.", 'success');
            redirect('orders.php?q=' . urlencode($o['order_no']));
        }
        audit('invoice_view', 'order', $o['order_no']);
        doc_render('Invoice ' . $o['invoice_no'], doc_invoice($o, $u), ['back' => 'orders.php?q=' . urlencode($o['order_no']), 'qr' => invoice_check_url($o),
            'stamp' => $o['status'] === 'refunded' ? 'REFUNDED' : 'PAID', 'stamp_color' => $o['status'] === 'refunded' ? '#d23a4a' : '#1fa971',
            'actions_html' => '<form method="post">' . csrf_field() . '<input type="hidden" name="type" value="invoice"><input type="hidden" name="id" value="' . (int)$o['id']
                . '"><button name="action" value="email">✉️ Email to customer</button></form><a href="user.php?id=' . (int)$u['id'] . '&tab=orders">👤 Customer</a>']);

    case 'certificate':
        $l = row('SELECT * FROM licenses WHERE id = ?', [input_int('id')]);
        if (!$l) { flash('License not found.', 'danger'); redirect('licenses.php'); }
        $st = effective_status($l);
        doc_render('License ' . $l['license_key'], doc_certificate($l, user_by_id((int)$l['user_id'])), ['back' => 'user.php?id=' . (int)$l['user_id'] . '&tab=licenses',
            'stamp' => strtoupper($st), 'stamp_color' => $st === 'active' ? '#1fa971' : '#d23a4a']);

    case 'statement':
        $u = user_by_id(input_int('id'));
        if (!$u) redirect('users.php');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : local_date($u['created_at']);
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : local_date();
        doc_render('Statement · ' . $u['email'], doc_statement($u, $from, $to), ['back' => 'user.php?id=' . (int)$u['id']]);

    case 'user':
        $u = user_by_id(input_int('id'));
        if (!$u) redirect('users.php');
        audit('user_file_printed', 'user', $u['id']);
        $lics = rows('SELECT l.*, p.name pname, d.device_id dcode FROM licenses l LEFT JOIN plans p ON p.id = l.plan_id LEFT JOIN devices d ON d.id = l.device_pk WHERE l.user_id = ? ORDER BY l.id DESC', [$u['id']]);
        $devs = rows('SELECT * FROM devices WHERE user_id = ? ORDER BY id DESC', [$u['id']]);
        $orders = rows('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$u['id']]);
        $events = rows('SELECT kind, label, path, ip, created_at FROM activity_events WHERE user_id = ? ORDER BY id DESC LIMIT 40', [$u['id']]);
        $logins = rows("SELECT * FROM login_attempts WHERE kind = 'user' AND email = ? ORDER BY id DESC LIMIT 15", [$u['email']]);
        $spent = (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE user_id = ? AND status = 'paid'", [$u['id']]);
        $h = doc_head('Customer File', ['Customer' => '#' . $u['id'], 'Prepared by' => $admin['name'] ?: $admin['email']]);
        $h .= '<div class="body"><div class="row"><div class="box"><h4>Customer</h4><p><b>' . e($u['name'] ?: '—') . '</b><br>' . e($u['email']) . '<br>' . e($u['phone']) . ' · ' . e($u['country'])
            . '</p></div><div class="box"><h4>Account</h4><dl class="kv"><dt>Status</dt><dd>' . e($u['status']) . '</dd><dt>Joined</dt><dd>' . local_dt($u['created_at']) . ' (' . e($u['signup_method']) . ')</dd>'
            . '<dt>Verified</dt><dd>' . ($u['email_verified'] ? 'Yes' : 'No') . '</dd><dt>2FA</dt><dd>' . ($u['totp_enabled'] ? 'On' : 'Off') . '</dd><dt>Total spent</dt><dd>' . money($spent) . '</dd>'
            . '<dt>Last login</dt><dd>' . local_dt($u['last_login_at']) . ' · ' . e($u['last_login_ip']) . '</dd></dl></div></div>';
        if ($u['admin_notes'] || $u['tags']) $h .= '<div class="words">🏷 ' . e($u['tags'] ?: '—') . '<br>' . nl2br(e((string)$u['admin_notes'])) . '</div>';
        $h .= '<h3 class="sec">Licenses</h3><table><thead><tr><th>Key</th><th>Plan</th><th>Status</th><th>Device</th><th>Expires</th></tr></thead><tbody>';
        foreach ($lics as $l) $h .= '<tr><td style="font-family:Consolas">' . e($l['license_key']) . '</td><td>' . e($l['pname'] ?: $l['source']) . ' · ' . (int)$l['days'] . 'd</td><td>' . e(effective_status($l)) . '</td><td>' . e($l['dcode'] ?: '—') . '</td><td>' . local_dt($l['expires_at'], false) . '</td></tr>';
        $h .= '</tbody></table><h3 class="sec">Devices</h3><table><thead><tr><th>Device ID</th><th>Name / PC</th><th>App</th><th>Last seen</th><th>Status</th></tr></thead><tbody>';
        foreach ($devs as $d) $h .= '<tr><td>' . e($d['device_id']) . '</td><td>' . e($d['name']) . ' · ' . e($d['hostname']) . '</td><td>v' . e($d['app_version']) . '</td><td>' . local_dt($d['last_seen_at']) . '</td><td>' . e($d['status']) . '</td></tr>';
        $h .= '</tbody></table><h3 class="sec">Payments</h3><table><thead><tr><th>Date</th><th>Order</th><th>Plan</th><th>Method</th><th>Status</th><th class="r">Amount</th></tr></thead><tbody>';
        foreach ($orders as $o) $h .= '<tr><td>' . local_dt($o['created_at'], false) . '</td><td>' . e($o['order_no']) . '</td><td>' . e($o['plan_name']) . '</td><td>' . e($o['method']) . '</td><td>' . e($o['status']) . '</td><td class="r">' . money($o['amount_paise']) . '</td></tr>';
        $h .= '</tbody></table><h3 class="sec">Recent sign-ins</h3><table><thead><tr><th>Time</th><th>Result</th><th>IP</th><th>Browser</th></tr></thead><tbody>';
        foreach ($logins as $a) $h .= '<tr><td>' . local_dt($a['created_at']) . '</td><td>' . ($a['success'] ? 'OK' : 'Failed') . ' ' . e($a['reason']) . '</td><td>' . e($a['ip']) . '</td><td>' . e(short_agent($a['user_agent'])) . '</td></tr>';
        $h .= '</tbody></table><h3 class="sec">Recent activity</h3><table><thead><tr><th>Time</th><th>Event</th><th>Details</th><th>IP</th></tr></thead><tbody>';
        foreach ($events as $ev) $h .= '<tr><td class="small">' . local_dt($ev['created_at']) . '</td><td>' . e(event_label($ev['kind'])) . '</td><td class="small">' . e(mb_substr($ev['label'] ?: $ev['path'], 0, 90)) . '</td><td class="small">' . e($ev['ip']) . '</td></tr>';
        $h .= '</tbody></table><p class="muted small">Confidential — contains personal data. Handle according to the privacy policy.</p></div>';
        doc_render('Customer file · ' . $u['email'], $h, ['back' => 'user.php?id=' . (int)$u['id'], 'stamp' => 'CONFIDENTIAL', 'stamp_color' => '#a36b06']);

    case 'report':
        require_admin('reports');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : local_date(null, '-29 days');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : local_date();
        $f = gmdate('Y-m-d H:i:s', strtotime("$from 00:00:00 UTC") - $off);
        $t = gmdate('Y-m-d H:i:s', strtotime("$to 23:59:59 UTC") - $off);
        $o_min = (int)($off / 60);
        $days = rows("SELECT DATE(DATE_ADD(paid_at, INTERVAL $o_min MINUTE)) d, COUNT(*) n, SUM(amount_paise) s FROM orders WHERE status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY d ORDER BY d", [$f, $t]);
        $plans = rows("SELECT plan_name, COUNT(*) n, SUM(amount_paise) s FROM orders WHERE status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY plan_name ORDER BY s DESC", [$f, $t]);
        $methods = rows("SELECT method, COUNT(*) n, SUM(amount_paise) s FROM orders WHERE status = 'paid' AND paid_at BETWEEN ? AND ? GROUP BY method ORDER BY s DESC", [$f, $t]);
        $total = array_sum(array_column($days, 's')); $count = array_sum(array_column($days, 'n'));
        $signups = (int)val('SELECT COUNT(*) FROM users WHERE created_at BETWEEN ? AND ?', [$f, $t]);
        $acts = (int)val('SELECT COUNT(*) FROM licenses WHERE activated_at BETWEEN ? AND ?', [$f, $t]);
        $refunds = (int)val("SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE status = 'refunded' AND paid_at BETWEEN ? AND ?", [$f, $t]);
        $max = max(1, ...array_map(fn($r) => (int)$r['s'], $days ?: [['s' => 1]]));
        $h = doc_head('Revenue Report', ['Period' => local_dt($f, false) . ' – ' . local_dt($t, false), 'Prepared by' => $admin['name'] ?: $admin['email']]);
        $h .= '<div class="body"><div class="grid3"><div class="box"><span class="muted small">Revenue</span><b>' . money($total) . '</b></div><div class="box"><span class="muted small">Paid orders</span><b>' . $count
            . '</b></div><div class="box"><span class="muted small">Average order</span><b>' . money($count ? intdiv($total, $count) : 0) . '</b></div><div class="box"><span class="muted small">New users</span><b>' . $signups
            . '</b></div><div class="box"><span class="muted small">Activations</span><b>' . $acts . '</b></div><div class="box"><span class="muted small">Refunded</span><b>' . money($refunds) . '</b></div></div>';
        $h .= '<div class="words">Total revenue: ' . e(amount_in_words($total)) . '</div><h3 class="sec">Daily revenue</h3><div class="bars">';
        foreach ($days as $r) $h .= '<div><span>' . e(gmdate('d M Y', strtotime($r['d']))) . '</span><i style="width:' . max(2, round($r['s'] * 100 / $max)) . '%"></i><span class="r">' . money($r['s']) . ' · ' . (int)$r['n'] . '</span></div>';
        if (!$days) $h .= '<p class="muted">No paid orders in this period.</p>';
        $h .= '</div><div class="row" style="margin-top:20px"><div><h3 class="sec">By plan</h3><table><thead><tr><th>Plan</th><th class="r">Orders</th><th class="r">Revenue</th></tr></thead><tbody>';
        foreach ($plans as $r) $h .= '<tr><td>' . e($r['plan_name']) . '</td><td class="r">' . (int)$r['n'] . '</td><td class="r">' . money($r['s']) . '</td></tr>';
        $h .= '</tbody></table></div><div><h3 class="sec">By payment method</h3><table><thead><tr><th>Method</th><th class="r">Orders</th><th class="r">Revenue</th></tr></thead><tbody>';
        foreach ($methods as $r) $h .= '<tr><td>' . e(strtoupper($r['method'])) . '</td><td class="r">' . (int)$r['n'] . '</td><td class="r">' . money($r['s']) . '</td></tr>';
        $h .= '</tbody></table></div></div></div>';
        $picker = '<form method="get" style="display:flex;gap:6px;align-items:center;color:#fff;font-size:13px"><input type="hidden" name="type" value="report">From <input type="date" name="from" value="' . e($from)
            . '" style="padding:6px;border-radius:8px;border:0"> to <input type="date" name="to" value="' . e($to) . '" style="padding:6px;border-radius:8px;border:0"><button>Update</button></form>';
        audit('report_printed', 'report', "$from..$to");
        doc_render('Revenue report', $h, ['back' => 'reports.php?from=' . $from . '&to=' . $to, 'actions_html' => $picker]);
}
flash('Unknown document.', 'danger');
redirect('index.php');
