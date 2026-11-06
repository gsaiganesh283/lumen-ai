<?php
require __DIR__ . '/_init.php';
require_admin('coupons');

$plans = rows("SELECT code, name, active FROM plans ORDER BY sort, days");

if (admin_post('coupons')) {
    $act = input('action');
    $id = input_int('id');
    $loc = fn($v) => $v ? gmdate('Y-m-d H:i:s', strtotime("$v UTC") - setting('timezone_offset_minutes') * 60) : null;
    $planCode = '';
    if (in_array($act, ['save', 'generate'], true)) {
        $planCode = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', input('plan_code')));
        if ($planCode === '' || !in_array($planCode, array_column($plans, 'code'), true)) {
            flash('Select a valid plan for this coupon.', 'danger');
            redirect('coupons.php');
        }
    }
    $data = ['kind' => input('kind') === 'flat' ? 'flat' : 'percent', 'max_uses' => max(0, input_int('max_uses')), 'per_user_limit' => max(0, input_int('per_user_limit', 1)),
        'plan_codes' => $planCode, 'valid_from' => $loc(input('valid_from')), 'valid_to' => $loc(input('valid_to')),
        'active' => isset($_POST['active']) ? 1 : 0, 'note' => mb_substr(input('note'), 0, 200)];
    $data['value'] = $data['kind'] === 'flat' ? (int)round((float)input('value') * 100) : max(0, min(100, input_int('value')));
    if ($act === 'save') {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', input('code_prefix')));
        $suffix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', input('code_suffix')));
        $code = "$prefix-$suffix";
        if (!preg_match('/^[A-Z0-9]{6}-[A-Z0-9]{3}$/', $code)) { flash('Coupon code must contain six generated characters and three manual characters.', 'danger'); redirect('coupons.php'); }
        if (val('SELECT 1 FROM coupons WHERE code = ? AND id <> ?', [$code, $id])) { flash('Code already exists.', 'danger'); redirect('coupons.php'); }
        if ($id) update('coupons', $data + ['code' => $code], 'id = ?', [$id]); else insert('coupons', $data + ['code' => $code, 'created_at' => now()]);
        audit('coupon_save', 'coupon', $code, $data);
        flash("Coupon $code saved.", 'success');
    } elseif ($act === 'generate') {
        $n = max(1, min(500, input_int('count', 10)));
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', input('prefix'))) ?: 'HM';
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            do { $c = $prefix . random_code(6); } while (val('SELECT 1 FROM coupons WHERE code = ?', [$c]));
            insert('coupons', array_merge($data, ['code' => $c, 'max_uses' => 1, 'per_user_limit' => 1, 'created_at' => now()]));
            $codes[] = $c;
        }
        audit('coupon_generate', 'coupon', $prefix, "$n codes");
        if (isset($_POST['download'])) csv_download("coupons-$prefix.csv", ['Code'], array_map(fn($c) => [$c], $codes));
        flash("Generated $n single-use codes.", 'success');
    } elseif ($act === 'toggle') { q('UPDATE coupons SET active = 1 - active WHERE id = ?', [$id]); }
    elseif ($act === 'delete') { q('DELETE FROM coupons WHERE id = ?', [$id]); audit('coupon_delete', 'coupon', $id); flash('Deleted.', 'success'); }
    redirect('coupons.php');
}
$w = '1=1'; $p = [];
if (($s = input('q')) !== '') { $w = '(code LIKE ? OR note LIKE ?)'; $p = ["%$s%", "%$s%"]; }
$total = (int)val("SELECT COUNT(*) FROM coupons WHERE $w", $p);
[$lim, $off, $page, $pages] = paginate($total, 30);
$list = rows("SELECT c.*, (SELECT COALESCE(SUM(discount_paise),0) FROM orders o WHERE o.coupon_code = c.code AND o.status = 'paid') AS saved FROM coupons c WHERE $w ORDER BY id DESC LIMIT $lim OFFSET $off", $p);
$edit = input_int('edit') ? row('SELECT * FROM coupons WHERE id = ?', [input_int('edit')]) : null;
$toLocal = fn($v) => $v ? gmdate('Y-m-d\TH:i', utc_ts($v) + setting('timezone_offset_minutes') * 60) : '';
$codeParts = explode('-', $edit['code'] ?? '', 2);
$codePrefix = count($codeParts) === 2 && strlen($codeParts[0]) === 6 ? $codeParts[0] : random_code(6);
$codeSuffix = count($codeParts) === 2 && strlen($codeParts[1]) === 3 ? $codeParts[1] : '';
$fields = function ($c) use ($toLocal, $plans) {
  $selectedPlan = trim(explode(',', $c['plan_codes'] ?? '')[0]);
  ob_start(); ?>
  <div class="form-row"><div><label>Type</label><select name="kind"><option value="percent" <?= ($c['kind'] ?? '') === 'percent' ? 'selected' : '' ?>>% off</option><option value="flat" <?= ($c['kind'] ?? '') === 'flat' ? 'selected' : '' ?>>₹ off</option></select></div>
    <div><label>Value (% or ₹)</label><input name="value" type="number" step="0.01" value="<?= isset($c['value']) ? ($c['kind'] === 'flat' ? $c['value'] / 100 : $c['value']) : 10 ?>"></div>
    <div><label>Max total uses (0 = ∞)</label><input name="max_uses" type="number" value="<?= (int)($c['max_uses'] ?? 0) ?>"></div>
    <div><label>Uses per user</label><input name="per_user_limit" type="number" value="<?= (int)($c['per_user_limit'] ?? 1) ?>"></div></div>
  <div class="form-row"><div><label>Plan</label><select name="plan_code" required><option value="">Select a plan</option><?php foreach ($plans as $plan): ?><option value="<?= e($plan['code']) ?>" <?= $selectedPlan === $plan['code'] ? 'selected' : '' ?>><?= e($plan['name']) ?> (<?= e($plan['code']) ?>)<?= !$plan['active'] ? ' [Hidden]' : '' ?></option><?php endforeach; ?></select></div>
    <div><label>Valid from</label><input type="datetime-local" name="valid_from" value="<?= $toLocal($c['valid_from'] ?? null) ?>"></div>
    <div><label>Valid to</label><input type="datetime-local" name="valid_to" value="<?= $toLocal($c['valid_to'] ?? null) ?>"></div></div>
  <label>Internal note</label><input name="note" value="<?= e($c['note'] ?? '') ?>">
  <label class="check"><input type="checkbox" name="active" <?= ($c['active'] ?? 1) ? 'checked' : '' ?>> Active</label>
<?php return ob_get_clean(); };
admin_start('Coupons');
?>
<div class="grid grid-2">
<div class="card" id="form"><h2><?= $edit ? 'Edit ' . e($edit['code']) : '➕ New coupon' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <label>Code</label><div class="form-row"><div><input name="code_prefix" value="<?= e($codePrefix) ?>" readonly class="mono"></div><div><input value="-" readonly class="mono" aria-label="Code separator"></div><div><input name="code_suffix" value="<?= e($codeSuffix) ?>" maxlength="3" minlength="3" pattern="[A-Za-z0-9]{3}" placeholder="3 characters" required class="mono" style="text-transform:uppercase"></div></div><?= $fields($edit ?? []) ?><button class="btn">Save</button></form></div>
<div class="card"><h2>🎲 Bulk-generate single-use codes</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generate">
  <div class="form-row"><div><label>Prefix</label><input name="prefix" value="HM"></div><div><label>How many</label><input name="count" type="number" value="20" min="1" max="500"></div></div><?= $fields([]) ?>
  <label class="check"><input type="checkbox" name="download" checked> Download as CSV</label><button class="btn">Generate</button></form></div>
</div>
<div class="card"><form class="filters"><input type="search" name="q" value="<?= e(input('q')) ?>" placeholder="Search code or note"><button class="btn btn-sm">Search</button></form>
<div class="table-wrap"><table class="compact"><thead><tr><th>Code</th><th>Discount</th><th>Used</th><th>Saved users</th><th>Plans</th><th>Valid</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($list as $c): ?><tr><td><code><?= e($c['code']) ?></code><div class="small muted"><?= e($c['note']) ?></div></td><td><?= $c['kind'] === 'percent' ? (int)$c['value'] . '%' : money($c['value']) ?></td>
  <td><?= (int)$c['used_count'] ?> / <?= $c['max_uses'] ?: '∞' ?></td><td><?= money($c['saved']) ?></td><td><?= e($c['plan_codes'] ?: 'All') ?></td>
  <td class="small"><?= $c['valid_from'] ? local_dt($c['valid_from'], false) : '…' ?> → <?= $c['valid_to'] ? local_dt($c['valid_to'], false) : '…' ?></td>
  <td><?= $c['active'] ? badge('active') : '<span class="badge badge-gray">Off</span>' ?></td>
  <td class="actions"><a class="btn btn-sm btn-ghost" href="coupons.php?edit=<?= (int)$c['id'] ?>#form">Edit</a><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <button class="btn btn-sm btn-ghost" name="action" value="toggle"><?= $c['active'] ? 'Disable' : 'Enable' ?></button><button class="btn btn-sm btn-ghost" name="action" value="delete" data-confirm="Delete coupon?">🗑</button></form></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="8" class="empty">No coupons yet.</td></tr><?php endif; ?></tbody></table></div><?= pager($page, $pages, $total) ?></div>
<?php admin_end();
