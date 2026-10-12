[← R4 decision matrix](phase-4-r4-financial-review.md)

# R4 — Core tax composition, withholding and worked examples

**2026-10-12 · ACCEPTED architectural examples under 0119/0120; no engine implementation.**
The reviewed numerical configurations remain illustrative, not jurisdiction law or prescribed rates.
Multiple taxes/dependencies/inclusion/exemptions/withholding are designed now; only explicitly
supported methods may be enabled later. The [typed contract](../domain/quotation-financial-calculation.md)
and [slices](phase-4-r4-implementation-slices.md) govern delivery with R5–R9 gates.

## Generic component model — architecture now, execution progressively

Determine eligibility from company regime, tax classification/nature, Party profile/exemption,
declared place-of-supply/jurisdiction facts, transaction/estimate tax date and source quantities.
Keep separate commercial pricing context, statutory context and access scope. Optional CustomerAccount
is not the tax-profile owner; a Party-backed prospect can supply tax context without debtor setup.

Retain 0056 default classification resolution/persistence before first use: Item explicit → category
default → appropriate company nature default; Assembly explicit → Assembly Group → services default.
Extending the original universal Item “goods default” to service Items/manual declared nature needs
owner approval/new ADR, not silent rewriting. Resolved master classification is not dynamically
changed by a category move; explicit governed classification correction/version affects future
resolution and dependent drafts, never historical transactions. Tax rates/context still resolve at
their appropriate dates; persisting classification does not freeze tax law.

Manual lines carry explicit nature/classification evidence or configured non-applicability, no fake
Item. Assembly work is classified as its offered work, not sum of component taxes unless an approved
mixed-supply adapter explicitly requires splitting; that future adapter must preserve commercial
parent/child no-double-charge. Package identity never supplies tax jurisdiction/nature by itself.

Proposed logical records below are a contract for review, not selected migrations/table names:

| Responsibility | Facts required now |
|---|---|
| Tax identity | Company-scoped statutory component code/name, OUTPUT_COLLECTED versus separate WITHHOLDING treatment; direction, jurisdiction/report identity. Commercial charges have different identity. |
| Effective rule/rate versions | Immutable used version, validity, applicability/disposition, type and decimal rate/amount, currency, UoM/denominator, quantity/fixed scope, named taxable basis and controlled input definitions. |
| Tax group/template version | Named collection and explicit dependencies/inclusion flags/calculation stages/rounding settings, eligible transaction context and required tax mode; not one rate or a sum of rates. |
| Dependency edge | Stable component references and typed basis contribution (tax-only or net-plus-selected components); optional explicitly named taxable commercial charge basis. Acyclic, compatible scope/UoM/currency and no missing/duplicate edges. |
| Source classifications and Party profiles | Goods/service facts, registrations, applicable jurisdiction, exemption/certificate reference/validity/component coverage, actor/reason/version. No single exclusive Party kind. |
| Document determination/evidence | Rule/classification/profile/group versions, requested/effective dates, facts and selection reasons, each component base/rate/quantity/amount/inclusion/disposition; before/after rounding and allocation, transaction/base currencies and pinned FX. |
| Withholding estimate | Separate rule/category/basis/rate/date/profile and known versus unevaluable threshold context; estimated withheld and estimated cash, no output-tax reduction. Actual receipt/remittance/certificate accounting belongs to Finance. |
| Future Finance adapters | Recovery distributions, non-recoverable cost/GL/report mappings, statutory invoice tax point, self-assessment/reverse-charge and localisation provider evidence. Retain 0056 concepts without quote-ledger postings. |

Calculation types: PERCENTAGE × explicit monetary base; FIXED_PER_LINE once for that assessed line;
FIXED_PER_DOCUMENT once for the document; AMOUNT_PER_UNIT × declared assessed quantity/denominator.
Fixed amounts declare tax currency and conversion policy; quantity bases may use offered packages,
stock units or technical/service measure only with explicit compatible evidence. “Quantity” never
means sum of unlike kg/hour/carton values. Advanced restricted formulas/providers stay allowlisted,
typed, versioned, bounded and independently authorised under 0056, not executable SQL/scripts.

Tax bases use explicit `NET`, `TAX_AMOUNT([component IDs])`, `NET_PLUS_COMPONENTS([IDs])`, or a
supported named statutory value/charge basis. No implicit “all preceding rows”. Stable IDs survive
screen sorting; evaluation uses validated topological order, display order has no arithmetic authority.
For a downstream component, declare whether dependency amount is raw or legally rounded/allocated
at its stage. Aggregate tax calculations group only compatible identity/rate/jurisdiction/disposition.
A header component is assessed once and has line allocations, not a second charge per line.

Activation/preview rejects cycles/self-reference/missing dependencies, unsupported reverse inclusion,
duplicate component identity for the same assessed slice, incompatible currencies/UoMs, ambiguous
jurisdiction matches and missing mandatory facts. An exemption of a referenced component yields an
explicit zero amount/disposition if valid; missing component/rate is unknown, never assumed zero.
Dependent applicability may require the parent to be taxable only when configured explicitly.

BASIC/LOCALISED/ADVANCED tax modes remain0056's independent company capability. BASIC can expose
one straightforward percentage; LOCALISED multiple components/exemptions/inclusivity; ADVANCED
compound/fixed/quantity/restricted rules. Design their shared model now; later slices may expose
only supported execution. A configured multi-tax transaction cannot collapse to BASIC's first tax
or silently ignore an unsupported component. Mode downgrade/history requires the same coherent
fail-closed review as R3, without pretending tax execution is delivered.

## Same net amount, several legitimate configured totals

Every row starts with one tax-exclusive line net `1000.0000`, unless its input column says inclusive.
No discount, jurisdiction assumption or rounding ambiguity in this first comparison.

| Configuration | Explicit basis/effect | Components | Gross payable |
|---|---|---|---:|
| Two additive percentage taxes | A=10% NET; B=5% NET | A `100.0000`, B `50.0000` | **`1150.0000`** |
| Compounded second tax | A=10% NET; B=5%(NET+A) | A `100.0000`, B `55.0000` | **`1155.0000`** |
| Tax on tax amount only | A=10% NET; B=5%A | A `100.0000`, B `5.0000` | **`1105.0000`** |
| Fixed line levy | F=`10.0000` once | F `10.0000` | **`1010.0000`** |
| Per-stock-unit levy | Q=`2.0000` × 20 assessed units | Q `40.0000` | **`1040.0000`** |
| Quantity levy plus tax on levy-inclusive subtotal | Q as above; A=10%(NET+Q) | Q `40.0000`, A `104.0000` | **`1144.0000`** |
| Ten10-unit cartons, `2.0000` per stock unit | Q=`2.0000` × 100 units, not10 cartons | Q `200.0000` | **`1200.0000`** |

These are distinct tax configurations. Summing 10%+5% reproduces only the first. A commercial delivery
charge of `10.0000` may also affect payable/taxable base under an explicit rule, but it is price/charge
income, not a statutory tax merely because a vendor template lets both appear in one table.

### Discounts, inclusivity and exemptions

| Scenario | Calculation | Result |
|---|---|---|
| Ordinary20% commercial discount, additive10%/5% taxes | Net1000→800; taxes80+40 | Net `800.0000`, tax `120.0000`, payable `920.0000`. Taxable basis is after discount in this configured regime. |
| Entered1150 includes both additive taxes | Solve P=N+0.10N+0.05N | Net `1000.0000`, included100+50; payable `1150.0000`. |
| Entered1155 includes both compounded taxes | Solve P=N+0.10N+0.05(N+0.10N)=1.155N | Net `1000.0000`, included100+55; payable `1155.0000`, not1155÷1.15. |
| Entered1100 includes A10%; B5% NET excluded | Solve1100=N+A; then add B | Net `1000.0000`, A100 included/B50 extra; payable `1150.0000`. |
| Same mixed inclusion, B5%(NET+A) excluded | A100 included, B55 extra | Payable `1155.0000`. |
| Included fixed levy10 within entered1010 | Net=P−10 | Net `1000.0000`, included10, payable `1010.0000`; a discount on net does not reduce the fixed levy unless its rule says so. |
| Three different lines | Goods1000 with10%+5%, service500 with5%, exempt Item200 | Net `1700.0000`, taxes150+25+0=`175.0000`, gross `1875.0000`. |
| Customer exempt from A10%, still liable for additive B5% | A EXEMPT0; B5% NET=50 | Net1000, tax50, gross `1050.0000`; not blanket tax-free. |
| Same A exemption, B5% on A only | A EXEMPT0; eligible B base explicitly0 | B0, gross `1000.0000`; if B's eligibility requires taxable A, record B NOT_APPLICABLE instead. |

An inclusive adapter receives declared included component IDs, plan versions, final offered input and
allowed discount semantics. Solve a supported affine dependency graph without premature rounding;
extract canonical tax-exclusive net before final R3 controls, then calculate excluded components.
Unique solution/unit compatibility/non-negative result required; ambiguity, nonlinear unsupported
basis and circularity refuse. Tax determination/inversion remain outside Sales formula execution;
input tax context is evidence, not a magic “add inclusive tax again” switch.

Two commercial intents must be explicit. NET_FIXED1000 with10% tax displays1100; exempt outcome
keeps net1000/payable1000. GROSS_FIXED1100 including that component can retain payable1100 with
net1100 after exemption, if that is the actual authorised agreement. Never silently reinterpret a
retained R3 maintained1000 net as inclusive1000. Changing tax plan/exemption can change extracted
net/margin on a gross-fixed draft; reprice/review and R5 approval invalidation must capture it.
Exact discount “fixed amount off gross” is an explicitly different normalisation input from “fixed
amount off net”; ordinary R3 discounts default to canonical net. Excluded output taxes are added once.

### Withholding is a separate payment bridge

For net1000 with output10%=100, commercial gross remains **`1100.0000`**.

| Explicit withholding rule | Estimated withheld | Estimated cash from customer | Commercial gross/output tax |
|---|---:|---:|---|
| 5% on net1000 | `50.0000` | `1050.0000` | Unchanged1100/100 |
| 5% on gross1100 | `55.0000` | `1045.0000` | Unchanged1100/100 |
| Cumulative threshold needs unavailable Finance history | Unknown | Unknown, not a false exact1050 | Gross/output remain known |

Actual Finance settlement records cash plus supported withheld tax credit/payable/certificate against
the original receivable; quotation estimates create none of those facts. Withholding is not negative
output tax, commercial discount, retention or permission to close a receivable. Additional statutory
collections/remittance need localisation/accounting authority if outside0025/0056's settlement contract.

## Rounding and allocation examples

Assume two-decimal currency and HALF_UP purely for these proposed comparison policies. Money persists
at storage4, intermediary decimal/rational work preserves required precision.

| Same transaction | Policy boundary | Result |
|---|---|---:|
| Three lines, each qty3 at unit0.3350 | Unit rounds to0.34, each3×0.34=1.02 | **`3.0600`** |
| Same | Unit retains4; each raw1.0050 rounds to1.01 at line | **`3.0300`** |
| Same | Raw combined3.0150 rounds once at document | **`3.0200`** |
| Three0.05 lines,10% tax | Each0.0050 rounds0.01 | Tax **`0.0300`** |
| Same | Aggregate base0.15, raw tax0.0150 rounds0.02 | Tax **`0.0200`**, allocated0.01/0.01/0 by stable IDs |
| Three100 lines, document discount1 | Exact shares1/3; allocate quantum0.01 largest remainder | `0.3400`/`0.3300`/`0.3300`, sum **`1.0000`** |

Recommended commercial default: explicit unit boundary4 (retains0055's order), final line net at
currency quantum, document sum of final line nets. Do not globally round a sum again and conceal a
residual. Tax policy chooses line/component versus compatible aggregate, rounds at declared stages
and allocates before later dependent components where required. Do not allocate one tax group's
rounding to an exempt line or different jurisdiction. Proposed tie-break is logical line/component
identity, not editable row order; preserve raw/base/final/residual/allocated amounts and policy version.
Document-wide discounts have an explicit eligible positive base and per-line allocation before
ordinary taxable-net determination; incompatible/missing/zero basis refuses, not equal spreading.
R6 must separately settle partial/choice/remaining-unit allocation without cumulative over-refund.

Transaction/base totals also require explicit reconciliation. Suppose three lines `0.0100` each,
pinned FX`0.333000000`: each raw base`0.00333` rounds at storage4 to`0.0033`, sum`0.0099`;
document raw`0.00999` rounds`0.0100`. Keep each0012-compliant computed `base_amount` unchanged.
If converted document total is authoritative, record the separate base reconciliation residual
`0.0001` and its line allocations (allocated contributions0.0034/0.0033/0.0033). Computed base and
allocated contribution are distinct fields/facts; do not overwrite computed base or pretend equality.
Finance later reconciles the residual explicitly in base-currency accounting, not as output tax or
transaction-currency price. No journal/residual-account workflow is implemented in R4.

For a gross-fixed inclusive amount, rounded included gross must equal settled net plus included
components. Proposed normalisation profile lets net carry an explicitly recorded extraction residual
only where its tax calculation/rounding contract permits; revalidate final net/controls and component
bases. If lawful component rounding and fixed gross cannot reconcile under the supported policy,
refuse rather than silently change tax, gross or price to satisfy the margin. This inclusion-rounding
policy is part of F6 owner review, not a hidden final-total adjustment.

## Financial evidence, refusals and phase ownership

Retain both currencies, source price currency/conversion allowance, pinned FX route/legs with requested/
actual dates and source/value, raw and normalised price intent, line/quantity/denominator/factors,
discount/charge/reference allocations, component eligibility/exemption/classification/profile evidence,
tax estimate date versus legal tax point, graph bases/order/rate/inclusion/stages, rounding/residuals,
net/tax/gross and separately known/unknown cash/withheld estimate. Company/time zone and policy
versions are snapshot facts. Used component/rate/template and issued evidence are immutable.

Fail closed for unsupported component/mode/quantity basis/FX/inversion, missing required jurisdiction/
classification/rate, conflicting eligibility, cycles and unevaluable mandatory controls. Explicit zero,
exempt, not-applicable and unknown have different reporting/explanation even when displayed tax is0.
No blanket seller tax toggle or exemption edit bypass; configuration/exemption override/read, FX entry,
price entry and document approval are separate R9 duties enforced at Request and Action.

**Design now:** all composition/inclusion/withholding/exemption contracts, financial precision and
versioned allocations needed by quotation/pricing schema. **Implement later:** jurisdiction-specific
rules, legal validation/provider, Finance GL/recovery/returns/credits/refunds/withholding/remittance/
certificates and actual cumulative histories. R5 owns quote issue/staleness; R6 owns accepted quantity/
options/benefit reconciliation; R7 owns order/job inheritance; R8 owns commercial/tax display/delivery;
R9 owns permissions/reporting. Later invoice determines legal tax at its own configured facts/date;
no blind copy of quote estimate or live rewrite of prior quotation is permissible.

## Related

- [R4 decision matrix](phase-4-r4-financial-review.md) · [Primary evidence/readiness](phase-4-r4-evidence-and-readiness.md)
- [Tax contract](../domain/tax.md) · [Currencies](../domain/multi-currency.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Phase index](README.md)
