# Jobs module

The **disabled** Phase 5 Jobs foundation (SP0b; [design](../../../../docs/phases/phase-5-sp0a-design.md), [delivery contract](../../../../docs/phases/phase-5-sp0a-delivery-contract.md)).
It is four tables and their models. **Nothing can create or change a real Job**: no route, duty, permission vocabulary, grant, Action or receiver entry point.

## Owns

- Tables (`2026_10_22_090000_create_jobs_foundation`): `service_jobs` (code, state RELEASED/ACTIVE/COMPLETED/CLOSED, generation ≥ 1; the Laravel queue's `jobs` table is
  unrelated), `job_handover_origins` (the future receiver receipt: points at the Job, one per Job, one per purpose + business identity), `job_baselines`
  (declared scope basis, versioned, same-Job predecessor chain) and `job_events` (sequence and generation, both unique per Job).
- Origins, baselines and events are append-only through `job_evidence_append_only_guard` (this module's own, never the Documents guard). `down()` refuses while
  any row exists, fixtures included.
- `Support\ReleasedServiceInput` and `ReleasedServiceValidator`: a typed value object and a pure shape check. They are not an entry point.

## Boundaries

- `service_jobs` holds no origin, baseline or event pointer; there is no circular FK. The latest baseline or event is derived by version or generation.
- No quantity, measure, money, acceptance, allocation, stage or upstream source id exists here; those arrive as real composite FKs with the Sales integration,
  when the origin identity also gains its stage.
- Only the stated constraints are claimed: company-scoped composite keys, state and generation checks, per-Job uniqueness and immutability. The lifecycle state
  machine, default activity, holds, assignments and receipts belong to later slices.
- Fixture rows exist only in tests (`tests/Feature/Modules/Jobs`).
