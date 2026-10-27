[← Phase 5 planning](phase-5-job-execution.md)

# Task D — J1 lifecycle foundation design

2026-10-26 · #42 · PROPOSED technical design only. No code, migration, route, duty, grant or
activation. J1-F1 needs separate owner implementation authority. ADRs 0145–0152 remain unchanged.

## Authority, baseline and reuse

Owner expressly authorised #42 while #34 remains under review. The task helper refused the
integration dependency; the planning exception is recorded on #42 without changing #34.
Branch starts at published develop `d4746d7d`. SP0b #28 is integrated: inspected
[Jobs README](../../backend/app/Modules/Jobs/README.md) and
[four-table migration](../../backend/database/migrations/2026_10_22_090000_create_jobs_foundation.php).
They supply service_jobs, job_handover_origins, job_baselines and job_events, tenant keys,
append-only row AND TRUNCATE guards, and populated rollback refusal. No lifecycle engine exists.

Reuse accepted [0145 lifecycle](../domain/job-execution-lifecycle.md),
[0151 closeout](../domain/job-closeout-reconciliation.md),
[SP0a](phase-5-sp0a-design.md) and [Task A](phase-5-r7-job-receiver-design.md).
Task B is a design input at immutable commit
[981ce196](https://github.com/kaleemnadeem16/erp/blob/981ce19608ef83fd9381be35db360fc4866e35e0/docs/phases/phase-5-task-b-j1-execution.md),
with its [closeout companion](https://github.com/kaleemnadeem16/erp/blob/981ce19608ef83fd9381be35db360fc4866e35e0/docs/phases/phase-5-task-b-j1-closeout.md).
Those files are absent from develop; do not assume integration. Existing research answers the
business questions; this refines local mechanics, not business policy.

## Proposed physical boundary

Four new tables only: job_assignment_events, job_holds, job_hold_resolutions,
job_operation_receipts. F1 evidence is explicitly JOB scope, not an invented default activity.
No activity_id, team directory, upstream string IDs, money, labour, completion, termination or
closeout tables. Later receiver/activity integration adds real same-Job activity FKs under separate
authority before activity-scoped commands. Job-level facts cannot assert activity fulfilment.

Every new table has bigint id, company_id NOT NULL, UNIQUE(company_id,id), composite FK to
service_jobs(company_id,id), recorded_at timestamptz and company-first lookup indexes. Evidence
links to events need UNIQUE(company_id,job_id,id) added to job_events and composite triple FKs;
never accept a same-company event belonging to another Job. Actor/assignee references use existing
users(company_id,id), not labels. Hard deletion of referenced people refuses; deactivation retains
history. Later Actions recheck membership/eligibility independently of FK existence.

This is annotated pseudo-DDL, not migration code. Strings are bounded nonblank values; hashes are
lowercase 64-character SHA-256; command identities are UUIDs; times retain actual precision.
Core business facts use columns, not JSON. No cached privileged response payload.

| Table | Proposed columns beyond common keys | Required constraints / indexes |
|---|---|---|
| job_assignment_events | event_id, sequence positive integer, predecessor_id nullable, responsible_user_id, intended_from/until nullable timestamptz, instructions nullable text, actor_id, occurred_at nullable, occurrence_precision, reason | UNIQUE(company,job,sequence); UNIQUE(company,job,id); predecessor triple FK; responsible/actor company FKs; sequence 1 has no predecessor, later sequence has exact previous sequence; intended_until requires intended_from and is >= it; company/job/sequence index |
| job_holds | event_id, scope_kind fixed JOB, reason, effective_from, actor_id | UNIQUE(company,job,id), UNIQUE(company,job,event_id); company/job/id index; same-Job placement event; identity independent of lifecycle state |
| job_hold_resolutions | event_id, hold_id, resolved_effective_at, actor_id, reason | UNIQUE(company,hold_id); triple FK to same-Job hold; UNIQUE(company,job,event_id); resolution time >= placement time; company/job/hold index |
| job_operation_receipts | actor_id, request_key varchar(120), operation varchar(60), intent_hash char(64), result_event_id, contract_version positive integer | UNIQUE(company,actor,request_key); actor company FK; same-Job event FK; company/job/result_event index; immutable safe references only |

Assignment records responsibility. Proposed first target is one active existing company user,
distinct from worker/attester/payroll identity. No worker name is required for work or completion.
Team identity and on-behalf eligibility remain GATED; F1 creates no employee directory. Approval
of this physical candidate does not approve an assignment holder policy. A team adapter must add
typed real FKs, never free-text substitutes. Reassignment/window change appends a successor and
grants no access, start or accepted-scope change. Unassignment and retroactive reassignment are
outside F1; no null assignee shortcut. Historical reports retain their original context.

## Events, command identity and generation

Proposed additive job_events fields: command_identity UUID nullable and semantic_intent_hash char(64)
nullable, present or absent together. UNIQUE(company_id,job_id,command_identity) for non-null IDs;
F1 assignment/hold/resolution/start events require both. Legacy release identity remains receiver
origin. Existing event actor/reason/occurrence/evidence_version remain authoritative; child rows
agree with their event. Receipt actor may differ on authorised fresh-key replay; original child
actor must match the originating event actor. Replay never rewrites original evidence.

Semantic intent includes operation/version, Job, exact target/window/hold, reason/evidence and
expected generations. Transport key and actor are not business-effect identity. Request hash covers
full command and is actor-key partitioned. Same stable command across keys/actors resolves one
event only for identical intent; changed semantic intent conflicts. Deliberate separate holds use
different IDs even with matching text. UUID possession grants nothing. Singleton start also pins
the RELEASED→ACTIVE transition/generation; a fresh UUID cannot start an already-started Job twice.

Proposed hash contract v1: UTF-8 canonical JSON with lexically sorted object keys, explicit nulls
for absent optional fields, integer IDs/generations and canonical lowercase UUIDs. Timestamps use
UTC ISO-8601 with declared occurrence precision retained; no fabricated instant for a date-only
fact. Preserve validated text bytes, array order where meaningful, operation and contract version.
Reject unknown fields before hashing; the same serializer is used for keyed and semantic intent,
with the documented transport/actor exclusions only in semantic intent. No floating-point values.
Changing the serializer requires a new contract version; compare stored version before replay.

Each generation-changing operation appends one event and advances head generation by one. Combined
assign/start needs a separately reviewed compound event/evidence shape and is excluded from F1,
rather than encoded as independent half-successes. Replay adds at most a receipt, no event/head
advance. No circular service_jobs event/baseline pointer is introduced.

Proposed database invariants, enforced at transaction end with deferrable constraint triggers:

1. Initial release: sequence=1, prior_generation=0, next_generation=1, from_state NULL,
   to_state RELEASED. Every persisted head has a coherent latest event. Real executable release
   still requires the future R7 receiver; raw fixture insertion is not authority.
2. Successor: sequence=previous+1, prior_generation=previous next_generation, next_generation=prior+1,
   from_state=previous to_state. Head state/generation equals final event. Unpaired event or head
   advance fails COMMIT. Head company/id/code changes or deletion after evidence refuse.
3. ASSIGN, HOLD and RESOLVE_HOLD retain RELEASED/ACTIVE state with exact child evidence; START is
   RELEASED→ACTIVE with responsibility and no unresolved hold. An unknown operation cannot use
   the state-retaining case to bypass the allowlist. Holds block ordinary mutations; placement
   and named resolution have their own reviewed exceptions.
4. Placement requires its event. Resolution names its own hold and cannot clear another hold.
   A Jobs constraint trigger enforces cross-row time/actor/operation agreement; a cross-table
   CHECK cannot enforce this. Assignment chain is continuous and cannot fork.
5. Completion/withdrawal/termination/close/historical-effect operations refuse under the F1
   allowlist until later evidence/guards extend it. Preserve four-state head CHECK; no
   RELEASED→CLOSED shortcut or delivered termination engine is claimed.

Each assignment/hold/resolution event has exactly one corresponding child, and every child names
that operation's event; a START has none of those children. Receipt operation agrees with its
result event; extra replay receipts do not require extra events. An original command transaction
must include its receipt, but later replay actors may append their own key references to that
result. F1 refuses future-effective hold placement/resolution: use the command's trusted server
time, with historical work reports handled separately. Every unresolved hold blocks ordinary
execution; no client timestamp can move it out of effect. Historical assignment occurrence is
retained only as evidence, never used to backdate a new execution permission.

Triggers lock Job head before inspecting successor/hold/assignment state, including direct SQL
child insertions. Stable head locking plus unique predecessor/sequence/command keys serialises
writers. Validate newly appended edges and final head, not an unindexed full-history scan per
append. Deferred checks allow event/child/head insertion order within a transaction, never a
committed partial chain. Test actual COMMIT timing, not just INSERT.

## Compatibility and safe installation

F1 tightens disabled SP0b; installation cannot assume empty tables. Preflight all existing chains
and relations. Any incompatibility aborts migration transaction with safe table/count diagnostics
and requires a separate reviewed compatibility/backout plan. No fabricated release/start events,
history rewriting, fixture deletion or catch-all legacy bypass. Existing tests use coherent chains
in isolated databases. Valid populated heads can remain if preflight passes; source rows are untouched.

Use versioned job_lifecycle_consistency_v1 and job_lifecycle_evidence_guard_v1 functions with an
explicit trigger inventory. Preserve job_evidence_append_only_guard and Documents functions.
All four new tables refuse UPDATE/DELETE and statement-level TRUNCATE, including CASCADE. Keep
SP0b guards. No permission/role creates an entry. Privileged trigger disabling is outside the
application invariant; this proposal changes no grants.

Down takes a deployment write-exclusion window and locks affected tables before checking rows.
Refuse if any new table has any row (fixtures included), OR an event carries the F1 identity pair.
Otherwise remove only F1-owned objects in reverse FK order. Preserve every SP0b row/guard and
unrelated guard. Refuse before dropping anything, transactionally; an unlocked pre-check is unsafe.
See [batch proposal](phase-5-task-d-j1-f1-proposal.md) for exact paths/checks.

## Later guarded writer protocol — outside F1

Request AND owning Action check current company, each duty/ALL RecordScope and safe record access,
with explicit actor for workers/console. Assignment/role/admin grants nothing. Jobs duties stay
STAGED/unregistered; no skeleton manifest or grants in F1.

Pure-local commands lock Job then same-Job evidence/command anchors in stable IDs. Recheck authority,
inspect actor/key receipt and command effect before NEW-work freshness. Authorised replay projects
original references under current rights; changed operation/intent conflicts. New work checks exact
head/baseline/assignment/hold and applicable source generations, then child/event/receipt/head commit
on one runtime connection/outer transaction. Resolve unique races only after rollback and renewed
authority checks; bounded deadlock retry repeats the whole operation. Failed work writes no receipt.

Source-sensitive commands enter owning coordinator's compatible upstream locks before Jobs. A
missing source fence/writer inventory cannot be replaced by a Job lock. Sales release/hold/fulfilment
and canonical conditions, Inventory0040 and Purchasing0149 readiness/corrections remain GATED.
No absent callback/module or empty query is evidenced N/A. Receiver/default activity/components
remain GATED despite SP0b integration. F1 fixtures do not prove a live Start/Complete/Close path.
J1 completion requires no hours, cost report, job.cost.view or J2. Known obligations still block
unsafe close; optional missing costing never creates a pending timesheet.

## Technical review choices

Approve/revise four-table/Job-level boundary, user-responsibility candidate, event identity and
deferred consistency protocol. Do not reopen accepted lifecycle semantics. GATED before enabled
writers: actual receiver/activity/completion contract, team/on-behalf policy if selected, exact
source lock compatibility, safe duty/holder rollout and independent-session proof. J1-F1 code
needs separate owner authority, implementation owner and confirmed timestamp reservation.

## Related

- [J1-F1 exact batch/verification proposal](phase-5-task-d-j1-f1-proposal.md)
- [Planning overview](phase-5-job-execution.md) · [Task A verification](phase-5-r7-job-receiver-verification.md)
- [Database](../architecture/database.md) · [Tenancy](../architecture/multi-tenancy.md)
- [Domain integrity](../architecture/domain-integrity.md) · [Pre-push checks](../architecture/pre-push-checks.md)
