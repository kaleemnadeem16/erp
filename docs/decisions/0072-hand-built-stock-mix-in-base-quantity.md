[← Decisions](README.md)

# 0072 — Let a person build the stock draw by hand, in base quantity, validated and priced by the server

- **Status:** accepted (escalation of a mix's *cost consequence* is deliberately not decided here — see "Not decided")
- **Date:** 2026-10-01
- **Affects:** Estimating's Material Preview stock proposal, [inventory-packages.md](../domain/inventory-packages.md),
  [ADR 0053](0053-explainable-inventory-package-selection-presets.md) §3, [ADR 0054](0054-risk-based-material-plan-variance-controls.md) §4

## Context

ADR 0053 §3 says a user may choose another currently valid mix than the preset's proposal, and ADR 0054 §4 says a different valid
mix at the same planned quantity normally needs no reason. Until now the Material Preview could only compare the three presets.

The choice has to stay generic across industries: the same quantity can be held in different pack sizes and units (a 20 L drum,
a 5 L can, a roll of 50 m), and a pack may be part-used. Package-level tracking (sealed/opened packages, identities) is a
separate capability that is not built ([ADR 0052](0052-progressive-inventory-package-tracking.md)).

## Decision

1. **The canonical mix is quantity per location and batch, in the item's stock unit.** A line is
   `(item, location, batch, quantity)`. This is the representation the automatic proposal already uses, so a hand-built mix and
   a proposal are the same kind of thing. Physical package size is never the stored representation. If 20 L is usable at a
   location and the estimator chooses 7.5 L, the line is 7.5 L and 12.5 L remains.
2. **Units are an input convenience.** A line may be typed in any unit that converts to the item's stock unit through the
   existing item-specific conversion ([ADR 0050](0050-item-specific-uom-conversion-and-divisibility.md)): "0.75 drum" of a
   2 kg drum is 1.5 kg. The server converts, keeps the entered figure, unit and factor only to explain the result, and refuses
   a unit that does not convert. A part-used pack needs no special case; it is a smaller quantity.
3. **The server validates and prices; it never trusts the client's arithmetic.** A mix is *refused* — not warned about, and not
   overridable — when a line names stock that is not a usable balance in the preview's scope (absent, outside the narrowed
   scope, held for QA, in a location that does not count as usable, or exhausted), draws more than that balance's usable
   quantity, repeats a location/batch, breaks the item's quantity precision, or uses a unit that does not convert.
4. **A mix may not draw more than the plan.** The requirement is the planned quantity (the standard, or a what-if adjustment).
   Lines adding up to more than it are refused with the instruction to change the planned quantity instead, so *the variance
   rules — reasons, blocks, approval — always judge how much is used, and a hand-built mix is never a way around them.*
   Lines adding up to less are accepted and reported as a shortage, exactly as an automatic proposal reports one.
5. **Same costing and evidence.** Lines are priced from the same balances and cost evidence as a proposal (missing cost is
   `null`, never zero; a mix with any uncosted line has no estimated cost), and cost stays behind
   `estimating.internal_cost.view`. Each line says its source, entered quantity, factor, usable before, remaining after and unit
   cost; the result says whether it matches the proposal and how its cost compares with the proposal's.
6. **Advisory, nothing stored.** Like the rest of the preview it reserves and posts nothing. The chosen lines are part of the
   evidence fingerprint. Choosing stock needs `estimating.inventory_availability.view`, like narrowing scope or choosing a
   strategy; otherwise the input is refused.
7. **Package tracking extends this model; it does not replace it.** The rule for the future: sealed/opened package state,
   package identities, package-aware proposals and "about two 5 L cans" equivalents are added as *more facts about the same
   base-quantity lines* (for example a package identity on a line), never as a new mix representation.

## Not decided — reusing the variance bands for a mix's cost consequence

ADR 0054 §4 lets a mix escalate when its consequence crosses a configured band. The existing variance bands cannot carry that
meaning without distortion, so no escalation is built and nothing here invents a second policy concept:

- A variance band judges **planned quantity against the standard**. Its `OVER`/`UNDER` direction means "plan above/below the
  recipe"; its quantity and percent measures are that quantity difference; its cost measures are *that quantity difference
  valued at one unit cost* (the cost percent is therefore the quantity percent). All four describe "we are using more or less
  material than the recipe says".
- A hand-built mix keeps the planned quantity the same and changes **which stock it comes from**. Its consequence is the cost of
  the draw against the cost of the proposal, an unrelated baseline: it is neither over nor under, and its size is not a quantity
  difference at a single unit cost. Reusing "cost impact over 15%" would make one rule mean two different things, and a company
  editing the band could not tell which they were changing.

What ships instead is *information*: the mix's cost, the proposal's cost and the difference. The choice of whether a costly
mix should ever need a reason or approval — and, if so, whether it is a new kind of band ("cost of the draw over the proposal"),
a reuse with an explicit baseline, or nothing — is left to a separate decision with the owner. Until then a mix can never lower
or raise how much is used, so no variance or approval rule can be bypassed by choosing one.

## Deferred, with what each depends on

| Capability | Depends on | Picked up by |
|---|---|---|
| `item_packagings` (named package kinds, "5 L can") | ADR 0050's item quantity units (built) | The package-tracking slice below |
| `PACKAGE_BALANCE`, `CONTAINER_TRACKED` modes; sealed/opened package state; remnants | `item_packagings`; Inventory receipts, issues, transfers and counts keeping package state reconciled to the ledger ([ADR 0052](0052-progressive-inventory-package-tracking.md) §3) | The package-tracking slice |
| Package-aware proposals and package equivalents | The two above | The package-tracking slice |
| Expiry, open-remnant and surplus criteria in the presets | Package/batch state carrying expiry and remnants | The package-tracking slice |
| Waste/residual variance bands (ADR 0054) | Sealed-pack and remnant state | The package-tracking slice |
| Variable-content / catch-weight packages | [Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages) — dual recorded quantities | Its own design, after Q23 |
| Per-unit quantity precision (a drum only in whole drums) | A rule on `item_quantity_units.quantity_precision`, which is stored but not enforced anywhere today | The package-tracking slice, or sooner if a company needs it |
| Escalation of a mix's cost consequence | Owner decision above | A separate decision |

**The package-tracking slice** has no scheduled phase yet. It is proposed as a dedicated Inventory design (its own ADR) settled
*before Phase 5 material issue*, because that is where reservations and real consumption of sealed/opened stock start
([ADR 0052](0052-progressive-inventory-package-tracking.md) §3: availability stays advisory until then), and
[Q24](../OPEN-QUESTIONS.md#q24--phase-5-material-exceptions) (Phase 5 material exceptions) sits beside it. It is recorded in
[phases/README.md](../phases/README.md) under Phase 5 so it cannot be forgotten.

## Consequences

- **Positive:** an estimator can steer the draw (nearest warehouse, use up a remnant location) and see exactly what it costs,
  with every figure recomputed by the server.
- **Positive:** the same representation carries into package tracking unchanged.
- **Positive:** approval and variance control cannot be sidestepped by choosing stock by hand.
- **Negative:** a mix cannot express "and a bit more, for waste": that is a planned-quantity change, by design.
- **Negative:** until package tracking exists a mix makes no claim about sealed or opened packs.

## Alternatives considered

**Store the mix in package units** — rejected: it ties the representation to package tracking that does not exist and breaks for
items without packs.

**Let the client compute totals and costs** — rejected: it would let a stale or altered figure through.

**Allow drawing more than the plan with a warning** — rejected: it would let a mix change consumption without the variance rules.

**Reuse the variance bands for the cost consequence now** — rejected for the semantic mismatch above.
