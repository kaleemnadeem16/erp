[← Phase 5 planning](phase-5-job-execution.md)

# Task D — J1-F1 disabled lifecycle database foundation proposal

2026-10-26 · #42 · DESIGN ONLY; proposal for separate owner authorisation, not an implementation
assignment. Read the [physical design](phase-5-task-d-j1-foundation.md) as one contract.
No runtime tests executed by this planning task. J1 completion-only remains independent of J2.

## Smallest reviewable code batch, if separately authorised

Exactly four new Jobs evidence tables and additive structural invariants on existing heads/events.
Models are scoped storage shapes, no public writer/service. No receiver, source adapter, Action,
handler, route, permission vocabulary/registration/grant, UI, API client, demo, Job creation entry,
completion, termination, closeout or labour/cost engine. The schema is disabled by absence of an
entry point, not an activation flag. Structural tests use explicitly isolated fixtures only.

| Proposed owned path (repo relative) | Permitted content |
|---|---|
| backend/database/migrations/2026_10_26_090000_create_job_lifecycle_foundation.php | Four tables; company/same-Job keys; additive event identity columns/triple key; versioned consistency/append-only triggers; preflight; refusal-safe down |
| backend/app/Modules/Jobs/Models/JobAssignmentEvent.php | Company-scoped immutable storage shape/casts/relationships only |
| backend/app/Modules/Jobs/Models/JobHold.php | Same; no resolve/start helper |
| backend/app/Modules/Jobs/Models/JobHoldResolution.php | Same; no mutable resolved_at on hold |
| backend/app/Modules/Jobs/Models/JobOperationReceipt.php | Same; safe references, no response cache |
| backend/app/Modules/Jobs/Models/JobEvent.php | Document identity properties/casts only; no writer |
| backend/tests/Feature/Modules/Jobs/JobLifecycleFoundationTest.php | Raw SQL tenancy/chain/hold/identity/evidence attacks and scoped models |
| backend/tests/Feature/Modules/Jobs/JobLifecycleMigrationTest.php | Empty/populated up/down/reapply, preflight rejection, guard preservation |
| backend/tests/Feature/Modules/Jobs/JobsFoundationTest.php | Adapt only SP0b fixtures/assertions to coherent chains; retain original coverage |
| backend/app/Modules/Jobs/README.md | Exact disabled schema boundary and limitations |
| docs/phases/phase-5-task-d-j1-foundation.md and this file | Later implementation evidence/deviations only if its task authorises doc updates |

No service provider, queue jobs, Sales/Inventory/Purchasing/Tax/Documents code or old migration
changes. No accepted ADR edits. No edits to docs/STATUS.md, docs/STATUS-LOG.md or
docs/phases/README.md; integrator records status. Additional paths require scope review.

## Timestamp reservation and sequencing

Propose reservation **2026_10_26_090000_create_job_lifecycle_foundation** on #42; no file created.
Fresh fetch inspected published develop `d4746d7d`: latest migration is
2026_10_22_140000_create_tax_party_profiles, newer than the issue body's 100000 reference.
All fetched origin branches were inspected; pending Documents #25 includes
2026_10_22_100000_guard_documents_truncate. No fetched migration exceeds the proposed reservation.
Unpushed work cannot be discovered from refs: coordinator must confirm pending owners' reservations
before code assignment; implementer re-fetches/checks immediately before creating the file.
If pending work conflicts or orders later, reserve a new later timestamp and update the proposal
before implementation. This document is not a global lock. Physical prerequisite is integrated
SP0b #28, not a speculative activity table. Task B review/integration remains a design-consumption
gate for the later implementation assignment; the owner exception starts planning only.

## Required implementation checks — not executed here

| ID | Attack / evidence and required result |
|---|---|
| DF01 | Direct cross-company Job/user/event/hold/receipt FK and same-company wrong-Job child → database refusal; models scope reads/writes |
| DF02 | UPDATE/DELETE/TRUNCATE/CASCADE on each new table → refusal; SP0b and Documents guards unchanged |
| DF03 | Missing head event, unpaired head advance, orphan event, stale generation, sequence gap, wrong from_state/increment → refuse COMMIT |
| DF04 | Release fixture plus coherent assignment/hold/resolution/head/event → commit in legal insertion orders; no runtime release authority inferred |
| DF05 | Assignment fork, wrong predecessor Job/sequence, invalid interval, actor/operation mismatch → refuse; inactive-target Action gate remains separate |
| DF06 | Two holds, resolve one → other remains; duplicate/wrong-Job/time-before-placement resolution → refuse |
| DF07 | Unknown operation, held START, unpaired evidence, premature complete/close/termination → refuse direct SQL; no generic head-state bypass |
| DF08 | Duplicate actor/key, changed intent and stable command reuse → immutable uniqueness; company/actor key partitions preserved |
| DF09 | Two PostgreSQL sessions append same generation/predecessor/command or race hold/start → coherent commit or refusal, no fork |
| DF10 | Exception after each child/event/receipt/head step and at deferred-check commit → no partial effects; prove actual shared connection |
| DF11 | Valid populated SP0b preflight succeeds unchanged; malformed history aborts install without DDL residue or fabricated repair |
| DF12 | Empty migrate→rollback→reapply succeeds; any new row/F1 identity makes down refuse before object drops; insertion cannot race check |
| DF13 | Down with pre-existing SP0b evidence and no F1 data preserves all original rows/guards and unrelated functions |
| DF14 | Diff contains no route/duty/grant/Action/receiver/source writer/client/demo; original Jobs coverage retained |

Run focused Jobs regression through ERP_AGENT=codex wrappers/private databases, Pint on touched PHP
and whole-project PHPStan. Run CompanyScopedForeignKeyTest, CompanyScopedUniqueIndexTest,
PermissionCheckRegistrationTest, WriteRouteGuardTest and RecordScopeCoverageTest, plus applicable
additional ratchets. No new route/duty registration expected; assert absence rather than add a skeleton.
Run isolated migrate-cycle and explicit populated refusal/preflight tests. Independent connections
need real commits; a surrounding test transaction proves neither deferred timing nor concurrency.
Use disposable databases, never disable evidence triggers to clean shared data. Inspect EXPLAIN for
company/Job/history queries at expected scale. CI owns full backend/browser regression. No frontend,
API generation or renderer change is needed by this proposed batch.

## Review and activation gates

1. Jobs reviewer agrees schema, operation names, same-Job keys, deferred checks and compatibility/
   down plan. Coordinator confirms reservation and exact file ownership.
2. Security reviewer confirms company isolation and absence of vocabulary/entries. Later guards,
   ALL-scope and real holders remain prerequisites for enabled writers.
3. Owner separately authorises J1-F1 code at reviewed proposal SHA with an implementation owner.
   Design approval alone cannot start implementation.
4. Implementation reports exact SHA, applicable checks and failure ownership. Coordinator integrates
   after exact-commit owner approval. Planner never merges develop/main.
5. Receiver, activities, canonical fulfilment/source fencing remain GATED. Later assign/start,
   completion and source-closeout batches need separate authority and real integration proof.

Rejected shortcuts: mutable responsibility/hold rows lose history; clear-all hides independent holds;
activity strings bypass FKs; head CHECK alone misses event coherence; UUID alone duplicates effects;
silent history repair fabricates facts; source stubs falsely signal capability. No J2 prerequisite.

## Related

- [Foundation design](phase-5-task-d-j1-foundation.md) · [Planning overview](phase-5-job-execution.md)
- [SP0a delivery](phase-5-sp0a-delivery-contract.md) · [Lifecycle](../domain/job-execution-lifecycle.md)
- [Pre-push checks](../architecture/pre-push-checks.md) · [Coordination](../architecture/agent-coordination.md)
