<?php
require __DIR__ . '/app.php';
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $errors = register_user((string)($_POST['full_name'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
    if (!$errors) {
        flash('ok', 'Account created. Please log in.');
        redirect('login.php');
    }
}
render_header('Register'); ?>
<h1>Create your account</h1>
<?php foreach ($errors as $er): ?><p class="flash error"><?= e($er) ?></p><?php endforeach; ?>
<form method="post" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <label>Full name <input name="full_name" required maxlength="80" value="<?= e($_POST['full_name'] ?? '') ?>"></label>
  <label>Email <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
  <label>Password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
  <small>At least 8 characters with upper-case, lower-case and a number.</small>
  <button class="btn">Register</button>
</form>
<?php render_footer();
