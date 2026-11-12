# Enshapai Resort - Booking System (DIT 1311 prototype)

Rapid prototype (RAD) of a resort booking system for the annual World Rally Championship (WRC) season.
Built in PHP 8.1+ with SQLite, following a Secure SDLC. Card payment is **simulated**.

## Features
- Guest registration, login, logout (hashed passwords, lockout after 5 failures)
- Room search with availability check (no double-booking)
- Booking and simulated bank-card payment (Luhn check, only brand + last 4 digits stored)
- Guest dashboard (view / pay / cancel bookings)
- Admin dashboard (all bookings, revenue, security audit log)

## Run it locally (Windows / macOS / Linux)
1. Install PHP 8.1 or newer (https://www.php.net/downloads or XAMPP: https://www.apachefriends.org).
2. Make sure the `pdo_sqlite` extension is enabled (it is by default in XAMPP and most PHP builds).
3. Open a terminal in this folder and run:
   ```
   php -S localhost:8000
   ```
4. Open http://localhost:8000 in your browser. The database file is created automatically in `data/`.

## Demo accounts
- Admin: `admin@enshapai.test` / `Admin@12345` (change before any real use)
- Guests: register your own account.

## Test cards (simulated gateway)
- `4242 4242 4242 4242` - approved
- `4000 0000 0000 0002` - declined
- Any future expiry (MM/YY) and any 3-digit code.

## Run the automated tests
```
php tests/run_tests.php
```
Results print to the screen and are saved to `tests/results.txt` - paste them into the report.

## Project structure
| File | Purpose |
|------|---------|
| `app.php` | Database, security helpers, business logic, page layout |
| `index.php`, `rooms.php`, `book.php`, `payment.php` | Guest booking flow |
| `register.php`, `login.php`, `logout.php`, `dashboard.php` | Accounts |
| `admin.php` | Admin-only dashboard |
| `tests/run_tests.php` | Unit, functional and security tests |

## Security controls (SSDLC)
Prepared statements (SQL injection), output encoding (XSS), CSRF tokens, `password_hash`, session regeneration on login,
HttpOnly/SameSite cookies, security headers + CSP, login lockout, role-based access, ownership checks, audit log.
