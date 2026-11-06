<?php
require __DIR__ . '/_init.php';

// Link from the email.
if (isset($_GET['token'])) {
    $t = consume_token('verify', (string)$_GET['token']);
    if (!$t) {
        auth_start('Verify email');
        echo '<div><div class="big-emoji">⌛</div><h1>Link expired</h1><p class="muted">This verification link is invalid or was already used.</p>'
            . ($USER ? '<form method="post" action="verify.php">' . csrf_field() . '<button class="btn" name="resend" value="1">Send a new link</button></form>'
            : '<a class="btn" href="login.php">Sign in to get a new link</a>') . '</div>';
        auth_end();
        exit;
    }
    $u = user_by_id((int)$t['owner_id']);
    if ($u) {
        mark_verified($u);
        track('verify_email', ['user_id' => (int)$u['id'], 'label' => 'Email verified']);
    }
    flash('Email verified! ✅ Let\'s set up your plan.', 'success');
    redirect($USER ? 'get-started.php' : 'login.php');
}

$u = require_login(false);
if ($u['email_verified']) redirect('get-started.php');

$msg = isset($_GET['sent']) ? 'We sent a verification link to ' . $u['email'] . '.' : '';
if (is_post()) {
    if (isset($_POST['resend'])) {
        if (!rate_limit('verify-resend:' . $u['id'], 3, 3600)) {
            $msg = 'You can request up to 3 links per hour. Please check your spam folder.';
        } else {
            send_verification_email($u);
            $msg = 'A new verification link was sent to ' . $u['email'] . '.';
        }
    } elseif (isset($_POST['change_email'])) {
        $new = strtolower(input('email'));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL) || email_domain_blocked($new)) $msg = 'Please enter a valid permanent email.';
        elseif (val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$new, $u['id']])) $msg = 'That email is already registered.';
        else {
            update('users', ['email' => $new], 'id = ?', [$u['id']]);
            $u['email'] = $new;
            send_verification_email($u);
            $msg = "Email changed. We sent a verification link to $new.";
        }
    }
}

auth_start('Verify your email');
?>
<div>
  <div class="big-emoji">📬</div>
  <h1 class="center">Check your inbox</h1>
  <?php if ($msg): ?><div class="alert alert-info"><?= e($msg) ?></div><?php endif; ?>
  <p class="muted center">Click the link we emailed to <b><?= e($u['email']) ?></b> to verify your account. It expires in 24 hours.</p>
  <form method="post" class="center"><?= csrf_field() ?><button class="btn" name="resend" value="1">Resend email</button>
    <a class="btn btn-ghost" href="logout.php">Sign out</a></form>
  <details style="margin-top:18px"><summary class="muted">Wrong email address?</summary>
    <form method="post"><?= csrf_field() ?><label>New email</label>
      <div class="input-group"><input type="email" name="email" required><button class="btn" name="change_email" value="1">Change</button></div></form>
  </details>
  <?php if (!setting('email_enabled')): ?><p class="hint center">Admin note: real email sending is off, so this email is only in Superadmin → Emails.</p><?php endif; ?>
</div>
<?php auth_end();
