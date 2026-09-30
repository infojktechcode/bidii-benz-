# Bidii Benz Rentals — Database Design (ERD)

**Design standard:** MySQL/MariaDB 10.4, InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`.
All migrations live in `database/migrations/` and are applied by `php scripts/migrate.php`.

## Entity Relationship Diagram

```mermaid
erDiagram
    users ||--o| clients : "role=client"
    users ||--o{ bookings : "created_by (staff/owner)"
    users ||--o{ payments : "confirmed_by"
    users ||--o{ audit_logs : "actor"
    clients ||--o{ bookings : "places"
    clients ||--o{ payments : "pays"
    cars ||--o{ bookings : "hired as"
    cars ||--o{ booking_days : "occupied days"
    bookings ||--o{ booking_days : "occupies"
    bookings ||--o{ payments : "settled by"

    users {
        int id PK
        enum role "client|staff|owner"
        varchar email UK
        varchar phone UK
        varchar password_hash
        enum status "active|suspended"
        datetime last_login_at
        datetime deleted_at "soft delete"
    }
    clients {
        int id PK
        int user_id FK_UK
        varchar full_name
        enum id_type "national_id|passport|alien_id"
        varchar id_number UK
        varchar city
        varchar address
    }
    cars {
        int id PK
        varchar make "default Mercedes-Benz"
        varchar model
        smallint year
        varchar body_type
        tinyint seats
        enum transmission
        enum fuel_type
        varchar registration_plate UK
        decimal daily_price
        enum status "active|maintenance|retired"
        varchar image_path
        datetime deleted_at "soft delete"
    }
    bookings {
        int id PK
        varchar booking_ref UK
        int client_id FK
        int car_id FK
        date pickup_date
        date return_date
        datetime actual_return_at
        decimal daily_rate "price snapshot at booking"
        decimal total_amount
        enum status "pending_payment|confirmed|active|completed|cancelled|no_show"
        int created_by FK "nullable"
        datetime cancelled_at
    }
    booking_days {
        int car_id PK_FK
        date day PK
        int booking_id FK
    }
    payments {
        int id PK
        int booking_id FK
        int client_id FK
        decimal amount
        enum method "mpesa|cash|bank_transfer|manual"
        enum status "pending|confirmed|failed|refunded"
        varchar mpesa_receipt UK
        varchar mpesa_phone
        varchar checkout_request_id
        int confirmed_by FK "staff/owner"
        datetime paid_at
    }
    audit_logs {
        bigint id PK
        int user_id FK
        varchar action
        varchar entity
        int entity_id
        varchar ip_address
        varchar detail
    }
    login_attempts {
        int id PK
        varchar identifier
        varchar ip_address
        tinyint successful
        timestamp attempted_at
    }
```

## Design decisions

### 1. Double-booking prevention (core rule)
`booking_days` holds **one row per car per occupied calendar day**, with
`PRIMARY KEY (car_id, day)`. A second booking claiming an occupied day fails at
the database with a duplicate-key error — no application logic can bypass it.

Verified evidence (2026-09-29):

```
ERROR 1062 (23000): Duplicate entry '1-2026-10-06' for key 'PRIMARY'
```

**Concurrency contract (Phase 5) — implemented and verified:** the booking
service inserts the `bookings` header and all `booking_days` rows inside ONE
transaction and rolls back on any failure
(`BookingRepository::createWithDays()`, `app/Repositories/BookingRepository.php`).
Proof that a mid-transaction duplicate-key failure removes the header *and* the
days already written: `tests/Integration/BookingConcurrencyTest.php`.

Availability is the logical query below. The implementation deliberately runs it
as two guards, both inside `BookingService::createBooking()`:

1. `c.status = 'active' AND c.deleted_at IS NULL` — enforced by
   `CarRepository::findById()` (which filters `deleted_at`) plus an explicit
   status check; the public fleet list uses the same predicates via
   `CarRepository::findActive()`.
2. the `NOT EXISTS` day-overlap half — `CarRepository::isAvailable()`.

```sql
SELECT c.id FROM cars c
WHERE c.status = 'active' AND c.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM booking_days bd
      WHERE bd.car_id = c.id
        AND bd.day BETWEEN :pickup AND :return
  );
```

`booking_days` rows are deleted (not kept) when a booking is cancelled, freeing
the car. The status update and that deletion run inside ONE transaction
(`BookingRepository::updateStatus()`), so a `cancelled` booking can never be
left still occupying its days. Booking history is preserved in `bookings`.

Verified evidence (2026-09-30):

```
forced duplicate key on a later day → bookings count unchanged,
booking_days count unchanged            (header + earlier days rolled back)
held row lock during cancellation      → status rolled back to pending_payment,
                                         cancelled_at NULL, days still present
```

### 2. Money
`DECIMAL(12,2)` (amounts) / `DECIMAL(10,2)` (rates), `currency CHAR(3) = KES`.
`CHECK (amount > 0)`, `CHECK (total_amount >= 0)`. No floating point anywhere.
`bookings.daily_rate` is a **snapshot** of the car price at booking time, so a
later price change never rewrites historical totals.

### 3. Deletion strategy
| Table | Strategy |
|---|---|
| `bookings`, `payments`, `audit_logs` | Never deleted (financial/legal record) |
| `cars`, `users` | Soft delete (`deleted_at`); RESTRICT if referenced |
| `clients` | RESTRICT while bookings exist |
| `booking_days` | CASCADE from `bookings` (cancelled ⇒ rows removed anyway) |

Verified evidence: `DELETE FROM cars` and `DELETE FROM clients` with existing
bookings both rejected with `ERROR 1451` (FK RESTRICT). Counts unchanged.

### 4. Status flow
```
pending_payment → confirmed → active → completed
       ↓              ↓          ↓
   cancelled      cancelled   completed (with balance)
       ↓
   no_show
```
Balance rule: `total_amount − SUM(confirmed payments)`; **cancelled bookings are
excluded from balances and from occupancy**.

### 5. Indexes
- `booking_days`: PK `(car_id, day)` — serves both overlap check and history.
- `bookings`: `(car_id, pickup_date, return_date)`, `(client_id)`, `(status)`,
  `(pickup_date)`, `(created_at)` — the last one drives the report date ranges.
- `payments`: `(booking_id)`, `(status)`, `(created_at)`, UNIQUE `(mpesa_receipt)`
  — a receipt can never be applied twice; `(created_at)` drives payment reports.
- `login_attempts`: `(identifier, attempted_at)` — supports login rate limiting.
- `audit_logs`: `(user_id)`, `(action)`, `(entity, entity_id)`, `(created_at)`.

### 6. Security-relevant constraints
- `users.email` and `users.phone` UNIQUE → no duplicate accounts.
- `users.password_hash VARCHAR(255)` → `password_hash()` (bcrypt) output.
- `login_attempts` → server-side brute-force throttling table.
- `audit_logs` → every privileged action recorded (user, action, entity, IP).
- Client PII (national ID, phone) is stored — Kenya DPA 2019 applies:
  privacy notice + retention policy required before go-live.

## Migrations
| File | Table |
|---|---|
| 0001_create_users.sql | users |
| 0002_create_clients.sql | clients |
| 0003_create_cars.sql | cars |
| 0004_create_bookings.sql | bookings |
| 0005_create_booking_days.sql | booking_days |
| 0006_create_payments.sql | payments |
| 0007_create_audit_logs.sql | audit_logs |
| 0008_create_login_attempts.sql | login_attempts |
| 0009_add_bookings_created_index.sql | bookings (index `created_at`) |
| 0010_add_payments_created_index.sql | payments (index `created_at`) |

> Runner note: MySQL DDL causes an implicit commit, so migrations run **without**
> an explicit transaction (see `scripts/migrate.php`). Each file = one statement
> = one atomic DDL unit.

## Seed data
`php scripts/seed.php [--force]` — synthetic only: 6 users (1 owner, 2 staff,
3 clients), 7 Mercedes-Benz cars, 5 bookings (completed/confirmed/pending/
cancelled), 3 payments, 14 occupied days, 1 audit entry. All names, IDs and
phone numbers are fake (Kenya DPA 2019: real client data never used in testing).
