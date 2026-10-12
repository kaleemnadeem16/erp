[← Domain index](README.md)

# Sales pricing resolution — implementation contract

**SPECIFIED / NOT IMPLEMENTED OR CODE-AUTHORISED, 2026-10-12.** Business authority is
[0115](../decisions/0115-resolve-sales-pricing-by-explicit-eligibility-and-commercial-intent.md),
[0116](../decisions/0116-govern-sales-discounts-and-final-net-controls.md),
[0117](../decisions/0117-preserve-pricing-evidence-across-capability-transitions.md).
This selects a typed implementation shape within those semantics; R4–R9 gates are explicit below.
Permission names are proposed duty mappings in the [slice handoff](../phases/phase-4-r3-implementation-slices.md),
not new granted business authority. No separate pricing prototype creates a quotation lifecycle.

## Input/output and source adapters

`ResolveSalesPrice` takes explicit actor/company and a typed authorised context: contracting Party,
commercial source and logical scope, offered quantity/selling unit, selected package, pricing basis/
denominator, exact conversions, selected list/agreement, pricing date/currency, enabled channel/location,
mode and optional entered base/discretionary/final exception intent. Do not trust caller-supplied
cost, policy pass, visibility flag, group membership, stock factor or authority limit. Resolve those
server-side under current scope; source reads and sensitive evaluation are separately authorised.

| Adapter | Required evidence and fallback | Must not do |
|---|---|---|
| Direct Item | Company Item/sellable eligibility, category path, current valid alternate unit or named package and factor; cost from 0110 where needed. | Supplier offer as Sales price, custom AVCO lookup, reserve/post stock. |
| Service Item | Same catalogue applicability, explicit service pricing unit; supported cost provider or explicit unknown. | Treat missing stock as service cost zero or force an Assembly. |
| Assembly work | Pinned version/work measure, Assembly/Group hierarchy; complete applicable cost for derived price via 0110. | Component-category pricing, commercial package conversion inside recipe, double-markup sell guidance. |
| Governed manual | Explicit description, commercial measure and authorised base/exception; explicit cost certainty/provider if relevant. | Fabricate Item/Assembly/Party/account, text match catalogue or bypass tax/control. |

Result contains source/effect/version, base and calculated/final unit/line amounts, rate denominator,
ordered benefits, exclusions/rank rationale, compatibility and COSTED/PARTIAL/UNKNOWN, each control
PASS/WARN/FAIL/UNEVALUABLE, effective authority, dependency fingerprint and readiness/refusals.
Evaluation success is not issue eligibility, approval, acceptance or trusted forecast. User projection
is a separately authorised Resource; internal canonical result is not automatically JSON-exportable.

Admin configuration preview is read-only, labels hypothetical inputs and has no write/adoption side
effect. Its amounts are not authorisation tokens. Operational preview/persistence remains gated by
R5 document identity and R9 effective scope; stale approval/confirmation is not accepted from client.

## Typed persistence sketch — annotated pseudo-DDL, not migration code

All business rows have `id, company_id`; codes normalised with company-composite uniqueness.
Foreign keys pair company and target identity. Polymorphic raw IDs are not accepted tenancy proofs.
Version economic data is immutable after activation/use; lifecycle availability is separate evidence.
Money columns below are `decimal(19,4)` with currency, base-currency equivalent and pinned FX evidence
under0012/0118/0120. Accepted R4 permits one quote currency and explicitly convertible source terms
through the pinned company-base bridge, exact-date default/bounded prior policy and provenance;
no unsupported route or incomplete dual-money row. Implementation remains separately gated.
[Financial calculation](quotation-financial-calculation.md) owns FX/rounding/tax adapters.

```text
sales_pricing_groups
  company/code unique, name, ACTIVE | ARCHIVED
sales_party_pricing_memberships
  company, party → Parties, group, effective interval, revision/actor/reason
  no overlapping active interval for same company/Party/group; multiple different groups allowed
  append replacement/history, no deletion of used evidence

sales_price_lists
  company/code unique, currency, label, status
sales_pricing_rule_versions
  company, family_id, version unique, required_mode, effective interval, explicit predecessor
  audience GENERAL | PARTY | PRICING_GROUP | AGREEMENT; typed nullable references
  source COMPANY | ITEM | ITEM_CATEGORY | ASSEMBLY | ASSEMBLY_GROUP
  exact/subtree category match; supported optional channel/location conditions
  priority, typed effect, pricing UoM/package/denominator, benefit identity, allowed plan | exclusive net
  base rate / markup / margin or adjustment percent / per-unit / line amount
  tier partition reference where applicable; reason/actor/recorded time
sales_pricing_tier_bands
  rule_version, LINE_LOCAL, ALL_UNITS, declared measure and compatible UoM/package
  lower-inclusive, upper-exclusive (unbounded upper allowed), effect/basis
  disjoint bands within partition; named explicit lower eligible band, no invented zero fallback
sales_pricing_plan_versions / ordered_slots
  company, version identity, named family, order unique within plan
  one default discount slot; explicit permitted combination, running-net basis
sales_pricing_exclusions
  plan/family, typed exact Item/category match, optional audience/context conditions
  deny in that family; snapshot explanation, no global contractual prohibition inferred
sales_pricing_control_versions
  required_mode/essential common control, typed floor | minimum margin | authority reduction test
  advisory | mandatory, compatible basis/currency/reference, exceptionable flag (no enabled bypass)
  amount or percentage, reason/actor/version
sales_pricing_agreement_versions / terms
  company/code/version identity, contracting Party, coverage, effective interval
  fixed rate | discount against named reference, adjustment allowance, no ordinary departure
  no commitment/settlement ledger here
sales_pricing_operation_receipts
  company/actor/request_key unique, canonical intent hash, immutable result reference
  used for retryable configuration/confirmation writes; not a new quotation receipt convention
```

Quotation price evidence is owned by R5 document persistence, not an orphan R3 quote table. Reserve
canonical snapshot payload with immutable source/rules/terms/memberships/category path, measurements/
factors, currency/FX/rounding, base/calculated/final, benefit/reference allocations, cost certainty/
inputs, controls/authority/decisions and actor/time/reason. R5 chooses its company-safe quote/revision/
line keys and transaction boundary. Optional classification/channel/location master shapes need not
be built for SIMPLE; supported typed reference slots cannot accept arbitrary unvalidated values.
R9 chooses the employee/role effective-limit store, not hardcoded role-name amounts in pricing rules.

## Constraints and migration obligations

Positive denominator/factors and quantity bounds; non-negative rate/net/floor; percent 0–100 with
TARGET_MARGIN <100; exactly one audience reference where required; source reference CHECKs enforce
one applicable typed target, and company-level/manual defaults carry no fake target. Category/Item
package identity matches the referenced Item. Intervals/bands valid/disjoint with PostgreSQL exclusion
constraints where expressible. 0118 accepts date-only company-local half-open intervals;0120
accepts storage4/unit4, higher-precision conversion and representability refusal. These monetary/date
contracts are settled; R5 quote keys and R9 public authority still gate migration/surface activation.
Initial quote quantity adapters cannot silently truncate finer-than-ledger input.

Composite FKs constrain currency, Party/group/list/plan/Item/category/Assembly/version/package.
Cross-audience/category overlap and full semantic conflict require guarded activation with consistent
configuration generation plus database enforcement of exact duplicate/conflicting same-partition
keys. Raw-SQL writer matrix must cover configuration activation/economic immutability, company mode
and future quote evidence completeness; unsupported writers refuse, never create half-authority.
No fake universal database constraint is claimed for arbitrary rule predicates.
Index company/status/audience/source/currency/effective lookups, memberships by Party/group and
receipt unique keys; preview bounded candidate sets, no unindexed company-wide predicate scan.
R5 reverse-dependency keys include candidate-set generation/absence as well as selected versions,
so a new higher-priority rule cannot evade staleness. Item/category/Party writers coordinate with
this dependency contract; re-read actual ancestry/membership before adoption/issue independently
of asynchronous notifications. Those future integrations do not authorise editing CRM/C2 now.

Additive migrations only after slice authorisation; schema and permission registrations before writers,
then modes/configuration, then readers/UI. Defaults SIMPLE, no speculative memberships or prices seeded
onto real existing Parties/Items, no price inferred from Assembly guidance or supplier offers. Add
FinancialActivity registration when monetary rows arrive. Backfills truthful/per-company/idempotent;
no database reset. Real down/apply/rollback/reapply on isolated test DB; populated evidence rollback
refuses when removal would lose facts. Never edit historical migrations or C2 cost DTOs to fit pricing.

## Actions, transactions and exact-intent retries

| Named operation | Preconditions / fact changed | Concurrency and result |
|---|---|---|
| RecordPricingGroup / ArchivePricingGroup | Configuration duty, company identity; archive leaves used memberships/history. | Group lock, expected revision, reason for governed archive; active dependants reconcile/refuse. |
| SetPartyPricingMembership | Configuration/membership duty AND legitimate Party scope; validated effective interval. | Company config generation then Party/group in stable ID order; append revision, bump dependency generation atomically. |
| RecordPricingVersion / ActivatePricingVersion / RetirePricingVersion | Config duty; mode supported, required basis/terms/plan/controls coherent; no known material conflict. | Lock company pricing generation then families/versions ascending IDs, re-read and validate; increment generation, append event; no status PATCH. |
| ResolveSalesPrice / PreviewSalesPrice | Quote/config-preview duty and independently authorised context; no persisted commercial fact. | Consistent read and generation comparison; if context/config changes during evaluation return stale, no mixed-version result. Cost evidence records actual consistent observed scope/time under 0110. |
| ReplaceDiscretionaryDiscount / ConfirmPriceReview | Future R5 editable draft, entry duty/limits, both proposed results displayed; selected agreement allows. | Lock R5 document/revision then pricing generations/dependencies in one published global order; verify expected revision and exact comparison fingerprint; append intent/confirmation/evidence atomically. |
| RecordFinalPriceException | Future editable draft + separate override duty, reason, valid terms/limits and final controls. | Same document transaction; retain calculated/final; invalidates affected approval under R5, no duplicate benefits. |
| ChangePricingMode | Config duty/reason; impact preview and reconciled dependencies, no hidden effects or obligations dropped. | R5-approved document/config lock order, fresh impact fingerprint; atomic change or refusal. Actual downgrade unavailable until R5 dependencies supported. |

Configuration writes carry request key, expected revision/generation and exact canonical intent.
For same actor/company/key: same authorised intent returns original receipt before stale check;
different intent refuses `IDEMPOTENCY_KEY_CONFLICT`. Recheck current access before replay; receipt
projection must not reveal now-protected facts. No-op records truthful receipt, no fabricated change.
Confirmation carries expected automatic/proposed result fingerprints, not just `confirmed=true`;
changed price/eligibility invalidates it. Cancellation leaves automatic result unchanged. A client
cannot authorise an increase by submitting a hidden checkbox. Explain both nets, delta and replaced
benefit in the server-derived comparison and UI; store confirmed intent/actor/time through R5.

R5 must publish the common document/config lock order before enabling its mutations; table-specific
config order above is for independent config Actions, not an invented override of existing locks.
Persistent evidence creation/adoption and issue snapshots share the R5 document transaction; never
save a draft and evidence separately. Hash/generation is a comparison aid, not sole issue authority.
Issue re-resolves current authorised relevant facts before atomically freezing under R5.

## API and refusals — target contracts

Proposed `/api/v1/sales-pricing-groups`, `/sales-pricing-memberships`, `/sales-price-lists`,
`/sales-pricing-rules`, `/sales-pricing-plans`, `/sales-pricing-controls`, `/sales-pricing-agreements`;
version parents support named `/activate` and `/retire` Actions, no mutable economic used version.
GET/POST/PATCH-draft resources use Request/Action guards, validated company references, pagination,
expected revision and standard Resources. Under-list rule/tier children use at most one nesting level;
version/context are explicit fields. No general endpoint for contractual departure/risk bypass.

Admin-only POST `/sales-pricing-preview` computes a read-only hypothetical result; never persists
quote facts and does not get a retryable-write receipt. Quote pricing input/preview/history integrates
existing future quotation endpoints selected in R5, not parallel pricing-owned quotation routes.
OpenAPI + generated clients are coding-owner deliverables, not hand-edited in this handoff.

Use canonical error envelope. Target codes: `NO_ELIGIBLE_PRICE`, `PRICING_RULE_CONFLICT`,
`SELECTED_AGREEMENT_INVALID`, `ADJUSTMENT_NOT_PERMITTED`, `PRICE_BASIS_INCOMPATIBLE`,
`DUPLICATE_PRICING_BENEFIT`, `DISCOUNT_REPLACEMENT_CONFIRMATION_REQUIRED`,
`PRICE_AUTHORITY_EXCEEDED`, `MANDATORY_CONTROL_FAILED`, `MANDATORY_CONTROL_UNEVALUABLE`,
`PRICING_MODE_DEPENDENCY`, and standard `STALE_EVIDENCE`/key conflict. Business refusals use 422;
stale/idempotency conflict 409; unauthorised duty 403, out-of-scope identity 404. Sensitive refusal
omits operands/hidden threshold/rule identity; safe status/reason remains actionable. R5 issue endpoint
must retain its own canonical lifecycle reasons, not be invented by R3.

## UI, evidence and approval integration

SIMPLE field destinations: main line base/rate/unit, optional single discount selector/value, visible
calculated/final net and warnings; reasoned final exception in governed review. Replacement comparison
shows old/new net and price increase/decrease and requires explicit confirmation. Reopening preserves
recorded intent and relevant disclosures. All arithmetic server/decimal helpers, no JS money floats.

MANAGED adds authorised group/Party/category/rate/tier/plan/term setup sheets and on-line selected
agreement/context/basis/benefit explanation; ADVANCED adds typed step preview/configuration and detailed
history only when enabled. Every enabled input has form/detail/list/card/summary/filter/action/history
coverage; no unused validated field hidden from its own screen. Required states include loading/empty/
validation/permission/stale/conflict/confirmation/error/success, keyboard and phone/light/dark.
Print/export customer projection belongs to R8; no automatic raw internal trace in Quick Print.

No approval/request schema or risk permission selected here: R5 must bind stored maker/checker,
net/reference/terms/cost/control/revision fingerprint and expiry/invalidation; R9 supplies effective
limits/read/scope. Mandatory failure remains refusal until their approved exception path exists.
These known gates do not block drafting this contract or unrelated CRM/C2 implementation.

## Related

- [Accepted R4 financial adapter](quotation-financial-calculation.md)

- [Pricing domain](sales-pricing.md) · [Domain index](README.md)
- [Slice sequence and verification](../phases/phase-4-r3-implementation-slices.md)
- [API conventions](../architecture/api-conventions.md) · [Domain integrity](../architecture/domain-integrity.md)
- [Authorisation](../architecture/authorization.md) · [Database](../architecture/database.md)
