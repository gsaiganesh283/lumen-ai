<?php
require __DIR__ . '/_init.php';
$u = require_login(false);
$id = input_int('id');

if (is_post()) {
    $action = input('action');
    if ($action === 'new') {
        $subject = mb_substr(input('subject'), 0, 200);
        $body = mb_substr(input('body'), 0, 5000);
        if (mb_strlen($subject) < 3 || mb_strlen($body) < 5) flash('Please add a subject and describe the problem.', 'danger');
        elseif (!rate_limit('ticket:' . $u['id'], 5, 3600)) flash('Too many tickets. Please wait a bit.', 'danger');
        else {
            $cat = in_array(input('category'), ['General', 'Payment', 'License', 'Desktop app', 'Account'], true) ? input('category') : 'General';
            $tid = insert('tickets', ['user_id' => $u['id'], 'subject' => $subject, 'category' => $cat,
                'priority' => input('category') === 'Payment' ? 'high' : 'normal', 'created_at' => now(), 'updated_at' => now()]);
            insert('ticket_messages', ['ticket_id' => $tid, 'author' => 'user', 'body' => $body, 'created_at' => now()]);
            track('ticket', ['user_id' => (int)$u['id'], 'label' => "Opened ticket #$tid: $subject"]);
            flash("Ticket #$tid created. We'll reply by email and here.", 'success');
            redirect("support.php?id=$tid");
        }
        redirect('support.php');
    }
    $t = row('SELECT * FROM tickets WHERE id = ? AND user_id = ?', [$id, $u['id']]);
    if ($t && $action === 'reply' && mb_strlen(input('body')) > 0) {
        insert('ticket_messages', ['ticket_id' => $t['id'], 'author' => 'user', 'body' => mb_substr(input('body'), 0, 5000), 'created_at' => now()]);
        update('tickets', ['status' => 'open', 'updated_at' => now()], 'id = ?', [$t['id']]);
        track('ticket', ['user_id' => (int)$u['id'], 'label' => "Replied on ticket #{$t['id']}"]);
    } elseif ($t && $action === 'reopen' && $t['status'] === 'closed') {
        update('tickets', ['status' => 'open', 'updated_at' => now()], 'id = ?', [$t['id']]);
        insert('ticket_messages', ['ticket_id' => $t['id'], 'author' => 'user', 'body' => '(Ticket reopened)', 'created_at' => now()]);
        flash('Ticket reopened.', 'success');
    } elseif ($t && $action === 'close') {
        update('tickets', ['status' => 'closed', 'updated_at' => now(), 'rating' => input_int('rating') ?: null], 'id = ?', [$t['id']]);
        flash('Ticket closed. Thanks for the feedback!', 'success');
    }
    redirect("support.php?id=$id");
}

if ($id) {
    $t = row('SELECT * FROM tickets WHERE id = ? AND user_id = ?', [$id, $u['id']]);
    if (!$t) redirect('support.php');
    $msgs = rows('SELECT * FROM ticket_messages WHERE ticket_id = ? AND internal = 0 ORDER BY id', [$id]);
    page_start("Ticket #$id", 'support.php'); ?>
    <p><a href="support.php">← All tickets</a></p>
    <div class="card"><div class="card-head"><h2><?= e($t['subject']) ?></h2><div><?= badge($t['status']) ?> <span class="badge badge-gray"><?= e($t['category']) ?></span></div></div>
      <div class="chat"><?php foreach ($msgs as $m): ?>
        <div class="msg <?= $m['author'] === 'user' ? 'me' : '' ?>"><?= e($m['body']) ?><div class="meta"><?= $m['author'] === 'user' ? 'You' : '🛟 Support' ?> · <?= local_dt($m['created_at']) ?></div></div>
      <?php endforeach; ?></div>
      <?php if ($t['status'] !== 'closed'): ?>
        <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reply">
          <textarea name="body" required placeholder="Write a reply..."></textarea><br><br><button class="btn">Send</button></form>
        <form method="post" style="margin-top:10px" class="input-group"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="close">
          <select name="rating" style="max-width:220px"><option value="">Rate our help (optional)</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('⭐', $i) ?></option><?php endfor; ?></select>
          <button class="btn btn-ghost">Close ticket</button></form>
      <?php else: ?><form method="post" class="input-group" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <span class="muted" style="flex:1">This ticket is closed.</span><button class="btn btn-sm" name="action" value="reopen">↺ Reopen ticket</button></form><?php endif; ?>
      <p class="no-print" style="margin-top:12px"><button class="btn btn-sm btn-ghost" onclick="print()">🖨 Print conversation</button></p>
    </div>
    <?php page_end();
    exit;
}
$tickets = rows('SELECT t.*, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id AND m.internal = 0) AS n FROM tickets t WHERE user_id = ? ORDER BY updated_at DESC', [$u['id']]);
page_start('Support', 'support.php');
?>
<div class="grid grid-2">
<div class="card"><h2>🆕 New ticket</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="new">
    <label>Topic</label><select name="category"><option>General</option><option>Payment</option><option>License</option><option>Desktop app</option><option>Account</option></select>
    <label>Subject</label><input name="subject" required maxlength="200">
    <label>Describe the problem</label><textarea name="body" required maxlength="5000" placeholder="Include your order number or Device ID if it's about a payment or the app."></textarea>
    <br><br><button class="btn">Send</button></form></div>
<div class="card"><h2>📚 Quick answers</h2>
  <details class="faq"><summary>My key says "belongs to another device"</summary><p class="muted small">Go to Licenses → Move to device and pick the PC you're using, then activate again.</p></details>
  <details class="faq"><summary>I paid by UPI but have no key</summary><p class="muted small">UPI payments are checked by our team, usually within a few hours. You'll get an email with the key.</p></details>
  <details class="faq"><summary>The app doesn't hear the interviewer</summary><p class="muted small">In the app open Settings → Audio and set "Interviewer audio" to Auto. Bluetooth headsets are supported.</p></details>
  <details class="faq"><summary>How do I renew?</summary><p class="muted small">Plans → choose a plan with "Renew". Days are added on top of what's left.</p></details>
  <?php if (setting('support_email')): ?><p class="small">Email: <a href="mailto:<?= e(setting('support_email')) ?>"><?= e(setting('support_email')) ?></a></p><?php endif; ?></div>
</div>
<div class="card"><h2>🎫 Your tickets</h2>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Subject</th><th>Topic</th><th>Status</th><th>Updated</th></tr></thead><tbody>
<?php if (!$tickets): ?><tr><td colspan="5" class="empty">No tickets yet.</td></tr><?php endif; ?>
<?php foreach ($tickets as $t): ?><tr><td><?= (int)$t['id'] ?></td><td><a href="support.php?id=<?= (int)$t['id'] ?>"><?= e($t['subject']) ?></a> <span class="muted small">(<?= (int)$t['n'] ?>)</span></td>
  <td><?= e($t['category']) ?></td><td><?= badge($t['status']) ?></td><td><?= time_ago($t['updated_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php page_end();
