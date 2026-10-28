[← Phase index](README.md)

# Task B — J1 basic Job execution and closeout design

2026-10-22 · #34 · PROPOSED for coordinator/owner review, DESIGN ONLY.
ADRs 0145–0152 remain ACCEPTED; this adds SPECIFIED technical candidates, not IMPLEMENTED,
VERIFIED or PUBLICLY ENABLED execution. No code, migrations, routes, permissions or grants created.
Reuse [0145 lifecycle](../domain/job-execution-lifecycle.md), [0151 closeout](../domain/job-closeout-reconciliation.md)
and integrated [Task A](phase-5-r7-job-receiver-design.md); no business research repeated or ADR reopened.

## Evidence and supported boundary

Baseline 5525293 integrates Task A (#32), QL1a (#10) and Documents DF1a (#22). Inspected Sales
QuotationOperation/QuotationOperationReceipts illustrate actor/key/hash replay before fresh work;
they are Sales-specific helpers, not an implemented Jobs operation service. No Jobs module on this
baseline. #28 is In Review on another branch (agent-b), not integrated or consumed here. CA/R7,
receiver, completion-condition evidence, Jobs duties and source readiness remain GATED dependencies.
Do not mistake a draft quotation or disabled foundation for a released executable Job.

J1 begins with a genuine RELEASED ServiceJob/default activity from the supported R7 receiver.
First source remains SERVICE_ITEM, full fixed deliverable with pinned accepted-baseline conditions.
Offered quantity/UoM is commercial provenance only. No invented operational 1/unit/hour/percentage.
Completion conditions have no authoritative source today: evidence shape/version is GATED until Sales
supplies it. Basic execution requires no named worker identity, timesheet, expense, stock issue,
subcontract, Assembly, formal PDF, job.cost.view or J2. Assignment records responsibility, not payroll.

## Effective lifecycle and candidate Actions

Authoritative state remains RELEASED, ACTIVE, COMPLETED, CLOSED; independent holds and termination
(CANCELLED_UNUSED/STOPPED) are facts, never competing enum states. Every command/read/selector/history
uses this effective combination. RELEASED is not started; COMPLETED is not closed or financially settled.

| Action / owning module | Preconditions and atomic facts | Result / refusal |
|---|---|---|
| AssignJobActivity — Jobs | RELEASED/ACTIVE, current baseline/generation, eligible company responsibility, applicable hold rules and contractual limits; append assignment/event/receipt | No start/access grant; inactive/cross-company target refuses; material contractual change goes to Sales |
| StartJob — Jobs | RELEASED, valid current responsibility/execution context, no hold/termination, supported readiness, current authority | Append explicit start evidence/event; state ACTIVE; never infer historical start |
| AssignAndStartJob — Jobs | All assignment AND start checks/duties | Both facts/event effects/receipt or neither, no partial assignment on failed start |
| PlaceJobHold — Jobs | RELEASED/ACTIVE, reason and held scope; applicable R7 coordination proof | Independent hold/event; no erased work; no ordinary held execution |
| ResolveJobHold — Jobs | Named unresolved hold, current prerequisites and own resolve authority | Append resolution for only that hold; no auto-start; other holds remain |
| RecordJobCompletion — Jobs | ACTIVE, no blocking hold/termination/discrepancy, exact pinned baseline and fulfilment basis, current authority | Fixed-deliverable attestation/evidence + event, COMPLETED; no hours/percentage/cost inference |
| AssessJobCloseout — Jobs/source owners | Eligible completed/terminated outcome; current safe-read authority | Advisory source-family results/vector, missing-fact next actions; not permission to close |
| CloseJob — Jobs/source owners | Revalidate outcome, holds, source inventory/generations, dispositions and any supported follow-ups | Immutable closeout/assessment/event/receipt with CLOSED; any unknown mandatory impact refuses |
| WithdrawJobCompletion — Jobs/source owners | Unclosed COMPLETED, original completion, authority/readiness and supported dependent reconciliation | Append withdrawal, ACTIVE; unsafe/unknown effects refuse; ordinary closed/terminated reopening deferred |
| CancelUnusedJob / StopJob — Jobs + R7/source owners | Proven unused RELEASED / worked ACTIVE; actual and remainder impacts supported | Preserve prior lifecycle plus termination fact; no implicit claim/stock/supplier cancellation; GATED affected writers |
| RecordHistoricalJobReport / ReviewJobReport — Jobs | Current capture/review authority and original context; no execution state bypass | Append occurrence/recording/hold context and classification; capture is not fulfilment, recognition or restart |

All rows require current company, per-duty scope, expected generations and supported owning effects.
Held assignment changes require explicit supported hold policy; first path refuses them if that policy
is missing. Internal reschedule cannot alter accepted scope/deadline. Only Jobs writes its local facts;
Sales owns acceptance/capacity/claim changes, Inventory physical facts, Purchasing commitments/acceptance.
No target Action silently releases commercial capacity. Unknown impacts fail closed.

## Responsibility and fixed-deliverable completion

Assignment candidate: one active company member responsible OR a supported stable team/responsibility
identity, plus optional intended window and instructions; retain recorder, previous assignment and
both effective/recording time. No universal individual labour identity. A free-text team label cannot
prove membership, execution eligibility or scope. Initial supported assignment identity and on-behalf
rules are GATED security selections. Missing assignee at acceptance does not invalidate acceptance.

Start checks current assignment/execution context independently of access. Assigned person needs
actual operation authority; manager acting on behalf needs explicit reviewed operation context,
not an admin/role-name inference. Responsibility/assignee change never rewrites prior execution.
Use ordinary actor identity for accountable attestation, not a fabricated worker or attendance record.

Completion attestation pins Job/activity, accepted source/baseline/version, completion rule/version,
original deliverable identity, fulfilled conditions and required source evidence references, actor and
occurrence precision/time plus server recording time. Values come only from supported canonical
source/receiver baseline; missing or mismatched conditions refuse. No client-supplied success flag can
waive conditions. Attest whole fixed deliverable, no arbitrary partial fractions; measured extensions
need the supported optional measure/quantity pair and source conservation under Task A.
Configured approval, if applicable, binds exact evidence/generations; no universal second approver.
Completing activity does not duplicate Job/commercial fulfilment. Mark Job COMPLETED only when all
required supported scope is fulfilled; no completion from 100% UI progress. Retain original baseline
and original completion even after authorised withdrawal/correction.

## Candidate evidence model extensions — later implementation only

Keep service_jobs/ServiceJob and #28's four-table schema unchanged by this task. All new evidence:
company_id, UNIQUE(company_id,id), company-composite and same-Job parent FKs, immutable actor/time,
append-only UPDATE/DELETE guards, company-first indexes. Every Jobs→Sales reference uses real
(company_id,id) FK plus needed parent linkage; missing real targets remain GATED, never string IDs.

| Candidate structure | Key integrity and responsibility |
|---|---|
| job_assignment_events | job/activity, stable command_identity, predecessor/sequence, supported member/team reference, optional window, reason/actor/time; unique company/job/command identity; same-Job chain and valid intervals |
| job_holds / job_hold_resolutions | Stable hold identity, Job/scope, effective_from/reason/actor/time; resolution unique company/hold, same-Job FK, effective_until >= start; no global clear-all flag |
| job_completion_evidence | Job/activity/baseline/rule/component reference, immutable canonical attestation facts/occurrence context and event; withdrawal references exact prior evidence, no overwrite |
| job_termination_events / job_remainder_dispositions | Independent outcome, reason, source-owned impact/disposition receipt; no invented remainder measures, optional paired measure/positive quantity only if genuinely measured |
| job_readiness_assessments / components | Company/Job/head generation, policy version and complete source-family inventory/vector; immutable private canonical results and separately safe projections |
| job_closeouts | Unique company/Job original closeout, outcome/evidence/assessment reference, generation vector, authority/time and event; original never rewritten |
| job_resolution_cases / events | Original report/evidence and classifier/reason, occurrence/recording context, owning correction receipts, history; current resolution derived from append-only events |
| job_operation_receipts | Company, actor_id, request_key, operation, intent_hash, Job/result event references, version and recorded_at; UNIQUE(company_id,actor_id,request_key); immutable safe references, never cached privileged responses |

job_activities/default-activity trigger and job_baseline_components belong to GATED receiver integration
(Task A), not duplicated in Task B or SP0b. Lifecycle adds legal transition/event/head-generation
consistency guards to #28's state CHECK/unique next_generation; no claim they exist today. No circular
baseline/event head FK required. Activity/assignment/hold/history indexes follow company/Job/sequence.
Real down refuses evidence (including fixtures), preserves unrelated guards; timestamp and exact
constraint implementation are GATED to Jobs owner/coordinator. No schema files produced here.

## Permissions and access contract — candidate vocabulary, all STAGED

Jobs owns future JobDutyManifest/JobAccess, following AU default-deny unless ACTIVE; never reuse
SalesAccess. Every required capability resolves RecordScope independently to ALL; units/own/assigned
restrictions refuse rather than filter. Current membership and legitimate safe record access checked
in Request AND owning Action; workers/imports/console use explicit actor with equivalent checks.
No job.receive implies execution authority. Neither assignment nor Sales releaser/owner/admin implies
new Jobs rights. No constants, permission rows, registry or grants are introduced by planning.

| Candidate duty | Access/effect boundary |
|---|---|
| job.view | Operational worksheet/list/history only; no cost/rates/private source evidence |
| job.assign / job.start | Responsibility versus actual execution distinct; combined action requires both |
| job.hold / job.hold.resolve | Separate placement/resolution; applicable sales.handover.hold/amend authority remains independent and GATED |
| job.complete / job.completion.withdraw | Attest supported fulfilment versus sensitive withdrawal; independent dependent-source authority |
| job.cancel_unused / job.stop | Own termination and safe dispositions; no claim-release authority |
| job.close | Reviewed safe Job/readiness read and close operation; job.view and job.cost.view NOT prerequisites |
| job.history.record / job.history.review / job.history.correct | Capture/classify/correct distinct; legitimate original context access and source correction duties where effects applied |
| job.receive | Task A internal Sales-coordinator-only receiving duty; not a lifecycle client entry or substitute for the above |
| job.cost.view / labour recognition | J2/later only; no cost panel/amount/expense/recognition requirement in J1 |

GATED holder recipe lists each real company/person/duty/ALL-scope assignment and safe projection,
individually owner-reviewed; no automatic incumbent/admin/mandate growth grants. Exclude cost/margin
and J2 duties. STAGED remains unregistered/non-operative until actual guard/Action/holder delivery is
separately approved. Hand-made permission rows confer nothing. Safe own-operation reads do not grant
broad worksheet/history/source visibility. Attachments need independent Documents access.
Apply same scope/field profile to counts/search/selectors/history/receipt/replay/export/cache/errors.
Current company change clears cached responses. Foreign-company identifiers indistinguishable from
absent; no cost, hash/private source or competing ID in replay/conflicts or closeout blockers.

## Worksheet, API candidates and basic journey

J1 worksheet shows original/current supported scope, responsibility/intended window, explicit start,
effective lifecycle + holds + termination, pinned fulfilment basis, attestation/history, current safe
closeout blockers and next permitted actions. No cost fields, costs-as-zero, timesheet, labour expense,
HR identity or formal PDF panel. Empty assignment is unassigned, not permission to work. COMPLETED
and CLOSED separate; closed means operational reconciliation, not paid/invoiced/fully costed.

Later API candidates: GET /jobs/{id}/worksheet and safe scoped list/history/receipt; command suffixes
/assignments, /start, /assign-and-start, /holds, /holds/{id}/resolve, /completion,
/completion-withdrawal, /closeout-assessments, /close, /historical-reports and supported dispositions.
No public POST /jobs or generic PATCH state. This document creates none. Requests carry operation key,
command identity, expected Job/assignment/hold/baseline/source generations and exact evidence/reason;
actor/recorded_at/source success/closed state are never trusted client fields. Decimal strings only
when measured. Implementation finalises names/errors through existing API conventions, Resources,
OpenAPI/client together. Reuse REQUEST_KEY_REUSED-equivalent conflict; safe stale/held/unsupported/
unknown-impact outcomes, never SQL errors/private source metadata.

Assign → separately Start (or both with both duties) → place/resolve hold if needed → whole-deliverable
attestation → source readiness → eligible Close. UI shared sheets/tokens, server allowed actions,
loading/empty/stale/denied/held/terminated/closed/error states; keyboard, phone no overflow, light/dark.
Review exact basis before attestation/close; retry uses same intent/key, never silent optimistic success.
Known source blocker gives a permitted next action or safe owning-contact result, no cost disclosure.

## Scope of smallest separately authorised disabled batch

After actual #28 and Task A identity/activity contracts are available: propose only assignment events,
holds/resolutions and client-command receipt value/schema contracts plus isolated company/append-only/
identity tests and existing event/generation convention review. No production Actions, Job entry,
completion/closeout engine, source registry, permission constants/literals/entries/grants, route/client,
labour/cost tables or demos. Source dependencies remain GATED. Exact batch/file/migration ownership
requires coordinator review and separate owner implementation authority. If actual activity table is
absent, stay at typed value/validation design; do not invent a substitute FK or enlarge SP0b.

Later separately bounded batches deliver guarded assign/start/hold together with valid receiver/source
context; completion with real pinned conditions/fulfilment participant; close with all source-family
inventory and race proof; historical correction/dispositions only with real owning effects. No enabled
half and no public J1 sign-off from disabled schemas. J2 remains an optional independent Task C.

## Related

- [Closeout/transactions/verification](phase-5-task-b-j1-closeout.md)
- [Task A receiver](phase-5-r7-job-receiver-design.md) · [SP0a](phase-5-sp0a-design.md)
- [Overview](phase-5-job-execution.md) · [R9 rollout](phase-4-r9-permission-rollout.md)
- [Lifecycle](../domain/job-execution-lifecycle.md) · [Closeout](../domain/job-closeout-reconciliation.md)
