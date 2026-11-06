<?php
require __DIR__ . '/_init.php';
require_admin('view');
const FEATURES = [
    '📊 Dashboard' => ['Revenue today', 'Revenue for 30 days with growth vs the previous 30', 'Total users, sign-ups today, verified count', 'Active / unused / expiring-in-7-days licenses',
        'Users and desktop apps online now', 'Pending UPI approvals card', 'Open tickets card', 'Failed logins (24 h) and failed emails (7 d)', 'Revenue chart (30 days)',
        'Sign-ups & activations chart', 'Sales-by-plan chart', 'Licenses-by-status chart', 'Newest users list', 'Latest payments list', 'Expiring-soon list', 'Setup warnings (email off, no payment method)'],
    '📈 Reports' => ['Custom date range + quick 7d/30d/90d/1y', 'Group by day, week or month', 'Revenue + orders chart', 'Average order value', 'Renewal count and rate', 'Refund total',
        'Churn: expired and not renewed', 'Conversion funnel (sign-up → verified → checkout → paid → activated)', 'Revenue by plan', 'Revenue by payment method', 'Coupon usage report', 'Top customers', 'Revenue CSV export'],
    '🟢 Live monitor' => ['Refreshes every 3 seconds', 'Users online in the portal with their current page', 'Desktop apps online', 'Admins online', 'Events-per-minute chart (30 min)',
        'Live activity stream', 'Filter the stream', 'Hide app check-ins', 'Pause', 'Logins and failed logins in the last hour', 'Errors in the last hour', 'Revenue and sign-ups in 24 h'],
    '🔎 Search' => ['One search across users, licenses, devices, orders and tickets', 'Search box on every page', 'Find by email, key, Device ID, order, invoice, UTR, payment ID or IP'],
    '👥 Users' => ['Search by email, name, phone, ID, IP or referral code', 'Filter by status', 'Filter by verified', 'Filter by sign-up method', 'Filter by plan state',
        'Filter by tag', 'Filter by join date', 'Online-now filter', 'Sort by newest, oldest, last seen, top spenders, email', 'CSV export', 'Bulk verify', 'Bulk activate',
        'Bulk suspend with reason', 'Bulk ban and sign out', 'Bulk sign out everywhere', 'Bulk add tag', 'Bulk give free days', 'Bulk permanent delete (superadmin)',
        'Create users (with password or emailed set-password link)'],
    '👤 User profile' => ['Login as user — no password needed (one-time 60-second link, banner, logged)', 'Sensitive actions blocked while viewing as a user', 'Edit name, email, phone, country',
        'Tags', 'Private admin notes', 'Email preference controls', 'Set status (active / suspended / banned) with reason and optional email', 'Mark verified / unverified', 'Resend verification email',
        'Send password reset link', 'Set a password directly', 'Unlock a locked account', "Turn off the user's 2FA", 'Sign out everywhere', 'Revoke single sessions', 'Soft delete', 'Permanent delete (type DELETE)',
        'Spend, licenses, events and last-seen summary', 'Referral code, referrals and referrer', 'Issue a license from a plan or custom days', 'Bind a new license to a device', 'Trial or admin grant',
        'Email the key on issue', 'Add or remove days (± days) with optional email', 'Set an exact expiry date and time', 'Move a license to another device or unlink', 'Revoke with reason and email',
        'Suspend', 'Reactivate', 'Reset to unused (restart the clock)', 'Re-send the key', 'License notes', 'Delete a license (superadmin)', 'License history timeline', 'Add a device',
        'Rename a device', 'Block / unblock a device', 'Delete a device', 'Record a payment or grant a plan (amount, method, UTR, renew)', 'Per-user activity with filters', 'Per-user activity CSV',
        'Sessions with view-as markers', 'Sign-in attempt history', 'Every email sent to the user', "The user's tickets", 'Send a notification (in-app + email)'],
    '🔑 Licenses' => ['Status counters', 'Search by key, email or Device ID', 'Filter by status, source, plan', 'Expiring in 1/3/7/30 days', 'No-device filter', 'Sort by expiry, newest, last check',
        'Create up to 50 keys at once for a user', 'Bulk add/remove days', 'Bulk suspend, revoke, reactivate, unlink', 'CSV export'],
    '💻 Devices' => ['Totals, online, blocked, versions', 'Search by Device ID, name, hostname, IP, email', 'Filter by status, version, online', 'Bulk block, unblock, delete', 'CSV export', 'Linked license, app version and OS'],
    '💰 Orders & payments' => ['UPI approval queue (pending first)', 'Approve → key issued and emailed automatically', 'Reject with reason → email + notification', 'View the UPI payment screenshot',
        'Copy UTR', 'Refund (mark refunded, optional Razorpay refund, optional key revoke)', 'Order notes and cancel', 'Search by order, invoice, UTR, payment ID, email', 'Filter by status, method, coupon, dates',
        'Paid total for any filter', 'Abandoned checkouts', 'Refund totals', 'CSV export', 'Renewal marker', 'Razorpay webhook for automatic activation', 'Duplicate-UTR protection'],
    '💎 Plans' => ['Create and edit plans (code, name, days, price, MRP, badge, description, features, order)', 'Show / hide', 'Duplicate', 'Safe delete', 'Sold count, revenue and price per day'],
    '🏷 Coupons' => ['% or ₹ off', 'Max uses and per-user limit', 'Limit to plans', 'Valid from / to', 'Enable / disable', 'Delete', 'Bulk-generate single-use codes with CSV download', 'Usage and discount stats', 'Search'],
    '🎫 Support' => ['Queue with open/answered/closed/satisfaction stats', 'Filter by status, priority, topic, assigned to me', '"Needs reply" marker', 'Bulk assign, answer, close, reopen, delete',
        'Reply (email + in-app notification)', 'Internal notes', 'Reply and close', 'Status, priority and assignee', 'Saved replies', "User's license in the ticket", 'View as user from a ticket'],
    '📣 Engage' => ['Broadcast to 9 segments (all, active plan, expiring, expired, never paid, unverified, inactive, tag, emails)', 'Preview recipients', 'In-app and/or email',
        'Respects marketing opt-out (or override for service messages)', 'Batched email sending', 'Announcements in portal, desktop app or both', 'Scheduled announcements', 'Announcement styles', 'Edit, toggle, delete announcements'],
    '✉️ Emails' => ['Full email log with status counts', 'Filter by status, template, search', 'View any email', 'Resend', 'Edit all 17 templates', 'Live template preview', 'Reset template', 'Send test email',
        'Send queue now', 'Retry failed', 'Delete old logs', 'Gmail SMTP with App Password'],
    '🛰 User tracking' => ['Every page view, click, time on page, scroll depth, login, payment and desktop event saved', 'Filter by user, event, source, IP, device, text, dates', 'Problems-only view',
        'CSV export (up to 200,000 events)', 'Top pages', 'Busiest IPs', 'Shared-IP detection (account sharing)', 'Delete old events', 'Click an IP to see all its activity'],
    '⚙️ Settings' => ['65 settings in 8 groups', 'General: name, URLs, support, WhatsApp, timezone', 'Accounts: registration, verification, Google, blocked domains, referral bonus',
        'Security: password rules, lockout, sessions, admin IP allowlist, required 2FA, rate limits', 'Payments: Razorpay, UPI, GST, invoice details',
        'Licenses: trial, start on activation or purchase, device changes, offline grace, reminders', 'Email: Gmail SMTP', 'Tracking: retention, automatic backups',
        'Desktop app: latest/minimum version, download link, kill switch', 'Secrets encrypted in the database and masked', 'Allowlist lock-out protection'],
    '🛡 Security' => ['Maintenance mode switch', 'Registration switch', 'Desktop kill switch', 'Sign out all users', 'Clear rate limits', 'Block IPs (temporary or permanent)', 'Suspicious IP detection with 1-click block',
        'Sign-in attempts log with filters', 'All active sessions with revoke', 'CSRF protection on every form', 'Brute-force lockout and rate limits', 'bcrypt passwords, AES-256-GCM secrets, RSA-signed licenses',
        'Strict security headers (CSP, frame blocking, HSTS on HTTPS)'],
    '🗄 Database' => ['Tables with rows, size, engine', 'Browse any table with search, sort, paging', 'Edit, insert and delete rows (superadmin)', 'Schema and CREATE TABLE view', 'Table CSV export (secrets hidden)',
        'Read-only SQL console', 'Write SQL with automatic backup first (superadmin)', 'Dangerous statements blocked', 'Saved queries', 'Create, download, restore, delete and upload backups',
        'Safety backup before every restore', 'Automatic scheduled backups with pruning', 'Check, analyze, optimize tables', 'Empty log tables'],
    '📦 App releases' => ['Upload installer with version, notes and SHA-256', 'Choose the download version', 'Set minimum version (forces older apps to update)', 'Download counts', 'Versions in use'],
    '🧑‍💼 Admins & audit' => ['Roles: superadmin, admin, support, viewer', 'Create admins', 'Change roles', 'Disable / enable', 'Reset password or 2FA', 'Safe delete (keeps one superadmin)',
        'My account: password, 2FA with QR, sessions', 'Admin login lockout, rate limit and 2FA step', 'Audit log of every admin action with filters and CSV'],
    '🩺 System' => ['18 health checks', 'Run scheduled jobs now', 'Error log viewer', 'PHP, database, disk, memory info', 'Friendly error pages with reference codes',
        'Scheduled jobs: expire plans, reminders (7/3/1 days), email queue, cleanup, backups'],
];
$count = array_sum(array_map('count', FEATURES));
admin_start('All features');
?>
<div class="card"><h2>✨ <?= $count ?> superadmin features</h2><p class="muted">Everything below is built into this portal.</p></div>
<?php foreach (FEATURES as $group => $items): ?>
<div class="card"><h2><?= e($group) ?> <span class="muted small">(<?= count($items) ?>)</span></h2><ul class="feature-group"><?php foreach ($items as $f): ?><li>✓ <?= e($f) ?></li><?php endforeach; ?></ul></div>
<?php endforeach; ?>
<?php admin_end();
