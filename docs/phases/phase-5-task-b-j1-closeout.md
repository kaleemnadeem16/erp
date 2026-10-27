[← Phase index](README.md)

# Task B — J1 closeout, transaction and verification contract

2026-10-22 · #34 · PROPOSED companion to [basic execution](phase-5-task-b-j1-execution.md).
ADRs 0145/0151 remain authoritative; all implementation/activation is separately gated. No runtime
verification claimed. Reuse accepted research; do not turn J1 into mandatory J2 labour or costing.

## Source-owned closeout and truthful inapplicability

AssessJobCloseout collects a complete supported family inventory, not a list only of installed
callbacks. Each required result carries company/Job/family/component, source contract/policy version,
applicability basis, authoritative source inventory/generation, outcome, safe blocker and private
owning evidence/receipt. Pin exact lifecycle/outcome/baseline and assignment/hold generations too.
Missing callback, denied source, empty query or absent module is UNSUPPORTED_UNKNOWN, not NONE/N/A.

| Owning result | Close meaning |
|---|---|
| RESOLVED | Actual owning obligations/required effects resolved, evidenced, current generation |
| NOT_APPLICABLE_WITH_EVIDENCE | Positive source-owned support/path/writer inventory proves no applicable obligation; no undocumented default |
| SUPPORTED_VALUATION_FOLLOW_UP | Performance/quantity/ownership/operational responsibility resolved; real acknowledged eligible owner and supported correction path; no deferred mandatory effect |
| BLOCKING | Known unfinished work, discrepancy, pending ordinary report, disputed quantity, custody or commitment; actionable safe owning next step |
| UNSUPPORTED_UNKNOWN | Required adapter/verdict/impact or trusted inventory missing; close refuses, never waive by note |

For J1's fixed-service path, operational/accepted-scope fulfilment and remainder need real Jobs+R7
participation. For excluded sources, supported path restrictions and reliable source-owned writer
inventory must positively evidence inapplicability. An ordinary Job checkbox cannot assert no stock,
no supplier or no pending labour. Known outside-path facts are blockers even when UI has no panel.
Inventory/Purchasing adapters are GATED, not blanket global requirements when actual inapplicability
is proved; no new no-op source registry or generic success stub. First enabled close may accept only
RESOLVED/evidenced-N/A until supported valuation follow-up is actually delivered.

Optional absent labour/cost entry or coverage declaration never blocks ordinary supported completion/
closure and never proves zero expense/full coverage. Known ordinary pending reporting/recognition or
unresolved quantities cannot be relabelled optional absence. J1 receives such facts only as safe owning
readiness inputs; no labour tables, recognition, valuation, cost report or expense entry is built here.
No need for job.cost.view to close; use reviewed non-monetary verdict, not cost fields or private hashes.

Valuation follow-up under ADR 0151, when separately supported, pins source/component, missing valuation
fact/reason/basis, eligible accountable owner, actual correction operation, acknowledgement and review
trigger. Reassignment needs successor eligibility/acknowledgement and history; no orphan if owner leaves.
No note/person/date waives unknown impact, disputed quantity, ordinary pending entries or required
atomic Inventory ADR 0040/Purchasing ADR 0149 effect. These source-specific paths remain GATED, not implemented.

## Close command and post-close history

Preflight assessment is advisory; CloseJob takes expected Job and complete source vector/assessment
identity, resolves own current safe access and close authority, then rechecks all relied-on evidence
under proven locks/fences. Eligible outcome: supported COMPLETED, or eligible termination with actual
remainder disposition receipts. Active hold/discrepancy/unknown effect blocks affected close. No general
partial fixed-deliverable close or fake unused quantity. Mandatory disposition/assessment/closeout/event/
head generation/receipt commit together; never close first then attach unresolved evidence.

Original closeout immutable. Later legitimate historical correction appends original lineage and a
resolution case/current discrepancy without reopening execution or rewriting saved evidence. Preserve
occurrence precision/time versus recording time, original assignment/hold context, actor/reviewer and
source-owned effects. Report during hold is truth capture, not retroactive authority/fulfilment/cost
recognition. Explicit review classifies historical error, emergency/safety report, unauthorised work,
legitimate late report, new work or unresolved evidence; classification alone applies no source effect.
Unsupported adjustment stays blocked/visible; no partially applied chain. Closed/terminated new work
requires a separately supported authority, not ordinary restart or correction disguise.

Completion withdrawal: original unclosed completion + current authority/readiness + all supported
dependent reversals/dispositions before ACTIVE. Unknown effect refuses and preserves completion.
Unused-cancel contradiction: retain report/case and involve Sales/R7 before unsafe claim reuse; do not
delete report, confiscate another relied-on allocation or silently mark a cancelled Job executable.
Stop retains actuals and outstanding responsibility; accepted/supplier/stock obligations remain with
owners until supported coordinated disposition. First unsupported correction/disposition operation
refuses; architecture acceptance is not a fake implementation path.

## Locks, transactions and command idempotency

Candidate default connection shared by Job event/head/evidence/receipt and mandatory owning effects.
For any source-sensitive operation, the owning Sales/source coordinator acquires its source fences
FIRST under Task A order, then calls Jobs; Jobs never locks Sales rows or invokes an inverted callback.
Pure local operations lock ServiceJob head, then same-Job activity/assignment/holds and receipt/effect
anchors in stable IDs. Before activation jointly prove this is compatible with Sales/source writers;
a local start still must participate in the actual source-generation/hold fencing it relies upon.
No source scan outside protected generations may authorise a write. No Job creator takes Numbering
counter before Sales locks; Task B client commands create no new Job/code.

Close adds authoritative source inventory/head protection compatible with those writers; if source
writer takes a source fence before Job, close must do the same through owning coordinator. Exact
Inventory/Purchasing/head/Numbering order and independent-session proof are GATED. Transaction-level
snapshot or hash equality alone cannot close the race if a source can change after validation.
All actual/import/worker/correction/hold/withdrawal/source writers must participate. Nested helpers
use same runtime connection/savepoints, no independent commit. Notifications after commit only.

1. Current actor/company/duty/ALL-scope and safe-record access; validate key/typed intent. Lock canonical
   subject/command anchors, recheck authority, then find receipt before mutable freshness checks.
2. Receipt UNIQUE(company,actor,request_key), immutable operation+canonical exact intent hash including
   expected generations/values/reason/evidence. Same key/hash returns original permitted references;
   changed operation/intent conflicts. Reproject under current field access; never cached privileged blob.
3. Fresh key for same stable effect identity and identical semantic intent returns existing result when
   currently authorised. Different actor/key cannot duplicate start/completion/close/disposition. Do not
   equate similar text with identical intent: two legitimate holds remain separate facts.
4. For NEW effect, compare expected generations, lifecycle+holds/termination, assignment, pinned rule,
   readiness/source inventory and configured approvals. Missing/stale/unknown refuses without effects.
5. Append evidence/event/receipt and update head generation atomically; DB enforces next_generation
   uniqueness and legal event/state/generation relation. Unique race/deadlock retries entire outer
   transaction after rollback, bounded, with fresh authority checks; no query on aborted transaction.

Stable command_identity on holds/assignments binds exact operation intent across request keys; hold
resolution targets an existing hold. New intentional hold has a distinct identity even with same reason.
Start/completion effect identity additionally pins expected transition/execution cycle/baseline; withdrawal
allows only a new explicitly supported completion cycle, not duplicate old evidence. Close original
identity unique company/Job. Business-effect anchor uniqueness and event generation are required;
client UUID alone cannot bypass state, scope, source or capacity checks. Exact serialization/length and
concurrency protocol need Jobs implementation review, not a second global receipt service.

## Dependencies — ALL source delivery gates explicit

| Owner / tracking | GATED delivery and limits |
|---|---|
| Sales/R7 agent | GATED authoritative accepted SERVICE_ITEM, release/receiver verifier, pinned completion conditions, source generations/locks, fulfilment/withdrawal/remainder/hold impacts. #32 integrated DESIGN is not these writers; QL1a integrated does not establish acceptance |
| Jobs implementation agent, #28 | GATED integration/review of real ServiceJob/event/baseline schema and validator. Currently In Review on agent-b branch `73bda44`, absent baseline `5525293`. Do not assume prior assignment to agent-a or touch its files |
| Jobs receiver integration | GATED real origin Sales FKs, stage/purpose uniqueness, default activity and components/conditions from Task A; no test fixture origin can become executable |
| Inventory ADR 0040 | GATED applicability/readiness and consumed-cost correction participant/owning receipt before ANY affected material path; existing stock operations not proof; no scalar stock bypass |
| Purchasing ADR 0149 | GATED supplier-service acceptance, portions/commitment cancel/short-close/correction and safe readiness before affected source; Job stop does not cancel supplier obligation |
| Security/integration coordinator | GATED independent per-duty ALL-scope/access, safe source verdicts, actual holder inventory, connection/lock review and staging sign-off; no role/admin inference |
| Source labour/external/Finance | GATED safe known-obligation input or evidenced inapplicability; no J2 tables/costing/AP/payroll/GL implementation; full Finance not universal J1 prerequisite |
| Documents | Existing DF0/DF1a do not prove source-specific attachment access/Job snapshot adapter; formal output optional; no mandatory Job PDF |

Each source-specific gate affects that path, not a requirement to implement every module before
completion-only J1. Actual excluded-path inventory and writer restrictions must be proved jointly;
never infer N/A from scope statements alone. Coordinator assigns owners and task dependencies by exact
contracts/commits/checks at implementation. No source contract/ADR edited. Missing-contract conflicts
are reported via tasks.sh report for owning investigation, never patched in another owner's files.

## Acceptance/failure requirements — not executed tests

| ID | Scenario → required result |
|---|---|
| JB01 | Genuine release missing / fixture-only origin → no executable Job; neither QL1a nor SP0b substitutes for R7 |
| JB02 | Assign employee/team then read/start without rights → assignment grants nothing; explicit current duties/ALL-scope enforced in Request and Action |
| JB03 | Combined assign/start lacks either duty/readiness → neither commits; separate assign alone leaves RELEASED |
| JB04 | Start/hold race → serial outcome or stale refusal, never ordinary work after effective hold; resolving one of two holds leaves other blocking |
| JB05 | Inactive/cross-company assignee or invalid interval/direct child FK → refuse; prior responsibility/history retained |
| JB06 | Attestation missing canonical completion conditions or altered baseline → refuse; fulfilled fixed deliverable needs no hours/named worker/cost/percentage/PDF |
| JB07 | Completion versus actual/hold/source change → consistent evidence/generation or refusal; no false fulfilment from activity duplication |
| JB08 | No optional labour/cost/declaration → eligible J1 completes/closes; no fabricated pending timesheet, cost fields or zero/full-coverage claim |
| JB09 | Known pending ordinary report, disputed quantity, custody/supplier/remainder or unknown required impact → close blocks; empty callback registry not N/A |
| JB10 | Preflight passes then source correction/recognition/hold changes → close revalidates/fences all sources or refuses stale; independent DB sessions prove race |
| JB11 | Throw after close evidence or owning disposition write → Job/head/evidence/receipt/all mandatory effects roll back together on same connection |
| JB12 | Same/fresh keys/cross actors retry start/complete/close → original authorised effect or conflict, not duplicate; two intentional distinct holds not collapsed |
| JB13 | Receipt replay after revoked/limited/company change → current denial/absent foreign key; safe identity only, no private IDs/cost/hash/history leakage |
| JB14 | Legitimate late/held report → truthful occurrence/recording/context classification, no automatic recognition/fulfilment/start; unknown time not fabricated |
| JB15 | Completion withdrawal unsafe / closed restart / new-work-as-correction → refuse, preserve original completion/closeout and actionable case |
| JB16 | Cancel-unused contradicted by late work → visible Sales reconciliation blocks unsafe reuse; stop retains actuals/owned remainder; failed successor leaves old obligation |
| JB17 | Valuation-only follow-up lacks actual eligible acknowledged owner/correction path → close refuses that route; no waiver or orphan reassignment |
| JB18 | Raw SQL evidence edit/delete, illegal event/head generation/state, duplicate receipt/effect, cross-company/same-Job child mismatch → DB refusal; evidence-safe down refuses populated tables |
| JB19 | Close-only actor lacks job.view/job.cost.view → reviewed safe own-operation verdict succeeds when eligible, no broad worksheet/cost/source attachment access |
| JB20 | Integrated UI journey assign/start/hold/attest/close plus stale/denied/retry/error/closed states, phone/light/dark/keyboard → no optimistic success or client state bypass |

Later implementation runs focused/module regression and applicable tenant/scope/permission/write/read/
Action-boundary ratchets, Pint/Larastan, isolated migration cycle, API/client drift and frontend checks
for actual surfaces. Feature agents do not run full backend suite; CI/coordinator owns full regression.
#14 worktree browser limitation requires coordinator's supported integrated browser evidence. Test files,
mock source verdicts or this docs link check never establish VERIFIED/PUBLICLY ENABLED capability.

## Choices for owner/reviewer and next handoff

Preserve accepted states, fixed-deliverable default, J1/J2 separation, independent safe close duty and
fail-closed correction/readiness. Proposed mechanics: append-only responsibility/hold/attestation/
closeout evidence, local per-command receipts, current-authorised replay, all-source commit fencing,
operational worksheet with no cost fields and a narrow disabled batch in companion.
Genuine GATED selections: supported member/team assignment and on-behalf context, canonical Sales
completion evidence/version, exact physical Job/source/event keys, safe non-monetary source verdict
profile, real lock/generation protocol, individual holder recipe and first supported termination/
withdrawal/follow-up paths. No silent schema/permission/route/activation approval; coordinator/owner
reviews design first and separately assigns implementation. J2 and source monetisation stay Task C.

## Related

- [Basic execution design](phase-5-task-b-j1-execution.md) · [Overview](phase-5-job-execution.md)
- [Task A](phase-5-r7-job-receiver-design.md) · [Receiver verification](phase-5-r7-job-receiver-verification.md)
- [Closeout0151](../domain/job-closeout-reconciliation.md) · [Lifecycle0145](../domain/job-execution-lifecycle.md)
- [R9 rollout](phase-4-r9-permission-rollout.md) · [Pre-push checks](../architecture/pre-push-checks.md)
