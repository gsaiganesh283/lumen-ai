<?php
/** Superadmin "Login as user": a one-time link (valid 60 seconds) made in the superadmin portal. */
require __DIR__ . '/_init.php';
$t = consume_token('impersonate', input('t'));
if (!$t || !$t['admin_id'] || !val('SELECT 1 FROM admins WHERE id = ? AND active = 1', [$t['admin_id']])) {
    http_response_code(403);
    exit('This admin login link is invalid or expired. Create a new one from the superadmin portal.');
}
$u = user_by_id((int)$t['owner_id']);
if (!$u) exit('User not found.');
if ($USER) session_end('user');
$s = session_create('user', (int)$u['id'], 2, ['impersonated_by' => (int)$t['admin_id']]);
track('impersonation', ['user_id' => (int)$u['id'], 'admin_id' => (int)$t['admin_id'], 'session_id' => $s['id'], 'source' => 'admin',
    'label' => 'Admin #' . $t['admin_id'] . ' signed in as this user']);
redirect('dashboard.php');
