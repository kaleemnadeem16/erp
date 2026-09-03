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
  source_type, source_id
  batch_id, serial_no      NULLABLE     -- as above

avco_pools                              -- explicit immutable pool identity; ADR 0039
  id, company_id, item_id
  scope                                 -- COMPANY_ITEM | WAREHOUSE_ITEM | COMPANY_ITEM_BATCH
  warehouse_id NULLABLE, batch_id NULLABLE
  qty_on_hand, inventory_value
  avg_unit_cost, base_avg_unit_cost, last_valid_avg_unit_cost
  status
  updated_at

inventory_policy_changes                -- immutable audit of governed settings
  id, company_id, policy
  old_values, new_values
  reason, changed_by, changed_at

negative_stock_override_requests        -- immutable shortage + policy snapshot
  id, company_id, item_id, location_id, batch_id NULLABLE
  movement_class, requested_qty, available_qty, shortage_qty
  source_type, source_id, reason_code, explanation
  authorisation_mode, allow_self_review, requested_by, requested_at, review_due_at NULLABLE

negative_stock_override_approvals       -- PRIOR_APPROVAL decision; one per request
  id, company_id, request_id, decision, notes, decided_by, decided_at

negative_stock_override_reviews         -- IMMEDIATE_WITH_REVIEW outcome; one per request
  id, company_id, request_id, outcome, notes, reviewed_by, reviewed_at

negative_stock_shortages
  id, company_id, item_id, location_id, batch_id NULLABLE, avco_pool_id NULLABLE
  qty_open, provisional_unit_cost, status
  source_move_id, settled_at NULLABLE

stock_valuation_entries                 -- cost impact of each move; feeds the GL
  id, stock_move_id
  avco_pool_id NULLABLE                 -- immutable pool used at posting time
  pool_effect NULLABLE                  -- SOURCE_OUT | DESTINATION_IN | NULL
  qty, unit_cost, amount, base_amount
  account_id
```

`stock_valuation_entries.qty`/`amount`/`base_amount` are a signed feed, unlike every quantity
column elsewhere in this schema: an inbound receipt posts positive (a debit), an outbound
movement — reversal today, issues/transfers once built — posts the same magnitude negative (a
credit). `unit_cost` stays a positive per-unit magnitude regardless of direction; direction on
`stock_moves` itself is expressed by `from`/`to` location nullability, not by a signed `qty`
(`stock_moves.qty` keeps its `> 0` constraint). This lets "inventory account balance equals the
sum of stock valuation" (see the landmine below) stay a flat `SUM(base_amount)`, not
direction-aware logic — set by [ADR 0043](../decisions/0043-control-purchase-order-receipt-and-qa-posting.md)'s
receipt reversal (`App\Modules\Inventory\Actions\PostInventoryMovement::reverseReceipt()`), binding
for whoever builds the general issue/transfer engine next.

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

### Valuation by method

Per-item, not one method for the whole company — [items.md](items.md#valuation-method),
[ADR 0032](../decisions/0032-per-item-valuation-method.md). Each item's `valuation_method` picks
which of these applies. The shared receipt-side movement path and normal non-negative outbound
issues are implemented for all three. Issues enforce exact-location/tracking availability and QA
eligibility; transfers, authorised negative-stock settlement and replay remain later Phase 2
slices.

**AVCO.** On receipt:

```
new_avg = ((qty_on_hand × avg_unit_cost) + (received_qty × received_unit_cost))
          ÷ (qty_on_hand + received_qty)
```

Issues are costed at the current average, materialised in `avco_pools`. The
recalculation must be **transactional and concurrency-safe** — two simultaneous receipts
computing from the same pool state produce a silently wrong cost.

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
| NULL | location | Goods receipt (including a customer return) |
| location | NULL | Issue to a work order, write-off, or a return to supplier |
| location | location | Transfer |
| location | location | Adjustment after a physical count |

Returns aren't a third physical direction — a customer return is shaped like a receipt, a
return to supplier like an issue. What distinguishes a return from an ordinary receipt/issue is
`source_type`, not `from`/`to`.

One table, one polymorphic source reference. Every quantity change in the system is a
`stock_move` — there is no other way to alter stock.

For AVCO, a same-pool transfer changes physical custody but not pool quantity, value or average.
A cross-pool transfer removes quantity/value at the source pool's official AVCO and introduces
exactly that base value into the destination pool, whose average is recalculated. It is a pool
inflow, not external acquisition cost. The paired valuation effects retain both pool identities,
rounding cannot create/destroy company value, and company total quantity/value remain unchanged.

If the physical source location has sufficient quantity but its wider source pool is non-positive
(possible for `WAREHOUSE_ITEM` when another location in that warehouse pool is negative), the last
defensible provisional pool AVCO is carried to the destination. Both effects remain provisional
and linked to the same source recovery/repair chain; later repair appends paired adjustments and
propagates through affected destination dependencies while preserving company-value neutrality.
This is a non-positive valuation-pool case and never permits a negative physical-source transfer.

### Negative stock contract

[ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md) is the complete policy.
Availability is exact-location/tracking-specific and independent from valuation scope. Live stock
blocks by default; configured, permissioned overrides apply only to genuine outbound/consumption
classes. Internal transfers, missing serial/SPECIFIC identity, negative physical-count results and
other hard constraints are never overrideable. Corrections/reversals use a separate workflow.

Overrides use prior approval by default or immediate posting with bounded review. Every use is
explicit, reasoned, immutably audited and tracked separately by review outcome and shortage state
(`OPEN`, `PARTIALLY_SETTLED`, `SETTLED`). Neither API nor UI applies one silently.

The company policy foundation is live: `BLOCK` is the default; the authorisation mode defaults to
`PRIOR_APPROVAL`; review SLA defaults to 24 hours and is bounded to 1–168 hours; independent
review is the default; sales/despatch and material consumption are eligible candidates while
supplier returns and scrap/write-off start disabled. Changing any of these fields requires the
elevated `inventory.negative-stock-policy.manage` permission, a reason, and an immutable tenant-safe
old/new audit record. This configuration does not itself authorise a movement—the request,
approval/immediate-review, shortage and settlement workflows remain explicit subsequent steps.

Override request, approval and review records are live as immutable, tenant-safe facts. Requests
snapshot exact location/tracking shortage, source, controlled reason, written explanation and the
then-current authorisation mode. Prior approval requires a different user with
`inventory.negative-stock.approve`; immediate mode requires override permission up front and
persists its review deadline, while review requires `inventory.negative-stock.review` and is
independent unless the snapshotted company policy explicitly permits self-review. These records do
not yet post inventory; the movement boundary will consume them only after transactional
availability revalidation.

Unsupported AVCO/FIFO quantity uses a defensible system-derived provisional cost. AVCO freezes
and recovers independently at its resolved configured pool; a negative location does not freeze a
positive wider pool. FIFO records a shortage, never a fake receipt layer. Compatible inbound
settles oldest shortages first and appends linked adjustments without mutating original history.
AVCO provisional cost uses the pool's last valid official average, then a specifically linked
trusted expected inbound/acquisition cost, then exactly one unambiguous eligible expected inbound;
multiple plausible sources or no defensible source block, and users never enter an estimate.
FIFO cost prefers a specifically linked PO/expected receipt/inbound; without a link, exactly one
unambiguous eligible expected inbound may be used. Multiple plausible sources or no defensible
source block—the system never chooses an inbound merely because it is oldest, cheapest or nearest.

Cutover-only AVCO/FIFO negative openings require exact identity, defensible cost, approval,
resolution ownership/date and per-location/pool/company reconciliation. Acceptance immediately
creates an `OPEN_CUTOVER_SHORTAGE` linked to official opening state and governed by normal
compatible settlement and append-only repair; historical source negatives remain separate
migration evidence. Its target defaults to cutover plus 30 elapsed days; an alternative requires
explicit approval/reason, while overdue status escalates and reports but never creates an automatic
inventory adjustment. The opening grants no live authority and SPECIFIC/serial negatives remain
blocked. See
[inventory-cutover.md](inventory-cutover.md#dates-negative-balances-and-corrections).

A supplier invoice is never a stock source. Even a future quick-purchase UI must create a linked
goods receipt and supplier invoice; only the receipt-side inventory transaction changes quantity.
Posted stock movements are not edited in place: corrections create explicit reversal, return or
adjustment movements after validating downstream dependencies. See
[ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md).

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

### GL posting

Phase 2 does not post to the GL. A `stock_move` writes a `stock_valuation_entries` row (this
schema, above) recording which account the move conceptually affects and the costed amount —
"this receipt is an Inventory-side movement of X" — using whichever method (AVCO/FIFO/SPECIFIC)
is official for the item. That row is a **feed**, not a journal entry: it has no debit/credit
pair, isn't balanced, and isn't immutable in the [invariant 3](../architecture/invariants.md#3-financial-documents-are-immutable)
sense. Turning the feed into an actual balanced, immutable `journal_entries`/`journal_lines` pair
— the real GL posting — is [general-ledger.md](general-ledger.md)'s job, in Phase 6.

## Landmines

**Negative stock.** Live stock is blocked by default, with a company-configurable authorised
override. Availability is measured at the exact source location and tracking identity; missing
serial identity is a hard block. Override eligibility is a constrained company configuration for
genuine outbound/consumption classes; transfers are permanently excluded and corrections use a
separate workflow. Transaction controls use prior approval by default or immediate posting with
bounded review. AVCO/FIFO uses a defensible, system-derived provisional cost and later linked
adjustments; it never averages through a non-positive AVCO pool, fabricates FIFO receipt layers or
guesses among ambiguous sources. A tightly controlled AVCO/FIFO cutover opening exception is
permitted without weakening post-go-live policy.

**Recalculating average under concurrency.** Two receipts processed simultaneously will both
read the pre-update average. Requires row locking, and a test that actually exercises it.

**Backdated receipts.** A receipt entered with an earlier date than existing issues means those
issues may have been costed from incomplete chronology. Live operation defaults to blocking a
conflicting valuation backdate while retaining separate truthful physical/source, ERP-entry,
valuation-effective and future accounting dates. A company may enable authorised forward
recalculation, but entry never triggers ordinary automatic replay: it creates an impact-previewed
request requiring authorised review. Independent approval is the default; an elevated audited
policy may allow explicitly identified self-review for a small company.

Approved replay is deterministic and transactional, preserves original movements/valuation
entries and appends linked adjustments. Immediately before execution it revalidates current
policy and authority, affected history, valuation state, period state, shortage dependencies and
hard integrity rules; stale approval cannot execute. Closed financial history is never rewritten
and migration authority remains isolated. Conflict/replay scope is method-specific: affected
configured pools and real cross-pool dependencies for AVCO, the affected layer/downstream movement
graph for FIFO, the exact serial chain for SPECIFIC and the compatible settlement/adjustment chain
for negative shortages. Unrelated pools are excluded. Preview may overestimate conservatively;
actual adjustments are limited to changed economic results, replay runs to deterministic
convergence and execution revalidates the dependency scope.

[ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)
separates review delay, changed facts and ageing authority. Pending review has a bounded
company-configurable SLA (72 elapsed hours by default); breach marks `OVERDUE` and escalates while
preserving requester/approver separation, but does not invalidate the request. Staleness is
state-based. Each request owns immutable append-only impact versions, approval targets one exact
version, and execution revalidation automatically appends a fresh version requiring fresh approval
when material dependencies or economics change. Normal rolling-floor clock progression alone is
not stale. Approved execution authority has a hard bounded company-configurable TTL (24 elapsed
hours by default); expiry prevents execution. Trusted server time and persisted deadlines govern,
and later policy changes do not rewrite them.

The same ADR makes
materiality exact and semantic: changed executable economics/dependencies create an automatic
replacement impact version, while authority-only changes reroute/invalidate approval against the
same version. Every version is independently `NORMAL_RISK` or `HIGH_RISK`. Closed-period effects,
qualifying finalised downstream effects, controlled ordinary-tier self-review and later defined
non-disableable governance conditions are hard high-risk triggers requiring a second independent
approver. Optional bounded company thresholds use base/functional currency and may strengthen,
never disable, hard routing.

Final revalidation/execution serialises overlapping recalculations and locks the complete Q14.2
economic closure: affected configured AVCO pools plus cross-pool dependencies; FIFO layers,
allocations and downstream graph; the exact SPECIFIC serial/unit chain; and affected shortage
settlement/repair chains. Locking is never narrower than valuation state that can change the
deterministic result. Stale versions cannot execute. The bounded regeneration counter counts only
material invalidations after at least one approval, never pre-review recomputation; manual
resumption does not reset it and a repeated cap hit escalates more severely.

The same ADR closes the correction workflow with an append-only linked correction package based on
the exact approved impact version. It preserves original and counterfactual results, exact approved
delta, historical dates and actual applicable FX bases; current-rate retranslation is not the
default and genuine FX effects remain separate. Deterministic replay allocates economic lines among
remaining Inventory value, consumed job costs, Sales/COGS and other economic owners without
changing physical quantity or mutating any original/finalised record. Accounting represents those
lines in the GL rather than adding another economic allocation; Tax, integrations and ancillary
obligations reconcile separately on their own bases.

Implemented synchronous participants declared inside one internal transaction execute atomically;
any failure rolls back all and cannot become a pending obligation after partial internal commit.
Unimplemented, intentionally asynchronous, Tax-deferred and external owners remain explicit,
monitored obligations delivered where applicable through a durable idempotent outbox/correction
event. The earliest permitted open period is the default posting point; no valid point means
pending/blocked, never automatic reopening. Full reconciliation requires economic allocation to
equal the approved delta, GL to reconcile to that allocation, ancillary obligations to reconcile
on their own bases and explicit rounding to leave no unexplained residual.

The final operational details also require that Q13 immediate review records `JUSTIFIED` or
`FLAGGED`; the latter enters accountability/correction without automatically reversing stock, and
review remains separate from shortage settlement. Independent Q13 review is the default; an
explicitly recorded self-review is available only through a separately enabled, elevated and
audited small-company policy and does not itself invoke Q14. Separately, ordinary-tier self-review
of an actual Q14 impact is a mandatory high-risk trigger requiring its second independent approval.
Consequential company settings require elevated permission, mandatory reason,
actor, old/new values and timestamp without rewriting snapshotted deadlines/context. Correction
packages have unique stable identity and idempotent execution, so retries resolve existing results
instead of duplicating value. They expose cumulative period/account/financial-line/prior-package
and currency context for Accounting-owned materiality. An unlinked manual journal cannot resolve a
Q14 package, though legitimate manual journals outside that workflow remain available.

Backdating horizon is a separate eligibility dimension. The company defaults to current + previous
open period, or may select period-lock-only or one fixed/rolling posting floor. Rolling floors use
trusted server time in the company accounting timezone and snapshot request-time context; normal
clock progression does not stale an eligible request, but policy/boundary changes can. The strictest
cutover, period, valuation-policy and horizon boundary wins; no per-request extension exists.
Outside-horizon events retain physical truth, use the earliest permitted valuation-effective point
and send economic differences to the Q14 correction workflow.

**Migration is not live posting.** Controlled pre-go-live source history and opening state use
[inventory-cutover.md](inventory-cutover.md), not ordinary movement endpoints. Historical
negative balances/backdating may be preserved as source facts without weakening the post-cutover
[ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)/Q14 policy. The live stock
schema must retain cutover/source provenance and support AVCO,
FIFO and SPECIFIC opening states without silently recalculating official source history.

**Purchase UoM vs stock UoM.** Bought in 25 kg drums, consumed in grams. Convert on receipt and
store in the item's stock UoM; keep the purchase UoM on the purchase line for reference.

**Stock value drifting from the GL.** The inventory account balance and the sum of stock
valuation must agree. Build the reconciliation report early — discovering a discrepancy a year
in is far worse than catching it in week one.

**Changing an item's `valuation_method` after it has stock moves.** Must be locked once any
`stock_move` references the item — see [items.md](items.md#valuation-method) and
[ADR 0032](../decisions/0032-per-item-valuation-method.md). This is the phase where that lock
first has something real to check against; it isn't enforceable before `stock_moves` exists, and
must not be forgotten once it does.

## Related

- [items.md](items.md) — what is stocked, and where `valuation_method` is chosen
- [purchasing.md](purchasing.md) — which receipt documents originate purchase stock moves
- [acquisition-costing.md](acquisition-costing.md) — provisional cost and later adjustments
- [formulations.md](formulations.md) — consumes stock cost
- [general-ledger.md](general-ledger.md) — where valuation posts
- [ADR 0032](../decisions/0032-per-item-valuation-method.md) — per-item valuation method
- [inventory-cutover.md](inventory-cutover.md) — controlled history and opening-state migration
- [ADR 0036](../decisions/0036-controlled-inventory-cutover.md) — migration/live-policy boundary
- [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md) — live negative-stock and
  cutover-opening controls
- [ADR 0038](../decisions/0038-company-wide-per-item-avco-pool.md) — superseded company-item-only
  pool decision
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md) — constrained AVCO pool
  scopes and immutable pool identity
- [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md) — separates
  receipt, QA, supplier liability and acquisition cost
- [ADR 0007](../decisions/0007-avco-valuation.md) — superseded by 0032, original AVCO reasoning
- [ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md) — simple and
  structured location modes
- [ADR 0043](../decisions/0043-control-purchase-order-receipt-and-qa-posting.md) — purchase
  receipt, QA disposition and reversal posting contract
