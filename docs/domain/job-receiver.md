# Jobs — receiving a released Sales service (Phase 5 Batch 1, disabled)

[← Domain index](README.md) · [Sales release](sales-service-release.md) · [Job lifecycle](job-execution-lifecycle.md) · [Batch 1 package](../phases/phase-5-batch1-authorisation-package.md) · #69

The Jobs half of the paired Sales release → Jobs receipt. It is an internal, disabled integration: there is **no route, no registered permission, no ACTIVE duty and no grant**. Every shipped Jobs duty is SPECIFIED, so
the receiver refuses everyone today; the tests prove the logic with a fixture-activated manifest only.

## What happens in one release

Sales' `ReleaseHandoverComponent` runs ONE transaction: actor authority → source locks → business uniqueness → completion basis and readiness → immutable `handover_release_events` row → the Jobs receiver
(`ReceiveReleasedService`, a Sales-owned port bound by Jobs) → a check that the receiver linked exactly this release. The receiver, in that same transaction and session:

1. proves the `VerifiedServiceRelease` is current and the company matches; independently requires `job.receive` of the releasing actor;
2. numbers the Job (`JOB-{SEQ:5}`, per company, gaps allowed);
3. writes `service_jobs` (RELEASED, generation 1), the RELEASE `job_events` row (sequence 1, generation 0→1), `job_baselines` v1 (scope = the verified deliverable text, rule `SIMPLE_ATTESTATION_V1`),
   the generic `job_handover_origins` (purpose, `stage:<id>`, the release intent hash), the Sales lineage `job_sales_origins`, `job_baseline_components` (deliverable and exact agreed conditions) and the one
   DEFAULT `job_activities` row.

Any exception, including a deferred COMMIT-time check, rolls everything back: the release event, the Job, its number and its evidence. A retry, a fresh key or another actor resolves to the existing release
(Sales) and the database refuses a second Job for the same release event, so no duplicate Job, number or acceptance claim can exist.

## Boundaries

- No public create-Job endpoint; no completion, closeout, cancellation or stop (the lifecycle allowlist still refuses them; Batch 2).
- A started Job passes through `SalesJobExecutionContextVerifier`; a Job with no Sales lineage is refused.
- Cost is conditional and separate from completion (Sales). No labour, material, supplier, hours, percentage, signature or PDF is involved.
- `down()` of the migration refuses while any receiver row exists. Backout of a pilot is "disable new work", never destructive.
- Independent security/transaction review, Inventory/Purchasing owner sign-off of the no-obligation catalogue, and an owner activation decision are required before any duty is activated (Batch 3).
