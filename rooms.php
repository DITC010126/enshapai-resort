<?php
require __DIR__ . '/app.php';
$in = (string)($_GET['check_in'] ?? '');
$out = (string)($_GET['check_out'] ?? '');
$searching = parse_date($in) && parse_date($out) && nights($in, $out) > 0;
$rooms = db()->query('SELECT * FROM rooms ORDER BY price_per_night')->fetchAll();
render_header('Rooms'); ?>
<h1>Rooms and availability</h1>
<form method="get" class="form row">
  <label>Check-in <input type="date" name="check_in" value="<?= e($in) ?>" required></label>
  <label>Check-out <input type="date" name="check_out" value="<?= e($out) ?>" required></label>
  <button class="btn">Search</button>
</form>
<div class="cards">
<?php foreach ($rooms as $r):
    $free = $searching ? room_available((int)$r['id'], $in, $out) : true; ?>
  <div class="card">
    <h3><?= e($r['name']) ?></h3>
    <p><?= e($r['description']) ?></p>
    <p><strong>KES <?= number_format((int)$r['price_per_night']) ?></strong> / night &middot; sleeps <?= (int)$r['capacity'] ?></p>
    <?php if ($searching && !$free): ?>
      <p class="flash error">Not available for these dates</p>
    <?php else: ?>
      <a class="btn" href="book.php?room=<?= (int)$r['id'] ?>&amp;check_in=<?= e($in) ?>&amp;check_out=<?= e($out) ?>">Book this room</a>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php render_footer();
