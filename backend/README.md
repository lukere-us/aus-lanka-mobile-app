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
5. Run the tests: `php artisan test`

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

Auth endpoints are limited to 5 attempts per minute per email+IP; other API calls to 120/min per user.

Note: XAMPP ships MariaDB, production targets MySQL 8. CI runs the tests on MySQL 8 so both are covered.
