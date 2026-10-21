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
reuse actor/key convention only for later client commands; receiver identity uses origin, not that
Sales-specific interface or actor/key convention.
New work follows module providers, owning Actions, typed values and Requests/Resources conventions.
Code-structure's Projects/Production examples are not existing modules or assigned Job owners.
Propose `backend/app/Modules/Jobs`, with its own README/provider, pending naming approval.
No accepted ADR, Phase 4 contract, application code or tooling changed. Revised against coordinator
review on #19 (PASS WITH REFINEMENTS): A1–A5 narrow SP0b; B1–B3/C1–C2/D1/E1 are later contracts.

## SP0b physical candidates — four tables only (A1–A5)

The Laravel queue migration already owns `jobs`; the domain parent is **service_jobs**, model
**ServiceJob**, in the proposed Jobs module. Later `/jobs` API paths remain valid. Every table has
company_id, UNIQUE(company_id,id), company-composite FKs, company-first indexes and RESTRICT evidence
deletion. Use existing bigint IDs, BelongsToCompany and company checks. Business identity is separate
from row ID. Recording timestamps are UTC; business dates use Company timezone.

| SP0b table candidate | Columns and constraints beyond company/id |
|---|---|
| service_jobs | code, state, generation; UNIQUE(company_id,code); state CHECK RELEASED/ACTIVE/COMPLETED/CLOSED; positive generation; no current_baseline_id, last_event_id or circular FK |
| job_handover_origins | job_id, source_contract_version, target_purpose, business_identity, intent_hash; composite FK to service_jobs; UNIQUE(company_id,job_id), UNIQUE(company_id,target_purpose,business_identity); immutable receiver receipt anchor, not acceptance authority |
| job_baselines | job_id, version, predecessor_id nullable, scope_description, completion_rule_version, fingerprint; unique company/job/version and company/job/id; same-Job predecessor FK; immutable; no unchecked source baseline reference |
| job_events | job_id, operation, from_state, to_state, prior_generation, next_generation, occurred_at nullable, occurrence_precision, recorded_at, actor_id, reason, evidence_version; UNIQUE(company_id,job_id,next_generation); positive next_generation; immutable |

No activities, default-activity trigger, assignment events, holds, components or job_operation_receipts
in SP0b. No deferred circular head FKs or transition/head triggers: those belong to lifecycle.
Append-only triggers cover origins/baselines/events; parent has state/generation CHECKs. Fixture rows
can exercise the constraints in isolated tests, but no production Job creation/transition path exists.
Actor references follow the existing company-membership model and preserve recorded identity.
Use a Jobs-specific guard function name, e.g. job_evidence_append_only_guard, never the Documents guard.

Origin carries no acceptance/allocation/stage/source-ID strings or unchecked polymorphic references.
The receiver-integration migration later adds actual composite-FK Sales references. The origin's
job_id makes it the target receipt; no extra receiver receipt table or parent-to-origin circular FK.
SP0b has only a validator/value object, **no ReceiveReleasedService entry point**, source registry or
production insertion Action. Fixture insertion is test-only, not an accepted-service shortcut.
No duty constants, ->can/authorize literals, routes, grants or PermissionRegistry entries in SP0b.
The permission ratchet must see no unregistered new duty literals.

Put scope_description and completion_rule_version on baseline; these preserve the declared basis
without inventing a measured unit. Defer job_baseline_components entirely until receiver integration
and approval of the service completion basis. Never encode unknown quantity/measure as 0, 1 or a
made-up unit. J1/SP0b has no labour table, monetary columns or actual-cost ledger.
Index company/state/id for later list shape and company/job/next_generation for history. No speculative
GIN indexes. EXPLAIN representative histories at scale before activation.

## Later-slice schema candidates

| Slice / deferred table | Candidate shape and gate |
|---|---|
| Receiver integration: origin extensions | Real acceptance/allocation/stage composite FKs after Sales tables exist; receiver identity UNIQUE(company_id,stage_id,target_purpose,business_identity); exact upstream source/baseline/generation binding; Jobs migration references Sales, never Sales FK into Jobs |
| Receiver integration: job_baseline_components | job_id, baseline_id, accepted component/original scope FKs and conversion evidence only after actual supported contracts; measure_id and quantity optional as a pair, both absent or both present with quantity > 0; source precision/increment governs |
| Receiver integration: job_activities | job_id/code/generation/is_default; unique company/job/code and partial unique default; real release creates one default activity; default existence trigger arrives here, not SP0b |
| Lifecycle: job_assignment_events | same-Job activity, sequence/predecessor, assignee member or responsibility label, valid optional interval, actor/reason/time; immutable; stable assignment command identity; labels grant no access |
| Lifecycle: job_holds / job_hold_resolutions | independent hold identity and effective_from/reason/actor/time; one resolution per hold with valid effective_until; stable command identities; resolving one hold leaves others |
| Lifecycle: job_operation_receipts | actor_id/request_key/operation/intent_hash/job_id/event_id/contract_version/completed_at; UNIQUE(company_id,actor_id,request_key); append-only safe result references; for client commands, not receiver identity |
| Lifecycle: job_outcomes / job_remainder_dispositions | immutable completion/withdrawal/stop/cancel evidence, completion rule and owning fulfilment/disposition receipt; remainder measure+quantity optional pair with positive quantity when present, never invented for an unmeasured service |
| Closeout: assessments/components/closeouts | explicit family/inventory/generation/outcome/safe reason/evidence, immutable source vector and outcome/authority/time; one original closeout per company/Job |
| Historical resolution: cases/events | original evidence/classification/reason and chained actor/time/source-generation/correction receipts; no restart authority |

Later lifecycle adds database transition/event-generation consistency guards once writers exist;
SP0b's event uniqueness alone does not claim to enforce the entire state machine. Derive latest
baseline/event through version/generation; no circular head FK is required. Same-Job composite FKs
protect all deferred evidence. No source identity stored solely as arbitrary JSON.
Later quantities reuse exact source precision/increments/conversions, never float or assumed monetary
scale. Future monetary source owners use decimal(19,4), both amounts/currencies/pinned FX and
FinancialActivity; cost-bearing baseline extensions must register too. None belongs to SP0b.

## Later receiver integration — R7 and exact receipts (B1–B3)

Sales owns acceptance/capacity, source eligibility, allocation/stage/source baseline and release.
Jobs owns insertion/default activity/target evidence. Neither writes the other's tables directly.
Later candidate Jobs Action: ReceiveReleasedService (absent from SP0b) with Jobs-local input/result values, invoked by the
real Sales service adapter. No speculative shared handover framework or autonomous POST Job creation.

Input: actor/company; supported path/version; acceptance/root allocation/stage/purpose/business
identity; immutable baseline/components/digests; expected root/allocation/stage/source generations;
approved completion basis and optional supported measure/quantity pair; authority/approval reference; canonical intent hash and R7
operation identity. Client authorised flags or arbitrary target IDs are never trusted.
Receiver checks its own authority/read/scope and target prerequisites under the outer transaction.
Sales proof must be obtained through the real supported contract under locks, not a fabricated DTO.
Return company/Job/default activity IDs, origin identity, target baseline/digest/generation and Job
origin-row ID as receiver receipt. Sales stores a result link to that origin ID (not a FK into Jobs);
Jobs never calls Sales OperationReceiptPort. Sales receipt links its exact allocation/stage/baseline
to this result. All commit
with the target/allocation effects; a dispatched queue message is not a durable receiver receipt.

Receiver identity is (company,stage,purpose,business_identity), independent of actor/key.
Same receiver identity + same business intent returns original permitted target, including fresh keys
or another currently authorised actor. Changed intent conflicts; supported baseline successor is a
separate command. Sales keyed replay checks current authority and renders safe original result references
with current permitted state. Later lifecycle changes do not require rerunning obsolete release
readiness merely to retrieve the original receipt.

## Later lifecycle Actions and ownership

| Candidate Action | Owning effect / boundary |
|---|---|
| ReceiveReleasedService | Jobs; origin/Job/baseline/activity/event/receipt in Sales outer release transaction |
| AssignJobActivity / StartJob / AssignAndStartJob | Jobs; assignment and execution distinct, combined operation needs both duties or neither commits |
| PlaceJobHold / ResolveJobHold | Jobs effects plus applicable R7 hold/amend checks; resolve named hold only, no bypass |
| RecordJobCompletion / WithdrawJobCompletion | Jobs with supported fulfilment and dependent impact evidence; preserve original completion |
| CancelUnusedJob / StopJob | Jobs + real R7/source impacts; preserve actuals/remainder, no commercial release |
| AssessJobCloseout / CloseJob | Jobs aggregates source verdicts; advisory preflight, locked commit; close evidence/event/receipt atomic |
| RecordHistoricalJobReport / ResolveJobDiscrepancy | Jobs captures/classifies; source owner applies authorised corrections; no implied work/recognition |
| ReadJob / ReadJobWorksheet | J1 operational projections with no cost fields; scoped source ownership retained |
| ReadJobCostSummary | J2 or later, independently scoped source valuation/coverage/completeness projection |

Preserve RELEASED→ACTIVE→COMPLETED→CLOSED, independent holds/termination, and only supported
COMPLETED→ACTIVE withdrawal before close. Cancel-unused requires proven unused; stop retains
accountable remainder. CLOSED/terminated ordinary restart refuses. Unsupported successors/reductions
refuse instead of locally freeing claims. Occurrence precision/time, recording time, historical
assignment/holds and original correction lineage remain explicit. Late contradiction of unused cancel
opens a visible case and coordinated R7 reconciliation before unsafe claim reuse.

## Later lifecycle permission and RecordScope matrix (C1–C2)

Candidate names need catalogue/collision review; no registration/grant here or in SP0b. Later Jobs
owns JobDutyManifest/JobAccess mirroring Sales semantics, without reusing SalesAccess. Default deny
unless the duty is ACTIVE; all new duties begin STAGED until a reviewed holder recipe activates them.
Per required duty RecordScope must resolve to ALL; limited grants refuse, never filter. Request and
Action both guard company/membership/owning duty/legitimate record access; non-HTTP callers supply
explicit actor. Independent read access is not a mandatory extra job.view grant for close: close may
use its reviewed safe-read contract. No CRM-manage, assignment or role-name inference.

| Candidate duty | Additional boundary |
|---|---|
| job.view | Separate STAGED operational worksheet/history duty; not required for J1 close, whose own safe record access must be proven |
| job.assign / job.start | Independent operations; assignment grants no read/access |
| job.hold / job.hold.resolve | Exact hold and applicable independent Sales handover duty |
| job.complete / job.completion.withdraw | Fulfilment evidence and withdrawal's dependent impact authority |
| job.cancel_unused / job.stop | Dedicated disposition authority; not commercial claim release |
| job.close | Own reviewed safe Job/readiness access; needs neither job.view nor job.cost.view; no source cost/correction privileges |
| job.history.record / job.history.review / job.history.correct | Capture, review and correction distinct; legitimate original record/source reads |
| job.cost.view | J2/later separate STAGED cost duty; not needed for J1 close; no implicit rates, identities, files or configuration |
| R9 handover release + job.receive | Later Jobs receiving duty explicitly named job.receive, STAGED until holder recipe; independent Sales release authority and source/target access; never trusted boolean/delegation inference |
| J2 source record/recognise/correct/coverage declare | Source-owned duties under0146/0147; combined action needs both; never implied by completion |

Recommend initial company-wide scope per required duty; none/units/own/assigned scopes refuse safely
until full Job mapping exists. Reuse RecordScope resolver; do not add a new parallel scope system.
Resolve each duty separately: unrestricted read cannot widen restricted close/cost/correction.
Cover lists/counts/search/history/nested resources/selectors/receipts/exports/errors. No UI-only filter.
Readiness returns reviewed non-monetary actionability; denied dependencies remain safe blockers,
not omitted obligations. Hide private IDs/rates/thresholds/hashes. J1 returns no cost fields. J2/later omits them without cost
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
actions. J1 returns no cost fields and does not implement ReadJobCostSummary or labour tables.
Known pending labour facts arrive only through a source-owned J1 readiness input, never a local
labour ledger. Optional absence is not fabricated evidence. J2/later cost view separates source
valuation, category coverage and overall completeness; completion/closure never promotes certainty.
Estimate/baseline/current actual remain distinct. Read coherent generations or retry/refuse. J2 needs
real recognition/attribution/correction/invalidation before recognised totals (D1).

## Technical review and smallest SP0b

Recommend Jobs module, immutable evidence/generation heads, later same-connection receiver, initial
company-wide-only scope and explicit readiness. These remain proposals; no new business ADR asserted.
Owner/reviewers approve exact service/completion basis, final duty strings/holder recipe, physical
names/reference binding after actual CA/HA design, and first supported correction/disposition paths.
Actual lock compatibility requires joint source-writer review before activation.
Coordinator assigns Sales/R7, Jobs, security/integration and J2 source/costing owners separately.

Smallest separately authorised SP0b: module README/provider without routes; validator/value object
only; exactly job_handover_origins, service_jobs (ServiceJob), job_baselines and job_events plus
company/immutability/state/event-generation uniqueness tests. Origin row is the future receiver
receipt. No ReceiveReleasedService entry point or unchecked upstream reference strings. No circular
head FKs or transition/head triggers. No activities/components/assignment/holds/client receipts,
labour/cost engine, duties/constants/authorization literals/PermissionRegistry entries/grants,
endpoints/client/demo target. Fixture insertion only in isolated tests. SP0b cannot create real Jobs.
Append-only guard uses its own Jobs name; down() refuses when evidence rows exist, even fixtures.
Timestamp is reserved by coordinator at implementation, later than develop's newest migration at
merge (E1). Separate owner approval is still required; no implementation follows from this package.

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
