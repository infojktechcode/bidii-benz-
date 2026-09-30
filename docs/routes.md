# Route map

Guard column is the server-side check enforced in `app/routes.php` — frontend
hiding of a link is never access control.

- **public** — no session needed
- **login** — any signed-in user
- **client** — role `client` (a booking must belong to the caller)
- **staff** — role `staff` or `owner` (`Guard::atLeast('staff')`)
- **owner** — role `owner` only (money-moving actions)
- **(CSRF)** — every POST also requires a valid `_csrf` token
- **(secret)** — machine caller authenticated by shared secret

## Public

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Landing page |
| GET | `/privacy` | Statutory privacy notice |
| GET | `/cars` | Fleet browsing |
| GET | `/cars/{id}` | Vehicle detail |
| GET | `/media/cars/{id}` | Vehicle photo (streamed by PHP) |
| GET | `/login`, POST | Sign in |
| GET | `/register`, POST | Sign up |

## Client (login + CSRF)

| Method | Path | Purpose |
|---|---|---|
| GET | `/cars/{id}/book`, POST | Booking form / create booking |
| GET | `/booking/{ref}/confirm` | Booking confirmation page |
| GET | `/bookings` | Booking history |
| GET | `/bookings/{id}` | Booking detail + its payments |
| POST | `/bookings/{id}/cancel` | Cancel own booking (only before pickup) |
| GET | `/payment/{bookingId}` | Payment page (balance, method, phone) |
| POST | `/payment/{bookingId}/initiate` | Start a collection (mock STK / cash / bank) |
| POST | `/payment/callback` | M-Pesa result callback **(secret, no session/CSRF)** |
| POST | `/logout` | Sign out |

## Admin — staff/owner (login + CSRF)

| Method | Path | Purpose |
|---|---|---|
| GET | `/admin` | Dashboard: totals, bookings by status, recent bookings |
| GET | `/admin/vehicles` | Vehicle list |
| GET | `/admin/vehicles/create`, POST | Add vehicle (+ photo) |
| GET | `/admin/vehicles/{id}/edit`, POST | Edit vehicle |
| POST | `/admin/vehicles/{id}/delete` | Delete vehicle (soft) |
| GET | `/admin/bookings` | Booking list with status filter |
| GET | `/admin/bookings/{id}` | Booking detail + lifecycle actions |
| POST | `/admin/bookings/{id}/confirm` | Confirm a booking |
| POST | `/admin/bookings/{id}/start` | Start the hire |
| POST | `/admin/bookings/{id}/complete` | Record the return, settle balance |
| POST | `/admin/bookings/{id}/cancel` | Cancel on behalf of the office |
| GET | `/admin/clients` | **Client directory (name, contact, totals)** |
| GET | `/admin/clients/{id}` | **Client profile + their bookings and payments** |
| GET | `/admin/payments` | Payment list with status filter |
| POST | `/admin/payments/{id}/confirm` | Manually confirm a cash/bank payment |
| POST | `/admin/payments/{id}/refund` | Refund a confirmed payment **(owner only)** |
| GET | `/admin/reports` | Date-ranged reports (staff reach; owner dashboards) |
| GET | `/admin/audit` | Audit trail viewer (filter by action/actor, 50 per page) |

## Errors

| Case | Response |
|---|---|
| Unknown path | 404 view |
| Unauthenticated on a guarded route | 302 → `/login` (intended path remembered) |
| Wrong role on a guarded route | 403 view |
| Another client's booking/payment (IDOR) | 403 view |
| Bad/missing CSRF token on POST | 403 view |

## Notes

- The client-booking routes reuse `/cars/{id}` patterns: `/cars/{id}/book` is
  guarded, `/cars/{id}` is public.
- `no_show` exists in the status enum but has no route or workflow yet.
- HEAD requests to dynamic routes return 404 (only GET/POST are registered);
  static assets are served by Apache directly.
