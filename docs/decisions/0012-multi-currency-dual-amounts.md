[← Decisions](README.md)

# 0012 — Multi-currency with dual amount storage

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** every table holding money

## Context

Multi-currency is required. Adding it later means backfilling a currency code and an exchange
rate onto every historical financial row — and the correct historical rates are frequently
unrecoverable.

## Decision

Multi-currency from the first migration that touches money.

**Every monetary row stores both amounts:**

| Column | Meaning |
|---|---|
| `amount`, `currency_id`, `fx_rate` | Transaction currency, and the rate used |
| `base_amount` | Company base currency, computed at that pinned rate |

A historical `base_amount` is **never** recomputed from a current rate.

Money is `decimal(19,4)` in the database, cast to a `brick/money` value object in PHP.

Three concepts kept distinct: **transaction** currency (what the document was raised in),
**base** currency (the GL's reporting currency), and **display** currency (a UI preference,
never stored on a document).

## Consequences

- **Positive:** Historical documents remain correct regardless of later rate movements.
- **Positive:** GL reporting works in one currency without losing the original.
- **Negative:** **Realised FX gain/loss** must be computed when a receipt settles an invoice at
  a different rate, and posted to the P&L. This is real complexity, not bookkeeping decoration:

  ```
  Invoice  1,000 USD @ 280  →  280,000 base receivable
  Receipt  1,000 USD @ 285  →  285,000 base banked
                                 5,000 realised FX gain
  ```

- **Negative:** Every monetary column is two columns. Wider tables, more care in every query.
- **Requires:** an `exchange_rates` table with dated rates, and a defined rule for which rate
  applies to which document type.
- **Deferred:** unrealised revaluation of open balances at period close is needed for complete
  reporting but is not required for Phase 6.

## Alternatives considered

**Single currency, add later** — rejected. This is precisely the retrofit that is impossible to
do correctly, because historical rates cannot be reconstructed after the fact.

**Store transaction amount only, convert on read** — rejected. Reporting would change
retroactively as rates move, and the GL would not balance in base currency.

**Integer minor units instead of decimal** — a legitimate alternative that avoids decimal
representation questions entirely. Rejected because currencies vary in decimal places, unit
costs need more than two (a per-gram ingredient cost), and `decimal(19,4)` is supported
identically by both database engines ([0003](0003-postgres-with-mysql-compatibility.md)).

## Related

- [domain/multi-currency.md](../domain/multi-currency.md)
- [0011 — Double-entry GL](0011-double-entry-open-item-ar.md)
- [0024 — Base currency and fiscal year set at onboarding](0024-onboarding-base-currency-and-fiscal-year.md) *(resolved Q2)*
