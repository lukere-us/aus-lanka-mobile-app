# Architecture (baseline, A04 / B03)

**Shape:** one Laravel application (modular monolith) serving a versioned REST API to four clients —
customer app (Expo), admin + organizer portals (Next.js), scanner app (Expo). Video is delivered by
object storage + CDN, never through the application server.

## Modules (bounded contexts)

| Module | Owns | Talks to others through |
| --- | --- | --- |
| Identity | users, roles, permissions, tokens, organizer onboarding | `User` model, Gate permissions |
| Audit | audit_logs | `AuditLogger::record()` |
| Catalogue (Movies) | movies, media assets, rental packages | read models for Orders |
| Events | events, venues, ticket types, inventory | reservations service |
| Orders | orders + item snapshots, lifecycle | `OrderService`, domain events |
| Payments | gateway adapters, webhooks, refunds, ledger | `PaymentGateway` interface |
| Fulfillment | rentals, tickets + QR tokens, check-in | listens to `OrderPaid` |
| Notifications | templates, push/email/SMS | queued listeners |
| Reporting | KPIs, exports | read-only queries |

Rule: a module never writes another module's tables directly; it calls that module's service or reacts to its event.

## Non-negotiables (plan §2.1)

- Fulfillment only after **server-verified** payment (signed webhook or server-side verify call); never trust app return URLs.
- Webhooks, fulfillment and notifications are **idempotent** (unique event ids, `firstOrCreate` + DB constraints).
- Ticket stock reserved inside a DB transaction with **row locks** (`lockForUpdate`) and an expiry released exactly once.
- QR values are **opaque random tokens** — no customer or payment data.
- Check-in is **atomic** (`UPDATE ... WHERE status = 'valid'` affecting exactly one row).
- Playback needs a server entitlement check + **short-lived signed URL**; no permanent public video URLs.
- Money = `DECIMAL(12,2)` + `currency CHAR(3)` (`App\Support\Money`); timestamps stored **UTC**, shown in Asia/Colombo.
- Financial, organizer, ticket-state and privileged admin actions are written to **audit_logs**.

## Payments (Milestone 2)

Gateway: **Stripe Checkout** (cards, Apple Pay, Google Pay), decided 16 Nov 2026. Code targets the `PaymentGateway`
interface (`createCheckout`, `parseWebhook`, `fetchStatus`, `cancelCheckout`); `StripeGateway` calls Stripe's REST API
directly, `FakeGateway` stands in for local work without keys. Stripe webhooks must send `checkout.session.completed`,
`checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed` and `checkout.session.expired` to
`/api/v1/payments/webhooks/stripe`.

## Market and currencies

Run from Australia, sold worldwide. **Base currency AUD**: every price is set in AUD and payouts/reports use AUD.
Admins manage payable currencies (AUD, USD, LKR to start) and their rate per 1 AUD. At checkout the customer picks
a currency; prices are converted, and the rate plus the AUD amounts are locked into the order (`exchange_rate`,
`base_total`, `order_items.base_unit_price`). Venues carry `country` + IANA `timezone`; event times are stored UTC and
returned with `starts_at_local` in the venue's zone. Phone numbers are international E.164.

```
App: POST /orders (Idempotency-Key) ──► Order(pending_payment) + OrderItems(snapshot) + Payment(initiated)
                                        └─► gateway.createCheckout → checkout_url
Customer pays at provider
Provider ──► POST /payments/webhooks/{provider}
             parseWebhook (signature) → payment_events inbox (unique provider+event_id) → ProcessPaymentEvent job
             → gateway.fetchStatus (server-side truth) → lock payment + order rows
             → amount/currency match? → Payment succeeded, Order paid, audit, OrderPaid (after commit, once)
                                     ✗ → Payment "review", audit, no fulfillment
App return ──► POST /orders/{ref}/verify-payment → same fetchStatus path (never trusts the app)
Scheduler ──► orders:expire-pending → checks provider first, then Order expired + OrderExpired (release stock)
```

Sellable modules plug into checkout by registering a `PurchasableResolver` for their item type
(`rental_package`, `ticket_type`, ...) in `PurchasableRegistry`; they listen to `OrderPaid` / `OrderExpired`.

## Environments

| Env | Database | Queue | Notes |
| --- | --- | --- | --- |
| Local | XAMPP MariaDB | database driver | `php artisan serve` |
| CI | MySQL 8 service | sync | GitHub Actions, `backend-ci.yml` |
| Staging / Prod | MySQL 8 InnoDB | Redis + Horizon (planned) | HTTPS only, secrets outside git |

## Ticketing (Milestone 3)

```
Organizer applies ─► pending ─► admin/event manager approves ─► owner gets Organizer role
Organizer: venue → draft event → ticket types (General / Early Bird / VIP / VVIP / custom) → submit
Admin review ─► published ─► public discovery (/events)
Checkout {"type":"ticket_type"} ─► TicketTypeResolver (on sale? window? max per order?)
   └─ inside the order transaction: lock ticket_type row → available ≥ qty? → reserved += qty, reservation(held)
OrderPaid  ─► IssueTicketsForPaidOrder (queue: fulfillment) ─► lock → reserved -= qty, sold += qty → N tickets (opaque QR)
OrderExpired ─► release held reservations once (reserved -= qty)
Late payment after expiry ─► takes free stock if any, else no tickets + audit "tickets.unfulfillable" (refund)
Gate scan ─► UPDATE tickets SET status='used' WHERE id=? AND status='valid'  (1 row = admitted, 0 = rejected)
```

Inventory invariant: `quantity_sold + quantity_reserved ≤ quantity_total`, changed only by `InventoryService` under a row lock.
Organizer data is always scoped through `OrganizerContext` (organizer_members); gate staff can scan only their organizer's events.

## Movie rentals and playback (Milestone 4)

```
Admin (movies.manage): movie draft -> video asset (provider + id) -> rental packages (AUD price, watch hours,
                       start-within days) -> publish (needs a ready video + an active package)
Checkout {"type":"rental_package"} -> RentalPackageResolver (published? quantity 1? not already rented?)
OrderPaid -> CreateRentalsForPaidOrder (queue: fulfillment) -> Rental(ready, activate_by = paid + N days), once per item
POST /rentals/{id}/play -> PlaybackService: owner + account active + window check
   first play: ready -> active, expires_at = now + watch_hours
   -> VideoProvider.playbackUrl(asset, viewer, min(now + 10 min, rental end))   (link never outlives the rental)
PUT /rentals/{id}/progress -> resume position per user + movie
rentals:expire (every 5 min) + effectiveStatus() at read time: active past expires_at / ready past activate_by -> expired
```

Video hosting is behind `VideoProvider` (`config/video.php`). The `sample` provider issues our own HMAC-signed,
expiring link (`/api/v1/dev/stream/{token}`) that redirects to a public test film, so the full flow can be tested
before the client chooses Bunny Stream or Cloudflare Stream. With a real provider the CDN itself validates the
signed token, so the video files are never reachable without one. DRM (Widevine/FairPlay) is a provider setting plus
player configuration; not enabled until a licence requires it.

## Fees, commissions, refunds and payouts (D06, D07, F08)

Admin-editable settings (`/api/v1/admin/settings`, audited; placeholder defaults until the product owner confirms):
`ticket_commission_bps` 1000 (10%), `booking_fee_per_ticket` 0.00 AUD, `booking_fee_bps` 0, `ticket_refund_cutoff_hours` 48,
`rental_refund_window_hours` 48, `payout_hold_days` 2. Organizers can carry their own commission rate.

```
Checkout: booking fee (tickets only) = per-ticket AUD fee + % of ticket subtotal -> converted like prices -> fee_total
Tickets issued -> organizer ledger: +sale (AUD line total), -commission (rate snapshot)        [source_key = idempotent]
Refund request (customer, policy-checked) | staff refund (no policy, optional fees) | event cancelled (all tickets + fees)
  approve: lock -> tickets valid->refund_pending (unscannable), unwatched rentals ready->revoked
  provider refund on the original payment (Stripe payment_intent; Idempotency-Key refund-{id})
    succeeded -> tickets refunded, seat back on sale (future events), ledger -refund +commission_reversal,
                 order partially_refunded / refunded
    failed    -> tickets/rentals restored, refund failed
    pending   -> refunds:sync (every 10 min) settles it later
Balance = SUM(ledger); available = entries for events started >= hold days ago, minus payouts; payout <= available
```

## Customer accounts (C02, C04)

- Email verification by 6-digit code, sent at registration and after an email change; **a verified email is required to buy**.
  Phone verification by SMS code (log driver until an SMS provider is chosen). Codes: keyed hash only, 10-minute expiry,
  5 guesses, 60-second resend cooldown, newest code only. In APP_ENV=local the API also returns `debug_code`.
- `/me`: profile, preferred currency, marketing opt-in, notification preferences; email change (password + re-verify);
  password change (signs out other devices); sessions list/revoke; push device tokens (FCM, one token = one install).
- Privacy: `/me/export` returns everything held about the person. `DELETE /me` (password; must confirm giving up
  upcoming tickets/active rentals; blocked for organizer owners and open refunds) erases personal details, revokes
  tokens and devices, and soft-deletes an anonymous user row. Orders, payments, refunds and the audit log are kept for
  record keeping and no longer identify the person.
