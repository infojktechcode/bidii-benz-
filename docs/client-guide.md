# Client guide

For customers hiring a vehicle. Everything works in the browser — there is
nothing to install.

## 1. Create an account — `/register`

Name, national ID/passport number, city, email, phone and a password. Your
ID and phone identify the hire at hand-over; read the
[privacy notice](/privacy) for how they are used (Kenya DPA 2019).

## 2. Browse the fleet — `/cars`

Every Mercedes-Benz with its photo, daily price, seats, transmission, fuel and
an **availability badge** — green "Available" when nobody holds it today, or
"Booked until <date>" straight from the booking ledger. Open a vehicle for the
full description and the day-by-day occupancy calendar.

## 3. Book — `/cars/{id}/book`

Choose pickup and return dates (the return day must be after the pickup day;
the price updates to `daily price × days`). If the vehicle is already taken
for any of those days you are told immediately — two people can never hold the
same vehicle-day.

You land on a **confirmation page** with your booking reference (e.g.
`BB-XXXXXX`). The booking starts as `pending_payment`.

## 4. Pay — `/payment/{bookingId}`

Open the booking (or use the link on the confirmation page) and pay the
outstanding balance:

- **M-Pesa (STK push)** — in the development/demo configuration the payment is
  confirmed instantly and the booking becomes `confirmed`. If the site is in
  production mode without live credentials, M-Pesa is refused rather than
  faked; use cash or bank transfer instead.
- **Cash at the office** / **Bank transfer** — recorded straight away as
  awaiting office confirmation; staff confirm it once the money is in.

You can pay in parts: each collection asks only for what is still outstanding.
All of a booking's payments are listed on its detail page.

## 5. Follow the hire

| Status | Meaning |
|---|---|
| `pending_payment` | Booked, waiting for payment |
| `confirmed` | Paid — the vehicle is reserved for you |
| `active` | The hire is under way |
| `completed` | Returned and settled |
| `cancelled` | Cancelled (by you or the office) |

## 6. Cancel — before pickup

Open the booking and use **Cancel booking** while it is `pending_payment` or
`confirmed`. The dates are released immediately, so the vehicle can be booked
again. (Once a hire is under way, only the office can close it.)

## Your account - `/account`

The **Account** link in the header opens your self-service page:

- **Profile** — edit your full name, phone number and city. Your email
  address and ID number cannot be changed here; contact the office for those.
- **Change password** — enter your current password plus a new one (at least
  8 characters). You stay signed in on this device, and the old password stops
  working immediately. Too many attempts in a short period are slowed down
  (wait a few minutes and try again).

## Your data

- You only ever see **your own** bookings and payments — the server refuses
  access to anyone else's (you would get a 403 page).
- Sign out on shared devices; sessions also expire automatically after
  30 minutes of inactivity / 8 hours in total.
- Questions about your data: see the [privacy notice](/privacy).
