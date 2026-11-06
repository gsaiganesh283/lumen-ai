<?php
require __DIR__ . '/_init.php';
require_admin('system');
if (admin_post('system')) {
    if (input('action') === 'cron') { $r = run_cron(true); flash('Scheduled jobs ran: ' . ($r ? implode(', ', $r) : 'nothing to do'), 'success'); audit('cron_run', 'system'); }
    if (input('action') === 'clear_log') { @file_put_contents(HM_DATA . '/logs/php_errors.log', ''); audit('error_log_clear', 'system'); flash('Error log cleared.', 'success'); }
    redirect('system.php');
}
$checks = [
    ['PHP version ≥ 8.1', version_compare(PHP_VERSION, '8.1', '>='), PHP_VERSION],
    ['PDO MySQL', extension_loaded('pdo_mysql'), ''], ['OpenSSL (encryption, license signing)', extension_loaded('openssl'), OPENSSL_VERSION_TEXT],
    ['cURL (Razorpay, Google)', extension_loaded('curl'), ''], ['zlib (backups)', function_exists('gzopen'), ''], ['fileinfo (uploads)', extension_loaded('fileinfo'), ''],
    ['mbstring', extension_loaded('mbstring'), ''],
    ['data/ folder writable', is_writable(HM_DATA), realpath(HM_DATA)], ['shared/config.php not writable by web (recommended)', !is_writable(__DIR__ . '/../shared/config.php'), 'set read-only after setup'],
    ['HTTPS', is_https(), is_https() ? 'on' : 'off (use HTTPS in production)'],
    ['Scheduled jobs ran in last 10 min', setting('last_cron') > time() - 600, setting('last_cron') ? time_ago(gmdate('Y-m-d H:i:s', setting('last_cron'))) : 'never'],
    ['Email sending configured', setting('email_enabled') && setting('smtp_user') !== '', setting('smtp_user') ?: 'not set'],
    ['Razorpay configured', setting('razorpay_enabled') && setting('razorpay_key_id') !== '', setting('razorpay_key_id') ?: 'not set'],
    ['UPI configured', setting('upi_enabled') && setting('upi_id') !== '', setting('upi_id') ?: 'not set'],
    ['Google sign-in configured', setting('google_client_id') !== '' && setting('google_client_secret') !== '', setting('google_client_id') ? 'client ID set' : 'not set'],
    ['License signing key present', is_file(HM_DATA . '/license_private.pem'), ''],
    ['Backup in last 48 h', (backup_list()[0]['time'] ?? 0) > time() - 172800, ($b = backup_list()[0] ?? null) ? $b['name'] : 'none'],
    ['Admins using 2FA', (int)val('SELECT COUNT(*) FROM admins WHERE active = 1 AND totp_enabled = 0') === 0, val('SELECT COUNT(*) FROM admins WHERE active = 1 AND totp_enabled = 0') . ' without 2FA'],
];
$log = HM_DATA . '/logs/php_errors.log';
$tail = is_file($log) ? implode('', array_slice(file($log) ?: [], -60)) : '';
$disk_free = @disk_free_space(HM_DATA);
admin_start('System health');
?>
<div class="grid grid-4">
  <?= stat_card('PHP', PHP_VERSION, PHP_OS_FAMILY . ' · ' . PHP_SAPI) ?><?= stat_card('Database', e((string)val('SELECT VERSION()')), number_format((int)val('SELECT SUM(data_length+index_length) FROM information_schema.tables WHERE table_schema = DATABASE()') / 1048576, 2) . ' MB') ?>
  <?= stat_card('Disk free', $disk_free ? number_format($disk_free / 1073741824, 1) . ' GB' : '?') ?><?= stat_card('Memory limit', e(ini_get('memory_limit')), 'upload max ' . e(ini_get('upload_max_filesize'))) ?>
</div><br>
<div class="card"><div class="card-head"><h2>🩺 Health checks</h2><form method="post"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="cron">▶ Run scheduled jobs now</button></form></div>
<table class="compact"><?php foreach ($checks as [$label, $ok, $info]): ?><tr><td><?= $ok ? '✅' : '⚠️' ?></td><td><?= e($label) ?></td><td class="small muted"><?= e($info) ?></td></tr><?php endforeach; ?></table>
<p class="hint">Scheduled jobs (expiry, reminders, email queue, cleanup, backups) run automatically while the site has visitors. For exact timing also add a Windows Task Scheduler job every 5 minutes: <code>C:\xampp\php\php.exe <?= e(realpath(__DIR__ . '/../shared/cron.php')) ?></code></p></div>
<div class="card"><div class="card-head"><h2>🐞 Error log (last 60 lines)</h2><form method="post"><?= csrf_field() ?><button class="btn btn-sm btn-ghost" name="action" value="clear_log" data-confirm="Clear the error log?">Clear</button></form></div>
  <div class="code-block"><?= $tail !== '' ? e($tail) : 'No errors logged 🎉' ?></div></div>
<?php admin_end();
