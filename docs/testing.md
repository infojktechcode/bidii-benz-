# Testing

## Running

```
php phpunit.phar                 # everything
php phpunit.phar --filter Name    # by class/method name
php phpunit.phar --testsuite Unit # see phpunit.xml
```

Current baseline: **`OK (194 tests, 1005 assertions)`** (PHP 8.3.33,
PHPUnit 10.5.65, bundled `phpunit.phar` — no Composer install needed).

Integration tests open the real database and **skip themselves** with
`MySQL not available.` when it is unreachable; unit tests never need MySQL.

## Layout

```
tests/
  bootstrap.php              autoloads app + test classes, seeds $_SESSION,
                             sets APP_ENV=testing
  Support/CreatesRentalFixture.php
                             throw-away client + vehicle per test, removed in
                             tearDown so tests never touch seeded rows
  Unit/                      no database: Config, Env, Router, Csrf, Input,
                             Guard, RouteGuard, RouteTarget, Auth (service),
                             RateLimiter, ReportRange
  Integration/               real database: Schema, AuthFlow, BookingFlow,
                             BookingConcurrency, PaymentFlow, CancellationAuth,
                             ReturnWorkflow, ReportSummary, AuditTrail,
                             SessionRevalidation, ClientDirectory
```

## What the suites lock down

| Area | Representative guarantee |
|---|---|
| Router/Guards | Every route resolves to a real controller method, every non-public route is guarded, every `/admin/*` route requires `staff`, public allow-list matches reality |
| Views | Every `View::render()` target exists (static scan — fails without a web server) |
| Schema | All migrations applied, double booking rejected by the primary key, check constraint rejects inverted dates, unique plate, plausible seed counts |
| Booking | Overlap prevention, lifecycle transitions, cancel frees the days, staff-only transitions |
| Concurrency | Two claims of the same vehicle-day: exactly one wins, loser rolls back cleanly |
| Payments | Mock confirms + persists the checkout id, cash stays pending, unique receipts, part-payments keep the balance open, refunds require a confirmed payment, **mock refused in production** |
| Auth | Registration, login/logout, rate limiting, IDOR returns 403 |
| Session | **Suspended / deleted / role-changed accounts are signed out on the next request** |
| Reports | Range parsing (rejects inverted/too-long input), summaries match hand-computed expectations |
| Audit | Actor/action/entity written, payload clipped, failures never break the action, no credential column exists |
| Clients | Directory lists every client with totals, profile resolves, unknown id → null (404) |

## Live HTTP checks

The suites cannot see Apache, so each phase also runs an HTTP script against
`http://localhost/bidii-benz/public/` (PHP `curl`, throw-away rows, explicit
cleanup). Phase 7's script covers:

- hidden paths return **403** (`.env`, `.git`, `docs/`, `tests/`, migrations,
  `phpunit.*`, directory listing) while `/public/` keeps serving 200
- staff sign-in → `/admin/clients` renders; suspending the account signs it
  out on the **next** request; a role change does the same; a suspended
  account cannot establish a session
- mock M-Pesa initiation writes a `payment.initiate` audit row for the paying
  client (both `mpesa` and `cash`)
- client role receives **403** on admin pages, unknown client id → **404**

Regression scripts from earlier phases (54 checks: guards, IDOR, booking
lifecycle, returns, reports) are re-run after every change.

## Updating expectations

When you add a migration, apply it (`php scripts/migrate.php`) — `SchemaTest`
compares the files on disk against `schema_migrations`. When you add a route,
`RouteGuardTest` immediately demands a guard and `RouteTargetTest` demands a
real controller method and view.
