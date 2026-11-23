[← Domain index](README.md)

# Jobs command API

The owner authorised this HTTP slice on 23 November 2026 (#128), after independent approval of
#127 and the #129 actor-specific source verifier. All 23 shipped Jobs duties remain SPECIFIED;
no permission registration, grants, activation, migration, public creation or write screen is included.

## Endpoints and authority

Every route uses `/api/v1`, Sanctum, an exact ACTIVE Jobs duty, current active company membership
and that duty’s own ALL scope. Command-only employees need neither `job.view` nor Sales read duties.
Requests guard authority before record lookup; the existing Actions independently guard and recheck
it under the existing source fences before replay or fresh work. No controller transaction or
alternative lifecycle/source policy is introduced.

| POST suffix below `/jobs/{job}` | Exact duty | Existing Action |
|---|---|---|
| `assign` | `job.assign` | `AssignJobResponsibility` |
| `start` | `job.start` | `StartJob` |
| `holds` | `job.hold` | `PlaceJobHold` |
| `resolve-hold` | `job.hold.resolve` | `ResolveJobHold` |
| `complete` | `job.complete` | `CompleteJob` |
| `closeout-assessments` | `job.close` | `AssessJobCloseout` |
| `close` | `job.close` | `CloseJob` |

There is no POST `/jobs`, state PATCH, combined/bulk command, public receiver, cancellation or reopening.

## Transport and retries

Every POST requires positive integer `expected_generation` and nonblank `request_key` (≤85 characters).
The six lifecycle commands also require a caller-retained lowercase UUID `command_identity`.
Assessment instead keeps its existing per-actor/key identity. Unknown input keys are rejected,
including actor/company, server timestamps, source facts, advanced expected vectors, includes and filters.
Target ids are positive integers; scoped eligibility stays with the Action, with no global `exists` lookup.

Lifecycle commands accept optional offset-bearing `occurred_at`, converted to UTC, and a nonblank
`occurrence_precision` (≤20, default NONE). Recorded time is always the database’s clock.
Assignment requires `assignee_id`, with optional nonblank `reason` (≤1000), `instructions` (≤2000)
and offset-bearing intended dates; an end requires a start and cannot precede it. Start accepts an
optional reason. Hold and resolution require nonblank reason; resolution requires `hold_id`.
Completion requires nonblank `statement` (≤1000). Close requires `assessment_id`, with nullable
optional statement (≤1000). No other business fields are accepted.

The controller calls the existing Action once with canonical scalars/UTC timestamps. Identical key
or command UUID retries return the original event, even after head advancement; changed intent or
generation refuses with existing REQUEST_KEY_REUSED / COMMAND_CONFLICT. A new hold UUID is a new hold.
Assessment replay is per actor/key/generation; another actor records their own assessment.

Success and replay are 200 in a typed `data` envelope. Command fields are exactly `job_id`, `event_id`,
`sequence`, `operation`, `state`, `generation`, `replayed`; state/generation describe that event,
including on replay. Assessment fields are exactly `job_id`, `assessment_id`, `status`, `blockers`,
`generation`, `replayed`. BLOCKED is a successfully recorded assessment (200). Current blocker codes
are JOB_NOT_COMPLETED, COMPLETION_MISSING, OPEN_HOLDS, SOURCE_NOT_READY, SOURCE_CONDITIONS_CHANGED,
FULFILMENT_MISSING and FULFILMENT_MISMATCH. HTTP does not reproduce their business rules.

Authentication refuses with 401, operation authority with 403. Only JOB_NOT_FOUND is translated to
a generic `404 {message: "The Job was not found."}` for commands; other domain refusals keep the
existing 422 message/errors/reason_code contract. No financial figures, private source context,
hashes, pins, source vectors or broad history appear in command results.

## Minimal operation context

`GET /jobs/{job}/operation-context?operation=ASSIGN|START|HOLD|RESOLVE_HOLD|COMPLETE|ASSESS_CLOSEOUT|CLOSE`
uses the selected operation’s exact duty. Unknown operations refuse before any Job lookup.
The query requires authority, starts one transaction, acquires CompanyAccessFence, rechecks authority,
loads only an internal same-company Job locator, then calls the existing bound
`JobExecutionContextVerifier::enter` with a freshly read actor. Sales owns actor-specific source
eligibility and its source fences. Authority is rechecked after that call and before returning.

Only eligible records disclose context; readiness is deliberately distinct. The query makes one SQL
snapshot of head and bounded children after source proof, without a Job lock, and materialises a
safe projection inside the transaction. No model, builder, source vector or deferred query escapes.
Known SOURCE_CONTEXT_UNAVAILABLE / JOB_NOT_FOUND source refusals and false record eligibility become
the generic 404; unexpected verifier/infrastructure failures propagate and disclose no context.

| Operation | Fields beyond `job_id`, `number`, `state`, `generation`, `operation` |
|---|---|
| ASSIGN | `responsibility_id`, `has_responsibility` |
| START | `has_responsibility`, `open_hold_count` |
| HOLD | None |
| RESOLVE_HOLD | `open_holds`: same-Job open ids, operational reasons, UTC recorded times |
| COMPLETE | `open_hold_count` |
| ASSESS_CLOSEOUT / CLOSE | `open_hold_count`, nullable UTC `completion_recorded_at` |

Context appends no assessment or business evidence and promises no command success. The Action
subsequently verifies current authority, source eligibility/readiness, generation and preconditions.

## Acceptance and applicability

The HTTP tests use genuine Sales releases and existing Jobs Actions/verifier/fulfilment recorder.
They cover lifecycle, safe responses, validation, authority/scope/company/child boundaries, replay,
changed intent, stale state, immutable fulfilment and closeout, and rollback. Committed HTTP tests
use separate PostgreSQL processes for duplicate/competing commands and real fence waits. A normal
security writer serialises through the company barrier; controlled post-source-wait authority changes
prove the recheck, and source-lineage corruption during a real wait proves fail-closed disclosure.
The controlled authority change shares the refused read transaction and rolls back; it is not
claimed as an independently committed security interleaving.

Completion checklist: semantics reuse accepted lifecycle contracts; tenant/scope/security are guarded
twice; integrity and existing database invariants stay with owning Actions; transport and generated
client accompany routes. New migration/rollback, permission rollout, advanced mode, screen/lookup,
responsive/accessibility/browser journey and demo activation are not applicable to this disabled
HTTP-only slice. Existing read screens and API contracts remain intact. Relevant ratchets, focused
and Jobs module tests, owned Pint, whole-project PHPStan, client drift and frontend typechecks/build
are required; exact results and hosted CI belong to the delivery issue, not this domain contract.

## Related

- [Jobs module](../../backend/app/Modules/Jobs/README.md)
- [Job execution lifecycle](job-execution-lifecycle.md)
- [Completion and closeout](job-completion-closeout.md)
- [Jobs read API](job-read-api.md)
- [Phase 5](../phases/phase-5-job-execution.md)
- [API conventions](../architecture/api-conventions.md)
