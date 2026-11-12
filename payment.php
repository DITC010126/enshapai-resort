<?php
require __DIR__ . '/app.php';
$user = require_login();
$bid = (int)($_GET['booking'] ?? $_POST['booking'] ?? 0);
$st = db()->prepare('SELECT b.*, r.name AS room_name FROM bookings b JOIN rooms r ON r.id=b.room_id WHERE b.id=? AND b.user_id=?');
$st->execute([$bid, (int)$user['id']]);
$b = $st->fetch();
if (!$b) { http_response_code(404); exit('Booking not found.'); }

$msg = '';
$ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$ok, $msg] = process_payment($bid, (int)$user['id'], (string)($_POST['holder'] ?? ''), (string)($_POST['card'] ?? ''), (string)($_POST['expiry'] ?? ''), (string)($_POST['cvv'] ?? ''));
    if ($ok) { flash('ok', $msg); redirect('dashboard.php'); }
}
render_header('Payment'); ?>
<h1>Pay for your stay</h1>
<p><?= e($b['room_name']) ?>: <?= e($b['check_in']) ?> to <?= e($b['check_out']) ?> &middot; <strong>KES <?= number_format((int)$b['total']) ?></strong></p>
<?php if ($msg): ?><p class="flash error"><?= e($msg) ?></p><?php endif; ?>
<?php if ($b['status'] === 'pending'): ?>
<form method="post" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="booking" value="<?= (int)$bid ?>">
  <label>Cardholder name <input name="holder" required maxlength="60" autocomplete="cc-name"></label>
  <label>Card number <input name="card" required inputmode="numeric" maxlength="23" autocomplete="cc-number"></label>
  <label>Expiry (MM/YY) <input name="expiry" required placeholder="MM/YY" maxlength="5" autocomplete="cc-exp"></label>
  <label>Security code <input name="cvv" required inputmode="numeric" maxlength="4" autocomplete="cc-csc"></label>
  <small>Demo only. Use 4242 4242 4242 4242 (approved) or 4000 0000 0000 0002 (declined). Any future expiry and any 3-digit code.</small>
  <button class="btn">Pay now</button>
</form>
<?php else: ?><p>This booking is <?= e($b['status']) ?>.</p><?php endif; ?>
<?php render_footer();
