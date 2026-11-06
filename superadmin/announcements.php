<?php
require __DIR__ . '/_init.php';
require_admin('announcements');
$loc = fn($v) => $v ? gmdate('Y-m-d H:i:s', strtotime("$v UTC") - setting('timezone_offset_minutes') * 60) : null;
$toLocal = fn($v) => $v ? gmdate('Y-m-d\TH:i', utc_ts($v) + setting('timezone_offset_minutes') * 60) : '';
if (admin_post('announcements')) {
    $id = input_int('id');
    if (input('action') === 'save') {
        $data = ['title' => mb_substr(input('title'), 0, 150), 'body' => input('body'), 'level' => in_array(input('level'), ['info', 'success', 'warning', 'danger'], true) ? input('level') : 'info',
            'target' => in_array(input('target'), ['portal', 'desktop', 'both'], true) ? input('target') : 'both', 'active' => isset($_POST['active']) ? 1 : 0,
            'starts_at' => $loc(input('starts_at')), 'ends_at' => $loc(input('ends_at'))];
        if ($id) update('announcements', $data, 'id = ?', [$id]); else $id = insert('announcements', $data + ['created_at' => now()]);
        audit('announcement_save', 'announcement', $id, $data['title']);
        flash('Announcement saved.', 'success');
    } elseif (input('action') === 'toggle') q('UPDATE announcements SET active = 1 - active WHERE id = ?', [$id]);
    elseif (input('action') === 'delete') { q('DELETE FROM announcements WHERE id = ?', [$id]); audit('announcement_delete', 'announcement', $id); }
    redirect('announcements.php');
}
$list = rows('SELECT * FROM announcements ORDER BY id DESC');
$edit = input_int('edit') ? row('SELECT * FROM announcements WHERE id = ?', [input_int('edit')]) : null;
admin_start('Announcements');
?>
<p class="muted">Banners shown at the top of the user portal and/or inside the desktop app (up to 3 at a time).</p>
<div class="card" id="form"><h2><?= $edit ? 'Edit announcement' : '➕ New announcement' ?></h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <label>Title</label><input name="title" required value="<?= e($edit['title'] ?? '') ?>"><label>Message</label><textarea name="body"><?= e($edit['body'] ?? '') ?></textarea>
  <div class="form-row"><div><label>Style</label><select name="level"><?php foreach (['info', 'success', 'warning', 'danger'] as $l): ?><option <?= ($edit['level'] ?? '') === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div><label>Show in</label><select name="target"><?php foreach (['both' => 'Portal + desktop app', 'portal' => 'User portal only', 'desktop' => 'Desktop app only'] as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['target'] ?? 'both') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div><label>Starts</label><input type="datetime-local" name="starts_at" value="<?= $toLocal($edit['starts_at'] ?? null) ?>"></div><div><label>Ends</label><input type="datetime-local" name="ends_at" value="<?= $toLocal($edit['ends_at'] ?? null) ?>"></div></div>
  <label class="check"><input type="checkbox" name="active" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>> Active</label><button class="btn">Save</button></form></div>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Title</th><th>Where</th><th>Window</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($list as $a): ?><tr><td><div class="alert alert-<?= e($a['level']) ?>" style="margin:0;padding:6px 10px"><b><?= e($a['title']) ?></b> <?= e(mb_substr((string)$a['body'], 0, 120)) ?></div></td><td><?= e($a['target']) ?></td>
  <td class="small"><?= $a['starts_at'] ? local_dt($a['starts_at']) : 'now' ?> → <?= $a['ends_at'] ? local_dt($a['ends_at']) : 'forever' ?></td><td><?= $a['active'] ? badge('active') : '<span class="badge badge-gray">Off</span>' ?></td>
  <td class="actions"><a class="btn btn-sm btn-ghost" href="announcements.php?edit=<?= (int)$a['id'] ?>#form">Edit</a><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-ghost" name="action" value="toggle"><?= $a['active'] ? 'Turn off' : 'Turn on' ?></button><button class="btn btn-sm btn-ghost" name="action" value="delete" data-confirm="Delete?">🗑</button></form></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="5" class="empty">No announcements.</td></tr><?php endif; ?></tbody></table></div></div>
<?php admin_end();
