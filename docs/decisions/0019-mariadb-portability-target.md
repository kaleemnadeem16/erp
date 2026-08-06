[← Decisions](README.md)

# 0019 — PostgreSQL primary, MariaDB 10.11 portability target

- **Status:** **superseded by [0020](0020-postgresql-only.md)**
- **Date:** 2026-07-29
- **Supersedes:** [0003](0003-postgres-with-mysql-compatibility.md)
- **Affects:** every migration and query, CI

> **Superseded 2026-07-29.** Shared hosting was dropped as a deployment tier, which removed the
> reason for MySQL-family support entirely. See [0020](0020-postgresql-only.md).

## Context

[ADR 0003](0003-postgres-with-mysql-compatibility.md) established PostgreSQL as primary with
MySQL 8 compatibility, chosen before the infrastructure had been surveyed.

The survey ([environments.md](../architecture/environments.md)) found dbvm running
**PostgreSQL 16.11 (with PostGIS) and MariaDB 10.11** — not MySQL 8.

MariaDB and MySQL diverged after MySQL 5.7 and are no longer drop-in equivalents. The most
significant difference for us: **MariaDB has no native JSON type.** `JSON` is an alias for
`LONGTEXT` with a `json_valid()` check constraint. MySQL 8 has a real binary JSON type with
different indexing and validation behaviour.

Testing only against MySQL 8 would leave the engine we actually deploy on untested.

## Decision

Supersedes 0003, restating the full decision:

**PostgreSQL 16 is the primary engine.** The application must also run unmodified on the
MySQL family, with **MariaDB 10.11 as the primary compatibility target** since it is what the
infrastructure actually runs.

Test matrix:

| Trigger | Engines |
|---|---|
| Every PR and merge | `pgsql`, `mariadb` |
| Nightly | `pgsql`, `mariadb`, `mysql8` |

MySQL 8 stays in the matrix because a customer deployment may use it, but nightly rather than
per-commit — the runner is a 3 vCPU production host and build time is not free.

## Consequences

- **Positive:** The engine we actually deploy on is covered by every test run.
- **Positive:** Portability claims are backed by evidence rather than discipline.
- **Negative:** Three-engine coverage means three ways to be wrong. JSON is the main hazard —
  MariaDB's `LONGTEXT`-backed JSON is *looser* than MySQL 8's, so passing on MariaDB does **not**
  guarantee MySQL 8. This is why MySQL 8 stays in the matrix at all.
- **Negative:** Requires `pdo_mysql` in the image — see [ADR 0016](0016-php-84-alignment.md).
- **Requires:** the rules in
  [database.md](../architecture/database.md), now including a
  MariaDB-specific section.

## Alternatives considered

**Keep MySQL 8 as the sole target** (ADR 0003 unchanged) — rejected: it leaves the actual
deployment engine untested.

**MariaDB only, drop MySQL 8** — simpler and cheaper. Rejected because MariaDB's JSON is the
looser of the two; passing it proves little about MySQL 8, and a customer with MySQL 8 is
plausible.

**Test all three on every commit** — most rigorous. Rejected on runner capacity; mainvm has 3
vCPU and serves production traffic.

**Drop MySQL-family support entirely** — Postgres is on both machines and is what we would
choose. Rejected because it eliminates the shared-hosting tier and constrains where the product
can be sold, which was the original reason in 0003 and still holds.

## Related

- [0003](0003-postgres-with-mysql-compatibility.md) — superseded by this
- [architecture/database.md](../architecture/database.md)
- [architecture/ci-cd.md](../architecture/ci-cd.md#tests)
