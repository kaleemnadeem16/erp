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
| [quotation-lifecycle.md](quotation-lifecycle.md) | Accepted R5 draft/candidate/adoption/issue and availability Action/persistence contract |
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
| [sales-authority.md](sales-authority.md) | Accepted R9 guards/limits/approval/audit execution contract |
| [sales-duty-matrix.md](sales-duty-matrix.md) | Exact duty × operation × scope × fields × holder/activation catalogue; no live grants |
| [sales-reporting-and-forecast.md](sales-reporting-and-forecast.md) | Accepted measures/partial wins/dated FX/trust/publication/history contract |
| [documents.md](documents.md) | Accepted0137 shared template/page/artefact/file/recovery contracts and module adapter ownership; specified, not implemented |
| [quotation-documents.md](quotation-documents.md) | Accepted R8 customer projection, templates, formal artefacts and storage/recovery; specified, not delivered |
| [quotation-delivery-evidence.md](quotation-delivery-evidence.md) | Accepted R8 channel/recipient/retry evidence and R6 consent-method boundary; specified, not delivered |
| [sales-operational-handover.md](sales-operational-handover.md) | Accepted R7 operational allocations, targets, baselines and bounded corrections; specified, not delivered |
| [sales-customer-decisions.md](sales-customer-decisions.md) | Accepted R6 consent, immutable commitments, evidence, capacities and customer-decision Action contracts |
| [crm-corrections.md](crm-corrections.md) | Accepted intake, enquiry corrections and historical responsibility evidence (specified, not implemented) |
| [job-labour-recognition.md](job-labour-recognition.md) | Accepted0146 flexible labour evidence, recognition, three completeness axes and corrections; specified, not implemented |
| [job-execution-lifecycle.md](job-execution-lifecycle.md) | Accepted0145 Job/activity lifecycle, effective holds/termination and operational closeout; specified, not delivered |
| [job-costing.md](job-costing.md) | Historical Job costing sketch; replaced assumptions and current lifecycle link |

### Finance

| Doc | Covers |
|---|---|
| [general-ledger.md](general-ledger.md) | Chart of accounts, journals, what posts when |
| [receivables.md](receivables.md) | Invoices, receipts, allocation, customer ledger |
| [multi-currency.md](multi-currency.md) | Rates, dual amounts, realised FX |
| [precision.md](precision.md) | Storage scales, rounding stages, quantity representability, number display |
| [tax.md](tax.md) | Configurable tax codes, withholding tax, input/output tax |

Unfamiliar term? → [GLOSSARY.md](../GLOSSARY.md)

- [Material cost resolution](material-cost-resolution.md) — Inventory-owned read-only certainty, coverage and valuation evidence

## Phase 5 proposed contract candidates

- [Unified Job costing candidate](job-unified-costing-candidate.md) — Q55; not accepted or implemented.

## Phase 5 accepted bounded contracts

- [External Job cost and Purchasing attribution](job-external-cost-attribution.md) —0149/Q54; specified, not implemented.

- [Job cost coverage](job-cost-coverage.md) — 0147/Q52; specified, not implemented.
- [Job material attribution](job-material-attribution.md) — 0148/Q53; specified, not implemented.

## Related

- [architecture/invariants.md](../architecture/invariants.md) — rules that govern all of this
- [phases/README.md](../phases/README.md) — build order
