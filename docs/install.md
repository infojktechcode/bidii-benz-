# Installation

Target environment: PHP 8.3, MySQL/MariaDB, Apache with `mod_rewrite`
(stock XAMPP satisfies all three). No Composer dependencies — the only
third-party binary is the bundled `phpunit.phar`.

## 1. Place the project

Copy (or clone) the project so it sits next to the XAMPP docroot:

```
C:\xampp\htdocs\bidii-benz\
```

The local URL is then `http://localhost/bidii-benz/public/`.

> The document root must be `public/` in production. With XAMPP's default
> document root (the `htdocs` folder itself) the project-root `.htaccess`
> blocks every sensitive path (`.env`, `.git`, `docs/`, `tests/`, migrations),
> but only `public/` is meant to be reachable.

## 2. Configure

```
copy .env.example .env
```

Edit `.env` — at minimum check `DB_*`; see [environment.md](environment.md)
for every variable. Defaults match a stock XAMPP MySQL (`root`, empty
password, database `bidii_benz`).

## 3. Create the database and schema

```
mysql -u root -e "CREATE DATABASE bidii_benz CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php scripts/migrate.php
```

Migrations run in filename order, one file = one statement, each recorded in
`schema_migrations`. Re-running is safe: only pending files are applied.

```
php scripts/migrate.php status   # applied / pending list
```

## 4. (Optional) seed demo data

```
php scripts/seed.php            # refuses if data already exists
php scripts/seed.php --force    # wipes and reseeds
```

Seeds 6 users (1 owner, 2 staff, 3 clients), 7 Mercedes-Benz cars, 5 bookings,
3 payments and 14 occupied vehicle-days. All identities are fake (Kenya DPA
2019: no real client data in a development database).

## 5. Verify

```
php phpunit.phar
```

Expect `OK (199 tests, 1032 assertions)`. Integration tests skip themselves
when MySQL is not reachable.

Then open `http://localhost/bidii-benz/public/` and sign in with a seed
account (see the [README](../README.md)).

## 6. Production checklist

- [ ] Document root points at `public/` (nothing else is web-reachable).
- [ ] `APP_ENV=production` and `APP_DEBUG=0`.
- [ ] `APP_URL` is the canonical HTTPS URL (it drives the redirect and links).
- [ ] A dedicated MySQL user with rights only on this database.
- [ ] TLS enabled; the app forces HTTPS when `APP_ENV=production`.
- [ ] `MPESA_ENV` is **not** `mock` once real credentials exist (mock payments
      are refused outright in production).
- [ ] `MPESA_CALLBACK_SECRET` set before going live (callbacks are refused
      while it is empty).
- [ ] `storage/uploads` and `storage/logs` writable by the web server, and not
      web-exposed (they sit outside `public/`).

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `Database connection failed.` | MySQL not started, wrong `DB_*` in `.env`, database not created |
| Migration says `FAILED` | Read the printed SQLSTATE; drop the partially created object and re-run |
| 404 on every page | `mod_rewrite` off, or the URL points at the project root instead of `/public/` |
| 403 on `.env` / `.git` / `docs` | Expected — the project-root `.htaccess` refuses them |
| Tests report `MySQL not available.` | Start XAMPP MySQL; the suite skips DB tests otherwise |
| Uploads fail | `storage/uploads` not writable, or file above `UPLOAD_MAX_BYTES` |
