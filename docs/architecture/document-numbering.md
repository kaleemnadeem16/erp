[← Architecture](README.md)

# Document numbering

One central framework numbers every document that carries a number — purchase orders, goods receipts, sales orders, invoices,
credit notes, stock transfers, work orders and whatever comes next. Decision and research:
[ADR 0079](../decisions/0079-document-numbering-system-templates-over-a-variable-registry.md). Code: the `Numbering` module
([README](../../backend/app/Modules/Numbering/README.md)).

## How it fits together

```
Document type (registered in code)  ──►  Number series  ──►  Series version  ──►  Template + Counter scope + Continuity
        │                                      │                                          │
        │ supplies a numbering context         │ routing: manual choice › rule › default  ▼
        ▼                                      ▼                                       Counter  (one per series + scope key)
   NumberingService::allocate(context)  ──────────────────────────────────────────►  Allocation log (frozen, append-only)
```

- **Document type** — what a module registers: key, variables it can supply, defaults, permission, when it becomes official.
- **Series** — a named numbering line of a type. Every type has a `DEFAULT`; others (`IMPORT`, `EXPORT`) are optional.
- **Version** — the governed configuration of a series: template, counter scope, continuity. A change adds a version.
- **Template** — `PO-{BRANCH}-{YYYY}-{SEQ:5}`: fixed text, variables, exactly one counter.
- **Counter scope** — which counter a number comes from: a period (`NONE, YEAR, MONTH, DAY, FISCAL_YEAR, FISCAL_PERIOD`) plus
  dimensions (the document's variables, e.g. `BRANCH`). Explicit. A variable outside the scope is only decoration.
- **Counter** — the running number for one (series, scope key).
- **Allocation log** — for every number: the text, series, version, every resolved variable **with the record it came from**, the
  issue instant and local date, the scope key and the counter value. Never edited; numbers are never recomputed.
- **Company constants** — `{REGION}=NORTH`, a governed history.

## The rules the engine holds

| Rule | Meaning |
|---|---|
| **Uniqueness boundary** | A number is unique within *(company, document type)* across all that type's series — enforced by `UNIQUE(company, doc_type, number)` on the log. Saving a configuration is rejected when two different counters could issue the same text (checked by sampling periods, dimension values and counter values); the index is the proof. |
| **Issue date** | Date and fiscal variables use the instant the document becomes official, never an editable document date, in the **company's time zone** (`companies.timezone`, default `UTC`, set in **Settings → Company**, [ADR 0081](../decisions/0081-central-company-profile-and-company-wide-settings.md); a general company setting, not a numbering one). The tester warns when a date-based format is about to use the `UTC` default. |
| **Continuity** | Per series: `GAPLESS` (the counter is advanced inside the document's transaction, so a rollback returns the number) or `ALLOW_GAPS` (each counter owns a PostgreSQL sequence; `nextval` never waits, a rollback leaves a gap). A type can lock its policy (statutory documents). |
| **Frozen values** | Business-derived variables (supplier, currency, warehouse…) are offered only where the document freezes the source at numbering; their values and sources are copied into the log. |
| **Counters only rise** | `RaiseCounter` needs a reason and is audited; lowering is refused by the action and a database check. |
| **History** | Versions, allocations, counter adjustments and constants are append-only. |

## Series routing

A document is numbered from, in order: **(1)** the series the caller names (`requestedSeries`) if it is active and `is_selectable`
(the caller has already checked the actor may choose — permission `numbering.series.select`); **(2)** the first matching routing
rule by priority — a rule is a list of conditions `{attribute, EQ|IN, values}` over the routing attributes the document type
exposes, all of which must hold; **(3)** the default series.

## Adding a numbered document

1. **Implement `NumberableDocumentType`** (see `Purchasing/Numbering/PurchaseOrderNumbering.php` for a worked example): `key()`,
   `label()`, `module()`, `becomesOfficial()`, `variables()` (each with whether its source is frozen at numbering), `dimensions()`,
   `routingAttributes()`, `defaultTemplate()`, `defaultScope()`, `defaultContinuity()`, `continuityLocked()`,
   `managePermission()`, `sampleContext()`, `knownDimensionValues()`.
2. **Register it** from the module's provider: `afterResolving(NumberingRegistry::class, fn ($r) => $r->register(new MyDocumentNumbering))`.
3. **Register its manage permission** in the module's permission vocabulary (e.g. `sales.numbering.manage`).
4. **Hand over a context** at the moment the document becomes official, inside that transaction:
   `NumberingService::allocate(new NumberingContext(type, issuedAt, values, routing, documentId, requestedSeries, actorId))` and
   store `->number` on the document. Each supplied value is `new NumberingValue('SUP012', 'supplier_accounts#12')` — the text and
   where it came from.
5. **Nothing else.** No tables, no counters, no screens, no preview — they are the framework's. Do not read or alter the log.

A document with no existing numbering gets its `DEFAULT` series from the type's defaults on first use. To keep a document's
current visible numbering when moving it onto the framework, make `defaultTemplate()` reproduce it (`{SEQ:4}` for the zero-padded
number) and, if it already has numbers, carry the old counter over in a migration (see `2026_10_02_110000_create_numbering_framework_tables`).

## Current document types

| Type | Becomes official | Variables | Dimensions | Default |
|---|---|---|---|---|
| `PURCHASE_ORDER` | issued | `SUPPLIER_CODE`, `CURRENCY` | — | `{SEQ:4}`, ALLOW_GAPS (existing companies: GAPLESS, carried over) |
| `GOODS_RECEIPT` | posted | `WAREHOUSE`, `SUPPLIER_CODE` | `WAREHOUSE` | `{SEQ:4}`, ALLOW_GAPS (existing companies: GAPLESS, carried over) |

## API and screens

**Settings → Document Numbering** (`/settings/document-numbering`, needs `numbering.view`) lists every registered document type with
its current format, a live example, how often it restarts and its gap policy. *Edit* opens a side sheet
(`/settings/document-numbering/{TYPE}`): the simple view is the **format** (a variable palette inserts at the cursor), **how often to
restart the count** in plain words, **whether gaps are allowed**, a **live tester**, and a reason box. Everything else sits behind that
sheet's *Advanced options*: count separately by a dimension, series and routing rules, counters (raise only), and history.
*Company constants* is a toggle on the page. A time-zone notice shows while the company is still on the UTC default.

| Call | Does | Needs |
|---|---|---|
| `GET numbering/document-types` | Every type with its series, current version and example | `numbering.view` |
| `GET numbering/context` | Built-in variables, constants, the company time zone | `numbering.view` |
| `POST numbering/preview` | The tester; reads counters, never takes a number | `numbering.view` |
| `POST numbering/series` · `POST numbering/series/{id}/versions` | Add a series · add a governed version | the type's manage permission |
| `GET numbering/series/{id}/versions · counters · rules` | History · counters (with next number) · routing rules | `numbering.view` |
| `POST numbering/series/{id}/rules` | Add a routing rule | the type's manage permission |
| `POST numbering/counters/{id}/raise` | Raise a counter, with a reason | `numbering.counter.raise` |
| `GET/POST numbering/constants` | Constants and their history · record or retire one | `numbering.view` · `numbering.constant.manage` |

A refused configuration is a `422` whose message says why (for example which two counters would both reach `PO-2026-00001`).
Modules link into this one screen; they keep no numbering screens of their own.

## `{COMPANY}`

The company's short code from Settings → Company (never the editable name). It is frozen with the number in the allocation log; a format that
uses it before the company has a code fails with a message pointing to the Company screen.

## Not built yet

Purchase-order
`{WAREHOUSE}` (needs a ship-to); a read screen for the allocation log; numbers-must-follow-dates validation for gapless series. See
ADR 0079 §19.

## Related

- [invariants.md](invariants.md#8-document-numbers-are-unique-issued-by-one-framework-and-gapless-only-where-a-series-says-so) · [extensibility.md](extensibility.md) · [ADR 0079](../decisions/0079-document-numbering-system-templates-over-a-variable-registry.md)
