# Bidii Benz Rentals

A self-drive car rental system for a Mercedes-Benz fleet in Kenya: clients
browse the fleet and book online, payments are collected over M-Pesa (mock
until real STK credentials are configured), and staff/owner run the back
office — bookings, vehicle returns, balances, payments and reports.

**Stack:** PHP 8.3 (plain PHP, no framework) · MySQL/MariaDB · Apache (XAMPP)
· PHPUnit (bundled `phpunit.phar`).

## Quick start (XAMPP)

1. Copy the project into `htdocs` so it lives at `C:\xampp\htdocs\bidii-benz`.
2. Copy `.env.example` to `.env` and adjust (see [docs/environment.md](docs/environment.md)).
   The defaults work with a stock XAMPP MySQL (`root`, empty password).
3. Create the database and load the schema:
   ```
   mysql -u root -e "CREATE DATABASE bidii_benz CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   php scripts/migrate.php
   php scripts/seed.php
   ```
4. Open <http://localhost/bidii-benz/public/> (the app is served from `public/`).
5. Run the tests: `php phpunit.phar`

Seed accounts (synthetic test data — never used in production):

| Role   | Email               | Password       |
|--------|---------------------|----------------|
| owner  | owner@bidii.test    | OwnerPass123!  |
| staff  | staff1@bidii.test   | StaffPass123!  |
| client | client1@bidii.test  | ClientPass123! |

## Documentation

| Document | Contents |
|---|---|
| [docs/install.md](docs/install.md) | Installation, migration, seed, troubleshooting |
| [docs/environment.md](docs/environment.md) | Every `.env` variable, defaults, what happens in production |
| [docs/routes.md](docs/routes.md) | Full URL map with the access guard on each route |
| [docs/payments.md](docs/payments.md) | M-Pesa modes, mock collection, callbacks, partial payments, refunds |
| [docs/security.md](docs/security.md) | Auth model, session, CSRF, headers, web-root hardening, audit trail |
| [docs/testing.md](docs/testing.md) | Unit/integration suites, what skips when MySQL is down, HTTP checks |
| [docs/staff-guide.md](docs/staff-guide.md) | Back-office handbook (staff/owner) |
| [docs/client-guide.md](docs/client-guide.md) | What a client can do, step by step |
| [docs/dfd.md](docs/dfd.md) | Data-flow diagram and data stores |
| [docs/database-design.md](docs/database-design.md) | Schema, ERD, constraints, indexes, migrations |

## Layout

```
public/          front controller (index.php), assets, media — the only
                 directory a web server should expose in production
app/
  Controllers/   request handlers (one per area)
  Core/          Config, Database, Router, Session, Guard, Csrf, Audit, ...
  Repositories/  prepared-statement queries only
  Services/      business rules (booking lifecycle, payments, photos, reports)
  views/         PHP templates (layout/, admin/, booking/, payment/, ...)
database/
  migrations/    one statement per file, tracked in schema_migrations
scripts/         migrate.php, seed.php (CLI only, deny-all via .htaccess)
storage/         uploads + logs (deny-all via .htaccess)
tests/           Unit/ (no DB) and Integration/ (real DB, skip if down)
docs/            the documents listed above
```

## Commands

```
php scripts/migrate.php          # apply pending migrations
php scripts/migrate.php status   # applied / pending
php scripts/seed.php --force     # reseed synthetic demo data
php phpunit.phar                 # full test suite
php phpunit.phar --filter Name   # one test/class
php scripts/http-smoke.php       # live HTTP end-to-end checks (Apache up)
```

## Behaviour worth knowing

- Booking overlap is enforced by the `booking_days` primary key: two bookings
  can never claim the same vehicle-day, even under concurrent requests.
- Cancelling frees the vehicle's days immediately; completed hires keep their
  days as history.
- The fleet page badges each vehicle's availability from `booking_days` — the
  same ledger the booking form refuses a taken date against.
- Reports are keyed on `bookings.created_at` / `payments.created_at`.
- Statuses: `pending_payment, confirmed, active, completed, cancelled`
  (`no_show` is reserved in the schema but has no workflow yet).
- Every privileged action is written to `audit_logs`.
