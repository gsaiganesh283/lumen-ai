<?php
require __DIR__ . '/_init.php';
$admin = require_admin('settings');

if (isset($_GET['pdf'])) {
    try {
        $path = manual_pdf_path();
        end_buffers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="InterviewLens-User-Manual.pdf"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    } catch (Throwable $e) {
        flash('PDF generation failed: ' . $e->getMessage(), 'danger');
        redirect('manual.php');
    }
}

if (admin_post('settings')) {
    if (input('action') === 'upload_manual_image') {
        header('Content-Type: application/json');
        $file = $_FILES['image'] ?? null;
        if (!$file || !is_uploaded_file($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Choose an image to upload.']);
            exit;
        }
        if ((int)$file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Images must be 5 MB or smaller.']);
            exit;
        }
        $info = @getimagesize($file['tmp_name']);
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Only PNG, JPG, GIF, and WebP images are allowed.']);
            exit;
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension === 'jpeg') $extension = 'jpg';
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        $path = manual_image_path($name);
        if (!$path || !move_uploaded_file($file['tmp_name'], $path)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not save the screenshot.']);
            exit;
        }
        echo json_encode(['ok' => true, 'src' => manual_image_src($name), 'name' => $name]);
        exit;
    }
    if (input('action') === 'autosave_manual') {
        header('Content-Type: application/json');
        $body = manual_clean_html((string)($_POST['body_html'] ?? ''));
        if ($body === '') {
            echo json_encode(['ok' => false, 'error' => 'Manual content cannot be empty.']);
            exit;
        }
        settings_set([
            'manual_title' => trim((string)($_POST['title'] ?? '')) ?: 'InterviewLens User Manual',
            'manual_intro' => trim((string)($_POST['intro'] ?? '')),
            'manual_body_html' => $body,
            'manual_accent_color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['accent'] ?? '')) ? $_POST['accent'] : '#e53935',
            'manual_pdf_orientation' => in_array($_POST['orientation'] ?? '', ['portrait', 'landscape'], true) ? $_POST['orientation'] : 'portrait',
            'manual_pdf_size' => in_array($_POST['size'] ?? '', ['A4', 'letter'], true) ? $_POST['size'] : 'A4',
        ]);
        echo json_encode(['ok' => true, 'at' => date('H:i:s')]);
        exit;
    }
    if (input('action') === 'save_manual') {
        $body = manual_clean_html((string)($_POST['body_html'] ?? ''));
        if ($body === '') {
            flash('Manual content cannot be empty.', 'danger');
        } else {
            settings_set([
                'manual_title' => trim((string)($_POST['title'] ?? '')) ?: 'InterviewLens User Manual',
                'manual_intro' => trim((string)($_POST['intro'] ?? '')),
                'manual_body_html' => $body,
                'manual_accent_color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['accent'] ?? '')) ? $_POST['accent'] : '#e53935',
                'manual_pdf_orientation' => in_array($_POST['orientation'] ?? '', ['portrait', 'landscape'], true) ? $_POST['orientation'] : 'portrait',
                'manual_pdf_size' => in_array($_POST['size'] ?? '', ['A4', 'letter'], true) ? $_POST['size'] : 'A4',
            ]);
            audit('manual_edit', 'manual', 'user_manual', 'Updated editable manual content');
            flash('Manual draft saved. Publish it when ready.', 'success');
        }
        redirect('manual.php');
    }
    try {
        $action = input('action');
        if ($action === 'publish_manual') {
            settings_set([
                'manual_published_at' => now(),
                'manual_published_title' => trim((string)setting('manual_title')),
                'manual_published_intro' => trim((string)setting('manual_intro')),
                'manual_published_body_html' => (string)setting('manual_body_html'),
                'manual_published_accent_color' => (string)setting('manual_accent_color'),
            ]);
            $published_path = HM_DATA . '/manual/interviewlens-user-manual.pdf';
            if (is_file($published_path)) {
                unlink($published_path);
            }
            manual_pdf_path(true);
            audit('manual_publish', 'manual', 'user_manual', 'Published PDF');
            flash('User manual HTML and PDF published successfully.', 'success');
        } elseif ($action === 'email_manual') {
            $path = HM_DATA . '/manual/interviewlens-user-manual.pdf';
            if (!setting('manual_published_at') || !is_file($path)) {
                throw new RuntimeException('Publish the manual PDF before emailing users.');
            }
            $sent = 0;
            foreach (rows("SELECT id, email, name FROM users WHERE status = 'active' AND email_verified = 1") as $user) {
                send_mail($user['email'], 'manual_published', [
                    'name' => $user['name'] ?: 'there',
                    'link' => manual_portal_url(),
                ], (int)$user['id'], false, [['path' => $path, 'name' => 'InterviewLens-User-Manual.pdf', 'type' => 'application/pdf']]);
                if (setting('email_enabled') && setting('smtp_user') !== '') $sent++;
            }
            audit('manual_email', 'manual', 'user_manual', 'Emailed published PDF to ' . $sent . ' users');
            flash('Manual email delivery started for ' . $sent . ' user(s).', 'success');
        }
    } catch (Throwable $e) {
        flash('Manual action failed: ' . $e->getMessage(), 'danger');
    }
    redirect('manual.php');
}

admin_start('User manual', 'manual.php');
$title = setting('manual_title') ?: 'InterviewLens User Manual';
$intro = setting('manual_intro');
$body = setting('manual_body_html') ?: manual_default_body();
?>
<div class="card" style="max-width:1100px">
  <h2>📘 User manual</h2>
  <p class="hint">Edit the user-facing manual below. Superadmin screens are excluded. Use headings, lists, links, tables, callouts, and code blocks. Red screenshot highlights are retained automatically.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_manual">
    <label>Main heading</label><input name="title" value="<?= e($title) ?>" maxlength="180" required>
    <label>Intro / subtitle</label><input name="intro" value="<?= e($intro) ?>" maxlength="500">
    <label>Manual content</label>
    <div class="actions" style="flex-wrap:wrap">
      <?php foreach (['h2'=>'Heading','h3'=>'Sub-heading','p'=>'Paragraph','strong'=>'Bold','em'=>'Italic','ul'=>'Bulleted list','ol'=>'Numbered list','blockquote'=>'Callout','pre'=>'Code block','hr'=>'Divider'] as $tag => $label): ?>
        <button type="button" class="btn btn-sm btn-ghost" data-insert-tag="<?= e($tag) ?>"><?= e($label) ?></button>
      <?php endforeach; ?>
      <label class="btn btn-sm btn-ghost" style="cursor:pointer">🖼 Add screenshot
        <input id="manual-image" type="file" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
      </label>
    </div>
    <textarea id="manual-body" name="body_html" class="sql-box" style="min-height:520px;font-family:Consolas,monospace"><?= e($body) ?></textarea>
    <p class="hint">Place the cursor between paragraphs before choosing <b>Add screenshot</b>. The image is inserted at that exact cursor position. Maximum 5 MB per image.</p>
    <div class="grid grid-2">
      <div><label>Screenshot/callout accent color</label><input type="color" name="accent" value="<?= e(setting('manual_accent_color') ?: '#e53935') ?>"></div>
      <div><label>PDF page size</label><select name="size"><option value="A4" <?= setting('manual_pdf_size') === 'A4' ? 'selected' : '' ?>>A4</option><option value="letter" <?= setting('manual_pdf_size') === 'letter' ? 'selected' : '' ?>>Letter</option></select></div>
      <div><label>PDF orientation</label><select name="orientation"><option value="portrait" <?= setting('manual_pdf_orientation') !== 'landscape' ? 'selected' : '' ?>>Portrait</option><option value="landscape" <?= setting('manual_pdf_orientation') === 'landscape' ? 'selected' : '' ?>>Landscape</option></select></div>
    </div>
    <div class="actions" style="align-items:center">
      <button class="btn">Save manual draft</button>
      <span id="autosave-status" class="hint" style="margin:0">Autosave on</span>
    </div>
  </form>
  <h3>Preview</h3><iframe id="manual-preview" style="width:100%;height:520px;background:#fff;border:1px solid #444"></iframe>
  <p>Last published: <b><?= e(setting('manual_published_at') ?: 'Not published') ?></b></p>
  <div class="actions"><a class="btn btn-ghost" href="<?= e(manual_preview_url()) ?>" target="_blank" rel="noopener">Preview in User portal</a>
    <a class="btn btn-ghost" href="manual.php?pdf=1">⬇ Download PDF</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="publish_manual"><button class="btn">Publish PDF and manual</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="email_manual"><button class="btn btn-ghost" data-confirm="Email the published PDF to all active verified users?">Email users</button></form></div>
</div>
<script>
(() => {
  const editor = document.getElementById('manual-body'), preview = document.getElementById('manual-preview');
  let imageInsertStart = 0, imageInsertEnd = 0;
  document.getElementById('manual-image').addEventListener('click', () => {
    imageInsertStart = editor.selectionStart;
    imageInsertEnd = editor.selectionEnd;
  });
  const titleInput = document.querySelector('input[name="title"]');
  const introInput = document.querySelector('input[name="intro"]');
  const esc = (s) => String(s).replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  const isFullDoc = () => /<(style|section|main|nav|article|header)\b/i.test(editor.value);
  const refresh = () => {
    const d = preview.contentDocument;
    d.open();
    if (isFullDoc()) {
      // Full HTML document: render as authored, then collapse any built-in
      // sidebar / two-column layout so it matches the published single column.
      const override = '<style>nav,aside,.toc,.sidebar,.side-menu,[data-role="toc"]{display:none!important}'
        + 'html,body{margin:0!important;padding:0!important;background:#f4f2fb!important}'
        + 'body{padding:26px 30px!important;box-sizing:border-box!important}'
        + '.wrap{display:block!important;grid-template-columns:none!important;max-width:1000px!important;margin:0 auto!important}'
        + 'main{max-width:none!important;width:auto!important;padding:0!important}'
        + 'section,.card,.cover,.hero,.note,.tip,.warn,figure{margin-left:0!important;margin-right:0!important}</style>';
      d.write(editor.value + override);
    } else {
      const head = '<h1>' + esc(titleInput ? titleInput.value : '') + '</h1>'
                 + '<p>' + esc(introInput ? introInput.value : '') + '</p>';
      d.write('<style>body{font:15px Arial;padding:24px;color:#222;overflow-wrap:anywhere}h1,h2{color:#4936b8}img{display:block;max-width:100%;max-height:620px;width:auto;height:auto;object-fit:contain}blockquote{border-left:4px solid #e53935;padding:10px;background:#fff1f1}</style>' + head + editor.value);
    }
    d.close();
  };
  editor.addEventListener('input', refresh);
  if (titleInput) titleInput.addEventListener('input', refresh);
  if (introInput) introInput.addEventListener('input', refresh);
  document.getElementById('manual-image').addEventListener('change', async (event) => {
    const file = event.target.files[0];
    if (!file) return;
    const form = new FormData();
    form.append('csrf', document.querySelector('input[name="csrf"]').value);
    form.append('action', 'upload_manual_image');
    form.append('image', file);
    try {
      const response = await fetch('manual.php', {method: 'POST', body: form});
      const result = await response.json();
      if (!result.ok) throw new Error(result.error || 'Screenshot upload failed.');
      const image = `<img src="${result.src}" alt="Manual screenshot" style="display:block;max-width:100%;max-height:620px;width:auto;height:auto;object-fit:contain">`;
      editor.setRangeText(image, imageInsertStart, imageInsertEnd, 'end');
      refresh();
      scheduleAutosave();
    } catch (error) {
      alert(error.message);
    } finally {
      event.target.value = '';
    }
  });
  // ── Autosave (debounced background save, no page reload) ──────────────────
  const statusEl = document.getElementById('autosave-status');
  const csrf = document.querySelector('input[name="csrf"]').value;
  const sizeSel = document.querySelector('select[name="size"]');
  const orientSel = document.querySelector('select[name="orientation"]');
  const accentInput = document.querySelector('input[name="accent"]');
  let saveTimer = null, saving = false, pending = false;
  const setStatus = (t) => { if (statusEl) statusEl.textContent = t; };

  async function doAutosave() {
    if (saving) { pending = true; return; }
    if (!editor.value.trim()) { setStatus('Not saved — content is empty'); return; }
    saving = true; setStatus('Saving…');
    const form = new FormData();
    form.append('csrf', csrf);
    form.append('action', 'autosave_manual');
    form.append('title', titleInput ? titleInput.value : '');
    form.append('intro', introInput ? introInput.value : '');
    form.append('body_html', editor.value);
    form.append('accent', accentInput ? accentInput.value : '#e53935');
    form.append('size', sizeSel ? sizeSel.value : 'A4');
    form.append('orientation', orientSel ? orientSel.value : 'portrait');
    try {
      const res = await fetch('manual.php', {method: 'POST', body: form});
      const data = await res.json();
      setStatus(data.ok ? ('Saved ' + (data.at || '')) : ('Not saved — ' + (data.error || 'error')));
    } catch (e) {
      setStatus('Not saved — offline');
    } finally {
      saving = false;
      if (pending) { pending = false; scheduleAutosave(); }
    }
  }
  function scheduleAutosave() {
    setStatus('Editing…');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(doAutosave, 1200);
  }
  [editor, titleInput, introInput].forEach(el => el && el.addEventListener('input', scheduleAutosave));
  [accentInput, sizeSel, orientSel].forEach(el => el && el.addEventListener('change', scheduleAutosave));
  // Save immediately if the admin leaves the page with unsaved edits
  window.addEventListener('beforeunload', () => {
    if (saveTimer) {
      clearTimeout(saveTimer);
      const form = new FormData();
      form.append('csrf', csrf); form.append('action', 'autosave_manual');
      form.append('title', titleInput ? titleInput.value : '');
      form.append('intro', introInput ? introInput.value : '');
      form.append('body_html', editor.value);
      form.append('accent', accentInput ? accentInput.value : '#e53935');
      form.append('size', sizeSel ? sizeSel.value : 'A4');
      form.append('orientation', orientSel ? orientSel.value : 'portrait');
      navigator.sendBeacon('manual.php', form);
    }
  });

  document.querySelectorAll('[data-insert-tag]').forEach(button => button.addEventListener('click', () => {
    const tag = button.dataset.insertTag, value = tag === 'hr' ? '<hr>' : `<${tag}>${tag === 'p' ? 'Write paragraph text' : tag === 'ul' || tag === 'ol' ? '<li>List item</li>' : tag === 'blockquote' ? 'Important note' : tag === 'pre' ? 'Code or command' : tag === 'strong' ? 'Bold text' : tag === 'em' ? 'Italic text' : tag === 'h3' ? 'Sub-heading' : 'Heading'}</${tag}>`;
    const start = editor.selectionStart, end = editor.selectionEnd; editor.setRangeText(value, start, end, 'select'); editor.focus(); refresh(); scheduleAutosave();
  }));
  refresh();
})();
</script>
<?php admin_end();
