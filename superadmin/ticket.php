<?php
require __DIR__ . '/_init.php';
$admin = require_admin('tickets');
$t = row('SELECT t.*, u.email, u.name FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?', [input_int('id')]);
if (!$t) { flash('Ticket not found.', 'danger'); redirect('tickets.php'); }

if (admin_post('tickets')) {
    $act = input('action');
    if ($act === 'reply' && trim(input('body')) !== '') {
        $internal = isset($_POST['internal']) ? 1 : 0;
        insert('ticket_messages', ['ticket_id' => $t['id'], 'author' => 'admin', 'admin_id' => $admin['id'], 'body' => input('body'), 'internal' => $internal, 'created_at' => now()]);
        $status = $internal ? $t['status'] : (input('then') === 'close' ? 'closed' : 'answered');
        update('tickets', ['status' => $status, 'updated_at' => now(), 'assigned_to' => $t['assigned_to'] ?: $admin['id']], 'id = ?', [$t['id']]);
        if (!$internal) {
            send_mail($t['email'], 'ticket_reply', ['subject' => $t['subject'], 'ticket_id' => $t['id'], 'message' => input('body'), 'link' => site_url('/support.php?id=' . $t['id'])], (int)$t['user_id']);
            notify((int)$t['user_id'], "Support replied: {$t['subject']}", mb_substr(input('body'), 0, 140), 'info', 'support.php?id=' . $t['id']);
        }
        audit($internal ? 'ticket_note' : 'ticket_reply', 'ticket', $t['id']);
    } elseif ($act === 'meta') {
        update('tickets', ['status' => in_array(input('status'), ['open', 'answered', 'closed'], true) ? input('status') : $t['status'],
            'priority' => in_array(input('priority'), ['low', 'normal', 'high', 'urgent'], true) ? input('priority') : $t['priority'],
            'assigned_to' => input_int('assigned_to') ?: null, 'updated_at' => now()], 'id = ?', [$t['id']]);
        audit('ticket_update', 'ticket', $t['id'], ['status' => input('status'), 'priority' => input('priority')]);
    } elseif ($act === 'canned_save' && input('title')) {
        insert('canned_replies', ['title' => input('title'), 'body' => input('body'), 'created_at' => now()]);
    } elseif ($act === 'canned_delete') {
        q('DELETE FROM canned_replies WHERE id = ?', [input_int('canned')]);
    }
    flash('Saved.', 'success');
    redirect('ticket.php?id=' . $t['id']);
}
$msgs = rows('SELECT m.*, a.name AS aname FROM ticket_messages m LEFT JOIN admins a ON a.id = m.admin_id WHERE ticket_id = ? ORDER BY m.id', [$t['id']]);
$canned = rows('SELECT * FROM canned_replies ORDER BY title');
$admins = rows('SELECT id, name, email FROM admins WHERE active = 1');
$lic = row("SELECT license_key, status, expires_at FROM licenses WHERE user_id = ? ORDER BY FIELD(status,'active','issued','suspended','expired','revoked'), id DESC LIMIT 1", [$t['user_id']]);
admin_start("Ticket #{$t['id']}", 'tickets.php');
?>
<p><a href="tickets.php">← All tickets</a></p>
<div class="grid" style="grid-template-columns: minmax(0,2fr) minmax(260px,1fr)">
<div class="card"><h2><?= e($t['subject']) ?></h2>
  <div class="chat"><?php foreach ($msgs as $m): ?>
    <div class="msg <?= $m['author'] === 'admin' ? 'me' : '' ?>" style="<?= $m['internal'] ? 'border:1px dashed var(--amber)' : '' ?>"><?= e($m['body']) ?>
      <div class="meta"><?= $m['author'] === 'admin' ? '🛟 ' . e($m['aname'] ?: 'Admin') . ($m['internal'] ? ' · internal note' : '') : '👤 ' . e($t['email']) ?> · <?= local_dt($m['created_at']) ?></div></div>
  <?php endforeach; ?></div>
  <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="action" value="reply">
    <?php if ($canned): ?><select onchange="if(this.value){const b=document.getElementById('reply');b.value=(b.value?b.value+'\n\n':'')+this.value;this.value=''}"><option value="">Insert saved reply…</option>
      <?php foreach ($canned as $c): ?><option value="<?= e($c['body']) ?>"><?= e($c['title']) ?></option><?php endforeach; ?></select><br><br><?php endif; ?>
    <textarea id="reply" name="body" required placeholder="Write your reply. The user gets it by email and in the portal."></textarea>
    <div class="actions" style="margin-top:8px"><label class="check"><input type="checkbox" name="internal"> Internal note (user won't see)</label>
      <select name="then" style="width:auto"><option value="answered">Then: mark answered</option><option value="close">Then: close ticket</option></select><button class="btn">Send</button></div></form>
</div>
<div>
  <div class="card"><h2>Details</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="meta">
    <label>Status</label><select name="status"><?php foreach (['open', 'answered', 'closed'] as $s): ?><option <?= $t['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <label>Priority</label><select name="priority"><?php foreach (['low', 'normal', 'high', 'urgent'] as $s): ?><option <?= $t['priority'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
    <label>Assigned to</label><select name="assigned_to"><option value="0">Nobody</option><?php foreach ($admins as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)$t['assigned_to'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name'] ?: $a['email']) ?></option><?php endforeach; ?></select>
    <br><br><button class="btn btn-sm">Update</button></form>
    <dl class="kv" style="margin-top:14px"><dt>User</dt><dd><?= user_link((int)$t['user_id'], $t['email']) ?></dd><dt>Topic</dt><dd><?= e($t['category']) ?></dd><dt>Opened</dt><dd><?= local_dt($t['created_at']) ?></dd>
      <dt>License</dt><dd><?= $lic ? '<code class="small">' . e($lic['license_key']) . '</code> ' . badge(effective_status($lic)) : 'None' ?></dd><?php if ($t['rating']): ?><dt>Rating</dt><dd><?= str_repeat('⭐', (int)$t['rating']) ?></dd><?php endif; ?></dl>
    <?php if (can('impersonate')): ?><form method="post" action="user.php?id=<?= (int)$t['user_id'] ?>" target="_blank"><?= csrf_field() ?><input type="hidden" name="action" value="impersonate"><button class="btn btn-sm btn-ghost">🕵 View as user</button></form><?php endif; ?></div>
  <div class="card"><h2>Saved replies</h2>
    <?php foreach ($canned as $c): ?><form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="action" value="canned_delete"><input type="hidden" name="canned" value="<?= (int)$c['id'] ?>"><span class="small" style="flex:1"><?= e($c['title']) ?></span><button class="btn btn-sm btn-ghost">🗑</button></form><?php endforeach; ?>
    <details><summary class="small">➕ Add saved reply</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="canned_save"><input name="title" placeholder="Title"><textarea name="body" placeholder="Reply text"></textarea><button class="btn btn-sm">Save</button></form></details></div>
</div></div>
<?php admin_end();
