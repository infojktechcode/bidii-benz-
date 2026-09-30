# Deployment runbook

From a working local install ([install.md](install.md)) to a production
deployment. Covers: server requirements, least-privilege database account,
the production environment checklist, TLS, backups, restores and credential
rotation. Local XAMPP development never needs this file.

## 1. Pre-deploy verification

Run on the machine where the release candidate is checked out:

```
php phpunit.phar                 # baseline: OK (215 tests, 1127 assertions)
php scripts/http-smoke.php       # 147 checks, needs local Apache up
php scripts/migrate.php status   # 0 pending
git status --short               # clean, HEAD on the intended release commit
```

All four must be green before anything moves to a server.

## 2. Server requirements

- PHP **8.3+** with `pdo_mysql`, `fileinfo`, `mbstring` (plus the usual core
  extensions: `session`, `json`, `filter`). **No GD/ImageMagick** — media is
  validated with `finfo` + `getimagesize` (both core) and thumbnails are out
  of scope.
- CLI PHP for `scripts/` (`migrate.php`, `seed.php`, `backup.php`).
- MySQL 8.x or MariaDB 10.4+.
- Apache with `mod_rewrite` (or nginx: route everything to `public/index.php`).

## 3. Web-server layout

- Document root = **`public/`**. Nothing else is web-reachable in production.
- The `storage/` tree must exist and be writable by the deploy/CLI user
  (`storage/uploads/`, `storage/logs/`, `storage/backups/`) and **not**
  web-exposed.
- Do not expose `.env` (place the project root outside any served path, or
  keep the deny-all `.htaccess` files if you must serve from the project root
  as XAMPP does).

## 4. Database account (least privilege)

Never point production `DB_USER` at `root`. Create an account limited to this
database:

```sql
-- runtime account (enough for the app at request time)
CREATE USER 'bidii_app'@'127.0.0.1' IDENTIFIED BY '<long-random-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON bidii_benz.* TO 'bidii_app'@'127.0.0.1';

-- migrations/seed run occasionally as DDL-capable — either grant DDL to the
-- same user, or swap DB_USER around migration runs:
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON bidii_benz.* TO 'bidii_app'@'127.0.0.1';

FLUSH PRIVILEGES;
```

- Put the password in `.env` only (file readable by the web/CLI user, mode
  600 where the OS allows).
- `scripts/backup.php` runs with this account too — `--no-tablespaces` means
  no `PROCESS` privilege is required.

## 5. Environment checklist

`.env` in the project root (never committed, never web-served):

| Variable | Production value |
|---|---|
| `APP_ENV` | `production` (enables HTTPS redirect, strict CSP, mock-payment refusal) |
| `APP_DEBUG` | `0` (ignored in production anyway; set it regardless) |
| `APP_URL` | canonical **https** URL, e.g. `https://rentals.example.com` — drives redirects and links |
| `DB_HOST` / `DB_PORT` / `DB_NAME` | as provisioned |
| `DB_USER` / `DB_PASS` | the least-privilege account from §4 — not `root` |
| `SESSION_IDLE_TIMEOUT` / `SESSION_ABSOLUTE_TIMEOUT` | keep or tighten (defaults 1800 / 28800) |
| `MPESA_ENV` | `mock` is **refused** when `APP_ENV=production`; set `sandbox`/`live` only with real credentials (they still fail-closed until live STK is integrated) |
| `MPESA_CALLBACK_SECRET` | set before enabling callbacks — callbacks are refused while it is empty |
| `UPLOAD_MAX_BYTES` | keep default (2 MB) unless policy says otherwise |

Restart PHP (php-fpm reload / Apache restart) after editing `.env`.

## 6. TLS

- Terminate TLS at Apache (`mod_ssl`) or at a fronting proxy. The application
  itself never handles certificates.
- With `APP_ENV=production` the app 301s every plain-HTTP request to the
  `APP_URL` authority, so HTTP cannot be used to browse.
- Recommended at the server: an `Strict-Transport-Security` header and
  redirecting port 80 to HTTPS at the vhost level.

## 7. Backups

Two different things must be backed up — they live in different places:

| Data | How |
|---|---|
| Database (schema + every row, incl. `audit_logs`) | `php scripts/backup.php` → `storage/backups/bidii_benz-<timestamp>.sql` |
| Vehicle photos | copy `storage/uploads/` (e.g. `robocopy` / `rsync`) |
| Secrets + config | copy `.env` separately, encrypted or into a secrets store — **never** into the web tree or a repo |

The dump is plain SQL with `DROP TABLE` statements included, so restoring it
**replaces** the target database's contents.

Schedule it at the OS level:

```
# Windows (Task Scheduler), daily 02:00
php C:\path\to\bidii-benz\scripts\backup.php

# Linux (cron), daily 02:00
0 2 * * * /usr/bin/php /path/to/bidii-benz/scripts/backup.php >> /path/to/backup.log 2>&1
```

- `php scripts/backup.php --list` shows what exists (newest first).
- Retention (how many dumps to keep) is an operator decision — the script
  deliberately never deletes anything.
- **Verify the backup by restoring it**: at least quarterly, run the drill in
  §8 against a scratch database. An untested backup is not a backup.

## 8. Restore drill

Scratch drill (safe, no production data touched):

```
mysql -u root -e "CREATE DATABASE bidii_benz_restoretest"
mysql -u root bidii_benz_restoretest < storage/backups/bidii_benz-<timestamp>.sql
mysql -u root -e "SELECT COUNT(*) FROM bidii_benz_restoretest.users; DROP DATABASE bidii_benz_restoretest"
```

Full production restore (destructive — replaces all rows):

1. Stop writes: take the site down (stop Apache / maintenance page).
2. Pick the dump: `php scripts/backup.php --list`.
3. `mysql -u <db_user> -p <db_name> < storage/backups/<dump>.sql`
4. `php scripts/migrate.php status` → every migration applied.
5. Restore `storage/uploads/` (and `.env` if the host was rebuilt).
6. `php phpunit.phar`, then restart the web server and spot-check (§10).

## 9. Credential rotation checklist

Rotate on schedule and **whenever a credential may have been exposed**:

- **Daraja sandbox keys** (`MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`,
  `MPESA_PASSKEY`): regenerate in the Safaricom portal → update `.env` →
  restart PHP. ⚠ The development `.env` has carried parked sandbox
  credentials since the mock-mode decision — rotate them in the portal before
  any live use; deleting them from `.env` does not revoke them.
- **Database password**: `ALTER USER 'bidii_app'@'...' IDENTIFIED BY '<new>'`
  → update `DB_PASS` in `.env` → restart → run `php phpunit.phar`.
- **`MPESA_CALLBACK_SECRET`**: regenerate (`openssl rand -hex 32`) on both
  sides at once — an empty secret refuses all callbacks (fail closed).
- **App login seed accounts**: the seeded accounts are development fixtures;
  never create them in production (`seed.php` is a dev tool).

## 10. Post-deploy verification

- Homepage, `/cars`, `/privacy` render over HTTPS (no redirect loop).
- Sign in as owner → `/admin`, `/admin/payments`, `/admin/audit` render.
- One failed login and one successful login appear in `/admin/audit`.
- `storage/logs/` shows no new errors after a few minutes of traffic.
- `php scripts/backup.php` succeeds once from the server's CLI.

## 11. Rollback

- **Code only** (no migration applied): redeploy the previous release commit
  / restore the previous code copy, restart PHP.
- **Migration was applied**: migrations are forward-only (no down scripts).
  Either fix forward with a corrective migration, or restore the §8 backup
  taken before the deploy — that is why §7 scheduling is mandatory.
- **Bad data**: restore from the most recent good dump (destructive for
  everything written after it — check the timestamp first).
