[← Decisions](README.md)

# 0038 — Maintain one company-wide AVCO pool per item

- **Status:** superseded by [0039](0039-configurable-avco-valuation-pools.md)
- **Date:** 2026-09-01
- **Affects:** Phase 2 Inventory, stock valuation, negative-stock recovery, inventory cutover,
  backdated-receipt replay and Phase 6 Finance

## Context

[ADR 0032](0032-per-item-valuation-method.md) selects AVCO, FIFO or SPECIFIC per item but does not
define the geographic scope of an AVCO pool. Physical availability is location-specific under
[ADR 0037](0037-controlled-negative-stock-overrides.md), yet this does not require each location
to calculate a separate official average. Leaving the pool implicit would make transfers,
negative recovery, cutover reconciliation and backdated replay ambiguous.

## Decision

The only supported official AVCO scope is `COMPANY_WIDE_PER_ITEM`: each company maintains one
quantity, value and average-cost pool for each AVCO-valued item across all its warehouses and
locations. Physical quantities remain exact-location records and independently auditable;
physical availability and valuation scope are separate concepts.

An internal transfer within the company changes custody/location but preserves company-item
quantity, value and official AVCO. It does not create another pool or cause an AVCO change merely
because stock moved between the company's locations.

The non-positive-pool recovery rule in ADR 0037 applies to the company-item total. A negative
location does not freeze AVCO while the company-item pool remains positive. Only when that pool
reaches zero or becomes negative does the system retain the last valid average solely as a
provisional reference and suspend weighted-average mathematics. When supported inbound quantity
makes the pool positive again, shortages and linked adjustments are applied first; the new AVCO
uses only genuine positive remainder and excludes exhausted pre-negative inventory.

Cutover reconciles both exact-location quantities and company-item AVCO quantity/value. An
individual negative location does not make the AVCO pool negative; special negative-pool opening
and recovery treatment applies only when the company-item pool itself is non-positive.

For a conflicting backdated AVCO receipt, conflict detection and authorised forward replay cover
later movements of that item across the company from the proposed valuation-effective timestamp.
Replay may stop only at deterministic AVCO convergence. This does not make FIFO company-wide:
FIFO follows affected layers and downstream movement lineage, while SPECIFIC follows the exact
serial chain.

AVCO scope is not an ordinary company or item setting. Introducing another scope later requires a
new decision and a controlled valuation migration/cutover covering current average, stock value,
transfers, negative recovery, replay, opening balances and Finance reconciliation.

## Consequences

- **Positive:** Internal transfers do not manufacture valuation changes within one legal entity.
- **Positive:** One company-item average is straightforward to reconcile with total company stock
  value while location quantities remain operationally exact.
- **Positive:** Negative-pool recovery and backdated AVCO replay have an explicit deterministic
  boundary.
- **Negative:** A cost change at one location affects the official AVCO used at every company
  location for that item.
- **Negative:** The system cannot currently support a company that is legally or operationally
  required to maintain warehouse-specific average costs.
- **Requires:** `item_average_costs` is unique by company + item and updated with transactional row
  locking across receipts from all company locations.
- **Requires:** Reports explain that location quantity and company-item average are deliberately
  different scopes.

## Alternatives considered

**AVCO per warehouse** — rejected without a concrete requirement. It would make inter-warehouse
transfers valuation events and add pool-specific reconciliation and negative-recovery complexity.

**AVCO per location** — rejected because moving stock between bins would create artificial cost
changes and fragment one company's official item value.

**Configurable pooling** — rejected as speculative. Changing a live pool boundary is a valuation
migration, not a safe settings toggle.

**Infer the pool from physical availability scope** — rejected because custody and accounting
valuation answer different questions.

## Related

- [ADR 0032 — Per-item valuation method](0032-per-item-valuation-method.md)
- [ADR 0037 — Controlled negative-stock overrides](0037-controlled-negative-stock-overrides.md)
- [Stock](../domain/stock.md)
- [Inventory cutover](../domain/inventory-cutover.md)
- [Phase 2 — Purchasing & stock](../phases/phase-2-purchasing-stock.md)
