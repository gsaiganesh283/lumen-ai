<?php
require __DIR__ . '/_init.php';
require_admin('view');

// One-row quick action from the ⋯ menu ("id:action") reuses the bulk code.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/^(\d+):([a-z]+)$/', input('row'), $m)) {
    $_POST['ids'] = [$m[1]];
    $_POST['bulk'] = $m[2];
    $_POST['days'] = $_POST['days'] ?? '7';
}
// Bulk actions on selected users.
if (admin_post('users')) {
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $act = input('bulk');
    if (!$ids) { flash('Select at least one user.', 'danger'); redirect($_SERVER['REQUEST_URI']); }
    $in = implode(',', array_fill(0, count($ids), '?'));
    switch ($act) {
        case 'verify': q("UPDATE users SET email_verified = 1, email_verified_at = COALESCE(email_verified_at, ?) WHERE id IN ($in)", array_merge([now()], $ids)); break;
        case 'activate': q("UPDATE users SET status = 'active', status_reason = '' WHERE id IN ($in) AND status <> 'deleted'", $ids); break;
        case 'suspend': q("UPDATE users SET status = 'suspended', status_reason = ? WHERE id IN ($in) AND status <> 'deleted'", array_merge([input('reason') ?: 'Suspended by admin'], $ids)); break;
        case 'ban':
            q("UPDATE users SET status = 'banned', status_reason = ? WHERE id IN ($in) AND status <> 'deleted'", array_merge([input('reason') ?: 'Banned by admin'], $ids));
            q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id IN ($in)", $ids);
            break;
        case 'logout': q("UPDATE sessions SET revoked = 1 WHERE kind = 'user' AND owner_id IN ($in)", $ids); break;
        case 'tag':
            $tag = preg_replace('/[^a-z0-9_-]/i', '', input('reason'));
            if ($tag) foreach ($ids as $id) q("UPDATE users SET tags = TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(tags,''), ?)) WHERE id = ? AND FIND_IN_SET(?, tags) = 0", [$tag, $id, $tag]);
            break;
        case 'grant':
            $days = max(1, input_int('days', 1));
            foreach ($ids as $id) {
                $lic = row("SELECT * FROM licenses WHERE user_id = ? AND status IN ('active','issued') ORDER BY expires_at DESC LIMIT 1", [$id]);
                if ($lic) extend_license($lic, $days, 'admin:' . $ADMIN['id']);
                else issue_license($id, $days, null, 'admin', 'admin:' . $ADMIN['id']);
                notify($id, "🎁 $days free days added", 'A gift from the ' . setting('site_name') . ' team.', 'success', 'licenses.php');
            }
            break;
        case 'delete':
            if ($ADMIN['role'] !== 'superadmin') { flash('Only superadmins can delete users.', 'danger'); redirect($_SERVER['REQUEST_URI']); }
            q("DELETE FROM users WHERE id IN ($in)", $ids);
            break;
        default: flash('Choose an action.', 'danger'); redirect($_SERVER['REQUEST_URI']);
    }
    audit('bulk_' . $act, 'user', implode(',', $ids), input('reason'));
    flash('Done: ' . $act . ' for ' . count($ids) . ' user(s).', 'success');
    redirect($_SERVER['REQUEST_URI']);
}

// Filters
$where = ['1=1'];
$p = [];
if (($s = input('q')) !== '') {
    $where[] = '(u.email LIKE ? OR u.name LIKE ? OR u.phone LIKE ? OR u.referral_code = ? OR u.id = ? OR u.last_login_ip = ? OR u.signup_ip = ?)';
    array_push($p, "%$s%", "%$s%", "%$s%", strtoupper($s), (int)$s, $s, $s);
}
if (($v = input('status')) !== '') { $where[] = 'u.status = ?'; $p[] = $v; }
if (($v = input('verified')) !== '') { $where[] = 'u.email_verified = ?'; $p[] = $v === 'yes' ? 1 : 0; }
if (($v = input('method')) !== '') { $where[] = 'u.signup_method = ?'; $p[] = $v; }
if (($v = input('tag')) !== '') { $where[] = 'FIND_IN_SET(?, u.tags)'; $p[] = $v; }
if (($v = input('plan')) === 'active') { $where[] = "EXISTS (SELECT 1 FROM licenses l WHERE l.user_id = u.id AND l.status = 'active' AND l.expires_at > ?)"; $p[] = now(); }
if ($v === 'none') $where[] = 'NOT EXISTS (SELECT 1 FROM licenses l WHERE l.user_id = u.id)';
if ($v === 'expired') { $where[] = "EXISTS (SELECT 1 FROM licenses l WHERE l.user_id = u.id AND l.status = 'expired') AND NOT EXISTS (SELECT 1 FROM licenses l WHERE l.user_id = u.id AND l.status = 'active' AND l.expires_at > ?)"; $p[] = now(); }
if (($v = input('from')) !== '') { $where[] = 'u.created_at >= ?'; $p[] = $v . ' 00:00:00'; }
if (($v = input('to')) !== '') { $where[] = 'u.created_at <= ?'; $p[] = $v . ' 23:59:59'; }
if (input('online') === '1') { $where[] = 'u.last_seen_at > ?'; $p[] = ts_plus('-5 minutes'); }
$w = implode(' AND ', $where);
$sorts = ['new' => 'u.id DESC', 'old' => 'u.id ASC', 'seen' => 'u.last_seen_at DESC', 'spent' => 'spent DESC', 'email' => 'u.email ASC'];
$sort = $sorts[input('sort')] ?? $sorts['new'];
$select = "SELECT u.*, (SELECT COALESCE(SUM(amount_paise),0) FROM orders o WHERE o.user_id = u.id AND o.status = 'paid') AS spent,
    (SELECT MAX(expires_at) FROM licenses l WHERE l.user_id = u.id AND l.status = 'active') AS plan_end,
    (SELECT COUNT(*) FROM devices d WHERE d.user_id = u.id AND d.status <> 'removed') AS devices
    FROM users u WHERE $w";

if (input('export') === 'csv') {
    audit('export_users', 'user', '', $w);
    $out = (function () use ($select, $sort, $p) {
        foreach (q("$select ORDER BY $sort", $p) as $r) {
            yield [$r['id'], $r['email'], $r['name'], $r['phone'], $r['country'], $r['signup_method'], $r['email_verified'] ? 'yes' : 'no', $r['status'],
                $r['tags'], $r['spent'] / 100, $r['plan_end'], $r['devices'], $r['created_at'], $r['last_login_at'], $r['last_login_ip']];
        }
    })();
    csv_download('users-' . gmdate('Ymd') . '.csv', ['ID', 'Email', 'Name', 'Phone', 'Country', 'Signup', 'Verified', 'Status', 'Tags', 'Spent (INR)', 'Plan ends (UTC)', 'Devices', 'Created (UTC)', 'Last login (UTC)', 'Last IP'], $out);
}

$total = (int)val("SELECT COUNT(*) FROM users u WHERE $w", $p);
[$lim, $off, $page, $pages] = paginate($total, 25);
$users = rows("$select ORDER BY $sort LIMIT $lim OFFSET $off", $p);
$tags = array_unique(array_filter(explode(',', implode(',', array_column(rows("SELECT tags FROM users WHERE tags <> '' LIMIT 500"), 'tags')))));
admin_start('Users');
?>
<form class="filters">
  <input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Email, name, phone, ID, IP, referral code">
  <?= filter_select('status', ['active' => 'Active', 'suspended' => 'Suspended', 'banned' => 'Banned', 'deleted' => 'Deleted'], 'Any status') ?>
  <?= filter_select('verified', ['yes' => 'Verified', 'no' => 'Not verified'], 'Verified?') ?>
  <?= filter_select('method', ['password' => 'Email sign-up', 'google' => 'Google', 'admin' => 'Created by admin'], 'Any sign-up') ?>
  <?= filter_select('plan', ['active' => 'Has active plan', 'expired' => 'Expired only', 'none' => 'Never bought'], 'Any plan') ?>
  <?php if ($tags): ?><?= filter_select('tag', array_combine($tags, $tags), 'Any tag') ?><?php endif; ?>
  <?= filter_select('sort', ['new' => 'Newest', 'old' => 'Oldest', 'seen' => 'Last seen', 'spent' => 'Top spenders', 'email' => 'Email A–Z'], 'Sort') ?>
  <input type="date" name="from" value="<?= e(input('from')) ?>" title="Joined from"><input type="date" name="to" value="<?= e(input('to')) ?>" title="Joined to">
  <label class="check"><input type="checkbox" name="online" value="1" <?= input('online') ? 'checked' : '' ?> onchange="this.form.submit()"> Online now</label>
  <button class="btn btn-sm">Filter</button><a class="btn btn-sm btn-ghost" href="users.php">Reset</a>
  <a class="btn btn-sm btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">⬇ CSV</a>
  <?php if (can('users')): ?><a class="btn btn-sm btn-green" href="user.php?new=1">➕ New user</a><?php endif; ?>
</form>
<form method="post" class="card" data-needs-selection="1"><?= csrf_field() ?>
<?php if (can('users')): ?>
<div class="bulk-bar">With selected:
  <select name="bulk"><option value="">Choose action…</option><option value="verify">✅ Mark email verified</option><option value="activate">▶ Activate</option>
    <option value="suspend">⏸ Suspend</option><option value="ban">⛔ Ban + sign out</option><option value="logout">🚪 Sign out everywhere</option>
    <option value="tag">🏷 Add tag (use reason box)</option><option value="grant">🎁 Give free days</option><?php if ($ADMIN['role'] === 'superadmin'): ?><option value="delete">🗑 Delete permanently</option><?php endif; ?></select>
  <input name="reason" placeholder="Reason / tag"><input name="days" type="number" min="1" value="3" style="width:80px" title="Days for 'Give free days'">
  <button class="btn btn-sm" data-confirm="Apply this action to the selected users?">Apply</button></div>
<?php endif; ?>
<div class="table-wrap"><table class="compact"><thead><tr><th><input type="checkbox" data-bulk-all></th><th>User</th><th>Sign-up</th><th>Status</th><th>Plan ends</th><th>Spent</th><th>Devices</th><th>Joined</th><th>Last seen</th><th></th></tr></thead><tbody>
<?php if (!$users): ?><tr><td colspan="10" class="empty">No users match.</td></tr><?php endif; ?>
<?php foreach ($users as $r): $on = $r['last_seen_at'] && $r['last_seen_at'] > ts_plus('-5 minutes'); ?>
<tr><td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" data-bulk></td>
  <td><?= $on ? '<span class="live-dot"></span> ' : '' ?><a href="user.php?id=<?= (int)$r['id'] ?>"><?= e($r['email']) ?></a><div class="small muted"><?= e($r['name']) ?> · #<?= (int)$r['id'] ?><?= $r['tags'] ? ' · 🏷 ' . e($r['tags']) : '' ?></div></td>
  <td><?= $r['signup_method'] === 'google' ? '🟢 Google' : ($r['signup_method'] === 'admin' ? '🛡 Admin' : '✉️ Email') ?><div><?= $r['email_verified'] ? badge('verified') : badge('unverified') ?></div></td>
  <td><?= badge($r['status']) ?><?= $r['totp_enabled'] ? ' 🔐' : '' ?></td>
  <td><?= $r['plan_end'] ? local_dt($r['plan_end'], false) : '<span class="muted">—</span>' ?></td><td><?= money($r['spent']) ?></td><td><?= (int)$r['devices'] ?></td>
  <td class="small"><?= local_dt($r['created_at'], false) ?></td><td class="small"><?= time_ago($r['last_seen_at']) ?></td>
  <td class="actions"><a class="btn btn-sm btn-ghost" href="user.php?id=<?= (int)$r['id'] ?>">Open</a>
    <details class="menu"><summary class="kebab" title="Quick actions">⋯</summary><div class="menu-list">
      <a href="user.php?id=<?= (int)$r['id'] ?>&tab=licenses">🔑 Licenses</a><a href="user.php?id=<?= (int)$r['id'] ?>&tab=activity">🛰 Activity</a>
      <a href="document.php?type=user&id=<?= (int)$r['id'] ?>" target="_blank">🖨 Print customer file</a>
      <a href="document.php?type=statement&id=<?= (int)$r['id'] ?>" target="_blank">📄 Statement</a>
      <button type="button" data-copy="<?= e($r['email']) ?>">📋 Copy email</button>
      <?php if (can('impersonate') && $r['status'] !== 'deleted'): ?><button form="rowimp" formaction="user.php?id=<?= (int)$r['id'] ?>" formtarget="_blank">🕵 Login as user</button><?php endif; ?>
      <?php if (can('users')): ?><hr>
        <?php if (!$r['email_verified']): ?><button form="rowact" name="row" value="<?= (int)$r['id'] ?>:verify">✅ Mark verified</button><?php endif; ?>
        <button form="rowact" name="row" value="<?= (int)$r['id'] ?>:grant">🎁 Give 7 free days</button>
        <button form="rowact" name="row" value="<?= (int)$r['id'] ?>:logout">🚪 Sign out everywhere</button>
        <?php if ($r['status'] === 'active'): ?><button form="rowact" name="row" value="<?= (int)$r['id'] ?>:suspend">⏸ Suspend</button>
          <button form="rowact" name="row" value="<?= (int)$r['id'] ?>:ban" class="danger" data-confirm="Ban <?= e($r['email']) ?> and sign them out?">⛔ Ban</button>
        <?php else: ?><button form="rowact" name="row" value="<?= (int)$r['id'] ?>:activate">▶ Re-activate</button><?php endif; ?>
      <?php endif; ?>
    </div></details></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?= pager($page, $pages, $total) ?>
</form>
<form id="rowact" method="post"><?= csrf_field() ?></form>
<form id="rowimp" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="impersonate"><input type="hidden" name="tab" value="overview"></form>
<?php admin_end();
