[← Domain index](README.md)

# Backdated issue and transfer previews

A late stock issue or transfer can change later allocations and costs even though its source
quantity was available at the proposed date. This preview reconstructs clean recorded history,
inserts the proposed movement chronologically and follows transfer-connected locations or AVCO
pools. It computes reviewable differences without posting or changing any stock.

## Where it sits

`RequestStockRecalculation` → immutable impact → independent approvals. The existing
[recalculation workflow](stock-recalculation.md) owns risk, staleness, regeneration and review.
`PostInventoryMovement` still blocks conflicting backdates; approval execution and correction
packages are not implemented by this slice.

Receipts keep their existing replayers. Issues/transfers use `MovementCounterfactualReplayer`,
with separate internal collaborators for scope discovery and in-memory physical/valuation state.
The same builder is used for initial previews, approval revalidation and manual resumption.

## Schema

Additions to the existing tables, as annotated pseudo-DDL:

```sql
stock_moves
  movement_kind       varchar NOT NULL DEFAULT 'LEGACY'
                      -- LEGACY / RECEIPT / ISSUE / TRANSFER / REVERSAL
  reverses_move_id     bigint NULL
                      -- required exactly for REVERSAL; composite tenant FK to stock_moves

recalculation_requests
  movement_kind       varchar NOT NULL DEFAULT 'RECEIPT' -- RECEIPT / ISSUE / TRANSFER
  location_id         bigint NOT NULL -- receipt destination or outbound physical source
  destination_location_id bigint NULL -- required exactly for TRANSFER; different from source
  qty                 decimal(19,4) NOT NULL -- positive, in the item's pinned stock UoM
  unit_cost           decimal(19,4) NULL
  base_unit_cost      decimal(19,4) NULL
  currency_id         bigint NULL
  fx_rate             decimal(24,9) NULL
                      -- receipt facts required; outbound request facts must all be NULL

recalculation_impact_versions
  movement_impact     jsonb NULL -- NULL for the existing receipt preview shape
  counterfactual_qty_on_hand decimal(19,4) -- zero is valid for a complete issue drawdown
```

Request and impact additions are immutable under the existing workflow guards. Posting captures
the movement kind and exact reversed move from now on, without relabelling historical records.
The source/destination models are re-resolved through company scopes before request creation.

`movement_impact` retains:

- the proposed system-derived base amount, unit cost and source-layer allocation/age references;
- original and counterfactual quantity, value and average for each affected location/pool;
- changed later movements with both original and counterfactual costs/allocations;
- location, existing AVCO pool and movement ids defining the discovered scope;
- the base currency, pinned `1.000000000` FX basis, and risk exposure in that currency.

All money/quantity values in JSON are decimal strings, never floats. Summary quantities/values
aggregate the affected scopes once; the summary average is weighted and is not an individual
pool's official average. Exact pool results remain in the payload. An empty destination pool is
represented by its natural scope key; preview never creates a live pool just to obtain an id.

## Behaviour

Call the existing request Action with `movement_kind = ISSUE` or `TRANSFER`, `item_id`,
`location_id`, `qty`, `moved_at` and the stable `source_type`/`source_id`. A transfer also requires
`destination_location_id`; tracking uses `batch_no`/`serial_no` as in posting. Cost/currency/FX
inputs are rejected for outbound proposals: valuation comes from historical stock. Omitting
`movement_kind` keeps receipt compatibility. A source already posted cannot create a second
hypothetical movement. No new HTTP endpoint is added.

FIFO and SPECIFIC scope starts with the exact batch/serial at the physical source and proposed
destination. AVCO starts with the configured company, warehouse or company/batch pool: all
physical identities sharing its average participate. Historical transfer edges expand that scope
to connected locations/pools, including further transfers. This conservatively includes the full
recorded connected component; wholly unrelated warehouses/pools/batches are excluded.

Locations lock by id, then existing pools by id. Discovery is repeated under those locks; an
expanded location/pool set refuses with a retry message rather than acquiring locks out of order.
The loader refuses QA-controlled history, shortage/settlement history, missing layer depletion
records and ambiguous `LEGACY` AVCO outbound movements. Historical receipts/transfers have unambiguous
direction; FIFO/SPECIFIC legacy outflows can be interpreted from complete immutable consumption
rows and their reversal purpose. AVCO legacy issues versus exact reversals still require
separately verified identity. No guessed backfill or historical row update is performed.

Before calculating a proposal, the original timeline must reproduce live physical balances,
AVCO pool quantity/value and each recorded outflow's valuation. A mismatch blocks the preview.
The counterfactual then inserts the proposal after existing movements at the same timestamp and
before later timestamps, with movement id providing deterministic ordering.

FIFO allocation reuses the production allocator. Transfers preserve source cost slices and FIFO
age but become available only when they arrive. SPECIFIC follows only the exact serial chain.
Every draw must have exact physical cover, including every later recorded movement. A proposed
issue cannot take stock needed by a later issue or an exact receipt reversal. A complete AVCO
drawdown takes the precise remaining value, leaving zero quantity and value.

Same-pool AVCO transfers change custody without changing pool value or average. Cross-pool
transfers move the identical source value into the destination and then recompute its average;
subsequent transfers carry the counterfactual cost onwards. Receipt reversals remove their
original exact receipt value, never the current average. No current FX lookup is used.

Staleness includes both sides and later connected movements, even when their net adjustment
cancels to zero. JSONB object ordering is canonicalised before comparison. The optional risk
threshold uses the largest of proposed movement value, gross absolute scope-value changes and
gross absolute later-movement cost changes. These are alternative exposure measures, not summed
economic corrections; the chosen value is stored for review. Mandatory high-risk triggers still
apply, and a large zero-net transfer cannot evade review through cancellation.

## Landmines

- A source-only preview misses destination cost changes and onward transfers.
- FIFO age is not physical arrival time; using age can spend stock before arrival.
- A new issue may have cover at its date but leave an already-posted later issue short.
- AVCO availability is physical/tracking-specific, while its cost can include other identities
  in the same configured pool.
- A zero net adjustment does not prove unchanged allocations or low gross exposure.
- Generic outbound rows cannot safely distinguish receipt reversal from ordinary issue. Using
  the average for both silently changes the wrong historical amount.

## Related

- [stock-recalculation.md](stock-recalculation.md) — approval and correction workflow
- [stock.md](stock.md) — live movement/valuation boundary
- [negative-stock.md](negative-stock.md) — unsupported settlement rules remain separate
- [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md)
