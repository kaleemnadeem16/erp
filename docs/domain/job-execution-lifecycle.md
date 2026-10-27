[← Domain index](README.md)

# Job execution lifecycle

**2026-10-15 · Architecture ACCEPTED0145; bounded contract SPECIFIED. Job/receiver not
IMPLEMENTED or VERIFIED.** No implementation, grants or enablement authorised.
Labour identity/rates/recognition are accepted0146 and [specified separately](job-labour-recognition.md); exact coverage declaration remains proposed Q52. No universal named worker or timesheet requirement.

## Where it sits

Acceptance → R7 preparation/allocation → release/Job baseline → assignment/start → actual
work/fulfilment evidence → closeout. Straightforward trading need not create a Job.
Supported disjoint Jobs share accepted capacity once; stages do not multiply it.
Unsupported technical/setup splits and cross-acceptance consolidation remain deferred.

## Logical responsibilities — no migrations or final schema

| Record/fact | Responsibility |
|---|---|
| R7 preparation/stage | Proposed allocation/baseline/readiness; target absent until successful creation |
| Job | Company, owning stage/allocation lineage, lifecycle head/generation, initial/current baseline |
| Activity | Actionable portion; one default internally in SIMPLE; not a new commercial claim |
| Schedule/assignment | Intended time/responsibility with history; no permission or execution inference |
| Hold | Independent reason, effective interval, actor, resolution and generation |
| Termination | CANCELLED_UNUSED or STOPPED, reason, actual/remainder and owning disposition |
| Execution/completion | Occurrence/recording times, worker/activity/source and validated obligation evidence |
| Closeout | Reconciliation, remainder destinations, explicit valuation obligations/owner/path |
| Historical/source-cost adjustment | Original source and correction lineage/authority/impacts; no restart |

Use tenant-safe references, unique identities, append-only evidence and versioned mutable heads.
Reuse existing money/quantity/currency/FX invariants and precision contracts; no new timesheet
scale is chosen here. New monetary owners must register FinancialActivity.

## Effective state and transitions

Authoritative states: RELEASED, ACTIVE, COMPLETED, CLOSED. Preparation has no successful Job.
PLANNED is scheduling/requirements; assignment is responsibility, not access. Progress estimates
are not fulfilment. Cost completeness/recognition is independent from lifecycle and Finance.

Holds and termination are independent blocking facts. All active holds remain effective.
CANCELLED_UNUSED/STOPPED preserve the prior lifecycle stage until closeout; the final outcome
remains completed/cancelled-unused/stopped with truthful fulfilled quantities. Every UI/API/
selector/print/export/command considers the effective combination, not ACTIVE alone.

All permitted rows require current authority/company/scope, support and generations.

| Operation | Before → after | Conditions / refusals |
|---|---|---|
| Prepare | no Job → R7 preparation | No target, stock/GL, work authority or reservation promise |
| Release | preparation → RELEASED | Current acceptance/caps/readiness/baseline/configured approval; real owning receiver |
| Assign/reschedule | RELEASED/ACTIVE unchanged | No termination; hold restrictions; revalidate materially changed relied-on facts |
| Start | RELEASED → ACTIVE | Valid assignment/execution authority/readiness; no hold/termination |
| Ordinary actual/progress | ACTIVE unchanged | Valid execution context; no new ordinary work during hold/termination |
| Hold | RELEASED/ACTIVE + hold | Authority/reason; never erases progress |
| Resolve hold | lifecycle unchanged | Current prerequisites; resolves only named hold, never starts work |
| Complete | ACTIVE → COMPLETED | Required fulfilment evidence; no blocking hold/termination or unresolved discrepancy |
| Withdraw completion | COMPLETED → ACTIVE | Unclosed/unterminated; authority, original evidence, readiness and dependent reconciliation |
| Cancel unused | RELEASED + CANCELLED_UNUSED | Proven unused execution and safe downstream effects; eligible disposition atomic |
| Stop | ACTIVE + STOPPED | Preserve actuals/accountable remainder; no implicit freed capacity |
| Close | completed/terminated → CLOSED | Operational reconciliation and supported remainder dispositions; no unknown impact |
| Historical adjustment | lifecycle unchanged | Controlled past-evidence/cost path, never new work authority |
| Ordinary restart | CLOSED/terminated → refused | Deferred |

Combined assign/start commits both or neither, checking distinct operations/permissions.
Assignment grants no record/cost read. Missing past start does not justify fabricating one:
retain exceptional evidence for review. Completion withdrawal changes only erroneous unclosed
completion, not scope. Unsafe dependent effects refuse and surface resolution evidence.

## Historical, late and hold evidence

- Legitimate late work: current recording authority plus valid historical execution/assignment
  or authorised on-behalf context; preserve occurrence and recording time separately.
- Correction: append linked original/reason/actor evidence; never silently edit posted actuals.
- Hold work report: capture truth, not authority, recognition or fulfilment. Preserve historical
  assignment, hold, actor/reviewer and both times. Explicit review distinguishes historical-record
  error, emergency/safety circumstances, unauthorised work or unresolved report. Classification
  does not itself determine cost/commercial effects; owning domains apply supported handling.
- No initial general hold-bypass execution permission. Held/terminated/completed/closed records
  cannot receive new execution disguised as late entry.
- Late evidence contradicting unused cancellation opens reconciliation, blocks unsafe capacity
  reuse and remains visible; do not discard it to preserve the cancellation label.
- Closed historical discrepancy creates a resolution case, not ordinary restart.

Bounded labour recognition/correction semantics are now [specified under0146](job-labour-recognition.md); exact coverage declaration protocol remains Q52 research.

## Baselines, remainder and closeout

Keep issued quote, agreement, original operational baseline, authorised successors and actuals
distinct. Routine slots/assignment remain lightweight inside contractual/cap/readiness limits.
Material operational changes version baseline evidence and revalidate relied-on approvals.
Overrun/rework are not automatically additional commercial scope. No general repricing/increase.

Stopped Jobs retain operational responsibility until supported disposition. Sales retains the
commercial obligation. Internal reassignment changes responsibility only. A successor requires
coordinated retirement of eligible predecessor remainder and new linkage under the same cap;
completed quantities, setup and family lineage remain conserved. Failure leaves old responsibility
intact. Unused estimated hours never free capacity. Unknown effects refuse unsafe disposition.

Pending work remains explicitly owned; a supported successor or Sales-agreed reduction may resolve
it. Only completed supported disposition permits final closeout of that remainder.

Closeout reconciles fulfilment/remainder, required known actual quantities/records, holds,
exceptional reports, commitments and owning impacts. Disputed quantities, unresolved ordinary
entries or unknown downstream effects block. Incomplete valuation is permitted only with explicit
missing sources/reasons, accountable owner and supported follow-up/correction path. Every projection
retains incomplete status; unknown is not zero or final cost. Closed implies no invoicing/payment/
revenue recognition/accounting settlement. Later source adjustments append, retain lineage and do
not restart execution; original closeout evidence remains historical.

## Ownership and permission boundaries

| Operation | Owner / authority |
|---|---|
| Prepare/release | Sales R7 coordinator + owning Job Action; accepted sales.handover.prepare/release |
| Hold/schedule/unused cancellation/reassignment | sales.handover.hold/amend where applicable + owning target checks |
| Assign/start/actual/progress/complete/close | Job owning Actions; distinct operation eligibility, final duty strings/holders not approved here |
| Exceptional review/completion withdrawal/historical correction | Sensitive owning-operation authority + independent legitimate evidence/record read |
| Commercial correction/claim | Sales0132/R9 only; target cancellation is not commercial release |
| Stock/Purchasing/Finance/files | Owning adapter and independent authority |

Reuse RecordScope and Request/Action guards. Assignment, Party links and role names confer nothing.
Protect cost/rates, approval evidence, files and nested/history/count/export/receipt/worker projections.
Unsupported limited scope refuses across all surfaces. Reuse configured Approvals; no universal
maker/checker, exact new permission registration or holder grant is authorised here.

## Integrity and refusals

R7 business/stage identity, not UUID alone, determines target duplication. Currently authorised
exact replay/fresh-key same-intent replay returns original permitted result; changed keyed intent
conflicts. Every retryable mutation/correction needs receipts and stable effect identity.

Lock Sales coordination/roots/allocations → stage/Job → owning downstream state in a compatible,
verified order. Protect absent anchors with coordinator/unique identities. Under locks recheck
authority, caps, lifecycle plus holds/termination, generations/readiness/approvals and impacts.
All conflicting writers, including imports/workers/start/hold/close/actuals, must participate.

Required local Job/activity/baseline/allocation/receipt effects share outer transaction and verified
runtime connection; no early commit. Missing receiver is UNSUPPORTED, unknown is not NONE. Atomic
groups roll back all required effects; independent components report individually. Queue dispatch
is not durable target receipt. Source recognition/correction and attribution must not duplicate;
their bounded labour contract is specified under0146, with actual adapters still undelivered. Stable refusal meanings include stale, held,
terminated/closed, unsupported, missing authority/approval, unsafe disposition and unknown impact;
final reason_code names are implementation-owned after collision checks.

## Acceptance scenarios — requirements, not executed tests

| ID | Scenario → required result |
|---|---|
| JL01 | Prepare without team → pending plan, no Job; acceptance valid |
| JL02 | Throw after real target insertion → no target/baseline/activity/allocation/receipt survives |
| JL03 | Same/fresh-key release across actors → one target; changed intent conflicts |
| JL04 | Concurrent branches exceed cap → excess refuses; stage chain counts once |
| JL05 | Assign without start → no execution; combined action missing duty commits neither |
| JL06 | Hold/start race → serialised outcome; no ordinary execution after effective hold |
| JL07 | Resolve one of two holds → other blocks; ACTIVE+STOPPED always blocked/displayed |
| JL08 | Late pre-hold work → truthful times; hold report grants no recognition/fulfilment |
| JL09 | Activity roll-up → cost counted once; hours/percent alone cannot complete |
| JL10 | Cancel with actuals/unknown effects → refuse; late contradiction opens reconciliation |
| JL11 | Successor failure → old responsibility intact; success conserves remainder |
| JL12 | Unsafe completion withdrawal → refuse, retain evidence/resolution case |
| JL13 | Close unresolved work/ordinary entry/disputed quantity → refuse |
| JL14 | Close incomplete valuation → quantities reconciled + sources/reasons/owner/supported follow-up |
| JL15 | Later authorised source adjustment → append lineage/certainty change, no restart |
| JL16 | Assigned actor lacks record/cost scope → no access expansion or indirect disclosure |
| JL17 | Closed/terminated ordinary restart → refuse; historical correction remains controlled |

## Dependencies and reconciliation

No conflict identified with R7/R9: R7 managed Order states are not Job states. CA source,
HA0–2/service HA3b, actual guards/grants, completion/impact ports and real verification gate
activation. This contract delivers no adapter. Preserve historical Job Costing with its explicit
replaced assumptions. Materials/package prerequisite discrepancy, Q24,0040 correction linkage and
0137 shared Documents remain affected-path gates. No scalar bypass, fabricated reservation/zero/
NONE, duplicate ledger or independent renderer. Imports need truthful lineage, never invented consent.

## Related

- [Task F Jobs duty/holder activation plan](../phases/phase-5-task-f-jobs-access-plan.md) — proposed access catalogue and separately authorised disabled batch.
- [Labour recognition0146](job-labour-recognition.md)
- [0145](../decisions/0145-separate-job-lifecycle-execution-and-operational-closeout.md)
- [Phase 5](../phases/phase-5-job-execution.md) · [Labour research](../phases/phase-5-labour-resource-research.md)
- [Historical Job Costing](job-costing.md) · [R7](sales-operational-handover.md) · [R9](sales-duty-matrix.md)
- [Stock corrections](stock-recalculation.md) · [Documents](documents.md) · [Precision](precision.md)
