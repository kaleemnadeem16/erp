[← Domain index](README.md)

# General Ledger

Double-entry from day one ([ADR 0011](../decisions/0011-double-entry-open-item-ar.md)). Every
financial event posts a balanced journal entry.

Retrofitting proper accounting onto a system that tracked balances as columns is a rewrite, not
a migration. This is the classic ERP mistake and it is cheap to avoid now.

## Where it sits

Everything financial posts here: [stock.md](stock.md), [job-costing.md](job-costing.md),
[receivables.md](receivables.md), purchasing. Posting itself — `journal_entries`/`journal_lines`
— is built in [Phase 6](../phases/README.md). `chart_of_accounts`, `fiscal_years` and
`accounting_periods` are built in Phase 1, since item/category GL mapping ([items.md](items.md))
and the role accounts ([parties.md](parties.md)) need real accounts to reference — they carry no
posting logic yet, just the structure later phases post into.

## Schema

```
chart_of_accounts
  id, company_id
  code, name
  type            ASSET | LIABILITY | EQUITY | INCOME | EXPENSE
  parent_id       NULLABLE → chart_of_accounts    -- hierarchy; guarded like categories.parent_id
                                                      (no self-parent, no cycles, same company)
  is_postable     bool DEFAULT true   -- false for summary/header accounts
  currency_id     NULLABLE          -- account restricted to one currency (e.g. a USD bank
                                        account in a PKR-base company); null = follows the
                                        company's base currency. Still translated to base
                                        currency for reporting, same as any transaction currency
                                        — see multi-currency.md
  status          ACTIVE | ARCHIVED DEFAULT ACTIVE

  unique(['company_id', 'code'])

fiscal_years
  id, company_id
  name, starts_on, ends_on
  status          OPEN | CLOSED DEFAULT OPEN

  unique(['company_id', 'name'])
  starts_on <= ends_on
  no overlapping fiscal years for the same company

accounting_periods
  id, company_id, fiscal_year_id   -- must resolve to a fiscal_year in the same company
  period_no, name, starts_on, ends_on
  status          OPEN | CLOSED DEFAULT OPEN

  unique(['fiscal_year_id', 'period_no'])
  starts_on <= ends_on
  no overlapping periods for the same company
  must fall entirely within its fiscal_year's [starts_on, ends_on]

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

### Chart access

Authenticated company users may list/show chart accounts as references for other forms.
Creating, editing/reparenting and deleting an unreferenced chart account require
`accounting.chart-of-account.manage` through an assigned role or direct grant. The Form
Requests and controller enforce HTTP access; all three write Actions also require an actor and
check the capability, including non-HTTP callers. The existing company scope and foreign-key
guards still apply. This permission does **not** decide who may see or edit the Item/Category
GL-account mapping fields or customer/supplier control-account fields; those cross-module field
policies remain open in the [access audit](../architecture/access-security-audit.md).

No chart-maintenance SPA screen exists yet. A future screen should gate write controls using
the effective capability, with backend checks remaining authoritative. The configured
administrator receives the vocabulary via normal registration/admin sync; other development
maintainers can be granted the permission through Access Administration.

### Account type and normal balance

`chart_of_accounts.type` is a backed `AccountType` enum, not a bare string — `ASSET | LIABILITY |
EQUITY | INCOME | EXPENSE`. Which side of an entry is "normal" for an account follows directly
from its type and is centralised on the enum, not scattered as `if` conditionals wherever an
account is used:

| Type | Normal balance |
|---|---|
| `ASSET`, `EXPENSE` | Debit |
| `LIABILITY`, `EQUITY`, `INCOME` | Credit |

**Deliberately no finer subtype yet** (`RECEIVABLE`, `PAYABLE`, `CASH_BANK`, `INVENTORY`,
`FIXED_ASSET`, `COGS`, …). The five types above are the fixed, universal double-entry
categories and aren't going away; a *reporting* classification finer than that (e.g. "which
accounts count as cash for a cash-flow statement") is a real future need, but its vocabulary
should come from Phase 6's actual financial-statement design, not be guessed now. Unlike a
composite unique constraint or a scoping mechanism, an unused nullable column is cheap to add
later — there's no structural reason to reserve it speculatively.

**Account-type compatibility is not validated anywhere yet — intentionally.** Nothing today
checks that an account assigned to a given role carries the type that role implies:
`inventory_account_id`/`receivable_account_id` → `ASSET`, `payable_account_id` → `LIABILITY`,
`income_account_id` → `INCOME`, `expense_account_id` → `EXPENSE`. An `EXPENSE`-typed account can
currently be saved as `inventory_account_id` and nothing will object. This is deliberately
deferred to Phase 6's posting/configuration layer alongside the rest of this section's
finer-subtype reasoning, not an oversight — see [items.md](items.md#gl-mapping) and
[parties.md](parties.md#receivable-and-payable-are-control-accounts-not-income) for where each
role account is assigned.

### Fiscal years and periods

`fiscal_years` and `accounting_periods` are **created explicitly**, not auto-generated from a
recurring rule — same manual-entry philosophy as [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md)
(exchange rates) and gapless numbering ([sequences](../phases/phase-0-foundation.md)): a company
enters "FY2026–27, 2026-07-01 to 2027-06-30" once, then its periods within it. `companies.fiscal_year_start_month`
([ADR 0024](../decisions/0024-onboarding-base-currency-and-fiscal-year.md)) is only a UI-default
suggestion for that entry form, never consumed by a generator — there is no generator.

Both tables reject overlapping ranges for the same company at the database level (a Postgres
exclusion constraint, [database.md](../architecture/database.md)'s pattern for exactly this —
`EXCLUDE USING gist`, requiring `btree_gist`) rather than an application-level check, plus a
`starts_on <= ends_on` `CHECK`. A period additionally can't be expressed as a same-table `CHECK`
against its fiscal year's dates — that containment (`fiscal_year_id` resolves in-company, and the
period's range falls entirely inside its fiscal year's) is an application-level guard on save,
the same shape as `categories.parent_id`'s guard. The inverse update is guarded too: once periods
exist, changing a fiscal year's dates is rejected if the new range would exclude any of them.
Fiscal-year edits therefore cannot silently strand previously valid periods outside their
parent range.

### Account lifecycle in Phase 1

`chart_of_accounts` does not use soft deletes. The current DELETE endpoint physically removes an
account, but PostgreSQL foreign keys reject the operation while a child account, item/category
GL mapping, or customer/supplier control account references it. This is appropriate before any
journal tables exist. Once Phase 6 introduces posting, accounts with journal history must only be
archived (`status = ARCHIVED`) and never deleted; that posting-history guard is not implemented
in Phase 1 because there is no journal history to query yet. Selection of archived or
non-postable accounts for new mappings is likewise deferred to Phase 6.

The account hierarchy is tenant-safe at both layers: the model rejects self-parenting, cycles
and cross-company parents on create and update, while a composite `(parent_id, company_id)`
foreign key protects raw database writes.

### Immutability

Posted entries are **never** updated. Corrections are new entries with
`reversal_of_id` set. [Invariant 3](../architecture/invariants.md#3-financial-documents-are-immutable).

### Balancing

`Σ debit = Σ credit` in **both** transaction and base currency, enforced before posting. With
multi-currency, base-currency balance requires the FX difference to be posted explicitly — a
partial entry that balances in one currency but not the other must be rejected.

### Periods

Posting into a `CLOSED` period is rejected. Direct posting into that historical period would
require a separate permissioned and logged Accounting reopen workflow. The persisted `CLOSED`
value already locks
`companies.fiscal_year_start_month` per ADR 0024; the authorised close/reopen workflow itself
remains Phase 6 work and is not implied by that setting guard.

Inventory recalculation is not authority to reopen a period. Under
[ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md), an
approved backdated-stock difference uses an append-only linked correction at the earliest
permitted open posting point by default; if none exists, it remains pending or blocked. Accounting
owns classification under the applicable framework—including prior-period error, estimate change,
ordinary late information, materiality and any financial-statement presentation—while Inventory
supplies immutable original/counterfactual facts and cumulative period/account context.

Economic correction allocation lines must sum to the exact approved functional/base-currency
delta. The correction journal represents and reconciles to those allocation lines; it is not an
additional economic allocation to add again. Tax/VAT/duty, integrations and ancillary obligations
reconcile separately on their own bases. Historical FX uses the actual applicable original and
counterfactual bases rather than universal current-rate retranslation, while a genuine separate FX
effect is classified and posted separately. Explicit rounding treatment is required and no
unexplained residual may remain before the package is fully reconciled.

The correction package supplies cumulative affected periods, accounts, known financial-statement
lines, prior related packages and transaction/base-currency amounts. Accounting owns materiality,
classification and restatement treatment. A linked GL posting is required for Q14 reconciliation;
an unrelated manual journal cannot resolve the package, although legitimate manual journals remain
available outside this workflow. Stable package identity and idempotent execution prevent a retry
after an ambiguous timeout from posting the same economic correction twice.

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
- [items.md](items.md) — item/category GL mapping, the same account-resolution discipline
- [parties.md](parties.md) — `receivable_account_id` / `payable_account_id`
- [ADR 0011](../decisions/0011-double-entry-open-item-ar.md)
- [ADR 0024](../decisions/0024-onboarding-base-currency-and-fiscal-year.md) — `companies.fiscal_year_start_month`
- [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md) — canonical Q14 controls and Accounting reconciliation
