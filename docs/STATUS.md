# Project Status

**Updated:** 2026-08-13
**Current phase:** Phase 0 — Foundation (in progress — dev environment, company scoping, auth & access and UoM done; custom fields/sequences/currencies next)
**Application code:** Laravel 12 skeleton + company scoping + auth (Sanctum, roles/permissions, activity log) + UoM conversion; no domain code yet

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
| Retention/retainage support decided (per-contract %, default off) → [ADR 0023](decisions/0023-retention-retainage-enabled.md) | 2026-08-10 |
| Base currency (USD default) and fiscal year (July default) set at onboarding, locked after first use → [ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) | 2026-08-10 |
| Tax model: fully configurable tax-code engine, no hardcoded rates → [ADR 0025](decisions/0025-configurable-tax-engine.md) | 2026-08-10 |
| No RTL/localisation needed — English only → [ADR 0026](decisions/0026-english-only-no-rtl.md) | 2026-08-10 |
| Data import: generic column-mapping engine, not per-client scripts → [ADR 0027](decisions/0027-generic-column-mapping-import.md) | 2026-08-10 |
| Roles/permissions: dynamic, namespaced, per-user overrides, Teams-ready → [ADR 0028](decisions/0028-dynamic-namespaced-permissions.md) | 2026-08-10 |
| Second self-hosted ARM runner registered (`mainvm-erp-arm64`) → [ci-runner-setup.md](architecture/ci-runner-setup.md) | 2026-08-11 |
| ERP database created on dbvm (`erp` owned by `erp_app`) | 2026-08-11 |
| Docker Compose + Laravel 12 skeleton scaffolded (commit `1ee9293`) | 2026-08-11 |
| **Dev environment verified working end-to-end** — `app`/`nginx`/`postgres`/`redis`/`worker`/`scheduler` build and run healthy, migrations run clean against Postgres, Pest/Larastan/Pint all pass | 2026-08-11 |
| Dev-environment fixes (phpredis build, entrypoint `flock`, `www-data` UID rewrite) committed (`707eea5`) | 2026-08-11 |
| Dev nginx host port moved `8080`→`8090` (XAMPP conflict) | 2026-08-12 |
| **Company scoping** — `companies` table (seeded with company `1`), `CompanyContext` singleton (id + namespaced cache key/storage path helpers), `BelongsToCompany` trait (global scope + auto-fill), architecture test proving no company-scoped table can get a single-column unique index → [phase-0-foundation.md](phases/phase-0-foundation.md#company-scoping) | 2026-08-12 |
| Fixed `phpunit.xml`: was silently running the whole suite against an in-memory sqlite connection (Laravel default, never adjusted) instead of the `testing` Postgres connection — violated the Postgres-only invariant and meant DB-dependent tests weren't exercising real Postgres behaviour | 2026-08-12 |
| Dev Postgres host port moved `5432`→`5434` (native Windows Postgres + invoiceflow's dev stack both already used 5432/5433) | 2026-08-12 |
| **Auth & access** — Sanctum (cookie mode for the SPA, bearer tokens for mobile), `/api/v1/{login,logout,user,forgot-password,reset-password}`, `spatie/laravel-permission` with Teams (role-based + direct-to-user permissions, no roles seeded — list deferred to Phase 4), `spatie/laravel-activitylog` wired to a new base `App\Models\Model` → [phase-0-foundation.md](phases/phase-0-foundation.md#auth--access) | 2026-08-12 |
| **UoM** — `uoms` table (company-scoped), `App\Support\UomDimension` enum, `App\Support\UomConversionService` (dimension-guarded conversion), Postgres partial unique index enforcing one base unit per dimension per company → [phase-0-foundation.md](phases/phase-0-foundation.md#cross-cutting-mechanisms) | 2026-08-13 |

## In progress

Auth & access and UoM are done (see table above). The remaining cross-cutting mechanisms —
custom fields, sequences, currencies — are next, see [phase-0-foundation.md](phases/phase-0-foundation.md).

**Not done yet — pick up here:**
1. `docker-compose.prod.yml` is written but never deployed/verified against dbvm; the Dockerfile's
   only been built for `amd64` this session, `arm64` untested. Deliberately deferred until there's
   a real feature worth deploying, rather than verifying against an empty skeleton.
2. Next real work: the remaining cross-cutting mechanisms (custom fields, sequences, currencies),
   then the Scramble → Orval type pipeline and frontend skeleton — see
   [phase-0-foundation.md](phases/phase-0-foundation.md).

Session note: work moved from a Windows-side VS Code window (browsing the repo over
`\\wsl.localhost\...`) to a proper **Remote-WSL** VS Code window on 2026-08-11 — do Docker/git
commands natively from the WSL shell now, no `wsl.exe`/`MSYS_NO_PATHCONV` wrapping needed.

## Next

**[Phase 0 — Foundation](phases/phase-0-foundation.md).** Dev environment, company scoping, auth
& access and UoM are done; remaining scope is the rest of the cross-cutting mechanisms (custom
fields, sequences, currencies), the Scramble → Orval type pipeline, the frontend skeleton, and CI
— see the phase doc for the full task list.

Phase 0 has no dependency on the open questions below and **can proceed.**

Remaining before code beyond the skeleton starts: enable `btree_gist` (via a migration) and
confirm the `REDIS_PREFIX=erp_` doesn't collide with invoiceflow/crm on mainvm (unverified from
this dev environment — needs a look at their actual runtime config).

## Blocked / awaiting input

None currently blocking. Q1–Q7 resolved 2026-08-10 (see ADRs 0023–0028 above).

Full list: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 1 non-blocking (second customer
profile). Q7's actual role/permission list is still deferred to Phase 4, per ADR 0028.

## Roadmap at a glance

| Phase | Name | Status |
|---|---|---|
| 0 | [Foundation](phases/phase-0-foundation.md) | In progress |
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
