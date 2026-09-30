# Security notes

Scope: what is enforced today, where it is enforced, and what is deliberately
left open. Every claim here is covered by a test or by the live HTTP checks
described in [testing.md](testing.md).

## Roles and authorization

| Role | Reach |
|---|---|
| `client` | Own bookings and payments only (server-side ownership checks, IDOR attempts → 403) |
| `staff` | Whole back office: vehicles, bookings, returns, payment confirmation, clients, reports, audit trail |
| `owner` | Everything staff can do (`Guard::atLeast('staff')`) **plus money-moving actions** (payment refunds) |

Every route carries its guard in `app/routes.php`; `RouteGuardTest` fails if a
route is unguarded, if a guard value is unknown, if any `/admin/*` route is
neither `staff` nor `owner`, or if the public allow-list drifts from reality.
Hiding a link in the UI is never access control.

**Session revalidation:** the session only remembers what was true at login,
so each request re-checks the account (`Guard::revalidateSession`). A
suspended, deleted or role-changed user is signed out on the very next
request — not when the cookie expires.

## Authentication

- Passwords: `password_hash()` / `password_verify()` (bcrypt), with rehash on
  login when the cost changes. A failed login for an unknown user still burns
  a dummy hash (no user enumeration by timing).
- Rate limiting (`app/Services/RateLimiter.php`): **5** failures per identifier
  or **20** per IP inside a **900 s** window blocks further attempts
  (`login_attempts` table).
- Sign-in, sign-out, registration and failed logins are audited
  (`auth.login`, `auth.logout`, `auth.register`, `auth.login_failed`); failed
  logins store the identifier only — never the submitted password.
- Password self-service (`POST /account/password`, every role): requires the
  current password (bcrypt verify), is CSRF-validated and throttled to **5
  attempts per session per 900 s**
  (`RateLimiter::MAX_PASSWORD_CHANGES_PER_WINDOW` — the 6th → **429** before
  anything is verified). Success regenerates the session id, rotates CSRF and
  is audited (`account.password_changed`); refusals are audited as
  `account.password_change_failed` with field names only — never values.
- Profile self-service (`POST /account/profile`, client-only): registration
  validation rules + phone uniqueness, persisted in one transaction and
  audited as `account.profile_updated` (field names only).

## Session

- Cookie: `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, name `bidii_sess`.
- Idle timeout 1800 s / absolute timeout 28800 s (configurable).
- Session ID regenerated on login, privilege change and password change.
- All session reads go through `Session`/`Guard`; nothing trusts client input
  for identity.

## CSRF

Every POST requires a per-session `_csrf` token, validated in the controller
before any state changes (`CsrfTest`). GET requests never change state.

## Transport and browser hardening

Set on every response by `public/index.php`:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `X-Powered-By` removed
- `Content-Security-Policy: default-src 'self'; img-src 'self' data:;
  style-src 'self'; script-src 'self'` (production only — no `unsafe-inline`:
  no view emits inline handlers, `style=""` or `<style>` blocks, locked down
  by `tests/Unit/CspPolicyTest.php`; buttons use `data-*` hooks handled by
  `public/assets/js/app.js`)
- HTTPS redirect when `APP_ENV=production` — the `Location` host comes from the
  configured `APP_URL`, **never** from the request `Host` header (a forged Host
  cannot turn the redirect into an open redirect; if `APP_URL` is unusable the
  redirect is skipped rather than trusted from the request)

Error pages show message + file/line only when `APP_ENV=development` **and**
`APP_DEBUG=1`, decided from configuration — never from the raw process
environment. Otherwise a generic page is shown and the details are logged.

## Web-root hardening (document-root dependent)

In production the document root is `public/`, so nothing else exists on the
web. For the XAMPP layout (document root = project folder) a root
`.htaccess` refuses:

- dotfiles — `.env`, `.env.example`, `.gitignore`, editor files
- `/.git/**` (the repository, including its history)
- `docs/`, `tests/`, `database/` (migrations), `vendor/`
- `phpunit.xml`, `phpunit.phar`, `composer.*`
- directory listings (`Options -Indexes`)

`app/`, `scripts/` and `storage/` carry their own deny-all `.htaccess`.
All of these return **403** over HTTP; the app under `/public/` keeps working.

## Payments

- Mock M-Pesa cannot run in production (see [payments.md](payments.md)) —
  fail-closed before any row is written.
- Callbacks are authenticated with a timing-safe shared-secret comparison and
  are refused while the secret is unset.
- Receipts are unique; refunds require a confirmed payment and an actor.
- Refunds are **owner-only** at the route guard (`owner`); staff get 403.
- Collection initiation is throttled: **10 attempts per session per 900 s**
  (`RateLimiter::MAX_INITIATES_PER_WINDOW`), refused with **429** before any
  row is written — on live M-Pesa every STK push costs money.

## Audit trail (`audit_logs`)

Recorded actions:

```
auth.login · auth.login_failed · auth.register · auth.logout
vehicle.create · vehicle.update · vehicle.delete
booking.create · booking.confirm · booking.start · booking.cancel · booking.complete
payment.initiate · payment.confirm · payment.refund
account.profile_updated · account.password_changed · account.password_change_failed
```

Each row stores user id, role, action, entity, entity id, IP and a bounded
detail (500 chars). The table has **no** column that could hold a password,
token or API credential, and `AuditTrailTest` locks that schema down. An audit
failure is logged and never breaks the business action.

The trail is readable at **`/admin/audit`** (staff+, read-only): newest first,
filterable by action and actor, 50 rows per page, deleted users shown from the
audit row's own role.

## Data protection

Client PII (national ID, phone, address) is stored — Kenya DPA 2019 applies.
The statutory notice is served at `/privacy`. A retention policy for bookings,
payments and audit rows is required before go-live (not yet implemented).

## Known, accepted gaps (deferred)

| Item | Status |
|---|---|
| No password reset / forgot-password flow | Not in the approved requirements. |
| `no_show` status unreachable | Reserved in the schema; no workflow yet. |
| Live Daraja integration | Refused rather than faked (see payments). |
| Payment-initiation throttle is session-scoped | Acceptable while payments are mock/manual; re-harden (IP/DB counter) before live activation. |
| `audit_logs` retention | Policy decision outstanding (Kenya DPA); `login_attempts` rows older than the 900 s window are already pruned on every sign-in attempt. |

Resolved in Phase 8 (kept for the record): the HTTPS redirect no longer trusts
`HTTP_HOST` (now pinned to `APP_URL`), and refunds moved from `staff` to
`owner`-only.

Resolved in Phase 10: the production CSP no longer allows `style-src
'unsafe-inline'` and carries no inline `on*=` handlers (print/cancel moved to
`app.js` via `data-*` attributes); a profile phone update that collides with a
unique key the pre-check cannot see (concurrent update / soft-deleted holder)
now returns a field error instead of an uncaught 500.
