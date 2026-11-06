<?php
require __DIR__ . '/_init.php';
$admin = require_admin('tickets');
if (admin_post('tickets')) {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $act = input('bulk');
        if (in_array($act, ['open', 'answered', 'closed'], true)) q("UPDATE tickets SET status = ?, updated_at = ? WHERE id IN ($in)", array_merge([$act, now()], $ids));
        if ($act === 'assign_me') q("UPDATE tickets SET assigned_to = ? WHERE id IN ($in)", array_merge([$admin['id']], $ids));
        if ($act === 'delete' && $admin['role'] === 'superadmin') q("DELETE FROM tickets WHERE id IN ($in)", $ids);
        audit('ticket_bulk_' . $act, 'ticket', implode(',', $ids));
        flash('Updated ' . count($ids) . ' ticket(s).', 'success');
    }
    redirect($_SERVER['REQUEST_URI']);
}
$where = ['1=1']; $p = [];
$status = $_GET['status'] ?? 'open';
if ($status !== '') { $where[] = 't.status = ?'; $p[] = $status; }
if (($v = input('priority')) !== '') { $where[] = 't.priority = ?'; $p[] = $v; }
if (($v = input('category')) !== '') { $where[] = 't.category = ?'; $p[] = $v; }
if (input('mine') === '1') { $where[] = 't.assigned_to = ?'; $p[] = $admin['id']; }
if (($s = input('q')) !== '') { $where[] = '(t.subject LIKE ? OR u.email LIKE ? OR t.id = ?)'; array_push($p, "%$s%", "%$s%", (int)$s); }
$w = implode(' AND ', $where);
$total = (int)val("SELECT COUNT(*) FROM tickets t JOIN users u ON u.id = t.user_id WHERE $w", $p);
[$lim, $off, $page, $pages] = paginate($total, 30);
$list = rows("SELECT t.*, u.email, a.name AS assignee, (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id) AS n,
    (SELECT author FROM ticket_messages m WHERE m.ticket_id = t.id AND m.internal = 0 ORDER BY m.id DESC LIMIT 1) AS last_author
    FROM tickets t JOIN users u ON u.id = t.user_id LEFT JOIN admins a ON a.id = t.assigned_to WHERE $w
    ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.updated_at DESC LIMIT $lim OFFSET $off", $p);
$avg = val('SELECT ROUND(AVG(rating),1) FROM tickets WHERE rating IS NOT NULL');
admin_start('Support tickets');
?>
<div class="grid grid-4">
  <?= stat_card('Open', (int)val("SELECT COUNT(*) FROM tickets WHERE status = 'open'"), 'waiting for us', 'tickets.php?status=open') ?>
  <?= stat_card('Answered', (int)val("SELECT COUNT(*) FROM tickets WHERE status = 'answered'"), 'waiting for user', 'tickets.php?status=answered') ?>
  <?= stat_card('Closed (30d)', (int)val("SELECT COUNT(*) FROM tickets WHERE status = 'closed' AND updated_at >= ?", [ts_plus('-30 days')]), '', 'tickets.php?status=closed') ?>
  <?= stat_card('Satisfaction', $avg ? "$avg ⭐" : '—', 'average rating') ?>
</div><br>
<form class="filters"><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Subject, email or #">
  <?= filter_select('status', ['open' => 'Open', 'answered' => 'Answered', 'closed' => 'Closed'], 'Any status') ?>
  <?= filter_select('priority', ['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'], 'Any priority') ?>
  <?= filter_select('category', ['General' => 'General', 'Payment' => 'Payment', 'License' => 'License', 'Desktop app' => 'Desktop app', 'Account' => 'Account'], 'Any topic') ?>
  <label class="check"><input type="checkbox" name="mine" value="1" <?= input('mine') ? 'checked' : '' ?> onchange="this.form.submit()"> Assigned to me</label><button class="btn btn-sm">Filter</button></form>
<form method="post" class="card"><?= csrf_field() ?>
<div class="bulk-bar">With selected: <select name="bulk"><option value="assign_me">Assign to me</option><option value="answered">Mark answered</option><option value="closed">Close</option><option value="open">Reopen</option><?php if ($admin['role'] === 'superadmin'): ?><option value="delete">Delete</option><?php endif; ?></select><button class="btn btn-sm">Apply</button></div>
<div class="table-wrap"><table class="compact"><thead><tr><th><input type="checkbox" data-bulk-all></th><th>#</th><th>Subject</th><th>User</th><th>Topic</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Updated</th></tr></thead><tbody>
<?php foreach ($list as $t): ?><tr><td><input type="checkbox" name="ids[]" value="<?= (int)$t['id'] ?>" data-bulk></td><td><?= (int)$t['id'] ?></td>
  <td><a href="ticket.php?id=<?= (int)$t['id'] ?>"><?= e($t['subject']) ?></a> <span class="muted small">(<?= (int)$t['n'] ?>)</span><?= $t['last_author'] === 'user' && $t['status'] !== 'closed' ? ' <span class="badge badge-amber">needs reply</span>' : '' ?></td>
  <td><?= user_link((int)$t['user_id'], $t['email']) ?></td><td><?= e($t['category']) ?></td><td><?= badge($t['priority']) ?></td><td><?= badge($t['status']) ?><?= $t['rating'] ? ' ' . str_repeat('⭐', (int)$t['rating']) : '' ?></td>
  <td class="small"><?= e($t['assignee'] ?: '—') ?></td><td class="small"><?= time_ago($t['updated_at']) ?></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="9" class="empty">No tickets 🎉</td></tr><?php endif; ?></tbody></table></div><?= pager($page, $pages, $total) ?></form>
<?php admin_end();
