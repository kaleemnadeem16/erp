[← Phase index](README.md)

# Task A — receiver dependencies, acceptance and handoff

2026-10-22 · #32 · PROPOSED companion to the [receiver integration design](phase-5-r7-job-receiver-design.md).
Design only: no application implementation, migrations, permissions, registrations or routes.

## Dependency and ownership handoff

| Owning slice | Required delivery / what Task A can specify now |
|---|---|
| Sales QL/issue/acceptance | First grounded source SERVICE_ITEM requires item_id/offered_uom_id/offered_quantity > 0 (commercial provenance only); MANUAL has no descriptor and is excluded. Authoritative issued/accepted source and completion evidence remain GATED; draft work is not accepted business |
| R7 HA0/HA1 | Canonical plan/allocation/stage/business identity and immutable source baseline; fences, generations, supported readiness/approvals and composite source keys; exact physical names not implemented here |
| R7 HA2/service HA3b | Sales release coordinator/source verifier, capacity/stage uniqueness and Sales receipt linking Jobs origin; real calls/connection/rollback/concurrency proof with Jobs receiver |
| Jobs #28 | Four-table disabled schema and validator; no production receiving Action, activities, components or real source references. Authorised to Agent A but not delivered at baseline f0e2871 |
| Jobs receiver integration | Jobs-owned real Sales FKs, stage-inclusive origin business key AND UNIQUE(company_id,stage_id,target_purpose), GATED default activity/components, immutable initial baseline, job.receive guard and safe typed result; separate implementation approval |
| Security/R9 | Actual guarded duties, ALL-only independent scopes, current replay authorisation, safe result/source visibility, explicit holder rollout; existing manifest pattern does not activate handover/receiving |
| Numbering | Actual Job document-type/series binding and reviewed code allocation lock position; central framework exists, Jobs registration/configuration absent |
| Documents | DF0 #11 integrated; source-specific Sales bindings #24 and later framework/evidence delivery when the selected source requires them. No blanket formal Job PDF dependency |
| Pricing/tax/control | #12 and later supported financial subset required by actual commercial issue/consent path; UNKNOWN draft tax is not issued acceptance support |
| Task B lifecycle/closeout | Later start/hold/fulfilment/remainder/source-read readiness and correction compatibility; define only the pinned completion basis here, not those Actions/tables |
| Task C/J2 / Inventory / Purchasing | No implementation dependency for pure receiver design. Actual applicability/writer inventory must prove exclusions; required source effects cannot be defaulted to NONE |

Only #19 is a register dependency of this DESIGN task; missing CA/HA is not a reason to fabricate
source delivery or block independent planning. Implementation/activation tasks must name the actual
required integrated commits and checks, not simply depend on an issue title. #27 test-fixture cleanup
is integrated at baseline but says nothing about real receiver transaction safety.

No cross-owner edits: Sales owns CA/HA schema/Actions/locks/receipts, Jobs owns target/receiver/schema,
security reviewer checks independent authority/scopes, coordinator assigns actual implementers and
sequences exact commits. Existing Agent A quotation/#24/#28 work is not expanded by this document.
If Sales rejects a needed composite key/verifier/lock position, report a missing-contract issue and
resolve through its owner; do not patch Phase 4 handoffs or accepted ADRs. No reviewed incompatibility
was identified in the accepted logical contracts; actual writer compatibility is still unproven.

## Suggested separately authorisable delivery groups

1. Joint interface review: grounded first source SERVICE_ITEM; GATED actual CA/HA FK target keys,
   completion-evidence envelope/version, verifier interface, individual holder recipe, exact lock/Numbering
   positions/Inventory compatibility, activity default trigger/components and independent-session proof.
2. Disabled schema extension AFTER #28 and actual CA/HA schema: Jobs→Sales FKs and origin uniqueness
   replacement PLUS UNIQUE(company_id,stage_id,target_purpose); every Jobs-to-Sales reference has
   (company_id,id) composite FK and parent binding. Components/default trigger remain GATED. No fake backfill,
   public receiving or authority activation. Review evidence-safe rollback.
3. Atomic release adapter group: Sales coordinator/verifier + Jobs receiver, actual source/target guards,
   numbering and exact source/origin receipts. No enabled half. Small commits may integrate disabled,
   but supported path activation waits for all mandatory participants and real tests.
4. J1 activation only with separately authorised Task B lifecycle/closeout/source-read proof and reviewed
   holder configuration. J2 remains optional and independently reviewed; receiver creates no costs.

No route/API generator changes in a disabled-only group. Jobs receiver is internal/coordinator-only,
with no Request, route, console or import entry. Later Sales release Request/Action and safe
Resource/OpenAPI/client are owned by Sales when that task is authorised; no public Jobs create route.
Migrations use additive reviewed timestamps after latest develop, company-composite FKs/uniques and
Jobs-specific append-only guards. Required source composite-key additions are Sales-owned tasks.
Down refuses evidence; no destructive uniqueness downgrade. No stack/migration test run in Task A.

## Acceptance and failure matrix — future proof, not test results

| ID | Trigger and required result |
|---|---|
| RA01 | Draft quote, evidence observation, unsigned generic accepted flag or bare document binding supplied → unsupported/non-authoritative refusal, zero target effects |
| RA02 | Genuine fixed-service acceptance but team absent → acceptance remains valid; readiness distinguishes pending execution facts from release prerequisites, no invented worker/hour/unit |
| RA03 | Fixed unmeasured component → explicit identity/conditions, both measure and quantity NULL; measured component requires both and positive exact source quantity; mismatched pair/raw SQL fails |
| RA04 | EVERY Jobs-to-Sales reference has (company_id,id) composite FK plus required parent binding; wrong-parent/company fails even when individual IDs exist; CHECK only by recorded exception |
| RA05 | Same K with fresh actor/key → one ServiceJob/origin/default activity/initial baseline; Sales response links original origin; no extra commercial claim |
| RA06 | Raw/concurrent insertion with different business_identity on same stage/purpose fails UNIQUE(company_id,stage_id,target_purpose); changed baseline/keyed intent conflicts; safe identities only |
| RA07 | Real release throws after ServiceJob insertion, origin/baseline/activity writes or Sales stage/receipt writes → entire outer transaction rolls back, no durable half-target; assert runtime connection/backend session |
| RA08 | Outside coordinator/transaction, different connection, fabricated input, input/canonical mismatch or verifier UNAVAILABLE/UNSUPPORTED/UNKNOWN/non-positive → refuse with zero effects; persist baseline/components/measures only from positive canonical output |
| RA09 | Parallel releases against same absent stage target; parallel disjoint claims exceeding cap → independent sessions prove single target/cap conservation; non-measured full component cannot branch by inventing quantity |
| RA10 | Release races reduction/withdrawal/hold/approval or source policy change → locks/generations produce coherent supported result or stale refusal; no bypass via import/worker |
| RA11 | sales.handover.release and job.receive checked independently, ALL-only; limited/revoked refuses, foreign-company key indistinguishable from absent; no automatic receive for Sales releaser/owner/admin, STAGED default, individually reviewed holders without cost/margin |
| RA12 | Target later started/held/closed; original exact replay → original safe identity/current permitted state, no reset, new event, numbering or obsolete prerequisite failure |
| RA13 | Key uniqueness/deadlock → whole transaction rollback then bounded retry; no querying aborted transaction; changed intent maps safe conflict, no private competing IDs |
| RA14 | No creator/import/console takes Numbering counter before Sales locks; numbering/downstream failure leaves no target/receipt, GAPLESS restored or ALLOW_GAPS gap allowed; replay allocates no new number |
| RA15 | Source completion default/master text changes after release → original Job baseline/conditions unchanged; supported successor separate, original source references immutable |
| RA16 | Required atomic mixed group lacks actual adapter or Inventory/Purchasing effect is unknown → no partial success or NONE; unsupported path remains disabled |
| RA17 | SP0b fixture evidence exists when real FKs/unique identity attach → rollout blocks pending reviewed reconciliation, never unchecked nullable bypass/delete/fake accepted data |
| RA18 | Receiver target/origin mismatch → invariant refusal and owned reconciliation; no synthetic recovery receipt or acceptance; all replay/conflict responses expose safe identities only, never cost/hashes/private competing IDs |

Implementation evidence names real dependency commits, fixture versus actual integration tests,
connection/session identity, independent-session barriers and all conflicting writer paths. Backend
module regression, relevant tenant/scope/permission/write/read ratchets, migration cycle, Pint/Larastan,
API/client drift and browser tests apply to later changed surfaces. CI/coordinator handles full suite;
no feature-agent full backend run. #14 worktree browser limitation requires coordinator-supported
integrated journey verification, never a test against another checkout disguised as own branch proof.

## Review decisions and open selections

Proposed technical choices: origin business identity independent of request identity; one target per
stage/purpose; parent-consistent Jobs→Sales FKs; pinned fixed-deliverable conditions with no fabricated
operational unit; separate typed source verification; Sales-first shared transaction and locks; central
Numbering; immutable baseline/result identity with current-authorised replay. Alternatives rejected:
actor/key receiver identity duplicates targets, queue-only creation splits mandatory effects, generic
strings weaken source integrity, live-master baseline recalculation rewrites history, arbitrary 1-unit
services fake measures, and universal document/timesheet gates contradict accepted SIMPLE direction.

No accepted business choice reopened and no ADR modified. First grounded source is SERVICE_ITEM;
MANUAL is not a descriptor candidate. GATED until Sales/R7 writers exist: physical CA/HA names/keys,
completion evidence shape/version (no source today), verifier interface, exact lock/Numbering positions
and Inventory compatibility, individual holder recipe contents, job_activities default trigger,
job_baseline_components and independent-session proof. Jobs numbering registration/configuration
also awaits implementation review. Some cannot be frozen before source writers
exist; record unresolved exact bindings explicitly rather than approve a placeholder. Actual execution,
closeout and correction behaviour belongs to later tasks. Owner approval of this design does not
approve migrations, duties, routes, Job activation or public pilot sign-off.

## Related

- [Task G Sales-to-Jobs implementation batch proposal](phase-5-task-g-r7-batch-proposal.md) — proposed ownership, prerequisite order and disabled first Agent A batch.
- [Receiver integration design](phase-5-r7-job-receiver-design.md)
- [SP0a](phase-5-sp0a-design.md) · [Phase 5 overview](phase-5-job-execution.md)
- [R7 slices](phase-4-r7-implementation-slices.md) · [R6](../domain/sales-customer-decisions.md)
- [R9 duty matrix](../domain/sales-duty-matrix.md) · [Pre-push checks](../architecture/pre-push-checks.md)
