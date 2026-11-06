<?php
require __DIR__ . '/_init.php';
$admin = require_admin('admins');   // superadmin only (not in other roles)

if (admin_post('admins')) {
    $act = input('action');
    $id = input_int('id');
    $role = in_array(input('role'), ['superadmin', 'admin', 'support', 'viewer'], true) ? input('role') : 'viewer';
    if ($act === 'create') {
        $email = strtolower(input('email'));
        $pw = $_POST['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || val('SELECT 1 FROM admins WHERE email = ?', [$email])) flash('Email invalid or already an admin.', 'danger');
        elseif (strlen($pw) < 10) flash('Password needs 10+ characters.', 'danger');
        else {
            $nid = insert('admins', ['email' => $email, 'name' => input('name'), 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => $role, 'created_at' => now()]);
            audit('admin_create', 'admin', $nid, "$email ($role)");
            flash("Admin $email created.", 'success');
        }
    } elseif ($id === (int)$admin['id'] && in_array($act, ['role', 'toggle', 'delete'], true)) {
        flash("You can't change your own role, disable or delete yourself.", 'danger');
    } elseif ($act === 'role') {
        update('admins', ['role' => $role], 'id = ?', [$id]); audit('admin_role', 'admin', $id, $role); flash('Role updated.', 'success');
    } elseif ($act === 'toggle') {
        q('UPDATE admins SET active = 1 - active WHERE id = ?', [$id]);
        q("UPDATE sessions SET revoked = 1 WHERE kind = 'admin' AND owner_id = ?", [$id]);
        audit('admin_toggle', 'admin', $id); flash('Updated.', 'success');
    } elseif ($act === 'reset_password') {
        $pw = $_POST['password'] ?? '';
        if (strlen($pw) < 10) flash('Password needs 10+ characters.', 'danger');
        else { update('admins', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'failed_logins' => 0, 'locked_until' => null], 'id = ?', [$id]);
            q("UPDATE sessions SET revoked = 1 WHERE kind = 'admin' AND owner_id = ?", [$id]); audit('admin_password_reset', 'admin', $id); flash('Password reset.', 'success'); }
    } elseif ($act === 'reset_2fa') {
        update('admins', ['totp_enabled' => 0, 'totp_secret' => null], 'id = ?', [$id]); audit('admin_2fa_reset', 'admin', $id); flash('2FA reset.', 'success');
    } elseif ($act === 'delete') {
        if ((int)val("SELECT COUNT(*) FROM admins WHERE role = 'superadmin' AND active = 1 AND id <> ?", [$id]) < 1) flash('Keep at least one active superadmin.', 'danger');
        else { q('DELETE FROM admins WHERE id = ?', [$id]); audit('admin_delete', 'admin', $id); flash('Admin deleted.', 'success'); }
    }
    redirect('admins.php');
}
$list = rows("SELECT a.*, (SELECT COUNT(*) FROM audit_logs l WHERE l.admin_id = a.id) AS actions FROM admins a ORDER BY a.id");
admin_start('Admins');
?>
<div class="card"><h2>Roles</h2><ul class="small muted"><li><b>Superadmin</b> — everything, incl. admins, database writes, security & desktop settings.</li>
  <li><b>Admin</b> — everything except managing admins and writing to the database.</li><li><b>Support</b> — users, view-as-user, licenses, payments approval, tickets, tracking.</li><li><b>Viewer</b> — read-only dashboard, live monitor, reports, tracking.</li></ul></div>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Admin</th><th>Role</th><th>2FA</th><th>Last sign-in</th><th>Actions logged</th><th>Status</th><th>Manage</th></tr></thead><tbody>
<?php foreach ($list as $a): ?><tr><td><?= e($a['email']) ?><div class="small muted"><?= e($a['name']) ?></div></td>
  <td><form method="post" class="input-group"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><select name="role" style="padding:5px"><?php foreach (['superadmin', 'admin', 'support', 'viewer'] as $r): ?><option <?= $a['role'] === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-ghost">✓</button></form></td>
  <td><?= $a['totp_enabled'] ? '🔐 on' : '<span class="muted">off</span>' ?></td><td class="small"><?= local_dt($a['last_login_at']) ?><div class="muted"><?= e($a['last_login_ip']) ?></div></td>
  <td><a href="audit.php?admin=<?= (int)$a['id'] ?>"><?= (int)$a['actions'] ?></a></td><td><?= $a['active'] ? badge('active') : '<span class="badge badge-red">Disabled</span>' ?></td>
  <td class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-ghost" name="action" value="toggle"><?= $a['active'] ? 'Disable' : 'Enable' ?></button>
      <?php if ($a['totp_enabled']): ?><button class="btn btn-sm btn-ghost" name="action" value="reset_2fa" data-confirm="Reset this admin's 2FA?">Reset 2FA</button><?php endif; ?>
      <button class="btn btn-sm btn-ghost" name="action" value="delete" data-confirm="Delete this admin?">🗑</button></form>
    <form method="post" class="input-group"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="password" name="password" placeholder="New password" style="padding:5px;width:130px"><button class="btn btn-sm btn-ghost">Set</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card" style="max-width:640px"><h2>➕ Add admin</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="form-row"><div><label>Email</label><input name="email" type="email" required></div><div><label>Name</label><input name="name"></div></div>
  <div class="form-row"><div><label>Password (10+)</label><input name="password" type="password" required minlength="10"></div><div><label>Role</label><select name="role"><option>support</option><option>admin</option><option>viewer</option><option>superadmin</option></select></div></div>
  <br><button class="btn">Create admin</button></form></div>
<?php admin_end();
