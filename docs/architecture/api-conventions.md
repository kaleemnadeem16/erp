[← Architecture](README.md)

# API Conventions

The API is the only interface to the backend — web, mobile and integrations all use it
([ADR 0002](../decisions/0002-api-first-react-spa.md)). Consistency matters more than usual
because the TypeScript client is generated from it
([ADR 0006](../decisions/0006-generated-api-client.md)).

**Read before adding an endpoint.**

---

## Routes

```
/api/v1/{resource}
```

Plural, kebab-case. Nested only one level deep.

```
GET    /api/v1/quotations
POST   /api/v1/quotations
GET    /api/v1/quotations/{quotation}
PATCH  /api/v1/quotations/{quotation}
DELETE /api/v1/quotations/{quotation}

GET    /api/v1/quotations/{quotation}/lines
POST   /api/v1/quotations/{quotation}/confirm      ← action, not CRUD
```

**State transitions are named sub-resources, not PATCH on a status field.** `POST
…/confirm` expresses intent and can enforce its own rules; `PATCH {status: "confirmed"}`
cannot.

Versioning is in the path. `v1` stays stable; breaking changes create `v2`.

## Responses

Single resource:

```json
{ "data": { "id": 1, "number": "QUO-0001" } }
```

Collection:

```json
{
  "data": [ … ],
  "meta": { "current_page": 1, "per_page": 25, "total": 132 },
  "links": { "next": "…", "prev": null }
}
```

Always wrapped in `data` — including single resources — so response shape never changes when a
field is added at the envelope level.

## Errors

Laravel's default validation shape, used consistently:

```json
{
  "message": "The given data was invalid.",
  "errors": { "customer_id": ["The customer field is required."] }
}
```

| Status | Meaning |
|---|---|
| 400 | Malformed request |
| 401 | Not authenticated |
| 403 | Authenticated, not permitted |
| 404 | Not found — **or** not visible to this company |
| 409 | Conflict with current state (e.g. confirming an already-confirmed quotation) |
| 422 | Validation failed |
| 429 | Rate limited |

**404, not 403, for another company's records.** A 403 confirms the record exists.

Business rule violations return **422** with the same error shape as validation, so the frontend
handles both identically.

**The mapping is global**, registered in `bootstrap/app.php` so no database exception can reach a
client. Registration order matters — the first renderer to return a response wins, and
`UniqueConstraintViolationException` extends `QueryException`, so the specific handlers come
first:

| Source | Status |
|---|---|
| `DomainInvariantException` (a model or Action guard) | 422 |
| `UniqueConstraintViolationException` | 422 |
| SQLSTATE `23503` — foreign-key violation | 409 |
| SQLSTATE `23514` — CHECK violation, including every immutability trigger | 422 |

Known gap: an Action that rejects a caller on a permission check also raises
`DomainInvariantException`, so it currently answers **422 rather than 403**. The table above
reserves 403 for exactly that case; `UpdateInventorySettingsRequest::authorize()` is the only
place today that returns a real 403.

## Pagination

Cursor pagination for large or frequently-appended lists (stock moves, ledger entries, activity
log). Page-based elsewhere — ERP users expect page numbers.

Default 25, maximum 100.

## Filtering and sorting

```
GET /api/v1/items?filter[type]=RAW&filter[search]=pigment&sort=-created_at&include=uom
```

Consistent across all collection endpoints. `include` for relations — never load relations
unconditionally. Collection endpoints validate both parameters: `sort=field` is ascending,
`sort=-field` is descending, and unsupported sort fields or includes return `422` rather than
being silently ignored. Resource updates use `PATCH`; `PUT` is reserved for a future endpoint
that genuinely accepts a complete replacement representation.

A boolean filter (`filter[active]=true`) accepts `true` / `false` as well as `1` / `0`: a query string
is text, and the generated client serialises a boolean as `"true"`, which Laravel's own `boolean` rule
rejects. `ValidatesListQuery` normalises any `filter.*` key its request declares `boolean`
([ADR 0060](../decisions/0060-accept-true-and-false-for-boolean-list-filters.md)), so declare a new
one that way rather than working around it in a screen.

## Money

```json
{ "amount": "1250.0000", "currency": "PKR", "base_amount": "1250.0000" }
```

**Money is a string in JSON, never a number.** JavaScript's `number` cannot represent
`decimal(19,4)` reliably, and silent precision loss in financial data is unacceptable.

Both transaction and base amounts are always present
([invariants.md](invariants.md#5-both-currency-amounts-always)).

## Dates

ISO 8601 UTC — `2026-07-29T11:54:00Z`. Timezone conversion is a presentation concern.

## Idempotency

Endpoints that create financial documents accept an `Idempotency-Key` header. Required for
integration and webhook consumers, where retries are routine.

## Rate limiting

| Scope | Limit |
|---|---|
| Authenticated | 120/min per user (`API_RATE_LIMIT_PER_MINUTE`, `config/api.php`; production keeps the default) |
| Login | 5/min per IP (keyed on the address, so repeated scripted sign-ins exhaust it too) |
| Integrations | Per-token, configurable |

## Generated client

Every endpoint must produce a correct OpenAPI entry. Where Scramble cannot infer a response
shape, add explicit types — do not leave it to guess. CI regenerates and fails on diff, so a
missing type surfaces as a build failure rather than an `any` in the frontend.

**The models barrel is rewritten after generation.** Orval writes `generated/models/index.ts` as one
`export * from` line per schema — over a thousand. `export *` is a value re-export a browser cannot
skip, so in dev (Vite serves each file as its own module) every cold page load fetched every schema
file, although about 1,280 of them are pure types that compile to empty modules: roughly 1,500 requests
per load, which made the dev app crawl whenever the disk was busy. `scripts/slim-models-barrel.mjs`
(orval's `afterAllFilesWrite` hook) rewrites the barrel so pure-type schemas use `export type *` — erased
at build time — and only the ~80 that export a runtime value (enum-like constants) keep `export *`.
The names importable from `@erp/core-api` are unchanged; a cold dev load now makes ~350 requests, and
the production build transforms ~1,270 fewer modules. Don't hand-edit the barrel: regenerate.

## Related

- [code-structure.md](code-structure.md)
- [ADR 0006](../decisions/0006-generated-api-client.md)
- [ADR 0005](../decisions/0005-sanctum-authentication.md)
