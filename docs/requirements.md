# Requirements — implementation traceability

Every requirement of the study mapped to the code, tests and HTTP checks that
prove it. Verified at the **Phase 11 final checkpoint**:
`OK (215 tests, 1127 assertions)` + smoke **147/147** + lint 94 files clean.

Status legend: **COMPLETE — VERIFIED** (implemented and covered by automated
evidence), **OUT OF SCOPE** (deliberately excluded, never a study requirement).

## Client

| # | Requirement | Status | Evidence |
|---|---|---|---|
| C1 | Client registration | COMPLETE — VERIFIED | `POST /register` (CSRF + **5/900 s cap**, `RateLimiter`), `AuthFlowTest`, smoke "registration throttle" |
| C2 | Client login / logout | COMPLETE — VERIFIED | `AuthFlowTest` (ID + IP lockout), `RateLimiterTest`, smoke sign-ins |
| C3 | Vehicle browsing (list + detail) | COMPLETE — VERIFIED | `GET /cars`, `GET /cars/{id}`; smoke fleet 200s |
| C4 | Mercedes-Benz information | COMPLETE — VERIFIED | seeded fleet (make, model, year, description) — `database/seeds` |
| C5 | Vehicle photos | COMPLETE — VERIFIED | `PhotoStorage` (finfo + getimagesize, random name, storage outside web root), `GET /media/cars/{id}` |
| C6 | Seats / capacity | COMPLETE — VERIFIED | `cars.seats` CHECK constraint, vehicle form + cards |
| C7 | Daily prices | COMPLETE — VERIFIED | `cars.daily_price`, total computed server-side (`PaymentFlowTest` math) |
| C8 | Availability check + booking form | COMPLETE — VERIFIED | `booking_days` ledger; refusal on overlap, badge + 60-day calendar; `FleetAvailabilityTest` |
| C9 | Booking creation | COMPLETE — VERIFIED | overlap-proof insert; `BookingFlowTest`, `BookingConcurrencyTest` (7 concurrency cases) |
| C10 | M-Pesa payment | COMPLETE — VERIFIED | mock/cash/bank path, checkout-id correlation, part-payments; `PaymentFlowTest`, smoke payment section |
| C11 | Booking confirmation | COMPLETE — VERIFIED | `GET /booking/{ref}/confirm` owner-guarded (foreign ref → 403 in smoke), printable sheet (`data-print`) |
| C12 | Booking history | COMPLETE — VERIFIED | `GET /bookings` + empty states, cancel via `data-confirm`; smoke |
| C13 | Account self-service | COMPLETE — VERIFIED | `/account` profile + password; `AccountSelfServiceTest` (11), smoke Phase 9/10 |

## Administration

| # | Requirement | Status | Evidence |
|---|---|---|---|
| A1 | Owner access (full control) | COMPLETE — VERIFIED | `owner` guard on refunds/ops (`routes.md`), smoke "refunds are owner-only" |
| A2 | Staff access (back office) | COMPLETE — VERIFIED | `staff` guard on `/admin/*`; `RouteGuardTest`, smoke 403 matrix |
| A3 | Vehicle management (CRUD, photos) | COMPLETE — VERIFIED | guarded routes `routes.md:48-51`, `AdminController`, soft delete keeps history |
| A4 | Client management | COMPLETE — VERIFIED | `ClientDirectoryTest`, smoke "staff client directory" + 404 |
| A5 | Booking management (confirm/start/complete) | COMPLETE — VERIFIED | lifecycle in `BookingService`, smoke booking lifecycle, `CancellationAuthTest` |
| A6 | Payment management (confirm/refund) | COMPLETE — VERIFIED | smoke confirm + owner-refund checks; audit `payment.refunded` |
| A7 | Returns workflow (start → complete) | COMPLETE — VERIFIED | `ReturnWorkflowTest` (12), vehicle returns to circulation |
| A8 | Outstanding balances | COMPLETE — VERIFIED | balance math per part-payment (`PaymentFlowTest`), report totals match |
| A9 | Reports (5 views, date ranges) | COMPLETE — VERIFIED | `ReportSummaryTest`, `ReportRangeTest`, smoke reports render |
| A10 | Audit logging + viewer | COMPLETE — VERIFIED | append-only `audit_logs`, `AuditTrailTest`, smoke "audit viewer" (staff sees own, owner sees all) |

## Security

| # | Requirement | Status | Evidence |
|---|---|---|---|
| S1 | Password hashing | COMPLETE — VERIFIED | bcrypt `password_hash`/`verify` + rehash, dummy hash on unknown user (`AuthFlowTest`) |
| S2 | Prepared statements (no SQLi) | COMPLETE — VERIFIED | PDO bound params throughout; no interpolated SQL found in audit |
| S3 | Authorization / role guards | COMPLETE — VERIFIED | `RouteGuardTest` (40 routes), smoke 403 matrix, IDOR checks (confirmation ref) |
| S4 | CSRF protection | COMPLETE — VERIFIED | per-session token on every POST; `CsrfTest`, smoke bad-token → 403 |
| S5 | Input validation | COMPLETE — VERIFIED | `Input::fromRequest` + rule sets; `InputTest`, `AuthServiceTest` |
| S6 | Output escaping / XSS | COMPLETE — VERIFIED | `View::e` on all view output; `CspPolicyTest` (no inline handlers/styles) |
| S7 | Secure sessions | COMPLETE — VERIFIED | HttpOnly/SameSite=Lax/Secure, idle + absolute timeouts, regeneration; `SessionRevalidationTest`, smoke SEC-05 |
| S8 | Rate limiting / brute-force | COMPLETE — VERIFIED | login 5/20, password 5/session, initiation 10/session, registration 5/session (all 900 s); `RateLimiterTest`, smoke 429 checks |
| S9 | Secure file upload | COMPLETE — VERIFIED | MIME + getimagesize allowlist, random name, outside web root (`PhotoStorage`) |
| S10 | HTTPS enforcement | COMPLETE — VERIFIED | production redirect pinned to `APP_URL` (never `Host`); TLS termination = deployment ([deploy.md](deploy.md) §6) |
| S11 | Security headers | COMPLETE — VERIFIED | nosniff, DENY, Referrer-Policy, Permissions-Policy, production CSP; `CspPolicyTest`, live header capture |
| S12 | Audit trail of sensitive actions | COMPLETE — VERIFIED | `AuditTrailTest`, smoke audit viewer |
| S13 | Privacy notice / data minimisation | COMPLETE — VERIFIED | `GET /privacy` statutory notice; failed logins store identifier only |

## Payments

| # | Requirement | Status | Evidence |
|---|---|---|---|
| P1 | Mock/test M-Pesa (no real money) | COMPLETE — VERIFIED | `MPESA_ENV=mock` auto-confirms locally, never contacts Safaricom; refused when `APP_ENV=production` (`PaymentFlowTest`) |
| P2 | Manual fallback (cash/bank) | COMPLETE — VERIFIED | staff records bank/cash; payment form checks in smoke |
| P3 | Checkout correlation | COMPLETE — VERIFIED | `checkout_request_id` persisted before callback; `PaymentFlowTest` |
| P4 | Part-payments / balances | COMPLETE — VERIFIED | ledger accumulates until balance 0; `PaymentFlowTest`, report totals |
| P5 | Confirmation flow | COMPLETE — VERIFIED | mock immediate confirm; status + audit rows; smoke |
| P6 | Refund controls | COMPLETE — VERIFIED | **owner-only** route + audit (`payment.refunded`), smoke staff-403/owner-200 |
| P7 | Production safety | COMPLETE — VERIFIED | mock refused in production, sandbox/live fail closed until real STK ([deploy.md](deploy.md) §7); live Daraja **OUT OF SCOPE** (credentials parked, never exercised) |

## Documentation

| # | Deliverable | Status | File |
|---|---|---|---|
| D1 | ERD | COMPLETE | [database-design.md](database-design.md) (ERD section) |
| D2 | DFD | COMPLETE | [dfd.md](dfd.md) |
| D3 | Installation guide | COMPLETE | [install.md](install.md) |
| D4 | Environment reference | COMPLETE | [environment.md](environment.md) |
| D5 | Staff guide | COMPLETE | [staff-guide.md](staff-guide.md) |
| D6 | Client guide | COMPLETE | [client-guide.md](client-guide.md) |
| D7 | Test plan / report | COMPLETE | [testing.md](testing.md) |
| D8 | Security documentation | COMPLETE | [security.md](security.md) |
| D9 | Payments documentation | COMPLETE | [payments.md](payments.md) |
| D10 | Deployment runbook | COMPLETE | [deploy.md](deploy.md) |
| D11 | Route/guard map | COMPLETE | [routes.md](routes.md) |

## Not study requirements (recorded for the record)

- **Live Daraja STK push** — OUT OF SCOPE. Mock approved as the study basis;
  sandbox/live fail closed. Parked credentials: rotate in the Safaricom
  portal (external — values never stored or exercised here).
- **Password reset / forgot-password** — never an approved requirement;
  blocked additionally by no mail channel + change-approval rules.
- **Email/SMS notifications, rental extension, `no_show` status** — not
  requested in any phase.
- **`audit_logs` retention** — pending owner policy decision (Kenya DPA);
  `login_attempts` already pruned inside the 900 s window.
