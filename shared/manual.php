<?php
/** User-facing setup manual, shared by the portal page and PDF export. */

function manual_mime(string $path): string {
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') return $mime;
    }
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'png' => 'image/png',
        default => 'application/octet-stream',
    };
}

function manual_screenshot(string $file, string $caption): string {
    $path = __DIR__ . '/../user/assets/manual/' . basename($file);
    if (!is_file($path)) return '';
    $mime = manual_mime($path);
    $src = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
    return '<figure class="manual-shot"><img src="' . $src . '" alt="' . e($caption) . '"><figcaption>'
        . e($caption) . '</figcaption></figure>';
}

function manual_image_dir(): string {
    $dir = HM_DATA . '/manual/images';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create manual image storage.');
    }
    return $dir;
}

function manual_image_path(string $name): string {
    $name = basename($name);
    if (!preg_match('/^[a-f0-9]{32}\.(png|jpe?g|gif|webp)$/i', $name)) return '';
    return manual_image_dir() . '/' . $name;
}

function manual_image_src(string $name): string {
    return 'manual_image.php?name=' . rawurlencode(basename($name));
}

function manual_portal_url(string $path = '/manual.php'): string {
    return rtrim(setting('site_url'), '/') . $path;
}

function manual_preview_url(): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (preg_match('#^(.*)/superadmin/[^/]+$#i', $script, $match)) {
        $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $scheme = $forwarded === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host !== '') return $scheme . '://' . $host . $match[1] . '/user/manual.php';
    }
    return manual_portal_url();
}

function manual_pdf_images(string $html): string {
    if (!extension_loaded('gd')) {
        return preg_replace('/<img\b[^>]*>/i', '<p><i>Screenshot omitted from this PDF because the hosting server does not have PHP GD enabled. Screenshots are available in the online User Manual.</i></p>', $html) ?? $html;
    }
    return preg_replace_callback('/<img\b([^>]*)>/i', function ($m) {
        preg_match('/\bsrc\s*=\s*(["\'])(?:manual_image\.php\?name=|[^"\']*manual_image\.php\?name=)([^"\']+)\1/i', $m[1], $src);
        if (!$src) return $m[0];
        $path = manual_image_path(rawurldecode($src[2]));
        if (!is_file($path)) return '';
        $mime = manual_mime($path);
        $attrs = preg_replace('/\bsrc\s*=\s*(["\']).*?\1/i', 'src="' . e('data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path))) . '"', $m[1]);
        return '<img' . $attrs . '>';
    }, $html) ?? $html;
}

function manual_default_body(): string {
    return '<div class="manual-note"><b>Important:</b> InterviewLens is a portable Windows application. There is no installer. Download the EXE, then double-click the EXE whenever you want to open the application.</div>'
        . '<h2>1. Sign in to the User portal</h2><ol><li>Open the User portal in your browser.</li><li>Sign in with your account, or create an account and verify your email.</li><li>Buy or choose a plan if you do not already have a license.</li></ol>'
        . '<h2>2. Download the desktop application</h2><ol><li>Open <b>Download app</b> from the left menu.</li><li>Click the single <b>Download</b> button and wait for the EXE download to finish.</li><li>Open your browser Downloads folder.</li><li>Double-click the downloaded <b>.exe</b> file. Do not look for a setup wizard or installer.</li><li>If Windows SmartScreen appears, choose <b>More info → Run anyway</b> only when the file came from your User portal.</li></ol>'
        . '<h2>3. Add this computer</h2><ol><li>Open the EXE.</li><li>Copy the <b>Device ID</b> shown in the desktop application.</li><li>In the portal, open <b>Devices</b>, paste the Device ID, choose a license, and click <b>Add device</b>.</li></ol>'
        . '<h2>4. Activate the application</h2><ol><li>Open <b>Licenses</b> in the portal and copy your activation key.</li><li>Open the desktop License window.</li><li>Paste the key and click <b>Activate</b>.</li><li>Confirm that the desktop shows your active plan and remaining days.</li></ol>'
        . '<h2>5. Configure the User portal connection</h2><ol><li>Open <b>Customize → Advanced</b> in the desktop app.</li><li>Use the <b>User portal URL</b>, not the Superadmin URL.</li><li>Click <b>Test Connection</b>, then <b>Save Portal URL</b>.</li></ol>'
        . '<h2>6. Sync and test AI providers</h2><ol><li>Open <b>Customize → AI Connections</b>.</li><li>Click <b>Sync from Portal</b> to receive the provider settings managed by the administrator.</li><li>Click <b>Test All Connections</b>.</li><li>API keys and model settings are hidden in the desktop app. Contact support if a provider is not configured.</li></ol>'
        . '<h2>7. Open the app later</h2><p>Keep the downloaded EXE in a safe folder. Whenever you need InterviewLens, double-click that same EXE. You can create a desktop shortcut, but no installation step is required.</p>'
        . '<h2>Troubleshooting</h2><ul><li><b>Download unavailable:</b> confirm that you are signed in and have a published release.</li><li><b>Portal connection failed:</b> verify the User portal URL and internet connection.</li><li><b>License not active:</b> add the Device ID in the portal before activating.</li><li><b>No AI provider key:</b> click Sync from Portal after the device is licensed.</li><li><b>SmartScreen warning:</b> verify that the EXE was downloaded from the User portal before continuing.</li></ul>';
}

function manual_clean_html(string $html): string {
    // Drop document scaffolding that would otherwise leave stray text or run
    // when pasting a full HTML file (the <title> text, scripts, doctype, head meta).
    $html = preg_replace('#<!doctype[^>]*>#i', '', $html) ?? $html;
    $html = preg_replace('#<title\b[^>]*>.*?</title>#is', '', $html) ?? $html;
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
    $html = preg_replace('#<(meta|link)\b[^>]*>#i', '', $html) ?? $html;
    $html = strip_tags($html, '<h1><h2><h3><h4><h5><h6><p><br><strong><b><em><i><u><small><sub><sup><ol><ul><li><blockquote><pre><code><kbd><hr><table><thead><tbody><tr><th><td><a><img><div><span><section><article><header><footer><figure><figcaption><nav><aside><dl><dt><dd><style>');
    $html = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/isu', '', $html) ?? $html;
    $html = preg_replace_callback('/\s(href)\s*=\s*(["\'])(.*?)\2/isu', function ($m) {
        $url = trim($m[3]);
        return preg_match('/^(https?:\/\/|mailto:|\/|#)/i', $url) ? ' href="' . e($url) . '"' : '';
    }, $html) ?? $html;
    $html = preg_replace_callback('/<img\b([^>]*)>/i', function ($m) {
        preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $m[1], $src);
        $value = $src[2] ?? '';
        $valid_data = preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/i', $value, $data);
        $valid_file = preg_match('/^(?:manual_image\.php\?name=|[^"\']*manual_image\.php\?name=)[a-f0-9]{32}\.(?:png|jpe?g|gif|webp)$/i', $value);
        if (!$valid_data && !$valid_file) return '';
        if ($valid_data) {
            $extension = strtolower($data[1]) === 'jpeg' ? 'jpg' : strtolower($data[1]);
            $name = bin2hex(random_bytes(16)) . '.' . $extension;
            $path = manual_image_path($name);
            $decoded = base64_decode(preg_replace('/\s+/', '', $data[2]), true);
            if ($decoded === false || $path === '' || file_put_contents($path, $decoded, LOCK_EX) === false) return '';
            $value = manual_image_src($name);
        }
        preg_match('/\balt\s*=\s*(["\'])(.*?)\1/i', $m[1], $alt);
        return '<img src="' . e($value) . '" alt="' . e($alt[2] ?? 'Manual screenshot') . '">';
    }, $html) ?? $html;
    return trim($html);
}

function manual_pdf_override(): string {
    // Dompdf understands only a limited CSS subset (no CSS variables, flexbox,
    // CSS grid, clamp(), or gradients). This stylesheet restyles the manual's
    // elements with concrete values so the PDF is styled and readable.
    return '<style>'
        . 'nav,aside,.toc,.sidebar,.side-menu,[data-role="toc"]{display:none}'
        . '*{box-sizing:border-box}'
        . 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#1c1930;font-size:12px;line-height:1.5;margin:0;padding:0}'
        . '.wrap,main,section,.cover,.hero,.grid2,.card,header,footer,article{display:block;float:none;max-width:100%;width:auto;margin:0;padding:0;position:static}'
        . '.cover{min-height:0;height:auto;background:#2e2374;color:#fff;padding:26px;border-radius:12px;margin:0 0 18px}'
        . '.cover h1,.cover .sub,.cover .meta,.cover .badge,.cover .logo{color:#fff}'
        . '.cover h1{margin:8px 0;font-size:24px}'
        . '.cover .badge{background:#5a46b5;padding:4px 12px;border-radius:14px}'
        . 'h1{font-size:22px;color:#2e2374;margin:10px 0 4px}'
        . 'h2,h2.sec{font-size:18px;color:#4936b8;margin:18px 0 6px;border:0;padding:0}'
        . 'h3{font-size:15px;color:#2a2550;margin:14px 0 4px}'
        . 'h4{font-size:13px;color:#3a2f72;margin:10px 0 2px}'
        . '.sec-kicker{color:#5B3FD1;font-weight:bold;font-size:11px;text-transform:uppercase}'
        . 'p{margin:8px 0}'
        . 'ul,ol{margin:8px 0 8px 10px;padding-left:18px}'
        . '.steps{list-style:decimal;padding-left:22px}'
        . '.steps>li{margin:6px 0;padding:0;position:static}'
        . '.steps>li:before{content:none;display:none}'
        . 'table{border-collapse:collapse;width:100%;font-size:11px;margin:12px 0}'
        . 'th,td{border:1px solid #d9d4ee;padding:6px 8px;text-align:left;vertical-align:top}'
        . 'th{background:#efeaff;color:#2a2550}'
        . '.note{background:#eef4ff;border:1px solid #cddcff;padding:10px 12px;margin:12px 0;border-radius:8px}'
        . '.tip{background:#eafaf1;border:1px solid #b9e8cd;padding:10px 12px;margin:12px 0;border-radius:8px}'
        . '.warn{background:#fff6e6;border:1px solid #f3dca2;padding:10px 12px;margin:12px 0;border-radius:8px}'
        . '.card{border:1px solid #e4e0f2;padding:12px;margin:10px 0;border-radius:10px}'
        . 'code{background:#efeaff;color:#3a2f72;padding:1px 4px}'
        . 'kbd{background:#2d2748;color:#e8e3ff;padding:1px 5px;font-size:11px}'
        . '.pill{background:#efeaff;color:#3a2f72;padding:1px 8px;border-radius:12px}'
        . 'figure.shot,figure{border:1px dashed #b9a9ff;background:#f3f0ff;padding:16px;margin:12px 0;text-align:center;page-break-inside:avoid}'
        . 'figure.shot .tag{background:#5B3FD1;color:#fff;padding:3px 10px;font-size:10px;font-weight:bold;border-radius:10px}'
        . 'figure.shot .desc{color:#3a2f72;font-weight:bold;margin:8px 0 2px}'
        . 'figure.shot .hint{color:#6b6690;font-size:10px}'
        . 'img{max-width:100%;height:auto;display:block;margin:6px auto}'
        . '.dl,dl{margin:8px 0}dt{font-weight:bold;color:#2a2550;margin-top:8px}dd{margin:2px 0 0}'
        . '</style>';
}

function manual_html(bool $pdf = false, bool $published = false): string {
    $site = e(setting('site_name'));
    $title_setting = $published && setting('manual_published_title') !== ''
        ? setting('manual_published_title') : setting('manual_title');
    $intro_setting = $published && setting('manual_published_intro') !== ''
        ? setting('manual_published_intro') : setting('manual_intro');
    $body = $published && setting('manual_published_body_html') !== ''
        ? setting('manual_published_body_html') : (setting('manual_body_html') ?: manual_default_body());
    $accent_setting = $published && setting('manual_published_accent_color') !== ''
        ? setting('manual_published_accent_color') : setting('manual_accent_color');
    $title = e($title_setting ?: ($site . ' User Manual'));
    $intro = e($intro_setting ?: 'Complete setup guide for downloading and using the Windows desktop application.');
    if ($pdf) $body = manual_pdf_images($body);
    $accent = preg_match('/^#[0-9a-f]{6}$/i', (string)$accent_setting) ? $accent_setting : '#e53935';

    // If the admin pasted a full HTML document (its own <style>, <section>,
    // <nav>, headings, etc.), treat the file as the single source of truth and
    // output it exactly as authored — no title/intro wrapper, no extra CSS.
    // The portal page renders this inside an isolated iframe so its CSS can't
    // leak into the portal. Side menus are hidden only in the PDF.
    if (preg_match('/<(style|section|main|nav|article|header)\b/i', $body)) {
        // Collapse any built-in two-column / sidebar layout so the manual
        // renders as a clean single column (no overlap) when embedded, and
        // hide its internal side menu. This override is appended AFTER the
        // document's own <style> so it wins.
        if ($pdf) {
            // Dompdf supports only basic CSS (no variables, flex, grid, clamp),
            // so re-style the key elements with concrete values for the PDF.
            return $body . manual_pdf_override();
        }
        // On-screen (iframe): single column, comfortable gutter, and flatten the
        // full-height cover so it doesn't leave a huge empty box.
        $override = '<style>'
            . 'nav,aside,.toc,.sidebar,.side-menu,[data-role="toc"]{display:none!important}'
            . 'html,body{margin:0!important;padding:0!important;background:#f4f2fb!important}'
            . 'body{padding:26px 30px!important;box-sizing:border-box!important}'
            . '.wrap{display:block!important;grid-template-columns:none!important;max-width:1000px!important;margin:0 auto!important}'
            . 'main{max-width:none!important;width:auto!important;padding:0!important}'
            . '.cover,.hero,section,section.page{min-height:0!important;height:auto!important}'
            . '.cover{margin:0 0 20px!important}'
            . 'section,.card,.note,.tip,.warn,figure{margin-left:0!important;margin-right:0!important}'
            . '</style>';
        return $body . $override;
    }

    // Otherwise it is a simple rich-text fragment: wrap it with the heading,
    // intro and the built-in manual styling.
    $css = $pdf
        ? 'nav,aside,.toc,.sidebar,.side-menu,[data-role="toc"]{display:none!important}body{font:12px Arial;color:#202034}h1,h2{color:#4936b8}.manual-wrap img,.manual-pdf img{display:block;max-width:100%;max-height:620px;width:auto;height:auto;object-fit:contain}.manual-shot{margin:16px 0;page-break-inside:avoid}.manual-shot img{border:4px solid ' . $accent . '}.manual-shot figcaption{color:' . $accent . ';font-weight:bold;margin-top:5px}'
        : '.manual-wrap{max-width:960px;overflow-wrap:anywhere}.manual-wrap h2{margin-top:30px}.manual-wrap img{display:block;max-width:100%;max-height:620px;width:auto;height:auto;object-fit:contain}.manual-shot{margin:20px 0;padding:12px;border-radius:12px;background:#17172b}.manual-shot img{border:4px solid ' . $accent . ';border-radius:8px}.manual-shot figcaption{color:' . $accent . ';font-weight:700;margin-top:8px}.manual-note{border-left:4px solid ' . $accent . ';padding:10px 14px;background:#2a1820}';
    return '<style>' . $css . '</style><div class="' . ($pdf ? 'manual-pdf' : 'manual-wrap') . '">'
        . '<h1>' . $title . '</h1><p>' . $intro . '</p>' . $body
        . '</div>';
}

/** Locate a headless browser (Chrome/Edge/Chromium) or wkhtmltopdf binary. */
function manual_find_browser(): string {
    $configured = trim((string)setting('manual_pdf_browser'));
    if ($configured !== '' && is_file($configured)) return $configured;
    $candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe',
        '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium-browser', '/usr/bin/chromium', '/usr/bin/microsoft-edge',
        '/snap/bin/chromium', '/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf',
    ];
    foreach ($candidates as $c) if (@is_file($c)) return $c;
    if (function_exists('shell_exec')) {
        $win = stripos(PHP_OS, 'WIN') === 0;
        foreach (['google-chrome', 'chromium', 'chromium-browser', 'chrome', 'wkhtmltopdf'] as $name) {
            $out = @shell_exec(($win ? 'where ' : 'command -v ') . escapeshellarg($name) . ' 2>&1');
            if (is_string($out)) {
                $line = trim((string)strtok($out, "\n"));
                if ($line !== '' && @is_file($line)) return $line;
            }
        }
    }
    return '';
}

/** Build a complete, self-contained HTML document for pixel-perfect printing. */
function manual_pdf_document(bool $published): string {
    $body = $published && setting('manual_published_body_html') !== ''
        ? (string)setting('manual_published_body_html')
        : (setting('manual_body_html') ?: manual_default_body());
    $body = manual_pdf_images($body);               // inline images as data URIs
    $full = (bool)preg_match('/<(style|section|main|nav|article|header)\b/i', $body);
    $inner = $full ? $body : manual_html(false, $published);
    // Appended AFTER the manual's own CSS so it wins: drop the side menu,
    // flatten any sidebar grid, and set sensible print margins.
    $size = setting('manual_pdf_size') === 'letter' ? 'letter' : 'A4';
    $orient = setting('manual_pdf_orientation') === 'landscape' ? 'landscape' : 'portrait';
    $print = '<style>@page{size:' . $size . ' ' . $orient . ';margin:14mm}'
        . 'nav,aside,.toc,.sidebar,.side-menu,[data-role="toc"]{display:none!important}'
        . 'html,body{margin:0!important;background:#fff!important}'
        . '.wrap{display:block!important;grid-template-columns:none!important;max-width:none!important;margin:0!important}'
        . 'main{max-width:none!important;width:auto!important;padding:0!important}'
        . '.cover,.hero,section,section.page{min-height:0!important;height:auto!important}'
        . 'figure,.card,.note,.tip,.warn,table,section{break-inside:avoid}'
        . '</style>';
    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"></head><body>'
        . $inner . $print . '</body></html>';
}

/** Render HTML to PDF with a headless browser. Returns true on success. */
function manual_render_pdf_browser(string $html, string $outPath): bool {
    if (!function_exists('exec')) return false;
    $bin = manual_find_browser();
    if ($bin === '') return false;
    $tmpHtml = tempnam(sys_get_temp_dir(), 'manual_') . '.html';
    if (file_put_contents($tmpHtml, $html) === false) return false;
    $isWk = stripos($bin, 'wkhtmltopdf') !== false;
    $profile = '';
    @unlink($outPath);
    if ($isWk) {
        $cmd = escapeshellarg($bin) . ' --quiet --enable-local-file-access --print-media-type '
            . '--page-size ' . escapeshellarg(setting('manual_pdf_size') === 'letter' ? 'Letter' : 'A4') . ' '
            . ((setting('manual_pdf_orientation') === 'landscape') ? '--orientation Landscape ' : '')
            . escapeshellarg($tmpHtml) . ' ' . escapeshellarg($outPath);
    } else {
        $profile = sys_get_temp_dir() . '/manual_chrome_' . bin2hex(random_bytes(4));
        $url = 'file:///' . str_replace('\\', '/', $tmpHtml);
        $cmd = escapeshellarg($bin) . ' --headless --disable-gpu --no-sandbox --no-first-run'
            . ' --disable-extensions --user-data-dir=' . escapeshellarg($profile)
            . ' --print-to-pdf-no-header --print-to-pdf=' . escapeshellarg($outPath)
            . ' ' . escapeshellarg($url);
    }
    $output = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $output, $code);
    @unlink($tmpHtml);
    if (!empty($profile)) @manual_rrmdir($profile);
    return is_file($outPath) && filesize($outPath) > 1000;
}

function manual_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? manual_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function manual_pdf_path(bool $published = false): string {
    $published_path = HM_DATA . '/manual/interviewlens-user-manual.pdf';
    if ($published && setting('manual_published_at') && is_file($published_path)) {
        return $published_path;
    }
    $dir = HM_DATA . '/manual';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create the manual storage folder.');
    $path = $dir . '/interviewlens-user-manual.pdf';

    // 1) Preferred: a headless browser renders the manual's own HTML/CSS
    //    exactly (pixel-perfect), respecting modern CSS the PDF library can't.
    if (manual_render_pdf_browser(manual_pdf_document($published), $path)) {
        return $path;
    }

    // 2) Fallback: Dompdf with the simplified, library-compatible stylesheet.
    $autoloaders = [
        __DIR__ . '/../../vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../user/vendor/autoload.php',
        __DIR__ . '/../superadmin/vendor/autoload.php',
    ];
    $autoload = '';
    foreach ($autoloaders as $candidate) {
        if (is_file($candidate)) {
            $autoload = $candidate;
            break;
        }
    }
    if ($autoload === '') {
        throw new RuntimeException('PDF support is not installed. Install a Chrome/Edge browser on the server, or upload the Composer vendor folder containing dompdf/dompdf.');
    }
    require_once $autoload;
    if (!class_exists('Dompdf\\Dompdf')) {
        throw new RuntimeException('Dompdf is missing from the uploaded vendor folder. Install dompdf/dompdf and upload vendor again.');
    }
    $pdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
    $pdf->loadHtml(manual_html(true, $published), 'UTF-8');
    $pdf->setPaper(setting('manual_pdf_size') ?: 'A4', setting('manual_pdf_orientation') === 'landscape' ? 'landscape' : 'portrait');
    $pdf->render();
    file_put_contents($path, $pdf->output(), LOCK_EX);
    return $path;
}
