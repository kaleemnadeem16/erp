[← Docs index](../README.md)

# Domain Model

**What** the system models. For **how** it's built see [architecture/](../architecture/); for
**why** see [decisions/](../decisions/).

The domain is **process manufacturing + field service + job costing** — the pattern used by
chemicals, food, agriculture, print, cleaning, flooring and construction contracting. Nothing in
the model is specific to any one industry
([ADR 0008](../decisions/0008-assembly-abstraction.md)).

---

## The costing chain

The core of the system. Each layer feeds the one above.

```
                    ┌─────────────────────────────────┐
   ASSEMBLY  ───────│ "Paint exterior wall, 2 coats"  │  sold per sqm
   (sellable)       │  cost/unit → + margin → rate    │
                    └────────────────┬────────────────┘
                                     │ components, by kind
             ┌───────────────┬───────┴───────┬──────────────┐
         MATERIAL         LABOUR           PLANT       SUBCONTRACT
             │
             ▼
   ┌─────────────────────┐
   │  APPLICATION RATE   │  "1 L covers 12 sqm on smooth plaster"
   │  keyed by company-  │  dimensions are company-defined
   │  defined dimensions │
   └──────────┬──────────┘
              ▼
   ┌─────────────────────┐
   │    FORMULATION      │  "1 L of Shade #4471 = 0.12 kg pigment + …"
   │  versioned, immutable│
   └──────────┬──────────┘
              ▼
   ┌─────────────────────┐
   │  STOCK VALUATION    │  AVCO from goods receipts
   └─────────────────────┘
```

**Every layer below the assembly is optional.** A company selling stocked products uses one
material component and no formulation. A consultancy uses labour components only.

---

## Documents

```
Lead → Opportunity → Quotation (revisions) → Job → Work Order → Invoices → Receipts
                                               ↓
                                    Timesheets, material issues
                                               ↓
                                    Actual cost vs estimate
```

---

## Files

### Platform administration

| Doc | Covers |
|---|---|
| [access-administration.md](access-administration.md) | Member invitations and lifecycle, company roles, direct grants and effective access |

### Estimating & costing

| Doc | Covers |
|---|---|
| [units-of-measure.md](units-of-measure.md) | UoM, dimensions, conversion rules |
| [items.md](items.md) | Items, types, what is stock-tracked |
| [formulations.md](formulations.md) | Recipes, versioning, customer overrides |
| [application-rates.md](application-rates.md) | Consumption per work unit, rate dimensions |
| [assemblies.md](assemblies.md) | The sellable unit, cost roll-up, margin |
| [material-resolution.md](material-resolution.md) | Cross-phase quantity, sourcing, pack, cost and price boundaries |
| [inventory-packages.md](inventory-packages.md) | Package definitions, progressive physical tracking and internal-stock selection |
| [sales-pricing.md](sales-pricing.md) | Progressive customer-price resolution and controlled formulas |
| [sales-pricing-resolution.md](sales-pricing-resolution.md) | Accepted R3 implementation contract: typed inputs/model, Actions, evidence and API gates |
| [quotation-financial-calculation.md](quotation-financial-calculation.md) | Accepted R4 dates/FX, tax components, operative rounding and financial evidence contracts |

### Operations

| Doc | Covers |
|---|---|
| [parties.md](parties.md) | Customers and suppliers — unified party model, role accounts, sites |
| [purchasing.md](purchasing.md) | Purchase orders, receipts, QA and matching boundaries |
| [acquisition-costing.md](acquisition-costing.md) | Provisional/actual purchase cost and landed-cost allocation |
| [receipt-package-evidence.md](receipt-package-evidence.md) | Observed sealed/loose arrival and atomic receipt-source evidence |
| [package-opening-preview.md](package-opening-preview.md) | Read-only opening/unpacking composition and layer-preserving proposals |
| [package-opening-operations.md](package-opening-operations.md) | Immutable saved open/unpack successors, concurrency and conservation |
| [package-issue-operations.md](package-issue-operations.md) | Grouped package tracking (v1 complete): issue, transfer, activation and enforcement, count, receipt reversal, supplier QA, capability rollout — evidence and database checks |
| [package-tracking-policy.md](package-tracking-policy.md) | Historical foundation: pinned item policy and creation-time precedence (activation is now ADR 0091) |
| [package-balance-integration.md](package-balance-integration.md) | Historical foundation: the 2026-10-03 compatibility spike and writer audit |
| [stock.md](stock.md) | Warehouses, moves, layers, AVCO pools, the valuation feed |
| [negative-stock.md](negative-stock.md) | ADR 0037 — negative-stock policy, overrides, settlement, transfer repairs |
| [stock-recalculation.md](stock-recalculation.md) | ADR 0040 — backdated recalculation and corrections |
| [stock-movement-replay.md](stock-movement-replay.md) | Backdated issue/transfer previews and connected valuation history |
| [inventory-cutover.md](inventory-cutover.md) | Migrated history, opening stock and valuation continuity at go-live |
| [physical-count.md](physical-count.md) | Blind counting, count lines, variance posting and separation of duties |
| [approvals.md](approvals.md) | The shared approval engine: policies, authorities, requests, delegation, document review |
| [sales-pipeline.md](sales-pipeline.md) | Leads, opportunities, quotations, revisions |
| [crm-corrections.md](crm-corrections.md) | Accepted intake, enquiry corrections and historical responsibility evidence (specified, not implemented) |
| [job-costing.md](job-costing.md) | Jobs, work orders, timesheets, variance |

### Finance

| Doc | Covers |
|---|---|
| [general-ledger.md](general-ledger.md) | Chart of accounts, journals, what posts when |
| [receivables.md](receivables.md) | Invoices, receipts, allocation, customer ledger |
| [multi-currency.md](multi-currency.md) | Rates, dual amounts, realised FX |
| [tax.md](tax.md) | Configurable tax codes, withholding tax, input/output tax |

Unfamiliar term? → [GLOSSARY.md](../GLOSSARY.md)

- [Material cost resolution](material-cost-resolution.md) — Inventory-owned read-only certainty, coverage and valuation evidence

## Related

- [architecture/invariants.md](../architecture/invariants.md) — rules that govern all of this
- [phases/README.md](../phases/README.md) — build order
