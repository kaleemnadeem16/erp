[← Domain index](README.md)

# Receivables & Customer Ledger

> *"A customer opens a ledger and wants the job done. Multiple invoices are sent over a period,
> which they cover, with a proper ledger showing the balance."*

That is **open-item accounts receivable** with **progress billing**, presented as a running
statement of account ([ADR 0011](../decisions/0011-double-entry-open-item-ar.md)).

## Where it sits

Bills the jobs in [job-costing.md](job-costing.md); posts to
[general-ledger.md](general-ledger.md). Built in [Phase 6](../phases/README.md).

## Schema

```
customers
  id, company_id
  code, name
  default_currency_id, payment_terms_days, credit_limit
  billing_address, custom_fields

invoices
  id, company_id, customer_id, job_id NULLABLE
  number, invoice_date, due_date
  type            ADVANCE | PROGRESS | FINAL | CREDIT_NOTE
  currency_id, fx_rate
  subtotal, tax_total, retention_amount, total
  base_total
  status          DRAFT | POSTED | PARTIALLY_PAID | PAID | CANCELLED
  -- IMMUTABLE once posted

invoice_lines
  id, invoice_id, job_line_id NULLABLE
  description, qty, unit_price, amount
  tax_code_id
  progress_pct                    -- % of the job line billed by this invoice
  retention_pct

receipts                          -- money in
  id, company_id, customer_id
  receipt_date, method, reference
  currency_id, fx_rate
  amount, base_amount
  unallocated_amount              -- > 0 means an advance held on account

payment_allocations
  id, receipt_id, invoice_id
  amount, base_amount
  fx_gain_loss                    -- realised at settlement

customer_ledger_entries           -- the "ledger"
  id, company_id, customer_id
  entry_date
  source_type, source_id          -- invoice | receipt | credit note | adjustment
  debit, credit
  base_debit, base_credit
  currency_id
  running_balance                 -- materialised for statement performance
```

## Behaviour

### Open-item, not balance-forward

Each invoice is tracked individually; receipts allocate against specific invoices, with partial
allocation supported. Unallocated amounts are advances held on account.

The running-balance statement is a **presentation** over this data, not the storage model. That
distinction is what makes aging and dispute resolution possible — balance-forward cannot tell
you *which* invoice is overdue.

### Derived views

| View | Derivation |
|---|---|
| **Statement of account** | `customer_ledger_entries` for a customer, ordered by date, running balance |
| **Aging** | Open-item balances bucketed 0–30 / 31–60 / 61–90 / 90+ by due date |
| **Credit control** | Outstanding vs `credit_limit`, warning or blocking on job confirmation |

### Progress billing

Each job line carries a `contract_value`. A progress invoice bills a percentage or quantity of
it.

**Invariant: `Σ progress_pct` per job line must never exceed 100%.** Enforce in the Action, not
only in the UI.

### Advances

Money received before work starts is a **liability**, not revenue:

```
Advance received   Dr Bank                Cr Customer Advances
Progress invoice   Dr Receivables         Cr Revenue, Cr Output Tax
Advance applied    Dr Customer Advances   Cr Receivables
```

### Retention / retainage

The customer withholds a percentage until a defect liability period ends. Invoiced but not yet
due — so it needs its own control account, a release schedule, and **must be excluded from
normal aging**.

⚠️ **Applicability not yet confirmed** — see
[Q1](../OPEN-QUESTIONS.md#q1--retention--retainage). Columns are present and cost nothing if
unused; the posting and reporting logic is a painful retrofit.

### Numbering

Gapless, from `sequences` with `lockForUpdate()`.
[Invariant 8](../architecture/invariants.md#8-gapless-document-numbering).

## Landmines

**`MAX(id) + 1` for invoice numbers.** Produces duplicates under concurrent load. Not
theoretical — it is the first thing that breaks when two users invoice simultaneously.

**Editing a posted invoice.** Must be blocked. Corrections are credit notes.

**Allocation with FX.** An invoice raised at one rate settled at another produces a realised
gain or loss that must post to the P&L. See [multi-currency.md](multi-currency.md). Allocating
across currencies needs an explicit rule.

**Over-allocation.** A receipt allocated to more than its own value, or an invoice allocated
beyond its balance. Validate both directions.

**Running balance drift.** `running_balance` is materialised for performance and *will* drift if
entries are inserted out of order. Recompute on insert of a backdated entry, and provide a
rebuild command.

**Credit notes reducing the wrong period.** A credit note against a prior-period invoice affects
this period's revenue. Correct, but people find it surprising — surface it in reporting.

## Related

- [general-ledger.md](general-ledger.md) · [multi-currency.md](multi-currency.md)
- [job-costing.md](job-costing.md) — what gets billed
- [ADR 0011](../decisions/0011-double-entry-open-item-ar.md)
