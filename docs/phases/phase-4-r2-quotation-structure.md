[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R2 — Quotation structure and scope: consolidated owner review

**Date:** 2026-10-09. **State:** U1–U6 owner-approved and recorded in accepted
[ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md); compatibility assessment documented, research closure recommended.
**Scope:** A–H as one research package. No implementation or migrations. Initial alternatives below
are retained from `4ca8b44`; 0109 governs the approved direction and resolves the Assembly-only conflict.
[R1 is research-complete](phase-4-r1-decision-matrix.md); this record does not reopen its contracts.
[Actual capabilities](phase-4-r2-existing-capabilities.md) and [three-ERP comparison](phase-4-r2-erp-comparison.md)
provide the evidence. Q40 is resolved by 0109. The [verified audit](phase-4-r2-compatibility-audit.md)
and [compatibility plan](phase-4-r2-compatibility-plan.md) identify delivery gates; closure awaits owner confirmation.

## What we are doing

Describe an offer as identifiable commercial scope, quantities, included choices and recipient
context. Let an estimate explain its internal requirements without making every cost component
another customer charge. A small business should prepare a straightforward offer; sections,
assemblies and alternative scope appear when useful.

## Why it matters

The same ERP must quote cartons, consulting hours and measured project work. A forced elaborate
estimate burdens ordinary selling; a product-only structure loses versioned work requirements.
Ambiguous optional totals or copied scope can overstate forecasts and create duplicate accepted
work. Customer documents must preserve exactly the offered scope while protecting internal cost.

## Practical examples

All amounts below are illustrative maintained net prices in PKR, represented at four decimal
places; they do not decide tax, margin, discounts or the R3 pricing engine.

| Business | Customer offer | Internal meaning and consequence |
|---|---|---|
| Simple product sale | 2 cartons × PKR 12000.0000 = PKR 24000.0000; optional installation PKR 3000.0000 | Fixed content 12 each/carton gives 24 stock units. Base remains PKR 24000.0000; installation is separately offered, excluded until chosen. No compulsory formulation or work phases. Direct Item entry is approved by 0109; functionality remains unimplemented. |
| Service | 8 consulting hours × PKR 1500.0000 = PKR 12000.0000 | An eligible Party may receive this without a CustomerAccount. A catalogue service, labour estimate or controlled manual scope can describe delivery; hours sold are not automatically actual employee hours or a timesheet. |
| Assembly project work | 250 m² wall treatment, two coats × PKR 1200.0000 = PKR 300000.0000 | Pin Assembly version and dimensional context; coating/application rules determine material consumption. Two coats do not silently double the sold area to 500 m². Labour, plant, subcontract and overhead remain internal cost components unless distinct deliverables are explicitly quoted. |

For a premium product alternative at PKR 18000.0000/carton, the corresponding offer is
PKR 36000.0000 for two cartons. Display that alternative and its basis; do not add both variants
into a PKR 60000.0000 base. Two separate buildings may legitimately reuse the same Assembly:
same source does not prove the same scope. Requoting the same building in another family needs
an explicit competing/overlap assessment, not an assumption of additional business.

## Alternatives and effects — decision matrix

This matrix retains the initial alternatives and recommendations from `4ca8b44`. A–H match the
owner's requested scope; 0109 now records the approved bounded direction, not exact implementation details.

| Area | Realistic alternatives and effects | Recommended business direction | Technical implication / dependency |
|---|---|---|---|
| A Identity | One mutable quote loses offer history; independent quote per revision inflates deals; family plus revisions distinguishes negotiation from new scope. | One family for one offer lineage; identifiable revisions and immutable issued offers. Independent scope may use independent families under ADR 0100. | Prefer family number plus revision label; use central Numbering, with allocation timing in R5. Current family CRM association and frozen issue association follow ADR 0099. |
| B Line sources | Universal Assembly preserves ADR 0008 but adds maintenance; optional Item/Assembly/manual source simplifies selling but changes accepted semantics; separate engines multiply controls. | One shared commercial line contract with optional Item, Assembly or controlled manual source, subject to explicit superseding decision. Presentation text/sections carry no charge. | Commercial category is distinct from internal component kind. No physical tables or new per-category engines selected. See conflict gate. |
| C Measurement | One quantity confuses cartons/stock/work; universal cross-dimension conversion is unsafe; independent quantity bases preserve intent. | Explicit offered quantity/UoM, price denominator where needed, and separate derived stock/estimate quantity with conversion evidence. | Reuse global/item conversion and fixed packages; validate increments and bounds, snapshot factors. Exact numeric storage/rounding contract remains a decision gate, not inferred from preview. |
| D Presentation | Plain rows are simplest; sections improve readability; deep hierarchies and priced parent/child rows can confuse totals. | Ordered rows and optional sections/subtotals; flat sections first, work-phase labels without compulsory project taxonomy. | Customer projection excludes cost by whitelist. Headings/subtotals are derived presentation; bundled parent and explanatory children must not both add charges. Nested hierarchy deferred unless owner requires it. |
| E Choices | Only separate competing families limits compact offers; zero quantities obscure optional scope; explicit included/excluded and choose-one groups preserve offered quantities. | Optional add-ons excluded from base; within-quote choose-one alternatives with an explicit included baseline. Keep ADR 0100 family competition separate. | Snapshot offered options and baseline at issue. R6 records permitted selections separately; changed price/scope requires R5 treatment. No portal implementation implied. |
| F Estimate integration | Always use latest sources rewrites intent; freeze everything at draft hides staleness; pin sources and review on adoption/issue makes change visible. | Pin chosen Assembly version/context; separately adopt governed standard/planned requirements and cost evidence where applicable. | Preview is advisory. Revalidate server-side; preserve actual dependencies, variance/mix decisions and authority. R3 defines price effects, R5 stale/issue/approval rules. |
| G Recipient/location | CustomerAccount-only violates R1; one address confuses billing and delivery; mandatory project/site masters burden small businesses. | Eligible contracting Party, contextual contact/billing location, optional execution locations at quote/section/line scope. | Use Party relationships or governed quoted location evidence without fake CustomerSite/accounts. Snapshot customer-facing facts at issue; financial readiness remains separate. |
| H Scope reuse | Source-based uniqueness blocks legitimate repeat work; unrestricted copying duplicates business; explicit intent/provenance permits warning and review. | Stable logical scope identity across revisions, explicit copy/reuse provenance and overlap/competition review. Dependencies are declared business constraints, not inferred from ordering. | New independent copies get new scope identities. Cross-family same-intent links need explicit review; no universal deduplication key. Partial acceptance/dependency enforcement/jobs belong to R6/R7. |

## Approved business direction and retained delivery gates

### Commercial scope and source

A stocked/non-stocked product, service, labour, plant hire or subcontract deliverable can share
quantity, description, commercial price and inclusion controls. Its source determines available
estimate/stock evidence, not a separate quotation workflow. A manual commercial line needs an
explicit description, measure and authorised price; absence of internal cost is UNKNOWN, not zero.
No automatic Item creation, stock movement or accounting posting follows from such a line.

Text is explanatory and unpriced. A genuinely free included deliverable remains real scope with
quantity and possible cost; it must not disappear because its selling amount is zero. Discounts
and surcharges are classified commercial adjustments, with target scope and controlled pricing
rules deferred to R3/R4; negative quantities are not a substitute. Unpriced allowances/rate-only
schedules must not enter a trusted complete base total. Recommend deferring those advanced
categories until a concrete requirement settles their liability and acceptance semantics.

### Units, packaging and dimensions

Capture what is sold separately from what is consumed or stocked. A named fixed package can be
the selling basis; an item-specific carton conversion can also work. Do not give both independent
factors authority over the same conversion. Price per carton or per 100 units is a price basis,
not necessarily the work/stock unit. MOQ/order multiples differ from divisible quantity increments.

Validate increments rather than silently round a customer's scope up/down. Preserve exact decimal
conversion evidence; define where display rounding ends and authoritative quantity/money rounding
begins. Recommend one shared quotation quantity contract after checking six-digit preview input
versus nine-digit conversion precision; storage precision/bounds and the adapter behaviour remain
unsettled. R4 settles financial rounding. Variable-content packages remain Q23.

Company-defined dimensions are advanced estimate context, never compulsory fields for a simple
product/service quote. Product packaging does not imply manufacturing or physical package allocation.

### Presentation, inclusion and selection

Sections organise work phases, locations or packages but are not automatically acceptance units.
Internal components can be exposed as descriptive scope only through an explicit customer-safe
projection; hiding columns in print CSS is insufficient. Any priced parent/child arrangement must
identify which rows contribute to the total and which explain an included bundle.

An optional line retains its offered quantity and amount while excluded from the baseline. A simple
choose-one group contributes its specified baseline once; alternatives show their full amounts and
basis. Do not sum mutually exclusive variants or give an unresolved choice an authoritative base.
Selection must be restricted to valid issued combinations; prerequisite-dependent add-ons need
explicit compatibility evidence. Recommend simple independent add-ons/choose-one groups initially;
complex conditional configurators stay unsupported until R6 validates their acceptance contract.

Issue preserves the offered alternatives and baseline, not a mutable customer order. Acceptance
selects from that evidence under R6; an option chosen later is not a rewrite of the issued revision.
For SELECTED_OFFERS forecasting, only an unambiguous eligible issued baseline may contribute;
unselected options cannot be automatically added. Unresolved combinations cannot provide a trusted
complete amount. Actual acceptance adjusts accepted work and review requirements under 0101/0102;
the detailed baseline/selection reconciliation is a R6/R9 delivery gate before that path ships.

### Estimates, recipients and repeated scope

Pin Assembly version, work quantity/UoM and rate dimensions explicitly. Adopted evidence must cover
resolved application/formulation versions, standard/planned material requirements, resource rates,
overhead, cost scope/source/time and relevant approval decisions. Snapshot actual values as well
as references; a reference to today's catalogue is insufficient historical evidence. Preview hashes
are not a substitute for dependency/eligibility checks. Do not silently swap to a newer Assembly or
reprice a maintained commercial offer when costs change. Draft refresh and issue timing remain R5.

The contracting Party remains authoritative even when a contact receives the document or execution
occurs elsewhere. A billing address is not a new debtor; a site owner is not automatically the buyer.
Do not invent third-party payer/accounting rules in R2. Quote-local location descriptions are a
proposal for prospects lacking account-backed sites, with controlled provenance and snapshots.
Multiple locations should appear only where the work needs them; no new mandatory Project module.

Logical scope identity represents intended deliverable, not row position or Assembly ID. Preserve
identity for the same intended work across revisions; genuinely replaced work gets a new identity
and predecessor provenance. Exact material-change and accepted-scope adjustment rules remain R6.
Copies default to new independent scope, with explicit source provenance; claiming the same intent
requires explicit review. Warn on plausible duplicated descriptions/source/location/quantity without
hard uniqueness on those attributes. A dependency such as installation requiring a supplied rack
must be declared, not inferred from section order. Unsupported conditional scope must be refused
on affected acceptance/handover paths, not quietly treated as independently acceptable.

## Initial architectural conflict assessment — resolved by ADR 0109

**Historical blocker: Assembly-only selling.** Previously accepted
[ADR 0008](../decisions/0008-assembly-abstraction.md) makes Assembly the sellable unit even for goods;
[ADR 0055](../decisions/0055-progressive-sales-pricing-and-controlled-formulas.md) explicitly layers
Sales pricing on that abstraction. Optional direct Item/manual sourcing cannot be presented as
already compatible. Two viable owner choices are:

1. Keep universal Assembly; simplify entry through governed one-component/backstage Assembly
   creation. This preserves the contract but still requires versioning, provenance and maintenance.
2. Approve a shared commercial scope with optional estimate source. Recommended for ordinary
   selling; requires a new ADR expressly superseding the relevant Assembly-only contract and
   reconciling 0055's scope without discarding its progressive pricing/control decisions.

At initial research no ADR was superseded. Subsequent U1–U6 owner approval selected option 2;
0109 explicitly supersedes 0008/0055, retaining their valid contracts. Only their status links changed.
The old Sales domain's compulsory `assembly_version_id` remains historical planning, not a settled
future physical schema. Source-neutral pricing and manual-line authorisation must be reviewed in R3.

**Compatible if safeguards retained:** R1 family/CRM/policy contracts (0098/0099), independent and
exclusive offers (0100), forecast evidence/trust (0101/0102), Party and readiness (0103–0105), and
ownership/access/CRM (0106–0108). Within-quote choice groups must not silently redefine family
competition. Revisions or new previews do not automatically replace selected forecast evidence.
No automatic opportunity win, CustomerAccount creation, material reservation, job or invoice.

**Implementation dependencies, not reopened R1 blockers:** generic cost query, adopted plans,
Sales approvals/numbering, customer projection, quote quantity contract and valid choice evidence.
Reuse shared company isolation, field-sensitive capability checks at request and Action, audit,
transaction/locks, stale-state refusal and idempotent append-only evidence. Exact concurrency and
failure/retry tests follow R5/R6; failed issue/selection must not leave successful evidence.

## Six owner-reviewed bundles

| Bundle | Reviewed subject | Initial recommendation, now bounded by 0109 |
|---|---|---|
| U1 Identity, line sources and scope reuse | Family/revision identity; universal Assembly versus optional sources; repeated-work provenance | Family/revision/issued identity with stable logical scope; choose optional sources through an explicit superseding ADR, never by assumption. |
| U2 Measurement | Sold/work/stock/price measures, package authority, increments and precision | Separate bases and snapshot conversions; approve semantics now, require explicit precision/bounds reconciliation before implementation. |
| U3 Presentation | Sections, subtotal rules, hierarchy and customer visibility | Flat optional sections first; customer-safe projection; no additive priced parent plus priced explanatory children. |
| U4 Options and dependencies | Baseline totals, optional add-ons, choose-one groups and initial complexity | Explicit included baseline; options excluded until selected; simple groups first, with R6/R9 selection and forecast delivery gates. |
| U5 Estimate adoption | Pinned sources, cost uncertainty and refreshing evidence | Separate adopted estimate from commercial scope; no silent latest-version substitution; preserve variance/mix controls and UNKNOWN cost. |
| U6 Recipient and locations | Party/contact/billing/execution context and prospect location handling | Party-backed recipient, optional multiple execution contexts, no compulsory financial account or project master; immutable issued facts. |

These bundles are owner-approved bounded business direction, recorded in one coherent architecture
ADR 0109 rather than six separate engines. Q40 is resolved. Exact quantity precision, source-aware
pricing and API rollout remain explicit delivery gates. The compatibility audit supports closing
bounded R2 business research independently of code delivery; formal closure awaits owner confirmation.

## Deferred questions and handoffs

| Package | Owns the remaining detail | R2 input / safeguard |
|---|---|---|
| R3 Pricing | Source-neutral price applicability if U1 approved, manual authority, adjustment targeting, price denominator, margin and complete-cost requirements | One commercial contract, costs separate; no implied zero price/cost. |
| R4 Tax/FX | Tax treatment of chosen combinations, allocation/rounding, fallback/rate age/corrections and commitment valuation | Preserve independent monetary/FX evidence; ordinary later FX movement does not invalidate historical forecast publication. |
| R5 Lifecycle | Draft/revision limits, version eligibility, expiry/withdrawal/supersession, snapshot/number timing, stale adoption and approval invalidation | Immutable issued scope, baseline, recipient and estimate evidence; atomic retries; no automatic forecast replacement. |
| R6 Acceptance | Choice evidence, valid combinations/prerequisites, partial quantities, overlap and accepted-scope adjustments | Distinguish logical scope, presentation and exclusivity; accepted work cannot be counted again. |
| R7 Handover | Multiple accepted scopes/locations/jobs, dependency satisfaction, operational readiness, estimate baselines and corrections | No implicit one-section/one-family/one-opportunity = one job; quotation does not reserve stock. |
| R8 Documents | Customer projection/template, optional/alternative display, snapshot rendering, PDF/delivery/portal evidence | No internal cost leakage or portal mutation of issued evidence. |
| R9 Access/reporting | Scope-sensitive capabilities, reuse warnings, selected-baseline eligibility/overlap, historical analytics/imports/integration | Ownership is not permission; no mixed valuation bases or silent option/alternative aggregation. |

**Next:** owner review of the [compatibility plan](phase-4-r2-compatibility-plan.md) and research
closure recommendation. Approved business architecture does not authorise its code slices.
Do not begin R3 research, implement, create migrations or change API contracts in this assessment.

## Related

- [Approved ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md) · [Verified impact matrix](phase-4-r2-compatibility-audit.md) · [Compatibility plan](phase-4-r2-compatibility-plan.md)

- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [Phase index](README.md)
- [Actual capability review](phase-4-r2-existing-capabilities.md) · [Primary ERP comparison](phase-4-r2-erp-comparison.md)
- [R1 accepted matrix](phase-4-r1-decision-matrix.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [Invariants](../architecture/invariants.md) · [Research rules](../architecture/research-and-design.md)
