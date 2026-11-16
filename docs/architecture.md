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

Gateway not chosen yet (PayHere / WEBXPAY under evaluation). Code targets the `PaymentGateway` interface
(`createCheckout`, `parseWebhook`, `fetchStatus`); `FakeGateway` behaves like a real provider for development and
tests. A real adapter = one class + a `config/payments.php` entry.

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
