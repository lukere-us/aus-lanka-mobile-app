# API conventions (A04)

- **Base path:** `/api/v1`. Breaking changes ship as `/api/v2`; additive changes (new fields, endpoints) stay in v1.
- **Auth:** `Authorization: Bearer <token>` from `/auth/login` or `/auth/register`. Tokens expire after 30 days; one token per device (`device_name`).
- **JSON only.** Send `Accept: application/json`. Field names are `snake_case`.
- **Success:** `{ "data": ... }`. Lists are paginated: `{ "data": [...], "links": {...}, "meta": { "current_page", "per_page", "total", ... } }`, `?per_page=` max 100.
- **Errors:** always
  ```json
  { "error": { "code": "validation_failed", "message": "The given data was invalid.", "details": { "email": ["..."] } },
    "request_id": "9b1c..." }
  ```
  Codes: `validation_failed` 422, `unauthenticated` 401, `forbidden` 403, `account_suspended` 403, `not_found` 404,
  `conflict` / domain-specific 409, `too_many_requests` 429 (with `Retry-After`), `server_error` 500.
  Clients switch on `error.code`, never on `message`.
- **Tracing:** every response carries `X-Request-Id`. Clients may send their own (8–64 chars `[A-Za-z0-9-_.]`); it is logged and stored on audit records.
- **Money:** `{ "amount": "1500.00", "currency": "AUD" }` — amount is a string, never a float. Catalogue prices are
  in the base currency (AUD); orders are in the currency the customer chose (`POST /orders` with `"currency": "USD"`),
  with `exchange_rate.rate` and `exchange_rate.base_total` when it differs from AUD. `GET /currencies` lists choices.
- **Time:** ISO-8601 UTC with `Z` (`2026-12-20T13:30:00Z`). Events also return `starts_at_local` in the venue's timezone
  (e.g. Australia/Melbourne); show that for event times, and the device's zone for everything else.
- **IDs:** numeric ids internally; anything a customer can see or scan (order refs, QR) uses random opaque tokens.
- **Idempotency:** POSTs that create orders/payments will require an `Idempotency-Key` header (from Milestone 2).
- **Permissions:** enforced on the server via `permission:<slug>` middleware and policies; the UI hiding a button is never the control.
- **Docs:** an OpenAPI file (`docs/openapi.yaml`) is maintained alongside each module from Milestone 2.
