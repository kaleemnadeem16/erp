[← Domain index](README.md)

# Quotation discount authority

**Owner-authorised specification, 2026-11-06, issue #86. Backend stages 1–4 are implemented; duties remain STAGED. Owner lock-order review and owner-run generated-client verification remain integration gates.**
Decisions: [0163](../decisions/0163-govern-quotation-discount-authority-with-independent-reference-evidence.md)
and proposed [lock order 0164](../decisions/0164-order-discount-authority-locks-before-quotation-and-approval-records.md).
This is one backend milestone delivered in four green stages. Configuration screens are a separate
UI handoff; neither this specification nor demo values activates production authority.

## What a seller and approver should expect

A seller's entered price is compared with a separately approved reference, before tax. A discount
inside every applicable assigned limit can receive routine clearance. That clearance is recorded;
it is not an approval decision. Every final-net override or undeclared reduction still needs an
independent approver. Missing, expired, mismatched or ambiguous configuration never means unlimited
authority. Approval does not override minimum-price, margin, contractual, tax or issuing controls.

Both percentage and money limits must pass when configured. Companies can configure percentage-only,
amount-only or combined rules and line, quotation or both enforcement. The comprehensive demo uses
both on lines and the quotation. Monetary ceilings are in the company's base currency; PKR belongs
to Company 1's demo configuration, not application code.

## Governed references and continuity

References are approved source versions, not claims made by a salesperson. Precedence is customer
contract, then applicable project/company/product pricing with explicit priority. Equal best-rank
matches refuse. Validate the customer, typed product/service identity, priced measure/UOM, quotation
currency and half-open effective interval. Manual services require an independently approved
family/project-specific baseline. A reference proposal carries its source kind/key/version, factual
description, dates and reason; independent activation records its authoriser and reason. Sources
are not trusted until that activation exists. Ordinary entered prices and last quotations cannot
be activated implicitly. When an approved customer agreement supplies the authority denominator,
retain its difference from the separately governed ordinary list as distinct evidence; an unknown
ordinary list is null, never an entered-price substitute.

Bind the resolved version to the quotation family and economic identity. A revision/replacement
inherits this binding; removing a line, making another logical scope or splitting a quantity must
not select a smaller denominator. Changes to an established binding require independently
authorised replacement evidence, never an update to the old row. A genuine new source/measure
resolves a new governed reference. A missing or incompatible reference cannot receive routine
clearance; it requires the explicitly configured missing-reference exception route and independent
review. An unknown amount cannot satisfy a monetary ceiling. Exceptional review must identify the
unknown basis; it cannot certify an invented percentage or bypass mandatory financial controls.

The existing family demonstrably groups revisions, scopes, choice groups, issued records and whole
replacements. Customer-decision components can name a family's original scopes. Orders and jobs
are **not linked yet**: acceptance/handover and Phase 5 must carry the accepted quotation lineage.
JobHandoverOrigin has a business-identity field but no delivered authoritative quotation/order
mapping. This milestone cannot detect one real job deliberately split into unrelated families.
Do not claim customer-period or cross-order cumulative protection until those integrations exist.

## Exact calculations and FX

For each line retain reference unit price, priced quantity/measure and settled reference value;
entered unit price; specific discount input; line net; document-discount allocation; final net;
full reference-minus-final reduction and its percentage. Replacing an automatic 10% benefit with
a discretionary 20% reduction from reference 100 means final 80 and aggregate 20%, not 10%.

Use existing QuotationAdjustmentEngine and AllocateFinancialResiduals: allocate a document discount
once over eligible residual nets; settle at the currency quantum, reconcile every remainder, and
retain the allocation fingerprint. Do not subtract the same allocation from a final net twice.
No tax amount is included in the authority exposure. Retain specific-input and aggregate-final
measures independently. At the threshold passes; exceeding either percentage or amount requires
higher authority. Use rationals for comparisons so rounded display percentages cannot clear a
breach. Group equivalent reference/source lines before applying amount ceilings to prevent splits.

For maximum exposure include base lines and every potentially selectable optional/alternative
line, even mutually exclusive choices (a deliberately conservative upper bound). Never net a
positive concession against an unrelated price increase. Replacing a quotation preserves economic
lineage; do not add the full old and new mutually replacing offers as if both were independent sales.

Reuse QuotationMoney's pinned FxBundleEvidence. Validate company, requested date, source/target/base
currency, route, rate legs/source/date and fingerprint. Convert with the exact captured ratio;
never reconstruct a rate from rounded base_unit_price, fetch a fresh rate during approval, assume
missing FX is 1, or turn an unknown amount into zero. An explicit refresh is a commercial change
and invalidates approval. Identity conversion needs explicit same-currency evidence. Preserve
transaction and base amounts, conversion residual and settlement policy in immutable evaluations.

## Authority and policies

Company policy versions carry typed matching criteria, priority, effective dates, mode and expiry.
Supported criteria must be explicitly validated: operation DISCOUNT/OVERRIDE, customer, economic
source, family and branch/unit; unsupported organisation criteria refuse rather than broaden.
Select highest priority, refuse equal-priority ambiguity. A version cannot be edited once relied
upon; activation and revocation are reasoned append-only events. Reject overlapping activations of
the same identity and forbid backdated rewriting of issued evidence.

An authority band defines independent line/input/aggregate percentage and base-amount ceilings,
document percentage and base-amount ceilings, and an explicit ordinal for routing. A named employee
assignment names the band/version, effective dates and optional required company role and exact
organisation unit. Role membership alone is insufficient. Check current active membership, role
and unit/branch applicability and QuotationAccess. Resolve assignments deterministically; do not
add several roles/assignments or silently choose the loosest. Missing, revoked or expired assignment
means zero routine authority, with an approval requirement for concessions.

The owner-approved demo values are **DEMO ONLY**:

| Illustrative label | Maximum percentage | Maximum PKR concession |
|---|---|---|
| Sales Representative | 0 | 0.0000 |
| Sales Lead | 3 | 25000.0000 |
| Sales Manager | 7 | 100000.0000 |
| Director | 12 | 500000.0000 |
| Owner-level independent reviewer | Explicit exception | Explicit exception |

Labels confer no privilege. Real bands, people, branches and trusted sources await the owner's
separate review. Demo employees include expired/unassigned examples; no STAGED permission is
granted or activated. Demo governed price list and customer agreement are labelled Company 1 only.

## Shared approvals, evidence and expiry

Use QUOTATION_REVISION and the existing engine's versioned policies/stages/counts/decisions. Lowest
qualified configured band routes ordinary exceptions; separate mandatory controls can require
parallel or sequential steps. No eligible independent approver, no applicable policy, NONE mode
on a draft needing approval, conflicting policy or insufficient known authority refuses. The
creator, concession entrant and requester cannot decide their own exception, including at owner
level. Generic engine routes and inbox use the same subject eligibility. No delegated eligibility
or automatic escalation is added. A higher band can decide directly only where policy permits it.

Routing fingerprints include content/generation, governed-reference evidence, exact calculations,
pinned FX, company authority-policy version, assignment/generation evidence and selected approval
policy. Freeze them on the request/evaluation and re-read under the common fences at decision and
issue. Same-total edits and policy/reference/assignment changes are material. A generic decision
must run the same transaction hook before locking a request; no HTTP-only integration.

**Expiry replaces the earlier no-expiry default.** Initial configuration is 14 company-local
calendar days from submission, never beyond the end of the quotation's valid-through day. Store
the earlier UTC deadline. It applies to pending and approved requests; at/after the deadline the
request cannot authorise issue. No cron run is needed to enforce expiry and timeout never approves.
Re-request after expiry uses new evidence; exact retries replay their original authorised receipt.

## Persistence, security and API handoff

Logical schema (final physical names belong to each stage's migration):

```
sales_authority_heads: company_id, generation
sales_price_reference_versions: company_id/id, code/version, source kind/key/version,
  customer/family/item/measure, currency/unit_price decimal(19,4), effective interval,
  priority, proposer, reason, fingerprint
sales_price_reference_activations: company_id, reference_version, independent actor/reason/time
sales_quotation_reference_bindings: company_id, family/economic identity, reference_version,
  predecessor binding nullable, authorised reason/actor/time
sales_discount_policy_versions: company_id/id, code/version, typed criteria, priority,
  effective interval, expiry_calendar_days, reference/control configuration
sales_discount_bands: company_id, policy_version, ordinal, exact independent ceilings
sales_discount_assignments: company_id, employee, band_version, role/unit constraints, interval
sales_discount_events: company_id, kind/version/assignment, actor/reason/time, request identity
sales_discount_evaluations: company_id, revision/generation, exact policy/reference/assignment/
  FX/calculation snapshot, transaction/base amounts, result/reasons, fingerprint, recorded_at
sales_discount_operation_receipts: company_id, actor/key, intent digest, immutable result
```

Use company-composite FKs/uniques, numeric CHECKs, immutable database guards and generation fences.
Register monetary persistence with FinancialActivity. Down migrations refuse populated evidence.
The [lock-order ADR](../decisions/0164-order-discount-authority-locks-before-quotation-and-approval-records.md)
governs every writer, including direct SQL/configuration and the generic approval route.

Configuration API contracts must support version list/detail, proposal, independent reference
approval, policy activation, assignment/revocation, mode transition, current evaluation and paged
audit. Every write needs exact-intent idempotency, current expected generation, reason, HTTP and
Action guards. Reads need an explicit independent configuration/evidence duty; quotation reads
also require view and effective family scope. Use existing financial configuration duty where
appropriate; any new duty is STAGED and excluded from grants. Register every route/duty/writer in
the five coordinator-named coverage traps and rollout manifest. Controllers return typed Resources;
generate OpenAPI/client in the API commit. Never return models or arbitrary protected snapshots.

UI handoff comprises reference/authority settings, employee assignments, quotation explanation,
qualified inbox and restricted audit. Provide safe results and plain refusal messages for missing
reference/FX, no authority, ambiguity, independent review, stale evidence and expiry. Reference,
final price, percentage, base exposure and band/reasons are available only through the independent
evidence capability plus record access. Ordinary preview/inbox remains cost/ceiling/reason blind.
No raw cost fields exist in these contracts. No existing quotation/settings screens are edited.

## Rollout, legacy and delivery stages

OFF is the additive default. SHADOW appends audit findings but preserves every existing approval
and financial blocker. ENFORCE requires an explicit company transition and owner-approved holders;
this task enables no production company. Disable without deleting versions, decisions or evidence;
never let disabling satisfy a previously recorded approval need. Preserve issued history exactly.
Evaluate open legacy drafts and pending approvals afresh; missing historical references are explicit
exceptions, not inferred prices. Acceptance/partial-acceptance integration is required only on
delivered paths; no authoritative acceptance writer currently exists in this checkout. Future
acceptance/handover must preserve family linkage and recheck selected exposure before committing.

| Stage | Deliverable and green gate |
|---|---|
| 1 | Governed references, independent activation/binding, pinned-FX validation; precision/source/tenant/immutability/revision/split tests, migration cycle |
| 2 | Company policies, bands, employee assignments, activation/events/evaluation evidence; absent/expired/scope/overlap/idempotency tests, migration cycle |
| 3 | Opt-in strict shared routing/eligibility/expiry; ambiguity, stage/count, maker/checker, revoked access and unchanged Purchasing/Estimating regressions |
| 4 | All delivered draft/issue/replacement paths, APIs/client, shadow/audit, one final create-only demo seeder, writer traps, final-SHA cross-module tests and UI handoff |

Each stage is its own tested commit with accurate docs; later stages do not begin before the
previous gate passes. No partial foundation is exposed as working authority. Final verification
includes Sales/Approvals and affected consumer regressions, ratchets, Pint, whole-project PHPStan,
isolated migration cycle, client drift and frontend typechecks/build for generated changes. Exact
final-SHA evidence must distinguish shared working-tree checks from committed-source verification.

## Recorded for later — not built in this milestone

- Verified-cost/margin hard floor: only after a trustworthy complete cost source exists; never
  treat incomplete estimates as verified cost or allow ordinary approval to bypass it.
- Customer-period/rolling cumulative budgets across separate deals and authoritative order/job
  lineage. Prevent reset inside the implemented family now; disclose the unrelated-family gap.
- Rich substitution, automatic out-of-office delegation/escalation and notifications. Existing
  quotation no-delegation remains; do not introduce a new substitute mechanism implicitly.
- Automatic reuse of unchanged approval steps. Full reapproval is the initial safe behaviour.

## Historical stage 1 delivery evidence

The internal reference foundation uses `sales_price_reference_versions`,
`sales_price_reference_activations`, `sales_quotation_reference_bindings`,
`sales_authority_heads` and `sales_authority_operation_receipts` (migration
`2026_12_03_000000_create_sales_governed_price_references`). ProposeSalesPriceReference uses the
existing STAGED financial-configuration duty; ApproveSalesPriceReference adds the ungranted STAGED
`sales.pricing.reference.approve`. BindQuotationAuthorityReferences uses view/prepare and the
existing governed quotation operation. No route or ordinary quotation behaviour is added yet.
Bindings are immutable at this stage; an incompatible established baseline refuses rather than
being replaced implicitly. The later integration must provide independently authorised replacement
evidence before exposing a baseline-change operation.

Verified: 14 focused reference tests; Sales module 628 passed with one existing docs-path skip;
reference/schema/writer/route/permission/scope ratchets passed; whole-project PHPStan passed;
fresh/rollback/reapply passed. The local runner needs a private bootstrap setting the preview flag
false to reproduce its tested default, and PHPStan uses a private cache because the shared cache
is root-owned. Neither workaround changes `.env`, product behaviour or a test assertion.

## Historical stage 2 internal configuration and evaluation

Migration `2026_12_03_010000_create_sales_discount_authority_configuration` adds immutable policy
versions, bands, activation events, named assignments/revocations, company mode events and
protected evaluation snapshots. `sales_quotation_reference_anchors` also ties each original logical
scope to its family/economic binding: changing its source or measure cannot choose a cheaper
baseline. Revisions inherit the scope anchor; split lines share the economic amount ceiling.

Typed matching is operation, customer, exact unit and `contains_item_id` (the quotation contains
that Item; the selected policy governs the whole quotation). A new version supersedes its code's
old eligible version; expiry or changed criteria cannot resurrect an older policy. Base currency
is pinned on the policy. Assignment overlap is refused by the Action and database, role membership
is live, and unit restrictions need both the matching family unit and active membership. Routine
assessment checks each stored concession entrant's current discount duty/scope and assignment;
an issuer's higher authority cannot substitute for an entrant's authority.

MeasureQuotationConcessions reuses the existing adjustment/allocation kernel, includes optional
exposure, groups split sources, and retains original line/document inputs, actors, allocation,
reference and FX evidence, including dual transaction/base amounts and explicit unknowns.
Participant authorisation epochs, family access state, company time zone and currency quantum
are fingerprinted so same-total changes and restored grants cannot revive older evidence. Nominal
source prices become dual-currency monetary evidence when evaluated using quotation-pinned FX.
All ceiling comparisons use exact rationals. Shadow evaluation writes
immutable snapshots through EvaluateQuotationDiscountAuthority with the existing view/prepare
fence and receipt. OFF is the default. ENFORCE is only a reserved configuration value at this
stage: ordinary quotation clearance is unchanged until the complete integration is delivered.
There is no new HTTP route or production holder, activation, grant or UI.

Verification: combined Sales/Company regression 696 passed with one existing docs-path skip;
45 focused reference/policy/FX/evidence tests passed; whole-project PHPStan and isolated migration cycle passed. The rollout-manifest rows were checked directly because the container does not mount
the docs folder. The combined ratchet run passed
93 tests but failed both CompanyScopedForeignKeyTest methods on Purchasing's
`purchase_orders_fx_override_by_foreign`, introduced in commit `3eb5d9b8` (#90). It is reported to
that owner and the coordinator on #86; no Purchasing file or test assertion was changed to pass it.
Historical external gate: Purchasing fixed the FK in `253332eb`; both tenant-FK ratchet tests passed on 2026-11-09.

## Routing and lifecycle integration checkpoint (2026-11-09)

The complete backend includes strict Sales-only selection, evaluated concession routing, current
named-employee eligibility, independent evidence visibility, expiry and all delivered draft/approval/
readiness/issue paths. Generic decisions take the subject transaction hook before request locking;
configuration writers use statement barriers. The concrete contention trade-off remains proposed
for owner review in ADR 0164. An approval lasts at most fourteen company-local calendar days,
never beyond quotation validity, even if a stored policy configures a longer interval.

ENFORCE requires captured family/scope reference anchors before clearance. Draft operations append
SHADOW/ENFORCE evaluations; issue pins its immutable evaluation through the company-composite
`quotation_issues.authority_evaluation_id` FK. OFF retains the original receipt shape and protections.
An expired pending request can be submitted afresh; completed approval still needs live eligible
reviewers before issue. Missing evidence uses 422, with the single
`sales.discount_authority_hide_missing_evidence` switch for the owner's 404 alternative.

Configuration APIs, protected current/paged evidence reads, and the create-only Company 1 demo
are delivered. The [API and rollout contract](quotation-discount-authority-api.md) lists routes,
projections, permission boundaries and demo prerequisites; the [journey](../journeys/quotation-discount-authority.md)
explains the operator flow. No duty, holder or company mode is activated by delivery. Generated
client files remain with the owner for codegen/check; screens are a separate workstream.

### Screen handoff
Settings will need versioned reference proposals and independent approval, policy/band versions,
named employee assignments and revocation, and explicit OFF/SHADOW/ENFORCE confirmation. Show
DEMO ONLY labels on illustrative limits and people. A quotation needs a safe clearance/review
status; only authorised evidence readers may see its reference, percentage, base exposure and
qualified band. The approver needs that evidence and a visible expiry, with plain messages for
missing evidence, changed terms, expired approval or unavailable authority. Preserve draft context
when requesting again. A paged restricted audit will explain configuration and evaluation history.
The backend contracts are delivered; these screens remain a separate workstream.

Issue-time mandatory input-reduction controls judge line discounts, final-net override reductions
and document discounts. The document input is judged once against counted net before its
discount; approval does not waive a mandatory control.

Commercial editors are retained in append-only `quotation_concession_participants`
(company, revision and actor composite relationships), including existing operation-receipt
participants at migration. Same-value concession entry preserves its original author and time.
Clearing or replacing a concession does not restore its participants’ approval eligibility,
in OFF, SHADOW or ENFORCE mode.

The Sales approval standing read uses the shared approval reason policy. Human-written
reasons are visible to their author, an authorised evidence reader or a holder of
`approvals.reason.view`; other viewers receive null reasons with the outcome unchanged.

## Related

- [API and rollout](quotation-discount-authority-api.md) · [Journey](../journeys/quotation-discount-authority.md)
- [Sales authority](sales-authority.md) · [Pricing](sales-pricing.md) · [Approvals](approvals.md)
- [Approvals integration log](../phases/phase-4-approvals-integration-log.md)
- [AU rollout manifest](../phases/phase-4-au0-au1-rollout-manifest.md)
- [Domain index](README.md)
