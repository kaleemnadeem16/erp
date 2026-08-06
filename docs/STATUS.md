# Project Status

**Updated:** 2026-07-29
**Current phase:** Phase 0 — Foundation (not started)
**Application code:** none yet

---

## Where we are

Planning and architecture are complete enough to start building. The domain model, tech stack,
extensibility strategy and deployment approach are decided and recorded as
[ADRs](decisions/). No code has been written — this is deliberate; Phase 0 sets up the
environment before any application logic.

## Done

| Item | Date |
|---|---|
| Tech stack selected and recorded | 2026-07-29 |
| Domain model designed (assembly / formulation / job costing) | 2026-07-29 |
| Extensibility strategy decided (core + plugin, not forks) | 2026-07-29 |
| Finance model designed (double-entry GL, open-item AR, multi-currency) | 2026-07-29 |
| Deployment targets decided (Docker; shared hosting later withdrawn — see below) | 2026-07-29 |
| Documentation system established | 2026-07-29 |
| **Infrastructure surveyed** (mainvm + dbvm) → [environments.md](architecture/environments.md) | 2026-07-29 |
| Versions aligned to existing infra: PHP 8.4, PostgreSQL 16, Redis 7 | 2026-07-29 |
| Repo structure decided: single repository → [ADR 0018](decisions/0018-single-repository.md) | 2026-07-29 |
| CI/CD design → [ci-cd.md](architecture/ci-cd.md) | 2026-07-29 |
| **MySQL/MariaDB support dropped** — Postgres only → [ADR 0020](decisions/0020-postgresql-only.md) | 2026-07-29 |
| Multi-architecture images (amd64 + arm64) → [ADR 0021](decisions/0021-multi-architecture-images.md) | 2026-07-29 |
| Code-documentation strategy decided → [ADR 0022](decisions/0022-code-documentation-strategy.md) | 2026-07-29 |
| mainvm image cleanup — ~20 GB reclaimed, no rollback tags in active use lost | 2026-07-29 |

## In progress

Nothing. Awaiting decision to begin Phase 0.

## Next

**[Phase 0 — Foundation](phases/phase-0-foundation.md).** Environment and cross-cutting
scaffolding, no business logic:

- Docker Compose (app, nginx, postgres 16, redis 7, worker, scheduler, vite)
- Multi-arch Dockerfile (amd64 + arm64) on the shared PHP 8.4 Alpine base
- Laravel skeleton + React skeleton
- CI on two native runners; PostgreSQL 16 service container
- Auth (Sanctum), companies, users, roles
- UoM system, custom fields (JSONB + GIN), numbering sequences
- Scramble → Orval typed API pipeline
- `docs/reference/` generators with drift check

Phase 0 has no dependency on the open questions below and **can start immediately.**

Two infrastructure prerequisites, neither blocking design work:

- Register an org-level or second self-hosted ARM runner (the existing one is scoped to
  `invoiceflow`)
- Create ERP databases on dbvm's existing PostgreSQL

~~Prune stale images on mainvm~~ — done 2026-07-29, ~20 GB reclaimed. See
[ci-cd.md](architecture/ci-cd.md#image-retention) for the retention policy going forward.

## Blocked / awaiting input

| Blocks | Question |
|---|---|
| Phase 6 (Finance) | [Retention/retainage applicable?](OPEN-QUESTIONS.md#q1--retention--retainage) |
| Phase 1 (Master data) | [Base currency confirmation](OPEN-QUESTIONS.md#q2--base-currency) |
| Phase 6 (Finance) | [Tax model](OPEN-QUESTIONS.md#q3--tax-model) — not yet discussed at all |

Full list: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 3 blocking, 5 non-blocking.

## Roadmap at a glance

| Phase | Name | Status |
|---|---|---|
| 0 | [Foundation](phases/phase-0-foundation.md) | Not started |
| 1 | Master data | Not started |
| 2 | Purchasing & stock | Not started |
| 3 | Estimating core | Not started |
| 4 | Sales pipeline | Not started |
| 5 | Job execution | Not started |
| 6 | Finance | Not started |
| 7 | Mobile & integrations | Not started |

Detail: [phases/README.md](phases/README.md)

---

## How to update this file

Tick items in **Done** with the date, in the same commit as the work. Move the **Next** section
forward when a phase completes. Keep this file under one screen — detail belongs in the phase
docs, not here.
