[← Docs index](../README.md)

# Architecture

**How** the system is built. For **what** it models, see [domain/](../domain/). For **why**
these choices were made, see [decisions/](../decisions/).

| Doc | Covers | Read before |
|---|---|---|
| [invariants.md](invariants.md) | Rules that must never be broken | **Every session** |
| [research-and-design.md](research-and-design.md) | Research reuse, primary-source comparison and recording standard | Designing a new feature or materially changing its business model |
| [environments.md](environments.md) | The actual VMs, versions, disk, what's already running | Deploying, choosing a version |
| [ci-cd.md](ci-cd.md) | Pipeline, runners, image retention | Touching CI or deploy |
| [ci-runner-setup.md](ci-runner-setup.md) | Step-by-step: registering the second self-hosted runner | Setting up the ERP CI runner |
| [tech-stack.md](tech-stack.md) | Every library and why it's there | Setting up, adding a dependency |
| [code-structure.md](code-structure.md) | Module layout, Actions, events | Writing any backend code |
| [database.md](database.md) | Postgres conventions and what to use | Writing a migration or query |
| [multi-tenancy.md](multi-tenancy.md) | `company_id`, scoping, the escape hatch | Writing a migration or model |
| [extensibility.md](extensibility.md) | Variation ladder, plugins, custom fields | Handling a per-customer difference |
| [authorization.md](authorization.md) | Roles, permissions, namespacing, Teams | Adding a permission or gating an action |
| [authorization-retrofit.md](authorization-retrofit.md) | Tracked backlog: which already-built features still need permissions, field protection and UI gating | Picking up the next retrofit slice |
| [access-security-audit.md](access-security-audit.md) | Cross-module access and sensitive-data audit: confirmed gaps, coverage limits and staged rollout | Auditing a module or changing financial/master-data visibility |
| [approval-workflows.md](approval-workflows.md) | Maker/checker, multi-stage approval and authority limits | Adding an approval or controlled transition |
| [data-import.md](data-import.md) | Column-mapping import engine | Building or extending an import |
| [user-interface.md](user-interface.md) | UI research, interaction standards and phase screen ownership | Planning or building screens |
| [ui-building-blocks.md](ui-building-blocks.md) | The reusable UI that exists, and the alternatives kept on purpose | Before building any control, layout or screen behaviour |
| [appearance-and-printing.md](appearance-and-printing.md) | Light/dark/theme tokens and Quick Print branding, both company/user-layered | Adding a colour, a theme, or touching print output |
| [document-numbering.md](document-numbering.md) | The central numbering framework: series, versions, counters, routing, and how to make a document numbered | Adding a document that carries a number, or changing how numbers are issued |
| [company-settings.md](company-settings.md) | The Company module: profile, company-wide settings, field registry, locks, history, how to add a field | Adding a company-wide setting, or reading the company's time zone/code |
| [erp-blue-design-standard.md](erp-blue-design-standard.md) | The `erp-blue` palette, scoping and rules proven on Purchase Orders, and how to roll it out | Restyling a screen, or adding a palette |
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
