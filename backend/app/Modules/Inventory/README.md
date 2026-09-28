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
  foundations with SIMPLE/STRUCTURED company modes. A location code is unique only within its
  warehouse, so `Location` always eager-loads its warehouse and every resource embedding one carries
  it ([ADR 0059](../../../../docs/decisions/0059-name-a-location-as-warehouse-and-location.md))
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
- `NegativeStockShortage` — open physical shortage state linked one-to-one to its outbound
  movement and to exactly one authority: a live override request, or a cutover negative-opening
  exception (`OPEN_CUTOVER_SHORTAGE`) — retaining its provisional cost until settlement
- `NegativeStockSettlement` — immutable inbound allocation and provisional-to-actual valuation
  repair facts; multiple rows support partial settlement without rewriting posted history
- `OpeningStateImportTarget` — the validation-only `INVENTORY_CUTOVER` schema registered with the
  shared Imports module; generic staging cannot use it for ordinary imports or write stock state
- `InventoryCutover`, `InventoryCutoverBatch` and `InventorySourceFact` — the permissioned cutover
  workflow and immutable predecessor-system evidence; approval cannot finalise before
  `CommitInventoryCutoverOpeningState` commits every batch
- `InventoryCutoverPolicyTransition` — immutable effective-dated source method/scope/pool to target
  method/scope provenance, derived idempotently during cutover validation
- `InventoryCutoverAvcoPool` — immutable provenance for one target AVCO pool's opening state: which
  policy transition contributed, which resolved pool it landed in, the derived average
- `InventoryCutoverNegativeOpening` — ADR 0037's cutover-shortage exception: evidenced negative
  AVCO/FIFO opening balance, requested and approved by different actors, structurally separate
  from live ADR 0037 override authority
- `PhysicalCount`, `PhysicalCountLine` and `PhysicalCountLineEntry` — count/recount documents whose
  posted variance routes through `PostInventoryMovement`, the same boundary every other receipt/
  issue posts through; a recount replaces the line's working count, with every attempt retained
  immutably
- `RecalculationRequest`, `RecalculationImpactVersion`, `RecalculationApproval`,
  `RecalculationExecution` and `RecalculationRegenerationReview` — ADR 0040's authorised
  backdating workflow: one request accumulates append-only impact versions, each approved at an
  exact version with its own expiry, executed at most once
- `StockLayerConsumption` — permanent link from each layer allocation to the move it served, the
  FIFO/SPECIFIC replay foundation ADR 0040 depends on
- `QueryStockAvailability` (with `StockBalance`, `StockAvailabilitySnapshot`, `InventoryScope`) — the
  read-only, single-statement availability read model Estimating's inventory proposal consumes
  (ADR 0058): open layers per item/location/batch, QA hold attributed to the layer it sits in,
  location-type usability (`LocationType::holdsUsableStock()`), and defensible cost evidence. It
  locks and posts nothing; see [inventory-packages.md](../../../../docs/domain/inventory-packages.md#first-implementation-quantity_only-availability-and-allocation)

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
its own expiry and supports same-actor renewal after expiry. A *different*, eligible approver may
also claim a slot an expiry freed — authority-only rerouting, bounded by currently-unexpired
distinct approvers (`RecalculationImpactVersion::unexpiredApprovedActorIds()`) rather than a
separate action, since the economics haven't changed and no new impact version is needed. Three
post-approval material invalidations pause automatic regeneration.
`ResumeRecalculationRegeneration` records manual coordination and appends an unapproved
replacement without resetting history.
`ExecuteRecalculation` posts an approved AVCO, SPECIFIC or FIFO receipt — AVCO converges the pool
to the approved counterfactual; SPECIFIC and untouched-history FIFO are a plain layer insert.
When a FIFO receipt changes later direct issues, execution appends exact allocation reversals and
replacement consumptions, adjusts live layer balances and posts the residual value correction in
the same transaction. A changed later transfer is corrected the same way at every hop it reaches —
each transfer's destination layer-component(s) are superseded by freshly minted ones and the same
reallocation cascades into whatever that destination's own later issues or further transfers
already consumed from them, however deep the real chain goes. `ExecuteRecalculation` also posts an
approved backdated ISSUE or TRANSFER (`OutboundRecalculationExecutor`), mirroring
`IssuePoster`/`TransferPoster`'s posting shape at the true historical date, whether or not the
revalidated replay changes other history. FIFO/SPECIFIC use the same real-layer-id engine as the
receipt case — `LayerCounterfactualReplayer::planOutboundInsertion()` performs the new move's own
outbound draw against reconstructed as-of-`moved_at` state, then `FifoReceiptReallocator::
applyCorrections()` cascades whatever later real moves that draw displaces, however many hops
deep. AVCO converges every pool the connected component touched (not just source/destination) to
its own `counterfactual_scopes` snapshot and posts a residual per pool. The GL side never needs a
synthetic-id bridge for either method: the already-approved, already-revalidated
`valuation_adjustment` is posted as-is, split into the new move's own entry plus a residual plug.
The multi-owner correction package remains unbuilt. Supported
history and permission details live in
[stock-recalculation.md](../../../../docs/domain/stock-recalculation.md).

## Inventory cutover

`CreateInventoryCutover`, `AttachInventoryCutoverBatch`, `ValidateInventoryCutover`,
`ApproveInventoryCutover`, `CommitInventoryCutoverOpeningState` and `FinaliseInventoryCutover` own
the orchestration states. Each step uses its own `inventory.cutover.*` permission; approval is
independent from creation/validation. `PreviewInventoryCutover` exposes exact staged/control totals
without changing state. Attachment normalises a validated generic `OPENING_STATE` batch into
immutable source facts. `RecordInventoryCutoverPolicyTransitions` freezes each distinct source
valuation identity's target policy at `cutover_at` during validation, posting no quantity or value
itself.

`CommitInventoryCutoverOpeningState` is the method-specific opening-state Action finalisation
waits on: an approved cutover's `OpeningStateItemPoster` collaborator applies each item's recorded
target valuation policy (mutable pre-cutover — this is the item's first-ever `stock_moves` row),
then posts every non-zero source fact as a real receipt-shaped `stock_moves`/`stock_layers` row.
AVCO additionally converges the resolved target pool and records `InventoryCutoverAvcoPool`
provenance; FIFO posts a real dated layer per fact that carries its own age or serial, merging the
rest into one synthetic layer per location/batch dated at `cutover_at`; SPECIFIC always posts one
real layer per serial. Idempotent by batch status.

`InventoryCutoverReconciler` is deliberately separate from the poster. Before validation it
rejects untrustworthy normalised facts (wrong stock UoM, inactive/non-stock location, impossible
tracking identity, duplicate serial, or inconsistent quantity/value/FX arithmetic). At
finalisation it reconstructs the cutover's posted moves and proves exact item/location totals,
target AVCO pools and company controls independently of the batch status flags. Preview exposes
the location totals and every applied approved default from immutable staging evidence (ADR 0046).

A negative fact requires an **approved** `InventoryCutoverNegativeOpening` at its exact item,
location and batch — ADR 0037's cutover-shortage exception. `RequestInventoryCutoverNegativeOpening`
computes the evidenced quantity from the cutover's own immutable source facts (never free-form
input) and requires a positive defensible `provisional_unit_cost`, reason, supporting evidence,
responsible owner and resolution plan; `ApproveInventoryCutoverNegativeOpening` is the dedicated
second, independent actor ADR 0037 requires — neither Action grants or implies live ADR 0037
override authority. Once approved, `OpeningStateItemPoster::postNegativeOpening()` posts an
ISSUE-shaped `stock_moves` row and a linked `NegativeStockShortage` (`OPEN_CUTOVER_SHORTAGE`) at
the *approved* `provisional_unit_cost` — not the fact's own raw evidenced value, which stays on the
exception purely as provenance — settled afterwards by ordinary compatible inbound stock exactly
like a live shortage (`ShortageSettler`). SPECIFIC and serial-tracked items can never have one.
Creates no stock layer. A negative fact with no approved exception still refuses outright.

## Physical counts

`CreatePhysicalCount`/`AddPhysicalCountLine` build a draft document; each line snapshots
`book_qty` as `SUM(stock_layers.qty_remaining)` at its exact (item, location, batch/serial)
identity — valuation-method-agnostic, since every method already writes `stock_layers`.
`RecordPhysicalCountLine` handles both the first count and any recount: the line's `counted_qty`
always mirrors the latest call, while every attempt appends an immutable
`PhysicalCountLineEntry`. `PostPhysicalCount` requires an actor distinct from the document's
creator (mirroring `ApproveInventoryCutover`) and every line counted, then posts each non-zero
variance through `PostInventoryMovement` itself — not a bespoke poster, per
[physical-count.md](../../../../docs/domain/physical-count.md). A positive variance (found more)
always needs an explicit reviewer-supplied `found_unit_cost`, never inferred from AVCO's average
or any other source; a negative variance posts as an ordinary issue with no override authority,
relying on `IssuePoster`'s own insufficient-stock guard for the rare race where something else
moved the same identity after the count. `CancelPhysicalCount` abandons a draft with no postings.

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
