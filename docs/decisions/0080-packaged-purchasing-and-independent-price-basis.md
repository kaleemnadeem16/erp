# 0080 — Separate package identity, supplier offers and purchasing price basis

- **Status:** accepted
- **Date:** 2026-10-02
- **Affects:** Item master, Purchasing, receipts; extends ADRs 0033 and 0050–0053

## Context

The owner approved the small-company purchasing scope after the repository reconciliation:
distinct packages, several supplier choices, loose purchasing, independent pricing, partial
receipts and immutable agreements. Physical package balances and containers stay later capabilities.
Two boxes containing 20 × 0.1 L and 2 × 1 L have equal content but different identity.
The existing supplier relationship is unique per item/supplier, and receipt conversion currently
does not support the item-specific conversion used by purchase orders.

## Decision

Package definitions are item-owned fixed-content identities with a direct stock-UoM factor,
package quantity increment, barcode and optional same-item inner definition/count. They do not
require invented generic UoMs. The direct package factor is the single package conversion authority;
item quantity units remain the independent alternate-UoM conversion authority of ADR 0050.
This refines ADR 0051's conceptual quantity-unit linkage; all its ownership and advisory rules remain.
Inner relationships explain packaging and must be acyclic and consistent; never traverse them to
calculate transaction quantity. Used package content/identity is immutable: archive and create a
replacement. Package metadata cannot rewrite document snapshots.

Supplier offers belong beneath the existing item/supplier relationship. Each identifies a package
or loose purchase UoM, SKU, active/default choice, MOQ, zero-origin order multiple, lead time,
validity dates and a price/currency/basis. First delivery has one current price per offer; changing
it affects future drafts only. Price can be per selected package or per independently convertible
UoM, with an explicit denominator (e.g. per 100 units). Effective price tiers remain deferred.
Relationship approval/active status is reused; do not add an offer approval engine.

Order quantity, stock quantity and priced quantity are distinct. Draft save resolves and snapshots
the package/offer, labels, factor, price basis and terms. Submission freezes that revision under
the shared PO approval process; issue must not silently refresh it. Later master changes do not
change the agreement. A supplier's inability to fulfil it is handled by existing cancellation or
short-close plus an explicitly linked replacement order, preserving both agreements.

MOQ/multiples are evaluated per line in its order basis. The first version warns and requires a
recorded reason to continue; the PO creator is authorised to give that reason. They are not
conversion/increment rules. Whole/fractional package increments and stock increments are hard
quantity validation, with no silent rounding. Future allow/block/approval policy uses the shared
policy/approval architecture and is not implemented here.

Receipts retain actual package/count and stock quantity. Receiving in the agreed basis uses the
issued factor. Alternate loose units use item conversion and price normalisation. Provisional
gross product amounts derive from the issued price basis, with exact totals passed to Inventory;
discount/tax acquisition allocation remains its separate future capability. No unopened-on-hand
claims are made in QUANTITY_ONLY. Actual varying content remains Q23, not a fixed factor.

Conversion maintenance uses existing `inventory.item.manage`, enforced in Requests and Actions.
Package maintenance uses the same capability; supplier offers use existing Purchasing supplier
read/manage and commercial visibility capabilities. No new role or permission rollout is needed.

## Consequences

- **Positive:** simple box/loose buying works without container administration; equal-content
  packages stay distinct; physical tracking can add evidence later.
- **Negative:** additional masters and snapshots; receipt pricing must be normalised explicitly.
- **Requires:** company-scoped references, decimal arithmetic, non-HTTP authorisation tests,
  immutable issued evidence and partial-receipt/reversal reconciliation.

## Alternatives considered

Generic BOX UoMs as identities — rejected: one item can have different boxes with equal quantity.
Chained factors — rejected under ADR 0050. Mutable historical master references — rejected.
Prices on `item_suppliers` — rejected under ADR 0033; child offers hold commercial choices.
Automatic alteration after supplier changes — rejected: replacement requires explicit agreement.
Mandatory scanning/container tracking — deferred under ADR 0052.

## Research basis

Reused ADRs 0050–0053. Primary comparison accessed 2026-10-02: Odoo packaging/vendor pricing,
Dynamics ordering controls and independent commercial price lookup, Oracle supplier agreements
and dual quantities. They converge on separating quantity, price applicability and commercial
constraints, but differ in agreement/planning complexity. This ERP chooses explicit offers and
warn-with-reason, not a procurement agreement engine or automatic price conversion.

- [Odoo packaging](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/configure/packaging.html)
- [Odoo vendor prices](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/purchase/products/pricelist.html)
- [Dynamics ordering controls](https://learn.microsoft.com/en-us/dynamics365/supply-chain/production-control/default-order-settings)
- [Dynamics PO price lookup](https://learn.microsoft.com/en-us/troubleshoot/dynamics-365/supply-chain/procurement/po-unit-prices-not-calculated)
- [Oracle dual quantities](https://docs.oracle.com/en/cloud/saas/supply-chain-and-manufacturing/26b/fauom/track-items-in-more-than-one-unit-of-measure.html)

## Related

- [Item/Purchasing delivery plan](../phases/item-purchasing-improvements.md)
- [Inventory packages](../domain/inventory-packages.md)
- [Purchasing](../domain/purchasing.md)
- [ADR 0033](0033-item-suppliers-relationship.md)
- [ADR 0050](0050-item-specific-uom-conversion-and-divisibility.md)
- [ADR 0051](0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md)
