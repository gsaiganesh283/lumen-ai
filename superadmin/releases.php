<?php
require __DIR__ . '/_init.php';
require_admin('releases');
$dir = HM_DATA . '/releases';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$upload_dir = HM_DATA . '/release_uploads';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0700, true);

if (admin_post('releases')) {
    $act = input('action');
    if ($act === 'chunk_start') {
        header('Content-Type: application/json');
        $size = max(0, (int)($_POST['size'] ?? 0));
        $ext = strtolower(pathinfo($_POST['filename'] ?? '', PATHINFO_EXTENSION));
        if ($size <= 0 || $size > 200 * 1024 * 1024 || $ext !== 'exe') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Only .exe files up to 200 MB are allowed.']);
            exit;
        }
        $token = bin2hex(random_bytes(24));
        $meta = ['filename' => basename($_POST['filename']), 'size' => $size, 'version' => preg_replace('/[^0-9A-Za-z.\-]/', '', $_POST['version'] ?? ''),
            'notes' => trim((string)($_POST['notes'] ?? '')), 'latest' => !empty($_POST['latest']), 'chunks' => []];
        if ($meta['version'] === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Enter a version number.']);
            exit;
        }
        file_put_contents($upload_dir . '/' . $token . '.json', json_encode($meta), LOCK_EX);
        echo json_encode(['ok' => true, 'token' => $token]);
        exit;
    } elseif ($act === 'chunk') {
        header('Content-Type: application/json');
        $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
        $index = max(0, (int)($_POST['index'] ?? -1));
        $meta_path = $upload_dir . '/' . $token . '.json';
        $f = $_FILES['chunk'] ?? null;
        if (strlen($token) !== 48 || !is_file($meta_path) || !$f || !is_uploaded_file($f['tmp_name']) || $f['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid upload chunk.']);
            exit;
        }
        $meta = json_decode((string)file_get_contents($meta_path), true);
        $chunk_path = $upload_dir . '/' . $token . '-' . $index . '.part';
        if (file_put_contents($chunk_path, (string)file_get_contents($f['tmp_name']), LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Cannot store upload chunk.']);
            exit;
        }
        $meta['chunks'][(string)$index] = filesize($chunk_path);
        file_put_contents($meta_path, json_encode($meta), LOCK_EX);
        echo json_encode(['ok' => true, 'index' => $index]);
        exit;
    } elseif ($act === 'chunk_finish') {
        header('Content-Type: application/json');
        $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
        $meta_path = $upload_dir . '/' . $token . '.json';
        if (strlen($token) !== 48 || !is_file($meta_path)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Upload session expired.']);
            exit;
        }
        $meta = json_decode((string)file_get_contents($meta_path), true);
        $final = $upload_dir . '/' . $token . '.exe';
        $out = fopen($final, 'wb');
        if (!$out) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Cannot assemble uploaded file.']);
            exit;
        }
        $total = 0;
        for ($i = 0; ; $i++) {
            $part = $upload_dir . '/' . $token . '-' . $i . '.part';
            if (!is_file($part)) break;
            $total += (int)filesize($part);
            $in = fopen($part, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        if ($total !== (int)$meta['size']) {
            @unlink($final);
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Upload is incomplete. Please retry.']);
            exit;
        }
        $version = $meta['version'];
        $name = 'InterviewLens-' . $version . '.exe';
        $target = $dir . '/' . $name;
        if (!rename($final, $target)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Cannot publish uploaded file.']);
            exit;
        }
        $id = insert('app_releases', ['version' => $version, 'filename' => $name, 'size_bytes' => $total, 'sha256' => hash_file('sha256', $target),
            'notes' => $meta['notes'], 'created_at' => now()]);
        if (!empty($meta['latest'])) {
            q('UPDATE app_releases SET is_latest = (id = ?)', [$id]);
            settings_set(['desktop_latest_version' => $version]);
        }
        foreach (glob($upload_dir . '/' . $token . '*') ?: [] as $tmp) @unlink($tmp);
        audit('release_upload', 'release', $version, $name);
        echo json_encode(['ok' => true, 'name' => $name]);
        exit;
    } elseif ($act === 'upload') {
        $f = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
        $version = preg_replace('/[^0-9A-Za-z.\-]/', '', input('version'));
        if (!$f || !is_uploaded_file($f['tmp_name']) || !in_array($ext, ['exe', 'msi', 'zip'], true)) flash('Upload an .exe, .msi or .zip file.', 'danger');
        elseif ($version === '') flash('Enter a version number.', 'danger');
        else {
            $name = 'InterviewLens-Setup-' . $version . '.' . $ext;
            move_uploaded_file($f['tmp_name'], "$dir/$name");
            $id = insert('app_releases', ['version' => $version, 'filename' => $name, 'size_bytes' => filesize("$dir/$name"), 'sha256' => hash_file('sha256', "$dir/$name"),
                'notes' => input('notes'), 'created_at' => now()]);
            if (isset($_POST['latest'])) {
                q('UPDATE app_releases SET is_latest = (id = ?)', [$id]);
                settings_set(['desktop_latest_version' => $version]);
            }
            audit('release_upload', 'release', $version, $name);
            flash("Uploaded $name.", 'success');
        }
    } elseif ($act === 'latest') {
        $r = row('SELECT * FROM app_releases WHERE id = ?', [input_int('id')]);
        if ($r) { q('UPDATE app_releases SET is_latest = (id = ?)', [$r['id']]); settings_set(['desktop_latest_version' => $r['version']]); audit('release_latest', 'release', $r['version']); flash("v{$r['version']} is now the download.", 'success'); }
    } elseif ($act === 'min') {
        $r = row('SELECT * FROM app_releases WHERE id = ?', [input_int('id')]);
        if ($r) { settings_set(['desktop_min_version' => $r['version']]); audit('release_min', 'release', $r['version']); flash("Apps older than v{$r['version']} must update.", 'success'); }
    } elseif ($act === 'delete') {
        $r = row('SELECT * FROM app_releases WHERE id = ?', [input_int('id')]);
        if ($r) { @unlink("$dir/" . basename($r['filename'])); q('DELETE FROM app_releases WHERE id = ?', [$r['id']]); audit('release_delete', 'release', $r['version']); flash('Release deleted.', 'success'); }
    }
    redirect('releases.php');
}
$list = rows('SELECT * FROM app_releases ORDER BY id DESC');
$versions = rows("SELECT app_version v, COUNT(*) c FROM devices WHERE app_version <> '' GROUP BY app_version ORDER BY c DESC");
admin_start('App releases');
?>
<div class="grid grid-2">
<div class="card"><h2>⬆ Upload desktop EXE</h2><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
  <label>File (.exe up to 200 MB)</label><input id="release-file" type="file" required accept=".exe"><label>Version</label><input id="release-version" name="version" placeholder="1.0.1" required>
  <label>What's new</label><textarea id="release-notes" name="notes"></textarea><label class="check"><input id="release-latest" type="checkbox" name="latest" checked> Make this the download for users</label><button id="release-upload-btn" class="btn" type="button">Upload EXE</button><progress id="release-progress" value="0" max="100" style="width:100%;display:none"></progress><p id="release-upload-status" class="hint"></p></form>
  <p class="hint">Large files use resumable 1.5 MB chunks, so this works with a 2 MB PHP upload limit. Maximum file size: 200 MB.</p></div>
<div class="card"><h2>📊 Versions in use</h2><table class="compact"><?php foreach ($versions as $v): ?><tr><td>v<?= e($v['v']) ?></td><td><?= (int)$v['c'] ?> devices</td></tr><?php endforeach; ?><?php if (!$versions): ?><tr><td class="muted">No app check-ins yet.</td></tr><?php endif; ?></table>
  <p class="small">Latest: <b>v<?= e(setting('desktop_latest_version')) ?></b> · Minimum: <b>v<?= e(setting('desktop_min_version')) ?></b></p></div>
</div>
<div class="card"><div class="table-wrap"><table class="compact"><thead><tr><th>Version</th><th>File</th><th>Size</th><th>Downloads</th><th>Uploaded</th><th></th></tr></thead><tbody>
<?php foreach ($list as $r): ?><tr><td><b>v<?= e($r['version']) ?></b> <?= $r['is_latest'] ? badge('active') : '' ?></td><td><code><?= e($r['filename']) ?></code><div class="small muted cell-trunc">SHA-256 <?= e($r['sha256']) ?></div></td>
  <td><?= number_format($r['size_bytes'] / 1048576, 1) ?> MB</td><td><?= (int)$r['downloads'] ?></td><td class="small"><?= local_dt($r['created_at']) ?></td>
  <td class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?php if (!$r['is_latest']): ?><button class="btn btn-sm btn-ghost" name="action" value="latest">Make latest</button><?php endif; ?>
    <button class="btn btn-sm btn-ghost" name="action" value="min" data-confirm="Force every app older than this to update?">Set as minimum</button><button class="btn btn-sm btn-ghost" name="action" value="delete" data-confirm="Delete this release?">🗑</button></form></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="6" class="empty">No releases uploaded. Users see "not published yet" on the download page.</td></tr><?php endif; ?></tbody></table></div></div>
<script>
(() => {
  const file = document.getElementById('release-file'), button = document.getElementById('release-upload-btn');
  const progress = document.getElementById('release-progress'), status = document.getElementById('release-upload-status');
  const csrf = document.querySelector('input[name="csrf"]').value, size = 1.5 * 1024 * 1024;
  button.addEventListener('click', async () => {
    if (!file.files[0]) { status.textContent = 'Choose an EXE first.'; return; }
    const f = file.files[0];
    if (!/\.exe$/i.test(f.name) || f.size > 200 * 1024 * 1024) { status.textContent = 'Choose an EXE up to 200 MB.'; return; }
    button.disabled = true; progress.style.display = 'block';
    try {
      const start = new FormData(); start.append('csrf', csrf); start.append('action', 'chunk_start');
      start.append('filename', f.name); start.append('size', f.size); start.append('version', document.getElementById('release-version').value);
      start.append('notes', document.getElementById('release-notes').value); if (document.getElementById('release-latest').checked) start.append('latest', '1');
      const session = await fetch('releases.php', {method:'POST', body:start}).then(r => r.json());
      if (!session.ok) throw new Error(session.error || 'Could not start upload.');
      const total = Math.ceil(f.size / size);
      for (let i = 0; i < total; i++) {
        const part = new FormData(); part.append('csrf', csrf); part.append('action', 'chunk'); part.append('token', session.token); part.append('index', i);
        part.append('chunk', f.slice(i * size, Math.min(f.size, (i + 1) * size)), f.name + '.part');
        const result = await fetch('releases.php', {method:'POST', body:part}).then(r => r.json());
        if (!result.ok) throw new Error(result.error || 'Chunk upload failed.');
        progress.value = Math.round(((i + 1) / total) * 100); status.textContent = `Uploading ${i + 1} of ${total} chunks...`;
      }
      const finish = new FormData(); finish.append('csrf', csrf); finish.append('action', 'chunk_finish'); finish.append('token', session.token);
      const result = await fetch('releases.php', {method:'POST', body:finish}).then(r => r.json());
      if (!result.ok) throw new Error(result.error || 'Could not publish release.');
      status.textContent = 'Upload complete. Refreshing...'; location.reload();
    } catch (e) { status.textContent = e.message; button.disabled = false; }
  });
})();
</script>
<?php admin_end();
