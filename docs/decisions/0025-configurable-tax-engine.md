[← Decisions](README.md)

# 0025 — Configurable tax engine, no hardcoded rates or regimes

- **Status:** accepted
- **Date:** 2026-08-10
- **Affects:** Phase 4 (quotations), Phase 6 (finance), [domain/tax.md](../domain/tax.md)

## Context

[Q3](../OPEN-QUESTIONS.md) was raised and left entirely open. Real requirements, once explained
to the user:

- Sales tax / GST / VAT — added to what's sold, collected from the customer, remitted to
  government.
- Withholding tax — a customer deducts a percentage from what they pay and remits it on the
  seller's behalf as an advance against the seller's own tax bill.
- Goods vs. services — in Pakistan, tax on goods is federal (FBR); tax on services is
  provincial, and the reference business sells services.
- Input tax recovery — tax paid on purchases can offset tax owed on sales.
- Tax-inclusive vs. tax-exclusive pricing.

The user does not have a finance background and doesn't yet know the specific rates/rules that
will apply, but is clear that whoever holds the finance-director/CFO role should be able to
define and change tax behaviour themselves, without needing a developer, and that some
customers/companies may need no tax at all. Rates also change roughly every Pakistani budget
cycle, and the product is intended to eventually serve companies outside Pakistan too.

## Decision

Tax is fully data-driven — no jurisdiction, rate, or regime is hardcoded in application code.

- A `tax_codes` table holds rate, name, jurisdiction, goods/services/both applicability,
  recoverable flag, active flag, and an effective date range (so a historical document keeps the
  rate that applied when it was raised — the same snapshot principle used elsewhere,
  [Invariant](../architecture/invariants.md)).
- Configuration of tax codes sits behind its own permission (e.g. `finance.tax.manage`), not a
  blanket "admin" permission — consistent with the dynamic, granular roles direction still being
  worked out separately.
- Withholding tax is modelled as a deduction applied at receipt/payment time, kept **separate**
  from invoice/output tax.
- Output tax (sales) and input tax (purchases) post to distinct GL accounts — already reflected
  in [general-ledger.md](../domain/general-ledger.md)'s posting table — so recoverable input tax
  nets against output tax at filing.
- Tax can be switched off entirely per company or per customer.

## Consequences

- **Positive:** works for Pakistan's federal/provincial split, and for any other country's
  regime, without core code changes — fits the core+plugin/whitelabel direction
  ([ADR 0010](0010-core-plus-plugins.md)).
- **Positive:** rate changes are a data edit by the finance-director role, not a deploy.
- **Negative:** more setup surface — a company must configure at least one tax code, or
  explicitly opt out, before it can invoice correctly.
- **Requires:** effective-dated tax codes; a dedicated tax-management permission; confirmation of
  actual current Pakistani rates from an accountant before Phase 6 build starts — this ADR
  decides the *mechanism*, not the *rates*.

## Alternatives considered

**Hardcode Pakistani GST/sales tax rules** — rejected. Breaks the moment the product serves a
second company or country, and rates change too often to bake into code.

**A single flat tax rate per company** — rejected. Doesn't support the goods-vs-services split or
provincial variation the reference business actually has.

## Related

- [domain/tax.md](../domain/tax.md)
- [domain/general-ledger.md](../domain/general-ledger.md) — Output Tax / Input Tax accounts
- [domain/receivables.md](../domain/receivables.md) — invoice line `tax_code_id`
- [ADR 0010 — Core + plugins](0010-core-plus-plugins.md)
- Q3 — Tax model *(resolved, removed from open questions)*
