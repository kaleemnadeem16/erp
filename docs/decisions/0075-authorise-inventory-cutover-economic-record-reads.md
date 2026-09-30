[← Decisions](README.md)

# 0075 — Authorise Inventory cutover economic-record reads

- **Status:** accepted
- **Date:** 2026-09-30
- **Affects:** Inventory cutover list/detail/preview, negative-opening evidence, SPA controls

## Context

The cutover header, attached batches, preview and negative-opening exceptions contain source/control values, location valuations, provisional costs and transformation evidence. Their list, detail and preview APIs previously admitted any authenticated company user. Cutover validation and independent approval must reconcile the actual quantity **and value** against immutable predecessor-system facts; hiding the monetary half from those actors would make the control unreviewable. Generic stock readers have no reason to see the migration ledger.

## Decision

- Treat the cutover, its preview and included batches/transitions/negative openings as one economic migration record. List, detail and preview require at least one Inventory cutover duty (`create`, `stage`, `validate`, `approve`, `commit`, `finalise`, `negative_opening.request`, `negative_opening.approve`) or `inventory.valuation.view`. An unrelated authenticated user receives 403. Company scoping still applies first to record identity.
- A cutover-duty holder sees the complete monetary evidence needed to prepare, reconcile or approve opening balances. `inventory.valuation.view` grants read-only audit access, not a lifecycle action. Neither `purchasing.cutover.commit` alone nor a generic stock-operation permission grants the Inventory migration ledger. The Purchasing open-PO action remains separately authorised.
- Every mutation continues to use its existing Action and maker/checker guards. The SPA lets valuation-only users inspect list/detail/preview but hides New and each action without its specific capability, including staged batch attachment and negative-opening actions; a bookmarked New URL is blocked.
- Source facts remain non-includable in the header API because they can be unbounded; Imports has no general HTTP read surface yet. Any future paginated source-fact endpoint or export must use this same economic-record read boundary. This does not claim that all Inventory workflow costs are protected.

## Alternatives considered

**Project a quantity-only cutover to everyone** — rejected: a cutover is a controlled economic migration, not an operational stock lookup, and its transformation decisions can themselves contain monetary values.

**Require `inventory.valuation.view` in addition to every cutover duty** — rejected: validation and approval authority without the source/control value evidence would be unsafe, and would impose a second easily missed assignment on each cutover role.

**Only hide the UI sections** — rejected: the API preview and included resources would still expose the values.

## Research basis

This applies existing documented research and decisions rather than repeating a market survey. [ADR 0036](0036-controlled-inventory-cutover.md) establishes staged source evidence and independent cutover approval; [ADR 0046](0046-strict-staged-inventory-cutover-reconciliation.md) requires exact quantity/value reconciliation; [ADR 0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md) records the current primary-source comparison on operational stock versus valuation access. No valuation method or cutover lifecycle is changed here.

## Related

- [Inventory cutover](../domain/inventory-cutover.md)
- [Authorisation retrofit](../architecture/authorization-retrofit.md)
- [ADR 0036](0036-controlled-inventory-cutover.md)
- [ADR 0046](0046-strict-staged-inventory-cutover-reconciliation.md)
- [ADR 0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md)
