[← Decisions](README.md)

# 0003 — PostgreSQL primary, MySQL-compatible

- **Status:** **superseded by [0019](0019-mariadb-portability-target.md)**
- **Date:** 2026-07-29
- **Affects:** every migration and query, CI

> **Superseded 2026-07-29.** The infrastructure survey found dbvm running **MariaDB 10.11**, not
> MySQL 8. The Postgres-primary decision below still stands; the MySQL-family target changed.
> See [0019](0019-mariadb-portability-target.md).

## Context

PostgreSQL is the preferred engine. But the product may be deployed on customer-controlled
infrastructure, and **most shared hosting offers MySQL/MariaDB only** — PostgreSQL is rarely
available there. Requiring Postgres would close off that tier permanently.

## Decision

PostgreSQL 16 is the primary engine. The application must run unmodified on MySQL 8. The full
test suite runs against **both** in CI.

## Consequences

- **Positive:** Deployable to any customer environment, including shared hosting.
- **Negative:** Postgres-specific features are unavailable — no JSONB operators, native enums,
  arrays, `ILIKE`, partial indexes, materialised views, `LISTEN/NOTIFY` or row-level security.
- **Negative:** Full-text search must go through an abstraction (Laravel Scout) rather than
  native `tsvector`.
- **Requires:** the portability rules in
  [architecture/database.md](../architecture/database.md) are
  mandatory, and **CI must run both engines**. Discipline alone is not sufficient — the rules
  are easy to break accidentally and the failure is silent.

## Alternatives considered

**Postgres only** — simpler, and unlocks genuinely useful features (JSONB, RLS, better indexing).
Rejected because it eliminates the shared-hosting tier and constrains where the product can be
sold.

**MySQL only** — maximum deployment reach, one engine to test. Rejected because Postgres is
materially better for the reporting and analytical queries an ERP accumulates, and it costs
little to keep as the primary.

**Abstract behind a repository layer** — insulate the app from SQL entirely. Rejected as
over-engineering: Eloquent already provides the abstraction, and hand-rolling another layer
would cost more than the handful of rules being enforced.

## Related

- [architecture/database.md](../architecture/database.md) — the rules
- [architecture/invariants.md](../architecture/invariants.md)
