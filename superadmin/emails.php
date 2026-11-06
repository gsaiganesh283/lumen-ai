<?php
require __DIR__ . '/_init.php';
$admin = require_admin('emails');

if (isset($_GET['frame'])) {   // email preview inside an iframe
    $m = row('SELECT body_html FROM email_logs WHERE id = ?', [input_int('frame')]);
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; frame-ancestors 'self'");
    header('X-Frame-Options: SAMEORIGIN');
    echo $m['body_html'] ?? '';
    exit;
}
if (isset($_GET['tpl_preview'])) {
    [$s, $h] = render_email(input('tpl_preview'), ['name' => 'Asha', 'link' => site_url('/'), 'key' => 'HM-ABCDE-FGHJK-LMNPQ-RSTUV', 'plan' => 'Pro', 'days' => 60,
        'days_left' => 3, 'expires' => local_dt(ts_plus('+3 days')), 'device' => 'Office laptop', 'order_no' => 'HMEXAMPLE01', 'amount' => money(80000), 'utr' => '412345678901',
        'reason' => 'Example reason', 'ip' => '203.0.113.5', 'agent' => 'Chrome on Windows', 'time' => local_dt(now()), 'subject' => 'Example subject', 'message' => 'Example message', 'ticket_id' => 42, 'status' => 'suspended']);
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; frame-ancestors 'self'");
    header('X-Frame-Options: SAMEORIGIN');
    echo $h;
    exit;
}

if (admin_post('emails')) {
    $act = input('action');
    if ($act === 'test') {
        $id = send_mail(input('to') ?: $admin['email'], 'test', ['time' => local_dt(now())]);
        $m = row('SELECT status, error FROM email_logs WHERE id = ?', [$id]);
        flash($m['status'] === 'sent' ? 'Test email sent ✅' : ($m['status'] === 'logged' ? 'Saved to the log only — turn on "Send real emails" in Settings → Email.' : 'Failed: ' . $m['error']),
            $m['status'] === 'failed' ? 'danger' : 'success');
    } elseif ($act === 'resend') {
        $ok = setting('email_enabled') ? deliver_logged_email(input_int('id')) : false;
        flash($ok ? 'Resent.' : 'Could not resend (is real sending on?).', $ok ? 'success' : 'danger');
    } elseif ($act === 'process') {
        run_cron(true);
        flash('Queue processed.', 'success');
    } elseif ($act === 'retry_failed') {
        q("UPDATE email_logs SET status = 'queued', attempts = 0 WHERE status = 'failed'");
        flash('Failed emails queued again.', 'success');
    } elseif ($act === 'purge') {
        q('DELETE FROM email_logs WHERE created_at < ?', [ts_plus('-' . max(7, input_int('days', 90)) . ' days')]);
        flash('Old email logs deleted.', 'success');
    } elseif ($act === 'tpl_save' && isset(EMAIL_TEMPLATES[input('key')])) {
        q('INSERT INTO email_templates (`key`, subject, body_html) VALUES (?,?,?) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html)',
            [input('key'), input('subject'), $_POST['body_html'] ?? '']);
        flash('Template saved.', 'success');
    } elseif ($act === 'tpl_reset') {
        q('DELETE FROM email_templates WHERE `key` = ?', [input('key')]);
        flash('Template reset to default.', 'success');
    }
    audit('email_' . $act, 'email', input('id') ?: input('key'));
    redirect($_SERVER['REQUEST_URI']);
}

$tab = input('tab', 'log');
admin_start('Emails');
?>
<div class="tabs"><a class="<?= $tab === 'log' ? 'active' : '' ?>" href="emails.php">📬 Log</a><a class="<?= $tab === 'templates' ? 'active' : '' ?>" href="emails.php?tab=templates">🧩 Templates</a><a class="<?= $tab === 'tools' ? 'active' : '' ?>" href="emails.php?tab=tools">🧪 Test & tools</a></div>
<?php if (!setting('email_enabled')): ?><div class="alert alert-warning">Real sending is OFF. Emails are saved here but not delivered. Set up Gmail in <a href="settings.php?tab=Email">Settings → Email</a>.</div><?php endif; ?>

<?php if ($tab === 'log'):
  if (input_int('view')) { $m = row('SELECT * FROM email_logs WHERE id = ?', [input_int('view')]); ?>
    <div class="card"><div class="card-head"><div><h2><?= e($m['subject']) ?></h2><div class="small muted">To <?= e($m['to_email']) ?> · <?= local_dt($m['created_at']) ?> · <?= badge($m['status']) ?> <?= e($m['error']) ?></div></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm" name="action" value="resend">Resend</button></form></div>
      <iframe src="emails.php?frame=<?= (int)$m['id'] ?>" sandbox style="width:100%;height:560px;border:1px solid var(--line);border-radius:10px;background:#fff"></iframe></div>
  <?php }
  $w = '1=1'; $p = [];
  if (($s = input('q')) !== '') { $w .= ' AND (to_email LIKE ? OR subject LIKE ?)'; array_push($p, "%$s%", "%$s%"); }
  if (($v = input('status')) !== '') { $w .= ' AND status = ?'; $p[] = $v; }
  if (($v = input('template')) !== '') { $w .= ' AND template = ?'; $p[] = $v; }
  $total = (int)val("SELECT COUNT(*) FROM email_logs WHERE $w", $p);
  [$lim, $off, $page, $pages] = paginate($total, 30);
  $list = rows("SELECT id, user_id, to_email, subject, template, status, error, attempts, created_at, sent_at FROM email_logs WHERE $w ORDER BY id DESC LIMIT $lim OFFSET $off", $p);
  $by = array_column(rows('SELECT status, COUNT(*) c FROM email_logs GROUP BY status'), 'c', 'status'); ?>
  <div class="grid grid-4"><?php foreach (['sent', 'queued', 'failed', 'logged'] as $s): ?><?= stat_card(ucfirst($s), number_format((int)($by[$s] ?? 0)), '', 'emails.php?status=' . $s) ?><?php endforeach; ?></div><br>
  <form class="filters"><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Recipient or subject"><?= filter_select('status', ['sent' => 'Sent', 'queued' => 'Queued', 'failed' => 'Failed', 'logged' => 'Logged only'], 'Any status') ?>
    <?= filter_select('template', array_combine(array_keys(EMAIL_TEMPLATES), array_keys(EMAIL_TEMPLATES)), 'Any template') ?><button class="btn btn-sm">Filter</button></form>
  <div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Template</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($list as $m): ?><tr><td class="small"><?= local_dt($m['created_at']) ?></td><td><?= $m['user_id'] ? user_link((int)$m['user_id'], $m['to_email']) : e($m['to_email']) ?></td>
    <td><a href="emails.php?view=<?= (int)$m['id'] ?>"><?= e($m['subject']) ?></a></td><td class="small"><?= e($m['template']) ?></td><td><?= badge($m['status']) ?><div class="small muted cell-trunc"><?= e($m['error']) ?></div></td></tr><?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="5" class="empty">No emails.</td></tr><?php endif; ?></tbody></table></div><?= pager($page, $pages, $total) ?></div>

<?php elseif ($tab === 'templates'):
  $key = input('key', 'verify_email'); [$subj, $body] = email_template($key);
  $custom = array_column(rows('SELECT `key` FROM email_templates'), 'key'); ?>
  <div class="grid" style="grid-template-columns:240px minmax(0,1fr)">
    <div class="card"><?php foreach (array_keys(EMAIL_TEMPLATES) as $k): ?><a style="display:block;padding:6px 8px;border-radius:8px;<?= $k === $key ? 'background:var(--accent-soft)' : '' ?>" href="emails.php?tab=templates&key=<?= e($k) ?>"><?= e($k) ?><?= in_array($k, $custom, true) ? ' ✏️' : '' ?></a><?php endforeach; ?></div>
    <div class="card"><h2><?= e($key) ?></h2><p class="hint">Use {{name}}, {{site_name}}, {{link}}, {{key}}, {{days}}, {{expires}}… and {{button}} for the action button. Text is escaped automatically.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>"><label>Subject</label><input name="subject" value="<?= e($subj) ?>">
        <label>Body (HTML)</label><textarea name="body_html" class="sql-box" style="min-height:220px"><?= e($body) ?></textarea>
        <div class="actions" style="margin-top:10px"><button class="btn" name="action" value="tpl_save">Save</button><?php if (in_array($key, $custom, true)): ?><button class="btn btn-ghost" name="action" value="tpl_reset" data-confirm="Reset to the default template?">Reset to default</button><?php endif; ?></div></form>
      <h3 style="margin-top:18px">Preview</h3><iframe src="emails.php?tpl_preview=<?= e($key) ?>" sandbox style="width:100%;height:480px;border:1px solid var(--line);border-radius:10px;background:#fff"></iframe></div></div>

<?php else: ?>
  <div class="grid grid-2">
    <div class="card"><h2>🧪 Send a test email</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test"><label>To</label><input name="to" type="email" value="<?= e($admin['email']) ?>"><br><br><button class="btn">Send test</button></form>
      <p class="hint">Gmail: use smtp.gmail.com, port 587, your Gmail address and a 16-character App Password (Google Account → Security → 2-Step Verification → App passwords).</p></div>
    <div class="card"><h2>⚙️ Queue tools</h2><div class="actions">
      <form method="post"><?= csrf_field() ?><button class="btn btn-ghost" name="action" value="process">▶ Send queued now</button></form>
      <form method="post"><?= csrf_field() ?><button class="btn btn-ghost" name="action" value="retry_failed">🔁 Retry failed</button></form></div>
      <form method="post" class="input-group" style="margin-top:12px"><?= csrf_field() ?><input type="number" name="days" value="90" min="7"><button class="btn btn-ghost" name="action" value="purge" data-confirm="Delete email logs older than this many days?">🧹 Delete logs older than (days)</button></form></div>
  </div>
<?php endif; ?>
<?php admin_end();
