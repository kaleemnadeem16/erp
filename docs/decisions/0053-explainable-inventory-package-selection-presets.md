# 0053 — Ship explainable inventory-package selection presets before custom policies

- **Status:** accepted
- **Date:** 2026-09-26
- **Affects:** Phase 3 material resolution, Inventory policy, quotation snapshots

## Context

A default package recommendation is useful only if its goal is defined. “Best” might mean using
expiring remnants, paying the lowest internal cost or opening the fewest packages. Different
companies—and sometimes different item categories—need different priorities.

A fully programmable optimiser is premature and difficult to audit. A single hard-coded rule is
too restrictive. Mature systems expose named strategies such as FIFO, FEFO and least packages:
[Odoo removal strategies](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies.html)
and [least packages](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies/least_packages.html)
provide comparable examples, while
[Dynamics 365 location directives](https://learn.microsoft.com/en-us/dynamics365/supply-chain/warehousing/create-location-directive)
support ordered inventory-selection rules.

## Decision

### 1. Ship three named, deterministic presets

All presets first exclude unusable stock: expired, quarantined, already reserved, inaccessible or
otherwise ineligible balances/containers. They may mix open remnants and sealed package sizes.

1. `MINIMISE_WASTE` — the recommended company default. Prefer usable open remnants, then
   earliest expiry/FEFO, least surplus, lowest internal cost and finally the fewest packs/picks.
2. `LOWEST_INTERNAL_COST` — prefer the lowest estimated internal inventory cost, then least
   surplus, earliest expiry and fewest packs/picks.
3. `FEWEST_PACKAGES` — prefer the fewest newly opened packages and physical picks, then least
   surplus, lowest internal cost and earliest expiry.

Each comparison is lexicographic: the first criterion decides where it differs, then the next
breaks a tie. Stable location/package/container identifiers provide the final deterministic
tie-breaker. The resolver never uses an opaque weighted score.

When a criterion lacks reliable data—expiry in quantity-only mode, for example—the resolver skips
it, reports the limitation and continues with the remaining order. Internal cost uses applicable
inventory valuation evidence; missing cost is not treated as zero.

### 2. Resolve strategy by item, category, then company

The effective preset resolves from:

1. an item override, if present;
2. the nearest item-category ancestor default;
3. the company default (`MINIMISE_WASTE` initially).

Unlike the locked physical tracking mode, this recommendation policy may change for future
previews. An adopted or issued plan snapshots the preset, policy version, availability as-of time,
generated suggestion and explanation, and the user's final selection. Historical plans do not
recalculate when defaults change.

### 3. Always explain and allow a valid user selection

The response exposes the ordered criteria, selected package/balance/container lines, useful
alternatives, expected residual or shortage, warnings and cost evidence. A user may choose another
currently valid mix. Q18 decides required reasons and Q25 decides permissions/approval.

The proposal remains advisory and creates no reservation. A later reservation revalidates the
chosen stock and may require the user or policy to resolve a conflict.

### 4. Defer a constrained custom-policy builder

The model leaves room for a future company policy builder with versioned, effective-dated,
permission-controlled ordered criteria. It should compose supported predicates and priorities,
not accept arbitrary executable expressions or an unexplained weighted formula. The presets
provide stable behaviour and real usage evidence before that builder is designed.

## Consequences

- Companies get useful choices immediately without an overbuilt rule engine.
- Category/item overrides support different goods while retaining a safe company default.
- Recommendations are reproducible and explainable from snapshots.
- Tracking quality constrains recommendation precision and is visible to the user.
- Internal cost remains independent of customer selling price and supplier replenishment.

## Alternatives considered

**One universal least-waste rule** — rejected because labour/pick effort or cost may legitimately
matter more for some companies or items.

**Weighted multi-objective score** — rejected for the first generation because weights are hard
to explain, test and reproduce across unlike units.

**Build a custom rule designer now** — deferred until the presets produce real requirements.

**Let the engine silently choose** — rejected; users must understand and may replace a proposal.

## Related

- [ADR 0051](0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md)
- [ADR 0052](0052-progressive-inventory-package-tracking.md)
- [Inventory packages](../domain/inventory-packages.md)
- [Material resolution](../domain/material-resolution.md)
- [Q18 and Q25](../OPEN-QUESTIONS.md)
