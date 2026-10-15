# 0050 — Item-specific UoM conversions; divisibility is an item-and-UoM rule

- **Status:** accepted; increment validation and representability partly superseded by [0142](0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md)
- **Date:** 2026-09-25
- **Affects:** Purchasing (corrects a live Phase 2 gap), Inventory (receipt/count entry), Phase 3
  material resolution, [units-of-measure.md](../domain/units-of-measure.md),
  [items.md](../domain/items.md), [purchasing.md](../domain/purchasing.md),
  [material-resolution.md](../domain/material-resolution.md)

## Context

[Q20](../OPEN-QUESTIONS.md#q20--item-divisibility-and-quantity-increments) asked whether `COUNT`
should default to whole units, or whether fraction/whole-unit behaviour should always be an
item-and-UoM rule — credible cases on both sides include indivisible machines, fractional bulk
pieces, tablets, sheets and measured remnants. It also blocks the item-specific conversion and
allocation schema `material-resolution.md` had already identified as missing: `uoms.factor_to_base`
is global and `UomConversionService` refuses cross-dimension conversion, which correctly expresses
litre↔millilitre or kilogram↔gram but cannot express each↔box, tablet↔strip↔carton, metre↔roll,
sheet↔m² or drum↔litre — those ratios depend on the item, not the unit pair.

This is not only a Phase 3 preview concern. `BuildPurchaseOrderLineAttributes` (shipped Phase 2)
computes a PO line's stock quantity through the global, dimension-locked
`UomConversionService::convert('1', $purchaseUom, $stockUom)`, which throws unless the purchase
UoM shares a dimension with the stock UoM. **Box/each-style pack purchasing is not actually
possible today**, despite `item_suppliers.purchase_uom_id` existing on the schema since ADR 0033 —
this predates and does not depend on any Phase 3 preview decision.

The only existing "COUNT is integral" logic is `PostGoodsReceipt`'s over-receipt-tolerance ceiling,
which floors the maximum to a whole number when `stockUom->dimension === 'COUNT'`. Nothing
validates that a received, counted or ordered quantity is actually a whole number, and dimension is
the wrong signal to use for it — `material-resolution.md` already called this out as "not a
coherent system-wide policy."

Primary-source research across SAP, Oracle (EBS/Fusion SCM), NetSuite, Microsoft Dynamics 365
Business Central and GS1 confirms both halves of Q20's question and gives a concrete, converged
shape:

- **Global vs. item-specific conversion.** Oracle names this split directly: a *Standard*
  conversion is "common to any item"; an *Item-specific (Intra-class)* conversion is "for a
  specific item within a unit of measure class," and when both exist "the item-specific conversion
  is used" ([Oracle EBS PIM Implementation Guide](https://docs.oracle.com/cd/E18727_01/doc.121/e13450/T291651T291797.htm)).
  Oracle's own worked example is exactly this repo's each/box case: item "soda pop" has `CS = 24
  EA` (item-specific); item "canned tomatoes" has `CS = 10 EA` (item-specific) — the same unit code
  means a different quantity per item. Fusion Cloud SCM confirms this in the current data model:
  `INV_UOM_CLASS_CONVERSIONS` is "uniquely defined for an item"
  ([Fusion SCM docs](https://docs.oracle.com/en/cloud/saas/supply-chain-and-manufacturing/25d/oedsc/invuomclassconversions-27557.html)).
  SAP reaches the same destination differently: alternative units of measure (order/sales/issue/WM
  unit) live directly on the material master, each with its own item-owned conversion factor
  ([SAP Library, Material Master](https://help.sap.com/saphelp_470/helpdata/en/ff/515f4349d811d182b80000e829fbfe/content.htm)) —
  i.e. always item-specific by construction. NetSuite is the evidenced failure mode to avoid: a
  Unit Type owns one fixed, global conversion table, and "after a units type is assigned to any
  item, you can only add more units. You can't edit the type itself"
  ([NetSuite — Assigning Units of Measure to Items](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2212390.html)) —
  this cannot express Oracle's soda-pop/canned-tomatoes case at all.
- **Divisibility is never dimension-inferred.** No vendor researched defaults divisibility from a
  unit's dimension/class. Business Central's `Item Units of Measure` table carries a **Quantity
  Rounding Precision** field per (item, UoM) row — its own worked example is a receipt of 5 of 6
  boxed items rounding to a clean 5 rather than `4.99998`
  ([Microsoft Learn](https://learn.microsoft.com/en-us/dynamics365/business-central/inventory-how-setup-units-of-measure);
  shipped, not proposed, per the
  [2021 wave 2 release plan](https://learn.microsoft.com/en-us/previous-versions/dynamics365-release-plan/2021wave2/smb/dynamics365-business-central/rounding-base-unit-measure)).
  NetSuite instead ties divisibility to *tracking type*: "serial numbered items can't use
  fractional quantities... lot numbered items can use fractional quantities"
  ([NetSuite — Serial and Lot Inventory with Multiple UoM](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N2214525.html)) —
  which corroborates this repo's existing, separate "serial-controlled movements require quantity
  1" rule rather than replacing it.
- **Catch-weight is a different mechanism, not a smarter factor.** SAP Catch Weight Management
  exists specifically for materials whose two units of measure are "**not in a fixed conversion
  factor to each other**" and tracks them as independent, parallel quantities rather than one
  derived from the other
  ([SAP Help, Catch Weight Management](https://help.sap.com/saphelp_globext607_10/helpdata/en/e5/1f8cbba6844be6b43dd79e1fece31b/content.htm)).
  GS1 confirms this at the standards level for "Variable Measure Trade Items" — meat, cheese, rope,
  fabric, carpet on a roll — priced/ordered/invoiced in quantities that vary
  ([GS1](https://support.gs1.org/support/solutions/articles/43000734396-what-is-a-variable-measure-trade-item-)).
  A fixed per-item factor cannot represent a quantity that varies per physical container; this is
  [Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages)'s question, not this
  one.

## Decision

**1. Item-specific quantity conversion is a first-class concept, resolved before falling back to
the existing global, dimension-locked `UomConversionService`, never instead of it.**

```
items                                          -- add one column
  ...
  quantity_precision   decimal(19,9) NULLABLE  -- smallest increment the item's own stock UoM may
                                                -- be transacted in; NULL = unrestricted (today's
                                                -- behaviour, unchanged by default)

item_quantity_units                            -- new table
  id, company_id
  item_id              → items
  uom_id                → uoms                  -- the pack/alternate unit, e.g. BOX, ROLL, SHEET,
                                                  -- STRIP, CARTON
  factor                decimal(19,9)            -- 1 uom_id = factor × the item's stock UoM;
                                                  -- item-owned, may cross uom dimension (see rule 3)
  quantity_precision    decimal(19,9) NULLABLE   -- smallest increment this pack UoM may be
                                                  -- transacted in; NULL = unrestricted

  unique(company_id, item_id, uom_id)
```

There is deliberately no `base_uom_id` column: a factor is always relative to whatever
`items.stock_uom_id` currently is, and `items.md` already forbids changing an item's stock UoM once
any transaction references it.

**2. Conversion lookup order for any (item, from_uom, to_uom):** an `item_quantity_units` override
for that item and UoM, if one exists → else, if the two UoMs share a dimension, the existing global
`UomConversionService` → else fail loudly. No silent guessing, matching this repo's existing
discipline (`IngredientCostResolver`, `ExplodeAssemblyVersion` fail rather than guess).

**3. An item-specific factor may cross UoM dimensions.** "1 roll = 50 m" bridges `COUNT` and
`LENGTH` for that one item; "1 sheet = 2.9768 m²" bridges `COUNT` and `AREA`. This is a deliberate,
named exception to the global service's same-dimension-only rule, scoped to `item_quantity_units`
only — the global service's own invariant is unchanged for every other caller. This is also
explicitly **not** an application rate: an application rate is a contextual, business-judgement
consumption relationship ("litres per m² for one coat"); an item's pack size is a fixed catalog fact
about that item's packaging. `units-of-measure.md`'s existing UoM/application-rate boundary applies
here unchanged.

**4. Divisibility/precision is always an item(-and-UoM) property, never inferred from
`dimension`.** This directly answers Q20: `COUNT` does not default to whole units — a rounding
precision, not a boolean, expresses it (so "round to the nearest half-sheet" is representable, not
just whole-vs-fractional). Absence of a precision value means unrestricted, i.e. today's behaviour
is the default everywhere until an item explicitly sets one.

**5. Each pack level's factor is stored directly against the item's stock UoM, never chained
through an intermediate pack level.** A tablet→strip→carton hierarchy gets one `item_quantity_units`
row per level, each expressed directly in the stock UoM, to avoid compounding rounding error across
multi-hop conversions.

**6. Serial-tracking's "quantity must be exactly 1" rule stays a separate, tracking-based
constraint**, not folded into `quantity_precision` — matches NetSuite's actual behaviour and avoids
conflating two different reasons a quantity might be constrained.

**7. Explicit non-goals, left to other questions:**

- **Catch-weight/variable-content packages** ([Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages))
  are not solved by `item_quantity_units.factor`, which is fixed by definition. Catch weight needs
  a structurally different mechanism (an actually-measured second quantity per transaction, SAP-CWM
  style), which remains Q23's to design.
- **Named, barcoded commercial package definitions and Purchasing-owned supplier-pack/offer data**
  for costed source proposals remain undecided — narrowed, not closed, by this ADR; see the revised
  [Q17](../OPEN-QUESTIONS.md#q17--phase-3-commercial-package-and-costed-source-proposal-scope).
- **Formulation and assembly technical quantities never resolve through `item_quantity_units`.**
  `formulation_lines.qty` and `assembly_components` continue to resolve only through the existing
  global `UomConversionService`. Packaging must not alter technical composition; pack conversion
  applies only downstream, at sourcing/purchasing, exactly as `material-resolution.md` already
  states.

## Consequences

- **Positive:** Corrects an already-shipped Phase 2 defect — box/each-style purchase-UoM
  conversion is not actually usable today despite the column existing since ADR 0033. Answers Q20
  with convergent, cross-vendor evidence rather than an invented rule. Leaves the existing global
  `UomConversionService` and its dimension-lock completely untouched for every UoM that never needs
  an override — this is additive, not a rewrite. Keeps the Formulations/Estimating technical-vs-
  commercial boundary intact.
- **Negative:** One more table and one more lookup path for any code converting a quantity for a
  specific item. The cross-dimension exception in rule 3 is a real, deliberate carve-out from an
  otherwise-universal invariant (`units-of-measure.md`'s "conversion is permitted only within a
  dimension") and future readers must not generalise it to the global service itself.
- **Requires:** Implementation of the schema above, an item-aware conversion lookup (extending
  `UomConversionService` or adding a sibling service), and replacing `PostGoodsReceipt`'s ad hoc
  `dimension === 'COUNT'` floor with a real `quantity_precision` check — none of this is built yet;
  this ADR fixes the shape and rules only, the same way ADR 0033 fixed `item_suppliers`' shape
  before Phase 2 Purchasing existed to build it. Quantity-increment/order-multiple (SAP's Rounding
  Profile, Oracle's Fixed Lot Multiple, NetSuite's Reorder Multiple — a *step*, distinct from both
  this ADR and from `minimum_order_qty`) and whether MOQ becomes enforced remain open, unscheduled
  follow-ups, not decided here.

## Alternatives considered

**Extend `uoms`/the global service to sometimes mean item-specific** — rejected. Breaks "exactly
one `is_base` per dimension per company" and the guarantee that every other caller of
`UomConversionService` gets a truly universal conversion.

**NetSuite's single global Unit-Type-per-item model** — rejected as the evidenced failure case:
cannot express the same pack UoM meaning a different quantity for a different item, which is
exactly this repo's each/box, tablet/strip/carton and sheet/m² requirement.

**Pack-as-catalog-item** (GS1 Healthcare's pattern: "Carton of 24 Widget X" is its own item/GTIN,
not a conversion factor) — heavier, and closer to physical/traceable package identity, already
tracked separately as [Q22](../OPEN-QUESTIONS.md#q22--physical-container-granularity). Not needed
to solve the quantity-math problem this ADR closes; remains available later as the shape of a
future named/barcoded `item_packagings` capability if Q17 decides that's needed.

**Boolean `allow_fraction` instead of a numeric `quantity_precision`** — rejected. Costs the same
one column, but a boolean cannot express "round to the nearest half-sheet," while numeric precision
is what Business Central and Odoo actually ship.

**Chaining pack levels transitively** (strip's factor relative to tablet, carton's relative to
strip) instead of each level direct to the stock UoM — rejected. Compounds rounding error across a
multi-hop hierarchy; storing each level directly against the stable stock UoM avoids it.

## Related

- [Q20](../OPEN-QUESTIONS.md#q20--item-divisibility-and-quantity-increments) — closed by this ADR
- [Q17](../OPEN-QUESTIONS.md#q17--phase-3-commercial-package-and-costed-source-proposal-scope) —
  narrowed by this ADR, not closed
- [Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages) — explicitly not
  answered by this ADR
- [units-of-measure.md](../domain/units-of-measure.md) — the existing global conversion model this
  extends without changing
- [items.md](../domain/items.md) — stock UoM immutability this table depends on
- [purchasing.md](../domain/purchasing.md) — the live Phase 2 gap this corrects
- [material-resolution.md](../domain/material-resolution.md) — cross-module research baseline and
  rounding-boundary taxonomy this ADR is drawn from
- [ADR 0033](0033-item-suppliers-relationship.md) — `item_suppliers.purchase_uom_id`, the field
  this ADR makes actually usable for pack UoMs
