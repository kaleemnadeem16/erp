[← Domain](README.md)

# Quotation access completion — resume checkpoint

**2026-11-03: incomplete, unpublished continuation.** This is a handoff after an editor interruption
and a shared test-service failure, not feature sign-off. The test service was subsequently recovered under the owner’s one-time exception; see the recovery update below. Follow the original owner direction in
[#77](https://github.com/kaleemnadeem16/erp/issues/77). Do not edit STATUS files or run anything
against the owner's `erp` database. Production activation remains **BLOCKED**.

## Preserved work

Safety commits through `50623859` are published. The following continuation commits are local
on develop and have not been pushed as a finished slice:

- `9879758e`: owner-approved Estimating decimal-fixture false-positive correction, own commit;
  all original representation/serialisation checks retained and explicit secret-fixture checks added.
- `d3ad6ef6`: advisory corrections: fresh document authority under the fence, one-statement list
  count/page snapshot, independent retry budgets, uniform family refusal, database-date validation
  and safe restoration-note projection. View + prepare stays required for preview/edit.
- `219dc99b`: normal merge of published pricing/tax work through upstream `f0f81854`;
  retains per-row recipient/commercial projection and snapshot pagination.
- `a6846c65`: exact tax declaration route registrations and updated exact editable field shape.
- `fb60d250`: owner demo decisions in ADR 0154, partial supersession of ADR 0129's status,
  current persona table and intended preset/QA4 mapping. Demo implementation is not delivered.
- `58a602e6`: QA4 read-only explicit-company inventory command and tests. This is only the
  inventory portion; no holder recipe is applied and no right is granted.

Three additional incoming-migration populated rollback proofs are prepared in
`IncomingQuotationRollbackProofTest`. Pint and whole-project PHPStan passed. After recovery, all three tests passed (25 assertions) on the private test database.

## Verification evidence

All tests used `ERP_AGENT=quotation-security`, private DB `erp_testing_quotation_security`,
on postgres-test. No owner/dev/production database or running app endpoint was used.

| Check | Actual result |
|---|---|
| Earlier safety baseline full backend suite | 3,371 passed, one pre-existing skip; historical baseline, not final integrated head |
| Whole-project PHPStan | Passed on integrated source, inventory and incoming proof tests |
| Pint | Passed on advisory PHP paths, integration entries, inventory and incoming proof tests |
| Initial combined advisory rerun | 74 passed; 17 failed SQLSTATE 53100 when shared tmpfs filled |
| Focused integration/inventory | 28 passed, 18,883 assertions; tax declaration, editable shape, route registries, read-only inventory |
| Scope/snapshot/ratchet rerun | 12 passed, 35 assertions; real two-connection count/page regression included |
| CompanyAccessFenceTest rerun | 14 passed; two failed during schema setup with SQLSTATE 53100, not a passing run |
| Frontend | core-api/core-ui/main TypeScript checks and Vite production build passed |
| New incoming rollback proofs | Passed: three tests, 25 assertions, after approved test-service recovery |
| Final codegen/migration cycle/module regression/CI/demo/browser | Outstanding; no final-head green claim |

Logs under `/tmp/erp-quotation-`: `advisory-resume.log`, `integration-focused.log`,
`ratchets-retry.log`, `fence-retry.log`, `inventory-phpstan-final.log`,
`incoming-proof-phpstan.log`, `core-api-tsc.log`, `core-ui-tsc.log`, `main-tsc.log`, `vite.log`.
The last published CI is upstream `f0f81854`,
[run 41606304669](https://github.com/kaleemnadeem16/erp/actions/runs/41606304669), failed the
missing tax route entries and editable shape now corrected locally. This run is not our final head.

## Historical test-service failure and recovery

[#84](https://github.com/kaleemnadeem16/erp/issues/84) records the shared postgres-test tmpfs
exhaustion (1.3 GiB, 100%). Supported cleanup briefly recovered only our DB, then another run
filled the remaining space. PostgreSQL reported a server-process crash and the service exited.
**No restart/recreation, capacity change, other agent database deletion or PostgreSQL data-file
modification was performed.** An owner question requests permission for coordinated test-service-only
recovery with the exclusive stack lock and sufficient capacity. Do not run a wrapper that starts
postgres-test until that approval or infrastructure-owner recovery is confirmed.

The synthetic access-operator bootstrap is now **approved with conditions** in
[0155](../decisions/0155-bootstrap-only-the-synthetic-demo-access-operator.md). Implement only the
exact synthetic Company 1 target and approved staged assignment/share set, transactionally,
idempotently and auditable, unavailable in production. After success refuse added/altered grants.
No public route/UI, broad grant, issue/repair or permission activation. Ordinary access flows and
guards remain unchanged. Submit implementation for exact-SHA review and CI **before merging**;
this replaces the previous pending first-holder question. Sharing consumers are not delivered yet:
do not bootstrap an incomplete set now and silently expand it later.

The owner subsequently approved a **one-time fresh initialisation of postgres-test only**.
Recovery acquired the exclusive stack lock and confirmed no active tests; inspection verified
no disk-backed mounts and only the existing `size=1280m` tmpfs. Only the existing
`erp-postgres-test-1` container was started, with no Compose restart, volume deletion or change
to owner ERP/application services. Health, SQL connectivity and fresh test-only database inventory
passed. Initial usage was 46 MB (4%); the isolated incoming rollback test run passed all three
tests/25 assertions and used 8% tmpfs. Existing limit retained; tests initially use one private
database at a time. This exception grants no future cluster reset permission.

Original failure evidence: SQLSTATE 53100 disk exhaustion, checkpoint panic writing
`pg_logical/replorigin_checkpoint.tmp`, failed B-tree WAL redo and exit 1; `OOMKilled=false`.
Full old log is local at `/tmp/erp-postgres-test-failure-2026-11-03.log`. Outcome recorded on
[#84](https://github.com/kaleemnadeem16/erp/issues/84) and #77.
Broader module/ratchet regression is now running; no final-head verification claim yet.

## Continue in order

1. Test-service recovery is complete. Monitor capacity and keep private test runs serial; do not restart the shared app.
2. Incoming rollback proofs passed. Retry the two disk-failed fence cases; complete Sales and
   Documents regression and all applicable ratchets. Keep the populated refusal guards.
3. On a clean exact checkout run codegen --check and the private migration cycle; verify frontend
   drift and demo CI requirements. Merge origin/develop again before normal push; do not push
   until the advisory slice is verified. Watch every job on the exact pushed head and fix forward.
4. Finish QA4 unapplied holder-recipe code, provenance ledger, dry-run/backout and populated tests.
   The inventory command alone does not complete QA4.
5. Finish QA5 backend/client/UI: MY_UNITS and structured scope/delegation, security epochs,
   append-only shares/revocations, real Actions, owner transfer and permission-aware UI with
   non-disclosing errors. Published pricing UI dependency is now satisfied. Preserve the existing
   gate, unrelated CRM semantics, issue/repair separation and independent view + prepare authority.
6. Implement approved demo extension under the approved first-holder conditions, using ADR 0154 and the
   persona/preset contract: exact guards, shared role definitions, real Actions/scenarios, create-only
   reruns, no issue/repair and no real-holder production activation. Complete exact-head CI.

Representative capacity remains blocked separately: the owner has no representative company counts,
concurrent workload profile or latency budgets yet. Keep the parameterised synthetic harness and
its single-host/synthetic/no-real-concurrency limitations; invent no targets.

## Related

- [Advisory decisions](quotation-access-advisories.md)
- [Demo contract and persona/preset tables](quotation-access-demo.md)
- [Safety harness and rollback evidence](quotation-access-safety-proofs.md)
- [QA4 inventory checkpoint](quotation-access-qa2-qa4.md#qa4-read-only-inventory-checkpoint)
- [Ordered slices](../phases/phase-4-quotation-record-access-slices.md)
