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
checks them. The existing inventory approval Actions correctly follow this pattern.

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
6. generated UI/client code does not invent permission strings independently.

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

- Existing Estimating and some master-data CRUD still needs its capability retrofit. Purchasing
  operational writes and document reads now have capability checks, but some shared reference
  reads remain authenticated-only by design; see [authorisation retrofit](authorization-retrofit.md).
- Purchase orders have DRAFT → ISSUED with no submit/approval state; issue records neither actor
  nor approval chain.
- No generic approval-policy/step/decision schema exists; the `workflow_states` sketch in
  `extensibility.md` is not implemented and one `required_permission` cannot model multiple
  independent approvals.
- No department/organisation-unit membership or record-level visibility system exists.
- The authenticated-user response exposes effective capabilities and permission-aware routing is
  available, but many older feature screens still need the incremental gating retrofit below.
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

| Area | Current boundary | Retrofit requirement |
|---|---|---|
| Foundation and master data | Most CRUD is authenticated-only | Review view/create/update/archive, lookup/export and sensitive fields |
| Purchasing | Policy/history controls exist; ordinary PO/receipt CRUD is mostly authenticated-only | Add document capabilities now; submission/approval/issue separation follows Q27/Q28 |
| Inventory | High-risk workflows are strongest; ordinary read/master surfaces vary | Audit every remaining endpoint, list/show scope and frontend action |
| Estimating | Overhead policy controls and ADR 0057 vocabulary exist; most existing CRUD is authenticated-only | Retrofit each feature as its next vertical slice is touched |
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
- [Approval workflows](approval-workflows.md)
- [Extensibility](extensibility.md) — config-driven workflows and progressive capability modes
- [Multi-tenancy](multi-tenancy.md) — company isolation is separate from record visibility
- [ADR 0028](../decisions/0028-dynamic-namespaced-permissions.md)
- [Material resolution](../domain/material-resolution.md) — Phase 3 capability/field implications
- [Access administration](../domain/access-administration.md) — operable member lifecycle, roles
  and assignments
- [ADR 0062](../decisions/0062-company-member-and-access-administration.md) — invitation and
  additive-role decision
