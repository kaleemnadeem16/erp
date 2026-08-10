[← Decisions](README.md)

# 0023 — Retention/retainage support enabled

- **Status:** accepted
- **Date:** 2026-08-10
- **Affects:** Phase 6, [domain/receivables.md](../domain/receivables.md)

## Context

[Q1](../OPEN-QUESTIONS.md) asked whether customers withhold a percentage of each invoice (5–10%)
until a defect liability period ends — standard in contracting. The user confirmed real usage is
mixed within a **single company**: some customers are full-service contract work, invoiced and
paid off in stages, occasionally with retention; other customers are dealers/shops who buy
repeatedly against a running account with no retention, paying lump or partial amounts not tied
to one order.

Both cases are already the same underlying model
([ADR 0011](0011-double-entry-open-item-ar.md) — open-item AR, receipts allocate flexibly
against one or many invoices, unallocated amounts sit on account, and the customer statement is
a presentation over that data). Retention is an *additional*, independent, per-contract feature
on top of it, not a separate billing mode.

## Decision

Retention support is built now, not deferred. `retention_pct` is set per contract/invoice line,
**defaulting to 0%** (no retention) unless explicitly configured. A retained amount is invoiced
but excluded from normal aging until an explicit release event. This is orthogonal to how a
customer's payments are allocated — a customer can be on running-account allocation, invoice
matched allocation, or both, with or without retention, in any combination, all within the same
company.

## Consequences

- **Positive:** covers both the dealer/shop and the full-service contracting case with the
  schema and posting logic already anticipated in
  [receivables.md](../domain/receivables.md), no retrofit needed later.
- **Positive:** default-0 means customers/contracts that never use retention see no behaviour
  change.
- **Negative:** aging and the statement view must explicitly exclude the retained slice from
  "due," not just filter it at display time — real logic in the Action layer, not the UI.
- **Requires:** a defined retention release trigger/workflow (manual for now; Phase 6 scope) and
  the invariant that retention config lives on the contract/invoice, never assumed from the
  company or customer alone.

## Alternatives considered

**Defer the decision to Phase 6** — rejected. The docs already flagged that the schema is
near-free to reserve now but the posting/reporting logic is a painful retrofit; deciding while
nothing is built costs nothing.

**A company-wide retention setting** — rejected. Real usage doesn't correlate with the company,
it correlates with the individual contract — the same company runs both retention and
non-retention customers side by side.

## Related

- [domain/receivables.md](../domain/receivables.md)
- [ADR 0011 — Double-entry GL with open-item AR](0011-double-entry-open-item-ar.md)
- [Q1 — Retention / retainage](../OPEN-QUESTIONS.md) *(resolved, removed from open questions)*
