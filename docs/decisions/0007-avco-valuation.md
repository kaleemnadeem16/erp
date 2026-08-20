[← Decisions](README.md)

# 0007 — AVCO stock valuation, not FIFO

- **Status:** superseded by [0032](0032-per-item-valuation-method.md)
- **Date:** 2026-07-29
- **Affects:** Phase 2 (stock), Phase 3 (costing), GL postings

## Context

Stock valuation method determines the cost used in every formulation roll-up and every material
issue, and therefore flows into COGS and job variance. It is deeply embedded and expensive to
change once there is transaction history.

The reference business is formulation-based: ingredients are drawn from bulk containers and
mixed. Physical identity of a specific receipt is not preserved and cannot be observed.

## Decision

Weighted average cost (AVCO), maintained per item.

Goods receipts are still recorded individually as `stock_layers` (quantity, unit cost,
currency, date) for traceability and auditability, but issues are costed at the running
average.

## Consequences

- **Positive:** Matches physical reality. FIFO layer tracking on a drum of pigment describes
  something that does not happen.
- **Positive:** Substantially simpler — no layer consumption logic, no partial-layer
  arithmetic on every issue.
- **Positive:** Easier to keep MySQL-portable ([0003](0003-postgres-with-mysql-compatibility.md));
  FIFO consumption tends to invite engine-specific locking and window-function tricks.
- **Negative:** Less precise cost attribution when prices move sharply. Accepted.
- **Negative:** Some tax jurisdictions mandate FIFO. **Not verified for Pakistan** — if it turns
  out to be required, this ADR must be superseded before Phase 2 ships. Retaining `stock_layers`
  keeps that door open.
- **Requires:** average cost recalculated on every receipt, and the recalculation must be
  transactional and correct under concurrency.

## Alternatives considered

**FIFO** — better cost attribution under inflation and required in some jurisdictions. Rejected
as fiction for a mixing process, and materially more complex. Reconsider if a tax requirement
emerges.

**Standard costing with variances** — common in mature manufacturing. Rejected as far too much
process overhead: it requires periodic standard-setting and variance analysis discipline that a
business of this size will not sustain.

**Specific identification** — appropriate only for serialised or batch-critical stock. Rejected
as inapplicable to bulk ingredients; batch tracking remains available per item where genuinely
needed.

## Related

- [domain/stock.md](../domain/stock.md)
- [domain/formulations.md](../domain/formulations.md)
