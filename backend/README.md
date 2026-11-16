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
| GET, POST `/{id}/review` | `/api/v1/admin/events` | `events.approve` | Approve or reject events awaiting review |
| GET | `/api/v1/tickets`, `/api/v1/tickets/{id}` | token (holder) | Ticket wallet with QR value |
| POST | `/api/v1/scanner/check-in` | `scanner.checkin` (assigned organizer) | Scan `{qr, event_id}`: admitted once, then `ticket_already_used` |

### Try a purchase locally (fake gateway)

Checkout sells event tickets: `{"items":[{"type":"ticket_type","id":1,"quantity":2}]}` (movie rentals arrive next).
The flow: `POST /orders` → open `payment.checkout_url` and add `?outcome=succeeded` → the fake provider sends a
signed webhook → order becomes `paid` and `OrderPaid` fires once. Run `php artisan queue:work --queue=payments,fulfillment,default`
and `php artisan schedule:work` (expires unpaid orders after 15 minutes) alongside `php artisan serve`.

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
