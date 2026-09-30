[← Domain index](README.md)

# Stock

Quantities on hand, their movement, and their valuation. Valuation is the part that matters most
— it feeds every formulation roll-up, every material issue, and COGS.

Valuation method is chosen **per item**, not one method for the whole company — AVCO, FIFO or
SPECIFIC, defaulted from the item's category — see [items.md](items.md#valuation-method) and
[ADR 0032](../decisions/0032-per-item-valuation-method.md) (supersedes the original single-method
[ADR 0007](../decisions/0007-avco-valuation.md)). The schema below is written so that whichever
method is official for an item, the history needed to replay it under a *different* method
analytically is still captured — see "Historical retention for replay" below.

Two companion docs own the control workflows this model plugs into:
[negative-stock.md](negative-stock.md) (ADR 0037 — policy, overrides, shortages, settlement,
provisional transfer repairs) and [stock-recalculation.md](stock-recalculation.md) (ADR 0040 —
backdated recalculation and corrections).

## Where it sits

The bottom of the costing chain — the source of ingredient cost for
[formulations.md](formulations.md). Built in [Phase 2](../phases/README.md).

## Schema

```
warehouses
  id, company_id, code, name, is_default, active

locations
  id, warehouse_id, parent_id NULLABLE, code, name
  type, barcode NULLABLE, sort_order, allows_stock, active
  -- hierarchy and operational metadata; a usable warehouse has at least one active location

warehouse_location_roles
  id, company_id, warehouse_id, location_id, role
  -- one named RECEIVING/QUARANTINE/DISPATCH/RETURNS/TRANSIT/PRODUCTION location per warehouse

stock_batches
  id, company_id, item_id, code         -- stable batch identity; never key pools by free text

stock_moves
  id, company_id
  item_id, from_location_id NULLABLE, to_location_id NULLABLE
  qty, uom_id
  moved_at
  source_type, source_id                -- polymorphic: GRN, issue, adjustment, transfer, return
  unit_cost, currency_id, fx_rate       -- cost at the time of the move
  batch_id, serial_no      NULLABLE     -- captured whenever items.tracking says so; see items.md
  -- NULL from_location = receipt from outside; NULL to_location = issue/consumption

stock_layers                            -- one per goods receipt; the FIFO consumption unit,
                                         -- and AVCO's traceability record
  id, company_id
  item_id, location_id
  qty_received, qty_remaining
  unit_cost, currency_id, fx_rate, base_unit_cost
  received_at
  source_type, source_id, source_line_no -- preserves multiple transfer cost components
  batch_id, serial_no      NULLABLE     -- as above

avco_pools                              -- explicit immutable pool identity; ADR 0039
  id, company_id, item_id
  scope                                 -- COMPANY_ITEM | WAREHOUSE_ITEM | COMPANY_ITEM_BATCH
  warehouse_id NULLABLE, batch_id NULLABLE
  qty_on_hand, inventory_value
  avg_unit_cost, base_avg_unit_cost, last_valid_avg_unit_cost
  status
  updated_at

stock_valuation_entries                 -- cost impact of each move; feeds the GL
  id, stock_move_id
  avco_pool_id NULLABLE                 -- immutable pool used at posting time
  pool_effect NULLABLE                  -- SOURCE_OUT | DESTINATION_IN | VALUATION_ADJUSTMENT | NULL
  qty, unit_cost, amount, base_amount
  account_id
```

The negative-stock and transfer tables (`inventory_policy_changes`, `negative_stock_override_*`,
`negative_stock_shortages`, `negative_stock_settlements`, `stock_transfer_details`) are
documented in [negative-stock.md](negative-stock.md#schema).

`stock_valuation_entries.qty`/`amount`/`base_amount` are a signed feed, unlike every quantity
column elsewhere in this schema: an inbound receipt posts positive (a debit), an outbound
movement posts the same magnitude negative (a credit). `unit_cost` stays a positive per-unit
magnitude regardless of direction; direction on `stock_moves` itself is expressed by `from`/`to`
location nullability, not by a signed `qty` (`stock_moves.qty` keeps its `> 0` constraint). This
lets "inventory account balance equals the sum of stock valuation" (see the landmine below) stay
a flat `SUM(base_amount)`, not direction-aware logic — set by
[ADR 0043](../decisions/0043-control-purchase-order-receipt-and-qa-posting.md)'s receipt
reversal (`App\Modules\Inventory\Actions\PostInventoryMovement::reverseReceipt()`).

## Behaviour

Purchase receipt and QA movements enter this ledger only through ADR 0043's atomic posting and
immutable disposition/reversal boundaries. Receipt reversal corrects an erroneous event; a later
Supplier Return is a distinct outbound source and does not rewrite receipt or PO history.

### Progressive warehouse structure

Per [ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md), every company
uses the same capable schema but chooses one cohesive mode. `SIMPLE` is the default: creating a
warehouse atomically creates its single stock-holding location, while additional locations,
hierarchy, metadata and operational-role assignment are unavailable. `STRUCTURED` enables
multiple locations, same-warehouse parent/child hierarchy, fixed location types, barcode,
display order, stock-holding semantics and named operational roles. `VIEW` locations cannot hold
stock. Barcodes are unique within a warehouse.

The API Actions enforce mode-dependent requirements; PostgreSQL still enforces fixed enums,
same-warehouse hierarchy/role references, uniqueness and `VIEW` semantics for every write path.
Downgrade to `SIMPLE` is blocked until extra locations and every structured attribute/role are
removed. Addresses/site identity, dimensions/capacity, putaway/picking rules, automation and a
future advanced-WMS mode remain deferred until a phase that consumes them.

The React application exposes warehouse list, detail, create and edit screens. Creation always
collects the initial location required by `CreateWarehouse`; editing changes only warehouse
fields and cannot accidentally rewrite that location. The detail screen includes the existing
locations read-only. Location hierarchy and operational-role management remain separate
structured-mode workflows.

### Valuation by method

Per-item, not one method for the whole company — [items.md](items.md#valuation-method),
[ADR 0032](../decisions/0032-per-item-valuation-method.md). Each item's `valuation_method` picks
which of these applies. Receipts, outbound issues, shortage settlement and transfers are
implemented for all three. Issues and transfers enforce exact-location/tracking availability and
QA eligibility. Connected historical replay is implemented too — see
[stock-movement-replay.md](stock-movement-replay.md).

**AVCO.** On receipt:

```
new_avg = ((qty_on_hand × avg_unit_cost) + (received_qty × received_unit_cost))
          ÷ (qty_on_hand + received_qty)
```

Issues are costed at the current average, materialised in `avco_pools`. The
recalculation must be **transactional and concurrency-safe** — two simultaneous receipts
computing from the same pool state produce a silently wrong cost. An issue or transfer that
consumes a positive pool's exact remaining quantity takes its exact remaining value, so rounding
can never strand a residual on an emptied pool.

[ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md) supports exactly
`COMPANY_ITEM` (the default), `WAREHOUSE_ITEM` and `COMPANY_ITEM_BATCH`; the last requires batch
tracking. `WAREHOUSE_ITEM_BATCH` is deferred. The company default resolves and persists an AVCO
item's `avco_pool_scope` before stock begins; it does not dynamically inherit later changes and is
locked after the first move. A later scope change requires a controlled valuation-policy
migration, never an ordinary edit.

Each valid pool shape is enforced with checks and partial unique indexes: no warehouse/batch key
for `COMPANY_ITEM`, a warehouse and no batch for `WAREHOUSE_ITEM`, and a normalised batch and no
warehouse for `COMPANY_ITEM_BATCH`. Physical location, tracking and pool identity remain separate;
batch tracking alone does not imply batch valuation.

Every posted valuation entry retains the resolved `avco_pool_id` used at posting time. Historical
entries never re-resolve scope from the item's current setting. Shortages, transfer effects,
recalculation requests, cutover records and effective-dated policy transitions likewise retain
the historical pool identities they used.

**FIFO.** No materialised running cost — `stock_layers.qty_remaining` *is* the state. An issue
consumes the oldest layer(s) with `qty_remaining > 0` first, costed at each consumed layer's
`unit_cost`, decrementing `qty_remaining` (partial-layer consumption when the issue exceeds one
layer's remaining quantity). Real layer-consumption logic, not a small variation on AVCO.

**SPECIFIC.** One `stock_layers` row per exact unit — the issue must name which specific
unit/serial it consumes, costed at exactly that unit's `unit_cost`. Only meaningful for
`SERIAL`-tracked items where identity was genuinely captured at receipt; see "Historical
retention for replay" below.

`stock_layers` is written on every receipt regardless of the item's official method — under
AVCO it exists purely for traceability, under FIFO/SPECIFIC it's the data the costing logic
actually consumes.

### Move types

| From | To | Meaning |
|---|---|---|
| NULL | location | Goods receipt (including a customer return, or a physical count finding more) |
| location | NULL | Issue to a work order, write-off, return to supplier, or a physical count finding less |
| location | location | Transfer |

Returns and physical-count variances aren't a third or fourth physical direction — a customer
return is shaped like a receipt, a return to supplier like an issue, and a count's variance is
shaped like whichever direction its sign implies. What distinguishes any of these from an
ordinary receipt/issue is `source_type`, not `from`/`to`. See
[physical-count.md](physical-count.md) for the count/recount workflow and why a found-stock
receipt always needs an explicit reviewer-supplied cost.

One table, one polymorphic source reference. Every quantity change in the system is a
`stock_move` — there is no other way to alter stock.

### Transfers

For AVCO, a same-pool transfer changes physical custody but not pool quantity, value or average.
A cross-pool transfer removes quantity/value at the source pool's official AVCO and introduces
exactly that base value into the destination pool, whose average is recalculated. It is a pool
inflow, not external acquisition cost. The paired valuation effects retain both pool identities,
rounding cannot create/destroy company value, and company total quantity/value remain unchanged.

The movement boundary implements ordinary transfers transactionally. It locks both locations —
and, for AVCO, both pools — in deterministic ascending-id order and then locks source layers, so
opposite-direction work cannot invert lock order and an empty physical source cannot be
overridden. FIFO creates one destination layer per source-cost component, each keeping its
source layer's original `received_at` — FIFO age is a property of the stock, not of its custody
path — and SPECIFIC preserves the exact serial. AVCO writes equal-and-opposite source and
destination valuation effects, priced at the source pool's current rate — its frozen
`last_valid_avg_unit_cost` when non-positive — which is final at post time and never later
repaired, per [ADR 0045](../decisions/0045-avco-transfer-rate-final-not-provisional.md); see
[negative-stock.md](negative-stock.md#transfers-from-a-frozen-pool). Immutable transfer detail
retains both pool identities for valuation and audit.

**Demo data.** `StockTransfersDemoSeeder` moves stock between Main Stock and Karachi Stock, one
dedicated item per way a transfer treats value: a same-pool AVCO transfer (custody changes, the pool's
quantity, value and average do not), a cross-pool AVCO transfer (value leaves at the source pool's
average and the destination re-averages, company value conserved) and a FIFO transfer (a destination
layer per source-cost component, each keeping its source layer's cost and receipt date). A serial
(SPECIFIC) transfer is seeded on its own item, `demo-xfer-laser`: three serial-numbered meters, one moved to
Karachi as that exact unit at its own cost. Seeing
them also showed the ledger reading "stock → stock" (both warehouses code their location `stock`);
locations are now named `warehouse / location` everywhere, per
[ADR 0059](../decisions/0059-name-a-location-as-warehouse-and-location.md).

### Historical retention for replay

The schema above retains enough to replay history under a *different* method than an item's
official one, analytically — e.g. an AVCO-official item gets a "what would FIFO margin have
looked like" report from the same `stock_layers`/`stock_moves` rows, without re-running receipts.

Rules, per [ADR 0032](../decisions/0032-per-item-valuation-method.md):

- Analytical replay reads retained history only. It never writes a GL entry, alters posted
  COGS, changes an inventory balance, or changes `items.valuation_method`.
- A `SPECIFIC`-basis replay is only possible where the exact serial/unit identity was actually
  captured at the time (`batch_no`/`serial_no` on the relevant `stock_layers` row). Estimating it
  after the fact when it wasn't captured is not acceptable — the report should say "not
  available," not guess.
- Not building the replay engine now — this is Phase 2/3 work, done when the costing phase
  actually needs it. What matters today is that no schema decision closes the door on it later.

This is a different concept from [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)'s
backdated-recalculation replay ([stock-recalculation.md](stock-recalculation.md)) — "what would a
different official method have shown" versus "what would this item's own posted history look like
if a late receipt were inserted at its true date." The latter turned out to need more than
`stock_layers`/`stock_moves` alone retain: `LayerAllocator::allocate()` decides which layer(s) a
given issue/transfer draws from, but that decision itself wasn't persisted, only its net effect
(`qty_remaining`). `stock_layer_consumptions` (added alongside the ADR 0040 work) closes that gap
for FIFO/SPECIFIC by permanently linking each allocation to the move it served — see
`LayerAllocator::recordConsumption()`. New moves also record their `movement_kind`; reversals
retain `reverses_move_id`. See [stock-movement-replay.md](stock-movement-replay.md#schema) for
the additive schema and its use in connected issue/transfer previews.

The ledger itself is database-immutable, not only model-guarded: `stock_moves` and
`stock_valuation_entries` reject every raw `UPDATE`/`DELETE`, and `stock_layers` rejects any
change other than consuming `qty_remaining` — the same one-to-one mirror of the Eloquent guard
that `goods_receipts`/`goods_receipt_lines` and issued `purchase_orders`/`purchase_order_lines`
now also have. A test that must fabricate pre-guard history (e.g. `LEGACY` moves) switches the
relevant trigger off for exactly that statement; application code never does.

Receipt reversal now records an immutable `stock_layer_consumptions` row with
`purpose = REVERSAL`; ordinary issues/transfers default to `ALLOCATION`. This is FIFO/SPECIFIC
only and preserves the exact reversed layer for historical replay. The ledger is protected
against SQL updates/deletes as well as model mutations. Approved FIFO receipt execution can also
append an `ALLOCATION_REVERSAL` referencing the exact allocation it releases, followed by a
replacement `ALLOCATION`; both retain their recalculation request. PostgreSQL constrains the
reference to the same move/layer and prevents cumulative over-reversal, while replay treats the
pair as a signed correction without rewriting either original fact. See
[stock-recalculation.md](stock-recalculation.md#replay-integrity-and-regeneration-review) for
history completeness checks and the additional review schema.

### GL posting

Phase 2 does not post to the GL. A `stock_move` writes a `stock_valuation_entries` row (this
schema, above) recording which account the move conceptually affects and the costed amount —
"this receipt is an Inventory-side movement of X" — using whichever method (AVCO/FIFO/SPECIFIC)
is official for the item. That row is a **feed**, not a journal entry: it has no debit/credit
pair, isn't balanced, and isn't immutable in the [invariant 3](../architecture/invariants.md#3-financial-documents-are-immutable)
sense. Turning the feed into an actual balanced, immutable `journal_entries`/`journal_lines` pair
— the real GL posting — is [general-ledger.md](general-ledger.md)'s job, in Phase 6.

### User interface

`stock_moves` has the stock engine's first HTTP surface and screen, per
[phase-2-purchasing-stock.md](../phases/phase-2-purchasing-stock.md#close-out): a cursor-paginated
`GET /api/v1/stock-moves` list (filterable by item/warehouse/location/batch/source type/date
range) and a `show` endpoint, both read-only — `PostInventoryMovement` remains the only write
path. `item`/`from_location`/`to_location`/`batch`/`valuation_entries` are all opt-in via
`include`, never loaded unconditionally. The React list and detail screens
(`/stock-moves`, `/stock-moves/:id`) are the first frontend use of cursor pagination
(`CursorDataTable`/`useCursorQueryState`), matching [api-conventions.md](../architecture/api-conventions.md#pagination)'s
"page-based elsewhere, cursor for large/frequently-appended lists" split — this ledger has no
`total`/page-jump UI, only Previous/Next against the API's opaque cursor.

`stock_layers` and `avco_pools` each have their own read endpoint too now, closing the last gap
on this close-out item: a cursor-paginated `GET /api/v1/stock-layers` (`filter[open]` for an
item's still-open FIFO/SPECIFIC layers — `stock_layers_open_fifo` exists specifically to serve
this efficiently) and a page-paginated `GET /api/v1/avco-pools` (an item's current converged
balance per pool scope). Both are deliberately backend-only, with no dedicated screen — this is
reference data, not a document workflow, unlike every other Phase 2 close-out area. `item`/
`location`/`batch` (layers) and `item`/`warehouse`/`batch` (pools) are opt-in via `include`, same
convention as `stock_moves`.

## Landmines

**Negative stock.** Live stock is blocked by default, with a company-configurable authorised
override for genuine outbound/consumption classes only. Availability is measured at the exact
source location and tracking identity; provisional AVCO/FIFO cost is system-derived, never
guessed or user-entered. The complete contract — policy, overrides, shortages, settlement,
provisional transfer repairs and the cutover exception — is
[negative-stock.md](negative-stock.md).

**Recalculating average under concurrency.** Two receipts processed simultaneously will both
read the pre-update average. Requires row locking, and a test that actually exercises it.

**Backdated receipts.** A receipt entered with an earlier date than existing issues means those
issues may have been costed from incomplete chronology. Blocked by default; a company may enable
authorised, impact-previewed forward recalculation with append-only corrections. The complete
contract is [stock-recalculation.md](stock-recalculation.md).

**Migration is not live posting.** Controlled pre-go-live source history and opening state use
[inventory-cutover.md](inventory-cutover.md), not ordinary movement endpoints. Historical
negative balances/backdating may be preserved as source facts without weakening the post-cutover
[ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)/ADR 0040 policy. The live
stock schema must retain cutover/source provenance and support AVCO, FIFO and SPECIFIC opening
states without silently recalculating official source history.

**A supplier invoice is never a stock source.** Even a future quick-purchase UI must create a
linked goods receipt and supplier invoice; only the receipt-side inventory transaction changes
quantity. Posted stock movements are not edited in place: corrections create explicit reversal,
return or adjustment movements after validating downstream dependencies. See
[ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md).

**Purchase UoM vs stock UoM.** Bought in 25 kg drums, consumed in grams. Convert on receipt and
store in the item's stock UoM; keep the purchase UoM on the purchase line for reference.

**Stock value drifting from the GL.** The inventory account balance and the sum of stock
valuation must agree. Build the reconciliation report early — discovering a discrepancy a year
in is far worse than catching it in week one. `StockConservationPropertyTest` already asserts
the feed, pool, layer and shortage states agree after arbitrary movement sequences.

**Changing an item's `valuation_method` after it has stock moves.** Locked once any `stock_move`
references the item — `Item`'s `updating` hook, covering `valuation_method`, `avco_pool_scope`,
`stock_uom_id` and `tracking` together, tested in `GoodsReceiptPostingTest`. See
[items.md](items.md#valuation-method) and
[ADR 0032](../decisions/0032-per-item-valuation-method.md).

## Related

- [material-resolution.md](material-resolution.md) — planning boundary before reservation and
  actual job consumption
- [inventory-packages.md](inventory-packages.md) — package balances/containers reconcile to this
  authoritative quantity and valuation ledger
- [items.md](items.md) — what is stocked, and where `valuation_method` is chosen
- [purchasing.md](purchasing.md) — which receipt documents originate purchase stock moves
- [acquisition-costing.md](acquisition-costing.md) — provisional cost and later adjustments
- [negative-stock.md](negative-stock.md) — ADR 0037 policy, overrides, shortages, settlement
- [stock-recalculation.md](stock-recalculation.md) — ADR 0040 backdating and corrections
- [physical-count.md](physical-count.md) — count/recount workflow and posted variance
- [formulations.md](formulations.md) — consumes stock cost
- [general-ledger.md](general-ledger.md) — where valuation posts
- [inventory-cutover.md](inventory-cutover.md) — controlled history and opening-state migration
- [phase-2-purchasing-stock.md](../phases/phase-2-purchasing-stock.md#close-out) — remaining
  stock-engine HTTP/UI close-out work
- [ADR 0032](../decisions/0032-per-item-valuation-method.md) — per-item valuation method
- [ADR 0036](../decisions/0036-controlled-inventory-cutover.md) — migration/live-policy boundary
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md) — constrained AVCO pool
  scopes and immutable pool identity
- [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md) — separates
  receipt, QA, supplier liability and acquisition cost
- [ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md) — simple and
  structured location modes
- [ADR 0043](../decisions/0043-control-purchase-order-receipt-and-qa-posting.md) — purchase
  receipt, QA disposition and reversal posting contract
- [ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md) — quantity-only,
  package-balance and container-tracked modes
- [ADR 0045](../decisions/0045-avco-transfer-rate-final-not-provisional.md) — a transfer's rate
  from a frozen pool is final, never repaired
