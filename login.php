<?php
require __DIR__ . '/app.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$user, $msg] = attempt_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($user) {
        session_regenerate_id(true);   // [SEC] prevent session fixation
        $_SESSION['uid'] = (int)$user['id'];
        redirect($user['role'] === 'admin' ? 'admin.php' : 'dashboard.php');
    }
    $error = $msg;
}
render_header('Login'); ?>
<h1>Login</h1>
<?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="form">
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" required></label>
  <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn">Login</button>
</form>
<?php render_footer();
