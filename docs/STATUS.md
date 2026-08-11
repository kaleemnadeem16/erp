# Project Status

**Updated:** 2026-08-11
**Current phase:** Phase 0 — Foundation (in progress — dev environment verified, backend skeleton next)
**Application code:** Laravel 12 skeleton only, running in dev; no domain code yet

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

## In progress

The scaffold from `1ee9293` had never actually run — getting it up this session (2026-08-11
evening) surfaced three real, now-fixed bugs (uncommitted): (1) `pecl.php.net` is still down, and
both its usual workarounds failed too (Alpine's prebuilt `php84-pecl-redis` targets a different
PHP install and silently never loads; `install-php-extensions`' GitHub fallback misses phpredis'
`liblzf` submodule) — fixed by building phpredis by hand from a `--recurse-submodules` clone; (2)
`app`/`worker`/`scheduler` share one bind-mounted repo and one vendor volume but started
concurrently, so `composer install` and `storage:link --force` raced each other and corrupted
things — fixed with `flock` around the whole entrypoint setup block; (3) php-fpm's `www-data`
(82:82) couldn't write through the bind mount owned by the host user (1000:1000 under WSL2),
turning every request into a 500 — fixed by rewriting `www-data`'s uid/gid in the entrypoint. Also
added the `testing` DB connection `config/database.php` was missing, and installed `brick/money` /
Pest / Larastan for real (`pest --init`, not `pest:install` — that command doesn't exist in Pest
3). Full detail in the commit that lands this.

**Not done yet — pick up here:**
1. `docker-compose.prod.yml` is written but never deployed/verified against dbvm; the Dockerfile's
   only been built for `amd64` this session, `arm64` untested.
2. Commit this batch (Dockerfile/entrypoint/config fixes, composer.json/lock, phase doc updates).
3. Next real work: Phase 0's remaining backend-skeleton tasks — company scoping, auth (Sanctum,
   `spatie/laravel-permission`), then the cross-cutting mechanisms (UoM, custom fields, sequences,
   currencies) — see [phase-0-foundation.md](phases/phase-0-foundation.md).

Session note: work moved from a Windows-side VS Code window (browsing the repo over
`\\wsl.localhost\...`) to a proper **Remote-WSL** VS Code window on 2026-08-11 — do Docker/git
commands natively from the WSL shell now, no `wsl.exe`/`MSYS_NO_PATHCONV` wrapping needed.

## Next

**[Phase 0 — Foundation](phases/phase-0-foundation.md).** Dev environment is up and verified;
remaining scope is company scoping, auth (Sanctum, roles, activity log), the cross-cutting
mechanisms (UoM, custom fields, sequences, currencies), the Scramble → Orval type pipeline, the
frontend skeleton, and CI — see the phase doc for the full task list.

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
