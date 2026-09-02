[← Decisions](README.md)

# 0042 — Keep item-supplier relationship identity immutable

- **Status:** accepted
- **Date:** 2026-09-02
- **Affects:** Phase 2, Purchasing, [purchasing.md](../domain/purchasing.md)

## Context

ADR 0033 established `item_suppliers` as the relationship between one item and one supplier but
did not decide whether either endpoint could change later. Repointing an existing row would make
the same identifier mean a different commercial relationship and could make future purchase-order
or audit references appear to have always used the replacement item or supplier.

## Decision

`item_suppliers.item_id` and `supplier_account_id` are immutable after creation. Editable terms
remain on the relationship. A genuine endpoint change archives the old relationship and creates a
new one, preserving identity and history.

The HTTP update contract does not accept the identity fields, and the model rejects repointing
from non-HTTP write paths.

## Consequences

- **Positive:** Stable identifiers remain truthful when purchase documents reference sourcing.
- **Positive:** Historical supplier relationships remain available after replacement.
- **Negative:** Correcting an initially selected item or supplier requires archive-and-create.
- **Requires:** Imports must resolve the intended pair before creation and use an explicit
  correction workflow rather than rewriting a committed relationship.

## Alternatives considered

**Allow either endpoint to change** — rejected because it silently changes what an established
relationship identifier means.

**Create a version table for every terms edit** — rejected because ordinary SKU, lead-time, MOQ,
UoM and priority maintenance does not change relationship identity. Issued documents will retain
their own snapshots under invariant 1.

## Related

- [ADR 0033](0033-item-suppliers-relationship.md)
- [Purchasing](../domain/purchasing.md)
- [Architecture invariants](../architecture/invariants.md)
