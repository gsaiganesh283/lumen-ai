<?php
/** Scheduled jobs: expire licenses, reminders, email queue, cleanup, backups.
 *  Runs automatically every minute while the site gets traffic, and can also be
 *  run from Windows Task Scheduler / cron:  php website/shared/cron.php */

function run_cron(bool $force = false): array {
    $done = [];
    if (!$force && setting('last_cron') > time() - 60) return $done;
    if (!(int)val("SELECT GET_LOCK('hm_cron', 0)")) return $done;
    try {
        settings_set(['last_cron' => time()]);

        // 1. Expire licenses whose time ran out.
        foreach (rows("SELECT l.*, u.email, u.name, u.reminder_emails, d.name AS device_name FROM licenses l
                JOIN users u ON u.id = l.user_id LEFT JOIN devices d ON d.id = l.device_pk
                WHERE l.status = 'active' AND l.expires_at <= ? LIMIT 200", [now()]) as $l) {
            update('licenses', ['status' => 'expired'], 'id = ?', [$l['id']]);
            license_event((int)$l['id'], 'expired', '', 'system');
            notify((int)$l['user_id'], 'Your plan has expired', "{$l['license_key']} expired. Renew to keep using the app.", 'danger', 'plans.php?renew=' . $l['id']);
            send_mail($l['email'], 'license_expired', ['name' => $l['name'] ?: 'there', 'device' => $l['device_name'] ?: 'your PC',
                'expires' => local_dt($l['expires_at'], false), 'link' => site_url('/plans.php?renew=' . $l['id'])], (int)$l['user_id'], true);
            $done[] = "expired #{$l['id']}";
        }

        // 2. Expiry reminders (email + portal notification), once per threshold.
        $thresholds = array_filter(array_map('intval', explode(',', setting('reminder_days'))), fn($d) => $d > 0);
        sort($thresholds);   // smallest first, so a license only gets the nearest reminder
        foreach ($thresholds as $th) {
            foreach (rows("SELECT l.*, u.email, u.name, u.reminder_emails, d.name AS device_name FROM licenses l
                    JOIN users u ON u.id = l.user_id LEFT JOIN devices d ON d.id = l.device_pk
                    WHERE l.status = 'active' AND l.expires_at > ? AND l.expires_at <= ? AND u.status = 'active'
                    AND NOT EXISTS (SELECT 1 FROM reminder_logs r WHERE r.license_id = l.id AND r.threshold <= ? AND r.expires_at = l.expires_at)
                    LIMIT 200", [now(), ts_plus("+$th days"), $th]) as $l) {
                q('INSERT IGNORE INTO reminder_logs (license_id, threshold, expires_at, created_at) VALUES (?,?,?,?)',
                    [$l['id'], $th, $l['expires_at'], now()]);
                $left = days_left($l);
                notify((int)$l['user_id'], "Plan expires in $left day" . ($left == 1 ? '' : 's'),
                    "{$l['license_key']} ends on " . local_dt($l['expires_at'], false) . '. Renew now — days are added on top.',
                    'warning', 'plans.php?renew=' . $l['id']);
                if ($l['reminder_emails']) {
                    send_mail($l['email'], 'expiry_reminder', ['name' => $l['name'] ?: 'there', 'days_left' => $left,
                        'device' => $l['device_name'] ?: 'your PC', 'expires' => local_dt($l['expires_at']),
                        'link' => site_url('/plans.php?renew=' . $l['id'])], (int)$l['user_id'], true);
                }
                $done[] = "reminder {$th}d #{$l['id']}";
            }
        }

        // 3. Send queued emails.
        if (setting('email_enabled')) {
            foreach (rows("SELECT id FROM email_logs WHERE (status = 'queued' OR (status = 'failed' AND attempts < 3))
                    ORDER BY id LIMIT " . max(1, setting('emails_per_cron'))) as $m) {
                deliver_logged_email((int)$m['id']);
            }
        }

        // 4. Cleanup.
        q('DELETE FROM sessions WHERE expires_at < ? OR (revoked = 1 AND last_seen_at < ?)', [now(), ts_plus('-30 days')]);
        q('DELETE FROM one_time_tokens WHERE expires_at < ?', [ts_plus('-7 days')]);
        q('DELETE FROM rate_limits WHERE window_start < ?', [time() - 7200]);
        q("UPDATE orders SET status = 'cancelled' WHERE status = 'created' AND created_at < ?", [ts_plus('-3 days')]);
        q('DELETE FROM ip_blocks WHERE expires_at IS NOT NULL AND expires_at < ?', [now()]);
        $keep = setting('activity_retention_days');
        if ($keep > 0) q('DELETE FROM activity_events WHERE created_at < ? LIMIT 5000', [ts_plus("-$keep days")]);

        // 5. Automatic backup.
        $every = setting('auto_backup_hours');
        if ($every > 0 && setting('last_backup') < time() - $every * 3600) {
            settings_set(['last_backup' => time()]);
            backup_create('auto');
            backup_prune(setting('backups_to_keep'));
            $done[] = 'backup';
        }
    } finally {
        val("SELECT RELEASE_LOCK('hm_cron')");
    }
    return $done;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__ . '/bootstrap.php';
    $r = run_cron(true);
    echo 'Cron done: ' . ($r ? implode(', ', $r) : 'nothing to do') . PHP_EOL;
}
