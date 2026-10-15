[← Domain index](README.md)

# Quotation lifecycle — persistence and Action contract

**2026-10-13 · ACCEPTED business / SPECIFIED contracts / no application delivery or verification.**
Authority: [0122](../decisions/0122-govern-quotation-candidates-adoption-and-revalidation.md),
[0123](../decisions/0123-issue-numbered-quotation-revisions-atomically.md),
[0124](../decisions/0124-preserve-quotation-availability-and-scope-replacements.md), R1–R4 and
0070/0079/0085. [R5 slices](../phases/phase-4-r5-implementation-slices.md) define authorised-delivery
prerequisites. Physical names below specify a shape, not migrations or permission to build it.
R6/R7 acceptance/commitment functions stay gated; R9 public duty/scope/grant contract also required.

## Aggregate and typed persistence — annotated pseudo-DDL

All independently queried business rows carry company_id. Foreign keys combine company/target IDs;
JSON evidence cannot replace typed tenancy-validated references. Currency IDs use existing global
registry. Economic snapshots use decimal(19,4), decimal strings/rational evidence and pinned base
amounts under 0118–0120. Internal IDs never reserve official numbers.

```text
quotation_families
  id, company_id; internal reference; revision_generation
  current_opportunity_id nullable; association_generation; current CRM projection
quotation_family_association_events
  family, old/new Opportunity or explicit absence; actor/time/reason as0099 requires
quotation_logical_scopes / quotation_choice_groups
  company/family identity; stable original-intent/provenance reference
  source identity is NOT scope identity; repeated Item may be different genuine scope
  overlap_anchor_id shared across alternative members / predecessor scope; not display/source ID
quotation_revisions
  company/family; revision_ordinal; UNIQUE(company,family,ordinal)
  state DRAFT | ISSUED | CANCELLED_DRAFT; input_generation; current_candidate_id nullable
  recipient_party_id nullable ONLY in incomplete draft; contact/site scoped references
  explicit pricing_date/tax_estimate_date; one currency; valid_through_local_date
  predecessor_revision_id nullable, named replacement_intent, source_copy_reference
  official_number/numbering_allocation_id/issued_at nullable before issue
quotation_draft_lines / sections / choice_memberships
  revision + logical_scope identity; flat sections/order; BASE | OPTIONAL | CHOOSE_ONE
  ITEM | SERVICE_ITEM | ASSEMBLY | MANUAL typed source, exactly compatible references
  offered/priced/package/stock/technical measures and conversion versions as0109
  description, selected baseline, unpriced explanatory-parent reference where applicable
  entered pricing/discount/override intent and legitimate reason/confirmation references
quotation_candidates / candidate_lines / candidate_financial_components
  immutable candidate identity; revision + input_generation; schema/calculation version
  server input/source/Party/pricing/cost/plan/FX/tax/rounding/choice evidence snapshot
  dependency_vector; canonical_review_fingerprint; routing_fingerprint
  cost_status COSTED | PARTIAL | UNKNOWN and nullable complete amount; no subtotal as complete
  raw/settled/computed_base/allocated_contribution/residual facts as0120
quotation_adoptions
  candidate + typed applicable source evidence; standard/planned/cost certainty
  adopted_by/at; reason/variance/stock-choice approval references only where required
  no inventory reservation/posting; candidate isn't adoption until this fact exists
quotation_revalidations / candidate_invalidation_events
  original candidate/approval references; old/current dependency vector; outcome/reason
  actor/time; unchanged original canonical/routing fingerprints or refusal/change result
quotation_issues / issue_lines / issue_financial_components / issue_allocations
  UNIQUE(company,revision); candidate + applicable adoption + review decisions
  immutable legal Party/contact/site/current-at-issue CRM/policy exception snapshot
  scope/choices/baseline, actual measures/conversions, applicable source/cost/material facts
  commercial/FX/output-tax/withholding/rounding/control/policy/calculation evidence
  valid_through, expiry_timezone, expiry_exclusive_utc; actual issued_at_utc
  official_number and central allocation reference UNIQUE(company,quotation_type,number)
quotation_unknown_payment_acknowledgements
  candidate + exact reviewed result/fingerprint + UNKNOWN reason, company policy version
  issuer/acknowledged_at; issue reference when used; never approval/customer consent
quotation_offer_events / offer_scope_projections
  company/family/issue/logical_scope; ISSUE_AVAILABLE | WITHDRAW | REPLACE
  reason, actor/time, predecessor/successor and availability_generation
  one actionable overlapping revision per family/logical intent; expiry evaluated as-of
  projection may change atomically; original issued/economic/event rows cannot
quotation_operation_receipts
  UNIQUE(company,actor,operation,subject,key); normalised_request_hash
  original outcome references/time; committed atomically with business evidence
quotation_company_policy_versions
  governed valid-through default offset in company-local calendar days
  UNKNOWN payment policy BLOCK | ALLOW_WITH_ACKNOWLEDGEMENT (default BLOCK)
  no universal duration/backfill; explicit valid-through required if default not yet configured
quotation_write_fences
  company-scoped governance/mutation generation; shared coordinator/registered writer contract
```

Official number belongs to issue/revision, not mutable family association. DRAFT inputs can be
incomplete; candidate/issue gates enforce completeness. Quote currency defaults to company base and is explicit even in draft. Persisted entered monetary
inputs require0012/R4 currency/pinned FX/computed base evidence without marking them reviewed or
adopted; missing conversion refuses that money write, while legitimate non-money draft edits remain
possible. Calculated unknown totals stay nullable typed states, never incomplete dual-money rows.
State and current-pointer projections are
separate from immutable snapshots: expiry/withdrawal does not rewrite ISSUED into editable state.
Copies retain provenance but get proper family/logical intent rather than deduplicating by Item ID.
R6 extends accepted/remaining allocation/claims through these identities; no fake acceptance table,
zero accepted-quantity backfill or implicit Order/Job is installed by R5.

## Source-specific evidence and candidate adoption

Direct Items capture sellable/package/UoM and 0110 Inventory-owned applicable valuation/certainty.
Service Items capture service nature/measures and real applicable service cost. Assembly evidence
includes technical versions, standard/planned quantities and source-specific variance/stock-choice
controls under 0054/0058/0072/0076. Manual scope declares nature/basis and authorised price/cost facts;
no fabricated master/account, missing cost zero or stock cost masquerading as service cost.

Read-only preview returns a consistent proposed result and server-context fingerprint, never approval
or adoption. Save persists inputs, not preview PASS or client amounts. Freeze candidate when deliberately
submitted or reviewed for a controlled checkpoint; snapshots are immutable. Approvals bind that exact
candidate/revision and original source evidence; adoption/issue points to it, not a successor with equal
amount. The candidate's material-control adapter declares PRE_ADOPTION approval or ISSUE checkpoint
from existing source governance; do not defer a mandatory adoption control until after adoption.
Routine no-special-control Issue may capture candidate/adoption atomically after exact result confirmation.

Candidate content includes all offered choice identities/economic facts plus explicit baseline, not
an unlimited approval of future selection combinations. Only supported source/financial paths may be
issued; R6 selection/partial acceptance is not implemented by frozen choices. Explanatory children do
not charge twice. Cost-dependent arithmetic/mandatory controls fail without complete applicable cost;
accepted maintained/manual advisory paths may preserve PARTIAL/UNKNOWN without false PASS.

## Named Actions and matching Requests

| Action / Request suffix `Request` | Preconditions and atomic result |
|---|---|
| CreateQuotationDraft | Prepare + company/Party/source/Opportunity eligibility;0098 link policy; family/revision identity and receipt. No official number. |
| UpdateQuotationDraft | Prepare + expected input generation; typed editable fields only; bump generation, append relevant invalidation, preserve old evidence. |
| PreviewQuotation | Independent scoped inputs, supported mode; consistent read + post-evaluation dependency check; no business write/adoption. |
| FreezeQuotationCandidate | Exact draft generation/result token, complete required prerequisites; immutable snapshot and receipt; no approval invention. |
| SubmitQuotationReview | Prepare/appropriate submit duty, frozen candidate; registered shared subject/provider; routing and safe review capture; pending proposal remains not adopted. |
| RevalidateQuotationCandidate | Current scope/duties, candidate/input generation and dependencies; preserve usable approval only when both hashes and all eligibility match; append receipt. |
| AdoptQuotationEvidence | Applicable exact source duty/checkpoints/reasons/approval; source snapshot, standard/planned separation, no inventory mutation. Routine issue can invoke internally. |
| IssueQuotation | Issue duty + independent record/source/Party access; required approvals and checks; exact reviewed result and policy-bound acknowledgement; atomic0123 issue/number/evidence/receipt. |
| CancelQuotationDraft | Prepare duty, DRAFT expected generation, governed reason; terminal cancellation + event/receipt, no predecessor withdrawal. |
| CreateQuotationRevision | Prepare + predecessor read/eligibility; allocate internal ordinal under family lock; copy legitimate inputs/provenance, not approval or live recalculation; old availability intact. |
| WithdrawQuotationOffer | Dedicated governed withdrawal duty, expected availability generation/reason; remove eligible wholly unaccepted availability. Initial partial operation refuses. |
| IssueQuotationReplacement | Issue + exact predecessor/scope/generation and any independently required withdrawal duty; atomically new issue/replacement events,0124 checks, no accepted-business mutation. |
| SaveQuotationPolicyVersion | Governed config duty/reason/expected version; active successor, BLOCK/default-date policy validated; existing issued acknowledgement/history unchanged. |

Issue authority is not exceptional discount/risk/contract authority. Reuse R3 confirmation/override
Actions inside this aggregate; no price reimplementation or new contract-departure endpoint. Draft
relationship changes follow0098/0099 with separate immutable issue association; post-issue association
business authority remains0099 and final R9 surface/permissions, not a generic issue-record editor.
A new draft does not make an already-issued family's current CRM association ordinarily editable:
0099 post-issue duty/reason/old-and-new scope checks still apply; unsupported R9 surface refuses.
Reject raw client evidence/FX/cost/control PASS/approved flags or arbitrary status PATCH.

## Fingerprints, freshness and approved no-change

Canonical reviewed facts: logical sources/scope/measures/conversions/Party/context; entered and
resolved price/benefit order/reference/agreement/economic versions; complete cost/plan certainty and
versions; all offered choices/baseline; FX pair/value/date/source/route/policy; tax/exemption/inclusion/
component/raw-settled/rounding/allocation/control facts; validity/terms; relevant routing/issue policy.
Internal routing/review hashes are server-only sensitive evidence; clients submit an opaque integrity-
bound review token tied to actor/company/revision/generation and server-held exact result context.
Do not expose a guessable cost/threshold hash or trust client-supplied internal fingerprints. A token
is comparison intent, not approval/permission. Routing fingerprint covers shared-engine facts/base
value, not just quote total. Hash version and
canonical decimal normalisation are explicit; display order irrelevant only when neither scope,
customer description, allocation tie nor policy facts change. Use stable logical IDs for allocations.

Dependency generation/date passage triggers revalidation. No-change preserves approval only with
same candidate/source IDs/versions and both original hashes, usable current engine decision and
current source/record/date/policy eligibility. Append observation/revalidation without overwriting
approval or extending validity. Relevant changed provenance/version/Party at equal net invalidates.
Once invalidated, returning to equal facts cannot revive old approval. Source observation timestamps
alone are not economic changes only where source-owner freshness validation proves unchanged facts.
Approval step expiry is not quote expiry or a generic post-approval valid-until promise.

## Concurrency, exact replay and database obligations

One shared Sales coordinator publishes this order for every related writer: company Sales mutation
fence → applicable exclusive-group/claim identities sorted → families sorted → revisions/lines/logical
scopes sorted → config/source dependency rows in declared module+ID order → approval request rows →
central numbering allocation. Configuration-only writers take the company fence before their config
rows; they never acquire a quotation family after an inverse lock. Manual FX/tax/price/company/approval
changes participating in freshness must join a compatible fence/generation protocol or source-owner
validation barrier. Relevant inserts (e.g. closer prior FX/new effective rule) cannot escape validation
merely because an old selected row was locked. Register all relevant writers/ports before activation.

Do not claim current C2 cost readers already supply new lock/validation APIs: Inventory owns coherent
observation and its token validation. Missing required adapter/barrier refuses enabled delivery; no
Inventory reservation or unscoped global monetary/FX change is permitted. Sources not needing cost
must not be forced onto irrelevant Inventory locks. Wider source integrations require authorised
coding slices through owner; planning changes no existing source Actions.

Re-read authority/state/dependencies after lock; capture one issue UTC instant/local expiry context,
validate no expiry/current-term bypass, and compare fresh calculation with reviewed fingerprint.
Allocate number as late as practical inside transaction. Issue and receipt either commit together
or rollback; central sequence may retain allowed gap. Scope/availability changes cannot cross issue,
withdrawal/replacement/acceptance with incompatible claims. Future R6 must use the same coordinator;
absence of an acceptance row is not proof of no accepted business or a lock.

Initial whole-unaccepted operations require explicit supported acceptance-state reader and disabled
unsupported acceptance/import writers. When R6 state is present/enabled, use authoritative accepted
claim generation; missing adapter/status is UNEVALUABLE, not accepted_qty zero. Partial replacement
refuses `PARTIAL_REPLACEMENT_NOT_SUPPORTED` until R6/R7 acceptance/allocation/consent/commitment
support is approved/delivered. Gate at Action and database writer manifest, not a hidden UI button.

Requests validate structure/current authorisation before replay, not mutable active-state/date/token
expiry prerequisites that would reject an already completed operation. After receipt lookup, new
operations validate fresh eligibility/token/source prerequisites; expired/missing preview context
refuses rather than calculating a new unreviewed result.
Retry: after current duty/read/field/scope checks, matching completed receipt replays original outcome
before new draft/stale checks; same key/different payload conflicts. New key on issued revision cannot
issue again. Actor/company/subject identity and server-normalised economic intent are in request hash;
completed response re-projects immutable snapshot with current sensitive access. Never persist/cache
privileged JSON as universal replay output; original facts/number remain unchanged, current availability
may be separately labelled. Cancelled/withdrawn/replaced history never reopens on replay.

Constraints: same-company composite FKs including candidate/issue/revision/family/logical scope;
issue uniqueness, official-number uniqueness, ordinal/input-generation checks; exactly typed source/
choice references; representable positive chargeable quantities and meaningful zero only under approved
source semantics; money4 and known/unknown nullable consistency; issued recipient/currency/number/
snapshot/expiry mandatory; expiry after actual issue; included-component intent valid. Immutable evidence
triggers; draft writes state-guarded; deferred completeness/conservation and available-overlap checks.
Append-only event plus mutable projection updated together; SQL cannot create an unbacked issue or
restore a superseded actionable scope. Indexed company/family/revision and scoped date/status read paths.

Migrations additive with real down and isolated apply→rollback→reapply; evidence-bearing rollback
refuses destructive removal rather than fabricating history. New monetary rows register FinancialActivity.
No reconstruction of historical accepted facts from notes/current prices, no rewriting old migrations,
no orphan quote tables or generated-client edits. Truthful imported baseline, if later authorised,
labels capture-after-receipt; legacy accepted states need authoritative mappings before affected actions.

## API/UI, duties and customer-safe projection

API prefix `/api/v1`: `/quotations` draft create/list; `/{id}` scoped detail/draft update;
`/{id}/preview`, `/candidates`, `/review-submissions`, `/revalidations`, `/adoptions`, `/issue`,
`/cancel-draft`, `/revisions`, `/withdraw`, `/replacement-issue`; `/quotation-policy-versions` config.
Named POST transitions with idempotency; PATCH draft inputs only, no generic lifecycle PATCH.
Candidate/revision/history subresources require independent quote eligibility; shared review workspace
uses0085 whitelists/request-specific eligibility, not general cost access. Schemas/clients regenerated
by coding with source changes; public routes disabled until exact R9 duty/grant/scope contract delivered.

Proposed duty mapping (NOT registered/granted): `.prepare` covers normal draft/candidate/revision and
ordinary cancellation/submission; `sales.quotation.issue` issue; `.withdraw` governed seller withdrawal;
`.approve` shared quote approval; `.policy.manage` config. Complete names live in slice duty table.
Reuse existing `sales.numbering.manage` for type configuration and `numbering.series.select` only for
manual series choice; source adjustments/cost reads use their own suitable existing vocabulary.
Request AND Action guard exact duties plus independent current company/Party/source/record eligibility.
No implicit ownership/editor/manager grants. Unsupported limited Sales scope refuses under 0106 until
R9 supports it; don't invent “my quotes” as authorization. Genuine full-access re-sync only under explicit
registered mandate; new sensitive duties require owner approval and explicit rollout before enabling.

Resources: decimal strings, internal family/revision and official issue number, generation/allowed
Actions from server, dates/expiry time zone, baseline/choices, safe financial net/output/gross and
separate UNKNOWN withholding/payment, acknowledgement availability, stale reasons and scoped history.
Protected cost/margin/risk/other Parties' terms remain privileged or request-specific review projection;
no leaks through totals/diagnostics/history/errors/replay/customer exports. R8 customer artefact must
preserve honest UNKNOWN disclosure; issuer checkbox never substitutes for customer acceptance.

SIMPLE form: save inputs → review displayed result → Issue; conditional acknowledgement/reason/review
only when required. MANAGED source/term controls show when relevant. Detail distinguishes editable
DRAFT, calculated preview, candidate/adoption/approval and issued immutable offer; one official number
per issued revision, original available while successor drafts, reasoned withdrawn/replaced/expired
availability alongside separate future accepted scope. Follow shared sheets/shell, mobile/no overflow,
light/dark/loading/empty/error/denied/stale/conflict/success/history states; no R6 acceptance/job button.

Stable proposed codes: `STALE_EVIDENCE`, `REVIEW_RESULT_CHANGED`, `APPROVAL_REQUIRED`,
`REVIEW_EVIDENCE_CHANGED`, `ISSUE_ALREADY_RECORDED`, `QUOTE_VALIDITY_ENDED`,
`UNKNOWN_PAYMENT_BLOCKED`, `UNKNOWN_PAYMENT_ACKNOWLEDGEMENT_REQUIRED`,
`PARTIAL_REPLACEMENT_NOT_SUPPORTED`, `ACCEPTANCE_STATE_UNAVAILABLE`, `REPLACEMENT_SCOPE_CHANGED`.
Reuse canonical API exception/status mapping (Request authorization403; business rule422;
concurrent/idempotency conflict409 as applicable), not message parsing or leaked internal causes.

## R8 issue/document reconciliation — accepted2026-10-15

0133–0135 require QL3 to freeze customer projection/manifest/asset versions with commercial issue.
Default issue does not await PDF success; pending/failed generation leaves issued facts intact,
official sending blocked until verified artefact. Prepared-required policy needs delivered exact
number/date/fact checks or refuses. This is a specified extension, not delivered/verified QL3.
See [artefact contract](quotation-documents.md) for storage/recovery and immutable original. R9 public duties
and actual R6/R7 partial-family adapters remain gates; no document action bypasses them.

## Related

- [R7 accepted contract and actual-delivery gates](../phases/phase-4-r7-implementation-slices.md) — R7 authority is accepted0131–0132 and 0136; necessary adapters remain undelivered, so affected partial-family/correction gates remain.

- [R5 slices](../phases/phase-4-r5-implementation-slices.md) · [Approved L1–L6](../phases/phase-4-r5-business-review.md)
- [Financial contract](quotation-financial-calculation.md) · [Pricing resolution](sales-pricing-resolution.md)
- [Sales pipeline](sales-pipeline.md) · [R6 research](../phases/phase-4-r6-customer-decisions.md)
- [Authorisation](../architecture/authorization.md) · [Domain integrity](../architecture/domain-integrity.md)
