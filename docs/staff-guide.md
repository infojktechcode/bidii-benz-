# Staff handbook

For accounts with the `staff` or `owner` role. Everything here lives under
`/admin`; the server enforces the role on every route, so a client can never
open these pages (they get 403).

## Dashboard — `/admin`

Totals at a glance: vehicles, clients, bookings, payments awaiting
confirmation, outstanding balance, bookings by status, and the most recent
bookings. Buttons jump to each area.

## Bookings — `/admin/bookings`

Filter by status, open a reference for the detail page, then move it through
the lifecycle:

| Action | When | Effect |
|---|---|---|
| **Confirm** | `pending_payment` and money is in | Status → `confirmed` |
| **Start hire** | `confirmed` on pickup day | Status → `active`; the vehicle is on hire |
| **Complete** | `active`, vehicle back | Status → `completed`, actual return time recorded, balance calculated; the vehicle becomes bookable again for future dates |
| **Cancel** | before pickup (and, for staff/owner, also during an active hire) | Status → `cancelled`; the vehicle's days are freed immediately |

Only one action is ever offered for the current status — anything else is
also refused server-side. Every action is written to the audit trail with
your user id.

## Clients — `/admin/clients`

The client directory: name, email, phone, ID number, city, number of bookings,
total paid (confirmed payments) and account status. Open a client for their
profile, full booking history and payment history.

Use it to answer "who is this caller?" and to check a client's track record
before confirming a booking by phone. Client details are personal data
(Kenya DPA 2019) — share only what the conversation needs.

## Payments — `/admin/payments`

- Filter by status; the header shows the outstanding balance across all
  bookings.
- **Confirm** a `pending` cash/bank payment by entering the M-Pesa receipt or
  office reference (receipts are unique — the same one cannot be used twice).
  Confirmation is attributed to you.
- **Refund** a `confirmed` payment with a reason; the refund is audited.
  Refunds are currently available to staff as well as the owner.

## Vehicles — `/admin/vehicles`

Add, edit and soft-delete vehicles (make, model, year, body, seats,
transmission, fuel, plate, daily price, status, description, photo up to 2 MB).
A duplicate registration plate is rejected. Photos are served through the
app, never by direct file access.

## Reports — `/admin/reports`

Pick a date range (defaults to a sensible window; inverted or absurd ranges
are rejected with a message):

- summary for the period (bookings, revenue, payments),
- bookings by status,
- revenue by vehicle,
- revenue by client,
- outstanding balances.

Reports are keyed on when a booking/payment was **created**, not when the
hire happened.

## Things the system will refuse (by design)

- Completing or starting a hire that is not in the right status.
- A **client** cancelling anything but their own booking, or one that is
  already under way (clients can cancel `pending_payment`/`confirmed`; staff
  and owner may also cancel an `active` hire).
- Confirming a payment that is not pending; refunding one that is not
  confirmed.
- Opening another client's booking as a client (403) — staff can open any.
- Mock M-Pesa collections while the app runs with `APP_ENV=production`.
