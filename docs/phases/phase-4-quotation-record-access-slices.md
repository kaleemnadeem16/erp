[← Proposed quotation access design](../domain/quotation-record-access-design.md)

# Quotation record access — proposed delivery slices (#45)

**2026-10-26 · Planning only. No implementation slice authorised by this document.**
Authority: [#45](https://github.com/kaleemnadeem16/erp/issues/45), revised
[#30 direction](https://github.com/kaleemnadeem16/erp/issues/30#issuecomment-6413435143),
[architecture/owner decisions](../domain/quotation-record-access-design.md) and
[exact storage/Action contract](../domain/quotation-record-access-storage.md).
Do not activate routes or invent company-wide quotation access to make an intermediate UI work.
No status files or accepted ADR bodies are changed by the design task.

## Dependency and separation contract

Original Sales baseline `d4746d7d` had QL0/QL1a internal Actions. On 2026-10-27, #30's bounded
read adapters and generic cross-company 404 integrated at `2f74f66bd24d04015d53a919cf24f16f894cbe80`
([integration record](https://github.com/kaleemnadeem16/erp/issues/30#issuecomment-6436238275)).
The existing `QuotationController`, `ShowQuotationDraftRequest`, `PreviewQuotationRequest`,
Resources and `QuotationDraftReviewApiTest` are the QA3 adaptation targets; no parallel HTTP
implementation. Agent A owns #30 and any separately authorised write adapters. Its later write
proposal is not delivered code. #45 supplies a design, not an expansion of #30 or its holder recipe.
The read routes remain STAGED; integration supplies no record-level access or activation authority.

[0138](../decisions/0138-authorise-sales-and-document-duties-with-explicit-scope-and-grants.md)
and [R9 rollout](phase-4-r9-permission-rollout.md) still gate scope/registration/holders/mandates.
[0139](../decisions/0139-bind-sales-authority-limits-approvals-and-execution-to-exact-evidence.md) keeps
execution, maker/checker, request replay and independent sensitive authority intact.
[0140](../decisions/0140-publish-sales-measures-and-dated-forecasts-with-persistent-trust.md) requires scoping before
aggregate/export. [Quotation lifecycle](../domain/quotation-lifecycle.md) keeps family/revision,
CRM association, issue snapshots and current access distinct. This is an operational-authority
extension, not a commercial revision, R6 acceptance, R7 handover or retrospective attribution edit.

The [requested primary-documentation comparison](../domain/quotation-record-access-comparison.md)
is now delivered. Review its observations and divergences, resolve the owner-approval components
of OD1–OD7 and record the owner-approved decision before coding the new durable semantics. An accepted architecture is still
not permission to implement, register names, grant real users or expose a UI.

## Smallest reviewable slices, in order

### QA1 — Ownership and shared record-scope contract (disabled)

Dependencies: reviewed #45 design, owner OD1/OD2/OD4 decisions and accepted ADR extension as needed.
Deliver additive family owner/unit/generations, ownership-event integrity, immutable creator,
`ReassignQuotationOwner`/`ChangeQuotationAccessUnit` and initial event in `CreateQuotationDraft`.
Deliver structured RecordScope variants and supported-resource adapter registration. Keep the new
assignment duty staged and all quotation duties unavailable publicly. No grants, sharing or UI.

Reviewable sub-commits: (a) schema/event guard and baseline quarantine; (b) shared scope resolver
and compatibility validation; (c) owning Actions and creator/owner parity tests. Do not expose a
restricted mode between commits. Preserve unrelated modules' resolver semantics and CRM refusal.
No default grant, inferred historical owner, automatic Opportunity-owner copy or automatic unit.
An approved legacy capture mapping is separate from schema deployment and records truthful capture.

Exit: raw integrity tests, all old RecordScope/warehouse/CRM regressions, active/inactive/foreign
owner tests, creator immutability and later-revision inheritance pass. Company-wide recovery requires
explicit hypothetical test holders; no live grant recipe is applied.

### QA2 — Manager/team resolution and delegation foundation (disabled)

Dependencies: QA1, owner OD3/OD5/OD6 and reviewed unit-based oversight choice (or a separate
manager-relationship design if selected). Reuse existing active descendant traversal; no Sales team
or manager-role shortcut. Add security epochs/fence and complete Administration/role/unit/status/
bootstrap writer integration **before** shares can be considered effective. Add grant/revocation
schema, immutable evidence and guarded Actions with pinned epochs and exact replay.

Sub-commits: (a) shared fence/epochs and writer/trigger manifest; (b) effective unit/ownership/share
SQL compiler; (c) grant/revoke Actions and safe history/selector projections. Each remains STAGED;
no intermediate runtime permission activation. Dedicated scope validation must reject mixed roles
whose other duties do not support OWN variants. Register no new live permissions for test fixtures.

Exit: no transitive/escalating share, no authority after suspension/expiry/revocation/epoch change,
no revived old grant, full tenant-reference and concurrent writer/read tests. The manager result is
explained by explicit grants and units, not a person's job title. All sources of security mutation
have a treatment; an uncovered writer is a blocker, not a future hardening task.

### QA3 — One policy across QL1b Actions and adapters (still STAGED)

Dependencies: QA1+QA2, #30 correction/integration and actual read/write adapter inventory. Replace
company-wide quotation guards with the single policy in read, preview, update and receipt replay;
extend creation and future revision/cancel operations without changing their lifecycle meanings.
Enforce Request and Action checks. Use scoped route resolution so existing/foreign/hidden IDs have
the same 404 after the capability gate; do not fetch privileged lines before visibility checks.

Sub-commits: (a) direct Action/predicate and source/field guards; (b) existing #30 detail/preview
HTTP adapters; (c) authorised write adapters, only if separately assigned; (d) bounded list/search
adapters if separately authorised. Do not add a list merely because the predicate exists.

Create a `QuotationAccessSurfaceCoverageTest` registry for **every** quotation consumer, with
path, exact operation/duty, predicate, projection and tests. Existing surfaces must enforce it;
future export/report/history/issue/document adapters stay absent or explicitly disabled until
wired. No authenticated-only catch-all route or unimplemented export claiming coverage.
Repositories/scheduled jobs/export chunks must use the same query policy at execution and delivery.

Exit: HTTP/direct parity, all filtered list/count/sort/include/search/report/export fixtures agree
with the scoped loader, view-only cannot preview, denied lifecycle errors disclose no hidden record,
replays do not return cached privileged JSON. Existing CRM/source guards still act independently.
Generated client regeneration for new/changed backend schemas is mandatory even before UI; no
hand-edited client. The later QA5 slice owns actual UI, not delaying necessary API drift checks.

### QA4 — Reviewed holder recipe, adoption and activation

Dependencies: all preceding tests, complete surface/writer manifests, R9/AU registration and
origin-ledger delivery, actual owner-reviewed company/holder inventory and authorised deployment.
First capture/review legacy owner/unit mappings; unresolved families stay in the recovery queue.
Inventory every role/direct/mandate conferring assignment: one unrestricted path defeats OWN.
Do not replace a limited role grant with an unrestricted direct copy during migration or sync.

Proposed recipe for owner review only:

| Principal duty | Proposed explicit rights/scope; no real assignee inferred |
|---|---|
| Ordinary responsible preparer | view + prepare, each OWN or reviewed OWN_OR_DELEGATED; no management/issue/cost/approval duties inferred. |
| View-only collaborator | view with explicit compatible record scope; VIEW share when needed; no prepare/preview. |
| Team supervisor | independently approved view/prepare MY_UNITS, active membership in the reviewed parent/leaf unit; optional assignment/delegation/revocation only if approved. |
| Scoped access governor | proposed assign/delegate/revoke as required, independent view/prepare and non-delegated family scope; no rights derived from a received share. |
| Orphan/leaver recovery holder | explicit company-wide view + assign/revoke with company/approval provenance; not an admin/Manager name bypass. |
| Existing full-access mandate holder | existing verification plus reviewed quotation company/scope proof before status activation/sync. A count-of-permissions, role name or `covers()` alone is insufficient. |

Apply only approved registration/assignment/scope migrations with a provenance ledger. Re-run is
idempotent; retain legitimate independently assigned rights. Public activation changes the existing
catalogue only after all requirements and staging sign-off, and advances security epochs. Test
`permission:register`, administrator re-sync, role presets, demo/bootstrap and mandate consumers;
none may produce unrestricted rights before the reviewed scope recipe. No hidden service actor
may bypass the recipient/grantor checks. No real-principal data is collected in this planning task.

Backout: disable relevant entry points/workers first, revoke only unchanged migration-introduced
assignments, retain historical grants/ownership/receipts, and refuse destructive schema down.
Never silently downgrade OWN to ALL or erase a scope limit. Empty-schema cycle is insufficient:
verify the populated safe refusal and upgrading existing companies/grants too.

### QA5 — Generated client and usable UI

Dependencies: QA3 API/client contract, QA4 approved supported rollout and exact staging identities.
Expose owner (separate creator), scoped list/detail, view-only safe metadata, prepare-only preview,
explicit unit/owner transfer, named recipient and level, optional start/end, revoke/expired state,
and access history only at its authorised projection. Use existing shell, selectors and sheets.
No client-supplied allow flags, financial operands in access diagnostics or shares-as-permission UI.

Deliver denied/loading/empty/error/stale/conflict/success, own/team/delegated explanations, inactive
owner recovery, mobile/no-overflow, light/dark/accessibility and permission-aware actions. A new
family copy warns/indicates no copied shares without altering commercial provenance. Unit manager
changes reflect server reach; browser filters cannot cache an old permission verdict. Demo fixtures
are synthetic and use ordinary approved seeding patterns, never production assignment or real names.

Exit: regenerate client; core-api/core-ui/apps-main typechecks and build; Playwright journeys for
ordinary owner, view-only delegate, preparer delegate, team supervisor, disabled/expired/revoked
recipient, no-unit and foreign user. Link UI only after it performs a real supported workflow.

## Exact proposed verification matrix

| New/extended test target | Cases required before implementation handoff |
|---|---|
| `QuotationOwnershipTest`, `QuotationOwnershipStorageTest` | New/default/explicit owner; creator unchanged; active/foreign target; baseline capture truthful; null legacy quarantine; raw FK/event/projection/truncate attacks; same-family revisions and new-family copies; no Opportunity inheritance. |
| Extend `RecordScopeTest` and Administration scope validation tests | Same-duty union, role/direct mixtures, ALL precedence, no-grant NONE, own vs creator, OWN_OR_DELEGATED opt-in, unsupported mixed role, no-unit and cross-company membership; old warehouse and CRM parity. |
| `QuotationTeamAccessTest` | Parent/descendant, leaf/sibling, archived unit, multiple owner memberships, explicit unit change, member removal/manager movement, null unit and explicit recovery; a Manager-named user with no grant gets nothing. |
| `QuotationDelegationTest`, `QuotationDelegationStorageTest` | VIEW/PREPARE asymmetry, missing recipient capability, capability removal, grantor loses rights, scope ceilings, no self/onward sharing, duplicates, future/exact-start/exact-end/null end, revoke, transfer, suspend/reactivate and permanent epoch invalidation; immutable raw evidence. |
| `QuotationAccessConcurrencyTest` | Two DB connections: revoke/expiry/status/role/unit/owner change versus preview/update/export/replay; security-fence order, no stale hydrated actor/permission cache, no partial receipt/event; one decision instant and new-operation stale refusals. |
| Extend integrated `QuotationDraftReviewApiTest`; Action tests | Missing duty403 versus unknown/foreign/hidden404 parity, view-only preview denial, state/source errors only after access, protected fields absent, direct Action checks, current-authority replay. Do not change #30's correction in this design task. |
| `QuotationAccessSurfaceCoverageTest`, leakage/query-equivalence tests | Detail/update/preview, includes/lookups/counts/search/order/pagination, later revisions/history, cached/jobs/exports/reports/attachments; SQL scoped before aggregate; forbidden filters cannot infer hidden totals; batch/delivery recheck. Future unsupported surfaces absent/disabled. |
| `QuotationAccessRolloutTest` | STAGED handmade-row denial, exact registration ownership/exclusions, per-company grant origin/scope, verified mandate proof, rerun/backout, legacy/quarantined records and staging personas. |
| Existing architecture ratchets | `WriteRouteGuardTest`, `OpenReadRoutesTest`, `RecordScopeCoverageTest`, `PermissionCheckRegistrationTest`, `CompanyScopedForeignKeyTest`, `CompanyScopedUniqueIndexTest`, `ControllerActionBoundaryTest`; register changed surfaces in the same implementation commit. |

Implementation validation: focused Sales/Scope/Administration regressions, Pint/PHPStan, migration
forward→rollback→reapply plus populated refusal, codegen drift, frontend and affected browser tests
where applicable; full suite/actual integrations and exact-SHA CI before owner acceptance. A
planning document/link check is not evidence any of those implementation behaviours are delivered.

## Related

- [Primary-source comparison and OD1–OD7 approval status](../domain/quotation-record-access-comparison.md)

- [Domain index](../domain/README.md) · [Architecture and open owner decisions](../domain/quotation-record-access-design.md)
- [Storage and Action specification](../domain/quotation-record-access-storage.md)
- [R9 rollout](phase-4-r9-permission-rollout.md) · [Testing standards](../architecture/testing-standards.md)
- [Pre-push checks](../architecture/pre-push-checks.md) · [Quotation lifecycle](../domain/quotation-lifecycle.md)
