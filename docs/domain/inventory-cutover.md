[← Domain index](README.md)

# Inventory Cutover

How inventory from a predecessor system becomes an auditable opening state without weakening
normal stock controls. The generic mapping/staging mechanism is defined in
[data-import.md](../architecture/data-import.md); this document defines inventory semantics.

## Modes

| Mode | Imports | Does not pretend |
|---|---|---|
| Opening state | Quantity, carrying value and continuing valuation state at cutover | To contain historical movements |
| Operational cutover | Opening state plus open operational documents | That open POs are receipts or liabilities |
| Full history | Available source movements and official valuations, then an opening state | That incomplete detail is reconstructed fact |

The mode is selected per company and recorded on the cutover. Different entities may be loaded
in dependency order, but there is one inventory cutover timestamp and one reconciled inventory
opening state.

## Planning schema

```
inventory_cutovers
  id, company_id
  mode                         OPENING_STATE | OPERATIONAL | FULL_HISTORY
  cutover_at, source_system
  target_valuation_policy      json         -- per-item/category resolution snapshot
  status                       DRAFT | VALIDATED | APPROVED | FINALISED | FAILED
  approved_by NULLABLE, approved_at NULLABLE
  finalised_by NULLABLE, finalised_at NULLABLE
  control_quantity, control_value, base_control_value
  exception_summary            json NULLABLE

inventory_cutover_batches
  id, company_id, inventory_cutover_id, import_batch_id
  entity_type                  OPEN_DOCUMENT | MOVEMENT | LAYER | OPENING_STATE | …
  source_record_count, accepted_record_count, rejected_record_count
  source_quantity, source_value, base_source_value
  status

inventory_source_facts
  id, company_id, inventory_cutover_id, import_batch_row_id
  source_system, source_entity_type, source_record_id
  source_effective_at, source_posting_date NULLABLE
  item_id, location_id NULLABLE
  source_valuation_method, source_avco_scope NULLABLE
  source_valuation_pool_identity NULLABLE
  qty, uom_id
  unit_cost NULLABLE, amount, base_amount
  currency_id NULLABLE, fx_rate NULLABLE
  batch_no NULLABLE, serial_no NULLABLE
  source_payload                 json       -- immutable evidence, not live state
  UNIQUE (company_id, source_system, source_entity_type, source_record_id)

inventory_cutover_avco_pools
  id, company_id, inventory_cutover_id, item_id
  policy_transition_id                        -- source method/scope/pool identity, by reference
  warehouse_id NULLABLE, batch_id NULLABLE    -- the resolved target pool's own scope dimension
  target_avco_pool_id
  opening_quantity, opening_value, base_opening_value, derived_average

inventory_cutover_policy_transitions
  id, company_id, inventory_cutover_id, item_id
  source_valuation_method, source_avco_scope NULLABLE, source_pool_identity NULLABLE
  target_valuation_method, target_avco_scope NULLABLE
  effective_at, revaluation_basis NULLABLE
```

Exact normalised layer/movement columns are reconciled during implementation. Source facts must
not be hidden solely in JSON when a field drives stock identity, valuation or reconciliation.
`inventory_cutover_avco_pools` references its policy transition by id instead of duplicating
source method/scope/pool columns; a `FIFO`/`SPECIFIC` target needs no equivalent table because its
posted `stock_layers` rows (real or synthetic, tagged by `source_type`) already are the evidence —
only a target AVCO pool's derived average is a computed number with no other single source row
proving it. One row exists per (policy transition, resolved target pool) — normally one, but more
than one when a `WAREHOUSE_ITEM` target splits one source pool identity across several locations'
own pools.

## Workflow and boundaries

1. Define source system, cutover timestamp, mode, target item valuation methods/scopes and control
   totals.
2. Load dependencies through the generic import staging engine: master data and mappings before
   open documents, movements, layers and opening state.
3. Validate references, tenant ownership, precision, UoM dimensions, currency/FX snapshots,
   serial uniqueness, layer totals and source-to-opening reconciliation.
4. Preview every rejection, warning, synthetic layer and negative-opening exception.
5. Approve using a dedicated cutover permission; commit idempotently through inventory-owned
   cutover Actions in a controlled maintenance window.
6. Reconcile quantity/value by item, exact location, target valuation pool and company total, then
   finalise. Finalisation freezes the batch
   and activates live [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)/Q14
   policy after `cutover_at`.

Per [ADR 0046](../decisions/0046-strict-staged-inventory-cutover-reconciliation.md), the source
export does not need to mirror this schema. Mapping/defaulting may produce the required staged
shape, but it must be explicit and reviewable: an authorised `DEFAULT_IF_MISSING` decision may put
a warehouse-only balance into an existing documented opening location, while the immutable staged
row retains the raw absence, mapped value, reason, approver and approval time. No transform may
invent quantity, value, warehouse allocation, item/UoM or valuation identity, batch or serial, and
V1 never splits one company/warehouse total across target locations.

The generic engine owns upload, mapping, staging and batch mechanics. Inventory owns validation
and state creation. Purchasing owns imported open POs. Finance later owns the balancing opening
journal. Cross-module writes use Actions; the importer does not write domain tables directly.

### Implemented foundation

The shared Imports module supports the `INVENTORY_CUTOVER` purpose with company-scoped saved
mappings, immutable raw/mapped staging rows, per-row validation errors and idempotent external
batch identity backed by a source checksum. Inventory registers the `OPENING_STATE` target schema;
using that schema under `STANDARD` is rejected. The shared target contract exposes validation
rules only, so staging/validation cannot commit `stock_moves`, `stock_layers` or `avco_pools`.

Inventory's orchestration layer is also live. One non-failed `InventoryCutover` per company fixes
the mode, timestamp, source system, target-policy snapshot and quantity/value controls. A validated
generic batch attaches idempotently as an `InventoryCutoverBatch`; attachment resolves item,
warehouse/location, UoM and currency references and creates immutable `InventorySourceFact` rows
that retain both raw and mapped payloads. Preview returns exact decimal-string totals, validation
reconciles them to the company controls, and approval must be independent of both creation and
validation. The dedicated permissions are `inventory.cutover.create`, `.stage`, `.validate`,
`.approve`, `.commit` and `.finalise`.

Finalisation refuses until every linked batch is `COMMITTED`. Approval alone can therefore never
claim that stock is live. It also independently reconciles committed cutover movements against
the immutable source facts by exact item/location, reconciles every target AVCO pool to its
recorded contributions and rechecks company quantity/value controls; a false `COMMITTED` flag is
not sufficient. PostgreSQL triggers enforce provenance scope, immutable definitions/source facts
and monotonic status transitions even for raw SQL.

Before approval, normalised facts must use the item's stock UoM and an active stock-holding
location, satisfy tracking independently from valuation method, contain no duplicate serials, and
reconcile quantity × unit cost and amount × FX rate where supplied. Missing optional metadata may
remain null. Missing batch/serial identity or material location allocation remains unresolved.
Preview includes exact location totals and every applied transformation decision.

`InventoryCutoverPolicyTransition` records one immutable source-method/scope/pool → target-method/
scope transition per distinct source identity, always effective at the cutover timestamp. Target
policy item codes are normalised, AVCO targets require exactly one supported scope, method changes
may retain structured revaluation evidence, and validation derives the ledger idempotently. This is
provenance only; it creates no live stock state.

The opening-state posting Action, `CommitInventoryCutoverOpeningState`, follows an approved
cutover and is the one that commits each `OPENING_STATE` batch. It applies each item's target
method/scope from its recorded policy transition (mutable pre-cutover, since this is the item's
first-ever stock movement — `valuation_method`/`avco_pool_scope` lock immutable the moment a
`stock_moves` row exists), then posts every non-zero source fact as a real
`stock_moves`/`stock_layers` receipt at its true `source_effective_at` where supplied, exactly like
an ordinary receipt, just sourced from cutover evidence (`source_type = INVENTORY_CUTOVER_OPENING`)
instead of a purchase:

- **AVCO** additionally converges the resolved target pool per fact and records one
  `InventoryCutoverAvcoPool` provenance row per (policy transition, target pool) — see the schema
  note above.
- **FIFO** posts a real layer for any fact carrying its own `source_effective_at` or a serial; facts
  with neither are summarised data and are merged into one layer per (location, batch) dated at
  `cutover_at`, tagged `source_type = INVENTORY_CUTOVER_SYNTHETIC` and anchored at the group's
  lowest fact id (real evidence, not an invented row).
- **SPECIFIC** always posts one real layer per fact; `StockIdentity::validateReceiptTracking`
  enforces the qty-1-plus-serial pairing the same way an ordinary serial receipt does.

The Action is idempotent (a batch already `COMMITTED` is a no-op) and requires an active company
base currency when a fact carries no explicit currency. A negative fact quantity requires an
**approved** `InventoryCutoverNegativeOpening` at its exact item, location and batch — ADR 0037's
cutover-shortage exception, detailed under "Dates, negative balances and corrections" below.
Without one it still refuses outright, unchanged from before that exception existed.

For `OPERATIONAL` and `FULL_HISTORY` modes, Purchasing registers a separate
`OPEN_PURCHASE_ORDER` import target. One staged row represents one still-open source PO line with
its remaining quantity and pinned commercial facts. After cutover approval,
`CommitInventoryCutoverOpenPurchaseOrders` groups a source order's rows, validates consistent
header and unique line identity, and uses Purchasing's normal create/issue boundary to materialise
an issued target PO for the remaining commitment only. It never turns that commitment into stock,
recreates predecessor receipts or invents a liability. Cutover/batch/row/source provenance is
immutable and the Action is idempotent. The dedicated permission is `purchasing.cutover.commit`.
Every attached open-PO batch must be committed through that owning Action before Inventory can
finalise the cutover.

## Valuation continuity

### Same method after cutover

- AVCO reconciles exact-location quantities, every resolved target pool's quantity/carrying value
  and company-wide control totals under
  [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md). Continuing the same supported
  scope imports one opening pool per source identity; the official average is derived, never
  accepted as an independent total that may disagree.
- FIFO prefers remaining layers with quantities, costs and original dates. Summarised data creates
  one synthetic opening layer dated at cutover and marked as such.
- SPECIFIC requires every on-hand serial/unit and individual carrying cost. Missing identity is a
  blocking error; the company must supply detail or choose another target method through an
  explicit cutover decision.

### Method change at cutover

Historical facts keep their source method. The target method starts from a reconciled opening
state at `cutover_at`: FIFO→AVCO derives one opening average; AVCO→FIFO creates a synthetic opening
layer unless reliable residual-layer detail exists; any move to SPECIFIC requires exact units.
The item stores only the post-cutover official method. Provenance records what changed and why.

AVCO scope can likewise continue or change at cutover. Source history retains its historical
scope/pool identity and official value. Target pools are established from approved quantity/value,
with an effective-dated source→target policy transition. Combining source pools aggregates their
approved totals. Splitting a broad source pool requires defensible quantity and carrying-value
allocation; if evidence cannot support it, reject the target scope or use an explicitly approved
revaluation/cutover basis. Never distribute value arbitrarily or rewrite history as though the
target scope always applied.

## Dates, negative balances and corrections

Pre-cutover source dates are evidence and may show negative stock or backdating that live policy
would reject. They do not pass through live posting and do not authorise new exceptions.

A negative opening is permitted only for AVCO/FIFO through the dedicated cutover workflow when
forcing it to zero would falsify the predecessor system's approved closing position. It requires
company, item, exact location and known compatible batch where applicable; SPECIFIC, missing
serial identity and invented location/batch provenance are blocked. Required evidence includes
official source quantity and carrying value, source system and record identity, a defensible cost
under [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md), reason, supporting
evidence, responsible owner, resolution plan and target date.

Dedicated cutover approval and control-total reconciliation are required. Ordinary APIs, normal
negative-stock permissions, direct generic-import writes and reusable-template bypasses cannot
invoke this authority; cutover and live override permissions do not imply one another. Each
accepted exception creates an `OPEN` cutover-shortage record visible from go-live and settled by
supported inbound/corrective movements under ADR 0037. It creates no fake FIFO layer. AVCO
negative-opening and freeze/recovery treatment applies independently to the affected configured
pool, while cutover also reconciles exact locations and company-wide totals. It grants no right
to deepen the shortage after go-live outside normal ADR 0037 policy.

The target resolution date defaults to 30 elapsed days after cutover. A different date requires
explicit approval with the reason and approver retained. An overdue shortage escalates to its
owner/management and remains prominent in reporting, but never triggers an automatic receipt,
write-off, reversal or other invented event. Reconciliation retains predecessor closing totals,
imported opening totals, approved migration adjustments, accepted negative exceptions and the
resulting ERP opening totals for later inventory/GL reconciliation.

Posted operational transactions cannot be imported with an effective time after `cutover_at`.
After finalisation, corrections use immutable normal stock adjustments/reversals in an allowed
period. Source facts and committed batches are never overwritten.

### Implemented

`InventoryCutoverNegativeOpening` is the exception record: `RequestInventoryCutoverNegativeOpening`
computes the evidenced quantity from the cutover's own immutable `InventorySourceFact` rows at the
exact (item, location, batch) — never accepted as caller-supplied input — and requires a positive
`provisional_unit_cost`, reason, structured supporting evidence, responsible owner and resolution
plan. SPECIFIC and serial-tracked items are blocked outright, at request time. The default target
date is `cutover_at` + 30 days; a different date requires an explicit `target_date_reason`.
`ApproveInventoryCutoverNegativeOpening` is the dedicated cutover approval — the requester cannot
approve their own request, DB-enforced as well as in the Action. Cutover authority is structurally
separate from live ADR 0037 override authority: neither table nor Action references
`NegativeStockOverrideRequest`, and approving here grants no live override permission.

Once approved, `CommitInventoryCutoverOpeningState` posts it as an ISSUE-shaped `stock_moves` row
plus a linked `NegativeStockShortage` (`status = OPEN_CUTOVER_SHORTAGE`) — `negative_stock_shortages`
now accepts either a live `override_request_id` or an `inventory_cutover_negative_opening_id`,
never both, DB-enforced. AVCO additionally seeds the resolved pool directly at the *approved*
`provisional_unit_cost` (`AvcoPoolManager::seedNegativeOpening()`) — the one legitimate way a pool's
`last_valid_avg_unit_cost` becomes nonzero with no prior positive receipt, something live posting
can never do. FIFO creates no layer. The posted value always derives from the approved provisional
cost, not the fact's own raw evidenced value, which remains on the exception purely as provenance.
A later ordinary receipt settles the shortage exactly like a live one — `ShortageSettler` doesn't
distinguish the two authorities.

## User interface

The inventory cutover HTTP surface is exposed per
[phase-2-purchasing-stock.md](../phases/phase-2-purchasing-stock.md#close-out): the header
(`InventoryCutover`) is create/read-only over HTTP (`POST`/`GET /api/v1/inventory-cutovers`,
`GET /api/v1/inventory-cutovers/{id}`) and every state change is a named action — `POST
.../batches` to attach a validated Imports batch, `.../validate`, `.../approve`, `.../commit`,
`.../finalise` on the header, plus `POST .../negative-openings` and `POST
/api/v1/inventory-cutover-negative-openings/{id}/approve` for ADR 0037's cutover-shortage
exception. `GET .../preview` is the one read-only, no-state-change action.

Per [ADR 0075](../decisions/0075-authorise-inventory-cutover-economic-record-reads.md),
list/detail/preview are economic migration reads. A caller needs an Inventory cutover duty or
`inventory.valuation.view`; unrelated authenticated users receive 403. Cutover actors see the
complete value evidence needed to prepare, reconcile or independently approve opening state;
valuation-only readers can inspect but cannot act. The SPA gates New, batch attachment and each
transition/negative-opening control by its own capability. A Purchasing open-PO cutover committer
alone does not gain the Inventory valuation record. Source facts are deliberately not includable
on the header; any later paginated read or export needs the same boundary.

`AttachInventoryCutoverBatch` requires an already-`Validated` `ImportBatch`, but the Imports
module owns batch creation and has no HTTP API or UI of its own yet
([data-import.md](../architecture/data-import.md)). The `/inventory-cutovers/:id` detail screen's
"attach batch" control therefore takes a raw numeric Import Batch ID rather than a picker — usable
once a batch exists (seeded outside HTTP for now). The same gap applies to the negative-opening
request form's "responsible owner": there is no user-picker endpoint yet either, so it also takes
a raw numeric User ID. Both are expected to become real pickers once their owning modules get an
HTTP surface, following the same incremental pattern as every other Phase 2 screen.

The React screens (`/inventory-cutovers` list, `/inventory-cutovers/new`,
`/inventory-cutovers/:id` detail) are the first UI with a multi-actor approval lifecycle — action
buttons are gated by the cutover's current `status`, and every mutation refetches the cutover
rather than patching local state, matching [physical-count.md](physical-count.md#user-interface).
An approved cutover with a pending `OPEN_PURCHASE_ORDER` batch shows a distinct **Commit open
purchase orders** action alongside **Commit opening state**; finalisation remains unavailable at
the domain boundary until every owning module has committed its batches.

## Completeness and degradation

Warehouse imports preserve source warehouse/location keys, hierarchy, names/codes, recognised
location types, barcodes and operational roles where the source supplies them. This supports
common SAP, Odoo and ERPNext shapes without copying any one product's schema. A company with only
one effective location per warehouse may remain `SIMPLE`; importing multiple locations or any
structured metadata requires `STRUCTURED`. Mapping may normalise source type names into the fixed
target vocabulary, but must retain source provenance and report unmapped values. It must never
silently flatten a hierarchy or invent capacity/address data.

Full history is accepted only to the level supported by the source export. Missing batch/serial,
layer, UoM, FX or value detail is reported explicitly. The implementation may downgrade the plan
to operational or opening-state migration; it must not invent precise history. Analytical replay
is available only where retained evidence supports it.

Acquisition-cost components are retained separately when supplied. They must not be collapsed
into product price, and open/unmatched source financial documents remain Phase 6 concerns.

## Related

- [Stock](stock.md)
- [Purchasing](purchasing.md)
- [Acquisition costing](acquisition-costing.md)
- [Data import](../architecture/data-import.md)
- [ADR 0036](../decisions/0036-controlled-inventory-cutover.md)
- [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)
- [ADR 0038](../decisions/0038-company-wide-per-item-avco-pool.md)
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md)
- [ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md)
- [ADR 0046](../decisions/0046-strict-staged-inventory-cutover-reconciliation.md)
