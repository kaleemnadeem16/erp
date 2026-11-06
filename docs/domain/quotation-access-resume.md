[← Domain](README.md)

# Quotation access completion — resume checkpoint

**2026-11-05: advisory checkpoint published and CI-verified; overall completion remains outstanding.**
Published advisory/docs checkpoint `f3e7d0d29a40e4f8f38741ee7066328901d5ea05` passed
[Pipeline 41979504734](https://github.com/kaleemnadeem16/erp/actions/runs/41979504734): backend
shards, browser shards, both linters and codegen all succeeded. QA4 holder recipe, QA5 and demo
extension are not delivered by that checkpoint. Follow the original owner direction in
[#77](https://github.com/kaleemnadeem16/erp/issues/77). Do not edit STATUS files or run commands
against the owner's `erp` database. Production activation remains **BLOCKED**.

## Preserved work

Safety commits through `50623859` are published. The following continuation commits are now included in published develop `c7c55d99`:

- `9879758e`: owner-approved Estimating decimal-fixture false-positive correction, own commit;
  all original representation/serialisation checks retained and explicit secret-fixture checks added.
- `d3ad6ef6`: advisory corrections: fresh document authority under the fence, one-statement list
  count/page snapshot, independent retry budgets, uniform family refusal, database-date validation
  and safe restoration-note projection. View + prepare stays required for preview/edit.
- `219dc99b`: normal merge of published pricing/tax work through upstream `f0f81854`;
  retains per-row recipient/commercial projection and snapshot pagination.
- `a6846c65`: exact tax declaration route registrations and updated exact editable field shape.
- `fb60d250`: owner demo decisions in ADR 0156, partial supersession of ADR 0129's status,
  current persona table and intended preset/QA4 mapping. Demo implementation is not delivered.
- `58a602e6`: QA4 read-only explicit-company inventory command and tests. This is only the
  inventory portion; no holder recipe is applied and no right is granted.

## Unpublished QA5 increments

- `b56dd0af` and `26d732c4`: quotation MY_UNITS, same-duty OWN/unit union, current unit reach and
  legacy resolver parity. Broad regression passed 662 tests/4,141 assertions. The second commit
  removes an obsolete import and corrects the preceding premature final-Pint claim; all paths pass.
- Actor-epoch migration `2026_11_10_010000_advance_quotation_actor_authorization_epochs` and tests:
  monotone/permanent user invalidation on status/company/assignment changes, bounded actor-row
  waits, unchanged profile/login behaviour and populated safe refusal. Targeted tests passed
  eight tests/45 assertions; broad quotation/Documents/Administration/warehouse/CRM/security
  regression passed **693 tests/4,337 assertions**. Migration fresh/rollback/reapply, whole-project
  PHPStan, Pint and codegen --check passed. No generated-client drift. Logs use
  `/tmp/erp-quotation-epochs-*`. These changes are not published as a finished QA5 feature.

Named-share Actions/consumers are implemented locally as an incomplete backend increment;
API/UI, demo presets and the separately reviewed bootstrap remain outstanding. See the
[sharing checkpoint](quotation-sharing-implementation.md) for exact behaviour and verification.
The owner approved the audited ledger in [0157](../decisions/0157-audit-quotation-access-generation-with-an-append-only-ledger.md).
The local storage implementation uses the single additive migration
`2026_11_10_020000_create_quotation_access_events_and_shares`, preserving old ownership history.
Focused old/new storage and upgrade tests passed 21 tests/176 assertions, including exact SQLSTATE
23514 and the original append-only guard message for ownership TRUNCATE CASCADE. Whole-project
PHPStan and Pint passed. The broader quotation/Documents/Administration/CRM/security regression
passed 706 tests/4,430 assertions (445 seconds wrapper time, 69% tmpfs); log
`/tmp/erp-ledger-full-regression.log`. The additional two-connection ownership/share contention test passed (one test/11 assertions),
including bounded refusal, stale-pin rejection after commit and continued history. The private
fresh → rollback → reapply migration cycle passed. Codegen --check passed with no generated-client drift.
This is storage progress, not completed QA5 acceptance.

The owner also approved two narrowly scoped existing storage-fixture corrections in their own
commit: TRUNCATE CASCADE reaches the append-only guard through the new composite FK, and the
empty rollback/reapply removes/reinstalls child schema in dependency order. All original assertions
remain unchanged. The later delegation consumer has separate approval to change only the assertion
that pins OWN_OR_DELEGATED as unsupported, in the same commit implementing that feature; generic
denial, tenant and unknown-resource assertions must remain unchanged, with real-share denial tests.

Three additional incoming-migration populated rollback proofs are prepared in
`IncomingQuotationRollbackProofTest`. Pint and whole-project PHPStan passed. After recovery, all three tests passed (25 assertions) on the private test database.

Local named-share backend increment: guarded grant/revoke Actions, permanent epoch-pinned eligibility,
opt-in scope and compatible Administration assignments are implemented; no public family access
surface or demo extension yet. Broad regression passed 722 tests/4,528 assertions; final focused
checks passed 26/151. Owner-approved inventory/assignment/registration proofs passed 13/299 with
one unchanged pre-existing skip. Whole-project PHPStan passed serially after a parallel timeout;
Pint, generated-client drift check, all three frontend typechecks and production build passed.
The generated enum change is committed with its request source. Full exact-head CI and the overall
feature remain outstanding. See [the implementation checkpoint](quotation-sharing-implementation.md).

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
| Published advisory CI at c7c55d99 | All backend/browser shards, lint and codegen passed; this verifies that checkpoint, not later QA4/QA5/demo changes |

Historical local logs used `/tmp/erp-quotation-*`; those temporary files were lost across the
later environment restart. Do not infer a result for the interrupted broad regression from the
missing log. The published Pipeline result above supplies independent exact-SHA evidence.
The fresh local continuation log `/tmp/erp-quotation-nov5-regression.log` records 615 tests passed,
3,919 assertions, 317 seconds wrapper time and 39% tmpfs usage.

## Historical test-service failure and recovery

[#84](https://github.com/kaleemnadeem16/erp/issues/84) records the shared postgres-test tmpfs
exhaustion (1.3 GiB, 100%). Supported cleanup briefly recovered only our DB, then another run
filled the remaining space. PostgreSQL reported a server-process crash and the service exited.
During the initial investigation, no restart, capacity change, other agent database deletion or
PostgreSQL data-file modification was performed. The later explicit owner exception and completed
recovery are recorded below; it is not a blanket future reset permission.

The synthetic access-operator bootstrap is now **approved with conditions** in
[0155](../decisions/0155-bootstrap-only-the-synthetic-demo-access-operator.md). Implement only the
exact synthetic Company 1 target and approved staged assignment/share set, transactionally,
idempotently and auditable, unavailable in production. After success refuse added/altered grants.
No public route/UI, broad grant, issue/repair or permission activation. Ordinary access flows and
guards remain unchanged. Submit implementation for exact-SHA review and CI **before merging**;
this replaces the previous pending first-holder question. The complete sharing workflow is not delivered yet:
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
On 2026-11-05 Docker Desktop was initially unavailable; the owner started it. The isolated test
service was started under the exclusive lock with no disk-backed mounts and the unchanged 1280 MB
limit. It is healthy. A fresh quotation/Documents/ratchet regression passed 615 tests/3,919 assertions serially on
`erp_testing_quotation_security`. The MY_UNITS/OWN-union backend increment is implemented locally. Its focused tests passed; the
broader quotation/Documents/warehouse/CRM/security regression passed 662 tests/4,141 assertions
(333 seconds, 39% tmpfs). Log: `/tmp/erp-quotation-team-regression.log`. It is not published as a
finished QA5 feature; shares, UI, demo presets and bootstrap review remain outstanding.

## Local continuation — 5 November family API/UI

The local access ledger and named-share consumer are committed through `3e9ba12f`; these are not
the published exact-CI baseline. Local checkpoint `cb6e4bf7` implements the six family access routes, safe Resources, generated
client and bounded directory/history projection. The following local UI checkpoint integrates
the access/responsibility workspace; neither is a published finished slice.
Focused backend checks passed 12 tests/207 assertions; after recovery the API/security/ratchet
run passed 82 tests/476 assertions. Isolated browser/model/existing quotation checks passed 39
tests together (16 browser journeys plus 23 contract/model/existing tests), including cached
metadata denial in both full-page and sheet containers. Typechecks and Vite build passed. Whole-project
PHPStan passed using a private temporary cache after the normal cache was unwritable. Broader
regression failed after PostgreSQL backend exits and recovery: 564 passed, 134 failed, one existing
skip (4,280 assertions). It is not passing evidence. Logs show backend exit code 2 and SQLSTATE
08006/recovery mode, tracked in #13; exact cause remains unknown. No restart/reset was performed.
The recovered healthy test service passed the focused security/ratchet rerun; the fresh broader regression subsequently passed 745 tests/5,023 assertions, with one unchanged
existing skip; no infrastructure reset was used. Client regeneration/drift and the final frontend build also passed. No owner
database/application was used.

Approved existing-test alignment: exact six-route inventory plus no-baseline-route assertion, with
receipt assertions retained; PREPARE-without-VIEW review false plus both-duties-positive case.
Production remains off. QA4 provenance/recipe/backout and demo/presets/bootstrap are not complete.
The owner approved the narrow quotation-only patch path on 6 November. Preserve unrelated main-user
access, audit quotation changes and retain self-management/grant-ceiling checks. Implementation
and tests are next; no dependent access-Action change has yet been implemented.

## Continue in order

1. Test-service recovery is complete. Monitor capacity and keep private test runs serial; do not restart the shared app.
2. Incoming rollback proofs passed. Retry the two disk-failed fence cases; complete Sales and
   Documents regression and all applicable ratchets. Keep the populated refusal guards.
3. On a clean exact checkout run codegen --check and the private migration cycle; verify frontend
   drift and demo CI requirements. Merge origin/develop again before normal push; do not push
   until the advisory slice is verified. Watch every job on the exact pushed head and fix forward.
4. Finish QA4 unapplied holder-recipe code, provenance ledger, dry-run/backout and populated tests.
   The inventory command alone does not complete QA4.
5. MY_UNITS, same-duty OWN/unit union and actor epochs are implemented locally. Use the
   approved access ledger, then finish QA5 backend/client/UI: named scope/delegation,
   append-only shares/revocations, real Actions, owner transfer and permission-aware UI with
   non-disclosing errors. Published pricing UI dependency is now satisfied. Preserve the existing
   gate, unrelated CRM semantics, issue/repair separation and independent view + prepare authority.
6. Implement approved demo extension under the approved first-holder conditions, using ADR 0156 and the
   persona/preset contract: exact guards, shared role definitions, real Actions/scenarios, create-only
   reruns, no issue/repair and no real-holder production activation. Complete exact-head CI.

Representative capacity remains blocked separately: the owner has no representative company counts,
concurrent workload profile or latency budgets yet. Keep the parameterised synthetic harness and
its single-host/synthetic/no-real-concurrency limitations; invent no targets.

## Related

- [Approved access-generation extension](quotation-access-generation-extension.md)
- [Advisory decisions](quotation-access-advisories.md)
- [Demo contract and persona/preset tables](quotation-access-demo.md)
- [Safety harness and rollback evidence](quotation-access-safety-proofs.md)
- [QA4 inventory checkpoint](quotation-access-qa2-qa4.md#qa4-read-only-inventory-checkpoint)
- [Ordered slices](../phases/phase-4-quotation-record-access-slices.md)
