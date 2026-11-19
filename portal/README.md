# Admin and organizer website (Next.js)

The web portal for platform staff (administrators, finance, content and event teams) and event organizers. It uses
the Laravel API in `../backend` for everything; it has no database of its own.

## Run it on Windows

1. Install Node.js LTS (20.9 or newer) from https://nodejs.org if `node -v` doesn't print a version.
2. Start the API: `cd backend` and `php artisan serve` (http://localhost:8000).
3. First time: run `portal\scripts\setup-windows.bat`. It creates `portal\.env.local`, installs packages, checks the code
   and starts the site at http://localhost:3000.
4. After that, use `portal\scripts\start.bat`.

Sign in with the super admin from `backend/.env` (`SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD`) or any staff or
organizer account. Customer and gate-staff accounts are turned away: they use the mobile apps.

## How it works

- **Sign-in.** The sign-in form posts to a server action (`app/login/actions.ts`), which calls `POST /auth/login`
  and keeps the Sanctum token in an httpOnly cookie (`al_portal_token`). Browser JavaScript never sees the token,
  and the browser never calls the API directly, so the API needs no CORS entry for this site. Signing out revokes
  the token on the API.
- **API calls.** Pages are server components and call the API with `api()` from `lib/api.ts`, which adds the
  token, turns the API's error envelope into an `ApiError`, and sends the user back to sign-in on a 401.
- **Route guards.** `app/(portal)/layout.tsx` requires a signed-in user with at least one portal permission; each
  page checks its own permission with `can()` (from `lib/session.ts`, using the `permissions` list in `GET /me`).
  The API checks permissions again on every call, so the website's checks are only for showing the right screens.
- **Menu.** `lib/nav.ts` lists every screen with the permissions that show it. Screens not built yet appear greyed
  out as "Soon".
- **CSV downloads.** Links point to `/download/...` (`app/download/[...path]/route.ts`), which fetches the file
  from the API with the cookie's token and streams it to the browser. Only CSV report paths are allowed.

## Screens

| Screen | Path | Who sees it | Backlog |
| --- | --- | --- | --- |
| Sign in / sign out | `/login` | everyone | I01 |
| Dashboard | `/dashboard` | `reports.view` | I02 |
| Movies & rentals: list, add, details, videos, rental packages, publish | `/movies` | `movies.manage` | I03 |
| Events: approval queue, details, approve / send back, cancel with refunds | `/events` | `events.approve` or `events.manage` | I04 |
| Users & roles: search, roles, suspend / reactivate | `/users` | `users.view` (changes need `roles.assign`, `users.manage`) | I05 |
| Organizers: approve, reject, suspend | `/organizers` | `organizers.approve` | I05 |
| Orders & payments: search, details, refund an order | `/orders` | `orders.view` (refund needs `refunds.approve`) | I06 |
| Refunds: decide requests, follow failed ones | `/refunds` | `refunds.approve` | I06 |
| Payouts: balances, record payouts, commission per organizer, ledger | `/payouts` | `payouts.manage` | I06 |
| My events: create, ticket types, submit, sales, attendees (+ CSV, manual check-in), gates & staff, event-day numbers | `/organizer/events` | organizer accounts | I07 |
| Venues, Team, Scanner devices, Earnings | `/organizer/...` | organizer accounts | I07 |
| CSV downloads | `/reports` | `reports.view` | I08 |
| Settings & currencies | `/settings` | `settings.manage` | I08 |
| Audit log | `/audit-log` | `audit.view` | I08 |

Changes go through server actions (`actions.ts` next to each screen) using `attempt()` from `lib/actions.ts`, which
shows the API's own message on the form when it refuses. Event and ticket times are typed in the venue's local time and
converted to UTC before they're sent (`fromLocalInput` in `lib/format.ts`).

## Settings (`.env.local`)

| Name | Default | Meaning |
| --- | --- | --- |
| `API_BASE_URL` | `http://127.0.0.1:8000/api/v1` | Laravel API, used from the server only |
| `SESSION_COOKIE_SECURE` | `false` | `true` when served over https |

## Commands

- `npm run dev`: development server on port 3000
- `npm run typecheck`: TypeScript check
- `npm run build` then `npm start`: production build and server
