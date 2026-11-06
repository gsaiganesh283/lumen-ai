<?php
/** Send in-app notifications and/or emails to a group of users. */
require __DIR__ . '/_init.php';
require_admin('emails');

const SEGMENTS = [
    'all' => 'All active users', 'active_plan' => 'Users with an active plan', 'expiring7' => 'Plan expiring in 7 days', 'expired' => 'Plan expired (no active plan)',
    'never_paid' => 'Verified users who never bought', 'unverified' => 'Unverified emails', 'inactive30' => 'Not seen for 30+ days', 'tag' => 'Users with tag…', 'emails' => 'Specific emails…',
];

function segment_query(string $seg, string $extra): array {
    $base = "SELECT id, email, name, marketing_emails FROM users WHERE status = 'active'";
    switch ($seg) {
        case 'active_plan': return ["$base AND EXISTS (SELECT 1 FROM licenses l WHERE l.user_id = users.id AND l.status = 'active' AND l.expires_at > ?)", [now()]];
        case 'expiring7': return ["$base AND EXISTS (SELECT 1 FROM licenses l WHERE l.user_id = users.id AND l.status = 'active' AND l.expires_at BETWEEN ? AND ?)", [now(), ts_plus('+7 days')]];
        case 'expired': return ["$base AND EXISTS (SELECT 1 FROM licenses l WHERE l.user_id = users.id AND l.status = 'expired') AND NOT EXISTS (SELECT 1 FROM licenses l WHERE l.user_id = users.id AND l.status = 'active' AND l.expires_at > ?)", [now()]];
        case 'never_paid': return ["$base AND email_verified = 1 AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.user_id = users.id AND o.status = 'paid')", []];
        case 'unverified': return ["$base AND email_verified = 0", []];
        case 'inactive30': return ["$base AND (last_seen_at IS NULL OR last_seen_at < ?)", [ts_plus('-30 days')]];
        case 'tag': return ["$base AND FIND_IN_SET(?, tags)", [$extra]];
        case 'emails':
            $emails = array_values(array_filter(array_map(fn($x) => strtolower(trim($x)), preg_split('/[\s,;]+/', $extra))));
            if (!$emails) return ["$base AND 0", []];
            return ["$base AND email IN (" . implode(',', array_fill(0, count($emails), '?')) . ')', $emails];
        default: return [$base, []];
    }
}

if (admin_post('emails')) {
    [$sql, $p] = segment_query(input('segment'), input('extra'));
    $users = rows($sql, $p);
    if (input('action') === 'preview') {
        flash(count($users) . ' user(s) match: ' . implode(', ', array_slice(array_column($users, 'email'), 0, 10)) . (count($users) > 10 ? '…' : ''), 'info');
        redirect('notify.php?' . http_build_query(['segment' => input('segment'), 'extra' => input('extra')]));
    }
    $title = mb_substr(input('title'), 0, 150);
    if ($title === '') { flash('Title is required.', 'danger'); redirect('notify.php'); }
    $n = $m = 0;
    foreach ($users as $u) {
        if (isset($_POST['in_app'])) { notify((int)$u['id'], $title, input('body'), input('level', 'info'), input('link')); $n++; }
        if (isset($_POST['email']) && ($u['marketing_emails'] || isset($_POST['important']))) {
            send_mail($u['email'], 'broadcast', ['subject' => $title, 'message' => nl2br(e(input('body'))), 'name' => $u['name'] ?: 'there',
                'link' => site_url('/' . ltrim(input('link') ?: 'dashboard.php', '/'))], (int)$u['id'], true);   // queued, sent by cron in batches
            $m++;
        }
    }
    audit('broadcast', 'segment', input('segment'), "$title — $n notifications, $m emails");
    flash("Sent $n in-app notifications and queued $m emails (sent in batches of " . setting('emails_per_cron') . ' per minute).', 'success');
    redirect('notify.php');
}
admin_start('Notify & broadcast');
?>
<div class="card" style="max-width:820px"><form method="post"><?= csrf_field() ?>
  <div class="form-row"><div><label>Send to</label><select name="segment"><?php foreach (SEGMENTS as $k => $l): ?><option value="<?= $k ?>" <?= input('segment') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label>Tag or emails (for those options)</label><input name="extra" value="<?= e(input('extra')) ?>" placeholder="vip  or  a@x.com, b@y.com"></div></div>
  <label>Title / subject</label><input name="title" maxlength="150"><label>Message</label><textarea name="body"></textarea>
  <div class="form-row"><div><label>Style</label><select name="level"><option>info</option><option>success</option><option>warning</option><option>danger</option></select></div><div><label>Link (portal page)</label><input name="link" placeholder="plans.php"></div></div>
  <label class="check"><input type="checkbox" name="in_app" checked> In-app notification (bell in the portal)</label>
  <label class="check"><input type="checkbox" name="email"> Email (only to users who allow marketing emails…)</label>
  <label class="check"><input type="checkbox" name="important"> …or everyone, because this is an important service message</label>
  <div class="actions"><button class="btn btn-ghost" name="action" value="preview">👀 Preview recipients</button><button class="btn" name="action" value="send" data-confirm="Send to everyone in this group?">📣 Send</button></div></form></div>
<?php admin_end();
