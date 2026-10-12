[← Architecture](README.md)

# Authorisation

How capability permissions, record visibility and workflow authority fit together. Read this
before adding or changing any business action, endpoint, screen, role, approval or sensitive
field—not only when a feature appears to be “about permissions.”

[ADR 0028](../decisions/0028-dynamic-namespaced-permissions.md) chose the RBAC mechanism.
[approval-workflows.md](approval-workflows.md) defines the separate maker/checker and multi-stage
approval boundary.

## Four separate questions

Every feature must answer all four. Passing one does not imply the others.

| Layer | Question | Mechanism |
|---|---|---|
| Tenant boundary | Does the record belong to the current company? | `company_id`, scoped models and tenant-safe FKs |
| Capability | May this user perform this kind of action? | Spatie permission through Laravel `can()` |
| Data scope | Which records and sensitive fields may this user see or affect? | Laravel policy + query scope/domain scope |
| Workflow authority | May this user decide this step on this document now? | Permission + assignment/scope/limit + state + separation-of-duties guard |

Authentication alone answers none of the last three. Hiding a button answers none of them on the
server.

## RBAC mechanism

`spatie/laravel-permission` 8.3 is installed with Teams enabled and Laravel Gate integration:

- permissions represent capabilities checked by application code;
- roles bundle permissions and are assigned to users;
- direct-to-user grants may supplement roles for exceptional cases;
- application code checks permissions, never role names.

Direct grants are additive. They do **not** deny or override a permission inherited from a role.
Use small, accurately assigned roles rather than expecting a direct revocation to defeat a broad
role. Explicit-deny semantics would be a separate design and are not currently supported.

Official guidance also recommends assigning permissions to roles in the normal case and using
direct permissions sparingly:
[Spatie roles versus permissions](https://spatie.be/docs/laravel-permission/v8/best-practices/roles-vs-permissions).

## Permission vocabulary

Use stable business capabilities, normally `module.resource.action`:

```text
purchasing.purchase_order.view
purchasing.purchase_order.create
purchasing.purchase_order.submit
purchasing.purchase_order.approve
purchasing.purchase_order.issue
estimating.internal_cost.view
sales.quotation.price_override
```

Existing two-part names such as `estimating.overhead-policy.manage` remain valid. New names should
use the extra resource part when a module has more than one protected resource.

Rules:

- Name what the user can do, not a role (`purchase_order.approve`, not `manager`).
- Separate `view`, `create`, `update`, `submit`, `approve`, `post/issue`, `reverse`, `override`,
  `manage-policy` and `view-history` whenever those powers carry different risk.
- Do not create one vague `manage` permission for a lifecycle with independent duties.
- Do not encode user IDs, department IDs, record IDs or monetary thresholds into permission names.
  Those are assignments/policy data.
- Do not hardcode role checks such as `hasRole('admin')` in domain code.
- Wildcard permissions are disabled. Keep them disabled unless a later ADR defines safe admin
  semantics; `*` means all, not pattern discovery.

## Stable capabilities, dynamic company configuration

“Dynamic permissions” means companies dynamically configure assignments around a stable,
application-owned capability vocabulary. The enforcement points themselves cannot be arbitrary
company-created strings.

| Stable application contract | Company-configurable data |
|---|---|
| Capability names and their backend enforcement points | Role names and permission bundles |
| Which fields are sensitive | Role and direct-user assignments |
| Tenant isolation and immutable workflow evidence | Approval-required/tolerance policy |
| Supported record-scope kinds | Organisation memberships and scope grants |
| Separation rules that a policy declares mandatory | Authority limits, approver counts and routing |

This lets a small company give one Owner role every ordinary module capability and configure no
approval for normal work. A larger company can distribute the same capabilities across entry,
review and approval roles, constrain them to organisation units and activate multi-step policy.
No module-specific “small company mode” or role-name checks are required.

## Roles and direct grants

Roles are company-configurable bundles such as Buyer, Purchasing Approver or Storekeeper—not
hardcoded application branches. A role may contain both read and action permissions. A user may
hold several narrow roles.

Direct grants are for exceptional additions, temporary cover or a genuinely unique duty. They
must be visible in administration/audit UI. Avoid routine per-user permission matrices: they
become impossible to review and do not provide deny semantics.

### Delegated administration

A future department/organisation-unit administrator may assign only an explicitly delegable
subset inside units they administer. Delegation cannot cross companies, exceed the delegator's
administration mandate or confer protected tenant/finance administration. Every grant, change and
removal records actor, time, reason and optional expiry. Q26 owns the organisation model and
delegated boundary; role assignment alone must not imply authority to administer other users.

## Teams and organisational scope

> **Decided 2026-10-06 ([ADR 0089](../decisions/0089-organisation-units-and-record-scope.md)):** organisation units are a separate,
> company-defined tree with memberships; Spatie Teams stays the company boundary; scope is a limit ("only my units") on an existing
> assignment, resolved by `RecordScope` and applied explicitly at each API boundary. Applied so far to inventory by warehouse; see the
> [domain doc](../domain/access-administration.md#organisation-units-and-record-scope-adr-0089-q26). The text below is the original problem statement.

Spatie Teams is enabled, and `SetPermissionsTeam` currently sets the active team ID to the current
`company_id`. Therefore it currently provides **company-scoped role/direct assignments**.

That is useful groundwork, but it is not yet department-level or warehouse-level access. The
application has no Department/Organisation Unit model, membership model, active organisational
context or record-scope grants. One Spatie team dimension also cannot simultaneously mean company,
department and warehouse without an explicit organisational design.

ADR 0028 correctly avoided a no-Teams retrofit, but its statement that Teams is the department
seam is not a complete department solution. [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope)
must decide whether Teams remains the company assignment context while policies use separate
organisation-unit memberships, or whether the active team identity is migrated to a real
organisation-unit model.

See the official team-context behaviour:
[Spatie Teams permissions](https://spatie.be/docs/laravel-permission/v8/basic-usage/teams-permissions).

## Record and field visibility

A permission grants a capability across a class of work. It must not silently mean “all records.”
For example, `purchasing.purchase_order.view` may still resolve to one of:

- own records;
- assigned records;
- records for the user's department/site/warehouse;
- all company records.

Use Laravel policies for record decisions and a matching reusable query scope for collections.
Never fetch every company record and filter it only in React. Laravel recommends policies for
organising model/resource authorisation:
[Laravel authorisation](https://laravel.com/docs/12.x/authorization).

Sensitive fields can require separate capabilities even when the record is visible—for example
internal cost, margin, supplier price, payroll rate or bank details. Mask/omit those fields in the
API resource; a hidden frontend column is not security.

The scope decision must be applied consistently to list, count, search, export, lookup/select,
show and mutation queries. Otherwise a “restricted” user leaks data through totals, autocomplete
or an ID-based endpoint.

Routine cross-department work should use organisation membership, assigned-record access or
temporary task-scoped access rather than one role per pair of departments. “Write-only” is not a
safe general access level: create/update must include the minimum read projection needed to perform
and verify the work, while unrelated records and sensitive fields remain hidden.

## Where checks live

- **Route/Form Request/Policy:** early HTTP rejection and record visibility.
- **Action:** authoritative business capability and workflow guards, because CLI, jobs and future
  integrations may call Actions without HTTP.
- **Database/domain invariants:** tenant safety, immutable facts and constraints that must survive
  every caller.
- **Frontend:** consumes effective permissions/scopes to hide or disable unavailable actions and
  explain why; it is usability, never the enforcement boundary.

High-risk transitions should be checked in the Action even if middleware or a Form Request also
checks them. Use `App\Support\ActionAuthorization::require($actor, 'module.capability', 'what it is doing')`
for a plain capability check: it refuses with the domain error Actions already use, so every guarded Action reads the same way. The existing inventory approval Actions correctly follow this pattern.

## Registration and maintenance standard

`PermissionRegistry` is the deployable aggregator. Each owning module contributes its vocabulary
from its service provider; `permission:register` creates the combined rows idempotently for the
current team. Tests prove module ownership, fresh-install/admin registration and duplicate-safe
aggregation. This implements ADR 0028's module-owned-registration direction without making a
central permission array a recurring merge hotspot.

Required automated controls:

1. every literal permission checked by backend code exists in the registered vocabulary—enforced
   by `tests/Architecture/PermissionCheckRegistrationTest.php`, which scans `app/` for every
   authorisation-sink call (`->can()`, `->authorize()`, `hasPermissionTo()`, `hasAnyPermission()`,
   `hasAllPermissions()`, `Gate::*`), including the one-hop local-variable indirection used by
   `RequestNegativeStockOverride`, and fails if any literal permission name it finds is not in
   `PermissionRegistry`;
2. every registered permission has an owner/module and is either exercised or deliberately
   reserved;
3. protected Actions have allowed/denied tests and, where relevant, scope and same-actor tests;
4. API tests prove list and show endpoints apply identical record scope;
5. the authenticated-user response exposes effective permissions and any safe scope labels needed
   by the SPA—never confidential policy internals;
6. generated UI/client code does not invent permission strings independently;
7. every API write route has a visible capability guard or a recorded reason it needs none—enforced by
   `tests/Architecture/WriteRouteGuardTest.php`, which also fails any write route whose Action has no gate of its own (the ratchet list
   is empty; an Action called without HTTP must stop itself — see `ActionAuthorization`) **and** has a capability check at the request layer
   or in the controller, so an HTTP caller gets a 403 and not a domain error (`REQUEST_WITHOUT_GATE`, also empty and to stay empty).

## Per-feature authorisation checklist

Every new or changed action, endpoint, screen and background command must explicitly record:

1. Is authentication sufficient, or is a capability permission required?
2. Are read, create, update, submit, approve, issue/post, reverse, override and history distinct?
3. Is the record visible to all company users, own/assigned users, or an organisational scope?
4. Are any fields more sensitive than the surrounding record?
5. Does the transition require a different actor, several approvers, a threshold or expiry?
6. Where is the backend check repeated for non-HTTP Action callers?
7. How does the frontend learn and present the effective capability?
8. Are the permission, allowed/denied path, record scope and separation rule tested?
9. Is the permission registered by the owning module and included in deployment registration?
10. Do the domain doc, module README and journey name the permission and approval consequence?

“No new permission” is allowed, but it must be a conscious answer during review rather than the
result of forgetting the question.

### Build rules every new feature follows

The checklist asks the questions; these are the answers the [access audit](access-security-audit.md)
taught us to build in from the first commit. A feature that skips one is not finished, and a new
surface is never shipped "authenticated-only, to be fixed later".

| # | Rule | What proves it |
|---|---|---|
| R1 | **Guard twice — HTTP-only security is insufficient.** A guard at the HTTP boundary protects only the HTTP route; jobs, console commands, seeders and other Actions reach the same mutation without it. Every write has a capability check in its Form Request/controller *and* inside its Action (`ActionAuthorization::require`). A console command, job or seeder passes an explicit actor; a workflow that has already authorised its actor under its own capability uses a `forWorkflow` entry point, never a bypass. | `WriteRouteGuardTest` fails an unguarded write route; its exception list is empty and must stay empty. Add a denied-actor test for the Action (pattern: `MasterDataActionGuardTest`). |
| R2 | **Split what people need separately.** Read ≠ write ≠ approve/post ≠ reverse ≠ history; quantity ≠ valuation/cost; operating a record ≠ configuring its policy. Name capabilities by duty, never mechanically (`*.view`/`*.manage` per table). | Each capability is registered by the owning module and exercised alone and absent in tests (pattern: `StockVisibilityTest`). |
| R3 | **Protect the data, not the button.** A sensitive field (prices, costs, margins, GL/credit/terms, approval amounts, policy rates) is omitted from the response unless the caller holds its capability — in lists, `show`, nested `include`s, lookups, evidence/audit payloads, print projections and any export. Omit; never return zero. The UI hides the control as a convenience only. | Response-body tests, including `assertStringNotContainsString` on nested includes and with the capability absent (patterns: `MaterialPreviewApiTest`, `ApprovalEvidenceVisibilityTest`). For a field that must never leave the server, also add its key to `ProtectedFinancialFieldsNeverLeakTest::FIELDS`: it scans every readable response, at any depth, as a user holding everything except the opening capability. |
| R3a | **A new list endpoint is gated, or named as open on purpose.** `OpenReadRoutesTest` sends a user with no capability to every id-less API read; anything that answers 200 must be listed with its reason (shared reference data, the caller's own data) or be marked pending review in the audit tracker. Prefer gating by workflow duty (ADR 0074 shape) over adding to the list. | `OpenReadRoutesTest` fails the new route, and fails a listed route that is no longer open. |
| R3b | **A new surface that carries records says what scope it has.** Record scope is applied surface by surface, so a limited person is protected only where it is applied. Apply it at the API boundary (list, count, search, filter, sort, include, show, and the entry of writes; out of scope is a 404), never as a global model scope, and declare the route in `RecordScopeCoverageTest` (scoped, refused when limited, shared reference, or pending with the reason in the audit tracker). | `RecordScopeCoverageTest` fails an undeclared stock-bearing route; extend its pattern when another module adopts scope. Prove each scoped surface on response bodies as `WarehouseRecordScopeTest` does, including a limited person in no unit. |
| R4 | **Filters, sorts and includes obey the same rule.** A caller who cannot see a field cannot filter, sort or include by it (a monetary sort without the capability is refused), or the value can be inferred. Enforce at the request boundary, before the query runs. | A denied-caller test per filter/sort/include that touches a protected field. |
| R5 | **Ship the grant with the check.** A new fail-closed capability needs registration, a grant migration for everyone who already legitimately used the surface (never a count-only or unrelated role), and the administrator re-sync. Never grant a broad role to make a test pass. | `permission:register` + `erp:sync-admin-permissions` in the release steps; a rollout test for the migration (pattern: `StockVisibilityRolloutTest`: grants, idempotence, the exception, per-company). Verify in staging per the [safe rollout rule](#safe-rollout-rule). |
| R6 | **Tenant isolation is tested by id.** Every company-scoped route is proven unreachable for another company's record, by id, on `show`, update and any action route — not only absent from the list. | A cross-company test per new resource (pattern: `AccessAdministrationTenantIsolationTest`, the numbering isolation tests). |
| R7 | **No side doors.** Print, quick-print, CSV/export, download and notification payloads render only what the screen's projection already allows. A new export is a new surface and gets its own review row. A staged or internal Action with no entry point gets a test that fails the day one appears. | The audit tracker's coverage map names the surface; `ImportStagingHasNoEntryPointTest` is the pattern for "must stay unreachable". |
| R8 | **Do not leak existence.** Sign-in, recovery and lookup endpoints answer identically whether or not an account or record exists; throttle them. | Identical-body test (pattern: forgot-password, A7). |
| R9 | **Maker/checker is a domain invariant.** Separation of duties is checked against stored actors in the Action, not assumed from role assignment; approval evidence follows the subject document's own field policy plus an explicit need-to-know rule. | Same-actor refusal test; a new approval subject implements `canViewEvidence` ([approvals.md](../domain/approvals.md)). |
| R10 | **The SPA learns capability from the server.** Effective permissions come from `GET /api/v1/user`; navigation and controls use the shared screen-access map ([ADR 0066](../decisions/0066-capability-driven-screen-navigation.md)). A bookmarked URL to a screen the user cannot use is blocked, not just unlinked. | A browser spec for an allowed and a denied/read-only user. |
| R11 | **Say it in the docs, in the same commit.** The domain doc and journey name the capability and its approval consequence; the audit tracker's coverage-map row for the module is updated; STATUS and the log are updated only at a workstream milestone ([ADR 0095](../decisions/0095-status-is-short-current-and-purpose-limited.md)). | Review checklist item 10. |
| R12 | **Enforce an advanced mode where it cannot be bypassed, and fail closed.** When a mode makes new evidence authoritative, the database (triggers, deferred constraints) refuses writers that do not carry it, and a writer matrix lists every code path and its treatment; an unsupported writer is refused with a message, never partly supported ([domain-integrity.md §5](domain-integrity.md#5-advanced-modes-are-enforced-in-the-database-and-fail-closed)). | `StockWriterMatrixTest`; a raw-SQL bypass test; a parity test for items that never opt in. |
| R13 | **Say what a limited person gets, per surface.** Out-of-scope *records* are `404` (existence is not confirmed); a surface that cannot be narrowed to the person's scope answers `403` (`REFUSED_WHEN_LIMITED`); a list is narrowed. Record the choice in the coverage map and in the ratchet, and prove it on response bodies. | `RecordScopeCoverageTest`; `WarehouseRecordScopeTest`. |
| R14 | **Reuse before inventing a capability.** Ask first whether an existing capability already names the duty (ADR 0094 reused `quality-disposition.post` rather than adding a package QA permission). Add a new capability only for a genuinely separate duty or separation of duties; name it by duty, register it in the owning module's vocabulary, and apply R5 (grant continuity for people who already do the same duty, deliberate grants for new or one-way duties, role recipes in the domain doc). | `PermissionCheckRegistrationTest`; a rollout test. |

**Before you open a pull request**, run the guards that catch most regressions:
`tests/Architecture/WriteRouteGuardTest.php`, `PermissionCheckRegistrationTest.php`,
`CompanyScoped*Test.php`, `ControllerActionBoundaryTest.php`, `ImportStagingHasNoEntryPointTest.php`, `RecordScopeCoverageTest.php`, `StockWriterMatrixTest.php` and
`tests/Feature/Support/OpenReadRoutesTest.php`,
plus your own module's allowed/denied suite. If you add a route, a capability, a Resource field or an
export, also add its row to the [audit coverage map](access-security-audit.md#coverage-map-and-next-inspection).
These tests prove a guard is *present*; whether it is the *right* one is a review question.

## Current implementation assessment

### Strong foundation already present

- Namespaced permissions, roles, additive direct grants and company-scoped Teams plumbing.
- Operable company member and role administration: invitation, suspension/reactivation, role CRUD,
  role/direct assignment and effective-access inspection, protected by separate
  `administration.*` capabilities and anti-escalation/self-lockout guards.
- Module-owned permission vocabularies aggregated by the production-safe registry.
- Production-safe, idempotent permission registration, proven complete against every literal
  permission check in `app/` by an architecture test.
- Action-level checks for high-risk Inventory workflows.
- Proven maker/checker separation for cutover, negative openings, negative-stock approval and
  physical-count posting.
- Proven two-distinct-approver handling for high-risk stock recalculation, including expiry.
- Separate permissions for policy management and audit-history viewing.

### Not yet sufficient for full ERP access control

- Existing Estimating CRUD, Items/Categories and Purchasing operational writes now have
  capability checks. Shared reference reads remain authenticated-only by design; the
  Item/Category GL-account field boundary still needs a Finance decision. See
  [authorisation retrofit](authorization-retrofit.md).
- Purchase orders can route through submit → approve → issue under the shared approval engine
  ([ADR 0070](../decisions/0070-shared-configurable-approval-engine.md)), but nothing is asked until a
  company switches approvals on (mode `NONE` by default). Recalculation, negative-stock and cutover
  approvals have not been migrated onto the engine yet; company PO policy is still Q28.
- Every API write route has a capability guard at the request boundary and inside its Action
  (`WriteRouteGuardTest`, empty exception list). That proves a guard is present, not that the
  capability is the right one; the [audit](access-security-audit.md) tracks what is still unreviewed.
- Open owner decisions: Finance-owned field visibility (Q34), approval evidence exception and
  authority-ceiling reads (Q35), bearer-token lifetime (Q36). Negative-stock override request reads
  are not yet gated by workflow duty.
- No department/organisation-unit membership or record-level visibility system exists (Q26); stock
  and other read capabilities are company-wide until it is decided. Nothing has been verified in
  staging, only in development.
- The authenticated-user response exposes effective capabilities and permission-aware routing;
  capability-gated operational controls have landed for the retrofit slices below. Future
  screens must keep API and UI authority aligned.
- There is intentionally no hardcoded full role catalogue: application modules own stable
  capability names, while each company builds reusable roles from the registered vocabulary in
  Access Administration ([ADR 0062](../decisions/0062-company-member-and-access-administration.md)).

The answer is therefore: **the RBAC base is good and reusable, but it is not by itself enough for
multi-stage approvals or restricted data visibility.** Those can be added without replacing
Spatie, provided they are modelled as policies/scopes and approval facts around the RBAC core.

## Incremental retrofit of already-built features

Existing authenticated-only features are **not grandfathered**. Module-owned registration changes
where a capability is maintained; it does not retroactively add enforcement, field filtering or UI
gating. The retrofit is an explicit implementation backlog:
[authorization-retrofit.md](authorization-retrofit.md) tracks the audited, per-feature detail
behind the summary row below — read/write permission per endpoint, missing filter UI, unprotected
sensitive fields and a risk-ordered task checklist — for Items/Categories, Purchase
Orders/Receipts and existing Estimating CRUD so far.
The broader [access and sensitive-data audit](access-security-audit.md) records confirmed gaps
outside that original scope and distinguishes first-pass observations from completed reviews.

| Area | Current boundary | Retrofit requirement |
|---|---|---|
| Foundation and master data | Item/Category writes capability-gated; reference reads shared | Decide and project GL-account fields; review future lookup/export surfaces |
| Purchasing | PO/receipt writes and document reads capability-gated; commercial fields projected | Submission/approval/issue separation follows Q27/Q28 |
| Inventory | High-risk workflows and valuation reads have capability boundaries | Reapply valuation boundary to future exports and review new endpoints |
| Estimating | Existing CRUD writes capability-gated; list filters exposed | Scale 100-option lookup selectors and review new cost surfaces as added |
| Sales, Jobs and Finance | Not yet implemented | Start permission-complete; do not create an authenticated-only baseline to fix later |

Work proceeds one feature slice at a time, not as one risky repository-wide switch:

1. inventory every endpoint, Action, background entry point, screen, lookup, export and sensitive
   field in the slice;
2. choose the smallest meaningful stable capabilities—never generate CRUD names mechanically;
3. register them in the owning module and prepare company role/direct assignments;
4. enforce capability, record scope, field projection and workflow authority on the backend;
5. expose effective capability safely and implement matching navigation, controls, disabled-state
   explanations and every supported backend filter/sort/include in the UI;
6. prove allowed, denied, cross-company, scope, sensitive-field and non-HTTP Action paths;
7. update the module README, domain/phase docs, product journey and STATUS in the same commit.

### Safe rollout rule

Adding a permission check is fail-closed: after enforcement, a user without the newly assigned
capability loses access. Therefore a retrofit commit/deployment must include the permission
vocabulary and an explicit assignment/backfill or administrator rollout procedure before relying
on the check. Never assume an existing “admin” automatically receives permissions added after that
user was created; `--permissions=all` describes creation-time grants, not a permanent wildcard —
confirmed as a real production gap on 2026-09-26, not just a theoretical one: the deploy job ran
`permission:register` on every release (creating each new `Permission` row) but nothing granted
those new names to the administrator created once, earlier, via `--permissions=all`, so the live
admin fell further behind the vocabulary with every feature slice that shipped its own
permissions. `erp:sync-admin-permissions {email}` closes this — it re-grants the full current
vocabulary to a named administrator and is safe to re-run every deploy (see
[deployment.md](deployment.md#first-deploy-runbook)); the deploy job runs it right after
`permission:register` whenever the `ERP_ADMIN_EMAIL` repository variable is set. Local development
never hit this gap because `StockDemoSeeder` already syncs the demo login to the full registry on
every `demo:seed` run — the production path just had no equivalent.

**Sensitive duty exception — [ADR 0114](../decisions/0114-authorise-historical-attribution-correction-as-a-dedicated-duty.md).**
`sales.opportunity.attribution.correct` is not automatically added by a named administrator re-sync.
Include it only where an existing role is explicitly defined as genuinely full-access across all
registered capabilities and the target holds its eligible company assignment; role names, email and
current permission snapshots are insufficient. Otherwise exclude automatic inclusion, preserving
legitimate explicit grants and effective scope. Current unconditional all-registry helpers require
this narrow integration before the duty is rolled out; no global wildcard/admin-role system is implied.

Registration without enforcement is allowed as a clearly documented foundation/reserved
capability. Enforcement without assignment preparation, backend tests and usable UI is not. The
phase/module checklist must state which names are active now and which are deliberately reserved.

## Landmines

**Permission equals approval.** A permission makes someone eligible; it does not prove a specific
document was approved.

**One `approve` permission for every risk level.** Monetary/operational authority, required count
and department participation belong to approval-policy data, not a permission-name explosion.

**UI-only restriction.** Users can call the API directly. Backend policies and Actions remain
authoritative.

**Filtering only collection endpoints.** An unrestricted show/export/lookup endpoint defeats the
list filter.

**Self approval by accident.** Maker/checker separation is a domain invariant checked against
stored actors, not something a role assignment guarantees.

**Assuming direct grants can deny.** They only add effective permissions.

## Related

- [Authorisation retrofit backlog](authorization-retrofit.md) — the tracked, per-feature audit and
  task checklist behind "Incremental retrofit" above
- [Domain integrity standards](domain-integrity.md) · [Testing standards](testing-standards.md) · [Feature-completion checklist](feature-completion-checklist.md)
- [Access and sensitive-data audit](access-security-audit.md) — cross-module findings and staged
  remediation without a blanket access change
- [Approval workflows](approval-workflows.md)
- [Extensibility](extensibility.md) — config-driven workflows and progressive capability modes
- [Multi-tenancy](multi-tenancy.md) — company isolation is separate from record visibility
- [ADR 0028](../decisions/0028-dynamic-namespaced-permissions.md)
- [Material resolution](../domain/material-resolution.md) — Phase 3 capability/field implications
- [Access administration](../domain/access-administration.md) — operable member lifecycle, roles
  and assignments
- [ADR 0062](../decisions/0062-company-member-and-access-administration.md) — invitation and
  additive-role decision
