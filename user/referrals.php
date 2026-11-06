<?php
require __DIR__ . '/_init.php';
$u = require_login();
$link = site_url('/register.php?ref=' . $u['referral_code']);
$refs = rows("SELECT name, email, created_at, (SELECT COUNT(*) FROM orders o WHERE o.user_id = users.id AND o.status = 'paid') AS paid
    FROM users WHERE referred_by = ? ORDER BY id DESC", [$u['id']]);
$bonus = setting('referral_bonus_days');
$earned = (int)val("SELECT COUNT(*) FROM license_events le JOIN licenses l ON l.id = le.license_id WHERE l.user_id = ? AND le.actor = 'referral'", [$u['id']]) * $bonus;
$mask = fn($e) => substr($e, 0, 2) . '***' . substr($e, strpos($e, '@'));
page_start('Refer & earn', 'referrals.php');
?>
<div class="grid grid-3">
  <div class="stat"><div class="label">Friends joined</div><div class="value"><?= count($refs) ?></div></div>
  <div class="stat"><div class="label">Friends who bought</div><div class="value"><?= count(array_filter($refs, fn($r) => $r['paid'] > 0)) ?></div></div>
  <div class="stat"><div class="label">Free days earned</div><div class="value"><?= $earned ?></div></div>
</div><br>
<div class="card"><h2>🎁 Invite friends</h2>
  <?php if ($bonus > 0): ?><p>Get <b><?= $bonus ?> free days</b> on your plan for every friend who buys their first plan.</p><?php else: ?><p class="muted">Share InterviewLens with friends.</p><?php endif; ?>
  <label>Your link</label><div class="input-group"><input value="<?= e($link) ?>" readonly><button class="btn" data-copy="<?= e($link) ?>">Copy</button></div>
  <p class="small muted">Your code: <b class="mono"><?= e($u['referral_code']) ?></b></p>
  <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('I use ' . setting('site_name') . ' for interviews — try it: ' . $link) ?>">Share on WhatsApp</a></div>
<div class="card"><h2>Your referrals</h2><div class="table-wrap"><table><thead><tr><th>Friend</th><th>Joined</th><th>Status</th></tr></thead><tbody>
<?php if (!$refs): ?><tr><td colspan="3" class="empty">No referrals yet.</td></tr><?php endif; ?>
<?php foreach ($refs as $r): ?><tr><td><?= e($r['name'] ?: 'Friend') ?> <span class="muted small"><?= e($mask($r['email'])) ?></span></td><td><?= local_dt($r['created_at'], false) ?></td>
  <td><?= $r['paid'] ? badge('paid') : '<span class="badge badge-gray">Signed up</span>' ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php page_end();
