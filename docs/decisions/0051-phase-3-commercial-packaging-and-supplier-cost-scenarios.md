# 0051 — Generate default mixed-pack cost plans; keep them non-binding

- **Status:** proposed
- **Date:** 2026-09-25
- **Affects:** Phase 3 material resolution and assembly preview, Purchasing supplier terms,
  item-master commercial packaging, Phase 4 quotation snapshots

## Context

[Q17](../OPEN-QUESTIONS.md#q17--phase-3-commercial-package-and-costed-source-proposal-scope)
asks whether the Phase 3 preview should stop at standard/planned material quantity and current
internal cost, or introduce enough commercial-package and supplier-offer data to calculate a
costed way of covering the requirement.

[ADR 0050](0050-item-specific-uom-conversion-and-divisibility.md) removed one source of ambiguity:
item-specific UoM conversion and quantity precision are required independently of preview. A
`BOX` may equal 12 of one item and 24 of another, and Purchasing already needs that conversion.
What remains here is commercial identity and cost: which named/barcoded package a supplier sells,
on what effective-dated terms, and what a Phase 3 estimate may infer from those facts.

The current repository cannot answer that reliably:

- `item_suppliers` owns supplier SKU, preference, lead-time override, MOQ and purchase UoM, but
  no price, validity period, currency, quantity break, order multiple or package association;
- PO lines accept a transactional unit price and snapshot it on issue, but an unissued future PO
  is not a reusable supplier-price source;
- Assembly material costing uses a manual override, a nested formulation, or current AVCO. A new
  or not-yet-stocked purchased item can therefore have no meaningful internal cost;
- stock can represent partial quantities in lots/layers, but there is no physical-container model
  and Phase 3 has no reservation, requirement date or location-allocation contract.

Primary-source research supports a narrow middle boundary:

- [Odoo distinguishes](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/configure.html)
  a product-specific commercial packaging definition (name, contained quantity, purchase/sales
  applicability and barcode) from an individually tracked physical package. A packaging barcode
  identifies a packaging *type*, not one physical pallet or drum.
- [Odoo vendor price lists](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/purchase/products/pricelist.html)
  hold supplier-owned minimum quantity, price, lead time and ordering sequence, and use those facts
  to default an RFQ/PO.
- [Dynamics 365 planned BOM costing](https://learn.microsoft.com/en-us/dynamics365/supply-chain/cost-management/bom-calculation-groups)
  can read purchase-price trade agreements or inventory cost as explicit alternative cost sources,
  with fallback/warning policy rather than pretending one is the other.
- Dynamics 365 places [automatic vendor selection by lowest price or shortest lead time](https://learn.microsoft.com/en-us/dynamics365/supply-chain/master-planning/planning-optimization/purchase-trade-agreement)
  in master planning. Oracle likewise applies [minimum quantity and fixed-lot-multiple rules](https://docs.oracle.com/en/cloud/saas/supply-chain-and-manufacturing/25c/faupc/item-attributes-and-order-modifiers-for-supply-planning.html)
  while generating planned orders. Those are supply-planning decisions, not basic cost-roll-up
  arithmetic.

## Proposed decision

### 1. Include fixed commercial packages and Purchasing-owned offers in the Phase 3 foundation

Add a named commercial-package layer on top of ADR 0050's item-owned quantity conversion:

```text
item_packagings
  id, company_id, item_id
  item_quantity_unit_id            -- fixed contained quantity comes from ADR 0050's factor
  code, name
  usage                            -- PURCHASE | SALE | BOTH
  barcode nullable
  active

item_supplier_packagings
  id, company_id, item_supplier_id, item_packaging_id
  supplier_item_code nullable
  minimum_order_qty nullable       -- expressed in this package/offer's ordering UoM
  order_multiple nullable          -- distinct from item/UoM quantity precision
  is_preferred
  active

supplier_item_prices
  id, company_id, item_supplier_id
  item_supplier_packaging_id nullable
  price_uom_id
  min_qty
  unit_price decimal(19,4), currency_id
  valid_from, valid_to nullable
```

These names are the proposed conceptual shape, not migration-ready DDL. Acceptance still requires
checking keys, overlap constraints, archival rules and which supplier terms remain defaults on
`item_suppliers` versus package-specific overrides.

The boundaries are firm:

- Item master owns fixed package identity, quantity and barcode.
- Purchasing owns supplier/package relationships, commercial terms and prices.
- Estimating reads those facts through a resolution service; it does not own or silently rewrite
  them.
- A package is not a physical container, lot, reservation or stock balance.
- First-generation packages are fixed-content only. Catch-weight/variable-content receipt facts
  remain [Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages).

### 2. Phase 3 proposes an explainable mixed-pack plan and lets the user replace it

Preview uses the standard/planned requirement, reliable internal-availability facts and applicable
supplier offers to generate a default material plan. A plan may combine pack sizes and, when the
policy permits, suppliers:

```text
technical/standard requirement
  → explicit planned requirement
  → non-binding internal-stock contribution
  → uncovered requirement
  → one or more supplier/package plan lines
  → total coverage and expected surplus
  → estimated internal consumption cost and procurement cash outlay
```

If 6.5 L is required and one supplier offers 1 L, 5 L and 10 L packs, a valid suggestion or user
selection may be one 5 L pack plus two 1 L packs. The output keeps coverage, expected surplus,
normalised unit cost, estimated consumption cost and procurement cash outlay separate. The job is
not silently charged for every purchased litre merely because Purchasing must buy complete packs;
a later explicit policy may treat non-reusable residual as job cost, but Phase 3 does not assume
that.

The default is a suggestion, not an instruction. The user may add/remove plan lines, change pack
counts, choose another supplier or replace the suggested combination. The resolver recalculates
the consequences and validates effective dates, currencies, MOQ, order multiples and quantity
coverage. [Q18](../OPEN-QUESTIONS.md#q18--audit-requirements-for-planned-quantity-adjustments)
decides when that change needs a reason; [Q25](../OPEN-QUESTIONS.md#q25--material-resolution-permissions-and-sensitive-fields)
decides who may see cost and change or adopt the plan.

The algorithm must explain why its default won. [Q19](../OPEN-QUESTIONS.md#q19--default-ranking-for-source-and-pack-proposals)
remains a Phase 3 blocker and decides whether the default prioritises internal cost, procurement
outlay, least surplus, preferred supplier/package, earliest known availability or a configurable
ordered policy. The objective is always company/internal cost and material coverage, never the
customer's selling price; Sales resolves that independently in Phase 4.

“Available” must identify which fact it means:

- current internal availability is aggregate stock quantity by the inventory scopes the caller is
  allowed to inspect, and is advisory because Phase 3 does not reserve it;
- an active supplier offer proves a purchasable pack/price relationship, not that the supplier has
  that quantity physically available now;
- exact internal availability by unopened pack size is knowable only if later physical-container
  tracking records those individual packs. Without it, the system must not infer “two unopened 5 L
  cans” from an aggregate balance of 10 L.

The Phase 3 resolver does **not**:

- hide its ranking rationale or alternatives;
- claim advisory on-hand quantity has been allocated;
- claim a supplier has stock from price/lead-time data alone;
- create a PO, reserve stock, choose a warehouse/location/lot/container, or post anything.

### 3. Cost sources are explicit candidates; there is no silent fallback

Current AVCO, formulation-derived cost, an applicable supplier offer and an authorised manual
override are different evidence. Preview returns the chosen source, as-of date, currency/FX
evidence and warnings. Missing AVCO is not presented as a reliable zero, and a supplier offer does
not silently replace internal valuation.

An estimator must explicitly select the intended cost basis, or a later accepted policy must name
the default and fallback order. This proposal does not turn an estimated supplier purchase price
into an inventory valuation or accounting fact.

### 4. Persistence stays at the existing document boundaries

- A preview suggestion is ephemeral.
- A user-adopted material basis may be stored on a draft quotation revision with actor and source
  provenance.
- Issue/approval snapshots the selected quantities, package/offer facts, cost basis and FX evidence
  so later master-data changes do not reinterpret the quotation.
- Phase 5 owns reservation and actual issue/return/scrap.
- Phase 6 owns final acquisition cost, invoice variance and accounting.

## Consequences

- **Positive:** New/unstocked purchased materials can be estimated from governed Purchasing facts
  rather than a misleading zero or an unexplained manual rate.
- **Positive:** A user can see both job-consumption economics and immediate purchasing cash need
  without conflating them.
- **Positive:** Every preview begins with a useful default while preserving human control over
  mixed-pack and multi-supplier choices.
- **Positive:** ADR 0050 remains the single conversion/divisibility mechanism; commercial package
  identity adds meaning without duplicating its factor.
- **Positive:** The design can later feed sourcing/planning without embedding a premature optimiser
  in Estimating.
- **Negative:** Phase 3 gains master-data and Purchasing foundation beyond the preview screen
  itself, including effective-dated prices and package associations.
- **Negative:** Even a bounded mixed-pack suggestion is an optimisation problem and needs a
  deterministic Q19 objective, tie-breakers, limits and explainability tests.
- **Requires:** Explicit permissions and API field filtering under Q25; supplier prices and
  internal costs cannot become visible merely because a user can run a quantity preview.
- **Requires:** Acceptance-time constraints for effective-date/quantity-break overlap, currency/FX
  resolution, package archival, and deterministic scenario ordering.

## Alternatives considered

**Stop Phase 3 at planned quantity plus AVCO** — rejected as the recommendation. It is the smallest
slice, but a new or unstocked purchased material has no useful AVCO and would force zero-cost or
manual estimates precisely where supplier evidence is most valuable.

**Show each pack/offer independently and prohibit combinations** — rejected. A real requirement may
be covered most sensibly by one 5 L pack plus two 1 L packs, and forcing one pack size would make a
human reproduce the missing combination outside the system.

**Build a full supply-planning optimiser in Phase 3** — rejected. The bounded preview optimiser
uses known internal quantities and commercial offers to propose an estimate. Supplier live stock,
dated supply/demand netting, firm allocation, reservations and PO generation remain later planning
and execution responsibilities.

**Put a current price directly on `item_suppliers`** — rejected, consistent with ADR 0033. One
column cannot represent currencies, date ranges, quantity breaks or package-specific prices, and
editing it would erase the evidence behind an older estimate.

**Treat commercial packaging as the physical container model** — rejected. A named “5 L drum”
and Drum #000123 with an actual remaining quantity are different facts with different lifecycles.
The latter remains Phase 5/Q22–Q23 territory.

**Charge the full purchased quantity to the estimate by default** — rejected. Reusable surplus is
still an asset available to later work. Procurement outlay may exceed expected consumption cost;
the preview should expose both rather than choose an accounting policy silently.

## Related

- [ADR 0033](0033-item-suppliers-relationship.md) — supplier relationship and the earlier
  deliberate deferral of standing price tables
- [ADR 0050](0050-item-specific-uom-conversion-and-divisibility.md) — item-owned conversion and
  quantity precision beneath commercial packaging
- [Material resolution](../domain/material-resolution.md)
- [Purchasing](../domain/purchasing.md)
- [Units of measure](../domain/units-of-measure.md)
- [Q17–Q19 and Q25](../OPEN-QUESTIONS.md)
