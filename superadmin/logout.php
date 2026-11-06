<?php
require __DIR__ . '/_init.php';
if ($ADMIN) audit('logout', 'admin', $ADMIN['id']);
session_end('admin');
flash('Signed out.', 'info');
redirect('login.php');
