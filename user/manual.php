<?php
require __DIR__ . '/_init.php';
$u = require_login();
if (isset($_GET['pdf'])) {
    $path = manual_pdf_path(true);
    end_buffers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="InterviewLens-User-Manual.pdf"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
page_start('User manual', 'manual.php');
?>
<div class="card manual-card">
  <?php
    // Render the manual inside an isolated iframe so its own CSS / layout
    // (including any sidebar) cannot leak into or collide with the portal.
    $doc = '<!doctype html><html><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width, initial-scale=1">'
         . '<base target="_blank"></head><body>'
         . manual_html(false, true)
         . '</body></html>';
  ?>
  <iframe id="manual-frame" srcdoc="<?= htmlspecialchars($doc, ENT_QUOTES) ?>"
          style="width:100%;height:80vh;border:0;background:#fff;border-radius:12px"></iframe>
  <p style="margin-top:14px"><a class="btn" href="manual.php?pdf=1">⬇ Download PDF manual</a></p>
</div>
<script>
(() => {
  const f = document.getElementById('manual-frame');
  const fit = () => {
    try {
      const h = f.contentDocument.body.scrollHeight;
      if (h > 200) f.style.height = (h + 40) + 'px';
    } catch (e) {}
  };
  f.addEventListener('load', fit);
  window.addEventListener('resize', fit);
})();
</script>
<?php page_end();
