<?php
/** Serves uploaded profile photos (random, unguessable file names). */
require_once __DIR__ . '/../shared/bootstrap.php';
$f = basename((string)($_GET['f'] ?? ''));
$path = HM_DATA . '/uploads/avatars/' . $f;
if (!preg_match('/^[0-9]+_[A-Za-z0-9]{16}\.(png|jpg|webp|gif)$/', $f) || !is_file($path)) {
    http_response_code(404);
    exit;
}
end_buffers();
header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][pathinfo($f, PATHINFO_EXTENSION)]);
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
readfile($path);
