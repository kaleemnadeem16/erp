[← Architecture](README.md)

# Authorization

How access control works: dynamic roles, per-user overrides, and the seam that keeps future
department-level delegation cheap without building it now
([ADR 0028](../decisions/0028-dynamic-namespaced-permissions.md)).

**Read when adding a permission, a role, or gating a new action.**

## The mechanism

`spatie/laravel-permission`, used two ways at once:

- **Role-based** — a permission granted to a role, inherited by every user with that role.
- **Direct-to-user** — a permission granted to one specific user, overriding or supplementing
  their roles.

Both are native to the library — no bespoke RBAC layer.

## Naming convention

**Every permission is namespaced by module: `module.action`.**

```
jobs.approve
finance.tax.manage
receivables.retention.release
```

Never a flat name like `approve` or `manage-tax`. This is what lets permissions be grouped and
scoped later (e.g. "everything under `jobs.*` for this department") without a rename migration
across every existing assignment.

## Teams

Spatie's **Teams** feature is enabled from Phase 0, even though every row belongs to the same
team today (single company). It is the seam that department-level scoping will attach to later.

**Why now, not later:** turning Teams on after roles and permissions already exist means
migrating every existing assignment; turning it on before any exist is a config flag.

## What is explicitly deferred

- **Delegated admin** — a department manager granting permissions scoped to only their own
  department. Not built. The namespacing and Teams above are what make this an *addition* later,
  not a retrofit.
- **The actual role/permission list** — estimator, site supervisor, storekeeper, accountant,
  management, approval thresholds, etc. Phase 0 ships a minimal set sufficient to build against;
  the full definition is Phase 4 work, once real usage patterns exist to define it from.

## How modules register permissions

Modules and plugins register their own permissions rather than a central hardcoded list,
consistent with the container-bound-contracts pattern in
[extensibility.md](extensibility.md#container-bound-contracts) — a plugin adding a new action
also adds the permission that gates it, without editing core.

```php
// within a module's service provider
Permission::findOrCreate('jobs.approve');
```

`workflow_transitions.required_permission` (see [extensibility.md](extensibility.md)) is the
other place permissions get consumed — a config-driven workflow step names the permission it
requires, rather than the check being hardcoded per transition.

## Landmines

**Flat permission names.** Blocks scoping later without a rename across every assignment — see
[ADR 0028](../decisions/0028-dynamic-namespaced-permissions.md) for why this was decided now.

**Checking roles instead of permissions.** `hasRole('admin')` in application code hardcodes an
assumption about who has access; `can('jobs.approve')` stays correct as roles are redefined in
Phase 4 and beyond.

**Assuming single-team forever.** Code that queries permissions without going through Teams-aware
scopes works today by accident (only one team exists) and breaks silently the moment a second
team/department is introduced.

## Related

- [extensibility.md](extensibility.md) — plugin permission registration, config-driven workflow
- [ADR 0028 — Dynamic, namespaced permissions](../decisions/0028-dynamic-namespaced-permissions.md)
- Q7 — User roles and permissions *(mechanism resolved here; role list deferred to Phase 4)*
