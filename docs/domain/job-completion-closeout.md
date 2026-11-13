# Jobs — completion and closeout (Phase 5, internal and disabled)

[← Domain index](README.md) · [Receiver](job-receiver.md) · [Lifecycle](job-execution-lifecycle.md) · [Closeout and reconciliation](job-closeout-reconciliation.md) · [Source safety](job-source-safety.md) · #69

Internal Actions that take a started Job to COMPLETED and then CLOSED. **No route, no registered permission, no ACTIVE duty, no grant.** `job.complete` and `job.close` are SPECIFIED, so every
caller is refused today; the tests prove the logic with a fixture-activated manifest only. No labour, timesheet, material, cost, signature or PDF is involved, and nothing here settles an
invoice, a balance or accepted capacity.

## Completion — `CompleteJob`

`CompleteJob::execute(User $actor, int $jobId, int $expectedGeneration, string $requestKey, string $commandIdentity, string $statement, ?Carbon $occurredAt = null, string $occurrencePrecision = 'NONE', array $expected = [])`

Needs `job.complete` (its own ALL scope), a **started (ACTIVE)** Job, **no unresolved hold**, a **current verified source context** (the Sales verifier re-proves the no-obligation applicability
and the pinned conditions; an unsupported origin or an unready source refuses with `NOT_READY_TO_COMPLETE`) and a statement of 1–1000 characters. In one transaction it appends the COMPLETE
event (ACTIVE → COMPLETED), the immutable `job_completions` row, the head advance and the actor's receipt.

The completion records **who** (`completed_by`), **when** (the claimed time and the database's recorded time), the **statement** (equal to the event's reason) and the **original customer-agreed
work** it attests: the Job's latest baseline and its fingerprint, the Sales release event, and the agreed-conditions identity of the baseline component. There is **one completion per Job**.

## Closeout — `AssessJobCloseout` then `CloseJob`

`AssessJobCloseout::execute(User $actor, int $jobId, int $expectedGeneration, string $requestKey): JobCloseoutOutcome` (needs `job.close`) appends an immutable `job_closeout_assessments` row —
**READY**, or **BLOCKED** with stable codes — and never changes the Job. Blockers (`JobCloseoutBlockers`): `JOB_NOT_COMPLETED`, `COMPLETION_MISSING`, `OPEN_HOLDS`, `SOURCE_NOT_READY` (the source
cannot prove its obligations, including a mandatory control whose adapter is missing — never a "no impact" pass), `SOURCE_CONDITIONS_CHANGED`. It is idempotent per actor and request key.

`CloseJob::execute(User $actor, int $jobId, int $expectedGeneration, int $assessmentId, string $requestKey, string $commandIdentity, ?string $statement = null, ...)` (needs `job.close`) needs a
**COMPLETED** Job and a **READY assessment of exactly the generation being closed**; under the Job lock it re-runs the same blocker rules, so an assessment is never trusted past the moment it was
made (`CLOSEOUT_ASSESSMENT_REQUIRED`, `CLOSEOUT_BLOCKED`). It appends the CLOSE event (COMPLETED → CLOSED), the immutable `job_closeouts` row, the head advance and the receipt. One closeout per Job.

## Order, replay and authority

Inherited from `JobLifecycleOperation`: authority → the Job (one non-disclosing `JOB_NOT_FOUND`) → inside the transaction the **source verifier is entered first** (source fences before the Job lock)
→ **authority again, under the fences** → replay of the actor's key or the stable command → the Job head lock → replay again → expected generation / version vector → preconditions → writes. The
second authority check closes a gap found while testing this slice: a command that WAITED on the source fences used to miss a revocation or suspension that committed meanwhile; it now refuses,
replays included (this applies to every lifecycle command, not only completion and closeout). The assessment follows the same order.

## Database

Migration `2026_12_14_100000_create_job_completion_closeout.php` is additive. It patches two installed lifecycle functions in place (the operation allowlist gains COMPLETE and CLOSE; the state rule
gains ACTIVE → COMPLETED and COMPLETED → CLOSED), asserting each fragment exists exactly once and refusing otherwise; every other rule is untouched, so a hold, assignment or start on a completed Job
is still refused. New tables are append-only (row and TRUNCATE guards), company/same-Job composite FKs, one completion and one closeout per Job. **COMMIT-time completeness** (deferred triggers): a
COMPLETE never commits without its completion row agreeing with the event and the original agreed work and with no unresolved hold; a CLOSE never without its closeout row and a READY assessment of
the generation it closes, after the completion. `down()` refuses while any row, COMPLETE/CLOSE event or COMPLETED/CLOSED Job exists, checked after the tables are locked.

## Limits

- **Mandatory evidence:** the pilot has none beyond the source proof; a source that cannot prove readiness refuses. Policy-required evidence (signatures, approvals) is a later slice.
- No cancellation, stop, reopening or correction of a completion; no public endpoint or screen; no activation. Independent review is required before any duty is activated.
