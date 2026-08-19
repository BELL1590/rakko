# XSERVER Migration Plan

## Goal

Migrate the production runtime of the current `main` implementation (`22b432b65732fa584311ec0e4df57e4a1c06a30d`) from Cloudflare Workers + D1 to XSERVER shared hosting, while preserving the current UI/UX and business behavior.

The existing Workers implementation remains in the repository as the behavioral reference until cutover is complete.

## Target runtime

- PHP 8.4.x (8.3+ compatible where practical)
- XSERVER MySQL / MariaDB-compatible SQL via PDO
- InnoDB / `utf8mb4`
- XSERVER Cron for 5-minute reminder jobs
- Apache + `.htaccess` front controller
- Server-side rendered HTML
- Vanilla JS / current CSS design retained
- No Node runtime required in production

## Preserve all current behavior

- LINE Login (OAuth2/OIDC)
- state / PKCE S256 / nonce / HS256 id_token verification
- Friendship Status check
- LINE Messaging API booking completion messages
- 5-minute LINE reminders
- reservation_pages / reservation_slots model
- multi-slot booking on one page
- all-or-nothing grouped booking
- booking_group_id
- per-slot party size and companions
- remaining-seat calculation and overbooking prevention
- duplicate booking prevention per LINE user + slot
- cancellation and re-booking
- my bookings
- admin authentication
- reservation page create/edit/duplicate/publish/close
- slot create/edit
- date/time/capacity/max party size/booking period/reminder editing
- admin proxy booking
- checked_in_count inc/dec/all
- roster search
- slot CSV and whole-page CSV
- UTF-8 BOM CSV
- CSV formula injection neutralization
- Phase 2F UI and the unified five-state slot labels

## Repository strategy

Do not rewrite or delete the existing Workers source while migration is in progress.

Add the XSERVER implementation under:

```text
xserver/
  app/
    Auth/
    Controllers/
    Database/
    Http/
    Repositories/
    Services/
    Support/
    Views/
  bin/
    cron-reminders.php
    migrate.php
  config/
    config.example.php
  database/
    migrations/
  public/
    .htaccess
    index.php
    assets/
  tests/
  README.md
```

`xserver/config/config.local.php` must be ignored by Git and must never contain committed credentials.

## URL compatibility

Keep the current public/admin paths wherever possible:

- `/`
- `/login`
- `/auth/line`
- `/auth/line/callback`
- `/my-bookings`
- `/bookings/:id`
- `/reserve/:slug`
- `/reserve/:slug/book`
- `/admin`
- `/admin/login`
- `/admin/reservations`
- `/admin/reservations/:id`
- `/admin/slots/:id`
- `/admin/reservation-slots/:id/roster.csv`
- `/admin/reservations/:id/roster.csv`

Keep legacy redirects for old `/trips/...` URLs.

## Database migration

Port the logical schema from D1/SQLite to MySQL-compatible SQL.

### Tables

At minimum:

- users
- reservation_pages
- reservation_slots
- bookings
- notifications

The legacy `trips` table may be retained only if needed for import compatibility; it is not the canonical runtime model.

### MySQL-specific duplicate booking protection

SQLite partial unique indexes are not directly portable. Implement equivalent protection using a generated column or another DB-level uniqueness mechanism so a confirmed LINE user cannot have more than one confirmed booking for the same reservation slot.

Proxy/admin bookings with `user_id = NULL` must not be incorrectly deduplicated.

### Overbooking protection

Do not rely only on a pre-insert `SUM()` check.

For every booking transaction:

1. Begin transaction.
2. Lock the selected `reservation_slots` rows using `SELECT ... FOR UPDATE`, ordered by slot ID to reduce deadlocks.
3. Validate booking status, open/close times, capacity, max_party_size and duplicate booking state again under the lock.
4. Insert all selected bookings.
5. Commit only when every selected slot succeeds.
6. Roll back the entire group if any selected slot fails.

A grouped booking must remain **all-or-nothing**.

If a persistent reserved-seat counter is introduced, it must be transactionally maintained and covered by consistency tests.

### Cancellation

Cancellation must restore availability immediately and remain transactionally safe.

## Sessions and CSRF

Replace Worker cookie/session helpers with PHP equivalents while preserving:

- HttpOnly
- SameSite=Lax (or current equivalent)
- Secure in production
- signed / tamper-resistant session data
- CSRF protection on state-changing forms
- ownership checks returning 404 for another user's booking

Do not expose secrets in cookies or logs.

## LINE Login

Implement the existing flow in PHP without weakening validation:

- cryptographically random state
- PKCE S256
- nonce
- callback validation
- token exchange via HTTPS
- HS256 id_token signature verification using LINE Login channel secret
- iss / aud / exp / nonce verification
- subject used as LINE user identifier
- Friendship Status API

Production callback URL will be:

```text
https://<XSERVER-SUBDOMAIN>/auth/line/callback
```

The final host is deployment configuration, not hardcoded application logic.

## LINE Messaging API

Port booking completion and reminder messages with identical functional behavior.

Failures must not roll back an already-committed booking.

Notification states/retry rules must remain equivalent to current behavior:

- pending/requested/failed/skipped as applicable
- 4xx permanent skip behavior
- 5xx retry up to the current retry limit
- unique notification record per booking + notification type

## Cron

Create `xserver/bin/cron-reminders.php` as a CLI-only entry point.

It must:

- reject web execution
- load the same production config safely
- process due reminders
- avoid duplicate delivery
- write only non-sensitive operational logs
- return meaningful exit codes

Expected XSERVER schedule: every 5 minutes.

The exact PHP command path must be taken from XSERVER's server information at deployment time rather than hardcoded in the repository.

## UI

Reuse the current Phase 2F design.

Do not redesign during the runtime migration.

Required UI behavior includes:

- unified slot state labels: 受付中 / 受付開始前 / 受付終了 / 受付停止中 / 満席
- selected-slot summary
- sticky CTA
- same-page booking confirmation step
- `max_party_size=1` special UI
- grouped my-bookings presentation
- admin KPI dashboard
- roster-first slot detail layout
- CSV links
- mobile-first behavior

## CSV

Keep current columns and behavior.

Requirements:

- UTF-8 BOM
- Japanese-safe output
- companion columns automatically expand as needed
- confirmed-only by default
- cancelled inclusion option retained
- neutralize values beginning with formula-trigger characters such as `=`, `+`, `-`, `@`, tab or CR

## Configuration

Create `xserver/config/config.example.php` containing placeholders only.

Expected values include:

- APP_URL
- APP_ENV
- SESSION_SECRET
- DB_HOST
- DB_PORT
- DB_NAME
- DB_USER
- DB_PASSWORD
- LINE_LOGIN_CHANNEL_ID
- LINE_LOGIN_CHANNEL_SECRET
- LINE_MESSAGING_CHANNEL_ACCESS_TOKEN
- ADMIN_USERNAME
- ADMIN_PASSWORD_HASH or an equivalently secure credential representation

No real credential may be committed.

## Deployment layout

Prefer keeping PHP application code outside the web document root.

Example:

```text
/home/<server-id>/rakko-app/        # app/config/bin/database
/home/<server-id>/<domain>/public_html/<subdomain>/  # only xserver/public contents
```

If XSERVER's configured subdomain document root differs, deployment instructions must adapt to the actual panel configuration.

Only `public/` is web-accessible.

## Migration / cutover

Before production cutover:

1. Create XSERVER MySQL database and DB user.
2. Apply MySQL migrations.
3. Import required existing reservation data if any production D1 data exists.
4. Verify row counts and booking IDs.
5. Configure production secrets outside web root.
6. Configure LINE callback URL for the final XSERVER subdomain.
7. Configure Cron every 5 minutes.
8. Run production smoke tests.
9. Keep the Workers deployment untouched until XSERVER acceptance is complete.
10. Cut over only after successful verification.

Do not destroy D1 or Workers as part of the migration PR.

## Verification

Add XSERVER-side automated tests that cover, at minimum:

- reservation page and slot retrieval
- single-slot booking
- multi-slot grouped booking
- different party size per slot
- all-or-nothing rollback when one slot is full
- concurrent capacity protection
- duplicate confirmed booking rejection
- cancellation and re-booking
- booking open/close periods
- hidden/closed/full/before-open/open slot states
- max party size
- admin page/slot settings
- proxy booking
- checked_in_count inc/dec/all
- LINE message formatting
- reminder due/retry/dedup logic
- authorization and ownership
- CSRF
- CSV columns/BOM/Japanese/formula neutralization

The current Workers test suite remains the behavioral baseline. Do not delete or weaken it during migration.

## Completion criteria

Migration code is considered ready for XSERVER staging when:

- PHP static/syntax checks pass
- XSERVER-side automated tests pass
- current Workers tests still pass
- route compatibility is documented
- schema migration is documented
- no real secrets are committed
- manual local/staging smoke tests pass
- deployment README contains exact XSERVER panel steps for PHP version, MySQL, subdomain document root, SSL and Cron

Production release requires the user's actual XSERVER subdomain and DB credentials, which must be supplied out-of-band and never committed.
