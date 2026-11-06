<?php
/** Animated landing page with built-in sign-in / create-account panel. */
require __DIR__ . '/_init.php';
$plans = rows('SELECT * FROM plans WHERE active = 1 ORDER BY sort, days');
$site = setting('site_name');
if (!$USER) track('landing_view', ['label' => 'Landing page']);
$ref = normalize_code(input('ref', $_COOKIE['hm_ref'] ?? ''));
if (isset($_GET['ref']) && $ref !== '') set_cookie('hm_ref', $ref, time() + 30 * 86400);
$min_day = $plans ? min(array_map(fn($p) => $p['price_paise'] / max(1, $p['days']), $plans)) : 0;
$google = settings_google_ready();
$cta_attr = $USER ? 'href="dashboard.php"' : 'href="#register" data-auth="register"';
$demo = [
    ['q' => 'Tell me about a challenging project you worked on.', 'a' => 'At my last role I rebuilt our payments API in FastAPI. Response times dropped by 40% after I added Redis caching — and I led the rollout to 50k users.'],
    ['q' => "What's the difference between a process and a thread?", 'a' => 'A process has its own memory space; threads share the memory of their process. Threads are lighter to create, but need locks to share data safely.'],
    ['q' => 'Why should we hire you?', 'a' => "I've shipped production Python services end to end — design, testing and deployment on AWS — and I learn new stacks fast, like moving our team to Docker in two weeks."],
];
$faqs = [
    ['When do my plan days start?', setting('license_starts_on') === 'purchase' ? 'Right after payment.' : 'Only when you activate your key in the desktop app — not when you pay.'],
    ['Can the interviewer see InterviewLens when I share my screen?', 'No. The window and its cursor are excluded from screen capture on Windows 10 (2004+) and 11, so Zoom, Meet and Teams show only what is behind it.'],
    ['Can I move to a new laptop?', 'Yes — add the new Device ID in the portal and move your license (up to ' . (int)setting('device_changes_per_month') . ' times a month).'],
    ['How do I pay?', 'UPI, cards and netbanking. Your activation key is emailed as soon as the payment is confirmed.'],
    ['Will I be reminded before it expires?', 'Yes — by email, in your portal and inside the app, ' . e(setting('reminder_days')) . ' days before.'],
    ['Does it work with Bluetooth headsets?', 'Yes. It captures what your speakers or headset play, including Bluetooth earbuds.'],
];
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($site) ?> — <?= e(setting('tagline')) ?></title>
<meta name="description" content="<?= e($site) ?> listens to your interview, reads the screen and answers from your own resume — invisible to screen share.">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🧠</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/style.css?v=<?= HM_VERSION ?>">
<link rel="stylesheet" href="assets/landing.css?v=<?= HM_VERSION ?>">
</head>
<body class="lp">
<div class="scroll-bar"></div>
<div class="bg-fx" aria-hidden="true"><div class="orb o1"></div><div class="orb o2"></div><div class="orb o3"></div><div class="grid-fx"></div><canvas id="particles"></canvas></div>

<nav class="lp-nav">
  <a class="brand" href="index.php"><span class="logo">🧠</span> <?= e($site) ?></a>
  <div class="lp-links">
    <a class="lnk" href="#features">Features</a><a class="lnk" href="#how">How it works</a><a class="lnk" href="#resume">Resume AI</a>
    <a class="lnk" href="#pricing">Pricing</a><a class="lnk" href="#faq">FAQ</a>
  </div>
  <div class="lp-actions">
    <button class="icon-btn" data-theme-toggle title="Light / dark" aria-label="Toggle theme">🌓</button>
    <?php if ($USER): ?><a class="btn btn-sm btn-glow" href="dashboard.php">Dashboard →</a>
    <?php else: ?><a class="btn btn-sm btn-ghost hide-sm" href="#login" data-auth="login">Sign in</a><a class="btn btn-sm btn-glow" href="#register" data-auth="register">Get started</a><?php endif; ?>
    <button class="icon-btn menu-btn" aria-label="Menu">☰</button>
  </div>
</nav>

<main class="lp-wrap">
  <!-- Hero -->
  <section class="hero2" id="top">
    <div>
      <div class="pill-tag reveal"><b>NEW</b> Answers built from your own resume</div>
      <h1 class="reveal" style="--d:.08s">Your AI co-pilot<br>for <span class="grad-text typed" data-words='["job interviews","coding rounds","HR rounds","system design","client meetings"]'>job interviews</span></h1>
      <p class="lead reveal" style="--d:.16s"><?= e($site) ?> listens to the interviewer, reads your screen and shows the perfect answer in seconds — written from <b>your projects and experience</b>, and <b>invisible to screen share</b>.</p>
      <div class="hero-cta reveal" style="--d:.24s">
        <a class="btn btn-glow" <?= $cta_attr ?>><?= $USER ? 'Open dashboard' : 'Start now' ?> →</a>
        <a class="btn btn-ghost" href="#how">▶ See how it works</a>
      </div>
      <div class="hero-stats reveal" style="--d:.32s">
        <div><b data-count="<?= (int)ceil($min_day / 100) ?>" data-prefix="<?= e(setting('currency_symbol')) ?>">0</b><span>or less per day</span></div>
        <div><b data-count="8" data-suffix="+">0</b><span>AI models supported</span></div>
        <div><b data-count="5" data-suffix=" min">0</b><span>to set up</span></div>
      </div>
    </div>
    <div class="mock-wrap reveal zoom" style="--d:.2s">
      <div class="float-card f1">🙈 Hidden from Zoom · Meet · Teams</div>
      <div class="float-card f2">📄 Resume matched: 3 projects</div>
      <div class="float-card f3">⚡ Answer in 1.2 s</div>
      <div class="mock" data-tilt="mock">
        <div class="mock-bar"><i style="background:#ff5f57"></i><i style="background:#febc2e"></i><i style="background:#28c840"></i>
          <span class="chip days">🔑 30 days left</span><span class="chip hidden">Hidden</span></div>
        <div class="mock-body" id="demo" data-script='<?= e(json_encode($demo)) ?>'>
          <div style="display:flex;align-items:center;gap:10px;color:var(--muted);font-size:13px;font-weight:600">
            <div class="wave"><?php for ($i = 0; $i < 14; $i++): ?><span style="animation-delay:-<?= ($i * 0.13) ?>s"></span><?php endfor; ?></div> Listening to the interviewer…</div>
        </div>
      </div>
    </div>
  </section>

  <div class="marquee reveal" aria-label="Works with"><div class="marquee-track">
    <?php for ($r = 0; $r < 2; $r++) foreach (['Zoom', 'Google Meet', 'Microsoft Teams', 'Webex', 'Skype', 'Bluetooth headsets', 'Claude', 'GPT', 'Gemini', 'Groq'] as $n): ?><span>✦ <?= e($n) ?></span><?php endforeach; ?>
  </div></div>

  <!-- Features -->
  <section class="sec" id="features">
    <div class="sec-head reveal"><span class="eyebrow">Features</span><h2>Everything you need, in one small window</h2><p>Built for real interviews — fast, private and personal to you.</p></div>
    <div class="f-grid">
      <?php foreach ([
          ['🎧', 'Hears the interviewer', 'Captures meeting audio from speakers or Bluetooth headsets and turns questions into text instantly.', true],
          ['🙈', 'Invisible on screen share', 'The window and cursor never appear in Zoom, Meet or Teams.', false],
          ['📄', 'Answers from your resume', 'Upload your resume once. Every answer uses your real projects, roles and numbers.', false],
          ['🖥️', 'Reads coding questions', 'Screen OCR + Analyze solves what is on screen, step by step.', false],
          ['🤖', 'Any AI model', 'Claude, GPT, Gemini, Groq, DeepSeek and more — with automatic fallback.', false],
          ['⌨️', 'Hotkeys & pass-through', 'Control everything from the keyboard and click through to apps behind it.', true],
          ['⏰', 'Never miss a renewal', 'Days-left counter in the app, plus reminders by email and in your portal before your plan ends.', false],
      ] as $i => [$ico, $t, $d, $wide]): ?>
        <div class="f-card reveal<?= $wide ? ' wide' : '' ?>" style="--d:<?= $i * 0.07 ?>s"><div class="f-ico"><?= $ico ?></div><h3><?= e($t) ?></h3><p><?= e($d) ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- How it works -->
  <section class="sec" id="how">
    <div class="sec-head reveal"><span class="eyebrow">How it works</span><h2>From sign-up to your first interview in 5 minutes</h2></div>
    <div class="steps2"><div class="line"><i></i></div>
      <?php foreach ([['👤', 'Create account', 'Email or Google'], ['💎', 'Pick a plan', '30, 60 or 90 days'], ['⬇️', 'Download app', 'Windows 10 / 11'],
                      ['💻', 'Add Device ID', 'Shown in the app'], ['🔑', 'Activate', 'Paste your key — done']] as $i => [$ico, $t, $d]): ?>
        <div class="step2" style="--d:<?= 0.3 + $i * 0.3 ?>s"><div class="num"><?= $ico ?></div><h4><?= e($t) ?></h4><p><?= e($d) ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Resume showcase -->
  <section class="sec" id="resume">
    <div class="split">
      <div class="reveal left"><span class="eyebrow">Resume AI</span><h2>Sounds like <span class="grad-text">you</span>, not a textbook</h2>
        <p class="muted" style="font-size:17px">Upload your resume and the job description. <?= e($site) ?> answers in first person with your real experience — so you can say it naturally.</p>
        <ul class="checks"><li>Uses your projects, companies and metrics by name</li><li>STAR format for behavioural questions</li><li>Never invents experience you don't have</li><li>PDF, Word, text or a photo of your resume</li></ul></div>
      <div class="resume-demo reveal right">
        <div class="doc d1"><h5>📄 resume.pdf</h5><small>Software Engineer · 3 yrs</small><div class="ln" style="width:90%"></div><div class="ln hl" style="width:80%"></div><div class="ln" style="width:70%"></div><div class="ln" style="width:85%"></div><div class="ln hl" style="width:60%"></div><div class="ln" style="width:75%"></div></div>
        <div class="beam"></div>
        <div class="doc d2"><h5>🧠 Your answer</h5><small>First person · STAR</small><div class="ln hl" style="width:95%"></div><div class="ln" style="width:80%"></div><div class="ln" style="width:88%"></div><div class="ln hl" style="width:70%"></div><div class="ln" style="width:60%"></div></div>
      </div>
    </div>
  </section>

  <!-- Pricing -->
  <section class="sec" id="pricing">
    <div class="sec-head reveal"><span class="eyebrow">Pricing</span><h2>Simple plans. No auto-renewal.</h2><p>Pay once. Renew any time — new days are added on top.</p></div>
    <div class="price-grid">
      <?php $base_day = $plans ? $plans[0]['price_paise'] / max(1, $plans[0]['days']) : 0;
      foreach ($plans as $i => $p): $per_day = $p['price_paise'] / max(1, $p['days']); $save = $base_day > 0 ? (int)round((1 - $per_day / $base_day) * 100) : 0;
        $href = $USER ? 'checkout.php?plan=' . urlencode($p['code']) : '#register'; ?>
        <div class="p-card reveal<?= $p['badge'] ? ' hot' : '' ?>" style="--d:<?= $i * 0.1 ?>s" data-tilt>
          <?php if ($p['badge']): ?><div class="p-badge">★ <?= e($p['badge']) ?></div><?php endif; ?>
          <h3><?= e($p['name']) ?><?php if ($save > 0): ?><span class="save">Save <?= $save ?>%</span><?php endif; ?></h3>
          <div class="days"><?= (int)$p['days'] ?> days</div>
          <div class="p-price"><?= money($p['price_paise']) ?><?php if ($p['mrp_paise'] > $p['price_paise']): ?><s><?= money($p['mrp_paise']) ?></s><?php endif; ?></div>
          <div class="p-day"><?= money((int)round($per_day)) ?> per day · <?= e($p['description']) ?></div>
          <ul><?php foreach (array_filter(array_map('trim', explode("\n", (string)$p['features']))) as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
          <a class="btn <?= $p['badge'] ? 'btn-glow' : 'btn-ghost' ?>" href="<?= e($href) ?>" <?= $USER ? '' : 'data-auth="register" data-plan="' . e($p['code']) . '"' ?>>Choose <?= e($p['name']) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- FAQ -->
  <section class="sec" id="faq">
    <div class="sec-head reveal"><span class="eyebrow">FAQ</span><h2>Questions, answered</h2></div>
    <div class="faq2"><?php foreach ($faqs as $i => [$q, $a]): ?>
      <details class="reveal" style="--d:<?= $i * 0.05 ?>s"><summary><?= e($q) ?></summary><div class="ans"><?= e($a) ?></div></details><?php endforeach; ?></div>
  </section>

  <section class="sec"><div class="cta-box reveal zoom">
    <h2>Walk into your next interview ready</h2><p>Set up <?= e($site) ?> in five minutes. Plans cost <?= money((int)ceil($min_day / 100) * 100) ?> a day or less.</p>
    <a class="btn" <?= $cta_attr ?>><?= $USER ? 'Open dashboard →' : 'Create free account →' ?></a></div></section>

  <footer class="lp-foot"><span>© <?= gmdate('Y') ?> <?= e($site) ?> · <?= e(setting('tagline')) ?></span>
    <span><a href="privacy.php">Privacy</a><a href="terms.php">Terms</a><?php if (setting('support_email')): ?><a href="mailto:<?= e(setting('support_email')) ?>">Contact</a><?php endif; ?></span></footer>
</main>
<button class="to-top" aria-label="Back to top">↑</button>

<?php if (!$USER): ?>
<!-- Sign in / create account panel -->
<div class="modal-bg" id="auth" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Sign in or create account">
  <div class="auth-modal">
    <button class="x" aria-label="Close">✕</button>
    <div style="text-align:center"><span class="logo" style="width:46px;height:46px;font-size:24px">🧠</span></div>
    <div class="auth-tabs"><span class="ind"></span><button type="button" data-tab="login" class="on">Sign in</button><button type="button" data-tab="register">Create account</button></div>
    <div class="auth-panes">
      <div class="auth-pane" id="pane-login">
        <h3>Welcome back 👋</h3><p class="auth-sub">Sign in to your <?= e($site) ?> account.</p>
        <?php if ($google): ?><a class="google-btn" href="google.php?start=1"><?= google_icon() ?> Continue with Google</a><div class="divider">or with email</div><?php endif; ?>
        <form method="post" action="login.php" data-auth-form>
          <?= csrf_field() ?>
          <div class="field"><input id="l-email" name="email" type="email" placeholder=" " required autocomplete="email"><label for="l-email">Email address</label></div>
          <div class="field"><input id="l-pass" name="password" type="password" placeholder=" " required autocomplete="current-password"><label for="l-pass">Password</label><button type="button" class="eye" aria-label="Show password">👁</button></div>
          <div class="auth-row"><span></span><a href="forgot.php">Forgot password?</a></div>
          <button class="btn btn-xl btn-glow" type="submit"><span class="spin"></span> Sign in</button>
        </form>
        <p class="auth-sub center" style="margin-top:16px">New here? <a href="#" data-switch="register">Create an account</a></p>
      </div>
      <div class="auth-pane hide" id="pane-register">
        <h3>Create your account ✨</h3><p class="auth-sub">Takes less than a minute.</p>
        <?php if (!setting('registration_open')): ?>
          <div class="alert alert-warning">New sign-ups are paused right now. Please check back soon.</div>
        <?php else: ?>
          <?php if ($google): ?><a class="google-btn" href="google.php?start=1<?= $ref ? '&ref=' . e($ref) : '' ?>"><?= google_icon() ?> Sign up with Google</a><div class="divider">or with email</div><?php endif; ?>
          <form method="post" action="register.php" data-auth-form>
            <?= csrf_field() ?>
            <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
            <div class="field"><input id="r-name" name="name" type="text" placeholder=" " required maxlength="120" autocomplete="name"><label for="r-name">Full name</label></div>
            <div class="field"><input id="r-email" name="email" type="email" placeholder=" " required autocomplete="email"><label for="r-email">Email address</label></div>
            <div class="field"><input id="r-pass" name="password" type="password" placeholder=" " required data-strength autocomplete="new-password" minlength="<?= (int)setting('password_min_length') ?>"><label for="r-pass">Password</label><button type="button" class="eye" aria-label="Show password">👁</button></div>
            <div class="strength"><div></div></div>
            <div class="hint">At least <?= (int)setting('password_min_length') ?> characters with letters and numbers.</div>
            <div class="field"><input id="r-pass2" name="password2" type="password" placeholder=" " required autocomplete="new-password"><label for="r-pass2">Confirm password</label></div>
            <div class="field"><input id="r-ref" name="ref" type="text" placeholder=" " value="<?= e($ref) ?>" maxlength="16"><label for="r-ref">Referral code (optional)</label></div>
            <label class="terms"><input type="checkbox" name="agree" value="1" required> <span>I agree to the <a href="terms.php" target="_blank">Terms</a> and <a href="privacy.php" target="_blank">Privacy Policy</a>, including activity tracking.</span></label>
            <button class="btn btn-xl btn-glow" type="submit"><span class="spin"></span> Create account</button>
          </form>
          <p class="auth-sub center" style="margin-top:16px">Already have an account? <a href="#" data-switch="login">Sign in</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
  // Remember which plan was chosen so checkout can continue after sign-up.
  document.querySelectorAll('[data-plan]').forEach(b => b.addEventListener('click', () => {
    document.cookie = 'hm_plan=' + encodeURIComponent(b.dataset.plan) + ';path=/;max-age=86400;samesite=lax';
  }));
</script>
<?php endif; ?>
<script src="assets/landing.js?v=<?= HM_VERSION ?>"></script>
</body>
</html>
