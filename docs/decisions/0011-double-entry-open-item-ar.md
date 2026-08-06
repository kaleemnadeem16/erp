[← Decisions](README.md)

# 0011 — Double-entry GL with open-item AR

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** Phase 6, and any module that touches money

## Context

The requirement: a customer "opens a ledger", work is done, multiple invoices are issued over
time as the job progresses, and payments arrive against a running balance.

Two independent decisions follow: how the general ledger works, and how receivables are
tracked.

## Decision

**Double-entry general ledger from the start.** Every financial event posts a balanced journal
entry. Journal entries are immutable once posted; corrections are reversals.

**Open-item accounts receivable.** Each invoice is tracked individually; receipts are allocated
against specific invoices, with partial allocation supported. Unallocated amounts are advances
held on account. The running-balance *statement* is a presentation over this data, not the
storage model.

## Consequences

- **Positive:** Aging (0–30/31–60/61–90/90+) falls out for free, and is impossible under
  balance-forward — you cannot tell *which* invoice is overdue.
- **Positive:** Disputes are resolvable. "Which invoice does this payment cover?" has an answer.
- **Positive:** Job costing, stock valuation and AR reconcile against one ledger rather than
  drifting apart.
- **Negative:** Every module that touches money must post correct journal entries. Higher bar
  than incrementing a balance column.
- **Negative:** Allocation is genuinely fiddly — partial payments, credit notes, advances, and
  FX differences ([0012](0012-multi-currency-dual-amounts.md)) all interact.
- **Requires:** accounting periods with open/closed state; posting into a closed period is
  rejected. Advances are held as a **liability**, not revenue, until applied.

## Alternatives considered

**Balance columns on the customer record** — simplest possible. Rejected: no aging, no audit
trail, no dispute resolution, and balances drift out of agreement with reality with no way to
detect it.

**Balance-forward AR** — a running total with no invoice-level tracking. Rejected for the same
reasons; it is the model this requirement superficially resembles but is not.

**Single-entry with a reporting layer** — rejected. Retrofitting double-entry onto a system that
tracked balances as columns is a rewrite, not a migration. This is the classic ERP mistake and
it is cheap to avoid now.

**Defer accounting to an external system** (integrate with existing software) — reasonable for
some products. Rejected because job costing requires material issues, labour and overhead to
post to WIP as they happen; an external boundary would make variance reporting depend on a
sync.

## Related

- [domain/general-ledger.md](../domain/general-ledger.md)
- [domain/receivables.md](../domain/receivables.md)
- [0012 — Multi-currency](0012-multi-currency-dual-amounts.md)
