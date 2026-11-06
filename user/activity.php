<?php
require __DIR__ . '/_init.php';
$u = require_login(false);
$total = (int)val('SELECT COUNT(*) FROM activity_events WHERE user_id = ?', [$u['id']]);
[$lim, $off, $page, $pages] = paginate($total, 30);
$events = rows("SELECT kind, label, path, ip, user_agent, source, created_at FROM activity_events WHERE user_id = ? ORDER BY id DESC LIMIT $lim OFFSET $off", [$u['id']]);
page_start('My activity', 'activity.php');
?>
<div class="card"><p class="muted small">Everything recorded on your account: sign-ins, pages, payments and desktop app check-ins. See the <a href="privacy.php">Privacy Policy</a>.</p>
<div class="table-wrap"><table><thead><tr><th>Time</th><th>Event</th><th>Details</th><th>From</th></tr></thead><tbody>
<?php foreach ($events as $ev): ?>
<tr><td style="white-space:nowrap"><?= local_dt($ev['created_at']) ?></td><td><?= e(event_label($ev['kind'])) ?></td>
  <td class="small"><?= e($ev['label'] ?: $ev['path']) ?></td><td class="small muted"><?= e($ev['ip']) ?> · <?= e($ev['source'] === 'desktop' ? 'Desktop app' : short_agent($ev['user_agent'])) ?></td></tr>
<?php endforeach; ?>
<?php if (!$events): ?><tr><td colspan="4" class="empty">No activity yet.</td></tr><?php endif; ?>
</tbody></table></div><?= pager($page, $pages, $total) ?></div>
<?php page_end();
