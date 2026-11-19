# Backend API (Laravel 12)

## Run it locally on Windows + XAMPP

Needs PHP 8.2+ (XAMPP's PHP is fine) with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` enabled, and [Composer](https://getcomposer.org/).

1. Start **MySQL** in the XAMPP Control Panel.
2. Create the two databases (dev + tests), from a terminal in this folder:
   ```bat
   C:\xampp\mysql\bin\mysql -u root -e "CREATE DATABASE IF NOT EXISTS entertainment_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS entertainment_marketplace_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
3. Install and configure:
   ```bat
   composer install
   copy .env.example .env
   php artisan key:generate
   php artisan migrate --seed
   ```
   Seeding creates every role/permission and a Super Admin from `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` in `.env` — change these.
4. Run the API and the queue worker (two terminals):
   ```bat
   php artisan serve
   php artisan queue:work
   ```
   Check http://localhost:8000/api/v1/health
5. After pulling new code, double-click `scripts\update.bat` (install, migrate, re-sync roles, test; logs to `storage\logs\update-run.log`). Run the tests: `php artisan test` (or double-click `scripts\test.bat`, which logs to `storage\logs\test-run.log`)

Or double-click `scripts\setup-windows.bat`: it checks PHP/MySQL/Composer, does steps 2–3, runs the tests and logs everything to `storage\logs\setup-run.log`.

## Layout

```
app/
  Http/            shared middleware, base controller, health, error envelope
  Modules/
    Identity/      registration, login, tokens, password reset, roles & permissions (C01, C03)
    Admin/         admin APIs: user search, role assignment, suspension (I05 API)
    Audit/         append-only audit log service (B06)
    ...            Catalogue, Orders, Payments, Events, Tickets, Rentals, Notifications arrive per sprint
  Support/Money    decimal-safe money value object (LKR by default)
config/permissions.php   roles → permissions matrix (source of truth; synced by the seeder)
config/marketplace.php   currency, display timezone, token TTL, frontend URL
routes/api_v1.php        every v1 endpoint
```

## Endpoints so far

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/health` | — | App, database and queue health |
| POST | `/api/v1/auth/register` | — | Customer sign-up, returns token |
| POST | `/api/v1/auth/login` | — | Returns bearer token (30-day expiry) |
| POST | `/api/v1/auth/forgot-password` | — | Emails a reset link (same answer for unknown emails) |
| POST | `/api/v1/auth/reset-password` | — | Sets a new password, revokes all tokens |
| POST | `/api/v1/auth/logout` | token | Revokes the current token |
| GET | `/api/v1/me` | token | Current user, roles, permissions |
| PATCH, DELETE | `/api/v1/me` | token | Update profile/preferences · delete account (password; anonymises, keeps financial records) |
| POST | `/api/v1/me/verify/{email\|sms}/send` · `/me/verify/{email\|sms}` | token | Send / check a 6-digit verification code (verified email needed to buy) |
| PUT | `/api/v1/me/email`, `/api/v1/me/password` | token | Change email (re-verify) or password (signs out other devices) |
| GET, DELETE | `/api/v1/me/sessions[/{id}]` | token | Signed-in devices; sign one out |
| POST, DELETE | `/api/v1/me/devices` | token | Register / remove a push-notification token |
| GET | `/api/v1/me/export` | token | Download everything held about me (privacy request) |
| GET | `/api/v1/me/notifications` | token | Notification inbox with unread count |
| POST | `/api/v1/me/notifications/read` | token | Mark some (`ids`) or all as read |
| GET | `/api/v1/admin/users` | `users.view` | Search/filter users (paginated) |
| GET | `/api/v1/admin/users/{id}` | `users.view` | One user |
| PUT | `/api/v1/admin/users/{id}/roles` | `roles.assign` | Replace a user's roles (audited) |
| PATCH | `/api/v1/admin/users/{id}/status` | `users.manage` | Suspend / reactivate (audited, revokes tokens) |
| POST | `/api/v1/orders` | `customer.purchase` + `Idempotency-Key` header | Checkout (optional `currency`, default AUD): order + payment, returns `payment.checkout_url` |
| GET | `/api/v1/orders` | token | My orders / purchase history |
| GET | `/api/v1/orders/{reference}` | token (owner) | One order with items and payment status |
| POST | `/api/v1/orders/{reference}/verify-payment` | token (owner) | After returning from checkout: server asks the provider |
| POST | `/api/v1/orders/{reference}/pay` | token (owner) | New payment attempt after a failed one |
| POST | `/api/v1/payments/webhooks/{provider}` | provider signature | Payment notifications (stored once, processed on the `payments` queue) |
| GET | `/api/v1/dev/fake-gateway/{ref}?outcome=succeeded` | — (not in production) | Fake provider checkout page for local testing |
| GET | `/api/v1/admin/orders` | `orders.view` | Search orders by status, reference, email, dates |
| GET | `/api/v1/currencies` | — | Currencies customers can pay in, with the rate per 1 AUD |
| GET, PUT `/{code}` | `/api/v1/admin/currencies` | `settings.manage` | Add a currency, set its rate per 1 AUD, switch it on/off (audited) |
| GET | `/api/v1/movies` | — | Published movies with rental options (`q`, `genre`, `language`) |
| GET | `/api/v1/movies/{slug}` | — | One movie with rental packages |
| GET | `/api/v1/rentals` · `/rentals/{id}` | token (renter) | My rental library: status, start-by, time left, resume position |
| POST | `/api/v1/rentals/{id}/play` | token (renter) | Checks the rental, starts the window on first play, returns a 10-minute HLS link |
| PUT | `/api/v1/rentals/{id}/progress` | token (renter) | Save playback position for resume |
| GET/POST/PATCH | `/api/v1/admin/movies[...]` | `movies.manage` | Movies (list, `GET /{id}`), video assets, rental packages, publish/unpublish |
| GET | `/api/v1/admin/dashboard?from=&to=` | `reports.view` | Revenue split, refunds, commission, counts, daily series, top events/movies, items needing attention |
| GET | `/api/v1/admin/reports/{orders,refunds,payouts,ticket-sales,rentals}.csv?from=&to=` | `reports.view` | Finance CSV exports (local Sydney days) |
| GET | `/api/v1/admin/reports/organizer-balances.csv` | `reports.view` | Every organizer's current balance |
| GET | `/api/v1/admin/audit-logs` (`format=csv`) | `audit.view` | Audit log, filter by action (`refund.*`), actor, subject, request id |
| GET, PUT | `/api/v1/admin/settings` | `settings.manage` | Commission, booking fee, refund windows, payout hold (audited) |
| GET, POST | `/api/v1/orders/{reference}/refunds` | token (owner) | Customer refund requests (policy-checked) |
| GET | `/api/v1/admin/refunds` · POST `/{id}/approve`, `/{id}/reject` | `refunds.approve` | Finance reviews refund requests |
| POST | `/api/v1/admin/orders/{reference}/refunds` | `refunds.approve` | Staff refund outside policy (optional booking fee) |
| POST | `/api/v1/admin/events/{id}/cancel` | `events.manage` | Cancel event, refund every ticket in full (queued) |
| POST | `/api/v1/admin/tickets/{id}/cancel` | `events.manage` | Void a ticket without refund |
| GET | `/api/v1/organizer/balance`, `/organizer/ledger` | organizer owner/manager | Earnings: pending, available, paid out |
| GET, POST | `/api/v1/admin/organizers/{id}/balance`, `/ledger`, `/payouts` · PUT `/commission` | `payouts.manage` | Balances, payouts, per-organizer commission |
| GET | `/api/v1/events` | — | Upcoming published events (`q`, `category`, `city`, `from`, `to`) |
| GET | `/api/v1/events/{slug}` | — | Event details with ticket types, price, availability |
| POST | `/api/v1/organizer/apply` | customer | Apply to become an organizer (pending until approved) |
| GET | `/api/v1/organizer/profile` | organizer member | Organizer profile + members |
| POST/DELETE | `/api/v1/organizer/staff` | organizer owner | Add managers / gate staff by email; remove them |
| GET/POST | `/api/v1/organizer/venues` | `organizer.events.manage` | Venues the organizer can use / add one |
| GET/POST/PATCH | `/api/v1/organizer/events[/{id}]` | `organizer.events.manage` | Own events: create draft, edit |
| POST | `/api/v1/organizer/events/{id}/submit` | `organizer.events.manage` | Send for review (approved organizers only) |
| POST/PATCH | `/api/v1/organizer/events/{id}/ticket-types[/{id}]` | `organizer.events.manage` | Ticket types: price, quantity, sale window, max per order |
| GET, PATCH `/{id}/status` | `/api/v1/admin/organizers` | `organizers.approve` | Approve / reject / suspend organizers |
| GET (`?status=&search=`), GET `/{id}`, POST `/{id}/review` | `/api/v1/admin/events` | `events.approve` | List, view, approve or reject events awaiting review |
| GET | `/api/v1/tickets`, `/api/v1/tickets/{id}` | token (holder) | Ticket wallet with QR value |
| POST | `/api/v1/scanner/check-in` | `scanner.checkin` (assigned organizer) | Scan `{qr, event_id}`: admitted once, then `ticket_already_used` |
| GET | `/api/v1/scanner/events` · `/scanner/events/{id}/stats` | scanner staff | Events I can scan (with my gate) and live entry numbers |
| POST | `/api/v1/scanner/devices` | scanner staff | Register this phone/tablet as a scanning device |
| POST | `/api/v1/scanner/check-in` | assigned staff | Now also takes `gate_id` and `device_id`; every attempt is logged |
| GET, POST, DELETE | `/api/v1/organizer/events/{id}/gates`, `/staff` | `organizer.events.manage` | Gates and which gate staff work which event/gate |
| GET | `/api/v1/organizer/events/{id}/attendees[.csv]` | `organizer.attendees.view` | Attendee list / CSV (partly hidden emails) |
| GET | `/api/v1/organizer/events/{id}/sales`, `/stats`, `/scans` | `organizer.events.manage` | Sales and money, live entry stats, scan log |
| POST | `/api/v1/organizer/events/{id}/tickets/{ticket}/admit`, `/undo-check-in` | `organizer.events.manage` | Gate exceptions with a reason (audited) |
| GET, POST | `/api/v1/organizer/devices`, `/devices/{id}/revoke` | `organizer.events.manage` | Scanning devices; revoke a lost one |

### Sample data

`scripts\sample-data.bat` (or `php artisan db:seed --class=SampleDataSeeder`) fills the local database with five weeks of
realistic history so every admin and organizer screen has something to show:

- 12 customers plus finance, content and event staff
- 2 approved organizers with venues and gate staff, and 1 organizer application waiting for approval
- 6 events: one that already happened (with check-ins and a payout), two on sale, one waiting for approval, one draft, and one cancelled and refunded
- 7 movies with sample videos and rental packages
- About 50 paid orders in AUD, LKR and USD, failed and unfinished payments, rentals being watched, and refund requests

It goes through the real checkout, fake gateway, ledger and refund services, so all the totals agree. It runs once (it
does nothing if the sample customers exist) and only with the fake gateway outside production. Every sample account
uses the password `Password!2345`; the accounts are listed when it finishes (for example `kasun@example.com` for an
organizer). `scripts\sample-data.bat fresh` wipes the local database and starts again.

### Try a purchase locally (fake gateway)

Checkout sells event tickets `{"type":"ticket_type","id":1,"quantity":2}` and movie rentals `{"type":"rental_package","id":1,"quantity":1}`.
The flow: `POST /orders` → open `payment.checkout_url` and add `?outcome=succeeded` → the fake provider sends a
signed webhook → order becomes `paid` and `OrderPaid` fires once. Run `php artisan queue:work --queue=payments,fulfillment,notifications,default`
and `php artisan schedule:work` (expires unpaid orders after 15 minutes) alongside `php artisan serve`.

### Watch the sample film (rental test)

Double-click `scripts\try-sample-video.bat` (MySQL running in XAMPP). It loads a demo film, rents it as a demo
customer through the real checkout (fake payment, server-side verification, rental, entitlement check) and opens a
browser player on a 10-minute signed link. The film is Big Buck Bunny (Blender Foundation, CC BY 3.0).
Same thing by hand: `php artisan db:seed --class=Database\Seeders\DemoContentSeeder`, `php artisan serve`,
then `php artisan demo:rental-link` and open the printed link.

Locally (`APP_ENV=local`) the API returns verification codes as `debug_code`, and emails/SMS are written to
`storage/logs` instead of being sent, so the app can be tested without a mailbox or SMS provider.

### Payments with Stripe

Prices are set in **AUD** (base currency). Customers can pay in any currency an admin has switched on
(AUD, USD and LKR to start; USD and LKR need a rate first: `PUT /api/v1/admin/currencies/USD {"rate_per_base":"0.65","is_active":true}`).

To use Stripe instead of the fake gateway, in `.env`:
```
PAYMENT_GATEWAY=stripe
STRIPE_SECRET_KEY=sk_test_...        # Stripe Dashboard > Developers > API keys (test mode)
STRIPE_WEBHOOK_SECRET=whsec_...      # from the webhook endpoint (or `stripe listen` locally)
```
Webhook endpoint: `https://<your-api>/api/v1/payments/webhooks/stripe`, events `checkout.session.completed`,
`checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`.
Locally, the Stripe CLI forwards them: `stripe listen --forward-to localhost:8000/api/v1/payments/webhooks/stripe`.

Auth endpoints are limited to 5 attempts per minute per email+IP; other API calls to 120/min per user.

Note: XAMPP ships MariaDB, production targets MySQL 8. CI runs the tests on MySQL 8 so both are covered.
