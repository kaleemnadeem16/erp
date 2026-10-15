# 0142 — Represent stock quantities exactly; supersede ADR 0050's increment validation

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Inventory (Items, UoM, packaging), Purchasing quantity validation, Sales quantity contracts (B1), Estimating technical measures; [units-of-measure.md](../domain/units-of-measure.md); partly supersedes [0050](0050-item-specific-uom-conversion-and-divisibility.md)

## Context

[0050](0050-item-specific-uom-conversion-and-divisibility.md) made divisibility an item-and-UoM property with a
`quantity_precision` *increment* of up to nine places, and stored conversion factors at nine places. The stock ledger and every transaction
quantity column hold four places. Inspection (2026-10-15) found:

- Purchasing converts a purchase quantity to stock units and then rounds it to four places (half-up) silently when the item has no increment
  (`BuildPurchaseOrderLineAttributes`, `DraftGoodsReceiptLines`). The package kernel instead uses an exact `toScale(4)` that raises.
- Request rules accept increments finer than the ledger can hold, so a configured increment can be unreachable.
- Goods-receipt posting reads the **live** Item increment to floor an over-receipt ceiling, so a later Item edit changes the rule of an already
  issued order.
- Increments and conversion factors are otherwise sound; the development data has none that violate the rules below. Production has not yet been
  audited ([audit plan](../phases/precision-production-audit-plan.md)).

## Decision

1. **The stock ledger stays at four decimal places.** No ledger migration.
2. **Quantity contexts are explicit**, not one universal scale: *stock ledger* (4), *commercial line* (the destination field's scale; four wherever a
   transaction column is four today), *Assembly technical* (6, preserved), *conversion evidence* (9). A rule applies to the context of the
   destination, not to a value because of where it was typed.
3. **Increments.** For Items whose transaction quantities are stored at four places, a stock-unit increment must be positive, at most four places and an
   exact multiple of 0.0001. A per-unit or per-package increment is expressed in its own transaction unit, must be at most four places, and its
   stock-unit equivalent must be representable. Package content must be at most four places. The `quantity_precision` column keeps its nine-place
   width; no stored value is rewritten. Non-stocked and technical quantity contexts are **not** permanently bound to the stock-ledger limit: their contract is
   set by their destination and decided when that field exists.
4. **Conversion factors** stay at nine places as *evidence*. A factor's precision is not the destination's precision. A converted result that is
   not exactly representable at the destination scale is **refused** with a stable reason (`QUANTITY_NOT_REPRESENTABLE`); physical quantities are
   never silently rounded.
5. **Purchasing.** New and edited transactions refuse unrepresentable quantity conversions. Purchasing *monetary* arithmetic (four-place half-up, 0120) is unchanged.
   Enforcement is **not enabled** until the read-only compatibility audit, tests and an assessment of open transactions are accepted.
6. **Pinned rule.** An issued purchase order keeps the quantity rules it was issued under. A later Item increment edit must not replace the applicable
   historical rule; the over-receipt floor reads the order's pinned value. Existing lines without a pinned value take a truthful, labelled baseline.
7. **Legacy path — only if the audit shows affected records.** Where historical open-order evidence genuinely cannot satisfy the new rule, a narrowly governed
   completion path records the exact computed value, the posted quantity, the difference, the reason and the authority. Original quantities and
   conversion evidence are preserved; posted documents are never modified. If the audit finds none, the path is not built.
8. **Sales (B1).** The Sales kernel derives quantity representability from the actual source and posting contract and checks Item increments; it does not use one scale.
   Whether six places becomes the offered Assembly quantity scale is **not decided here**: it must first be reconciled with R2/R5 line precision,
   source units and downstream representability. No fractional stock issue is invented and no customer commitment is rounded automatically.

[0050](0050-item-specific-uom-conversion-and-divisibility.md) is partly superseded by this decision (increment validation and representability only); its conversion
lookup, item-owned factors, no-chaining and no-dimension-inference rules stand. Its status line is updated; its text is not.

## Consequences

- **Positive:** one refusal rule instead of two behaviours; increments can no longer be unreachable; history is untouched.
- **Negative:** previously accepted inexact conversions (for example a repeating factor) will be refused on new lines; some items may need a corrected factor or a package definition.
- **Requires:** audit before enforcement; a shared quantity-contract helper; PO increment pinning; demo data and tests per slice.

## Alternatives considered

**Cap every `quantity_precision` at four places for all contexts** — rejected: it would hard-code ledger limits into future service and technical quantities.
**Raise the ledger scale** — rejected: a large migration and risk for no current business need.
**Keep silent half-up rounding with a warning** — rejected: it changes physical quantities without a record.
**Round and record a residual for every inexact conversion** — rejected as the default; kept only as the narrow legacy path above.
**Rewrite stored nine-place increments** — rejected: history is not rewritten.

## Related

- [0050](0050-item-specific-uom-conversion-and-divisibility.md) · [0120](0120-settle-quotation-rounding-and-financial-evidence.md) · [0141](0141-layer-precision-as-invariants-versioned-policy-and-presentation.md)
- [precision.md](../domain/precision.md) · [slices](../phases/precision-implementation-slices.md) · [audit plan](../phases/precision-production-audit-plan.md)
