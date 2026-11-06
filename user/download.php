<?php
require __DIR__ . '/_init.php';
$u = require_login();
$has_license = (bool)val("SELECT 1 FROM licenses WHERE user_id = ? AND status IN ('active','issued') AND (expires_at IS NULL OR expires_at > ?) LIMIT 1", [$u['id'], now()]);
if (!$has_license) {
    flash('An active license is required before downloading the desktop application.', 'warning');
    redirect('plans.php');
}
$rel = row('SELECT * FROM app_releases WHERE is_latest = 1 ORDER BY id DESC LIMIT 1');
$external = setting('download_url');

if (isset($_GET['get'])) {
    track('download', ['user_id' => (int)$u['id'], 'label' => 'Downloaded app ' . ($rel['version'] ?? 'external')]);
    if ($rel) {
        $path = HM_DATA . '/releases/' . basename($rel['filename']);
        if (is_file($path)) {
            q('UPDATE app_releases SET downloads = downloads + 1 WHERE id = ?', [$rel['id']]);
            end_buffers();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($rel['filename']) . '"');
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        }
    }
    if ($external) redirect($external);
    flash('The download is not available yet. Please contact support.', 'danger');
    redirect('download.php');
}
page_start('Download app', 'download.php');
?>
<div class="grid grid-2">
  <div class="card center">
    <div class="big-emoji">🖥️</div>
    <h2><?= e(setting('site_name')) ?> for Windows</h2>
    <?php if ($rel || $external): ?>
      <p class="muted">Version <?= e($rel['version'] ?? setting('desktop_latest_version')) ?><?= $rel ? ' · ' . number_format($rel['size_bytes'] / 1048576, 1) . ' MB' : '' ?></p>
      <a class="btn" href="download.php?get=1">⬇️ Download</a>
      <?php if ($rel && $rel['sha256']): ?><p class="hint">SHA-256: <code style="word-break:break-all"><?= e($rel['sha256']) ?></code></p><?php endif; ?>
      <?php if ($rel && $rel['notes']): ?><details style="text-align:left"><summary>What's new</summary><p class="muted" style="white-space:pre-wrap"><?= e($rel['notes']) ?></p></details><?php endif; ?>
    <?php else: ?>
      <div class="alert alert-warning">The installer hasn't been published yet. Please check back soon or contact support.</div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>Open in 4 steps</h2>
    <ol><li>There is no installer. Double-click the downloaded <b>.exe</b> file. If Windows SmartScreen appears, click <b>More info → Run anyway</b> after confirming it came from this portal.</li>
      <li>Open <?= e(setting('site_name')) ?> and click the 🔑 badge at the top left.</li>
      <li>Copy the <b>Device ID</b> and add it on the <a href="devices.php">Devices</a> page.</li>
      <li>Paste your key from <a href="licenses.php">Licenses</a> and click <b>Activate</b>.</li></ol>
    <h3>Requirements</h3>
    <ul class="muted small"><li>Windows 10 (2004+) or Windows 11, 64-bit</li><li>4 GB RAM, internet connection</li><li>Microphone / speakers or a Bluetooth headset</li><li>Keep the EXE in a safe folder; use the same file to open the app again.</li></ul>
  </div>
</div>
<?php page_end();
