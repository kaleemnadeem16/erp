# Jobs module

The **disabled** Phase 5 Jobs foundation (SP0b; [design](../../../../docs/phases/phase-5-sp0a-design.md), [delivery contract](../../../../docs/phases/phase-5-sp0a-delivery-contract.md)).
It is eight tables and their models (four from SP0b, four from J1-F1). **Nothing can create or change a real Job**: no route, duty, permission vocabulary, grant, Action or receiver entry point.

## Owns

- Tables (`2026_10_22_090000_create_jobs_foundation`): `service_jobs` (code, state RELEASED/ACTIVE/COMPLETED/CLOSED, generation ≥ 1; the Laravel queue's `jobs` table is
  unrelated), `job_handover_origins` (the future receiver receipt: points at the Job, one per Job, one per purpose + business identity), `job_baselines`
  (declared scope basis, versioned, same-Job predecessor chain) and `job_events` (sequence and generation, both unique per Job).
- Origins, baselines and events are append-only through `job_evidence_append_only_guard` (this module's own, never the Documents guard). `down()` refuses while
  any row exists, fixtures included.
- J1-F1 (`2026_10_26_090000_create_job_lifecycle_foundation`; [design](../../../../docs/phases/phase-5-task-d-j1-foundation.md), [proposal](../../../../docs/phases/phase-5-task-d-j1-f1-proposal.md)) adds four more
  append-only evidence tables, each also truncate-guarded by the same `job_evidence_append_only_guard`: `job_assignment_events` (a continuous same-Job responsibility chain to a
  company user; records responsibility only, grants nothing), `job_holds` and `job_hold_resolutions` (independent JOB-scope holds; a resolution names exactly one hold) and
  `job_operation_receipts` (an actor's request key to a result event; safe references, no response payload). `job_events` gain `command_identity` and `semantic_intent_hash`
  (present together, unique per Job) and a `(company, job, id)` key for same-Job foreign keys.
- Lifecycle coherence is enforced by the database at COMMIT (`job_lifecycle_consistency_v1`, deferrable constraint triggers): the initial release, each successor against its
  predecessor, the head against the latest event, exactly one child per ASSIGN/HOLD/RESOLVE_HOLD (none for START), a receipt from each command's own actor, START only with a
  recorded responsibility and no unresolved hold, and an operation allowlist (RELEASE, ASSIGN, HOLD, RESOLVE_HOLD, START; completion, termination and closeout are refused).
  Writers take the Job head lock first (`job_lifecycle_evidence_guard_v1`), so concurrent writers serialise. The head's company, id and code are fixed once it has evidence.
  Holds and their resolutions are effective at their event's recorded server time; ASSIGN and a further HOLD remain possible while a hold is unresolved (only START is blocked).
- Installing checks that stored SP0b evidence already satisfies these rules and aborts, changing nothing, if it does not. `down()` locks the tables, then refuses while any new
  table has a row or any event carries a command identity.
- `Support\ReleasedServiceInput` and `ReleasedServiceValidator`: a typed value object and a pure shape check. They are not an entry point.

## Boundaries

- `service_jobs` holds no origin, baseline or event pointer; there is no circular FK. The latest baseline or event is derived by version or generation.
- No quantity, measure, money, acceptance, allocation, stage or upstream source id exists here; those arrive as real composite FKs with the Sales integration,
  when the origin identity also gains its stage.
- Only the stated constraints are claimed: company-scoped composite keys, state and generation checks, per-Job uniqueness and immutability. The Actions that
  write lifecycle evidence, the receiver, default activity, completion, closeout and costing belong to later slices.
- Fixture rows exist only in tests (`tests/Feature/Modules/Jobs`).
