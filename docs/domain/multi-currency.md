[← Domain index](README.md)

# Multi-Currency

Confirmed in scope from the start ([ADR 0012](../decisions/0012-multi-currency-dual-amounts.md)).
Adding it later is effectively impossible — historical exchange rates cannot be reconstructed
after the fact.

## Where it sits

Affects every table holding money: [receivables.md](receivables.md),
[general-ledger.md](general-ledger.md), [stock.md](stock.md), purchasing. Established in
[Phase 1](../phases/README.md).

## Schema

```
currencies
  id, code            'PKR', 'USD', 'EUR'
  name, symbol
  decimal_places      int          -- 2 for most, 0 for JPY

exchange_rates
  id, company_id
  from_currency_id, to_currency_id
  rate           decimal(19,9)
  rate_date
  rate_type      SPOT | AVERAGE | CLOSING
  source                            -- manual, or an API name

  unique(['company_id','from_currency_id','to_currency_id','rate_date','rate_type'])
```

## Behaviour

### Three distinct concepts

| Concept | Meaning | Stored? |
|---|---|---|
| **Transaction currency** | What the document was raised in | ✅ on the document |
| **Base currency** | The company's reporting currency, used by the GL | ✅ as `base_amount` |
| **Display currency** | A UI viewing preference | ❌ never on a document |

Conflating display with transaction currency is a common and confusing bug — a user switching
their view must never alter stored data.

### Dual amount storage

**Every monetary row stores both:**

```
amount, currency_id, fx_rate     -- transaction currency and the rate used
base_amount                      -- computed at that pinned rate
```

A historical `base_amount` is **never** recomputed from a current rate.
[Invariant 5](../architecture/invariants.md#5-both-currency-amounts-always).

### Realised FX gain/loss

The real complexity multi-currency buys. An invoice raised at one rate and settled at another
produces a gain or loss that must hit the P&L:

```
Invoice   1,000 USD @ 280  →  280,000 base receivable
Receipt   1,000 USD @ 285  →  285,000 base banked
                               5,000 base realised FX gain
```

Computed per [`payment_allocations`](receivables.md) row at settlement, posted as part of the
allocation's journal entry.

### Rate selection

Which rate applies to which document must be an explicit rule, not an accident:

| Document | Rate |
|---|---|
| Invoice, receipt, purchase | SPOT at document date |
| Period-end reporting | CLOSING |
| Comparative reporting | AVERAGE for the period |

If no rate exists for a date, use the most recent prior rate — and **record which date's rate was
actually used**, not just the value.

### Money representation

`decimal(19,4)` in the database, `brick/money` in PHP, **string in JSON**
([api-conventions.md](../architecture/api-conventions.md)).

Four decimal places, not two: unit costs frequently need them (a per-gram ingredient cost), and
rounding at storage compounds through the cost roll-up.

## Landmines

**Recomputing history.** The cardinal sin. Every report changes retroactively and nothing
reconciles.

**Missing rate on a date.** Weekends and holidays have no published rate. Define the fallback
(most recent prior) and record what was used.

**Rounding at the wrong point.** Convert then round, never round then convert. And round only at
presentation, never mid-calculation.

**Journal entries balancing in one currency only.** Balances in transaction currency but not
base. See [general-ledger.md](general-ledger.md).

**Unrealised revaluation.** Open foreign-currency balances should be revalued at period close
for correct reporting. **Deliberately deferred** — not required for Phase 6, but it is a real
gap and should not be forgotten.

**Assuming the customer's currency.** A customer has a *default* currency; a specific document
may use another. Never infer from the customer record alone.

## Related

- [receivables.md](receivables.md) — where FX gain/loss is realised
- [general-ledger.md](general-ledger.md)
- [ADR 0012](../decisions/0012-multi-currency-dual-amounts.md)
- [Q2 — Base currency](../OPEN-QUESTIONS.md#q2--base-currency)
