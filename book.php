<?php
require __DIR__ . '/app.php';
$user = require_login();
$roomId = (int)($_GET['room'] ?? $_POST['room'] ?? 0);
$st = db()->prepare('SELECT * FROM rooms WHERE id=?');
$st->execute([$roomId]);
$room = $st->fetch();
if (!$room) { http_response_code(404); exit('Room not found.'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$id, $errors] = create_booking((int)$user['id'], $roomId, (string)($_POST['check_in'] ?? ''), (string)($_POST['check_out'] ?? ''), (int)($_POST['guests'] ?? 0));
    if ($id) redirect('payment.php?booking=' . $id);
}
$in = (string)($_POST['check_in'] ?? $_GET['check_in'] ?? '');
$out = (string)($_POST['check_out'] ?? $_GET['check_out'] ?? '');
render_header('Book'); ?>
<h1>Book: <?= e($room['name']) ?></h1>
<p>KES <?= number_format((int)$room['price_per_night']) ?> per night &middot; up to <?= (int)$room['capacity'] ?> guests</p>
<?php foreach ($errors as $er): ?><p class="flash error"><?= e($er) ?></p><?php endforeach; ?>
<form method="post" class="form">
  <?= csrf_field() ?>
  <input type="hidden" name="room" value="<?= (int)$roomId ?>">
  <label>Check-in <input type="date" name="check_in" value="<?= e($in) ?>" required></label>
  <label>Check-out <input type="date" name="check_out" value="<?= e($out) ?>" required></label>
  <label>Guests <input type="number" name="guests" min="1" max="<?= (int)$room['capacity'] ?>" value="<?= e($_POST['guests'] ?? '1') ?>" required></label>
  <button class="btn">Continue to payment</button>
</form>
<?php render_footer();
