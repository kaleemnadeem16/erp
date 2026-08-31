[← Roadmap](README.md)

# Phase 2 — Purchasing & stock

- **Status:** in progress — planning established 2026-08-31; implementation not started
- **Depends on:** [Phase 1 — Master data](phase-1-master-data.md) (items, supplier accounts,
  currencies, UoMs and chart-of-account references)
- **Blocked by:** the stock transaction engine cannot ship until
  [Q13](../OPEN-QUESTIONS.md#q13--negative-stock-policy) and
  [Q14](../OPEN-QUESTIONS.md#q14--backdated-stock-receipts) are resolved; sourcing and warehouse
  foundations are not blocked

## Goal

Make purchasing and inventory operational: a company can define approved suppliers per item,
raise and receive a purchase order, move traceable stock through warehouses and locations,
value every receipt and issue under the item's official AVCO/FIFO/SPECIFIC method, and reconcile
physical counts. Phase 2 produces a complete valuation feed for later GL posting; it does not
create journal entries itself.

## Scope

### In

- A purchasing domain document defining purchase-order/line schema, statuses, approval/issue
  boundaries, cancellation, partial receipt and UoM/currency snapshots before code is written
- `item_suppliers` per [ADR 0033](../decisions/0033-item-suppliers-relationship.md), including
  migration of `items.default_supplier_id` into priority-1 relationships and removal of that
  obsolete column
- A `Purchasing` module owning supplier sourcing, purchase orders and goods-receipt documents
- `warehouses` and `locations` in the existing `Inventory` module
- `stock_moves`, `stock_layers`, `item_average_costs` and `stock_valuation_entries` per
  [stock.md](../domain/stock.md), with retained batch/serial and source-document identity
- Transactional AVCO, FIFO and SPECIFIC valuation selected by `items.valuation_method`
- Goods receipt, internal transfer, stock issue/return, adjustment and physical-count workflows
- Locking `items.valuation_method` and `stock_uom_id` after the first stock move
- Backend APIs, validation, company scoping, module Actions, Pest coverage and regenerated Orval
  client for every Phase 2 resource exposed over HTTP

### Out

- Supplier invoices, three-way matching, payables and payment runs — Phase 6 Finance
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

1. Complete purchasing design and resolve Q13/Q14 as ADRs.
2. Build supplier sourcing and warehouse/location foundations.
3. Build purchase orders and goods-receipt documents with immutable issued-line snapshots.
4. Build the single stock-movement Action and valuation engines behind it.
5. Add transfers, returns, adjustments and physical counts through that same movement boundary.
6. Expose/finish APIs, regenerate the client, audit integrity and reconcile documentation.

## Tasks

### Design and module boundaries

- [ ] Write `docs/domain/purchasing.md` before the Purchasing module's first feature; define PO
      headers/lines, status transitions, issue/cancel rules, partial receipts, over-receipt policy,
      purchase-UoM conversion and transaction/base-currency snapshots
- [ ] Resolve Q13 and Q14 in accepted ADRs, remove them from `OPEN-QUESTIONS.md`, and update
      `stock.md` with the chosen policies
- [ ] Scaffold `app/Modules/Purchasing/README.md`, provider and routes; document the boundary:
      Purchasing owns sourcing and source documents, Inventory owns physical movement/valuation,
      and cross-module writes go through Actions rather than direct model mutation

### Supplier sourcing

- [ ] Build `item_suppliers` with tenant-safe item/supplier/UoM references, pair uniqueness,
      priority, active flag, supplier SKU, lead-time override and minimum-order quantity
- [ ] Enforce at most one active priority-1 supplier per item with a PostgreSQL partial unique
      index; define deterministic behaviour when no preferred supplier exists
- [ ] Migrate each populated `items.default_supplier_id` to an active priority-1 relationship,
      then remove the obsolete column, model relation, request/resource fields and generated types
- [ ] Add CRUD/query APIs and tests proving multiple approved suppliers, same-company ownership,
      preference uniqueness and lead-time fallback to `supplier_accounts.lead_time_days`

### Warehouse foundation

- [ ] Build company-scoped `warehouses` and child `locations`; require at least one location per
      usable warehouse and enforce one default warehouse per company with a partial unique index
- [ ] Add APIs and tests for warehouse/location lifecycle, composite uniqueness, tenant isolation
      and deletion restrictions once stock history exists

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
- [ ] Implement AVCO with row locking around `item_average_costs`; prove two concurrent receipts
      cannot calculate from the same stale average
- [ ] Implement FIFO oldest-layer consumption with partial-layer splits and row locking
- [ ] Implement SPECIFIC receipt/issue validation: exact serial identity is required and a serial
      cannot be received twice or issued more than once while on hand
- [ ] Enforce tracking rules (`NONE`/`BATCH`/`SERIAL`) independently from valuation method and
      preserve batch/serial data on moves and layers
- [ ] Write one `stock_valuation_entries` feed for every costed movement; store transaction and
      base amounts using the pinned FX rate, without creating GL journals
- [ ] Prevent normal edits to an item's `valuation_method` or `stock_uom_id` after its first move

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
- [ ] Simultaneous receipts for one AVCO item produce the mathematically correct final quantity
      and average cost under a real PostgreSQL concurrency test
- [ ] FIFO issues consume the oldest available layers, including an issue spanning multiple layers;
      SPECIFIC issues consume only the named on-hand serial
- [ ] Batch/serial requirements follow `items.tracking`, not `valuation_method`
- [ ] Transfers preserve total company quantity and value; issues/returns/adjustments change them
      only through recorded stock moves
- [ ] Negative-stock and backdated-receipt behaviour exactly matches the accepted Q13/Q14 ADRs
- [ ] An item's valuation method and stock UoM cannot be changed after its first stock move
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
- [items.md](../domain/items.md) · [parties.md](../domain/parties.md)
- [ADR 0032](../decisions/0032-per-item-valuation-method.md)
- [ADR 0033](../decisions/0033-item-suppliers-relationship.md)
- [multi-currency.md](../domain/multi-currency.md) ·
  [units-of-measure.md](../domain/units-of-measure.md)
- [database.md](../architecture/database.md) ·
  [multi-tenancy.md](../architecture/multi-tenancy.md)
