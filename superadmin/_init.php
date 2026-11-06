<?php
/** Loaded first by every superadmin page. */
const HM_PORTAL = 'admin';
require_once __DIR__ . '/../shared/bootstrap.php';

security_headers();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$__page = basename($_SERVER['SCRIPT_NAME']);
if (!hm_ready()) {
    if ($__page !== 'setup.php') redirect('setup.php');
    return;   // setup.php continues on its own
}

// Optional IP allowlist for the whole superadmin portal.
$__allowed = array_filter(array_map('trim', explode(',', setting('admin_allowed_ips'))));
if ($__allowed && !in_array(client_ip(), $__allowed, true)) {
    http_response_code(403);
    exit('Your IP (' . e(client_ip()) . ') is not allowed to open the superadmin portal.');
}

schedule_cron();

$ASESSION = session_current('admin');
$ADMIN = null;
if ($ASESSION) {
    $ADMIN = row('SELECT * FROM admins WHERE id = ? AND active = 1', [$ASESSION['owner_id']]);
    if (!$ADMIN) { session_end('admin'); $ASESSION = null; }
}
$GLOBALS['ADMIN'] = $ADMIN;

/** What each role may do. Superadmin can do everything. */
const ROLE_PERMS = [
    'admin' => ['view', 'users', 'impersonate', 'licenses', 'payments', 'plans', 'coupons', 'tickets', 'emails', 'announcements',
        'tracking', 'live', 'reports', 'settings', 'security', 'database', 'releases', 'audit', 'system'],
    'support' => ['view', 'users', 'impersonate', 'licenses', 'tickets', 'tracking', 'live', 'payments'],
    'viewer' => ['view', 'live', 'reports', 'tracking'],
];

function can(string $perm): bool {
    global $ADMIN;
    if (!$ADMIN) return false;
    if ($ADMIN['role'] === 'superadmin') return true;
    return in_array($perm, ROLE_PERMS[$ADMIN['role']] ?? [], true);
}

function require_admin(string $perm = 'view'): array {
    global $ADMIN, $ASESSION;
    if (!$ADMIN) redirect('login.php?next=' . rawurlencode($_SERVER['REQUEST_URI']));
    if ($ASESSION['twofa_pending']) redirect('twofa.php');
    if (setting('require_admin_2fa') && !$ADMIN['totp_enabled'] && basename($_SERVER['SCRIPT_NAME']) !== 'account.php') {
        flash('Two-step verification is required for admins. Please set it up now.', 'warning');
        redirect('account.php');
    }
    if (!can($perm)) {
        http_response_code(403);
        admin_start('No access');
        echo '<div class="card empty">⛔ Your role (' . e($ADMIN['role']) . ') can\'t open this page.</div>';
        admin_end();
        exit;
    }
    return $ADMIN;
}

/** POST guard for write actions: CSRF + permission. */
function admin_post(string $perm): bool {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return false;
    if (!csrf_valid()) {
        flash('Security check failed (form expired). Please try again.', 'danger');
        redirect($_SERVER['REQUEST_URI']);
    }
    if (!can($perm)) {
        flash('Your role is not allowed to do that.', 'danger');
        redirect($_SERVER['REQUEST_URI']);
    }
    return true;
}

const ADMIN_NAV = [
    'Overview' => [['index.php', '📊', 'Dashboard', 'view'], ['live.php', '🟢', 'Live monitor', 'live'], ['reports.php', '📈', 'Reports', 'reports'], ['search.php', '🔎', 'Search', 'view']],
    'Customers' => [['users.php', '👥', 'Users', 'view'], ['licenses.php', '🔑', 'Licenses', 'view'], ['devices.php', '💻', 'Devices', 'view'],
        ['tracking.php', '🛰', 'User tracking', 'tracking'], ['tickets.php', '🎫', 'Support tickets', 'tickets']],
    'Sales' => [['orders.php', '💰', 'Orders & payments', 'payments'], ['plans.php', '💎', 'Plans', 'plans'], ['coupons.php', '🏷', 'Coupons', 'coupons']],
    'Engage' => [['notify.php', '📣', 'Notify & broadcast', 'emails'], ['announcements.php', '📢', 'Announcements', 'announcements'], ['emails.php', '✉️', 'Emails', 'emails']],
    'System' => [['settings.php', '⚙️', 'Settings', 'settings'], ['ai_providers.php', '🤖', 'AI providers', 'settings'], ['security.php', '🛡', 'Security', 'security'], ['database.php', '🗄', 'Database', 'database'],
        ['releases.php', '📦', 'App releases', 'releases'], ['manual.php', '📘', 'User manual', 'settings'], ['admins.php', '🧑‍💼', 'Admins', 'admins'], ['audit.php', '📜', 'Audit log', 'audit'],
        ['system.php', '🩺', 'System health', 'system'], ['features.php', '✨', 'All features', 'view']],
];

function admin_start(string $title, string $active = ''): void {
    global $ADMIN;
    $site = setting('site_name');
    $pending = can('payments') ? (int)val("SELECT COUNT(*) FROM orders WHERE status = 'pending_review'") : 0;
    $open = can('tickets') ? (int)val("SELECT COUNT(*) FROM tickets WHERE status = 'open'") : 0;
    $active = $active ?: basename($_SERVER['SCRIPT_NAME']);
    ?><!doctype html>
<html lang="en" data-theme="dark">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($site) ?> Superadmin</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🛡</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= HM_VERSION ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
</head><body>
<div class="bg-deco" aria-hidden="true"><i></i><i></i><i></i></div>
<?php if ($ADMIN): ?>
<script type="application/json" id="cmd-extra"><?= json_encode(array_values(array_filter([
    can('users') ? ['label' => 'Create a new user', 'href' => 'user.php?new=1', 'icon' => '➕', 'hint' => 'Action'] : null,
    can('licenses') ? ['label' => 'Create license keys', 'href' => 'licenses.php#create', 'icon' => '🔑', 'hint' => 'Action'] : null,
    can('payments') ? ['label' => 'Approve pending UPI payments', 'href' => 'orders.php?status=pending_review', 'icon' => '🧾', 'hint' => 'Action'] : null,
    can('coupons') ? ['label' => 'Create a coupon', 'href' => 'coupons.php#form', 'icon' => '🏷', 'hint' => 'Action'] : null,
    can('emails') ? ['label' => 'Broadcast a message', 'href' => 'notify.php', 'icon' => '📣', 'hint' => 'Action'] : null,
    can('announcements') ? ['label' => 'New announcement', 'href' => 'announcements.php#form', 'icon' => '📢', 'hint' => 'Action'] : null,
    can('database') ? ['label' => 'Backups', 'href' => 'database.php?tab=backups', 'icon' => '💾', 'hint' => 'Action'] : null,
    can('database') ? ['label' => 'SQL console', 'href' => 'database.php?tab=sql', 'icon' => '⌨', 'hint' => 'Action'] : null,
    can('reports') ? ['label' => 'Print revenue report', 'href' => 'document.php?type=report', 'icon' => '🖨', 'hint' => 'Document'] : null,
    can('security') ? ['label' => 'Maintenance / kill switch', 'href' => 'security.php', 'icon' => '🚨', 'hint' => 'Action'] : null,
    ['label' => 'Unanswered tickets', 'href' => 'tickets.php?status=open', 'icon' => '🎫', 'hint' => 'Action'],
    ['label' => 'Expiring in 7 days', 'href' => 'licenses.php?expiring=7', 'icon' => '⏰', 'hint' => 'Filter'],
    ['label' => 'Users online now', 'href' => 'users.php?online=1', 'icon' => '🟢', 'hint' => 'Filter'],
    ['label' => 'My account & 2FA', 'href' => 'account.php', 'icon' => '👤', 'hint' => 'Action'],
    ['label' => 'Open user portal', 'href' => site_url('/'), 'icon' => '↗', 'hint' => 'Link'],
    ['label' => 'Sign out', 'href' => 'logout.php', 'icon' => '🚪', 'hint' => 'Action'],
])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php"><span class="logo">🛡</span> <?= e($site) ?></a>
    <div class="muted small" style="padding:2px 10px">Superadmin portal</div>
    <nav>
    <?php foreach (ADMIN_NAV as $group => $items): $vis = array_filter($items, fn($i) => can($i[3])); if (!$vis) continue; ?>
      <div class="nav-group"><?= e($group) ?></div>
      <?php foreach ($vis as [$href, $icon, $label]): ?>
        <a href="<?= $href ?>" class="<?= $active === $href ? 'active' : '' ?>"><span><?= $icon ?></span><?= e($label) ?>
          <?php if ($href === 'orders.php' && $pending): ?><em class="pill"><?= $pending ?></em><?php endif; ?>
          <?php if ($href === 'tickets.php' && $open): ?><em class="pill"><?= $open ?></em><?php endif; ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </nav>
  </aside>
  <div class="content">
    <header class="topbar">
      <button class="icon-btn mobile-only" data-sidebar-toggle>☰</button>
      <h1 class="page-title"><?= e($title) ?></h1>
      <form action="search.php" class="top-search"><input type="search" name="q" placeholder="Search users, keys, orders, devices…" value="<?= e($_GET['q'] ?? '') ?>"></form>
      <div class="top-actions">
        <button class="cmd-btn" data-palette title="Quick actions">⚡ Actions <kbd>Ctrl K</kbd></button>
        <?php if (setting('maintenance_mode')): ?><span class="badge badge-amber">Maintenance ON</span><?php endif; ?>
        <?php if (setting('kill_switch')): ?><span class="badge badge-red">Kill switch ON</span><?php endif; ?>
        <a class="icon-btn" href="<?= e(site_url('/')) ?>" target="_blank" title="Open user portal">↗</a>
        <button class="icon-btn" data-theme-toggle title="Light / dark">🌓</button>
        <details class="menu"><summary class="avatar"><?= e(initials($ADMIN['name'], $ADMIN['email'])) ?></summary>
          <div class="menu-list"><div style="padding:8px 10px"><b><?= e($ADMIN['name'] ?: 'Admin') ?></b><div class="small muted"><?= e($ADMIN['email']) ?></div><div style="margin-top:6px"><?= badge($ADMIN['role']) ?></div></div><hr>
            <a href="account.php">👤 My account & 2FA</a><a href="audit.php?admin=<?= (int)$ADMIN['id'] ?>">📜 My activity</a><a href="features.php">✨ All features</a><hr>
            <a href="logout.php" class="danger">🚪 Sign out</a></div></details>
      </div>
    </header>
    <main class="main">
<?php else: ?>
<main class="public-main">
<?php endif; ?>
<?php foreach (flash_take() as [$kind, $msg]): ?><div class="alert alert-<?= e($kind) ?>" data-autohide><?= e($msg) ?></div><?php endforeach;
}

function admin_end(): void {
    global $ADMIN;
    if ($ADMIN): ?>
    </main>
    <footer class="footer"><span><?= e(setting('site_name')) ?> Superadmin · v<?= HM_VERSION ?></span><span>Server time <?= gmdate('H:i') ?> UTC</span><span>Page <?= round((microtime(true) - HM_START) * 1000) ?> ms</span></footer>
  </div>
</div>
<?php else: ?></main><?php endif; ?>
<script src="assets/admin.js?v=<?= HM_VERSION ?>"></script>
<script src="assets/ui.js?v=<?= HM_VERSION ?>"></script>
</body></html>
<?php
}

/** Small reusable bits */
function user_link(?int $id, ?string $email = null): string {
    if (!$id) return '<span class="muted">—</span>';
    $email ??= (string)val('SELECT email FROM users WHERE id = ?', [$id]);
    return '<a href="user.php?id=' . $id . '">' . e($email ?: "#$id") . '</a>';
}

function filter_select(string $name, array $options, string $all = 'All'): string {
    $cur = $_GET[$name] ?? '';
    $h = '<select name="' . e($name) . '" onchange="this.form.submit()"><option value="">' . e($all) . '</option>';
    foreach ($options as $v => $label) {
        $v = is_int($v) ? $label : $v;
        $h .= '<option value="' . e($v) . '"' . ((string)$cur === (string)$v ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h . '</select>';
}

function stat_card(string $label, $value, string $sub = '', string $href = ''): string {
    $inner = '<div class="label">' . e($label) . '</div><div class="value">' . $value . '</div>' . ($sub ? '<div class="sub">' . $sub . '</div>' : '');
    return $href ? '<a class="stat" href="' . e($href) . '">' . $inner . '</a>' : '<div class="stat">' . $inner . '</div>';
}
