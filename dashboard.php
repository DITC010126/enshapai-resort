<?php
require __DIR__ . '/app.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (cancel_booking((int)($_POST['cancel'] ?? 0), (int)$user['id'])) {
        flash('ok', 'Booking cancelled.');
    } else {
        flash('error', 'That booking could not be cancelled.');
    }
    redirect('dashboard.php');
}
$st = db()->prepare('SELECT b.*, r.name AS room_name FROM bookings b JOIN rooms r ON r.id=b.room_id WHERE b.user_id=? ORDER BY b.id DESC');
$st->execute([(int)$user['id']]);
$rows = $st->fetchAll();
render_header('My bookings'); ?>
<h1>My bookings</h1>
<?php if (!$rows): ?><p>No bookings yet. <a href="rooms.php">Browse rooms</a>.</p><?php else: ?>
<table><tr><th>#</th><th>Room</th><th>Dates</th><th>Total (KES)</th><th>Status</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= (int)$r['id'] ?></td><td><?= e($r['room_name']) ?></td>
<td><?= e($r['check_in']) ?> to <?= e($r['check_out']) ?></td>
<td><?= number_format((int)$r['total']) ?></td><td><?= e($r['status']) ?></td>
<td>
<?php if ($r['status'] === 'pending'): ?><a href="payment.php?booking=<?= (int)$r['id'] ?>">Pay</a><?php endif; ?>
<?php if (in_array($r['status'], ['pending', 'confirmed'], true)): ?>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="cancel" value="<?= (int)$r['id'] ?>"><button class="link">Cancel</button></form>
<?php endif; ?>
</td></tr>
<?php endforeach; ?></table>
<?php endif; render_footer();
