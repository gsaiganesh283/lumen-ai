<?php
require __DIR__ . '/_init.php';
require_admin('plans');

if (admin_post('plans')) {
    $act = input('action');
    $id = input_int('id');
    $data = ['code' => strtoupper(preg_replace('/[^A-Z0-9_]/i', '', input('code'))), 'name' => mb_substr(input('name'), 0, 80), 'days' => max(1, input_int('days')),
        'price_paise' => (int)round((float)input('price') * 100), 'mrp_paise' => (int)round((float)input('mrp') * 100), 'badge' => mb_substr(input('badge'), 0, 30),
        'description' => mb_substr(input('description'), 0, 255), 'features' => input('features'), 'sort' => input_int('sort'), 'active' => isset($_POST['active']) ? 1 : 0];
    if ($act === 'save') {
        if ($data['code'] === '' || $data['name'] === '' || $data['price_paise'] < 0) { flash('Code, name and price are required.', 'danger'); redirect('plans.php'); }
        if (val('SELECT 1 FROM plans WHERE code = ? AND id <> ?', [$data['code'], $id])) { flash('That code is already used.', 'danger'); redirect('plans.php'); }
        if ($id) update('plans', $data, 'id = ?', [$id]); else $id = insert('plans', $data + ['created_at' => now()]);
        audit('plan_save', 'plan', $id, $data);
        flash('Plan saved.', 'success');
    } elseif ($act === 'toggle') {
        q('UPDATE plans SET active = 1 - active WHERE id = ?', [$id]);
        audit('plan_toggle', 'plan', $id);
    } elseif ($act === 'duplicate') {
        $p = row('SELECT * FROM plans WHERE id = ?', [$id]);
        unset($p['id']);
        $p['code'] .= '_COPY' . random_code(2); $p['name'] .= ' (copy)'; $p['active'] = 0; $p['created_at'] = now();
        insert('plans', $p);
        flash('Plan duplicated (hidden until you activate it).', 'success');
    } elseif ($act === 'delete') {
        if (val('SELECT 1 FROM orders WHERE plan_id = ?', [$id]) || val('SELECT 1 FROM licenses WHERE plan_id = ?', [$id])) flash('This plan has orders — hide it instead of deleting.', 'danger');
        else { q('DELETE FROM plans WHERE id = ?', [$id]); audit('plan_delete', 'plan', $id); flash('Plan deleted.', 'success'); }
    }
    redirect('plans.php');
}
$plans = rows("SELECT p.*, (SELECT COUNT(*) FROM orders o WHERE o.plan_id = p.id AND o.status = 'paid') AS sold,
    (SELECT COALESCE(SUM(amount_paise),0) FROM orders o WHERE o.plan_id = p.id AND o.status = 'paid') AS revenue FROM plans p ORDER BY sort, days");
$edit = input_int('edit') ? row('SELECT * FROM plans WHERE id = ?', [input_int('edit')]) : null;
admin_start('Plans');
?>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Order</th><th>Plan</th><th>Days</th><th>Price</th><th>Per day</th><th>Sold</th><th>Revenue</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($plans as $p): ?><tr><td><?= (int)$p['sort'] ?></td><td><b><?= e($p['name']) ?></b> <code class="small"><?= e($p['code']) ?></code> <?= $p['badge'] ? '<span class="badge badge-purple">' . e($p['badge']) . '</span>' : '' ?><div class="small muted"><?= e($p['description']) ?></div></td>
  <td><?= (int)$p['days'] ?></td><td><?= money($p['price_paise']) ?><?= $p['mrp_paise'] ? ' <s class="muted">' . money($p['mrp_paise']) . '</s>' : '' ?></td><td><?= money((int)round($p['price_paise'] / max(1, $p['days']))) ?></td>
  <td><?= (int)$p['sold'] ?></td><td><?= money($p['revenue']) ?></td><td><?= $p['active'] ? badge('active') : '<span class="badge badge-gray">Hidden</span>' ?></td>
  <td class="actions"><a class="btn btn-sm btn-ghost" href="plans.php?edit=<?= (int)$p['id'] ?>#form">Edit</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-ghost" name="action" value="toggle"><?= $p['active'] ? 'Hide' : 'Show' ?></button>
      <button class="btn btn-sm btn-ghost" name="action" value="duplicate">Copy</button><button class="btn btn-sm btn-ghost" name="action" value="delete" data-confirm="Delete this plan?">🗑</button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<div class="card" id="form"><h2><?= $edit ? 'Edit plan: ' . e($edit['name']) : '➕ New plan' ?></h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="form-row"><div><label>Code</label><input name="code" value="<?= e($edit['code'] ?? '') ?>" required placeholder="30D"></div><div><label>Name</label><input name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
    <div><label>Days</label><input name="days" type="number" min="1" value="<?= (int)($edit['days'] ?? 30) ?>" required></div><div><label>Sort order</label><input name="sort" type="number" value="<?= (int)($edit['sort'] ?? 0) ?>"></div></div>
  <div class="form-row"><div><label>Price (₹)</label><input name="price" type="number" step="0.01" min="0" value="<?= isset($edit) ? $edit['price_paise'] / 100 : '' ?>" required></div>
    <div><label>Crossed-out price (₹)</label><input name="mrp" type="number" step="0.01" min="0" value="<?= isset($edit) ? $edit['mrp_paise'] / 100 : '' ?>"></div>
    <div><label>Badge</label><input name="badge" value="<?= e($edit['badge'] ?? '') ?>" placeholder="Popular"></div></div>
  <label>Short description</label><input name="description" value="<?= e($edit['description'] ?? '') ?>">
  <label>Features (one per line)</label><textarea name="features"><?= e($edit['features'] ?? PLAN_FEATURES) ?></textarea>
  <label class="check"><input type="checkbox" name="active" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>> Visible to users</label>
  <button class="btn">Save plan</button> <?php if ($edit): ?><a class="btn btn-ghost" href="plans.php">Cancel</a><?php endif; ?></form></div>
<?php admin_end();
