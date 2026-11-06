<?php
require __DIR__ . '/_init.php';
require_admin('settings');
$name = basename((string)($_GET['name'] ?? ''));
$path = manual_image_path($name);
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit('Image not found.');
}
$mime = manual_mime($path);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
