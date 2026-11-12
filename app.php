<?php
declare(strict_types=1);
/**
 * Enshapai Resort - core library (bootstrap, security helpers, business logic).
 * Secure SDLC controls are marked with [SEC].
 */

if (PHP_SAPI !== 'cli') {
    // [SEC] hardened session cookie + security headers
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
} else {
    $_SESSION = [];
}

if (!defined('DB_FILE')) {
    define('DB_FILE', __DIR__ . '/data/enshapai.sqlite');
}

function db(): PDO
{
    static $pdo = null;
    static $file = null;
    if ($pdo && $file === DB_FILE) {
        return $pdo;
    }
    if (!is_dir(dirname(DB_FILE))) {
        mkdir(dirname(DB_FILE), 0750, true);
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,   // [SEC] real prepared statements
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $file = DB_FILE;
    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'guest',
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        type TEXT NOT NULL,
        price_per_night INTEGER NOT NULL,
        capacity INTEGER NOT NULL,
        description TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        room_id INTEGER NOT NULL REFERENCES rooms(id),
        check_in TEXT NOT NULL,
        check_out TEXT NOT NULL,
        guests INTEGER NOT NULL,
        total INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_id INTEGER NOT NULL REFERENCES bookings(id),
        card_brand TEXT NOT NULL,
        card_last4 TEXT NOT NULL,
        amount INTEGER NOT NULL,
        reference TEXT NOT NULL,
        status TEXT NOT NULL,
        paid_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT NOT NULL,
        ip TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");

    if ((int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO rooms (name,type,price_per_night,capacity,description) VALUES (?,?,?,?,?)');
        $rooms = [
            ['Savannah Standard', 'Standard', 6500, 2, 'Comfortable double room with garden view and breakfast.'],
            ['Rally Deluxe', 'Deluxe', 9800, 3, 'Spacious room with balcony, ideal for WRC spectators.'],
            ['Family Cottage', 'Cottage', 14500, 5, 'Private two-bedroom cottage with fireplace and kitchenette.'],
            ['Enshapai Executive Suite', 'Suite', 21000, 4, 'Premium suite with lounge, private veranda and room service.'],
        ];
        foreach ($rooms as $r) {
            $ins->execute($r);
        }
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() === 0) {
        // Demo admin. CHANGE THIS PASSWORD before any real deployment.
        $pdo->prepare('INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,?)')
            ->execute(['Resort Admin', 'admin@enshapai.test', password_hash('Admin@12345', PASSWORD_DEFAULT), 'admin']);
    }
}

/* ---------------------------- generic helpers ---------------------------- */

function e(?string $s): string   // [SEC] output encoding against XSS
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string    // [SEC] CSRF protection
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(403);
        exit('Invalid or missing security token. Go back and try again.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function audit(string $action, ?int $userId = null): void   // [SEC] security logging
{
    db()->prepare('INSERT INTO audit_log (user_id,action,ip) VALUES (?,?,?)')
        ->execute([$userId, $action, $_SERVER['REMOTE_ADDR'] ?? 'cli']);
}

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $st = db()->prepare('SELECT id,full_name,email,role FROM users WHERE id=?');
    $st->execute([(int)$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function require_login(): array   // [SEC] authentication check
{
    $u = current_user();
    if (!$u) {
        flash('error', 'Please log in first.');
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array   // [SEC] role-based access control
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Access denied.');
    }
    return $u;
}

/* ------------------------------ validation ------------------------------ */

function valid_password(string $p): bool
{
    return strlen($p) >= 8 && preg_match('/[A-Z]/', $p) && preg_match('/[a-z]/', $p) && preg_match('/\d/', $p);
}

function luhn_valid(string $n): bool
{
    $sum = 0;
    $alt = false;
    for ($i = strlen($n) - 1; $i >= 0; $i--) {
        $d = (int)$n[$i];
        if ($alt) {
            $d *= 2;
            if ($d > 9) {
                $d -= 9;
            }
        }
        $sum += $d;
        $alt = !$alt;
    }
    return $sum % 10 === 0;
}

function card_brand(string $n): string
{
    if (preg_match('/^4/', $n)) return 'Visa';
    if (preg_match('/^(5[1-5]|2[2-7])/', $n)) return 'Mastercard';
    if (preg_match('/^3[47]/', $n)) return 'Amex';
    return 'Card';
}

function parse_date(string $d): ?DateTimeImmutable
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $dt : null;
}

function nights(string $in, string $out): int
{
    $a = parse_date($in);
    $b = parse_date($out);
    return ($a && $b) ? (int)$a->diff($b)->format('%r%a') : 0;
}

/* ---------------------------- business logic ---------------------------- */

function register_user(string $name, string $email, string $password): array
{
    $errors = [];
    $name = trim($name);
    $email = strtolower(trim($email));
    if ($name === '' || mb_strlen($name) > 80) $errors[] = 'Enter your full name (max 80 characters).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (!valid_password($password)) $errors[] = 'Password needs 8+ characters with upper-case, lower-case and a digit.';
    if ($errors) return $errors;

    $st = db()->prepare('SELECT 1 FROM users WHERE email=?');
    $st->execute([$email]);
    if ($st->fetch()) return ['An account with that email already exists.'];

    db()->prepare('INSERT INTO users (full_name,email,password_hash) VALUES (?,?,?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);   // [SEC] salted hash
    audit('register:' . $email, (int)db()->lastInsertId());
    return [];
}

/** Returns [user|null, message]. Locks the account for 5 min after 5 failures. [SEC] */
function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $st = db()->prepare('SELECT * FROM users WHERE email=?');
    $st->execute([$email]);
    $u = $st->fetch();
    $generic = 'Invalid email or password.';   // [SEC] no user enumeration

    if (!$u) {
        password_verify($password, password_hash('dummy', PASSWORD_DEFAULT)); // equalise timing
        audit('login_fail_unknown');
        return [null, $generic];
    }
    if ((int)$u['locked_until'] > time()) {
        audit('login_locked', (int)$u['id']);
        return [null, 'Account temporarily locked. Try again in a few minutes.'];
    }
    if (!password_verify($password, $u['password_hash'])) {
        $fails = (int)$u['failed_attempts'] + 1;
        $lock = $fails >= 5 ? time() + 300 : 0;
        db()->prepare('UPDATE users SET failed_attempts=?, locked_until=? WHERE id=?')
            ->execute([$lock ? 0 : $fails, $lock, $u['id']]);
        audit('login_fail', (int)$u['id']);
        return [null, $generic];
    }
    db()->prepare('UPDATE users SET failed_attempts=0, locked_until=0 WHERE id=?')->execute([$u['id']]);
    audit('login_ok', (int)$u['id']);
    return [$u, 'ok'];
}

function room_available(int $roomId, string $in, string $out): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM bookings
        WHERE room_id=? AND status IN ('pending','confirmed')
        AND NOT (check_out <= ? OR check_in >= ?)");
    $st->execute([$roomId, $in, $out]);
    return (int)$st->fetchColumn() === 0;
}

/** Returns [bookingId|null, errors[]] */
function create_booking(int $userId, int $roomId, string $in, string $out, int $guests, ?string $today = null): array
{
    $errors = [];
    $today = $today ?? date('Y-m-d');
    $a = parse_date($in);
    $b = parse_date($out);
    if (!$a || !$b) return [null, ['Enter valid check-in and check-out dates.']];
    if ($in < $today) $errors[] = 'Check-in cannot be in the past.';
    $n = nights($in, $out);
    if ($n < 1) $errors[] = 'Check-out must be after check-in.';
    if ($n > 30) $errors[] = 'Maximum stay is 30 nights.';

    $st = db()->prepare('SELECT * FROM rooms WHERE id=?');
    $st->execute([$roomId]);
    $room = $st->fetch();
    if (!$room) return [null, ['Room not found.']];
    if ($guests < 1 || $guests > (int)$room['capacity']) $errors[] = 'Guests must be between 1 and ' . $room['capacity'] . ' for this room.';
    if ($errors) return [null, $errors];

    if (!room_available($roomId, $in, $out)) return [null, ['Sorry, that room is not available for those dates.']];

    db()->prepare('INSERT INTO bookings (user_id,room_id,check_in,check_out,guests,total) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, $roomId, $in, $out, $guests, $n * (int)$room['price_per_night']]);
    $id = (int)db()->lastInsertId();
    audit('booking_created:' . $id, $userId);
    return [$id, []];
}

/**
 * SIMULATED card payment. Real systems must use a PCI-DSS compliant gateway so card
 * data never touches our server. We validate, then store ONLY brand + last 4 digits.
 * Test cards: 4242424242424242 (approved), 4000000000000002 (declined).
 * Returns [approved(bool), message].
 */
function process_payment(int $bookingId, int $userId, string $holder, string $number, string $expiry, string $cvv): array
{
    $number = preg_replace('/\D/', '', $number) ?? '';
    $st = db()->prepare('SELECT * FROM bookings WHERE id=? AND user_id=?');   // [SEC] ownership check
    $st->execute([$bookingId, $userId]);
    $b = $st->fetch();
    if (!$b) return [false, 'Booking not found.'];
    if ($b['status'] !== 'pending') return [false, 'This booking is not awaiting payment.'];
    if (trim($holder) === '' || mb_strlen($holder) > 60) return [false, 'Enter the cardholder name.'];
    if (!preg_match('/^\d{13,19}$/', $number) || !luhn_valid($number)) return [false, 'Invalid card number.'];
    if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $expiry, $m)) return [false, 'Expiry must be MM/YY.'];
    if (mktime(0, 0, 0, (int)$m[1] + 1, 1, 2000 + (int)$m[2]) <= time()) return [false, 'This card has expired.'];
    if (!preg_match('/^\d{3,4}$/', $cvv)) return [false, 'Invalid security code.'];

    $approved = ($number !== '4000000000000002');   // simulated bank response
    $ref = 'ENS-' . strtoupper(bin2hex(random_bytes(4)));
    db()->prepare('INSERT INTO payments (booking_id,card_brand,card_last4,amount,reference,status) VALUES (?,?,?,?,?,?)')
        ->execute([$bookingId, card_brand($number), substr($number, -4), $b['total'], $ref, $approved ? 'approved' : 'declined']);
    if ($approved) {
        db()->prepare("UPDATE bookings SET status='confirmed' WHERE id=?")->execute([$bookingId]);
    }
    audit(($approved ? 'payment_ok:' : 'payment_declined:') . $ref, $userId);
    return [$approved, $approved ? "Payment approved. Reference $ref." : 'Payment declined by the bank. Try another card.'];
}

function cancel_booking(int $bookingId, int $userId): bool
{
    $st = db()->prepare("UPDATE bookings SET status='cancelled' WHERE id=? AND user_id=? AND status IN ('pending','confirmed')");
    $st->execute([$bookingId, $userId]);
    if ($st->rowCount() > 0) {
        audit('booking_cancelled:' . $bookingId, $userId);
        return true;
    }
    return false;
}

/* -------------------------------- layout -------------------------------- */

function render_header(string $title): void
{
    $u = current_user();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' | Enshapai Resort</title>';
    echo '<link rel="stylesheet" href="assets/style.css"></head><body>';
    echo '<header><div class="wrap"><a class="brand" href="index.php">Enshapai Resort</a><nav>';
    echo '<a href="rooms.php">Rooms</a>';
    if ($u) {
        echo '<a href="dashboard.php">My bookings</a>';
        if ($u['role'] === 'admin') echo '<a href="admin.php">Admin</a>';
        echo '<form method="post" action="logout.php" class="inline">' . csrf_field() . '<button class="link">Logout (' . e($u['full_name']) . ')</button></form>';
    } else {
        echo '<a href="login.php">Login</a><a href="register.php">Register</a>';
    }
    echo '</nav></div></header><main class="wrap">';
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) {
        echo '<p class="flash ' . e($t) . '">' . e($m) . '</p>';
    }
    unset($_SESSION['flash']);
}

function render_footer(): void
{
    echo '</main><footer><div class="wrap">&copy; ' . date('Y') . ' Enshapai Resort - DIT 1311 prototype. Card payments are simulated.</div></footer></body></html>';
}
