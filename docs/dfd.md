# Data-flow diagram

Level-0 (context) view of Bidii Benz Rentals: who sends what, where it lands.

```
                    ┌──────────────────────────────────────────────┐
   register, login  │                                              │
   browse, book     │                 BIDII BENZ RENTALS           │
  ┌──────────┐      │  ┌───────────┐  ┌───────────┐  ┌───────────┐  │      ┌──────────────┐
  │          │──────┼─▶│   Auth    │  │  Booking  │  │  Payment  │  │◀─────│    M-Pesa     │
  │          │ pay  │  │ (guards,  │  │ (overlap  │  │ (balance, │  │callback│  (Daraja)    │
  │  CLIENT  │──────┼─▶│  session, │  │  proof,   │  │  methods, │  │      │ mock = local  │
  │          │      │  │  limits)  │  │  lifecycle│  │  refunds) │  │      └──────────────┘
  └──────────┘      │  └─────┬─────┘  └─────┬─────┘  └─────┬─────┘  │
                    │        │              │              │        │      ┌──────────────┐
  ┌──────────┐      │        ▼              ▼              ▼        │◀─────│ STAFF/OWNER  │
  │          │──────┼───────────────────────┬──────────────┬─────────│─────▶│ back office  │
  │  SYSTEM  │      │                       │              │         │      └──────────────┘
  │ (clock,  │──────┼── return time,        │              │         │
  │  photos) │      │    vehicle photos     ▼              ▼         │
  └──────────┘      │              ┌────────────────────────────┐    │
                    │              │   REPORTING + AUDIT TRAIL  │    │
                    │              └────────────────────────────┘    │
                    └───────────────────────┬──────────────────────┘
                                            │ prepared statements only
                                            ▼
                                ┌──────────────────────────┐
                                │  MySQL: users, clients,  │
                                │  cars, bookings,         │
                                │  booking_days, payments, │
                                │  audit_logs,             │
                                │  login_attempts,         │
                                │  schema_migrations       │
                                └──────────────────────────┘
```

## Processes and data stores

| Process | Reads / writes |
|---|---|
| **Auth** | `users`, `login_attempts`, `audit_logs` (`auth.*`, `account.*`) |
| **Booking** | `cars`, `bookings`, `booking_days` (overlap proof via PK), `audit_logs` (`booking.*`) |
| **Payment** | `payments`, `bookings` (balance → status promotion), `audit_logs` (`payment.*`) |
| **Returns** | `bookings` (`completed` + `actual_return_at`), frees future `booking_days` |
| **Vehicle admin** | `cars`, `audit_logs` (`vehicle.*`), photos on disk in `storage/uploads/cars` |
| **Reporting** | `bookings`, `payments` filtered by `created_at` range |
| **Audit** | append-only `audit_logs` |

## Trust boundaries

1. **Browser → app:** session cookie + CSRF token on every POST; role and
   ownership re-checked server-side on each request.
2. **App → database:** prepared statements only; constraints (primary keys,
   unique keys, foreign keys, check constraints) enforce the rules that must
   not be bypassable even by a code bug.
3. **M-Pesa → app:** `POST /payment/callback` crosses in without a session —
   authenticated by the `X-Callback-Token` shared secret, refused outright in
   mock mode and while the secret is unset.
4. **Filesystem:** the web server only exposes `public/`; uploads and logs sit
   outside it (and are deny-all when the document root is the project folder).
