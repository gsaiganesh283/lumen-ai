<?php
/** Printable account statement for any date range. */
require __DIR__ . '/_init.php';
$u = require_login();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('from')) ? input('from') : local_date($u['created_at']);
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', input('to')) ? input('to') : local_date();
track('statement_view', ['user_id' => (int)$u['id'], 'label' => "Viewed statement $from → $to"]);
$picker = '<form method="get" style="display:flex;gap:6px;align-items:center;color:#fff;font-size:13px">From <input type="date" name="from" value="' . e($from)
    . '" style="padding:6px;border-radius:8px;border:0"> to <input type="date" name="to" value="' . e($to) . '" style="padding:6px;border-radius:8px;border:0"><button>Update</button></form>';
doc_render('Account statement', doc_statement($u, $from, $to), ['back' => 'orders.php', 'actions_html' => $picker]);
