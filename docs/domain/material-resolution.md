[← Domain index](README.md)

# Material resolution across estimating, inventory and execution

This is the cross-module boundary for turning technical material requirements into plans. Detailed
package tracking and proposal rules live in [inventory-packages.md](inventory-packages.md).

## The quantities are different facts

```text
technical requirement → transaction plan → inventory proposal → physical execution
        standard             planned          recommended             actual
```

A formulation may require 6.5 L. An estimator may plan 7 L, select a mix that presents 8 L, and
the job may consume 6.8 L. None of those values replaces another. Customer selling price is also
independent from internal material cost.

| State | Meaning | Persistence boundary |
|---|---|---|
| Standard requirement | Calculated from active technical master data | Reproducible preview; snapshot on document issue |
| Planned adjustment | Transaction quantity deliberately chosen by the user | Immutable adoption evaluation under ADR 0054; snapshot with the draft/revision |
| Inventory proposal | Explainable default from currently usable internal stock | Ephemeral and advisory |
| Selected material plan | User-adopted package/balance/container mix | Draft/revision; snapshot on issue/approval |
| Reserved/issued/returned/scrapped | Physical execution against work | Phase 5 stock and job facts |
| Actual consumption | Net physical consumption, with scrap separately visible | Derived auditable execution fact |

## Current foundations

- Every item has one stock UoM. [ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md)
  defines fixed item-specific conversion and quantity precision but is not implemented yet.
- Global UoM conversions handle litre↔millilitre or kilogram↔gram. Item-specific conversions handle
  each↔box, drum↔litre, roll↔metre and similar relationships.
- Stock owns on-hand quantity, warehouse/location, valuation, lots/batches and partial layer issue.
- A lot is traceability identity, not an individual physical container.
- Formulations determine technical composition. Package selection happens downstream and never
  changes the formulation.
- Assembly roll-up currently obtains material cost from supported valuation/formulation/manual
  sources. FIFO/SPECIFIC and unsupported AVCO pool contexts fail rather than guess.

## Accepted Phase 3 boundary

[ADR 0051](../decisions/0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md)
decides that Phase 3 proposes package use from the company's own inventory. It may mix open
remnants and sealed package sizes and lets the user choose a valid alternative.

The preview calculates and explains:

1. standard requirement and calculation provenance;
2. explicit planned adjustment;
3. usable internal inventory as of a stated time;
4. the effective package-selection strategy and its rationale;
5. recommended mix, useful alternatives, coverage, shortage and expected residual;
6. estimated internal cost and its evidence;
7. limitations caused by aggregate or incomplete tracking.

It does not reserve stock, select a supplier, claim supplier stock, create a purchase order, post a
movement or calculate the customer's final selling price.

Purchasing begins with the uncovered shortage. It may later use supplier packages, prices, MOQ and
order multiples to propose replenishment. That is not part of the Phase 3 inventory proposal.

## Physical precision and selection policy

[ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md) provides three progressive
modes:

- `QUANTITY_ONLY` — aggregate quantity and approximate package equivalents;
- `PACKAGE_BALANCE` — sealed package counts plus grouped open/loose quantity;
- `CONTAINER_TRACKED` — each physical container and its remaining quantity/status.

[ADR 0053](../decisions/0053-explainable-inventory-package-selection-presets.md) provides three
initial strategies:

- `MINIMISE_WASTE` (company default);
- `LOWEST_INTERNAL_COST`;
- `FEWEST_PACKAGES`.

Company, category and item settings select the applicable behaviour. Details, conceptual state
shapes, transition rules and deterministic tie-breakers are in
[inventory-packages.md](inventory-packages.md).

## Cost, price and availability

- Internal inventory cost is evidence for estimating, not customer price.
- Supplier purchase price and procurement cash outlay are Purchasing evidence, not substitutes for
  current inventory valuation.
- Missing cost is never presented as zero.
- Availability is advisory in Phase 3. The response must state its as-of time.
- An adopted plan can be stale before execution; Phase 5 revalidates and reserves it.
- An issued quotation snapshots the adopted material/cost evidence so later policy or master-data
  changes do not rewrite history.

## Planned-variance control

[ADR 0054](../decisions/0054-risk-based-material-plan-variance-controls.md) audits every adopted
standard-to-planned difference without recording every draft keystroke. Versioned policy resolves
item → nearest category → company and applies the strongest matching effect:

```text
AUDIT_ONLY < REASON_REQUIRED < APPROVAL_REQUIRED < BLOCKED
```

Quantity, percentage, internal-cost, residual/waste and controlled-item conditions remain typed
measures rather than one opaque score. A different valid package mix at the same planned quantity
normally needs no reason unless its cost/waste consequence crosses a configured band. Material
changes after approval are re-evaluated and cannot inherit an old decision.

## Authorisation impact

Material resolution has independently sensitive actions and fields. Q25 will decide the first
registered vocabulary for running preview, viewing internal cost, changing planned quantity,
selecting a package plan and overriding cost/price. Permission to edit is not approval, and
organisational record scope remains Q26.

Every implemented action must complete the
[authorisation checklist](../architecture/authorization.md#per-feature-authorisation-checklist),
including backend enforcement, field filtering, frontend exposure, registration and tests.

## Rounding boundaries

These remain distinct policies:

- conversion precision — stored factors and high-precision intermediate arithmetic;
- quantity precision/divisibility — item/UoM rule;
- package selection — whole sealed-pack counts plus measurable remnants;
- supplier order rounding — future Purchasing package/order rule;
- consumption measurement — execution rule;
- unit-cost precision — accounting/money rule;
- selling-price and document rounding — Sales/Finance rules.

Calculations must not round early merely for display. Each material rounding event identifies its
policy and retains enough pre-/post-rounding evidence to explain the result.

## Known traps

- Treating a package as a global UoM corrupts item-specific conversion semantics.
- Inferring sealed packages from aggregate stock invents physical facts.
- Treating a batch as one container loses individual remnants.
- Letting formulation depend on package size mixes technical standards with allocation.
- Treating a package proposal as a reservation creates false availability confidence.
- Using supplier prices for the inventory proposal mixes replenishment with internal allocation.
- Coupling customer price to internal cost prevents independent Sales strategies.
- Treating catch weight as a variable conversion cannot record actual per-container content; Q23
  needs a separate dual-quantity design.

## Open decisions

- [Q21](../OPEN-QUESTIONS.md#q21--phase-4-selling-price-strategy-baseline) — customer selling-price
  strategies.
- [Q25](../OPEN-QUESTIONS.md#q25--material-resolution-permissions-and-sensitive-fields) — action
  permissions and sensitive fields.
- Q23/Q24 — variable-content packages and execution exceptions.
- Q26–Q28 — organisational scope and approval workflows.

## Research ledger

Primary sources used to validate the boundaries:

- [Odoo: UoM, packaging and physical packages](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/configure.html)
- [Odoo: removal strategies](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies.html)
- [SAP: Handling Units](https://help.sap.com/docs/SAP_ERP/39d6d7a325d04826b95328b25fad91ab/eee9c353b677b44ce10000000a174cb4.html)
- [Dynamics 365: on-hand by inventory dimensions/license plate](https://learn.microsoft.com/en-us/dynamics365/supply-chain/inventory/inventory-on-hand-list)
- [Dynamics 365: location directives](https://learn.microsoft.com/en-us/dynamics365/supply-chain/warehousing/create-location-directive)
- [Oracle WMS: inventory by LPN](https://docs.oracle.com/en/cloud/saas/warehouse-management/25b/owmim/inventory-management-modules.html)

## Related

- [Inventory packages](inventory-packages.md) · [Units of measure](units-of-measure.md)
- [Items](items.md) · [Stock](stock.md) · [Purchasing](purchasing.md)
- [Assemblies](assemblies.md) · [Sales pipeline](sales-pipeline.md) · [Job costing](job-costing.md)
- [Phase 3](../phases/phase-3-estimating-core.md)
- [ADR 0054](../decisions/0054-risk-based-material-plan-variance-controls.md)
