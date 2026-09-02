[← Roadmap](README.md)

# Phase 2 — Purchasing & stock

- **Status:** in progress — planning established 2026-08-31; implementation not started
- **Depends on:** [Phase 1 — Master data](phase-1-master-data.md) (items, supplier accounts,
  currencies, UoMs and chart-of-account references)
- **Blocked by:** nothing currently; negative stock is fixed by
  [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md) and backdated receipts by
  [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)

## Goal

Make purchasing and inventory operational: a company can define approved suppliers per item,
raise and receive a purchase order, move traceable stock through warehouses and locations,
value every receipt and issue under the item's official AVCO/FIFO/SPECIFIC method, and reconcile
physical counts. Phase 2 produces a complete valuation feed for later GL posting; it does not
create journal entries itself.

## Scope

### In

- The purchasing and acquisition-costing domain designs: purchase-order/receipt schema, QA and
  matching boundaries, partial receipt and UoM/currency/cost snapshots
- `item_suppliers` per [ADR 0033](../decisions/0033-item-suppliers-relationship.md), including
  migration of `items.default_supplier_id` into priority-1 relationships and removal of that
  obsolete column
- A `Purchasing` module owning supplier sourcing, purchase orders and goods-receipt documents
- `warehouses` and `locations` in the existing `Inventory` module
- `stock_moves`, `stock_layers`, explicit `avco_pools` and `stock_valuation_entries` per
  [stock.md](../domain/stock.md), with retained batch/serial and source-document identity
- Inventory cutover foundations per [ADR 0036](../decisions/0036-controlled-inventory-cutover.md):
  explicit cutover timestamp/mode, provenance-capable stock schema, opening AVCO/FIFO/SPECIFIC
  state Actions and reconciliation; generic mapping UI/engine delivery remains separately scoped
- Transactional AVCO, FIFO and SPECIFIC valuation selected by `items.valuation_method`
- Goods receipt, internal transfer, stock issue/return, adjustment and physical-count workflows
- Locking `items.valuation_method` and `stock_uom_id` after the first stock move
- Backend APIs, validation, company scoping, module Actions, Pest coverage and regenerated Orval
  client for every Phase 2 resource exposed over HTTP

### Out

- Supplier-invoice/payables tables, matching approval/payment holds and payment runs — Phase 6
  Finance; Phase 2 preserves the receipt/allocation contract those workflows require
- Balanced `journal_entries`/`journal_lines`; Phase 2 only writes valuation-feed rows — Phase 6
- Sales orders, customer returns initiated from sales, quotations and frontend CRUD — Phase 4
- Job/work-order material issue screens and job-cost entries — Phase 5
- Analytical replay under a valuation method other than the item's official method — retained
  history must support it, but the reporting engine is deferred to Phase 3 or later
- Vendor price lists and quantity-break pricing — not required by ADR 0033; purchase price is
  snapshotted on the PO line unless a later requirement produces a separate ADR
- Physical picking strategies such as FEFO/MANUAL — distinct from accounting valuation and not
  required in this phase
- Production deployment from Phase 0 — remains independently deferred until explicitly picked up

## Sequencing

The order matters because later work needs stable document and stock boundaries:

1. Resolve Q14 as an ADR; Q13 is resolved by
   [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md), and the purchasing foundation is recorded in
   [purchasing.md](../domain/purchasing.md), [acquisition-costing.md](../domain/acquisition-costing.md)
   and [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md).
2. Build supplier sourcing and warehouse/location foundations.
3. Build purchase orders and goods-receipt documents with immutable issued-line snapshots.
4. Build the single stock-movement Action and valuation engines behind it.
5. Add transfers, returns, adjustments and physical counts through that same movement boundary.
6. Expose/finish APIs, regenerate the client, audit integrity and reconcile documentation.

## Tasks

### Design and module boundaries

- [x] Write the purchasing/acquisition-costing domain design before the Purchasing module's first
      feature; define document boundaries, partial receipts, QA/matching integration, provisional
      costing, purchase-UoM conversion and transaction/base-currency snapshots
- [x] Resolve Q14 in an accepted ADR and remove it from `OPEN-QUESTIONS.md`; Q13 is resolved by
      ADR 0037 and both policies are reconciled into `stock.md`
- [ ] Set over-receipt tolerance/approval behaviour before goods-receipt implementation; keep it
      aligned with the later Phase 6 matching-tolerance policy
- [x] Scaffold `app/Modules/Purchasing/README.md`, provider and routes; document the boundary:
      Purchasing owns sourcing and source documents, Inventory owns physical movement/valuation,
      and cross-module writes go through Actions rather than direct model mutation

### Supplier sourcing

- [x] Build `item_suppliers` with tenant-safe item/supplier/UoM references, pair uniqueness,
      priority, active flag, supplier SKU, lead-time override and minimum-order quantity
- [x] Enforce at most one active priority-1 supplier per item with a PostgreSQL partial unique
      index; define deterministic behaviour when no preferred supplier exists
- [x] Migrate each populated `items.default_supplier_id` to an active priority-1 relationship,
      then remove the obsolete column, model relation, request/resource fields and generated types
- [x] Add CRUD/query APIs and tests proving multiple approved suppliers, same-company ownership,
      preference uniqueness and lead-time fallback to `supplier_accounts.lead_time_days`

### Warehouse foundation

- [x] Build company-scoped `warehouses` and child `locations`; require at least one location per
      usable warehouse and enforce one default warehouse per company with a partial unique index
- [x] Add APIs and tests for warehouse/location lifecycle, composite uniqueness, tenant isolation
      and deletion restrictions once stock history exists
- [x] Add progressive `SIMPLE`/`STRUCTURED` location capability modes, same-warehouse hierarchy,
      fixed types/barcodes/stock semantics, operational roles and guarded downgrade per ADR 0041

### Purchase orders and receipts

- [ ] Build purchase orders and lines from `purchasing.md`, using gapless numbering through the
      existing `NumberingScheme`, supplier-account/site references and explicit currency/FX data
- [ ] Snapshot supplier item code, description, ordered quantity, purchase UoM, converted stock
      quantity, unit price, currency and pinned FX rate when a PO is issued; never reconstruct an
      issued PO from current supplier/item/rate master data
- [ ] Implement transactional issue, cancel and partial/full receipt Actions with concurrency-safe
      outstanding quantities and idempotency protection against duplicate receipt submission
- [ ] Build goods-receipt headers/lines that retain the PO-line link, received purchase quantity,
      converted stock quantity, location, batch/serial identity and actual receipt timestamp

### Stock movement and valuation

- [ ] Build the stock tables in `stock.md` with company-aware foreign keys, decimal quantities and
      costs, immutable source identity, and indexes for item/location/date and open FIFO layers
- [ ] Implement one transactional inventory movement boundary used by receipts, issues, returns,
      transfers and adjustments; no other code path may alter quantity or valuation state
- [ ] Resolve and persist each AVCO item's `COMPANY_ITEM`, `WAREHOUSE_ITEM` or
      `COMPANY_ITEM_BATCH` pool scope (default `COMPANY_ITEM`), enforce tracking compatibility and
      lock it after the first movement; defer `WAREHOUSE_ITEM_BATCH`, per ADR 0039
- [ ] Implement AVCO with row locking around resolved `avco_pools`; prove two concurrent receipts
      into one pool cannot calculate from stale state while independent pools remain independent
- [ ] Implement same-pool transfer neutrality and cross-pool transfer value conservation,
      destination-average recalculation and immutable source/destination pool identities
- [ ] Implement provisional cross-pool transfer repair propagation without permitting a negative
      physical source or creating/destroying company inventory value
- [ ] Implement FIFO oldest-layer consumption with partial-layer splits and row locking
- [ ] Implement SPECIFIC receipt/issue validation: exact serial identity is required and a serial
      cannot be received twice or issued more than once while on hand
- [ ] Enforce tracking rules (`NONE`/`BATCH`/`SERIAL`) independently from valuation method and
      preserve batch/serial data on moves and layers
- [ ] Write one `stock_valuation_entries` feed for every costed movement; store transaction and
      base amounts using the pinned FX rate, without creating GL journals
- [ ] Prevent normal edits to an item's `valuation_method`, `avco_pool_scope` or `stock_uom_id`
      after its first move; scope changes use a separate effective-dated valuation cutover
- [ ] Implement company backdating horizon modes (`CURRENT_AND_PREVIOUS_PERIOD` default,
      `PERIOD_LOCK_ONLY`, `POSTING_FLOOR` with fixed/rolling subtype), audited policy changes and
      most-restrictive-boundary evaluation; rolling mode requires a valid company IANA accounting
      timezone and trusted server time, never user-controlled clocks
- [ ] Snapshot request-time rolling-floor context, preserve eligibility across normal clock
      progression, revalidate material policy/boundary changes at execution and reject per-request
      horizon extensions
- [ ] Build recalculation requests with immutable append-only impact versions, exact-version
      approvals, state-based staleness and automatic replacement-version generation per
      [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)
- [ ] Enforce bounded company-configurable review SLAs (72-hour default), overdue escalation that
      preserves requester/approver separation, and hard approved-execution TTLs (24-hour default)
      using persisted deadlines and trusted server time
- [ ] Implement exact semantic impact comparison, authority-only approval rerouting, per-version
      normal/high-risk classification and mandatory second independent approval for hard high-risk
      consequences per
      [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)
- [ ] Audit every consequential company-policy change with elevated permission, mandatory reason,
      actor, old/new values and timestamp without rewriting snapshotted request context/deadlines
- [ ] Serialise overlapping recalculation execution with deterministic locks over the complete
      method-specific replay closure, count only post-approval material invalidations toward the
      regeneration cap, and escalate repeated cap hits without resetting the counter
- [ ] Build idempotent append-only correction packages preserving exact approved deltas, historical
      FX bases, deterministic downstream allocation, explicit pending owners and zero-residual
      reconciliation per
      [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)

### Inventory cutover foundation

- [ ] Extend the generic ADR 0027 import contract for `INVENTORY_CUTOVER`; do not build a parallel
      uploader/mapper or permit the generic importer to write stock domain tables directly
- [ ] Build company cutover records with one explicit timestamp/mode, immutable source provenance,
      dedicated permissions, staging/preview/approval/finalisation and idempotent batch identity
- [ ] Implement reconciled opening-state Actions: target AVCO pools, FIFO residual or labelled
      synthetic layers, and per-serial SPECIFIC costs; preserve source method/scope/pool identity
      and effective-dated transitions across a method or AVCO-scope change
- [ ] Validate quantity/value by exact location, target AVCO pool and company total, plus UoM/FX,
      batch/serial identity and layer totals; reject unsupported broad→narrow pool allocation or
      require an explicitly approved revaluation basis rather than inventing detail
- [ ] Keep historical negative/backdated source facts separate from live posting, and test that no
      ordinary API, mapping template or post-cutover batch can invoke migration-only exceptions
- [ ] Leave open-PO materialisation to Purchasing and the balancing opening journal to Phase 6,
      connected through immutable cutover/source references

### Counts, API and audit

- [ ] Build physical-count documents with snapshot lines, count/recount workflow and posted
      variance adjustments routed through the same movement Action
- [ ] Add validated list filters, sorting, includes and pagination for all Phase 2 APIs; return
      domain conflicts as stable 409/422 responses rather than leaking database exceptions
- [ ] Regenerate and commit the Orval client; verify all frontend type-checks and production build
- [ ] Run a Phase 2 integrity audit covering raw-write tenant protection, concurrency, rollback,
      duplicate submission, precision, tracking and historical-retention guarantees

Tick these in the same commit as the work.

## Acceptance criteria

- [ ] One item can have several approved suppliers but no more than one active preferred supplier;
      supplier-specific SKU/UoM/MOQ/lead time round-trip through the API
- [ ] An issued PO remains historically unchanged after item, supplier, exchange-rate or UoM
      master data changes
- [ ] A partial receipt updates outstanding PO quantity exactly once and creates the matching
      stock move/layer/valuation feed atomically
- [ ] Simultaneous receipts into one AVCO pool produce the mathematically correct quantity/value
      and average under a real PostgreSQL concurrency test; supported scopes resolve uniquely and
      remain tenant-isolated
- [ ] FIFO issues consume the oldest available layers, including an issue spanning multiple layers;
      SPECIFIC issues consume only the named on-hand serial
- [ ] Batch/serial requirements follow `items.tracking`, not `valuation_method`
- [ ] Same-pool transfers leave pool value/average unchanged; cross-pool transfers carry source
      AVCO into the destination, recalculate destination AVCO and preserve total company quantity
      and value, including provisional repair propagation
- [ ] Negative-stock and backdated-receipt behaviour exactly matches the accepted Q13/Q14 ADRs
- [ ] A finalised inventory cutover reconciles exact locations, each target AVCO pool and company
      totals; continues or explicitly converts source valuation scope without rewriting history;
      and establishes valid AVCO/FIFO/SPECIFIC state without weakening Q13/Q14 controls
- [ ] An item's valuation method, AVCO pool scope and stock UoM cannot be changed normally after
      its first move; historical valuation entries retain their original immutable pool identity
- [ ] A negative location does not freeze a positive wider AVCO pool; each configured pool freezes
      and recovers independently when its own quantity becomes non-positive
- [ ] Authorised backdated replay follows affected configured AVCO pools and real cross-pool
      dependencies while excluding unrelated pools
- [ ] Horizon eligibility uses the configured period/floor mode, snapshots trusted rolling context,
      cannot cross stricter cutover/period/policy boundaries and routes outside-horizon differences
      to the explicit correction workflow without falsifying physical dates
- [ ] A backdated correction package is idempotent, preserves approved historical FX/deltas,
      allocates its economic lines without changing physical quantity, reconciles GL representation
      without double counting, reconciles Tax/integration/ancillary obligations on their own bases,
      and cannot finish with an unexplained residual or unresolved required obligation
- [ ] Retrying after a correction commit/response timeout resolves the unique existing package and
      never duplicates value; an unlinked manual journal cannot resolve its Accounting obligation
- [ ] A failure by any synchronous implemented participant rolls the declared internal transaction
      back in full; only deliberately deferred/asynchronous/external obligations may remain pending
- [ ] A posted physical count creates auditable variance moves and cannot be posted twice
- [ ] Cross-company access and raw cross-company foreign-key writes fail for every new table
- [ ] Full Pest/Pint/Larastan/frontend/codegen pipeline passes on PostgreSQL 16

## Docs to update on completion

- [ ] [STATUS.md](../STATUS.md) — mark Phase 2 complete and move Next to Phase 3
- [ ] `docs/domain/purchasing.md` — reconcile the implemented document/API contract
- [ ] [stock.md](../domain/stock.md) — reconcile tables, valuation and movement policies
- [ ] [items.md](../domain/items.md) and [parties.md](../domain/parties.md) — remove the Phase 1
      `default_supplier_id` bridge and record supplier-catalog/stock-lock behaviour
- [ ] Inventory and Purchasing module READMEs
- [ ] New ADRs for every Phase 2 decision, especially Q13/Q14

## Related

- [stock.md](../domain/stock.md)
- [purchasing.md](../domain/purchasing.md) ·
  [acquisition-costing.md](../domain/acquisition-costing.md)
- [items.md](../domain/items.md) · [parties.md](../domain/parties.md)
- [ADR 0032](../decisions/0032-per-item-valuation-method.md)
- [ADR 0033](../decisions/0033-item-suppliers-relationship.md)
- [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md)
- [ADR 0036](../decisions/0036-controlled-inventory-cutover.md) ·
  [inventory-cutover.md](../domain/inventory-cutover.md)
- [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md)
- [multi-currency.md](../domain/multi-currency.md) ·
  [units-of-measure.md](../domain/units-of-measure.md)
- [database.md](../architecture/database.md) ·
  [multi-tenancy.md](../architecture/multi-tenancy.md)
