# Payments

Money is only ever *recorded* by the application; collections happen either
through the local mock (no external call) or — once real credentials exist —
through Safaricom Daraja STK push.

## Payable states

A booking can be paid while its status is one of exactly four:
`pending_payment`, `confirmed`, `active`, `completed`.
Cancelled/completed-with-no-balance bookings refuse new payments.

**Balance rule:** `total_amount − SUM(confirmed payments)`. Payments are
partial by design: each collection asks for whatever is still outstanding.

## Methods

| Method | What happens on initiation |
|---|---|
| `mpesa` | Mock mode: the payment is confirmed immediately and the booking is promoted to `confirmed` once the balance is ≤ KES 0.01. Never contacts Safaricom. |
| `cash` | Recorded as `pending`, awaiting office confirmation (staff enter a receipt). |
| `bank_transfer` | Same as cash: `pending` until staff confirm. |

## Environments (`MPESA_ENV`)

| Value | Behaviour |
|---|---|
| `mock` | Local auto-confirm, no network call. **Refused outright when `APP_ENV=production`** — otherwise a client could mark their own booking paid from a live site. |
| `sandbox` / `live` | Refused before any row is written: real STK push is not integrated yet (and the error also says when `MPESA_*` credentials are missing). This is deliberate fail-closed behaviour, scheduled for the live-implementation phase. |

Every refusal happens **before** an insert, so a refused attempt never leaves
an orphaned `pending` row behind.

## Flow

```
client opens /payment/{bookingId}
  → sees balance, chooses method + phone
  → POST /payment/{bookingId}/initiate (CSRF)
      PaymentService.initiatePayment()
        checks: owns booking · payable state · no collection in flight ·
                amount due > 0 · environment rules
        mpesa+mock: insert pending → mock confirm → booking confirmed if settled
        cash/bank:  insert pending → "the office will confirm it"
      audit: payment.initiate (actor, payment id, booking ref, amount, method)
  → redirect to the booking confirmation page
```

Office side: `/admin/payments` → confirm (receipt required, unique) or refund.

## Callbacks (`POST /payment/callback`)

Machine caller — no session, no CSRF. Authenticated by the
`X-Callback-Token` header compared timing-safe against `MPESA_CALLBACK_SECRET`:

- `MPESA_ENV=mock` → callback rejected (nothing can call back in mock mode).
- Secret unset → **no** callback can ever authenticate (fail closed).
- Result code `0` → payment confirmed, booking promoted when settled.
- Result code ≠ 0 → payment marked `failed` with the description; the request
  is acknowledged so Daraja stops retrying.
- Unknown `CheckoutRequestID` → rejected and logged.

## Confirmation and refunds

- `POST /admin/payments/{id}/refund` requires a `confirmed` payment (a pending
  or already-refunded one is refused). Refund sets status `refunded` and is
  audited with the reason the staff typed.
- Staff confirmation records `confirmed_by` = the signed-in staff user, so the
  audit trail shows exactly who turned a cash record into money received.
- `payments.mpesa_receipt` is UNIQUE: the same receipt can never be applied
  twice.

## Audited actions

`payment.initiate` (client), `payment.confirm` (staff), `payment.refund`
(staff). No password, token or `MPESA_*` value ever reaches the audit table —
it has no column that could hold one.

## Known limitations

- Real Daraja STK push (sandbox/live collection) is **not integrated**: the app
  refuses rather than pretending. Mock mode covers development and demos.
- There is no standalone client "payment history" page; a client sees all of a
  booking's payments on the booking detail page.
- Refunds are available to `staff` (owner outranks staff); restricting them to
  `owner` only is an open product decision.
