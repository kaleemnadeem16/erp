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
| **Issue date** | Date and fiscal variables use the instant the document becomes official, never an editable document date, in the **company's time zone** (the existing `companies.accounting_timezone`, default `UTC`; a general company setting, not a numbering one). The tester warns when a date-based format is about to use the `UTC` default. |
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

## Not built yet

The API and **Settings → Document Numbering** screens (list of document types with examples, the default-series editor with the
tester, the advanced areas); a company profile screen for the time zone (today it is set in Inventory settings); `{COMPANY}` (needs a company code); purchase-order
`{WAREHOUSE}` (needs a ship-to); numbers-must-follow-dates validation for gapless series. See ADR 0079 §19.

## Related

- [invariants.md](invariants.md#8-gapless-document-numbering) · [extensibility.md](extensibility.md) · [ADR 0079](../decisions/0079-document-numbering-system-templates-over-a-variable-registry.md)
