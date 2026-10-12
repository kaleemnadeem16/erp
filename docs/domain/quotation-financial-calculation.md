[← Domain index](README.md)

# Quotation financial calculation

**ACCEPTED architecture:** ADRs [0118](../decisions/0118-pin-quotation-pricing-dates-and-company-base-fx.md),
[0119](../decisions/0119-determine-versioned-quotation-tax-components.md),
[0120](../decisions/0120-settle-quotation-rounding-and-financial-evidence.md), F1–F8/Q44.
**SPECIFIED delivery:** this contract and [R4 slices](../phases/phase-4-r4-implementation-slices.md).
**IMPLEMENTED / VERIFIED:** no R3/R4 delivery claimed. Separate coding authorisation is required.
R5 owns quotation aggregate/state/issue identity; R9 owns final duties. No new Finance engine.

## Input and result contracts

`QuotationFinancialContext` contains authenticated actor, company, scope, Party, typed selling
source (Item goods/service, Assembly, manual), declared transaction nature, offered/priced/package/
stock quantities and conversion versions, quote/source/base currencies, explicit pricing and tax
estimate dates, central company time zone, optional jurisdiction/place/channel/location facts,
selected agreement, pricing result, applicable complete/partial/unknown cost and dependency vector.
Client fields select legitimate inputs; server resolves profile, eligibility, rates, policy and cost.
Location applicability does not grant record access. No CustomerAccount is required for a prospect.

`FinancialResult` contains canonical net, per-line settled net, document sum, output component
results, gross, separate withholding estimate, estimated payment (nullable if unknown), computed
base conversions, allocation contributions/residuals, controls and provenance. Monetary inputs and
stored results are decimal strings, never floats. Higher-precision/raw/rational evidence is preserved
as decimal strings or numerator/denominator strings, not converted to binary JSON numbers. UNKNOWN is a typed state with reason, not zero.
A known exempt component is zero with exemption evidence; a missing component is UNKNOWN.
Gross is net plus collected output tax; withholding reduces estimated payment only. Commercial
charges use their own typed lines/bases; they are not statutory tax or concealed tax components.

## Typed model — annotated pseudo-DDL, not migrations

Business rows carry `id, company_id`; cross-company references use composite FKs. Global currency
IDs use the existing global registry. Activated/used economic versions are immutable; availability,
revocation and current projection are separate events. Model names are implementation contracts,
not permission to create orphan quote tables before R5 chooses aggregate identities.

```text
sales_fx_policy_versions
  company/version unique; EXACT_DATE | BOUNDED_PRIOR; max_age_days >= 0
  route COMPANY_BASE_BRIDGE; rate_type SPOT; effective_from, effective_end_exclusive
  source-price conversion allowance remains on R3 term/agreement version
quotation_fx_evidence (R5 owner identity reserved)
  requested_date, time_zone, observed_at_utc, policy_version, route, base_currency
  source_to_base_leg, quote_to_base_leg (pair/type/value9/actual_date/age/source)
  rate_master_id optional provenance, copied immutable values; identity_leg explicit
  derived_ratio high-precision/rational evidence, calculation_version
financial_rounding_policy_versions
  currency, unit_scale(default4), line_net_quantum, rounding_mode(default HALF_UP)
  tax_stage LINE_COMPONENT | ELIGIBLE_AGGREGATE; grouping_signature; dependency_stage
  allocation_method LARGEST_REMAINDER; tie_key logical_line_id; inclusion_profile
source_tax_classification_versions
  typed source/company identity, GOODS | SERVICE | other supported declared nature
  classification_id, effective period, assignment/default provenance
party_tax_profile_versions / exemption_versions
  Party/company, declared jurisdiction/context, effective period, component identities
  disposition, reason, supporting evidence references, issuer/expiry where applicable
  no fabricated original certificate/approval or customer-account dependency
tax_component_rule_versions
  stable component_id, OUTPUT_COLLECTED role, jurisdiction, rate_version/effective dates
  method PERCENT | FIXED_LINE | FIXED_DOCUMENT | PER_UNIT
  base NET | TAX_AMOUNT(component_ids) | NET_PLUS_COMPONENTS(component_ids)
  dependency_stage RAW | SETTLED; fixed_amount4/currency or rate9; declared_unit/basis
  required_tax_mode, supported_calculation_adapter, rounding_policy_version
tax_group_versions / tax_group_component_edges
  stable component identities and versions, named dependencies, deterministic order
  inclusion eligibility; no row-number dependency or GL-account-only identity
withholding_rule_versions (separate role and result)
  declared NET | GROSS | supported explicit base; rate/period/context requirements
  cumulative fact requirements; estimate only, no settlement certificate entries
quotation_financial_snapshots / component_evidence / allocation_evidence (R5 keys reserved)
  input_intent NET_FIXED | GROSS_FIXED; included_component_ids; original_input4
  canonical_net4, settled_line_net4, output_tax4, gross4; currency and computed_base4
  withholding_status/estimate4, payment_status/estimate4, reasons
  raw_decimal/rational_evidence, base/dependency/disposition, extraction_residual
  allocated_contribution4, reconciliation_residual4, weights/quantum/tie_policy
  source/profile/exemption/rate/group/FX/rounding/calculation/cost/policy versions
  actor, UTC timestamp, dependency fingerprint, parent/original evidence links
financial_operation_receipts
  company/actor/action/subject/key unique; request_hash; immutable outcome reference
```

Fixed-per-document components execute once over an eligible declared document base, then allocate
to eligible chargeable logical lines; never repeat per line. Child/explanatory lines do not charge
again. Optional/choose-one alternatives require R5/R6's agreed baseline identity before operational
calculation; internal fixtures may supply explicit selected scope, never presume every alternative.
Credit/negative document types remain Finance-owned; initial Sales quotes refuse negative scope.

## Pipeline, dependencies and numerical semantics

1. Validate actor/company/record eligibility and typed quantities/context; capture current dependency
   generation. Effective commercial dates use half-open local date intervals, UTC observations.
2. Resolve manual SPOT legs with one requested date and policy. Exact date defaults; bounded prior
   selects latest eligible on/before date independently per leg, recording both ages. No future rate,
   provider, silent reciprocal or arbitrary direct leg. With source USD100, USD/base280 and EUR/base350,
   EUR net is80; do not round source/base28000 before taking the ratio. Base identity rate is one.
3. Determine classification/profile/jurisdiction and component dispositions. Item goods/service
   defaults differ; persist initial governed classification before use with truthful baseline
   provenance. Category movement is not an undocumented historical tax-classification rewrite.
4. Validate effective component graph and mode support. NET bases are normally discounted commercial
   net. A10% NET plus B5% NET on1000 yields1150 gross; B5% NET_PLUS_COMPONENTS(A) gives1155;
   B5% TAX_AMOUNT(A) gives1105. Unsupported deemed bases need a declared adapter.
5. Normalise NET_FIXED/GROSS_FIXED separately from R3 formula evaluation. GROSS_FIXED1155 including
   A/B compound extracts1000; GROSS_FIXED1100 including A10% plus excluded B5% NET gives1150.
   For net-dependent pricing, use the declared source adapter then R3 adjustment plan once, re-evaluate
   output tax on resulting net. For a supported affine included graph whose included taxes sum
   `a × net + b`, extract `net = (gross_input - b) / (1 + a)` at rational precision; quantity/fixed
   terms in b must already be known, denominator positive, and the declared inclusion rounding
   profile validated. Rounded/non-affine inversion requires a supported deterministic adapter;
   otherwise refuse. A discretionary discount changes final payable gross; original
   gross intent describes input, not a guarantee after a later commercial adjustment. R3 benefits
   cannot be duplicated by normalisation. Reject non-invertible/unknown or unsupported mixed graphs.
6. Apply default unit4; quantity multiplication/adjustments use higher precision; final line net rounds
   to currency quantum. Allocate document commercial benefits to selected eligible lines before
   final settlement/controls. Document net sums settled lines; no extra header net rounding.
7. Evaluate graph respecting RAW/SETTLED dependencies. For aggregate rounding, topologically settle
   predecessor groups and allocate their results before a dependent SETTLED base. Compatible groups
   share component/rate/jurisdiction/disposition/policy; never merge exempt/unknown with taxable.
   Round component or eligible aggregate as configured; preserve raw and rounded values separately.
8. Allocate totals deterministically, preserving eligibility and non-negative caps. Three0.05 lines
   at10% yield line-rounded tax0.03 or aggregate0.02 allocated0.01/0.01/0 by stable tie order.
   Record both computed FX base amounts and separate balanced contributions/residuals.
9. Evaluate floors/margin on settled canonical net and pinned comparable base/quantity with complete
   applicable cost. Refuse mandatory UNEVALUABLE; advisory reports warning. Output tax and withholding
   never count as revenue. Missing costs remain PARTIAL/UNKNOWN under 0110; no historical cost invention.
10. Estimate withholding separately: net1000 plus tax100 gives gross1100; withholding5% NET=50,
    estimated payment1050. GROSS basis=55 gives1045. Missing cumulative facts leave payment UNKNOWN
    while otherwise complete commercial totals remain known. Issue treatment of unknown estimates
    is an R5 owner choice, not authority for a tax/mandatory-control bypass.

Inclusion rounding uses the activated profile's explicit extraction-residual tolerance and recipient;
no universal tolerance or undocumented balancing edit. An unsatisfiable gross/net/component
combination refuses. Rates, max age, local rules and permitted rounding vary through governed config.

## Actions, Requests, security and consistency

Internal services: `ResolveQuotationFx`, `DetermineQuotationTax`, `NormaliseInclusivePrice`,
`CalculateQuotationFinancials`, `AllocateFinancialResiduals`, `EvaluateSettledNetControls` consume
only typed context. Exposed `PreviewQuotationFinancials` and configuration/profile Actions guard
actor/company/scope themselves, even when invoked outside HTTP. Operational persistence/adoption/
issue Actions belong to R5 and reuse the pipeline atomically; preview never reserves/adopts/posts.

Proposed Requests: `StoreSalesFxPolicyRequest`, `StoreFinancialRoundingPolicyRequest`,
`StoreTaxGroupVersionRequest`, `ActivateTaxGroupVersionRequest`, `StorePartyTaxProfileRequest`,
`PreviewQuotationFinancialsRequest`. Reject unknown economic/cost/master-rate result fields;
accept version/context IDs only through scoped lookup. Do not trust supplied effective permissions,
computed rate, exemption status, base amounts, cost or final PASS. Both Request and Action guard
independent relevant read eligibility and exact duty. Costs/protected policy traces are server-only
or projected through legitimate sensitive-read authority; blind calculations do not grant cost read.

Reuse registered `currency.exchange-rate.manage` for existing manual master maintenance only.
It is not quote-FX override, tax setup, exemption override or pricing risk approval. Proposed R4
configuration/profile/exemption duties are pending final R9 vocabulary/grants (see slices).
No automatic salesperson/manager grants; explicit designated duty owners, same-company/read scope,
registry/permission ratchet and genuine-full-access-only re-sync. New public surfaces stay disabled
until this contract is approved and delivered. Existing CRM duties supply no pricing/tax authority.

Version writes require expected generation; activate locks company configuration generation and
validates complete graph/rates/policies before switching pointer in one transaction. Quote writes
will lock R5 aggregate then dependent records in stable ID order, re-read dates/config/profile/rates/
cost/authority, refuse stale context, calculate and append evidence atomically. R5 must specify exact
lock ownership/fingerprint; this does not invent an issue transaction outside its aggregate.

Retryable mutations require idempotency key, scoped request hash and atomic receipt/outcome. After
current authorisation, matching completed receipt replays original result before new stale checks;
same key/different payload conflicts. Concurrent same-key execution cannot duplicate versions or
snapshots. Failed transactions persist no partial economic evidence. Read-only preview uses a consistent read and compares dependency generation after calculation;
if facts changed, refuse stale instead of returning a mixed-version result. Preview is not a write
receipt and cannot be presented as approval/adoption authority.

## Database, migration and API/UI contract

CHECKs: money precision/finite non-negative initial quotes; positive FX/factors/basis; valid rate/
percent ranges; valid half-open intervals; exactly applicable method fields and typed source IDs;
NET_FIXED cannot name included components; known/unknown result status matches nullable amount;
identity leg same currency/value1; effective group unique component IDs. Composite company FKs;
exclusion constraints for conflicting effective exact partitions; immutable used-version guards.
Cycles/cross-profile semantic conflicts require Action validation under generation lock, not a
claim that a simple CHECK validates graphs. Stable evidence references survive master deletion
via copied facts/restrict or soft-delete; no cascade deleting issued evidence.

Additive migrations with real down paths and isolated apply/rollback/reapply verification belong to
coding. Do not backfill taxes, exemptions, historical rates or original receipt facts from today's
configuration. Any captured preexisting baseline is explicitly dated after initial receipt. No
quote evidence schema before accepted R5 keys; no change to PO casts/rounding by this Sales task.
MoneyCast must undergo scoped minor-unit/custom4 investigation and adapter regression verification.

Proposed configuration APIs under existing API prefix: `/sales-fx-policies`,
`/quotation-rounding-policies`, `/tax-group-versions` (activate named transition),
`/parties/{party}/tax-profile-versions`; immutable economic edits create successor versions.
R5 binds operational `/quotations/{id}/financial-preview` and financial evidence resources; no live
public endpoint implied now. Resources expose decimal strings, selected dates/currencies, actual
FX age/route, known/unknown tax/payment state, safe component explanations and settled totals.
Protected cost, other Parties' exemptions and internal authority traces never leak in errors,
nested results, history, exports or generated-client surfaces.

Stable proposed refusal codes: `FX_RATE_MISSING`, `FX_RATE_TOO_OLD`, `FX_ROUTE_UNSUPPORTED`,
`TAX_CONTEXT_UNKNOWN`, `TAX_DEPENDENCY_INVALID`, `TAX_CALCULATION_UNSUPPORTED`,
`INCLUSIVE_NORMALISATION_UNSUPPORTED`, `ROUNDING_POLICY_INCOMPATIBLE`,
`QUANTITY_NOT_REPRESENTABLE`, `FINANCIAL_DEPENDENCIES_STALE`, `MANDATORY_CONTROL_UNEVALUABLE`.
Use existing API status/scope conventions; ordinary validation422, stale/version conflicts409,
forbidden403 or scoped-not-found as appropriate. No raw database/dependency details to outsiders.

Configuration sheets show inclusive through-date vs internal exclusive end, named dependencies,
rate effective dates, method/mode support and activation failures. Operational R5 form shows one
quote currency, deliberate pricing-date/refresh, net/gross intent and included components, FX actual
age/route, component dispositions, net/output/gross and separately estimated withholding/payment.
Known warnings, mandatory refusals, unknown payment and stale review are distinguishable. No live
rate/date edit silently refreshes the draft. Hide unsupported advanced controls; preserve historical
readability after mode changes. Shared shells/sheets, mobile and dark/light states follow standards.

## Related

- [R4 slices](../phases/phase-4-r4-implementation-slices.md) · [Accepted review](../phases/phase-4-r4-financial-review.md)
- [Tax examples](../phases/phase-4-r4-tax-composition.md) · [Tax](tax.md) · [Currencies](multi-currency.md)
- [Pricing resolution](sales-pricing-resolution.md) · [R5 research](../phases/phase-4-r5-quotation-lifecycle.md)
- [Authorisation](../architecture/authorization.md) · [Domain integrity](../architecture/domain-integrity.md)
