[← Domain index](README.md)

# Tax

Sales/output tax and purchase/input tax are modelled as company-configured data, not hardcoded
regimes ([ADR 0025](../decisions/0025-configurable-tax-engine.md)). Rates, jurisdictions, and
whether tax applies at all vary by country, by province, and by goods vs. services — and change
on their own schedule, unrelated to application releases.

## Where it sits

Applied on quotation and invoice lines ([sales-pipeline.md](sales-pipeline.md),
[receivables.md](receivables.md)) and on purchase lines; posts to Output Tax / Input Tax accounts
in [general-ledger.md](general-ledger.md). Tax codes are configured in
[Phase 1](../phases/README.md) master data; applied to documents from Phase 4 onward.

## Schema

```
tax_codes
  id, company_id
  code, name
  rate               decimal              -- percentage
  applies_to         GOODS | SERVICES | BOTH
  jurisdiction        NULLABLE             -- free text/region, company-defined
  is_recoverable      bool                 -- input tax eligible for offset
  is_active           bool
  effective_from, effective_to   NULLABLE  -- rates change; history preserved

withholding_tax_codes
  id, company_id
  code, name
  rate               decimal
  is_active          bool

-- applied at line level
invoice_lines.tax_code_id     -- see receivables.md
purchase_lines.tax_code_id    -- mirror, purchasing module

-- applied at payment level
receipts.withholding_tax_code_id   NULLABLE
receipts.withholding_tax_amount    NULLABLE
```

## Behaviour

### Data-driven, not hardcoded

No jurisdiction, rate, or regime is baked into application code. A finance-director/CFO-level
role manages `tax_codes` directly, behind its own permission (e.g. `finance.tax.manage`), not a
blanket admin permission. A country- or province-specific starting set of codes can ship as
convenience seed data or a plugin, without changing core.

### Effective-dated rates

`effective_from` / `effective_to` mean a historical document keeps the rate that applied when it
was raised, even after the rate changes later — the same snapshot principle used for cost
roll-ups (see **Snapshot** in [GLOSSARY.md](../GLOSSARY.md)). Which document date selects the
rate (quotation date vs. invoice date) must be a defined, not assumed, rule — usually invoice
date.

### Goods vs. services

`applies_to` lets a company (or a province-specific plugin) route goods and services to
different tax codes, matching regimes like Pakistan's federal (goods) / provincial (services)
split, without either being a core concept.

### Withholding tax

Modelled as a deduction applied at receipt/payment time via `receipts.withholding_tax_code_id`,
kept **separate** from invoice/output tax — it is an advance against the seller's own tax
liability, not sales tax. Conflating the two breaks reconciliation against tax-authority
certificates.

### Input tax recovery

Output tax (on sales) and input tax (on purchases) post to distinct GL accounts (see
[general-ledger.md](general-ledger.md)'s posting table). `is_recoverable` marks which input tax
can net against output tax at filing; non-recoverable input tax is a cost, not a credit.

### Inclusive vs. exclusive pricing

Amounts are stored **tax-exclusive** internally — line amount and tax amount are always separate
columns, never blended. A tax-inclusive **display** is a presentation choice for quotes, not a
storage difference (same transaction/base/display distinction used for currency, see
[multi-currency.md](multi-currency.md)).

### No tax

Tax is optional per company and per customer. A customer flagged tax-exempt routes to a 0%-rate
tax code (still tracked, since exempt sales are often still reportable) — distinct from having no
tax code at all, which means tax doesn't apply and isn't reported.

## Landmines

**Rate changes mid-document-lifecycle.** A quotation raised before a rate change, invoiced after
— decide and document which date selects the rate (invoice date, not quotation date, is the
usual rule) rather than leaving it implicit.

**Confusing exempt with not-applicable.** 0%-but-tracked and no-tax-code-at-all look the same on
an invoice total but are not the same for reporting.

**Misclassifying goods vs. services.** Changes which jurisdiction/authority applies. Gets its
classification from the item master ([items.md](items.md)), not guessed per line.

**Netting withholding tax into invoice tax.** They sit on different legs of the accounting
(output tax at invoicing vs. an advance-tax asset at payment) — conflating them breaks
reconciliation with withholding certificates issued by the customer.

## Related

- [general-ledger.md](general-ledger.md) — Output Tax / Input Tax accounts
- [receivables.md](receivables.md) — invoice line `tax_code_id`
- [items.md](items.md) — goods vs. services classification
- [ADR 0025 — Configurable tax engine](../decisions/0025-configurable-tax-engine.md)
