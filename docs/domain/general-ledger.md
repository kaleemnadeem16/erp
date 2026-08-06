[← Domain index](README.md)

# General Ledger

Double-entry from day one ([ADR 0011](../decisions/0011-double-entry-open-item-ar.md)). Every
financial event posts a balanced journal entry.

Retrofitting proper accounting onto a system that tracked balances as columns is a rewrite, not
a migration. This is the classic ERP mistake and it is cheap to avoid now.

## Where it sits

Everything financial posts here: [stock.md](stock.md), [job-costing.md](job-costing.md),
[receivables.md](receivables.md), purchasing. Built in [Phase 6](../phases/README.md), though
account mapping is defined in Phase 1.

## Schema

```
chart_of_accounts
  id, company_id
  code, name
  type            ASSET | LIABILITY | EQUITY | INCOME | EXPENSE
  parent_id       NULLABLE          -- hierarchy
  is_postable     bool              -- false for summary/header accounts
  currency_id     NULLABLE          -- for currency-specific accounts (e.g. USD bank)

  unique(['company_id', 'code'])

accounting_periods
  id, company_id
  name, starts_on, ends_on
  status          OPEN | CLOSED

journal_entries
  id, company_id
  number, entry_date, period_id
  source_type, source_id            -- polymorphic: invoice, receipt, stock move, timesheet
  narration
  status          DRAFT | POSTED | REVERSED
  reversal_of_id  NULLABLE
  posted_at, posted_by
  -- IMMUTABLE once posted

journal_lines
  id, journal_entry_id
  account_id
  debit, credit                     -- transaction currency
  currency_id, fx_rate
  base_debit, base_credit           -- base currency
  cost_center_id  NULLABLE
  job_id          NULLABLE          -- analytical dimension
  description
```

## Behaviour

### Immutability

Posted entries are **never** updated. Corrections are new entries with
`reversal_of_id` set. [Invariant 3](../architecture/invariants.md#3-financial-documents-are-immutable).

### Balancing

`Σ debit = Σ credit` in **both** transaction and base currency, enforced before posting. With
multi-currency, base-currency balance requires the FX difference to be posted explicitly — a
partial entry that balances in one currency but not the other must be rejected.

### Periods

Posting into a `CLOSED` period is rejected. Backdated entries need the period reopened, which
should be permissioned and logged.

### What posts when

| Event | Debit | Credit |
|---|---|---|
| Goods receipt | Inventory | GRNI |
| Purchase invoice | GRNI, Input Tax | Payables |
| Material issue to job | WIP | Inventory |
| Timesheet posted | WIP | Labour Absorbed |
| Job completed | COGS | WIP |
| Sales invoice | Receivables | Revenue, Output Tax |
| Receipt from customer | Bank | Receivables |
| Advance received | Bank | Customer Advances *(liability)* |
| Advance applied | Customer Advances | Receivables |
| FX settlement difference | *(either)* | Realised FX Gain/Loss |
| Stock adjustment | Inventory / Variance | Variance / Inventory |

Account selection comes from item and category GL mapping ([items.md](items.md)) plus company
defaults — never hardcoded.

## Landmines

**Entries that balance in transaction currency but not base.** The most common multi-currency
GL bug. Validate both.

**GRNI never clearing.** Goods Received Not Invoiced accumulates when receipts and purchase
invoices don't match up. Build the aged GRNI report early; a growing balance is the symptom of
a process problem, not an accounting one.

**Inventory GL versus stock valuation drift.** The inventory account balance must equal the sum
of stock valuation. Reconcile continuously — see [stock.md](stock.md).

**Posting from too many places.** Every posting should go through one Action per event type, not
scattered `JournalEntry::create()` calls. Otherwise the rules above cannot be enforced.

**Rounding.** With `decimal(19,4)` and percentage-based tax, entries can be out by 0.0001.
Decide the rounding account and apply it consistently rather than letting entries fail to
balance.

## Related

- [receivables.md](receivables.md) · [multi-currency.md](multi-currency.md)
- [stock.md](stock.md) · [job-costing.md](job-costing.md)
- [ADR 0011](../decisions/0011-double-entry-open-item-ar.md)
