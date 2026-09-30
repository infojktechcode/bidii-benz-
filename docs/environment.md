# Environment variables

Everything is read from `.env` in the project root (never committed), with a
fallback to real process environment variables. Empty/missing values fall back
to the defaults below. See `.env.example` for a copy-ready template.

## Application

| Variable | Default | Meaning |
|---|---|---|
| `APP_ENV` | `development` | `production` switches on: HTTPS redirect, CSP header, non-verbose errors, refusal of mock M-Pesa collections. |
| `APP_DEBUG` | `1` | `1` shows exception details on the error page. Ignored when `APP_ENV=production`. |
| `APP_URL` | *(empty)* | Canonical base URL, e.g. `https://rentals.example.com`. Its path becomes the application base path; production HTTPS redirects are built from it. |
| `APP_NAME` | `Bidii Benz Rentals` | Shown in titles/emails. |

## Database

| Variable | Default | Meaning |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL host. |
| `DB_PORT` | `3306` | MySQL port. |
| `DB_NAME` | `bidii_benz` | Database name (must exist before migrating). |
| `DB_USER` | `root` | Database user — use a dedicated user in production. |
| `DB_PASS` | *(empty)* | Database password. |
| `DB_CHARSET` | `utf8mb4` | Set in code; not read from `.env`. |

## Session hardening

| Variable | Default | Meaning |
|---|---|---|
| `SESSION_IDLE_TIMEOUT` | `1800` | Seconds of inactivity before the session is destroyed. |
| `SESSION_ABSOLUTE_TIMEOUT` | `28800` | Seconds total session lifetime regardless of activity. |

Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` when the request
is HTTPS. The session name is fixed (`bidii_sess`).

## Uploads

| Variable | Default | Meaning |
|---|---|---|
| `UPLOAD_MAX_BYTES` | `2097152` | Maximum vehicle photo size (2 MB). Files land in `storage/uploads/cars/` and are served through the `/media/cars/{id}` route. |

## M-Pesa (Daraja)

| Variable | Default | Meaning |
|---|---|---|
| `MPESA_ENV` | `mock` | `mock` = local auto-confirm, never contacts Safaricom. `sandbox` / `live` = refused until real STK push is integrated (Phase 8 scope). **Mock is additionally refused when `APP_ENV=production`.** |
| `MPESA_CONSUMER_KEY` | *(empty)* | Daraja consumer key. |
| `MPESA_CONSUMER_SECRET` | *(empty)* | Daraja consumer secret. |
| `MPESA_SHORTCODE` | *(empty)* | Paybill/shortcode. |
| `MPESA_PASSKEY` | *(empty)* | STK passkey. |
| `MPESA_CALLBACK_URL` | *(empty)* | Public callback URL for `POST /payment/callback`. |
| `MPESA_CALLBACK_SECRET` | *(empty)* | Shared secret expected in the `X-Callback-Token` header. While empty, **no callback can authenticate** (fail closed). |

`MPESA_*` values are never logged and have nowhere to land in `audit_logs`
(the table has no column that could hold them).

## Error behaviour

- `APP_ENV=production` **or** `APP_DEBUG=0`: generic error page, details go to
  `storage/logs/`.
- `APP_ENV=development` and `APP_DEBUG=1`: message + file/line on the page.
- The decision uses the configured values only — never the raw process
  environment — so an unset variable can never expose stack traces in
  production.

## Logging

Logs are written to `storage/logs/` (outside `public/`, deny-all via
`.htaccess`). Application errors, refused callbacks and audit-write failures
are logged; passwords and tokens are never written.
