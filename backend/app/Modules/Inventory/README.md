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
- `StockTransferDetail` — immutable source/destination AVCO identities and provisional-state
  snapshot for transfer valuation and later repair propagation
- `StockTransferRepair` — immutable, at most one per transfer detail, recording the paired
  value reallocation once its source pool's provisional rate is later proven wrong
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
`PostInventoryMovement` is the sole quantity/valuation write boundary. Inbound receipts write a
traceability layer and update the resolved AVCO pool where applicable. Normal outbound issues
deplete exact-location/tracking availability, exclude unresolved or failed QA quantity, consume
FIFO layers or exact SPECIFIC serials, lock and cost AVCO at its resolved pool, and append the
Phase 6 valuation feed atomically. An explicitly supplied authorised override may cover only its
exact issue and no more than its snapshotted shortage. It consumes available stock first, derives
unsupported AVCO/FIFO cost from system history, and creates linked shortage state atomically.
Compatible inbound settles the oldest exact-identity shortage first, appends its valuation repair,
and exposes only excess quantity as ordinary available stock. Transfers reject negative physical
sources, preserve FIFO/SPECIFIC layers, remain neutral inside one AVCO pool and conserve exact base
value across pools. Whenever a settlement brings a non-positive pool back positive, every provisional
transfer taken out of it while frozen is corrected exactly once to the recovered rate — the exact
base amount reallocates from source to destination pool, cascading onward if that destination had
itself relayed the value through its own provisional transfer first.

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
