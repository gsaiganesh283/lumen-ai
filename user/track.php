<?php
/** Receives click / time-on-page beacons from assets/app.js. */
require __DIR__ . '/_init.php';
if (!$USER || $_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid() || !setting('track_clicks')) {
    http_response_code(204);
    exit;
}
if (!rate_limit('track:' . $SESSION['id'], 120, 60)) {
    http_response_code(204);
    exit;
}
$kind = input('kind') === 'page_time' ? 'page_time' : 'click';
$path = mb_substr(input('path'), 0, 400);
$label = $kind === 'click' ? 'Clicked "' . mb_substr(input('label'), 0, 80) . '" on ' . $path
    : 'Spent ' . input_int('seconds') . 's on ' . $path . ' (scrolled ' . input_int('scroll') . '%)';
track($kind, ['user_id' => (int)$USER['id'], 'session_id' => (int)$SESSION['id'], 'path' => $path, 'method' => 'JS', 'label' => $label,
    'duration_ms' => $kind === 'page_time' ? input_int('seconds') * 1000 : 0,
    'meta' => ['target' => mb_substr(input('target'), 0, 60), 'scroll' => input_int('scroll'), 'impersonated' => (bool)$IMPERSONATING]]);
http_response_code(204);
