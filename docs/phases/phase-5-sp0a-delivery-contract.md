[← Phase index](README.md)

# SP0a — transaction, API and verification proposals

2026-10-21 · Task #19 · PROPOSED companion to the [physical/interface/security design](phase-5-sp0a-design.md).
No application implementation or runtime verification. Technical candidates need owner/reviewer approval.

## Transactions, locks and retries

Sales release, Job/baseline/activity/event and both receipts share one PostgreSQL runtime connection
and outer transaction. Receiver refuses missing/mismatched transaction. Nested transactions must be
savepoints on that connection; no independent commit, queue-only target or external mandatory effect.
Integration tests assert runtime connection identity and rollback after actual target insertion.

Candidate R7 order: company Sales coordination fence → accepted/claim roots (sorted IDs) → allocations
→ stages → Job heads (sorted IDs) → source-specific heads → receipts. Protect absent origin with
stage/root fence plus unique company/stage/purpose. Actual CA/HA fence names are an upstream design
seam, not existing infrastructure. Every release/cancel/reduction/fulfilment/hold/close/source writer
must agree. A Job command needing Sales coordination locks that root before Job; no inverted callback.
Do not invent a global lock service. Read-only preflight authorises no commit.

1. Validate typed intent and current company/duty/read/scope. Canonical hash includes operation,
   contract version, stable references, exact values and expected generations, not transport time.
2. Under canonical locks recheck authority, then actor/key receipt. Same hash replays safe original
   references; changed hash conflicts. Business anchor replay prevents fresh-key/cross-actor duplicates.
3. For a new effect recheck acceptance/capacity, expected generations, lifecycle/holds/termination,
   approval and source inventory. Unknown or unsupported refuses.
4. Apply required owning effects/head generations/evidence/receipts atomically. Unique races resolve
   to authorised replay or conflict, not raw SQL errors. Failure rolls back every effect.
5. Render current permitted Resource; after-commit notifications are non-authoritative.

Each source writer changes its protected generation atomically. Close locks source heads only if
that order agrees with source writers. If sources require earlier roots, joint review must move
those roots ahead of Job for ALL writers. This unresolved proof blocks activation, not independent
design. A vector/hash check without a fence covering writers is insufficient.

Close revalidates assessment vector/outcome/holds/known reports/dispositions/follow-ups and records
immutable assessment/closeout/event/receipt with CLOSED atomically. Close/source correction, start/hold,
completion/actual, cancellation/late report and release/reduction races serialise or refuse stale
intent. Bounded deadlock retry restarts the whole transaction and authority checks; never retry only
one effect. Post-close corrections append source/resolution lineage and restate current views while
original closeout remains. No new execution or ordinary reopening.

## Migration candidates and rollback

No migration files produced. Later proposed additive order:

| Batch | Shape and gate |
|---|---|
| SP0b | Local origin/Job/baseline/components/activity/event/receipt; deferred head constraints and append-only/unique-anchor guards; production creation disabled |
| Receiver integration | Real CA/HA composite FK bindings and supported adapter after actual upstream tables/contracts; same-connection release proof |
| Lifecycle | Assignment/holds/outcomes/remainder/resolution evidence before respective writers |
| Closeout | Source generation participation, readiness/closeout evidence before any close Action |
| J2 | Source-owned labour/recognition/corrections/attribution/coverage; FinancialActivity, precision and dual-money constraints |

No old migration edits. Down removes genuinely unused disabled structures in reverse dependency
order; with evidence or dependants it refuses clearly instead of deleting history. Isolated fresh,
rollback and reapply tests include populated evidence and raw constraint attacks. No historical Job
backfill from draft quotation or invented consent. Later imports need approved reconciliation.
Coordinator reserves migration ordering at implementation, not in this planning package.

## API and frontend candidates

Later module routes under api/v1 through its provider. No public POST /jobs creation: real Sales
release is the sole supported entry. Candidate GET /jobs, /jobs/{id}, /worksheet, /history,
/cost-summary and independently authorised receipt lookup. Lists/history paginate with scoped counts.
No generic PATCH state. Candidate POST suffixes: /assignments, /start, /assign-and-start, /holds,
/holds/{hold}/resolve, /completion, /completion-withdrawal, /cancel-unused, /stop,
/closeout-assessments, /close, /historical-reports. All suffixes follow /jobs/{id}.
Source recognition/corrections remain source-owned endpoints, not generic Job money updates.

Requests carry operation key, expected Job/source generations, relied-on assessment fingerprint,
reason/evidence references and occurrence precision/time. Recording time is server-observed.
Use existing idempotency transport convention, not competing header/body keys. Resources expose safe
IDs/generations, effective state/holds/termination, permitted actions and receipt reference. Decimal
quantities/money stay strings; no client commercial recalculation.

Candidate refusal meanings: STALE_JOB_GENERATION, STALE_SOURCE_READINESS, UNSUPPORTED_JOB_SOURCE,
JOB_HELD, JOB_TERMINATED, JOB_CLOSED, JOB_OUTCOME_EVIDENCE_REQUIRED, UNKNOWN_DOWNSTREAM_IMPACT,
UNSAFE_REMAINDER_DISPOSITION, OPERATION_INTENT_CONFLICT. Implementation checks collisions and maps
validation/denial/conflict/missing resources through existing API conventions. No private source IDs,
costs or existence leaked in errors; forbidden source does not become an absent obligation.

Deliver guarded Requests+Actions, Resources/OpenAPI/client together when API work is authorised.
SP0b has no client change. UI uses shared shell/sheets/tokens, server allowed actions and loading,
empty/error/stale/denied/held/terminated/closed/unsupported states. Completion and closeout distinct;
no compulsory labour panel. Confirm effects/remainder; support keyboard, phone no overflow and
light/dark. Company/scope change clears cached cost/detail/receipt data. J1 needs no formal PDF;
formal output later uses Documents0137 with independent access/snapshot/retention controls.

## Integration owners and upstream evidence

| Proposed accountability | Boundary / dependency |
|---|---|
| Sales acceptance/R7 | Actual CA source, cap/stage/fence/generations/baseline/release/impact receipts and supported completion semantics; #10 only QL1a, not a CA/HA assignment |
| Jobs backend/frontend | Receiver/schema/Actions/Resources/worksheet/closeout; no source/Sales table writes |
| Security/RecordScope review | Per-duty scopes, non-HTTP guards, safe readiness, sensitive projections and real holder rollout |
| Coordinator/integrator | Assign tasks, review interfaces and sequence providers/migrations/client; exact-commit integration/CI |
| Labour/source/costing | J2 recognition/attribution/correction/coverage invalidation/source generations; no double posting |
| Inventory/Purchasing |0040 consumed-cost participant and0149 service acceptance/commitment/correction before affected paths; ordinary stock/goods receipt is insufficient |
| Documents | #11 DF0 contracts first; actual source/Job bindings later; DF0 alone cannot render or prove acceptance |
| Pricing/tax | #12 gated; eligible commercial source needs actual supported financial/tax/control evidence before issue/acceptance |

Final register checkpoint: #10 is blocked on #21 test-isolation investigation; #11 is approved but
still In Review, not Integrated. Follow-on #22 DF1a, #23 Company branding and #24 Sales binding
are gated tasks, not delivered adapters. In particular #24, not DF0, tracks the Sales snapshot/binding
seam relevant to a quotation-backed source. None of these changes the accepted planning baseline.

Roles are recommendations, not assignments. #10/#11/#12 are tracking references, not delivered
prerequisites. No blocker on their current bounded scopes is asserted. Future integration tasks name
actual required commits/checks; avoid whole-workstream dependencies where one supported contract
suffices. Conflicting interface needs go through tasks.sh report, not Phase 4 contract rewrites.

## Acceptance and failure scenarios — requirements, not executed tests

| ID | Required implementation evidence |
|---|---|
| V01 | Cross-company Job/origin/baseline/assignment/evidence/receipt direct writes fail; inactive/cross-company assignee refused |
| V02 | Raw evidence UPDATE/DELETE, invalid state, head/event/generation mismatch and duplicate stage/purpose fail; valid transactional head cycle succeeds |
| V03 | Real CA→R7→Job insertion then injected exception leaves no allocation/target/baseline/activity/event/receipt; same runtime connection, not mock-only proof |
| V04 | Independent DB sessions release same stage with same/fresh keys/different actors: one target, conserved cap; changed key/business intent conflicts |
| V05 | Revoked/limited actor's receipt replay refuses or redacts; unrelated unrestricted duty never widens restricted operation |
| V06 | Hold/start race and two-hold resolution safe; terminated ACTIVE blocked across reads/writes; combined assign/start missing either duty commits neither |
| V07 | Supported fulfilment completes without hours/percent inference; unsafe withdrawal retains completion and actionable resolution |
| V08 | J1 no labour/cost/declaration: eligible completion/close succeeds without fabricated entries; no recorded costs/Unassessed persists |
| V09 | Missing readiness adapter, unknown custody, supplier obligation, disputed quantity or ordinary pending report blocks close; empty registry is not N/A |
| V10 | Close/source/hold races yield coherent vector/outcome or stale refusal; throw after close evidence rolls back all effects |
| V11 | Cancel-unused with actual/unknown impact refuses; late contradiction opens R7 reconciliation; failed successor preserves old remainder |
| V12 | Closed/terminated restart and new-work-as-correction refuse; legitimate historical path preserves context and original closeout |
| V13 | Cost-blind close sees only reviewed safe readiness; histories/counts/receipts/errors/exports/files do not leak costs/rates/private identities/hashes |
| V14 | J2 record alone cannot recognise; combined duties enforced; correction/attribution/coverage invalidation atomic; valued rows never imply full coverage |
| V15 | Fresh→rollback→reapply and populated rollback prove evidence safety; raw SQL/security/scope/permission ratchets cover new writers |
| V16 | Real J1 browser journey plus stale/denied/held/closed/retry failures, phone/light/dark; J2 separately; fake acceptance tests are not integrated proof |

Implementation runs focused/module regression, applicable ratchets, Pint/Larastan, isolated migration
cycle, API/client drift and frontend typechecks/build/unit/browser per changed surfaces. CI/coordinator
handles full regression; feature agents do not run full backend suite. Worktree browser limitation #14
requires coordinator-supported integrated browser evidence, not waiver. Record exact commits, actual
results/failure owner and configuration/holder/staging sign-off before VERIFIED/PUBLICLY ENABLED.
No production seed, grant or test in this planning work.

## Review outcome required

Approve/revise technical candidates and smallest SP0b scope in companion. Preserve J1/J2 separation
and accepted R7 service direction without asking for those decisions again. Remaining approvals:
exact service adapter/completion basis; physical/duty/scope choices; accountable implementation owners;
first separate code batch. Actual shared-lock compatibility remains a delivery proof once source
writers exist. No accepted business architecture change or new ADR asserted merely for proposals.

## Related

- [Physical/interface/security design](phase-5-sp0a-design.md) · [Overview](phase-5-job-execution.md)
- [Domain integrity](../architecture/domain-integrity.md) · [API conventions](../architecture/api-conventions.md)
- [Authorisation](../architecture/authorization.md) · [Pre-push checks](../architecture/pre-push-checks.md)
- [R7 slices](phase-4-r7-implementation-slices.md) · [Costing](../domain/job-unified-costing.md)
- [Materials](../domain/job-material-attribution.md) · [External](../domain/job-external-cost-attribution.md)
