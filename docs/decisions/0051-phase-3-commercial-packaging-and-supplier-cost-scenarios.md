# 0051 — Use internal-inventory package proposals in Phase 3; keep Purchasing separate

- **Status:** accepted
- **Date:** 2026-09-26
- **Affects:** Phase 3 material resolution and assembly preview, item packaging, Inventory,
  Phase 4 quotation snapshots

## Context

Phase 3 must turn a calculated material requirement into a useful plan. If a job requires 6.5 L,
the company may have an open 3.5 L remnant, sealed 1 L and 5 L packs, or no usable stock at all.
The preview should recommend a practical combination while allowing the estimator to choose a
different one.

Earlier discussion mixed this need with supplier selection and purchase-price optimisation. That
is a different process. A supplier catalogue or price proves neither current supplier stock nor
which physical packages the company already owns. Purchasing decides how to replenish a shortage;
Phase 3 first explains how current internal inventory could cover the requirement.

[ADR 0050](0050-item-specific-uom-conversion-and-divisibility.md) supplies the fixed item-specific
quantity conversion beneath package definitions. The existing stock ledger records quantities and
value, but it cannot infer physical packs from an aggregate balance: after receiving three 5 L
packs and issuing 7 L, an 8 L balance does not reveal whether one sealed 5 L pack and a 3 L remnant
remain. [ADR 0052](0052-progressive-inventory-package-tracking.md) therefore defines progressive
package tracking, and [ADR 0053](0053-explainable-inventory-package-selection-presets.md) defines
the recommendation policy.

Industry systems make the same conceptual distinctions. [Odoo separates product packaging from
physical packages](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/configure.html),
while SAP models a handling unit as an identifiable physical packaging unit with contents
([SAP Handling Units](https://help.sap.com/docs/SAP_ERP/39d6d7a325d04826b95328b25fad91ab/eee9c353b677b44ce10000000a174cb4.html)).

## Decision

### 1. Phase 3 proposes from internal inventory

Phase 3 will calculate an advisory material-package proposal from usable on-hand inventory. It may
combine package sizes and open remnants. For a 6.5 L requirement it might recommend a 3.5 L open
remnant plus three sealed 1 L packs, or one 5 L pack plus two 1 L packs, depending on the selected
policy and what is actually available.

The preview must show:

- the standard requirement and any explicit planned adjustment;
- which internal stock/package records are usable as of a stated time;
- the recommended mix, alternatives, covered quantity, shortage and expected residual;
- the internal inventory cost evidence, separately from customer selling price;
- why the recommendation won and any limitations caused by the current tracking mode.

The user may replace the recommendation with another valid available mix. The recommendation is
never silently treated as the user's decision.

### 2. Package identity is item master data; package state is Inventory data

A fixed package definition is layered on ADR 0050's item-specific quantity conversion:

```text
item_packagings
  id, company_id, item_id, item_quantity_unit_id
  code, name, barcode nullable, active
```

This is a named kind of package such as “5 L can”, not a physical can. Inventory package-balance
or container records identify what the company owns and how much remains. Lot/batch identity,
package definition and physical container identity remain separate facts.

First-generation definitions have fixed content. Variable-content/catch-weight packages remain
[Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages).

### 3. Purchasing starts at the shortage boundary

If internal inventory cannot cover the requirement, Phase 3 reports the uncovered quantity and
the unavailable package choices. It does not select a supplier, claim supplier availability,
create a purchase order or optimise procurement cash outlay.

Supplier/package offers, effective-dated purchase prices, order multiples and replenishment
recommendations remain Purchasing capabilities. They may later consume the shortage produced by
material resolution, without changing the meaning of the Phase 3 inventory proposal.

### 4. The proposal is advisory until an execution workflow reserves stock

Running or changing a preview creates no reservation and posts no stock movement. Concurrent work
may consume the same stock, so every preview carries an availability as-of time and warns that the
result is advisory.

A user-adopted plan may be stored with a draft quotation revision. Issue or approval snapshots the
selected package plan, recommendation policy/version, cost evidence and availability evidence so
later master-data changes do not rewrite history. Phase 5 owns reservation, allocation, issue,
return and scrap.

### 5. Cost and price remain independent

The package proposal compares internal inventory cost using the applicable valuation evidence; it
does not use customer selling price. A cheap package mix for the company does not dictate what the
customer is charged. [Q21](../OPEN-QUESTIONS.md#q21--phase-4-selling-price-strategy-baseline)
decides the Sales pricing baseline.

Who may view costs, select a plan or override it remains
[Q25](../OPEN-QUESTIONS.md#q25--material-resolution-permissions-and-sensitive-fields). Whether a
changed planned quantity or package choice requires a reason remains
[Q18](../OPEN-QUESTIONS.md#q18--audit-requirements-for-planned-quantity-adjustments).

## Consequences

- A small company can use quantity-only stock and receive an explicitly approximate proposal.
- A company tracking sealed/open balances or individual containers gets increasingly exact
  choices without using a different inventory ledger.
- Mixed package sizes and user selection are first-class behaviour.
- Purchasing can later plan the reported shortage, but supplier data is not required to deliver
  the Phase 3 inventory feature.
- Internal cost, procurement outlay and customer price cannot be collapsed into one amount.
- The proposal engine must tolerate concurrent inventory changes and revalidate before a later
  reservation or issue.

## Alternatives considered

**Optimise supplier offers in Phase 3** — rejected. It combines an internal allocation question
with replenishment and would imply supplier availability that the system does not know.

**Use aggregate quantity only for every company** — rejected. It cannot distinguish sealed packs
from remnants and cannot truthfully recommend exact physical packages.

**Require individual-container tracking for every company** — rejected. It imposes scanning and
operational overhead that many small companies do not need; ADR 0052 provides progressive modes.

**Force one package size per requirement** — rejected. Valid stock may be split across sizes, and
the user explicitly needs mixed-pack selection with a replaceable default.

**Treat a package as a global UoM or a lot** — rejected. Package quantity is item-specific, while
a lot groups traceability and may contain multiple physical packages.

## Related

- [ADR 0033](0033-item-suppliers-relationship.md) — supplier relationships, outside this proposal
- [ADR 0050](0050-item-specific-uom-conversion-and-divisibility.md) — fixed item-specific quantity
  conversion beneath package definitions
- [ADR 0052](0052-progressive-inventory-package-tracking.md) — progressive package state
- [ADR 0053](0053-explainable-inventory-package-selection-presets.md) — recommendation policies
- [Inventory packages](../domain/inventory-packages.md)
- [Material resolution](../domain/material-resolution.md)
