<?php
/** Loaded first by every page in /user and /superadmin. */

declare(strict_types=1);

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

const HM_VERSION = '1.1.2';
define('HM_START', microtime(true));

// Buffer page output so cookies/headers (flash messages, CSRF) can be set at any point.
if (PHP_SAPI !== 'cli') ob_start();

/** Drop buffered output before streaming a file download. */
function end_buffers(): void {
    while (ob_get_level() > 0) ob_end_clean();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/tracking.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/cron.php';
require_once __DIR__ . '/docs.php';
require_once __DIR__ . '/manual.php';

if (!is_dir(HM_DATA . '/logs')) @mkdir(HM_DATA . '/logs', 0700, true);
ini_set('log_errors', '1');
ini_set('error_log', HM_DATA . '/logs/php_errors.log');

/** Friendly error page instead of a crash; full details go to data/logs. */
set_exception_handler(function (Throwable $e) {
    $ref = strtoupper(bin2hex(random_bytes(4)));
    error_log("[$ref] " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    try {
        if (hm_installed()) track('error', ['source' => PHP_SAPI === 'cli' ? 'system' : 'web', 'status' => 500,
            'label' => "Error $ref: " . mb_substr($e->getMessage(), 0, 180)]);
    } catch (Throwable $ignored) {
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error [$ref]: {$e->getMessage()}\n");
        exit(1);
    }
    end_buffers();   // drop any half-rendered page
    if (!headers_sent()) http_response_code(500);
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => "Server error (ref $ref). Please try again."]);
        exit;
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Something went wrong</title><body style="font-family:Segoe UI,Arial;background:#0f1020;color:#e8e8f5;display:grid;place-items:center;min-height:100vh;margin:0">'
        . '<div style="text-align:center;max-width:420px;padding:24px"><div style="font-size:48px">⚠️</div><h2>Something went wrong</h2>'
        . '<p style="color:#a0a0c0">We logged the problem (reference <b>' . $ref . '</b>). Please go back and try again.</p>'
        . '<a href="javascript:history.back()" style="color:#8f84ff">← Go back</a></div></body>';
    exit;
});

set_error_handler(function (int $no, string $msg, string $file, int $line) {
    if (!(error_reporting() & $no)) return false;
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE)) {
        error_log("Notice: $msg in $file:$line");   // log only, never break a page
        return true;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

/** Run scheduled jobs after the page is sent (at most once a minute). */
function schedule_cron(): void {
    register_shutdown_function(function () {
        try {
            if (hm_ready() && setting('last_cron') <= time() - 60) {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                ignore_user_abort(true);
                run_cron();
            }
        } catch (Throwable $e) {
            error_log('cron: ' . $e->getMessage());
        }
    });
}
