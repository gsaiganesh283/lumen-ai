<?php
/** Printable license certificate with the activation key and setup steps. */
require __DIR__ . '/_init.php';
$u = require_login();
$l = row('SELECT * FROM licenses WHERE id = ? AND user_id = ?', [input_int('id'), $u['id']]);
if (!$l) { flash('License not found.', 'danger'); redirect('licenses.php'); }
track('certificate_view', ['user_id' => (int)$u['id'], 'label' => "Viewed certificate for {$l['license_key']}"]);
$st = effective_status($l);
doc_render('License ' . $l['license_key'], doc_certificate($l, $u), [
    'back' => 'licenses.php',
    'actions_html' => '<a href="#" data-copy-key="' . e($l['license_key']) . '" onclick="navigator.clipboard.writeText(this.dataset.copyKey);this.textContent=\'✓ Key copied\';return false">📋 Copy key</a>'
        . (in_array($st, ['active', 'issued', 'expired'], true) ? '<a href="plans.php?renew=' . (int)$l['id'] . '">🔁 Renew</a>' : ''),
    'stamp' => $st === 'active' ? 'ACTIVE' : strtoupper($st), 'stamp_color' => $st === 'active' ? '#1fa971' : ($st === 'issued' ? '#5b4bdb' : '#d23a4a'),
]);
