<?php
/** Works out how far the user is in: plan → pay → download → device → activate. */
function onboarding_state(int $uid): array {
    $lic_any = (int)val('SELECT COUNT(*) FROM licenses WHERE user_id = ?', [$uid]);
    $pending = (int)val("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending_review'", [$uid]);
    $started = (int)val("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('created','pending_review','paid')", [$uid]);
    $downloaded = (bool)val("SELECT 1 FROM activity_events WHERE user_id = ? AND kind = 'download' LIMIT 1", [$uid]);
    $devices = (int)val("SELECT COUNT(*) FROM devices WHERE user_id = ? AND status <> 'removed'", [$uid]);
    $active = (int)val("SELECT COUNT(*) FROM licenses WHERE user_id = ? AND status = 'active' AND expires_at > ?", [$uid, now()]);
    $used = (int)val("SELECT COUNT(*) FROM licenses WHERE user_id = ? AND activated_at IS NOT NULL", [$uid]);
    $steps = [
        'plan' => ['Choose a plan', $started > 0 || $lic_any > 0, 'plans.php'],
        'pay' => ['Pay & get key', $lic_any > 0, $pending ? 'orders.php' : 'plans.php'],
        'download' => ['Download app', $downloaded || $devices > 0, 'download.php'],
        'device' => ['Add Device ID', $devices > 0, 'devices.php'],
        'activate' => ['Activate in app', $used > 0, 'licenses.php'],
    ];
    $current = null;
    foreach ($steps as $k => $s) if (!$s[1]) { $current = $k; break; }
    $done = count(array_filter($steps, fn($s) => $s[1]));
    return ['steps' => $steps, 'current' => $current, 'done' => $done, 'pending_payment' => $pending, 'active' => $active];
}

function onboarding_bar(array $st): string {
    $h = '<div class="wizard">';
    foreach ($st['steps'] as $k => [$label, $ok, $href]) {
        $cls = $ok ? 'done' : ($st['current'] === $k ? 'current' : '');
        $h .= '<a class="step ' . $cls . '" href="' . e($href) . '"><b>' . e($label) . '</b>' . ($ok ? 'Done' : ($st['current'] === $k ? 'Next step' : 'Pending')) . '</a>';
    }
    return $h . '</div>';
}
