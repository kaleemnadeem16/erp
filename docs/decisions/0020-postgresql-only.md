[← Decisions](README.md)

# 0020 — PostgreSQL only

- **Status:** accepted
- **Date:** 2026-07-29
- **Supersedes:** [0019](0019-mariadb-portability-target.md), and through it [0003](0003-postgres-with-mysql-compatibility.md)
- **Affects:** every migration and query, CI, the shared-hosting tier

## Context

[ADR 0003](0003-postgres-with-mysql-compatibility.md) required MySQL compatibility so the product
could run on shared hosting. [ADR 0019](0019-mariadb-portability-target.md) refined the target to
MariaDB 10.11 after the infrastructure survey.

Both rest on the same premise: that shared hosting is a deployment tier worth supporting.

That premise no longer holds. This is a containerised ERP with background workers, a formulation
engine and a double-entry ledger. It is not an application that runs meaningfully on shared
infrastructure — the [degradation list](../architecture/deployment.md) (no Redis, ~60s job
latency, no realtime, no headless Chrome) describes a product nobody would want to sell.

Deployment is VPS or managed containers, for us and for customers.

## Decision

**PostgreSQL 16 only.** MySQL and MariaDB support is dropped entirely. The shared-hosting
artifact and its constraints are removed.

CI runs a single engine.

## Consequences

Removing a constraint is the smaller half of this. The larger half is what becomes available —
capabilities previously banned that map directly onto problems already identified in the domain
model:

| Now available | Solves |
|---|---|
| **JSONB + GIN indexes** | [Custom fields](../architecture/extensibility.md#runtime-custom-fields) become genuinely queryable and fast, not a `LONGTEXT` we hope stays small |
| **Recursive CTEs** | Nested [formulation](../domain/formulations.md) explosion in one query instead of N+1 in PHP — a landmine explicitly flagged in that doc |
| **Exclusion constraints + `daterange`** | Non-overlapping `effective_from`/`effective_to` on formulation and assembly versions, enforced by the database rather than by hope |
| **Partial indexes** | `WHERE deleted_at IS NULL` — most ERP queries filter on active rows |
| **Materialised views** | Aging, stock valuation and variance reports precomputed, which matters on a [1 vCPU database host](../architecture/environments.md#dbvm--data-tier) |
| **Native full-text search (`tsvector`)** | Drops the Laravel Scout abstraction entirely |
| **`ILIKE`, arrays, `RETURNING`, generated columns** | Ordinary query ergonomics |
| **Transactional DDL** | A failed migration rolls back completely |
| **PostGIS** *(already installed on dbvm)* | Site geography for field service, if it ever earns its place |
| **Row-level security** | A future enforcement layer for [multi-tenancy](../architecture/multi-tenancy.md), belt-and-braces alongside the Eloquent scope |

- **Positive:** One engine to test, one to reason about, one set of behaviours. CI time roughly halves.
- **Positive:** The portability rules that existed purely for MySQL compatibility disappear.
- **Negative:** Shared hosting is permanently closed off. Accepted deliberately.
- **Negative:** Customer deployments require PostgreSQL. For a containerised product this is
  normal, but it is a real constraint on who can host it.
- **Requires:** rules that survive for *other* reasons must not be dropped by accident —
  composite unique constraints ([multi-tenancy](../architecture/multi-tenancy.md)) and
  `decimal(19,4)` money are unaffected by this ADR.

## Alternatives considered

**Keep MariaDB support** (ADR 0019 unchanged) — preserves optionality. Rejected: it costs real
CI time and permanently bans JSONB, recursive CTEs, partial indexes and exclusion constraints —
all of which solve problems already identified in the domain model. Optionality for a tier we
have decided not to sell into is not worth that.

**Keep the abstraction but stop testing it** — the worst option. Untested portability is not
portability; it is a claim that fails on first contact.

**Postgres now, add MySQL if a customer demands it** — superficially pragmatic. Rejected because
retrofitting is not incremental: once JSONB, recursive CTEs and exclusion constraints are in the
schema, adding MySQL means redesigning them. If that day comes, it is a deliberate project, not
a configuration change — and this ADR is the record of what would have to be undone.

## Related

- [architecture/database.md](../architecture/database.md) — conventions, replacing the portability rules
- [0019](0019-mariadb-portability-target.md) · [0003](0003-postgres-with-mysql-compatibility.md) — both superseded
- [ADR 0013](0013-docker-dev-and-production.md) — the shared-hosting artifact it described is withdrawn
