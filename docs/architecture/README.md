[← Docs index](../README.md)

# Architecture

**How** the system is built. For **what** it models, see [domain/](../domain/). For **why**
these choices were made, see [decisions/](../decisions/).

| Doc | Covers | Read before |
|---|---|---|
| [invariants.md](invariants.md) | Rules that must never be broken | **Every session** |
| [environments.md](environments.md) | The actual VMs, versions, disk, what's already running | Deploying, choosing a version |
| [ci-cd.md](ci-cd.md) | Pipeline, runners, image retention | Touching CI or deploy |
| [tech-stack.md](tech-stack.md) | Every library and why it's there | Setting up, adding a dependency |
| [code-structure.md](code-structure.md) | Module layout, Actions, events | Writing any backend code |
| [database.md](database.md) | Postgres conventions and what to use | Writing a migration or query |
| [multi-tenancy.md](multi-tenancy.md) | `company_id`, scoping, the escape hatch | Writing a migration or model |
| [extensibility.md](extensibility.md) | Variation ladder, plugins, custom fields | Handling a per-customer difference |
| [authorization.md](authorization.md) | Roles, permissions, namespacing, Teams | Adding a permission or gating an action |
| [data-import.md](data-import.md) | Column-mapping import engine | Building or extending an import |
| [api-conventions.md](api-conventions.md) | Routes, responses, errors, pagination | Adding an endpoint |
| [deployment.md](deployment.md) | Targets, multi-arch, what a customer deployment needs | Deploying or changing infra |

## The short version

A **modular monolith** ([ADR 0014](../decisions/0014-modular-monolith.md)): one Laravel
application, modules split by domain, communicating through events. Business logic lives in
Action classes, not controllers.

A **separate React SPA** ([ADR 0002](../decisions/0002-api-first-react-spa.md)) talking to a
versioned REST API, with the TypeScript client generated from the backend
([ADR 0006](../decisions/0006-generated-api-client.md)) so the two cannot silently drift.

**PostgreSQL 16 only** ([ADR 0020](../decisions/0020-postgresql-only.md)) — MySQL support was
dropped with the shared-hosting tier, which unlocks JSONB, recursive CTEs, partial indexes and
exclusion constraints. Images are **multi-architecture**
([ADR 0021](../decisions/0021-multi-architecture-images.md)): dev is x86, production is ARM.

**Single-organisation today, multi-tenant-ready**
([ADR 0004](../decisions/0004-multi-tenancy-ready-schema.md)) — `company_id` everywhere from
the first migration.

**Per-customer variation via plugins, not forks**
([ADR 0010](../decisions/0010-core-plus-plugins.md)), using the cheapest mechanism that works.

## Related

- [Decision index](../decisions/README.md) — the reasoning behind all of the above
- [Roadmap](../phases/README.md) — the order this gets built in
