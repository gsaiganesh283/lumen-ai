<?php
/** Activity tracking: every page view, action, login, payment and desktop event is saved. */

function track(string $kind, array $o = []): void {
    if (!setting('tracking_enabled') && !in_array($o['source'] ?? 'web', ['admin', 'system'], true)
        && !str_starts_with($kind, 'login') && !str_starts_with($kind, 'payment')) {
        return;
    }
    try {
        insert('activity_events', [
            'user_id' => $o['user_id'] ?? null, 'admin_id' => $o['admin_id'] ?? null, 'session_id' => $o['session_id'] ?? null,
            'device_id' => $o['device_id'] ?? '', 'source' => $o['source'] ?? 'web', 'kind' => substr($kind, 0, 40),
            'label' => mb_substr($o['label'] ?? '', 0, 255),
            'path' => mb_substr($o['path'] ?? ($_SERVER['REQUEST_URI'] ?? ''), 0, 400),
            'method' => $o['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? ''), 'status' => $o['status'] ?? 0,
            'duration_ms' => (int)($o['duration_ms'] ?? 0), 'ip' => $o['ip'] ?? client_ip(),
            'user_agent' => mb_substr($o['user_agent'] ?? user_agent(), 0, 400),
            'referrer' => mb_substr($o['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 0, 400),
            'meta' => isset($o['meta']) ? json_encode($o['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
        error_log('track failed: ' . $e->getMessage());
    }
}

function audit(string $action, string $target_type = '', $target_id = '', $detail = ''): void {
    $admin = $GLOBALS['ADMIN'] ?? null;
    insert('audit_logs', ['admin_id' => $admin['id'] ?? null, 'action' => $action, 'target_type' => $target_type,
        'target_id' => (string)$target_id, 'detail' => is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE),
        'ip' => client_ip(), 'created_at' => now()]);
    track('admin_' . $action, ['admin_id' => $admin['id'] ?? null, 'source' => 'admin',
        'user_id' => $target_type === 'user' && is_numeric($target_id) ? (int)$target_id : null,
        'label' => ($admin['name'] ?? 'Admin') . ": $action $target_type #$target_id"]);
}

const EVENT_LABELS = [
    'page_view' => '👁 Page view', 'click' => '🖱 Click', 'page_time' => '⏱ Time on page', 'login' => '🔑 Login',
    'login_failed' => '⛔ Login failed', 'logout' => '🚪 Logout', 'register' => '🆕 Registered', 'verify_email' => '✉️ Email verified',
    'google_login' => '🟢 Google sign-in', 'password_reset' => '🔁 Password reset', 'order_created' => '🛒 Checkout started',
    'payment_paid' => '💰 Payment', 'upi_submitted' => '🧾 UPI submitted', 'device_added' => '💻 Device added',
    'desktop_activate' => '🔓 App activated', 'desktop_heartbeat' => '💓 App check-in', 'desktop_event' => '🖥 App event',
    'download' => '⬇️ Download', 'impersonation' => '🕵 Admin view-as', 'ticket' => '🎫 Ticket', 'profile' => '👤 Profile',
];

function event_label(string $kind): string {
    if (str_starts_with($kind, 'admin_')) return '🛡 ' . ucwords(str_replace('_', ' ', substr($kind, 6)));
    return EVENT_LABELS[$kind] ?? ucwords(str_replace('_', ' ', $kind));
}
