[← Decisions](README.md)

# 0028 — Dynamic, namespaced permissions with per-user overrides, Teams-ready

- **Status:** accepted
- **Date:** 2026-08-10
- **Affects:** Phase 0 (auth & access), Phase 4 (full role/permission definitions)

## Context

[Q7](../OPEN-QUESTIONS.md) asked about roles and permissions. Phase 0 already planned
`spatie/laravel-permission` with a minimal role set, full definition deferred to Phase 4 — that
sequencing stands. What's newly decided here is the **mechanism**, prompted by the user's
requirements: permissions must be assignable both to roles and to individual users, and — not
needed today with a single customer, but required to stay cheap later — a department manager
should eventually be able to grant permissions scoped to just their own department, without
that needing to be built now. The eventual whitelabel/plugin direction
([ADR 0010](0010-core-plus-plugins.md)) means permissions also need to be something each module
or plugin can register, not a hardcoded central list.

## Decision

- `spatie/laravel-permission` covers both role-based permissions and direct-to-user overrides
  natively — no extra library needed for the "assign to a role, or to one specific person" case.
- Permissions are **namespaced by module** (`jobs.approve`, `finance.tax.manage`, …), never flat
  strings, so scoping-by-group is possible later without a rename migration across existing
  assignments. [ADR 0025](0025-configurable-tax-engine.md)'s tax-management permission already
  follows this convention.
- Spatie's **Teams** feature is enabled from Phase 0, even though unused at first (single
  company/team) — it maps naturally onto "department" scoping later, and retrofitting team
  support onto roles and permissions that already exist is the expensive path.
- The actual delegated-admin workflow (a manager granting permissions within their own
  department) is **explicitly deferred**, not built now. Namespacing plus Teams keeps that a
  future feature addition, not a retrofit.
- Modules and plugins register their own permissions rather than a central hardcoded enum,
  consistent with the container-bound-contracts pattern in
  [extensibility.md](../architecture/extensibility.md).

## Consequences

- **Positive:** one library, no bespoke RBAC engine, and it already covers today's actual
  requirement (per-user overrides) without waiting for the future one (delegation).
- **Positive:** namespacing and Teams cost little now and avoid the same "cheap now, painful
  retrofit later" trap already applied to retention ([ADR 0023](0023-retention-retainage-enabled.md))
  and base currency ([ADR 0024](0024-onboarding-base-currency-and-fiscal-year.md)).
- **Negative:** Teams adds a `team_id` dimension to every permission/role query even while every
  row belongs to the same team — small, accepted overhead.
- **Requires:** every permission defined from Phase 0 onward uses the `module.action` naming
  convention; Teams turned on in the spatie config from the first migration, not added later.

## Alternatives considered

**A fully custom RBAC engine** — rejected. Reinvents what spatie already provides and delays
Phase 0 in service of a delegation feature with no user yet.

**Defer Teams until department delegation is actually needed** — rejected, for the same reason
schema-cheap-now decisions were made for retention and currency: the retrofit cost on existing
role/permission data is real, the cost of turning it on now is not.

**Flat permission strings** — rejected. Blocks scoping later without renaming every existing
permission and re-auditing every assignment.

## Related

- [architecture/extensibility.md](../architecture/extensibility.md)
- [ADR 0025 — Configurable tax engine](0025-configurable-tax-engine.md) — first consumer of the namespacing convention
- Q7 — User roles and permissions *(mechanism resolved; full role/permission list still deferred to Phase 4)*
