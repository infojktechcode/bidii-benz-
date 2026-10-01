# Testing

## Running

```
php phpunit.phar                 # everything
php phpunit.phar --filter Name    # by class/method name
php phpunit.phar --testsuite unit # suites (lowercase): unit | integration
```

Current baseline: **`OK (229 tests, 1167 assertions)`** (PHP 8.3.33,
PHPUnit 10.5.65, bundled `phpunit.phar` — no Composer install needed).

Integration tests open the real database and **skip themselves** with
`MySQL not available.` when it is unreachable; unit tests never need MySQL.
Two `PhotoStorage` thumbnail tests skip on runtimes without the GD
extension and run under any build with `ext/gd` (XAMPP's Apache PHP
ships GD).

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
                              RateLimiter, ReportRange, CspPolicy,
                              PhotoStorage
  Integration/               real database: Schema, AuthFlow, BookingFlow,
                             BookingConcurrency, PaymentFlow, CancellationAuth,
                             ReturnWorkflow, ReportSummary, AuditTrail,
                              SessionRevalidation, ClientDirectory,
                              FleetAvailability, AccountSelfService,
                              Dashboard, AuditRetention
```

## What the suites lock down

| Area | Representative guarantee |
|---|---|
| Router/Guards | Every route resolves to a real controller method, every non-public route is guarded, every `/admin/*` route requires `staff` or `owner`, public allow-list matches reality |
| Views | Every `View::render()` target exists (static scan — fails without a web server) |
| Schema | All migrations applied, double booking rejected by the primary key, check constraint rejects inverted dates, unique plate, plausible seed counts |
| Booking | Overlap prevention, lifecycle transitions, cancel frees the days, staff-only transitions |
| Concurrency | Two claims of the same vehicle-day: exactly one wins, loser rolls back cleanly |
| Payments | Mock confirms + persists the checkout id, cash stays pending, unique receipts, part-payments keep the balance open, refunds require a confirmed payment, **mock refused in production** |
| Auth | Registration, login/logout, rate limiting, IDOR returns 403 |
| Session | **Suspended / deleted / role-changed accounts are signed out on the next request** |
| Reports | Range parsing (rejects inverted/too-long input), summaries match hand-computed expectations |
| Audit | Actor/action/entity written, payload clipped, failures never break the action, no credential column exists, viewer reads rows with the actor joined in |
| Clients | Directory lists every client with totals, profile resolves, unknown id → null (404) |
| Availability | The listing badge reads `booking_days` (same source as `isAvailable`): free car absent, booking reports its return date, cancelling releases it |
| Dashboards | Outstanding balance subtracts **every** confirmed payment across non-cancelled bookings, KPI counters track live state, "available today" equals active fleet minus occupied, inactive/deleted vehicles never count (`DashboardTest`) |
| Audit retention | Rows older than the 12-month window are pruned, the dry-run count reports without deleting, young-only tables are no-ops (`AuditRetentionTest`); config defaults to 12 months (`ConfigTest`) |
| CSP policy | The production CSP declares no `unsafe-inline`/`unsafe-eval`, and no view emits inline `on*=` handlers, `style=""` attributes, `<style>` blocks or src-less `<script>` — the production header can stay strict (`CspPolicyTest`) |

## Live HTTP checks

The suites cannot see Apache, so run the committed smoke script against
`http://localhost/bidii-benz/public/` with XAMPP up:

```
php scripts/http-smoke.php
```

**170 checks**, PHP `curl`, throw-away rows with explicit cleanup, exits
non-zero on any failure:

- hidden paths return **403** (`.env`, `.env.example`, `.git`, `docs/`,
  `tests/`, migrations, `phpunit.*`) while `/public/` keeps serving 200
- session revalidation: suspending / deleting / role-changing an account signs
  it out on the **next** request
- anonymous redirects, client **403** on every admin page, IDOR **403** on
  other clients' bookings/confirmations, CSRF forged/missing → **403**
- full booking lifecycle (create → cancel; staff confirm → start → complete
  with return time; future-return refusal) with audit rows checked
- reports rendering incl. invalid/inverted ranges
- mock M-Pesa + cash initiation write `payment.initiate` audit rows for the
  paying client
- staff client directory renders, unknown id → **404**
- Phase 8: audit viewer (staff/owner 200, client/anonymous refused, filters),
  fleet availability badges + skip link, owner-only refund (staff 403, control
  hidden), initiation throttle (11th attempt inside the window → **429**, no
  payment row written)
- Phase 9: account self-service — anonymous redirected, staff refused the
  client-only profile route, forged CSRF → 403, profile edit persists +
  audits, wrong current password leaves the hash untouched, password change
  invalidates the old password and audits, 6th change attempt in the window →
  **429**
- Phase 10: CSP safety — booking history cancel uses `data-confirm`, the
  confirmation page prints via `data-print`, neither page ships an inline
  `onclick`
- Phase 11: registration cap — first 5 attempts in the window handled, 6th →
  **429** (no row written), and authenticated responses carry
  `Cache-Control: no-store`
- Phase 11A: dashboards — client login lands on `/bookings`, client dashboard
  sections/quick actions/empty-state CTAs, admin KPI grid + operational
  sections + quick actions, dashboard rail + `aria-current` + role chip for
  staff/owner, no rail for clients or anonymous visitors, responsive
  `data-cards` tables, footer contact strip links the office phone number

## Updating expectations

When you add a migration, apply it (`php scripts/migrate.php`) — `SchemaTest`
compares the files on disk against `schema_migrations`. When you add a route,
`RouteGuardTest` immediately demands a guard and `RouteTargetTest` demands a
real controller method and view.
