# 0055 — Use progressive Sales pricing with controlled formula steps

- **Status:** superseded by [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- **Date:** 2026-09-26
- **Affects:** Phase 3 cost/price boundary, Phase 4 quotations and Sales pricing, Phase 6 tax

## Context

An assembly's internal cost answers “what does one unit cost us?” Sales pricing answers “what do
we charge this customer for this quantity on this date?” Those facts sometimes use one another,
but they are not the same fact. A maintained customer price must not silently change because
material cost changed, while a cost-derived price may need recalculation before a draft is issued.

For example, a company may sell 100 m² of work whose internal cost is $8/m², add a $2/m²
company-defined charge and apply a factor of 1.8. The net unit price is `(8 + 2) × 1.8 = $18` and
the net line is $1,800. Tax is determined afterwards. Smaller companies need this without first
configuring a full enterprise pricing engine; larger companies need customer agreements,
quantity tiers, effective dates and controlled formulas.

Industry products use different names for rules with the same economic effect. The stable model
therefore distinguishes calculation effect from applicability, precedence and presentation.

## Decision

Sales pricing is a separate Phase 4 capability layered on the sellable Assembly from
[ADR 0008](0008-assembly-abstraction.md). A one-material Assembly remains the simple way to sell
a stocked product. Phase 3 produces cost evidence only and does not decide the final customer
price.

Each company selects an independent pricing capability mode:

- `SIMPLE` — maintained unit price, cost plus markup, target margin and controlled manual price;
- `MANAGED` — adds effective-dated price lists, customer-specific prices, quantity tiers,
  currency, discounts/surcharges, minimum-margin controls and agreements;
- `ADVANCED` — adds a versioned, ordered pricing pipeline built from controlled formula steps.

`SIMPLE` is the initial mode. Company policy supplies the default base method, with a more
specific Assembly then Assembly Group policy able to override it. Absence of a valid price is an
error, never an implicit zero.

### Economic effects

These are the initial base-price effects, regardless of the label used by another ERP:

| Effect | Meaning |
|---|---|
| `MAINTAINED_PRICE` | A stored price remains fixed until its effective rule changes |
| `COST_PLUS_MARKUP` | `cost × (1 + markup percentage)` |
| `TARGET_MARGIN` | `cost ÷ (1 − margin percentage)` |

A standard work-unit rate is a maintained price on an Assembly/UoM. A customer or contract price
is the same effect with narrower applicability. Quantity tiers, date and currency decide whether
a price applies; discounts and surcharges adjust a resolved base price; a manual price is a
controlled exception. These are not additional base calculation methods.

### Resolution and precedence

The first applicable source in this order wins:

```text
customer contract/agreement
  → customer-specific maintained price
  → general maintained price
  → assembly pricing policy
  → permitted manual price, otherwise block
```

Within a source, choose the most specific applicable record, then explicit priority, effective
date and a stable identifier as deterministic tie-breakers. Do not automatically choose the
lowest price. Customer-group pricing is deferred until the Party domain has a real customer
segmentation concept; it must not be approximated with unrelated categories.

### Controlled formulas

Advanced formulas are ordered, versioned steps using allowlisted operations and typed values,
not executable PHP/JavaScript or database expressions. Initial step families are:

`BASE_COST`, `BASE_MAINTAINED_PRICE`, `ADD_PER_UNIT_AMOUNT`, `MULTIPLY_BY_FACTOR`,
`APPLY_MARKUP`, `APPLY_TARGET_MARGIN`, `MINIMUM_UNIT_PRICE`, `MAXIMUM_UNIT_PRICE`,
`ROUND_UNIT_PRICE`, and `ADD_LINE_CHARGE`.

Inputs declare types and units such as `MONEY_PER_WORK_UNIT`, `DECIMAL_FACTOR`, `PERCENTAGE`,
`MONEY_PER_LINE` and `QUANTITY`. Values may resolve through an explicit quotation-line value,
then customer/contract, Assembly, Assembly Group and company defaults where the input definition
allows those scopes. The evaluator rejects missing values, incompatible UoMs, invalid percentages
and division by zero. It exposes a preview/test result with every intermediate value.

Future syntax may make these steps easier to author, but remains a restricted DSL with the same
allowlist, type checking and resource limits.

### Draft, rounding and snapshot rules

- Cost-derived prices recalculate while a quotation is draft; the UI identifies stale inputs and
  requires an explicit reprice or confirmation before issue. Maintained prices remain fixed for
  their effective rule version.
- Calculate with high precision, round the unit price under the configured pricing policy,
  multiply by quantity, then apply document/currency rounding. Never round internal cost early.
- On issue, snapshot the price source and rule/formula version, resolved inputs, intermediate
  results, calculated price, final price, adjustment/override reason and approval, currency/FX
  evidence and rounding policy. An issued quote is immutable; changes create a revision.
- Tax determination runs after the net selling price is resolved. Inclusive presentation may
  back-calculate net and tax, but never makes tax part of the pricing formula.

Manual override preserves both calculated and final price. Each Phase 4 implementation slice
must define its permission and sensitive-field vocabulary under the authorisation checklist; Q25
covers the related Phase 3 material/cost fields, while Q27 governs reusable multi-step approvals.

## Consequences

- Small companies can use a direct price or familiar markup/margin without enterprise setup.
- Advanced companies can add price lists and formulas without replacing the Assembly or cost
  model.
- Pricing results are explainable and reproducible instead of depending on opaque custom code.
- Phase 4 must add versioned pricing records, a deterministic resolver and quotation snapshots;
  this ADR documents the contract but does not implement them.
- The existing Assembly `margin_type` calculation remains valid as transitional SIMPLE-mode cost
  pricing, but it is not the final Sales resolver and must not be treated as a maintained price.

## Alternatives considered

- **Make cost plus margin the only price.** Rejected: maintained and contractual prices must not
  drift with internal cost.
- **Always choose the lowest applicable price.** Rejected: contracts, deliberate priority and
  company policy may require a different result.
- **Allow arbitrary expressions or scripts.** Rejected: they are difficult to secure, validate,
  explain, version and reproduce.
- **Build the full advanced engine first.** Rejected: it creates unnecessary setup for smaller
  companies and delays the common path.

## Research basis

- [Microsoft Dynamics 365 Commerce price management](https://learn.microsoft.com/en-us/dynamics365/commerce/price-management)
- [Microsoft Dynamics 365 sales agreements](https://learn.microsoft.com/en-us/dynamics365/supply-chain/sales-marketing/sales-agreements)
- [Oracle Pricing strategies](https://docs.oracle.com/en/cloud/saas/supply-chain-and-manufacturing/26c/faupr/pricing-strategy.html)
- [Oracle Pricing rules](https://docs.oracle.com/en/cloud/saas/supply-chain-and-manufacturing/26a/faupr/pricing-rules.html)
- [SAP sales pricing conditions](https://help.sap.com/docs/s4hana-best-practices/create-sales-pricing-condition-bet-6ec0606a16fc1be2fdd9c76d828f6728/purpose?locale=en-US)
- [Odoo pricelists and formula pricing](https://www.odoo.com/documentation/18.0/applications/sales/sales/products_prices/prices/pricing.html)

## Related

- [ADR index](README.md)
- [Sales pricing domain contract](../domain/sales-pricing.md)
- [Material resolution](../domain/material-resolution.md)
- [Tax](../domain/tax.md)
- [ADR 0008 — Assembly abstraction](0008-assembly-abstraction.md)
