[← Decisions](README.md)

# 0035 — Separate purchasing operational and financial facts

- **Status:** accepted
- **Date:** 2026-08-31
- **Affects:** Phase 2 Purchasing and Inventory, Phase 6 Finance,
  [purchasing.md](../domain/purchasing.md), [acquisition-costing.md](../domain/acquisition-costing.md)

## Context

A purchase is not one event. The order records what was agreed, the goods receipt records what
physically arrived, inspection records what is usable, the supplier invoice records what the
supplier claims, and acquisition costing determines what the inventory ultimately cost. These
events can occur in a different order, in parts, and with different quantities or prices.

Treating any one document as all of those facts creates serious errors: an invoice can fabricate
stock, a late invoice can overwrite receipt history, QA can be confused with physical location,
or matching document totals can hide opposing line variances.

## Decision

Model procurement, receiving, quality, accounts payable and acquisition costing as related but
separate concerns. The normal UI remains `PO → Receive → Invoice → Done`, but each step creates
its own auditable fact and exceptions expose the additional controls only when needed.

Specifically:

- Only inventory transactions change physical quantity. A supplier invoice never creates stock,
  including when a future quick-purchase screen submits a receipt and invoice together.
- Posted receipts, invoices, stock movements and cost adjustments are immutable. Corrections use
  explicit reversals, credits or adjustment events.
- Receipt, inspection and invoice entry may proceed independently. Matching brings their facts
  together for approval; it does not merge or overwrite them.
- Matching is line- and allocated-quantity-based. Three-way matching compares PO, receipt and
  invoice; configurable four-way matching also limits approval to accepted inspection quantity.
  Requiring QA and requiring QA acceptance for payment are independent policies.
- Physical location, QA state and operational availability are separate. Availability is derived
  from QA state, holds and policy wherever possible rather than being freely editable.
- Provisional estimates, actual acquisition-cost components and resulting adjustments are
  retained separately. An actual amount changes effective cost but never overwrites its estimate.
- Product invoices and landed-cost documents are separate sources. V1 landed-cost allocation
  supports quantity, value and manual allocation with deterministic remainder rounding.
- Issued/posted documents snapshot quantities, UoM conversions, monetary amounts, currencies and
  FX rates. Multiple and partial receipts and invoices are normal, not exceptions.

Phase 2 owns purchase orders, goods receipts, inventory movements, provisional valuation and the
data contract required by later matching/cost adjustment. Phase 6 owns supplier invoices,
payables, payment holds/approval, balanced GL posting and the completed matching workflow.

## Consequences

- **Positive:** Physical, quality and financial records remain independently reconcilable and no
  late document silently rewrites another kind of fact.
- **Positive:** A simple local purchase stays simple while partial receipts, QA, invoice variances
  and landed costs have a coherent path when they occur.
- **Positive:** Three-way and four-way matching can be selected by policy without forcing every
  inspected purchase into four-way matching.
- **Negative:** One purchase may create several linked documents and allocation rows instead of a
  single mutable record.
- **Negative:** Reversals and corrections require dependency checks once stock has been
  transferred, consumed, matched or posted financially.
- **Requires:** Exact reconciliation of allocated quantities and costs, immutable source links,
  deterministic rounding, company scoping and idempotent posting Actions.
- **Requires:** Separate decisions for negative stock, backdating/closed periods, tolerance and
  approval policy, and valuation-method-specific treatment of later cost differences.

## Alternatives considered

**Let the supplier invoice receive stock** — rejected. It turns a financial claim into physical
truth and can duplicate a receipt. A convenience UI may create both documents atomically, but the
underlying events remain separate.

**One sequential process in which invoice entry waits for QA** — rejected. Invoices commonly
arrive while inspection is still in progress. They may be recorded and held for approval.

**Match document totals only** — rejected. Equal totals can conceal item-level quantity, UoM or
unit-price differences.

**Overwrite estimates when actual costs arrive** — rejected. It destroys the expected-versus-
actual variance and its audit trail.

**Implement every enterprise landed-cost and ownership option in V1** — rejected. Consignment,
weight and volume allocation remain deferred until real requirements and supporting master data
exist.

## Related

- [Purchasing](../domain/purchasing.md)
- [Acquisition costing](../domain/acquisition-costing.md)
- [Stock](../domain/stock.md)
- [ADR 0012](0012-multi-currency-dual-amounts.md)
- [ADR 0032](0032-per-item-valuation-method.md)

