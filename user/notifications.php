<?php
require __DIR__ . '/_init.php';
$u = require_login(false);
if (is_post()) {
    $a = input('action');
    if ($a === 'read_all') q('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), $u['id']]);
    if ($a === 'clear') q('DELETE FROM notifications WHERE user_id = ? AND read_at IS NOT NULL', [$u['id']]);
    if ($a === 'delete') q('DELETE FROM notifications WHERE id = ? AND user_id = ?', [input_int('id'), $u['id']]);
    if ($a === 'unread') q('UPDATE notifications SET read_at = NULL WHERE id = ? AND user_id = ?', [input_int('id'), $u['id']]);
    if ($a === 'open') {
        $n = row('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [input_int('id'), $u['id']]);
        if ($n) {
            q('UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ?', [now(), $n['id']]);
            if ($n['link'] && !preg_match('#^(https?:)?//#i', $n['link'])) redirect($n['link']);
        }
    }
    redirect('notifications.php');
}
$total = (int)val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$u['id']]);
[$lim, $off, $page, $pages] = paginate($total, 20);
$list = rows("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT $lim OFFSET $off", [$u['id']]);
$icons = ['success' => '✅', 'warning' => '⏰', 'danger' => '⛔', 'info' => 'ℹ️'];
page_start('Notifications', 'notifications.php');
?>
<div class="card">
  <div class="card-head"><h2>🔔 Notifications</h2><div>
    <form method="post" style="display:inline"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" name="action" value="read_all">Mark all read</button></form>
    <form method="post" style="display:inline"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" name="action" value="clear">Clear read</button></form></div></div>
  <?php if (!$list): ?><div class="empty">You're all caught up ✨</div><?php endif; ?>
  <?php foreach ($list as $n): ?>
    <form method="post" class="notif <?= $n['read_at'] ? '' : 'unread' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
      <div style="font-size:22px"><?= $icons[$n['level']] ?? 'ℹ️' ?></div>
      <div style="flex:1"><b><?= e($n['title']) ?></b><div class="muted small"><?= e($n['body']) ?></div><div class="small muted"><?= time_ago($n['created_at']) ?></div></div>
      <?php if ($n['link'] || !$n['read_at']): ?><button class="btn btn-sm btn-ghost"><?= $n['link'] ? 'Open' : 'Mark read' ?></button><?php endif; ?>
      <?php if ($n['read_at']): ?><button class="icon-btn" name="action" value="unread" title="Mark unread">●</button><?php endif; ?>
      <button class="icon-btn" name="action" value="delete" title="Delete">🗑</button>
    </form>
  <?php endforeach; ?>
  <?= pager($page, $pages, $total) ?>
</div>
<?php page_end();
