<?php
/** One-time installer: database connection, tables, default plans, keys and the first superadmin. */
require __DIR__ . '/_init.php';

$config_file = __DIR__ . '/../shared/config.php';
$has_admin = false;
if (hm_installed()) {
    try { $has_admin = (int)val('SELECT COUNT(*) FROM admins') > 0; } catch (Throwable $e) { $has_admin = false; }
}
if ($has_admin) {
    http_response_code(403);
    exit('<p style="font-family:Segoe UI">Setup is already complete. <a href="login.php">Sign in</a>.</p>');
}

$errors = [];
$step_db_ok = false;
if (hm_installed()) {
    try { db(); $step_db_ok = true; }
    catch (Throwable $e) { $errors[] = 'Could not connect with shared/config.php: ' . $e->getMessage() . ' — check MySQL is running, or enter the details below.'; }
}
$host = input('db_host', '127.0.0.1');
$port = input_int('db_port', 3306);
$name = input('db_name', 'hiremind');
$user = input('db_user', 'root');

function install_schema(): void {
    $sql = file_get_contents(__DIR__ . '/../shared/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);
    seed_defaults();
    license_public_pem();   // creates the license signing keys
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid()) {
    if (input('step') === 'db' && !$step_db_ok) {
        $pass = $_POST['db_pass'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) $errors[] = 'Database name may use letters, numbers and _ only.';
        if (!$errors) {
            try {
                $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $cfg = "<?php\n// Created by setup.php on " . gmdate('c') . ". Keep this file private.\nreturn " . var_export([
                    'db_host' => $host, 'db_port' => $port, 'db_name' => $name, 'db_user' => $user, 'db_pass' => $pass], true) . ";\n";
                if (file_put_contents($config_file, $cfg) === false) throw new RuntimeException('Cannot write shared/config.php — check folder permissions.');
                $step_db_ok = true;
                install_schema();
                $guess = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
                settings_set(['admin_url' => rtrim($guess . dirname($_SERVER['SCRIPT_NAME']), '/\\')]);
                if (input('site_url')) settings_set(['site_url' => rtrim(input('site_url'), '/')]);
                redirect('setup.php');
            } catch (Throwable $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif (input('step') === 'admin' && $step_db_ok) {
        $email = strtolower(input('email'));
        $pw = $_POST['password'] ?? '';
        try { install_schema(); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
        if (strlen($pw) < 10 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) $errors[] = 'Admin password needs 10+ characters with letters and numbers.';
        if ($pw !== ($_POST['password2'] ?? '')) $errors[] = "Passwords don't match.";
        if (!$errors) {
            $id = insert('admins', ['email' => $email, 'name' => input('name') ?: 'Superadmin', 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'role' => 'superadmin', 'created_at' => now()]);
            settings_set(['site_name' => input('site_name') ?: 'InterviewLens', 'support_email' => $email]);
            insert('audit_logs', ['admin_id' => $id, 'action' => 'setup_complete', 'detail' => 'Installed v' . HM_VERSION, 'ip' => client_ip(), 'created_at' => now()]);
            flash('Setup complete 🎉 Sign in with your new superadmin account.', 'success');
            redirect('login.php');
        }
    }
}
?><!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>InterviewLens setup</title><link rel="stylesheet" href="assets/style.css"></head><body><main class="public-main">
<div class="auth-card" style="max-width:520px">
  <div class="big-emoji">🛠️</div><h1>InterviewLens setup</h1>
  <p class="muted center">Step <?= $step_db_ok ? '2' : '1' ?> of 2 — <?= $step_db_ok ? 'create the superadmin account' : 'connect the MySQL database' ?></p>
  <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
  <?php if (!$step_db_ok): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="step" value="db">
    <div class="form-row"><div><label>MySQL host</label><input name="db_host" value="<?= e($host) ?>" required></div><div><label>Port</label><input name="db_port" type="number" value="<?= $port ?>" required></div></div>
    <label>Database name</label><input name="db_name" value="<?= e($name) ?>" required><div class="hint">Created automatically if it doesn't exist.</div>
    <div class="form-row"><div><label>MySQL user</label><input name="db_user" value="<?= e($user) ?>" required></div><div><label>MySQL password</label><input name="db_pass" type="password"></div></div>
    <div class="hint">XAMPP default: user <b>root</b>, empty password.</div>
    <label>User portal URL</label><input name="site_url" value="http://127.0.0.1:8080"><div class="hint">Where the <b>user</b> folder is served. You can change it later in Settings.</div>
    <br><button class="btn btn-block">Connect & create tables</button></form>
  <?php else: ?>
  <div class="alert alert-success">✅ Database connected. Finishing this step creates the tables, the 3 plans and your superadmin account.</div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="step" value="admin">
    <label>Product name</label><input name="site_name" value="InterviewLens">
    <label>Your name</label><input name="name" value="Superadmin">
    <label>Superadmin email</label><input name="email" type="email" required>
    <label>Password</label><input name="password" type="password" required minlength="10">
    <label>Confirm password</label><input name="password2" type="password" required>
    <br><br><button class="btn btn-block">Finish setup</button></form>
  <?php endif; ?>
</div></main></body></html>
