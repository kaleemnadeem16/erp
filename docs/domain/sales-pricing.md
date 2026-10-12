[← Domain index](README.md)

# Sales pricing

Sales pricing turns commercial scope and document context into a customer-facing net price. It is
independent from internal cost, tax and supplier pricing. [ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
generalises source applicability while retaining the progressive pricing/control decisions from
[historical ADR 0055](../decisions/0055-progressive-sales-pricing-and-controlled-formulas.md).
Implementation belongs to Phase 4; R3 must reconcile direct Item/service/manual applicability.
The [consolidated P1–P6 business review](../phases/phase-4-r3-business-review.md) is awaiting owner approval;
its recommendations are not accepted additions to this contract.

## Problem statement

The system can calculate what one unit of work costs the company, but the amount charged to a
customer may be a maintained rate, a contractual price, cost plus markup, a target margin or a
controlled formula. A maintained rate should not move when cost moves. A cost-derived rate should
identify when its input is stale. Tax is calculated after the net price, not hidden inside it.

Example: an Assembly costs $8/m². A company-defined input `x` is $2/m² and its factor is 1.8.
For 100 m²:

```text
net unit price = ($8/m² + $2/m²) × 1.8 = $18/m²
net line       = $18/m² × 100 m²       = $1,800
tax            = determined afterwards from the $1,800 line and tax context
```

## Capability modes

| Mode | Intended fit | Capabilities |
|---|---|---|
| `SIMPLE` | Small company or straightforward catalogue | Maintained price, cost-plus-markup, target margin, controlled manual price |
| `MANAGED` | Customer and volume pricing | SIMPLE plus effective-dated price lists, customer prices, quantity tiers, currency, adjustments, agreements and minimum-margin controls |
| `ADVANCED` | Governed company-specific logic | MANAGED plus versioned ordered formulas from restricted typed steps |

The company selects its mode and default base strategy. For Assembly-backed scope, an Assembly
Group or Assembly may define a more specific policy. Direct Item/manual scopes do not inherit an
Assembly hierarchy; their specific applicability/precedence remains R3. Capability mode controls available configuration; it does not erase or
reinterpret historical quotation snapshots.

## Conceptual schema

The following Assembly-oriented sketch is retained historical design from ADR 0055, not a
universal or implementation-ready physical model. R3 must reconcile source-neutral policy scopes
and applicability; do not mechanically add Item categories to its precedence. Preserve these facts:

```text
pricing_policies
  id, company_id
  scope_type        COMPANY | ASSEMBLY_GROUP | ASSEMBLY
  scope_id          NULLABLE
  base_method       MAINTAINED_PRICE | COST_PLUS_MARKUP | TARGET_MARGIN
  status, effective_from, effective_to, priority

price_lists / price_entries
  id, company_id, currency_id, status, effective dates
  applicability    GENERAL | CUSTOMER | AGREEMENT
  party/agreement, assembly, UoM, quantity tier
  unit_price       decimal(19,4)

pricing_formula_versions / inputs / steps
  id, company_id, version, status, effective dates, reason
  typed input definitions and permitted resolution scopes
  ordered allowlisted operation, operands and result type/UoM

quotation_line_price_snapshots
  company and quotation-line identity
  source/rule/formula versions, resolved inputs and intermediate results
  calculated_unit_price, final_unit_price, line_net   decimal(19,4)
  currency/FX and rounding evidence, override reason/approval
```

Pricing configuration is versioned or effective-dated and company-scoped. Issued snapshots own
the historical result; they do not point at live configuration as their source of truth.

## Resolution contract

```text
document context
  eligible Party/account context + commercial source + quantity/basis + currency + effective date
        ↓
customer contract/agreement
  → customer-specific maintained price
  → general maintained price
  → applicable source pricing policy (Assembly scopes where relevant; others await R3)
  → permitted manual price, otherwise error
        ↓
base price → ordered adjustments/formula → unit rounding → line net
        ↓
tax determination
```

The resolver uses specificity, explicit priority, effective date and stable identity. It never
silently selects the lowest applicable price. It returns both the result and an explanation of
every candidate accepted or rejected.

## Effects, applicability and adjustments

Keep these concepts separate:

- **base effect:** maintained price, cost plus markup, or target margin;
- **applicability:** eligible recipient, commercial source, quantity tier, date and currency;
- **adjustment:** discount, surcharge, factor, floor, ceiling or line charge;
- **exception:** authorised manual final price preserving the calculated price;
- **presentation:** tax-inclusive/exclusive display and currency formatting.

Different ERP labels that produce the same effect map to the same base or adjustment type. A
“standard rate per work unit” is a maintained Assembly/UoM price. A “contract price” is a
maintained price with agreement scope, not a fourth calculation method.

## Formula contract

Formula variables are defined data with a name, business meaning, type, unit and permitted scope.
Examples include money per m², a percentage, a dimensionless factor, a line amount and quantity.
Value resolution may allow quotation line → customer/agreement → Assembly → Assembly Group →
company. Missing or incompatible values fail visibly.

The first evaluator supports allowlisted steps: base cost or maintained price; add a per-unit
amount; multiply by a factor; apply markup or target margin; enforce a minimum/maximum unit price;
round the unit price; add a line charge. It validates UoMs and percentage ranges and protects
against division by zero. Arbitrary scripts, SQL and application functions are forbidden.

Every version has effective dates, lifecycle status, author/reason and preview test cases. The
preview shows inputs and intermediate results so a non-accountant can explain the final price.

## Draft, issue and override

- A draft using current cost can be marked stale and explicitly repriced; maintained price uses
  the selected effective record and does not drift with cost.
- Calculation retains precision until its named unit-price and document rounding boundaries.
- Issue snapshots source/rule/formula versions, all inputs and intermediate results, calculated
  and final prices, currency/FX, rounding and any override reason/approval.
- Issued quotes are immutable; a change creates a quotation revision.

## Authorisation and data scope

- Override permission is separate from quotation entry, viewing internal cost and approval. Each
  Phase 4 slice defines its detailed permission vocabulary; Q25 covers Phase 3 material/cost
  visibility and Q27 governs the reusable approval workflow.

## Boundaries

- Phase 3 material resolution supplies quantity and cost evidence, never the final Sales price.
  Existing Assembly `sell_per_unit` remains guidance; do not apply markup twice or treat it as a
  maintained price. Missing cost is not legitimate zero; [compatibility work](../phases/phase-4-r2-compatibility-plan.md)
  precedes affected trusted cost-derived/control paths.
- Purchasing supplier offers affect replenishment and acquisition cost, not customer-price
  precedence.
- [Tax](tax.md) consumes the resolved net line and independently determines tax.
- Customer-group pricing waits for a real Party segmentation model rather than reusing Inventory
  categories.

## Landmines

- Treating markup and margin as synonyms produces a plausible but wrong price on every quote.
- Choosing the lowest candidate automatically can violate a customer agreement or deliberate
  precedence.
- Recomputing an issued line from current cost or rules rewrites commercial history.
- Allowing untyped amounts in formulas can add money per line to money per unit or mix UoMs.
- Hiding a manual override by replacing the calculated value destroys its audit trail.
- Applying tax inside the price formula gives statutory rules the wrong date and lifecycle.

## Related

- [Assemblies](assemblies.md) · [Sales pipeline](sales-pipeline.md)
- [Material resolution](material-resolution.md) · [Tax](tax.md)
- [ADR 0055](../decisions/0055-progressive-sales-pricing-and-controlled-formulas.md)
