<?php
require __DIR__ . '/_init.php';
if ($USER) track('logout', ['user_id' => (int)$USER['id'], 'session_id' => (int)$SESSION['id'],
    'label' => $IMPERSONATING ? 'Admin left view-as' : 'Signed out']);
$back = $IMPERSONATING && isset($_GET['exit_admin']) ? admin_url('/user.php?id=' . (int)$USER['id']) : null;
session_end('user');
if ($back) redirect($back);
flash('You have been signed out.', 'info');
redirect('login.php');
