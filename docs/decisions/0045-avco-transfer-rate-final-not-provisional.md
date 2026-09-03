[← Decisions](README.md)

# 0045 — AVCO transfers price final at post time, never provisional

- **Status:** accepted
- **Date:** 2026-09-03
- **Affects:** Phase 2 Inventory ([negative-stock.md](../domain/negative-stock.md),
  [stock.md](../domain/stock.md)), Phase 6 Finance (valuation feed)
- **Supersedes:** [ADR 0039](0039-configurable-avco-valuation-pools.md)'s "Transfers" section,
  specifically its provisional-transfer-repair provision — the pool-scope decision in 0039
  otherwise stands unchanged.

## Context

[ADR 0037](0037-controlled-negative-stock-overrides.md) already decided that a physically-covered
issue drawn from a non-positive (frozen) AVCO pool prices at the pool's `last_valid_avg_unit_cost`
and that this is final — a later economic correction belongs to
[ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md)'s workflow, never a
silent rewrite. ADR 0039 built a *different* rule for the same underlying situation applied to
transfers: a transfer whose exact source location had sufficient quantity, but whose wider AVCO
pool was non-positive, was flagged `is_provisional` and, if that pool later recovered, was
retroactively repaired to the recovery's rate (`stock_transfer_repairs`, cascading transitively
through any pool the destination had itself relayed value into).

[Q15](../OPEN-QUESTIONS.md) found this repair rate is frequently wrong in a way that matters: the
transferred units are, by construction, physical stock that existed *before* the pool went
negative. Their true historical cost is normally exactly the frozen rate the transfer already
used, not the unrelated later receipt's price. Worked example: 10 units received at $10 into a
pool; an authorised shortage elsewhere in the pool freezes it; 6 of the original $10 units
transfer out while frozen; a later, unrelated $16 receipt settles the shortage. The old repair
moved `6 × ($16 − $10) = $36` out of the transfer's source pool and into its destination —
correcting a cost that was never wrong.

Reviewing why the transfer path diverged from the issue path exposed a stronger fact:
`PostInventoryMovement::transfer()` (`TransferPoster`) has no negative-stock override path and
hard-blocks any transfer whose quantity exceeds physical layer availability at its exact source
location — `TRANSFER` is not a legal `negative_stock_override_requests.movement_class` value
either. So a transfer's quantity is *always* 100% physically backed; it can never itself create
or carry shortage debt. The pool being non-positive when a transfer is posted only ever means some
*other*, unrelated location under the same pool has open debt — a fact about the pool, not about
the physically real units this transfer is moving. There is no partial-shortage-backed transfer
case for `is_provisional` to distinguish; the flag was measuring the wrong thing from the start.

## Decision

A transfer's source rate — the pool's current average, or its frozen `last_valid_avg_unit_cost`
when the pool is non-positive — is **final at post time**, exactly like a physically-covered issue
from the same frozen pool. There is no `is_provisional` flag, no repair-on-recovery, and no
cascade. `stock_transfer_details` keeps `source_avco_pool_id`/`destination_avco_pool_id` (still
useful for valuation and audit — which pools a transfer's value moved between), but drops
`is_provisional`. `TransferRepairPropagator` and `stock_transfer_repairs` are removed entirely,
since neither has a reachable case to handle under the corrected rule.

This does not change how a transfer's *destination* settles an existing shortage at its own
location — that mechanism (`ShortageSettler::applyTransferSettlements()`) repairs per incoming
cost slice at the exact layer/pool rate consumed, and is unaffected; it was never driven by
`is_provisional`.

If a future phase adds a negative-stock override path for transfers themselves (today only issues
have one), a transfer could then genuinely carry shortage-backed quantity, and a
provisional/repair mechanism for that specific, now-real case should be designed against whatever
that feature needs — not resurrected from this one.

## Consequences

- **Positive:** Transfer and issue valuation from a frozen pool now follow one consistent rule.
- **Positive:** Removes a repair subsystem (a migration, a model, a propagator, a cascading query)
  that, per this ADR's own finding, never had a reachable case to correct.
- **Positive:** Resolves [Q15](../OPEN-QUESTIONS.md) without adding layer-provenance tracking to
  AVCO — AVCO pools stay pooled-average; `stock_layers` remains traceability-only for AVCO items,
  as [ADR 0032](0032-per-item-valuation-method.md) already intends.
- **Negative:** A pool that has been frozen a long time can accumulate several transfers out of
  it, all priced at the same stale `last_valid_avg_unit_cost`, with no later true-up. This is the
  same acceptance ADR 0037 already made for issues; extending it to transfers is this ADR's point,
  not a new risk.
- **Requires:** A migration dropping `stock_transfer_repairs` and
  `stock_transfer_details.is_provisional`.

## Alternatives considered

**Quantity-split repair** (`known_qty`/`provisional_qty` on a transfer, repairing only the
shortage-backed portion) — the originally proposed fix. Rejected once the review established that
a transfer's quantity is always 100% physically backed under the current architecture
(`TransferPoster`'s hard availability block; no transfer override path exists), so
`provisional_qty` would always resolve to zero — the split can never actually occur, so building
the representation for it now is speculative.

**Full layer-provenance repair for AVCO transfers** (reuse `stock_layers.received_at` to decide
whether a transfer drew from before/after the freeze, repairing only during-freeze receipts) —
rejected for the same reason (nothing to repair today), and because it would mean AVCO transfer
costing starts reading individual layers the way FIFO/SPECIFIC already do, which cuts against AVCO
being a pooled average in the first place ([ADR 0032](0032-per-item-valuation-method.md)). Revisit
only if transfer-side overrides are built later.

**Keep the machinery dormant** (fix the trigger condition so it only fires for genuine
shortage-backed transfer quantity, but leave the classes/table in place for a future
transfer-override feature) — rejected as permanently unreachable code under the corrected rule;
this codebase's convention is not to carry speculative infrastructure for a feature that doesn't
exist yet.

## Related

- [ADR 0037](0037-controlled-negative-stock-overrides.md) — the issue-side final-rate precedent
  this decision extends to transfers
- [ADR 0039](0039-configurable-avco-valuation-pools.md) — pool scope decision; its transfer
  section is what this ADR corrects
- [ADR 0032](0032-per-item-valuation-method.md) — why AVCO stays a pooled average, not
  layer-costed
- [negative-stock.md](../domain/negative-stock.md) — the updated transfer/settlement contract
- [stock.md](../domain/stock.md) — the core movement/pool model
