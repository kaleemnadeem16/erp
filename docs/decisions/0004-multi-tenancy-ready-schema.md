[← Decisions](README.md)

# 0004 — Multi-tenancy-ready schema, single-org operation

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** every migration, every model

## Context

The system serves one organisation today. Multi-tenancy is not required now, but must be
achievable later "with little work".

Full multi-tenancy up front (tenant resolution, provisioning, per-tenant config, isolation
testing) is significant work with no current payoff. Ignoring it entirely means a very
expensive retrofit — particularly around unique constraints, which must be rebuilt against
live data that may already contain cross-company duplicates.

## Decision

Operate single-organisation, but build the schema and model layer as if multi-tenant:

1. `company_id` on every business table from the first migration, defaulting to `1`
2. **All unique constraints composite** — `unique(['company_id','code'])`, never `unique('code')`
3. A `BelongsToCompany` trait applying a global Eloquent scope, reading from a `CompanyContext`
   singleton that currently returns `1`
4. Cache keys and file paths namespaced by company from day one
5. No client-side ID enumeration outside the scoped model layer

Named `company_id` rather than `tenant_id` — multi-entity and multi-branch are useful even
within a single organisation, so the column earns its keep immediately.

## Consequences

- **Positive:** Enabling multi-tenancy becomes: change the `CompanyContext` resolver, add
  middleware, add company CRUD. Days, not months.
- **Positive:** Multi-entity support (subsidiaries, branches) is available immediately.
- **Negative:** Slightly wider indexes and one extra column everywhere. Negligible.
- **Negative:** Developers must remember composite constraints. Rule 2 is the one that hurts if
  forgotten, so it is called out in
  [invariants.md](../architecture/invariants.md) and should be enforced by a test.

## Alternatives considered

**Full multi-tenancy now** (`stancl/tenancy`) — rejected as building for a requirement that
doesn't exist, with real ongoing complexity in local development and testing.

**Database per tenant** — strongest isolation, easy per-customer backups. Rejected for now: it
complicates every deployment and is poorly suited to shared hosting. Note this decision does
not preclude it — a schema with clean company scoping can move either way.

**Ignore it entirely** — rejected. Retrofitting rule 2 alone (rebuilding every unique index
against dirty production data) is the kind of migration that gets postponed indefinitely.

## Related

- [architecture/multi-tenancy.md](../architecture/multi-tenancy.md) — implementation detail
- [architecture/invariants.md](../architecture/invariants.md)
