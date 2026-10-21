[← Phase index](README.md)

# Phase 5 SP0a — physical, interface and security design

2026-10-21 · Task #19 · **PROPOSED technical candidates for owner review.**
ADRs 0145–0152 remain ACCEPTED; bounded domain contracts remain SPECIFIED. This package is not
schema/API approval. No Job capability is IMPLEMENTED, VERIFIED or PUBLICLY ENABLED by this work.
SP0b requires separate implementation authority.

## Basis and preserved direction

Continue the accepted 21 October readiness reconciliation at `3ed28be`, without repeating research.
Intended source: authoritative accepted service scope through R7. J1 completion-only is independently
signable; J2 optional direct incurred labour is a separate extension. No universal Assembly, timesheet,
named worker, stock, subcontract or formal Job PDF. Exact eligible service adapter still needs review.

Inspection at `3ed28be`: QL0 exists, no real CA/R7 or Job receiver. Internal PR/FR/FX/rounding/control
and AU foundations are building blocks, not service delivery. RecordScope resolves each capability
as all/none/units; SalesAccess currently requires all. Sales OperationReceiptPort uses SalesActorContext:
reuse its receipt convention, not that Sales-specific interface as shared infrastructure.
New work follows module providers, owning Actions, typed values and Requests/Resources conventions.
Code-structure's Projects/Production examples are not existing modules or assigned Job owners.
Propose `backend/app/Modules/Jobs`, with its own README/provider, pending naming approval.
No accepted ADR, Phase 4 contract, application code or tooling changed.

## Physical schema candidates

Names below are proposals. Use existing bigint row IDs; business identity is separate. Every table:
company_id; UNIQUE(company_id,id); company-composite FKs; RESTRICT evidence deletion; company-first
indexes. Apply BelongsToCompany and Action company checks. Actor/assignee references use the actual
company-membership model; retain immutable actor identity after deactivation. New inactive or
cross-company assignees refuse. Timestamps are UTC; business dates use Company timezone.

| Table candidate | Columns and constraints beyond company/id |
|---|---|
| jobs | code, handover_origin_id, state, generation, current_baseline_id, last_event_id; unique company/code and company/origin; state CHECK RELEASED/ACTIVE/COMPLETED/CLOSED; positive generation |
| job_handover_origins | source_contract_version, acceptance_reference, allocation_reference, stage_reference, target_purpose, business_identity, intent_hash; unique company/stage/purpose and company/business_identity; immutable lineage, never commercial authority |
| job_baselines | job_id, version, predecessor_id, source_baseline_reference, source_generation, fingerprint, scope_description, completion_rule_version; unique company/job/version; predecessor belongs to same Job; immutable |
| job_baseline_components | job_id, baseline_id, accepted_component_reference, original_scope_anchor, measure_id, quantity, conversion_evidence_reference; unique company/baseline/component; exact positive supported quantity |
| job_activities | job_id, code, generation, is_default; unique company/job/code; partial unique company/job where is_default; release creates exactly one default activity; no independent commercial claim |
| job_assignment_events | job_id, activity_id, sequence, predecessor_id, assignee_member_id nullable, responsibility_label nullable, intended_from/until nullable, actor_id, reason, recorded_at; immutable chain, valid interval; label is not identity/access |
| job_events | job_id, sequence, operation, from_state, to_state, prior_generation, next_generation, activity_id nullable, occurred_at nullable, occurrence_precision, recorded_at, actor_id, reason, evidence_version; unique company/job/sequence; append-only |
| job_holds / job_hold_resolutions | company/job, hold identity, effective_from, reason, actor/time; resolution names one hold, effective_until and evidence; at most one resolution per hold; interval valid; multiple independent holds remain |
| job_outcomes | job_id, event_id, kind, completion_rule_version, occurrence context, fulfilment_evidence_reference; immutable completion/withdrawal/stop/unused-cancel facts; no editable percentage |
| job_remainder_dispositions | job_id, source_component_reference, measure/quantity, disposition_kind, owner_receipt_reference, predecessor_id; only supported owning disposition; never local commercial claim release |
| job_readiness_assessments / job_readiness_components | assessment Job/generation/policy/time/actor; component family, applicability_basis, inventory_version, source_generation, outcome, safe_reason, owning_evidence_reference; append-only |
| job_closeouts | job_id, event_id, outcome_id, assessment_id, generation_vector_hash, authority_reference, recorded_at; unique company/job; original evidence immutable |
| job_resolution_cases / job_resolution_events | job_id, original_evidence_reference, classification, reason; chained actor/time/source generation/owning correction receipts; current status derived from immutable events |
| job_operation_receipts | actor_id, request_key, operation, intent_hash, job_id, event_id, contract_version, completed_at; unique company/actor/key; immutable result references, no unredacted response blob |

Use same-Job composite FKs, e.g. (company,job,baseline) to an explicitly unique baseline tuple.
Create head cycles transactionally with deferred FKs as needed; successful release leaves no null
baseline/event head. DB rejects evidence UPDATE/DELETE, invalid transitions and head/event/generation
mismatch. Unique (company,job,prior_generation,operation) protects transition effect identity. Holds
and assignments additionally have stable command identities so legitimate separate facts are retained.
Default activity existence is checked by deferred constraint trigger at release commit, not just uniqueness.

Source reference columns are typed contracts, not unchecked polymorphic IDs. Final local CA/HA
references become composite FKs to actual integrated tables. Until those exist, production origin
insertion stays disabled; string identifiers do not prove acceptance. SP0b may persist only validated
fixture origins in isolated tests, with no production insertion path. Integration must replace the
reserved reference shape with reviewed actual bindings before any public release.
Core lifecycle/quantity/identity fields use columns; bounded narrative/evidence envelopes may use
versioned JSON, not arbitrary mutable snapshots. Foreign evidence access stays with its owner.

Quantities reuse source exact precision/increment and conversion contracts, never float or an assumed
four-place monetary scale. J1 stores no actual-cost ledger or authoritative commercial money.
Future monetary owners store decimal(19,4), both amounts/currencies and pinned FX, and register
FinancialActivity; cost-bearing baseline extensions must register too. No monetary columns in SP0b.
Indexes: company/state/id; company/job/sequence; company/job/effective_from for holds;
company/job/family for readiness. EXPLAIN representative large histories before activation.

## R7 receiver and exact receipts

Sales owns acceptance/capacity, source eligibility, allocation/stage/source baseline and release.
Jobs owns insertion/default activity/target evidence. Neither writes the other's tables directly.
Candidate Jobs Action: ReceiveReleasedService with Jobs-local input/result values, invoked by the
real Sales service adapter. No speculative shared handover framework or autonomous POST Job creation.

Input: actor/company; supported path/version; acceptance/root allocation/stage/purpose/business
identity; immutable baseline/components/digests; expected root/allocation/stage/source generations;
exact measure/quantity/completion basis; authority/approval reference; canonical intent hash and R7
operation identity. Client authorised flags or arbitrary target IDs are never trusted.
Receiver checks its own authority/read/scope and target prerequisites under the outer transaction.
Sales proof must be obtained through the real supported contract under locks, not a fabricated DTO.
Return company/Job/default activity IDs, origin identity, target baseline/digest/generation and Job
receipt reference. Sales receipt links exact allocation/stage/baseline to this result. All commit
with the target/allocation effects; a dispatched queue message is not a durable receiver receipt.

Same stage/purpose + same business intent returns original permitted target, including fresh keys
or another currently authorised actor. Changed intent conflicts; supported baseline successor is a
separate command. Keyed replay checks current authority and renders safe original result references
with current permitted state. Later lifecycle changes do not require rerunning obsolete release
readiness merely to retrieve the original receipt.

## Actions and ownership

| Candidate Action | Owning effect / boundary |
|---|---|
| ReceiveReleasedService | Jobs; origin/Job/baseline/activity/event/receipt in Sales outer release transaction |
| AssignJobActivity / StartJob / AssignAndStartJob | Jobs; assignment and execution distinct, combined operation needs both duties or neither commits |
| PlaceJobHold / ResolveJobHold | Jobs effects plus applicable R7 hold/amend checks; resolve named hold only, no bypass |
| RecordJobCompletion / WithdrawJobCompletion | Jobs with supported fulfilment and dependent impact evidence; preserve original completion |
| CancelUnusedJob / StopJob | Jobs + real R7/source impacts; preserve actuals/remainder, no commercial release |
| AssessJobCloseout / CloseJob | Jobs aggregates source verdicts; advisory preflight, locked commit; close evidence/event/receipt atomic |
| RecordHistoricalJobReport / ResolveJobDiscrepancy | Jobs captures/classifies; source owner applies authorised corrections; no implied work/recognition |
| ReadJob / ReadJobWorksheet / ReadJobCostSummary | Jobs projections; operational/sensitive reads separate, source ownership retained |

Preserve RELEASED→ACTIVE→COMPLETED→CLOSED, independent holds/termination, and only supported
COMPLETED→ACTIVE withdrawal before close. Cancel-unused requires proven unused; stop retains
accountable remainder. CLOSED/terminated ordinary restart refuses. Unsupported successors/reductions
refuse instead of locally freeing claims. Occurrence precision/time, recording time, historical
assignment/holds and original correction lineage remain explicit. Late contradiction of unused cancel
opens a visible case and coordinated R7 reconciliation before unsafe claim reuse.

## Permission and RecordScope matrix

Candidate names need catalogue/collision review; no registration/grant here. Every operation requires
current company membership, its own duty scope AND legitimate Job read scope. Request and Action both
guard; non-HTTP callers supply explicit actor. No CRM-manage, assignment or role-name inference.

| Candidate duty | Additional boundary |
|---|---|
| job.view | Operational worksheet/history; excludes private source, cost/rate, approval evidence and files |
| job.assign / job.start | Independent operations; assignment grants no read/access |
| job.hold / job.hold.resolve | Exact hold and applicable independent Sales handover duty |
| job.complete / job.completion.withdraw | Fulfilment evidence and withdrawal's dependent impact authority |
| job.cancel_unused / job.stop | Dedicated disposition authority; not commercial claim release |
| job.close | Safe readiness access, not source cost/correction privileges |
| job.history.record / job.history.review / job.history.correct | Capture, review and correction distinct; legitimate original record/source reads |
| job.cost.view | Independent cost scope; no implicit rates, identities, files or configuration |
| R9 handover release + Jobs receive | Sales owns final handover vocabulary; target receiving authority needs explicit holder/delegation review, not a trusted boolean |
| J2 source record/recognise/correct/coverage declare | Source-owned duties under0146/0147; combined action needs both; never implied by completion |

Recommend initial company-wide scope per required duty; none/units/own/assigned scopes refuse safely
until full Job mapping exists. Reuse RecordScope resolver; do not add a new parallel scope system.
Resolve each duty separately: unrestricted read cannot widen restricted close/cost/correction.
Cover lists/counts/search/history/nested resources/selectors/receipts/exports/errors. No UI-only filter.
Readiness returns reviewed non-monetary actionability; denied dependencies remain safe blockers,
not omitted obligations. Hide private IDs/rates/thresholds/hashes. Cost fields are omitted without cost
read, never a zero placeholder. Documents/file access and approval evidence require independent rights.

## Source readiness and J1 truth

Propose typed Jobs-local readiness values per named owning adapter: family, contract version,
source inventory/basis/scope/generation, outcome, safe blocker and private evidence/receipt reference.
Use0151 outcomes: RESOLVED, SUPPORTED_VALUATION_FOLLOW_UP, BLOCKING, UNSUPPORTED_UNKNOWN,
NOT_APPLICABLE_WITH_EVIDENCE. No generic registry default to success. Every required family is accounted
for by supported inventory/rules; absent code/query rows are not proof of inapplicability.

J1 requires real operational/R7 fulfilment/remainder. Supported path restrictions and writer inventory
must evidence other families' inapplicability. Known custody/supplier/pending labour facts block even
if normally excluded. Optional no-cost-entry remains Unassessed, not zero/confirmed complete.
Valuation follow-up requires an actual supported source correction path, eligible acknowledged owner,
reason and review trigger; free text cannot waive unknown effects or mandatory atomic corrections.

Worksheet separately shows state, holds, termination, assignment, fulfilment, remainder and permitted
actions. Cost view separates source valuation, category coverage and overall completeness. Completion
or closure never promotes cost certainty. Estimate/baseline/current actual are distinct. Read coherent
generations or retry/refuse. J1 can use current authoritative facts without inventing a materialised
costing engine. J2 needs real recognition/attribution/correction/invalidation before recognised totals.

## Technical review and smallest SP0b

Recommend Jobs module, immutable evidence/generation heads, same-connection receiver, initial
company-wide-only scope and explicit readiness. These remain proposals; no new business ADR asserted.
Owner/reviewers approve exact service/completion basis, final duty strings/holder recipe, physical
names/reference binding after actual CA/HA design, and first supported correction/disposition paths.
Actual lock compatibility requires joint source-writer review before activation.
Coordinator assigns Sales/R7, Jobs, security/integration and J2 source/costing owners separately.

Smallest separately authorised SP0b: module README/provider without routes; disabled receiver value
contract/validation/missing-source refusal; origin/Job/baseline/components/default activity/event/receipt
schema and tenant/immutability/unique-anchor tests. Production origin writer stays disabled pending
real CA/HA FKs. No assignment/holds/closeout engine, source registry, duties, grants, monetary columns,
endpoints, client or demo operational target. No Job execution can start. Review rollback and schema
constraints first. Test-only fixtures never count as genuine integrated acceptance.

Later disabled slices add lifecycle/readiness evidence and guards; real SP2b waits for SP1 + actual
HA0/1/2/service HA3b and receiver together. J1 activation also needs execution/closeout/security/holder
proof. J2 adds recognition/attribution/correction/coverage atomically. Inventory/Purchasing/formal output
remain separately authorised affected-path integrations. Disabled foundations alone cannot sign off J1.

## Related

- [Transaction/API/verification companion](phase-5-sp0a-delivery-contract.md)
- [Overview](phase-5-job-execution.md) · [Readiness](phase-5-implementation-readiness.md)
- [Lifecycle](../domain/job-execution-lifecycle.md) · [Closeout](../domain/job-closeout-reconciliation.md)
- [R7](../domain/sales-operational-handover.md) · [R7 slices](phase-4-r7-implementation-slices.md)
- [RecordScope](../../backend/app/Support/Scope/RecordScope.php)
- [Receipt port](../../backend/app/Modules/Sales/Support/Kernel/OperationReceiptPort.php)
- [Database](../architecture/database.md) · [Code structure](../architecture/code-structure.md)
