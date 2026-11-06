<?php
/** User portal page layout: header, sidebar, flash messages, footer. */

const USER_NAV = [
    ['dashboard.php', '🏠', 'Dashboard'],
    ['get-started.php', '🚀', 'Get started'],
    ['plans.php', '💎', 'Plans & renew'],
    ['licenses.php', '🔑', 'Licenses'],
    ['devices.php', '💻', 'Devices'],
    ['download.php', '⬇️', 'Download app'],
    ['manual.php', '📘', 'User manual'],
    ['orders.php', '🧾', 'Payments'],
    ['notifications.php', '🔔', 'Notifications'],
    ['referrals.php', '🎁', 'Refer & earn'],
    ['support.php', '💬', 'Support'],
    ['activity.php', '📜', 'My activity'],
    ['profile.php', '👤', 'Profile'],
    ['security.php', '🛡️', 'Security'],
];

function page_start(string $title, string $active = '', bool $bare = false): void {
    global $USER, $IMPERSONATING, $SESSION;
    $site = setting('site_name');
    $theme = $USER['theme'] ?? 'dark';
    $unread = $USER ? (int)val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$USER['id']]) : 0;
    $track = $USER && setting('tracking_enabled') && setting('track_clicks') ? '1' : '0';
    ?><!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($site) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= HM_VERSION ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
</head>
<body data-track="<?= $track ?>">
<div class="bg-deco" aria-hidden="true"><i></i><i></i><i></i></div>
<script type="application/json" id="cmd-extra"><?= json_encode([
    ['label' => 'Buy or renew a plan', 'href' => 'plans.php', 'icon' => '💎', 'hint' => 'Action'],
    ['label' => 'Copy my activation key', 'href' => 'licenses.php', 'icon' => '🔑', 'hint' => 'Action'],
    ['label' => 'Print account statement', 'href' => 'statement.php', 'icon' => '🖨', 'hint' => 'Document'],
    ['label' => 'Open a support ticket', 'href' => 'support.php', 'icon' => '🎫', 'hint' => 'Action'],
    ['label' => 'Add a device', 'href' => 'devices.php', 'icon' => '💻', 'hint' => 'Action'],
    ['label' => 'Change password / 2FA', 'href' => 'security.php', 'icon' => '🔐', 'hint' => 'Action'],
    ['label' => 'Download my data', 'href' => 'profile.php?export=1', 'icon' => '📦', 'hint' => 'Action'],
    ['label' => 'Sign out', 'href' => 'logout.php', 'icon' => '🚪', 'hint' => 'Action'],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php if ($IMPERSONATING): $adm = row('SELECT name, email FROM admins WHERE id = ?', [$SESSION['impersonated_by']]); ?>
<div class="impersonate-bar">🕵 Admin view: you are signed in as <b><?= e($USER['email']) ?></b> (by <?= e($adm['name'] ?? 'admin') ?>). Sensitive changes are disabled.
  <a href="logout.php?exit_admin=1" class="btn btn-sm">Exit to superadmin</a></div>
<?php endif; ?>
<?php if ($bare || !$USER): ?>
<header class="topbar public">
  <a class="brand" href="index.php"><span class="logo">🧠</span> <?= e($site) ?></a>
  <nav class="top-links">
    <a href="index.php#features">Features</a><a href="index.php#pricing">Pricing</a><a href="index.php#faq">FAQ</a>
    <?php if ($USER): ?><a class="btn btn-sm" href="dashboard.php">Dashboard</a>
    <?php else: ?><a href="login.php">Sign in</a><a class="btn btn-sm" href="register.php">Get started</a><?php endif; ?>
    <button class="icon-btn" data-theme-toggle title="Light / dark">🌓</button>
  </nav>
</header>
<main class="public-main">
<?php else: ?>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="dashboard.php"><span class="logo">🧠</span> <?= e($site) ?></a>
    <nav>
      <?php foreach (USER_NAV as [$href, $icon, $label]): ?>
        <a href="<?= $href ?>" class="<?= $active === $href ? 'active' : '' ?>"><span><?= $icon ?></span><?= e($label) ?>
          <?php if ($href === 'notifications.php' && $unread): ?><em class="pill"><?= $unread ?></em><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot"><a href="logout.php">🚪 Sign out</a></div>
  </aside>
  <div class="content">
    <header class="topbar">
      <button class="icon-btn mobile-only" data-sidebar-toggle>☰</button>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="top-actions">
        <button class="icon-btn" data-palette title="Quick actions (Ctrl+K)">⚡</button>
        <a class="icon-btn" href="notifications.php" title="Notifications">🔔<?php if ($unread): ?><em class="dot"></em><?php endif; ?></a>
        <button class="icon-btn" data-theme-toggle title="Light / dark">🌓</button>
        <details class="menu"><summary class="avatar" title="<?= e($USER['email']) ?>"><?php if ($USER['avatar_url']): ?><img src="<?= e($USER['avatar_url']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e(initials($USER['name'], $USER['email'])) ?><?php endif; ?></summary>
          <div class="menu-list"><div style="padding:8px 10px"><b><?= e($USER['name'] ?: 'My account') ?></b><div class="small muted"><?= e($USER['email']) ?></div></div><hr>
            <a href="profile.php">👤 Profile & photo</a><a href="security.php">🔐 Security</a><a href="licenses.php">🔑 My licenses</a>
            <a href="statement.php">🖨 Account statement</a><a href="orders.php">🧾 Invoices</a><a href="support.php">💬 Help & support</a><hr>
            <a href="logout.php" class="danger">🚪 Sign out</a></div></details>
      </div>
    </header>
    <main class="main">
    <?php foreach (active_announcements('portal') as $a): ?>
      <div class="alert alert-<?= e($a['level']) ?>">📣 <b><?= e($a['title']) ?></b> <?= e($a['body']) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
<?php foreach (flash_take() as [$kind, $msg]): ?>
  <div class="alert alert-<?= e($kind) ?>" data-autohide><?= e($msg) ?></div>
<?php endforeach;
}

function page_end(bool $bare = false): void {
    global $USER;
    $site = setting('site_name');
    if ($bare || !$USER): ?>
</main>
<footer class="footer"><span>© <?= gmdate('Y') ?> <?= e($site) ?></span>
  <a href="privacy.php">Privacy</a><a href="terms.php">Terms</a><?php if (setting('support_email')): ?><a href="mailto:<?= e(setting('support_email')) ?>">Contact</a><?php endif; ?></footer>
<?php else: ?>
    </main>
    <footer class="footer"><span>© <?= gmdate('Y') ?> <?= e($site) ?></span><a href="privacy.php">Privacy</a><a href="terms.php">Terms</a><a href="support.php">Help</a></footer>
  </div>
</div>
<?php endif; ?>
<?php if (setting('support_whatsapp')): ?><a class="wa-float" target="_blank" rel="noopener" href="https://wa.me/<?= e(preg_replace('/\D/', '', setting('support_whatsapp'))) ?>" title="WhatsApp support">💬</a><?php endif; ?>
<script src="assets/app.js?v=<?= HM_VERSION ?>"></script>
<script src="assets/ui.js?v=<?= HM_VERSION ?>"></script>
</body></html>
<?php
}

/** Animated split-screen layout for sign in, sign up, password reset and 2FA. */
function auth_start(string $title, string $headline = 'Ace every interview with an AI that knows your story.'): void {
    $site = setting('site_name');
    ?><!doctype html>
<html lang="en" data-theme="dark">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($site) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= HM_VERSION ?>"><link rel="stylesheet" href="assets/landing.css?v=<?= HM_VERSION ?>">
</head>
<body>
<div class="auth-page">
  <aside class="auth-art">
    <div class="orb o1"></div><div class="orb o2"></div>
    <a class="brand" href="index.php"><span class="logo">🧠</span> <?= e($site) ?></a>
    <div class="art-copy"><h2><?= e($headline) ?></h2><p>Real-time answers from your own resume — invisible to screen share.</p></div>
    <div class="quote-rot">
      <div class="on"><b>🎧 Hears the interviewer</b><span>Speakers or Bluetooth headsets — questions become text instantly.</span></div>
      <div><b>📄 Answers from your resume</b><span>Your real projects, roles and numbers, in first person.</span></div>
      <div><b>🙈 Hidden from screen share</b><span>Zoom, Meet and Teams never see the window or cursor.</span></div>
      <div><b>⏰ Never miss a renewal</b><span>Reminders by email, in the portal and inside the app.</span></div>
    </div>
  </aside>
  <main class="auth-main2">
    <div class="auth-top"><a class="icon-btn" href="index.php" title="Home">🏠</a><button class="icon-btn" data-theme-toggle title="Light / dark">🌓</button></div>
    <div class="auth-card2">
    <?php foreach (flash_take() as [$kind, $msg]): ?><div class="alert alert-<?= e($kind) ?>"><?= e($msg) ?></div><?php endforeach;
}

function auth_end(): void {
    ?>
    </div>
  </main>
</div>
<script src="assets/landing.js?v=<?= HM_VERSION ?>"></script>
</body></html>
<?php
}

function settings_google_ready(): bool {
    return setting('google_login_enabled') && setting('google_client_id') !== '' && setting('google_client_secret') !== '';
}

function google_icon(): string {
    return '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
}

function plan_card(array $p, string $cta_href, string $cta = 'Choose plan'): string {
    $per_day = money((int)round($p['price_paise'] / max(1, $p['days'])));
    $features = array_filter(array_map('trim', explode("\n", (string)$p['features'])));
    ob_start(); ?>
    <div class="plan<?= $p['badge'] ? ' featured' : '' ?>">
      <?php if ($p['badge']): ?><div class="plan-badge"><?= e($p['badge']) ?></div><?php endif; ?>
      <h3><?= e($p['name']) ?></h3>
      <div class="plan-days"><?= (int)$p['days'] ?> days</div>
      <div class="plan-price"><?= money($p['price_paise']) ?><?php if ($p['mrp_paise'] > $p['price_paise']): ?> <s><?= money($p['mrp_paise']) ?></s><?php endif; ?></div>
      <div class="muted small"><?= $per_day ?>/day · <?= e($p['description']) ?></div>
      <ul><?php foreach ($features as $f): ?><li>✓ <?= e($f) ?></li><?php endforeach; ?></ul>
      <a class="btn btn-block" href="<?= e($cta_href) ?>"><?= e($cta) ?></a>
    </div>
    <?php return ob_get_clean();
}

function license_days_ring(?int $left, int $total): string {
    $pct = $left === null ? 100 : ($total > 0 ? max(0, min(100, (int)round($left * 100 / $total))) : 0);
    $color = $left === null ? 'var(--accent)' : ($left <= 3 ? 'var(--red)' : ($left <= 7 ? 'var(--amber)' : 'var(--green)'));
    $label = $left === null ? 'Not used' : $left;
    $sub = $left === null ? "$total days" : 'days left';
    return '<div class="ring" style="--p:' . $pct . ';--c:' . $color . '"><div><b>' . e((string)$label) . '</b><span>' . e($sub) . '</span></div></div>';
}
