# 0056 — Use progressive tax calculation and explicit recovery distributions

- **Status:** accepted
- **Date:** 2026-09-26
- **Affects:** Phase 4 quotations and invoices, Purchasing input tax, Phase 6 Finance and tax

## Context

[ADR 0025](0025-configurable-tax-engine.md) correctly established that tax is configured data,
not hardcoded application logic, and separated sales/output tax, purchase/input tax and
withholding. Its conceptual `is_recoverable` boolean and one percentage rate are not enough for
real tax operations.

For a $100 purchase with $15 tax, one company may reclaim all $15, another none, and another 60%
($9 recoverable and $6 added to cost/expense). Jurisdictions may also require several tax
components, inclusive prices, fixed or quantity taxes, tax-on-tax, reverse charge, exemptions and
reporting classifications. Smaller companies should not configure those features when one
effective percentage is sufficient.

## Decision

ADR 0025 remains the foundation. Tax capability is progressive and is selected independently of
Sales pricing capability:

- `BASIC` — effective-dated percentage taxes; separate output/input directions; exclusive
  calculation; full or no recovery; explicit zero-rated, exempt and not-applicable outcomes;
  optional withholding;
- `LOCALISED` — adds multiple components, goods/service and item/Assembly tax classifications,
  party tax profiles, inclusive calculation, partial recovery, jurisdictions/locations,
  report codes/grids, exemption evidence and withholding certificates;
- `ADVANCED` — adds grouped/compound, fixed and quantity taxes, tax-on-tax, reverse-charge or
  self-assessed entries, intended-use recovery rules, multiple recovery types and restricted tax
  formulas or an approved localisation/external tax provider.

`BASIC` is the initial company mode. Country or province packages may provide starting
configuration and rules, but core never hardcodes a jurisdiction's law. Configuration must be
verified by the company's qualified tax adviser.

### Determination inputs and classification

Tax determination may use only declared facts: company tax regime, customer/supplier tax
profile, item or Assembly tax classification, goods/service classification, ship-to or service
location, transaction/tax-point date and, for purchase recovery, intended use.

Tax classification is a dedicated concept, not an Inventory category. A category may provide a
default:

```text
item explicit tax classification → nearest item-category default → company goods default
assembly explicit tax classification → assembly-group default → company services default
```

The resolved classification is persisted before first use and snapshotted on the document.
Moving an item or Assembly to another group later must not reinterpret history.

### Calculation order and storage

Pricing resolves the net line first, then tax determines its bases and components, then gross is
derived. For an inclusive customer price, the engine back-calculates net and tax while still
storing them separately. A component explicitly declares whether a preceding component forms
part of its base; sequence must never be inferred from display order.

For each tax component, a document snapshot stores the calculation method, base, rate or
quantity, amount, direction, inclusive/exclusive treatment, jurisdiction and report tag, rule and
rate version, GL mapping, recovery percentage, recoverable amount and non-recoverable amount.
The sum of recovery distributions must equal the input-tax component.

Recoverable purchase tax posts to a tax-credit asset. Non-recoverable tax is capitalised into the
asset/inventory acquisition cost or expensed according to the purchase context. Output tax posts
to a liability. Withholding remains separate: it reduces cash received or paid and records the
corresponding tax credit/payable and certificate without changing invoice tax.

### Dates, documents and corrections

A quotation tax result is an estimate using quotation context. Invoice issue determines the legal
tax using the configured tax-point basis and current document facts; it does not blindly copy the
quotation tax. Both documents snapshot the evidence that produced their result. Issued-document
tax changes use the appropriate credit/debit note or reversal workflow, never an in-place edit.

### Controlled formulas and localisation

Advanced tax formulas use versioned, allowlisted inputs and operations with type/UoM validation,
bounded execution, preview tests and full intermediate evidence. They cannot execute arbitrary
application code or queries. Localisation plugins or external services translate jurisdictional
rules into the same core component, recovery and snapshot contract; they do not bypass tenant,
permission, posting or audit boundaries.

Tax configuration and tax overrides have permissions independent from pricing, document entry
and document approval. The detailed vocabulary is decided with each implementation slice under
the authorisation checklist.

## Consequences

- A small company can operate with straightforward codes while advanced regimes fit the same
  transaction and ledger model.
- Claimable tax is represented as money distributions rather than an inadequate yes/no flag.
- Price, net amount, tax, gross amount and withholding remain reconcilable facts.
- Phase 4/6 implementation must replace the conceptual `is_recoverable` boolean with recovery
  treatment/rates and component distributions; this ADR does not migrate or implement schema.
- Local legal behaviour remains configurable and testable without turning the core ERP into a
  collection of country-specific branches.

## Alternatives considered

- **Keep `is_recoverable` only.** Rejected: it cannot represent partial or rule-based recovery.
- **Include tax inside Sales pricing formulas.** Rejected: price and statutory tax have different
  inputs, dates, permissions, accounting and correction lifecycles.
- **Hardcode each country's rules in core.** Rejected: rates and law change independently and the
  approach does not scale across jurisdictions.
- **Require the advanced engine for every company.** Rejected: unnecessary configuration would
  make the small-company path fragile.

## Research basis

- [Odoo tax computation](https://www.odoo.com/documentation/18.0/applications/finance/accounting/taxes/tax_computation.html)
- [Odoo tax-return grids](https://www.odoo.com/documentation/18.0/applications/finance/accounting/reporting/tax_returns.html)
- [Oracle tax recovery](https://docs.oracle.com/en/cloud/saas/financials/25d/faitx/tax-recovery.html)
- [Oracle tax recovery rates and controls](https://docs.oracle.com/en/cloud/saas/financials/25d/faitx/tax-recovery-rates-controls-and-defaults.html)
- [Oracle tax rates and calculation controls](https://docs.oracle.com/en/cloud/saas/financials/26a/faitx/tax-rates-controls-and-defaults.html)
- [Oracle tax recovery distributions](https://docs.oracle.com/en/cloud/saas/financials/25d/fautx/tax-recovery-distributions.html)

## Related

- [ADR index](README.md)
- [Tax domain contract](../domain/tax.md)
- [Sales pricing domain contract](../domain/sales-pricing.md)
- [General ledger](../domain/general-ledger.md)
- [ADR 0025 — Configurable tax engine](0025-configurable-tax-engine.md)
