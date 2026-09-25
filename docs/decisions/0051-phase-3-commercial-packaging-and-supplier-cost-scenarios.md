# 0051 — Add transparent supplier-cost scenarios; defer sourcing optimisation

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

### 2. Phase 3 calculates independent, explainable coverage scenarios, not an allocation plan

For each applicable offer independently, preview may calculate:

```text
technical/standard requirement
  → explicit planned requirement
  → supplier/package coverage quantity
  → expected surplus
  → normalised unit cost
  → estimated consumption cost
  → procurement cash outlay
```

The output must keep these values separate. If 6.5 L is required and a supplier sells a 5 L pack,
two packs cover 10 L and leave 3.5 L expected surplus. The estimate's material consumption cost is
normally 6.5 L multiplied by the normalised unit cost; the procurement outlay is the price of two
packs. The job is not silently charged for all 10 L merely because Purchasing must buy it. A later
explicit policy may treat non-reusable residual as job cost, but Phase 3 must not assume that.

The resolver lists eligible scenarios and their provenance/warnings. It may let the user inspect or
select one scenario, but it does **not**:

- combine packages or split demand across suppliers;
- rank a winner by a hidden weighted score;
- subtract on-hand stock or open orders;
- promise availability from lead time alone;
- create a PO, reserve stock, choose a warehouse/location/lot/container, or post anything.

Therefore [Q19](../OPEN-QUESTIONS.md#q19--default-ranking-for-source-and-pack-proposals) is not a
Phase 3 blocker under this proposal. It remains a later supply-planning decision for automatic
ranking/optimisation. Phase 3 uses a deterministic display order only, never labels that order
“best”.

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
- **Positive:** ADR 0050 remains the single conversion/divisibility mechanism; commercial package
  identity adds meaning without duplicating its factor.
- **Positive:** The design can later feed sourcing/planning without embedding a premature optimiser
  in Estimating.
- **Negative:** Phase 3 gains master-data and Purchasing foundation beyond the preview screen
  itself, including effective-dated prices and package associations.
- **Negative:** Users may see several valid scenarios without an automatic “best” answer until the
  later Q19 policy is decided.
- **Requires:** Explicit permissions and API field filtering under Q25; supplier prices and
  internal costs cannot become visible merely because a user can run a quantity preview.
- **Requires:** Acceptance-time constraints for effective-date/quantity-break overlap, currency/FX
  resolution, package archival, and deterministic scenario ordering.

## Alternatives considered

**Stop Phase 3 at planned quantity plus AVCO** — rejected as the recommendation. It is the smallest
slice, but a new or unstocked purchased material has no useful AVCO and would force zero-cost or
manual estimates precisely where supplier evidence is most valuable.

**Build a full cheapest/least-surplus/fastest multi-source optimiser in Phase 3** — rejected. That
requires a product policy for price versus surplus versus preference versus lead time, plus dated
availability and stock/open-order netting. Mature products place that work in supply planning; it
also crosses Phase 3's no-reservation boundary.

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
