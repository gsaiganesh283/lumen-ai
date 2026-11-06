<?php
/** Desktop app API. POST JSON to /api/?action=<name>.
 *  ping · activate {device_id, key} · check {device_id} · deactivate {device_id} · event {device_id, kind, label} */
require __DIR__ . '/../_init.php';

header('X-Robots-Tag: noindex');
$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($in)) $in = [];
$in += $_POST;

function api_fail(string $msg, int $code = 400, array $extra = []): never {
    json_out(['ok' => false, 'error' => $msg] + $extra, $code);
}

if ($action === 'ping') json_out(['ok' => true, 'site_name' => setting('site_name'), 'portal_url' => site_url(), 'time' => gmdate('c')]);

$device_code = normalize_code((string)($in['device_id'] ?? ''));
if (!valid_device_id($device_code)) api_fail('Invalid Device ID.');
$ip = client_ip();
if (!rate_limit("api:$device_code", max(10, setting('api_rate_limit_per_min')), 60) || !rate_limit("api-ip:$ip", 300, 60)) {
    api_fail('Too many requests. Please wait a minute.', 429);
}
$meta = ['hostname' => mb_substr((string)($in['hostname'] ?? ''), 0, 120), 'os' => mb_substr((string)($in['os'] ?? ''), 0, 120),
    'app_version' => mb_substr((string)($in['app_version'] ?? ''), 0, 30)];
$device = row('SELECT * FROM devices WHERE device_id = ?', [$device_code]);

function touch_device(array $device, array $meta): array {
    update('devices', array_filter($meta, fn($v) => $v !== '') + ['last_seen_at' => now(), 'last_ip' => client_ip()], 'id = ?', [$device['id']]);
    return row('SELECT * FROM devices WHERE id = ?', [$device['id']]);
}

switch ($action) {
    case 'activate':
        $key = normalize_code((string)($in['key'] ?? ''));
        if (!rate_limit("activate:$device_code", 10, 600)) api_fail('Too many activation attempts. Try again in 10 minutes.', 429);
        $lic = $key !== '' ? row('SELECT * FROM licenses WHERE license_key = ?', [$key]) : null;
        if (!$lic) {
            track('desktop_activate_failed', ['source' => 'desktop', 'device_id' => $device_code, 'label' => 'Unknown key ' . substr($key, 0, 8) . '…']);
            api_fail('This activation key is not valid. Check it in your portal (Licenses page).', 404);
        }
        $owner = user_by_id((int)$lic['user_id']);
        if (!$owner || $owner['status'] !== 'active') api_fail('The account for this key is not active. Contact support.', 403);
        $st = effective_status($lic);
        if ($st === 'revoked') api_fail('This key was deactivated. ' . $lic['revoked_reason'], 403);
        if ($st === 'suspended') api_fail('This key is suspended. Contact support.', 403);
        if ($st === 'expired') api_fail('This plan has expired. Renew it in the portal to add days.', 403, ['status' => 'expired']);
        if ($device && (int)$device['user_id'] !== (int)$lic['user_id']) {
            api_fail('This PC is registered to a different account. Sign in to that account or contact support.', 409);
        }
        if ($device && $device['status'] === 'blocked') api_fail('This device is blocked. Contact support.', 403);
        if (!$device) {   // first time we see this PC: register it to the key owner
            $id = insert('devices', ['device_id' => $device_code, 'user_id' => $lic['user_id'], 'name' => $meta['hostname'] ?: 'My PC', 'created_at' => now()] + $meta);
            $device = row('SELECT * FROM devices WHERE id = ?', [$id]);
        } elseif ($device['status'] === 'removed') {
            update('devices', ['status' => 'active'], 'id = ?', [$device['id']]);
        }
        if ($lic['device_pk'] && (int)$lic['device_pk'] !== (int)$device['id']) {
            $other = val('SELECT name FROM devices WHERE id = ?', [$lic['device_pk']]);
            api_fail("This key is linked to another device ($other). In the portal, open Licenses → Move to device and choose this PC.", 409);
        }
        $device = touch_device($device, $meta);
        $lic = activate_on_device($lic, $device);
        track('desktop_activate', ['user_id' => (int)$lic['user_id'], 'source' => 'desktop', 'device_id' => $device_code,
            'label' => "Activated {$lic['license_key']} on {$device['name']}", 'meta' => $meta]);
        json_out(['ok' => true] + desktop_payload($lic, $device));

    case 'check':
        if (!$device) json_out(['ok' => true, 'licensed' => false, 'status' => 'unregistered', 'portal_url' => site_url(),
            'message' => 'Add this Device ID in the portal, then activate your key.', 'kill' => setting('kill_switch')]);
        $device = touch_device($device, $meta);
        $lic = best_license_for_device((int)$device['id']);
        if ($lic) update('licenses', ['last_check_at' => now()], 'id = ?', [$lic['id']]);
        track('desktop_heartbeat', ['user_id' => (int)$device['user_id'], 'source' => 'desktop', 'device_id' => $device_code,
            'label' => 'App check-in · v' . ($meta['app_version'] ?: '?') . ($lic ? ' · ' . effective_status($lic) . ', ' . days_left($lic) . 'd left' : ' · no license')]);
        json_out(['ok' => true] + desktop_payload($lic, $device));

    case 'deactivate':
        if ($device) {
            foreach (rows("SELECT * FROM licenses WHERE device_pk = ? AND status IN ('active','issued')", [$device['id']]) as $l) {
                update('licenses', ['device_pk' => null], 'id = ?', [$l['id']]);
                license_event((int)$l['id'], 'unlinked', "from app on {$device['device_id']}", 'desktop');
            }
            track('desktop_event', ['user_id' => (int)$device['user_id'], 'source' => 'desktop', 'device_id' => $device_code, 'label' => 'Deactivated from the app']);
        }
        json_out(['ok' => true]);

    case 'event':
        if ($device) {
            $kind = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($in['kind'] ?? 'event')));
            track('desktop_event', ['user_id' => (int)$device['user_id'], 'source' => 'desktop', 'device_id' => $device_code,
                'label' => mb_substr($kind . ': ' . (string)($in['label'] ?? ''), 0, 255),
                'meta' => array_slice(is_array($in['meta'] ?? null) ? $in['meta'] : [], 0, 20)]);
        }
        json_out(['ok' => true]);
}
api_fail('Unknown action.', 404);
