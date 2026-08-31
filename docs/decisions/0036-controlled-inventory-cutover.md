[← Decisions](README.md)

# 0036 — Separate controlled inventory cutover from live stock operations

- **Status:** accepted
- **Date:** 2026-08-31
- **Affects:** Phase 2 Inventory, Phase 6 Finance, [inventory-cutover.md](../domain/inventory-cutover.md),
  [data-import.md](../architecture/data-import.md)

## Context

Companies adopting the ERP may bring years of inventory history from SAP, Dynamics, Odoo,
ERPNext, bespoke systems or spreadsheets. Source data ranges from a current quantity and value
to complete movements, FIFO layers, serial costs and open purchasing documents. It can also
contain facts that the new company's live policy would reject, including historical negative
balances and backdated receipts.

[ADR 0027](0027-generic-column-mapping-import.md) defines the reusable upload, mapping, staging,
validation and idempotency mechanism. It does not define how an inventory cutover establishes
official stock state, preserves source valuation, changes valuation method, or stops migration
exceptions becoming live-operational loopholes.

## Decision

Inventory onboarding uses ADR 0027's generic import engine, extended with an inventory-specific
cutover contract. It does not use a parallel importer and does not send historical rows through
normal live posting as if users had created them today.

Every company importing inventory has one explicit cutover timestamp and one approved mode:

- **Opening state:** quantity and carrying value at cutover, with the valuation state required
  to continue under the selected method.
- **Operational cutover:** opening state plus open documents such as outstanding purchase orders;
  open documents are imported as cutover records, not invented historical receipts or invoices.
- **Full history:** source movements and official source valuation are retained where the export
  is sufficiently complete, followed by a separately reconciled opening state at cutover.

The following are hard rules:

- Imports are staged, validated, previewed, explicitly approved, idempotent and auditable. They
  use dedicated cutover permissions and immutable batch/source identifiers.
- Source system, source record identity, source/effective/posting dates, valuation method,
  official quantity/value, currency/FX, UoM and batch/serial detail are retained when supplied.
- Imported official history is never silently revalued by the live engine. A different result
  may be reported as analytical replay, but cannot overwrite the source official value.
- A method change at cutover preserves pre-cutover facts under the source method and creates an
  explicit opening state for the new method; it never rewrites history to the new method.
- After cutover, normal Q13/Q14 controls apply to all operational posting. Migration exceptions
  are unavailable to ordinary APIs, users and reusable import templates.
- The cutover cannot be finalised until control totals reconcile by item/location and value, all
  exceptions are acknowledged, and the batch is frozen. Corrections after finalisation use
  normal auditable stock corrections, not reopening the import silently.

Valuation continuity is method-specific:

- **AVCO:** import quantity and carrying value; derive the continuing average from those values.
- **FIFO:** import remaining source layers where reliable. If only total quantity/value exists,
  create one explicitly labelled synthetic cutover layer; do not claim reconstructed FIFO age.
- **SPECIFIC:** each on-hand serial/unit and its carrying cost are required. Summarised quantity
  and value cannot establish valid SPECIFIC opening stock.

Historical negative balances may be preserved in full-history records. A negative balance at
the cutover opening state is an exception requiring reconciliation or explicit cutover approval;
whether approval is permitted is part of Q13. Historical backdating is preserved as source fact
and does not weaken the post-cutover Q14 policy.

## Consequences

- **Positive:** One core can onboard both new companies and migrations without weakening live
  stock controls or maintaining source-specific inventory engines.
- **Positive:** Official source history, the reconciled opening state and any analytical replay
  remain distinguishable and auditable.
- **Negative:** Inventory import is more than column mapping; it needs cutover orchestration,
  reconciliation, method-specific validation and immutable provenance.
- **Negative:** Full history may be refused or downgraded to opening state when source detail is
  incomplete or internally inconsistent.
- **Requires:** Phase 2 must make stock schema provenance-capable and provide method-specific
  opening-state Actions. The generic import engine itself remains shared cross-domain work.
- **Requires:** Phase 6 must reconcile the inventory cutover value with the opening GL entry and
  must not infer financial history from imported stock history.

## Alternatives considered

**Apply live Q13/Q14 rules to imported history** — rejected. It would reject legitimate source
facts or force history to be falsified before import.

**Let migration bypass normal validation through direct database writes** — rejected. It creates
an unaudited path around tenant, precision, reference and reconciliation controls.

**Build adapters or inventory engines per source ERP** — rejected. Source adapters may transform
exports into the generic staging contract, but official stock semantics remain one core model.

**Recalculate all imported history with the new engine** — rejected. That substitutes a
hypothetical result for the source system's official books and can silently change prior value.

## Related

- [ADR 0027](0027-generic-column-mapping-import.md)
- [ADR 0032](0032-per-item-valuation-method.md)
- [Inventory cutover](../domain/inventory-cutover.md)
- [Stock](../domain/stock.md)
- [Data import](../architecture/data-import.md)
