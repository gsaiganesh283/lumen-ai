<?php
require __DIR__ . '/_init.php';
require_admin('view');
$s = input('q');
$like = "%$s%";
$res = [];
if (mb_strlen($s) >= 2) {
    $res['Users'] = rows('SELECT id, email, name, status FROM users WHERE email LIKE ? OR name LIKE ? OR phone LIKE ? OR referral_code = ? OR id = ? OR last_login_ip = ? LIMIT 20', [$like, $like, $like, strtoupper($s), (int)$s, $s]);
    $res['Licenses'] = rows('SELECT l.id, l.license_key, l.status, l.user_id, u.email FROM licenses l JOIN users u ON u.id = l.user_id WHERE l.license_key LIKE ? LIMIT 20', [strtoupper($like)]);
    $res['Devices'] = rows('SELECT d.device_id, d.name, d.user_id, u.email FROM devices d JOIN users u ON u.id = d.user_id WHERE d.device_id LIKE ? OR d.hostname LIKE ? OR d.last_ip = ? LIMIT 20', [strtoupper($like), $like, $s]);
    $res['Orders'] = rows('SELECT o.order_no, o.invoice_no, o.status, o.amount_paise, o.user_id, u.email FROM orders o JOIN users u ON u.id = o.user_id WHERE o.order_no LIKE ? OR o.invoice_no LIKE ? OR o.utr LIKE ? OR o.gateway_payment_id LIKE ? OR o.gateway_order_id LIKE ? LIMIT 20', [$like, $like, $like, $like, $like]);
    $res['Tickets'] = rows('SELECT t.id, t.subject, t.status, u.email FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.subject LIKE ? OR t.id = ? LIMIT 20', [$like, (int)$s]);
    audit('search', 'search', '', $s);
}
admin_start('Search');
?>
<form class="filters"><input type="search" name="q" value="<?= e($s) ?>" placeholder="Email, name, key, Device ID, order, invoice, UTR, IP…" autofocus style="min-width:420px"><button class="btn btn-sm">Search</button></form>
<?php if ($s !== '' && !array_filter($res)): ?><div class="card empty">Nothing found for “<?= e($s) ?>”.</div><?php endif; ?>
<?php foreach ($res as $title => $items): if (!$items) continue; ?>
<div class="card"><h2><?= e($title) ?> (<?= count($items) ?>)</h2><table class="compact"><tbody>
<?php foreach ($items as $r): ?><tr>
  <?php if ($title === 'Users'): ?><td><a href="user.php?id=<?= (int)$r['id'] ?>"><?= e($r['email']) ?></a></td><td><?= e($r['name']) ?></td><td><?= badge($r['status']) ?></td>
  <?php elseif ($title === 'Licenses'): ?><td><code><?= e($r['license_key']) ?></code></td><td><a href="user.php?id=<?= (int)$r['user_id'] ?>&tab=licenses"><?= e($r['email']) ?></a></td><td><?= badge($r['status']) ?></td>
  <?php elseif ($title === 'Devices'): ?><td><code><?= e($r['device_id']) ?></code> <?= e($r['name']) ?></td><td><a href="user.php?id=<?= (int)$r['user_id'] ?>&tab=devices"><?= e($r['email']) ?></a></td>
  <?php elseif ($title === 'Orders'): ?><td><a href="orders.php?q=<?= e($r['order_no']) ?>"><?= e($r['order_no']) ?></a> <?= e($r['invoice_no']) ?></td><td><?= e($r['email']) ?></td><td><?= money($r['amount_paise']) ?></td><td><?= badge($r['status']) ?></td>
  <?php else: ?><td><a href="ticket.php?id=<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?> <?= e($r['subject']) ?></a></td><td><?= e($r['email']) ?></td><td><?= badge($r['status']) ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody></table></div>
<?php endforeach; ?>
<?php admin_end();
