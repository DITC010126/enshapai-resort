<?php
declare(strict_types=1);
/**
 * Enshapai Resort - automated unit + security tests (no extra libraries needed).
 * Run from project root:   php tests/run_tests.php
 * Output is printed and saved to tests/results.txt (paste into your report).
 */
define('DB_FILE', sys_get_temp_dir() . '/enshapai_test_' . bin2hex(random_bytes(4)) . '.sqlite');
require __DIR__ . '/../app.php';

$results = [];
function check(string $id, string $name, bool $pass): void
{
    global $results;
    $results[] = [$id, $name, $pass ? 'PASS' : 'FAIL'];
}
function status_of(int $id): string
{
    return (string)db()->query('SELECT status FROM bookings WHERE id=' . $id)->fetchColumn();
}

// ---- Unit tests: validation helpers
check('UT-01', 'Strong password accepted', valid_password('Rally2026x'));
check('UT-02', 'Weak password (no digit/upper) rejected', !valid_password('password'));
check('UT-03', 'Luhn accepts valid test card', luhn_valid('4242424242424242'));
check('UT-04', 'Luhn rejects invalid card number', !luhn_valid('4242424242424241'));
check('UT-05', 'Card brand detection (Visa)', card_brand('4242424242424242') === 'Visa');
check('UT-06', 'Nights calculation', nights('2026-12-01', '2026-12-04') === 3);
check('UT-07', 'Invalid date rejected', parse_date('2026-02-30') === null);
check('UT-08', 'Output encoding blocks XSS', e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');

// ---- Functional tests: registration and login
check('FT-01', 'Registration succeeds with valid data', register_user('Test Guest', 'guest@test.com', 'Rally2026x') === []);
check('FT-02', 'Duplicate email rejected', register_user('Test Guest', 'GUEST@test.com', 'Rally2026x') !== []);
check('FT-03', 'Invalid email rejected', register_user('X', 'not-an-email', 'Rally2026x') !== []);
[$u] = attempt_login('guest@test.com', 'Rally2026x');
check('FT-04', 'Login succeeds with correct password', $u !== null);
[$bad] = attempt_login('guest@test.com', 'WrongPass1');
check('FT-05', 'Login fails with wrong password', $bad === null);
$hash = (string)db()->query("SELECT password_hash FROM users WHERE email='guest@test.com'")->fetchColumn();
check('FT-06', 'Password stored as hash, not plain text', $hash !== 'Rally2026x' && str_starts_with($hash, '$'));

// ---- Security tests
[$sqli] = attempt_login("' OR '1'='1' --", 'x');
check('ST-01', 'SQL injection in login email does not authenticate', $sqli === null);
for ($i = 0; $i < 5; $i++) {
    attempt_login('guest@test.com', 'WrongPass1');
}
[$locked, $lockMsg] = attempt_login('guest@test.com', 'Rally2026x');
check('ST-02', 'Account locks after 5 failed attempts (even with right password)', $locked === null && str_contains($lockMsg, 'locked'));
[, $m1] = attempt_login('nobody@test.com', 'x');
[, $m2] = attempt_login('admin@enshapai.test', 'wrong');
check('ST-03', 'Same error for unknown user and wrong password (no enumeration)', $m1 === $m2);
check('ST-04', 'CSRF token is 64 hex chars and stable per session', strlen(csrf_token()) === 64 && csrf_token() === csrf_token());

// ---- Booking tests
register_user('Second Guest', 'second@test.com', 'Safari2026x');
$uid = (int)db()->query("SELECT id FROM users WHERE email='second@test.com'")->fetchColumn();
$today = '2026-11-09';
[$b1] = create_booking($uid, 1, '2026-12-01', '2026-12-04', 2, $today);
check('FT-07', 'Booking created for available room', $b1 !== null);
[$b2] = create_booking($uid, 1, '2026-12-03', '2026-12-06', 2, $today);
check('FT-08', 'Overlapping booking rejected (no double-booking)', $b2 === null);
[$b3] = create_booking($uid, 1, '2026-12-04', '2026-12-06', 2, $today);
check('FT-09', 'Back-to-back booking (check-in on previous check-out) allowed', $b3 !== null);
[$b4] = create_booking($uid, 2, '2026-10-01', '2026-10-03', 2, $today);
check('FT-10', 'Past check-in date rejected', $b4 === null);
[$b5] = create_booking($uid, 2, '2026-12-10', '2026-12-09', 2, $today);
check('FT-11', 'Check-out before check-in rejected', $b5 === null);
[$b6] = create_booking($uid, 1, '2027-01-10', '2027-01-12', 9, $today);
check('FT-12', 'Guests over room capacity rejected', $b6 === null);
$total = (int)db()->query('SELECT total FROM bookings WHERE id=' . (int)$b1)->fetchColumn();
check('FT-13', 'Total price = nights x nightly rate (3 x 6500)', $total === 19500);

// ---- Payment tests
[$ok] = process_payment((int)$b1, $uid, 'Second Guest', '4242 4242 4242 4242', '12/30', '123');
check('FT-14', 'Valid test card is approved and booking confirmed', $ok && status_of((int)$b1) === 'confirmed');
[$ok2] = process_payment((int)$b3, $uid, 'Second Guest', '4000000000000002', '12/30', '123');
check('FT-15', 'Declined card leaves booking pending', !$ok2 && status_of((int)$b3) === 'pending');
[$ok3] = process_payment((int)$b3, $uid, 'Second Guest', '4242424242424241', '12/30', '123');
check('FT-16', 'Invalid (non-Luhn) card number rejected', !$ok3);
[$ok4] = process_payment((int)$b3, $uid, 'Second Guest', '4242424242424242', '01/20', '123');
check('FT-17', 'Expired card rejected', !$ok4);
[$ok5] = process_payment((int)$b3, 9999, 'Hacker', '4242424242424242', '12/30', '123');
check('ST-05', "Cannot pay for another user's booking (ownership check)", !$ok5);
$cols = array_column(db()->query('PRAGMA table_info(payments)')->fetchAll(), 'name');
$stored = (string)db()->query('SELECT card_last4 FROM payments LIMIT 1')->fetchColumn();
check('ST-06', 'Only last 4 digits stored; no full card number or CVV column', !in_array('card_number', $cols, true) && !in_array('cvv', $cols, true) && strlen($stored) === 4);
check('FT-18', 'Owner can cancel own booking', cancel_booking((int)$b3, $uid));
check('ST-07', "User cannot cancel someone else's booking", !cancel_booking((int)$b1, 9999));

// ---- Report
$pass = count(array_filter($results, fn($r) => $r[2] === 'PASS'));
$out = str_pad('ID', 8) . str_pad('Test case', 78) . "Result\n" . str_repeat('-', 94) . "\n";
foreach ($results as [$id, $name, $r]) {
    $out .= str_pad($id, 8) . str_pad($name, 78) . $r . "\n";
}
$out .= str_repeat('-', 94) . "\nTotal: " . count($results) . '   Passed: ' . $pass . '   Failed: ' . (count($results) - $pass) . "\nPHP " . PHP_VERSION . ' - ' . date('Y-m-d H:i') . "\n";
echo $out;
file_put_contents(__DIR__ . '/results.txt', $out);
@unlink(DB_FILE);
exit($pass === count($results) ? 0 : 1);
