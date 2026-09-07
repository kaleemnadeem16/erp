# Inventory

Owns everything the company buys, holds, makes, consumes or sells, and the hierarchy that
groups it.

**Governed by:** [docs/domain/items.md](../../../../docs/domain/items.md) — the domain rules
this implements. [docs/domain/stock.md](../../../../docs/domain/stock.md) governs quantities,
movement and valuation, built in Phase 2.

## Owns

- `Category` — hierarchical grouping with GL-account defaults items inherit
  ([Phase 1](../../../../docs/phases/phase-1-master-data.md))
- `Item` — everything bought, held, made, consumed or sold; one table with a `type`
  discriminator (`RAW`/`SEMI_FINISHED`/`FINISHED`/`SERVICE`/`LABOUR`/`PLANT`) rather than
  separate product/service/resource tables ([Phase 1](../../../../docs/phases/phase-1-master-data.md))
- `Warehouse`, hierarchical `Location`, and warehouse location roles — Phase 2 stock custody
  foundations with SIMPLE/STRUCTURED company modes
- `StockMove`, `StockLayer`, `StockBatch`, `AvcoPool` and `StockValuationEntry` — the immutable
  movement, traceability and valuation-feed foundation; receipts, issues, shortage settlement and
  physical transfers are live for AVCO/FIFO/SPECIFIC
- `StockTransferDetail` — immutable source/destination AVCO pool identities, retained for
  transfer valuation and audit; a transfer's rate is final at post time and never repaired
  (ADR 0045)
- `InventoryPolicyChange` — immutable, tenant-safe audit history for governed inventory-policy
  changes; ADR 0037 negative-stock policy defaults and eligibility are configured on `Company`
- `NegativeStockOverrideRequest`, `NegativeStockOverrideApproval` and
  `NegativeStockOverrideReview` — immutable snapshots and independent decisions for ADR 0037's
  prior-approval and immediate-with-review workflows
- `NegativeStockShortage` — open physical shortage state linked one-to-one to the consuming
  override request and outbound movement, retaining its provisional cost until settlement
- `NegativeStockSettlement` — immutable inbound allocation and provisional-to-actual valuation
  repair facts; multiple rows support partial settlement without rewriting posted history

## Does not own

- GL posting — account columns on `Item`/`Category` are references only, Accounting owns what
  posts against them
- Purchasing and sales documents that reference items — Purchasing/Sales own those, this module
  owns only what they reference

## Key actions

`CreateCategory`/`UpdateCategory`/`ArchiveCategory` and
`CreateItem`/`UpdateItem`/`ArchiveItem` own the transactional write boundary used by HTTP and
future non-HTTP entry points. Model guards still enforce tenant, hierarchy and valuation
invariants regardless of which Action invokes them.

`CreateWarehouse` always creates its first location in the same transaction, so an active
warehouse cannot enter normal use without an exact physical location. Warehouse and location
updates/archiving use their corresponding Actions. `SIMPLE` mode keeps one plain location per
warehouse; `STRUCTURED` enables hierarchy, location metadata and operational role assignments.
Mode changes go through `UpdateInventorySettings`, which blocks unsafe downgrade.
The same Action governs negative-stock policy changes with an elevated permission, mandatory
reason, bounded review SLA and an immutable old/new audit record. Policy configuration does not
itself permit a negative movement; authorisation, posting and settlement are separate Actions.
`RequestNegativeStockOverride` snapshots the exact shortage and current authorisation policy;
`DecideNegativeStockOverride` and `ReviewNegativeStockOverride` enforce their distinct modes,
permissions and independent-user rules without mutating the request.
`PostInventoryMovement` is the sole quantity/valuation write boundary — a four-method facade
whose per-movement logic lives in the internal `Actions/Movements/` collaborators (receipt,
issue, transfer, pool state, layer allocation, settlement); nothing else may call those. Inbound
receipts write a traceability layer and update the resolved AVCO pool where
applicable — including a frozen pool: inbound at an open shortage's exact identity settles
first, and inbound elsewhere in the pool posts normally rather than being blocked. Normal
outbound issues deplete exact-location/tracking availability, exclude unresolved or failed QA
quantity, consume FIFO layers or exact SPECIFIC serials, lock and cost AVCO at its resolved
pool, and append the Phase 6 valuation feed atomically. A pool dragged non-positive (or below
the source location's physical stock) by a shortage elsewhere never blocks physically available
stock — the unsupported value posts at the pool's last valid average and the pool freezes until
recovery. An explicitly supplied authorised override may cover only its exact issue and no more
than its snapshotted shortage. It consumes available stock first, derives unsupported AVCO/FIFO
cost from system history, and creates linked shortage state atomically. Compatible inbound
settles the oldest exact-identity shortage first, appends its valuation repair (a settling
transfer repairs at the exact incoming layer costs it consumes), and exposes only excess
quantity as ordinary available stock. Transfers reject negative physical sources, preserve
FIFO/SPECIFIC layers and their original receipt age, remain neutral inside one AVCO pool and
conserve exact base value across pools, locking locations and pools in ascending-id order. A
transfer's exact source location is always fully physically backed, so it always prices at the
source pool's current rate — its frozen `last_valid_avg_unit_cost` when non-positive — and that
rate is final at post time, never later repaired (ADR 0045). `StockConservationPropertyTest`
fuzzes the whole engine and asserts feed/pool/layer/shortage agreement after every random
movement.

## Recalculation review

Inventory also owns backdated receipt/issue/transfer requests, immutable impact/approval records,
layer-consumption ledger and regeneration coordination reviews. `RequestStockRecalculation`
builds the preview; issues/transfers reconstruct connected history, derive outbound costs and
include both transfer sides and onward dependencies without posting stock. See
[stock-movement-replay.md](../../../../docs/domain/stock-movement-replay.md) for supported history.
`DecideRecalculationApproval` revalidates economics, dependencies and risk,
requiring dedicated higher-risk authority for a second approval; each required approval persists
its own expiry and supports same-actor renewal after expiry. Three post-approval material
invalidations pause automatic regeneration. `ResumeRecalculationRegeneration` records manual
coordination and appends an unapproved replacement without resetting history.
`ExecuteRecalculation` posts an approved AVCO, SPECIFIC or FIFO receipt — AVCO converges the pool
to the approved counterfactual; SPECIFIC and untouched-history FIFO are a plain layer insert.
When a FIFO receipt changes later direct issues, execution appends exact allocation reversals and
replacement consumptions, adjusts live layer balances and posts the residual value correction in
the same transaction. Transfer-connected FIFO execution remains blocked until immutable
destination-layer provenance can be corrected and cascaded too. Issue/transfer execution,
authority-only rerouting and the multi-owner correction package remain unbuilt. Supported history
and permission details live in
[stock-recalculation.md](../../../../docs/domain/stock-recalculation.md).

## Events

No events emitted yet.

## Non-obvious

`Category.parent_id` self-references `categories` and is guarded on save against two things a
plain foreign key can't stop on its own: pointing at another company's row, and reparenting a
category under its own descendant (which would turn GL-account resolution into an infinite
loop). See the `guardParent()` docblock on the model.

`Item.valuation_method` resolves differently from the GL account columns: it's set once, at
creation (an explicit override, or `Category::resolveDefaultValuationMethod()`), and stored
directly rather than re-resolved on every read — a later change to the category default must not
silently move an already-created item's official cost basis. See
[ADR 0032](../../../../docs/decisions/0032-per-item-valuation-method.md).

## Related

- [domain/items.md](../../../../docs/domain/items.md)
- [domain/stock.md](../../../../docs/domain/stock.md)
- [decisions/0032-per-item-valuation-method.md](../../../../docs/decisions/0032-per-item-valuation-method.md)
- [architecture/multi-tenancy.md](../../../../docs/architecture/multi-tenancy.md)
- [decisions/0041-progressive-inventory-location-capabilities.md](../../../../docs/decisions/0041-progressive-inventory-location-capabilities.md)
- [decisions/0045-avco-transfer-rate-final-not-provisional.md](../../../../docs/decisions/0045-avco-transfer-rate-final-not-provisional.md)
