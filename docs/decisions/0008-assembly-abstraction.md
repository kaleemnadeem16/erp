[← Decisions](README.md)

# 0008 — Assembly as the core sellable abstraction

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** Phases 3–6, the whole estimating and costing model

## Context

The reference business buys ingredients, mixes them to a formulation, and sells the result as
an on-site **service** priced per square metre — not as a product. A naive product-and-price
model cannot represent this.

The system must also serve other companies whose processes differ, so the abstraction cannot be
paint-specific ([0009](0009-company-defined-rate-dimensions.md),
[0010](0010-core-plus-plugins.md)).

The pattern is well established: it is **process manufacturing + field service + job costing**,
used across chemicals, food, agriculture, print, cleaning, flooring and construction.
Construction estimating solved the pricing side decades ago with **rate build-up**.

## Decision

The sellable unit is an **assembly**: a unit of work priced per work measure, whose cost is
built up from components classified by kind.

```
ASSEMBLY  "Paint exterior wall, 2 coats"   per sqm
  ├─ MATERIAL     → application rate → formulation → AVCO stock cost
  ├─ LABOUR       → hours per work unit × labour class rate
  ├─ PLANT        → equipment hours
  ├─ SUBCONTRACT  → lump or per unit
  └─ OVERHEAD     → allocation
  = cost/unit → + margin → sell rate/unit
```

Every layer beneath the assembly is **optional**. A company selling stocked products uses one
material component and no formulation. A consultancy uses labour components only.

## Consequences

- **Positive:** One model covers product sales, service delivery and manufacturing. Core
  contains nothing industry-specific.
- **Positive:** The material/labour/plant/subcontract split is the standard estimating
  breakdown, so variance reporting is meaningful and familiar to anyone from the industry.
- **Positive:** Quotation lines become **scope items** ("Exterior wall, 250 sqm, 2 coats"),
  which is how contractors actually quote.
- **Negative:** More indirection than a simple product catalogue. A company that only sells
  stocked goods carries structure it doesn't need.
- **Requires:** assemblies are versioned and immutable once used, and quotations snapshot the
  fully resolved breakdown — see [invariants.md](../architecture/invariants.md).

## Alternatives considered

**Products with a price list** — rejected outright; cannot express derived cost or service
delivery.

**Manufacturing BOM only** (no assembly layer) — a BOM produces a *product*, but this business
sells *work*, and labour and plant have no place in a BOM. Rejected as only half the model.

**Bespoke per-industry models** — rejected; that is the fork-per-customer failure mode
[0010](0010-core-plus-plugins.md) exists to prevent.

## Related

- [domain/assemblies.md](../domain/assemblies.md)
- [0009 — Company-defined rate dimensions](0009-company-defined-rate-dimensions.md)
- [domain/formulations.md](../domain/formulations.md)
