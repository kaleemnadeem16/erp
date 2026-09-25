[← Domain index](README.md)

# Material resolution across estimating, purchasing and execution

This note records the cross-module design baseline discovered before the Phase 3 assembly
preview is finalised. It is a review and question register, not an accepted decision: proposed
schema names are illustrative, and every unresolved choice must become an ADR before code relies
on it.

## Why this boundary exists

The system must keep four concepts separate even when a simple company experiences them as one:

```text
technical requirement → transaction plan → commercial source/pack → physical execution
        standard             planned              proposed                 actual
```

A formulation may require 6.5 L. An estimator may plan 7 L, buy or nominate two 5 L packs, and
the job may actually consume 6.8 L. None of those numbers replaces another. Internal cost and
customer selling price are separate results as well.

## Current baseline

### Quantity and UoM

- Every item has one stock UoM. An item-supplier relationship may name a purchase UoM.
- UoMs are company-scoped and convert globally inside one dimension through
  `factor_to_base decimal(19,9)`. Conversion rounds to scale 9; stock transaction quantities are
  generally stored at scale 4, while estimating quantities use scale 6.
- Fractional quantities are generally possible. Serial-controlled movements require quantity 1.
  Receipt tolerance currently treats every `COUNT` UoM as integral, but there is no item-level
  divisibility rule.
- There is no sales UoM, item-specific conversion, quantity increment, commercial pack, or
  physical-container model.

Global conversions correctly express litre↔millilitre, kilogram↔gram and metre↔millimetre.
They must not be used for item-dependent relationships such as each↔box, tablet↔strip↔carton,
metre↔roll, sheet↔m² or drum↔litre. Those depend on the item, variant or package.

### Purchasing and stock

- `item_suppliers` already owns supplier identity, supplier SKU, priority/preference, lead time,
  MOQ and purchase UoM. Purchase-order issue snapshots supplier SKU.
- Supplier prices, quantity breaks, validity periods, currency, order multiples and
  supplier-pack associations do not exist. MOQ is descriptive today rather than an enforced
  ordering constraint.
- Stock owns on-hand quantity, warehouse/location, AVCO/FIFO/SPECIFIC valuation, lots/batches,
  serials and partial layer issue. A partial quantity can remain in a lot/layer.
- A lot/batch is traceability identity, not an individual drum, roll, carton or reusable
  container. Container-level remaining quantity, expiry, reservation, job consumption, returns
  and recoverable scrap are not yet modelled end to end.

### Formulations and assemblies

- A formulation version records output quantity and standard ingredient quantities, supports
  line wastage, nesting and customer-specific overrides, and has one output.
- Explicit yield, substitutions, alternatives, co-products and by-products are absent.
- Packaging must not alter technical composition. Pack/source selection consumes the resolved
  requirement downstream of formulation/application-rate calculation.
- Assembly roll-up currently resolves material from a manual override, nested formulation or
  company-wide AVCO; labour and plant from effective-dated resource rates; subcontract from a
  manual rate; and overhead from the resolved policy. FIFO/SPECIFIC and non-company AVCO pools
  deliberately fail rather than guess.
- Assembly margin/markup can currently derive a sell rate, but that is not a complete Sales
  pricing system and must not become the only selling-price source.

### Sales and execution

- Phase 4 plans quotation revisions and immutable cost/price snapshots, but customer price
  lists, maintained prices, contract prices, quantity breaks, pack bases and final-price
  rounding are not modelled yet.
- Phase 5 owns material reservation/issue, return, scrap, actual consumption and estimate-versus-
  actual job cost. Phase 3 must not post or reserve inventory.
- Phase 6 owns final accounting/acquisition cost and GL consequences. Estimated source cost is
  evidence for an estimate, not an inventory valuation fact.

## Required semantic states

| State | Meaning | Owner | Persistence boundary |
|---|---|---|---|
| Standard requirement | Quantity calculated from active master data | Estimating | Reproducible preview; snapshot when a document is issued |
| Planned adjustment | Transaction quantity deliberately chosen by estimator/planner | Sales/Estimating, later Job planning | Persist value, reason and actor on the draft/revision once adopted |
| Source/pack proposal | Ranked way to cover planned quantity | Resolution service using Purchasing facts | Ephemeral; a suggestion is not history |
| Selected material plan | User-adopted source/pack basis and expected surplus | Quotation revision, later work plan | Snapshot on issue/approval; still no stock reservation in Phase 3/4 |
| Purchased quantity | Quantity ordered/received | Purchasing | Existing PO/receipt facts, later extended for packs |
| Issued/returned/scrapped quantity | Physical movements against a job | Inventory/Job Execution | Immutable stock movements and job-cost facts |
| Actual consumption | Net issued less returned, with scrap reported separately | Job Execution | Derived/auditable operational fact |

Never overwrite standard with planned or planned with actual. Variance needs all three.

## Capability ownership

| Capability | Owning module | Earliest phase | Domain support versus exposure |
|---|---|---:|---|
| Item-specific quantity conversions and divisibility | Item master | Before pack-aware preview | Backend foundation; simple UI may show only stock UoM |
| Commercial package definitions and barcodes | Item master | Before pack-aware preview | Optional company capability |
| Supplier pack association, order multiple and offers | Purchasing | 3 only if preview prices/suggests suppliers; otherwise 4/5 | Purchasing owns facts; Estimating only reads them |
| Standard requirement and planned adjustment | Estimating/Sales draft | 3 | Core simple path |
| Source/pack proposal engine | Estimating resolution service | 3 optional | Advanced UI; non-binding output |
| Customer price lists/rules and manual overrides | Sales | 4 | Configurable pricing strategies |
| Physical containers and remaining contents | Inventory | 5 | Advanced tracking mode only |
| Reservation, issue, return, scrap and actual consumption | Inventory + Job Execution | 5 | Operational UI selected by capability |
| Final acquisition cost and accounting variance | Purchasing + Finance | 6 | Accounting facts, never preview guesses |

The schema remains capable; company settings control policy and UI exposure, not historical
meaning. Turning an advanced screen off must not reinterpret stored transactions.

## Candidate domain shapes — not yet decisions

```text
item_quantity_units
  id, company_id, item_id, uom_id
  base_qty, base_uom_id
  quantity_increment, allow_fraction

item_packagings
  id, company_id, item_id
  code, name, contained_qty, contained_uom_id
  usage                  PURCHASE | SALE | BOTH
  barcode nullable, active

item_supplier_packagings
  id, company_id, item_supplier_id, item_packaging_id
  supplier_sku nullable, order_multiple nullable, is_preferred

supplier_item_prices
  id, company_id, item_supplier_id
  item_packaging_id nullable, price_uom_id
  min_qty, unit_price decimal(19,4), currency_id
  valid_from, valid_to nullable
```

A later advanced Inventory capability may introduce `stock_containers` with received package,
lot, location, original quantity, remaining quantity and lifecycle status. It must reference but
not replace lot/batch identity. Variable-content/catch-weight packages may require actual
contained quantity per received container rather than assuming the catalogue quantity.

## Phase 3 preview boundary

The preview should calculate and explain:

1. standard requirement and all rate/formulation provenance;
2. any explicit planned adjustment and its reason;
3. estimated internal cost and cost-source provenance;
4. optional source/pack proposals, ranked by a declared policy;
5. available/covered quantity, expected surplus or residual, and warnings/fallbacks;
6. selling-price output only through an explicitly selected strategy, kept separate from cost.

The preview must not reserve stock, create a purchase order, represent a proposal as a chosen
historical fact, or claim an estimated supplier price is actual accounting cost. Persist only a
user-adopted plan or an issued document snapshot.

## Authorisation impact

Material resolution contains capabilities and fields with different sensitivity. The final
preview contract must decide permissions for at least:

```text
estimating.assembly_preview.use
estimating.material_plan.adjust
estimating.source_proposal.view
estimating.internal_cost.view
estimating.cost.override
sales.selling_price.override
purchasing.supplier_offer.view
purchasing.supplier_offer.manage
```

These names are candidates, not registered permissions. [Q25](../OPEN-QUESTIONS.md#q25--material-resolution-permissions-and-sensitive-fields)
decides the first slice. A user who may run a quantity preview need not automatically see supplier
cost, internal margin or another department's records. A permission to make an override also does
not automatically approve it; material or selling-price exceptions may require a separate
document-specific approval under [approval-workflows.md](../architecture/approval-workflows.md).

Every implemented preview action must complete the
[authorisation checklist](../architecture/authorization.md#per-feature-authorisation-checklist),
including API field filtering and frontend capability exposure.

## Rounding boundaries

These policies are distinct and must not share one generic field:

- conversion precision — exact stored factors and high-precision intermediate arithmetic;
- quantity precision/divisibility — item/UoM rule;
- pack/order rounding — supplier/package rule;
- consumption rounding — operational measurement rule;
- unit-cost precision — money/accounting rule (`decimal(19,4)` storage);
- selling-price rounding — Sales price-rule output;
- quotation-total rounding — document/currency policy.

Intermediate requirement and cost calculations must not be rounded merely for display. Each
rounding event must identify its policy and expose pre-/post-rounding values where material.

## Known conflicts and traps

- Treating a pack as a global UoM corrupts conversion semantics across items.
- Treating `COUNT` as synonymous with indivisible produces inconsistent behaviour; divisibility
  belongs to the item/UoM relationship.
- Treating a batch as a physical container cannot distinguish two opened drums from one lot.
- Letting formulation depend on supplier pack size mixes technical standards with sourcing.
- Using Assembly margin fields as the entire price engine couples internal cost to customer
  price and cannot represent maintained prices.
- Current scale 9/6/4 quantity boundaries need one documented calculation-and-posting policy
  before pack allocation is implemented.
- The existing `PLANT` item type covers both cost resources and stocked capital assets; resolve
  that ambiguity separately rather than encoding it into material resolution.
- ADR 0033 remains valid for supplier identity, but its richer-price-list deferral may need a
  superseding ADR if Phase 3 consumes supplier offers.

## End-to-end target flow

```text
active technical standards
  → standard material requirement
  → transaction-specific planned adjustment
  → optional source/pack proposals
  → user-selected material plan
  → independent selling-price resolution
  → draft quotation revision
  → issued immutable cost/price/material snapshot
  → job/work plan and inventory reservations
  → issues, returns and scrap
  → actual job cost and accounting entries
  → standard-vs-planned-vs-actual variance
```

## Research ledger

External product documentation was used only to validate separation of concerns, not copied as
the ERP design:

- [Odoo: units of measure, packaging and packages](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/configure.html)
- [Dynamics 365: product/variant-specific UoM conversions](https://learn.microsoft.com/en-us/dynamics365/supply-chain/pim/uom-conversion-per-product-variant)
- [Odoo: vendor price lists](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/purchase/products/pricelist.html)
- [Odoo: sales price lists](https://www.odoo.com/documentation/18.0/applications/sales/sales/products_prices/prices/pricing.html)
- [Dynamics 365: estimated and actual production consumption](https://learn.microsoft.com/en-us/dynamics365/supply-chain/production-control/production-floor-execution-use)

## Decisions still open

The canonical questions are [Q17–Q28 in OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md#q17--phase-3-source-and-pack-resolution-scope).
They cover the Phase 3 supplier/pack and permission boundaries, planned-override audit, proposal
ranking, divisibility, Sales pricing defaults, physical-container granularity, variable-content
packs, Phase 5 material exceptions, organisational record scope and approval workflows. Resolve
them one by one through researched ADRs; do not silently promote the candidate shapes above into
implementation.

## Related

- [Units of measure](units-of-measure.md) · [Items](items.md) · [Purchasing](purchasing.md)
- [Formulations](formulations.md) · [Assemblies](assemblies.md) · [Sales pipeline](sales-pipeline.md)
- [Stock](stock.md) · [Job costing](job-costing.md)
- [Phase 3](../phases/phase-3-estimating-core.md) · [Progressive capability modes](../architecture/extensibility.md#progressive-capability-modes)
- [Authorisation](../architecture/authorization.md) · [Approval workflows](../architecture/approval-workflows.md)
- [ADR 0033](../decisions/0033-item-suppliers-relationship.md) · [ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md)
