<?php
require __DIR__ . '/app.php';
require_admin();
$rows = db()->query("SELECT b.id,b.check_in,b.check_out,b.total,b.status,u.full_name,u.email,r.name AS room_name
    FROM bookings b JOIN users u ON u.id=b.user_id JOIN rooms r ON r.id=b.room_id ORDER BY b.id DESC")->fetchAll();
$revenue = (int)db()->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='approved'")->fetchColumn();
$log = db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 15')->fetchAll();
render_header('Admin'); ?>
<h1>Admin dashboard</h1>
<p>Confirmed revenue: <strong>KES <?= number_format($revenue) ?></strong> &middot; Total bookings: <?= count($rows) ?></p>
<table><tr><th>#</th><th>Guest</th><th>Room</th><th>Dates</th><th>Total</th><th>Status</th></tr>
<?php foreach ($rows as $r): ?>
<tr><td><?= (int)$r['id'] ?></td><td><?= e($r['full_name']) ?><br><small><?= e($r['email']) ?></small></td>
<td><?= e($r['room_name']) ?></td><td><?= e($r['check_in']) ?> to <?= e($r['check_out']) ?></td>
<td><?= number_format((int)$r['total']) ?></td><td><?= e($r['status']) ?></td></tr>
<?php endforeach; ?></table>
<h2>Recent security log</h2>
<table><tr><th>Time</th><th>Action</th><th>IP</th></tr>
<?php foreach ($log as $l): ?><tr><td><?= e($l['created_at']) ?></td><td><?= e($l['action']) ?></td><td><?= e($l['ip']) ?></td></tr><?php endforeach; ?></table>
<?php render_footer();
