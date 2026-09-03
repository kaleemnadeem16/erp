# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

None. Q14 (backdated stock receipts) was resolved on 2026-09-01 and its final reviewed
consolidation is
[ADR 0040](decisions/0040-control-backdated-stock-recalculation-and-corrections.md), with AVCO pool
scope remaining separately governed by ADR 0039.

Q1, Q2 and Q3 were resolved on 2026-08-10 — see
[ADR 0023](decisions/0023-retention-retainage-enabled.md),
[ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) and
[ADR 0025](decisions/0025-configurable-tax-engine.md).

---

## Non-blocking

Q6 (data import mechanism) and Q7 (permissions mechanism) resolved 2026-08-10 — see
[ADR 0027](decisions/0027-generic-column-mapping-import.md) and
[ADR 0028](decisions/0028-dynamic-namespaced-permissions.md). Q7's actual role/permission
*list* is still deferred to Phase 4, per those docs. Q9 (exchange rate source) resolved
2026-08-19 — see [ADR 0031](decisions/0031-manual-exchange-rate-entry.md). Q11 (stock valuation
method) resolved 2026-08-20 — see [ADR 0032](decisions/0032-per-item-valuation-method.md). Q12
(multi-supplier catalog) resolved 2026-08-20 — see
[ADR 0033](decisions/0033-item-suppliers-relationship.md).

### Q8 — Second customer profile
**Blocks:** Nothing yet — but shapes Phase 3 and the plugin design

The extensibility strategy in [extensibility.md](architecture/extensibility.md) warns against
over-abstracting before a real second customer exists. Knowing who that is — and how their
process actually differs — would let us validate the rate-dimension model against a real case
instead of a hypothetical one.

### Q10 — Sharing master data across a customer's own legal entities
**Blocks:** Nothing in Phase 1. Relevant once a customer wants more than one legal
entity/branch under one login.

`company_id` is the full tenant-isolation boundary
([GLOSSARY.md](GLOSSARY.md#company), [ADR 0004](decisions/0004-multi-tenancy-ready-schema.md)),
not a legal-entity subdivision within one customer. Today, "multi-entity support" means another
row in `companies`, isolated from its sibling exactly as strictly as from an unrelated tenant —
the only planned bridge is user membership across `company_id`s
([multi-tenancy.md](architecture/multi-tenancy.md)'s planned tenant-switching UI), not shared
data. There is no construct for sharing a specific record — e.g. a trading partner in
[parties.md](domain/parties.md) — between two legal entities of the same real customer without
also exposing it to unrelated tenants.

Surfaced while deciding [ADR 0030](decisions/0030-unified-party-model.md) (a global, unscoped
`parties` table was considered and rejected as the wrong fix for this). Options when it becomes
real: a parent "account" grouping above `company_id`; per-record cross-company sharing grants; or
accept duplication and reconcile via an external key. Not decided — no second legal entity
exists yet to design against.

### Q15 — Repair rate for provisional transfers of pre-negative stock
**Blocks:** Nothing — company value is conserved either way. Affects how accurately cost is
allocated *between* pools, so it matters before Phase 6 GL posting consumes the valuation feed.

When a non-positive pool recovers, every still-open provisional transfer out of it is repaired
to the recovery inbound's rate (see
[negative-stock.md](domain/negative-stock.md#provisional-transfers-and-repair-propagation)).
But the physical units such a transfer moved are, by construction, stock that existed *before*
the pool went negative — and their true historical cost is often exactly the frozen
`last_valid_avg_unit_cost` the transfer already used. Worked example: receive 10 @ 10 at A;
authorised shortage of 10 at another location empties the pool; transfer 6 of the @10 units
A→B (provisional, rate 10); recovery receipt 12 @ 16 settles the shortage. Settlement already
trues the shortage's COGS from 10 to 16; the transfer repair *additionally* moves
`6 × (16 − 10) = 36` out of A into B, leaving A's remaining @16 units undervalued and B's @10
units overvalued by the same 36. The repair rate is only clearly right for stock that arrived
*during* the frozen period at the new price. Options: repair only transfers of during-frozen
inbound (track layer provenance); repair at the moved layers' own historical cost (often a
no-op); or keep the current single recovery rate for simplicity and accept the misallocation.
The cascade compounds the choice — second-hop repairs currently reuse the root recovery rate.
Current behaviour is pinned by `StockPoolRecoveryTest` and conserves totals, so this is a
refinement decision, not a correctness emergency.

### Q16 — Recording the posting actor on stock movements
**Blocks:** Nothing yet — decide before sales despatch/consumption endpoints call
`PostInventoryMovement::issue()` in Phase 4/5.

`stock_moves` records no acting user. Today every movement is reached through a source document
(receipt, reversal, QA disposition) or an override request that carries its own actor, so the
audit trail composes — but a plain issue/transfer posted by a future endpoint would identify
its actor only through its source document's conventions. Decide whether the movement boundary
should stamp `posted_by` itself (one column, uniform trail) or whether source-document actors
remain the single source of truth.

---

## Answered

Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.
