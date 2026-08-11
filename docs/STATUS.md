# Project Status

**Updated:** 2026-08-11
**Current phase:** Phase 0 — Foundation (in progress — infrastructure prerequisites done)
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
| Retention/retainage support decided (per-contract %, default off) → [ADR 0023](decisions/0023-retention-retainage-enabled.md) | 2026-08-10 |
| Base currency (USD default) and fiscal year (July default) set at onboarding, locked after first use → [ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) | 2026-08-10 |
| Tax model: fully configurable tax-code engine, no hardcoded rates → [ADR 0025](decisions/0025-configurable-tax-engine.md) | 2026-08-10 |
| No RTL/localisation needed — English only → [ADR 0026](decisions/0026-english-only-no-rtl.md) | 2026-08-10 |
| Data import: generic column-mapping engine, not per-client scripts → [ADR 0027](decisions/0027-generic-column-mapping-import.md) | 2026-08-10 |
| Roles/permissions: dynamic, namespaced, per-user overrides, Teams-ready → [ADR 0028](decisions/0028-dynamic-namespaced-permissions.md) | 2026-08-10 |
| Second self-hosted ARM runner registered (`mainvm-erp-arm64`) → [ci-runner-setup.md](architecture/ci-runner-setup.md) | 2026-08-11 |
| ERP database created on dbvm (`erp` owned by `erp_app`) | 2026-08-11 |

## In progress

Phase 0 environment scaffolding is underway (not committed yet — check `git status`, this is
all untracked). State as of 2026-08-11 evening:

**Written, present on disk:**
- `docker-compose.yml` (dev: app, nginx, postgres, redis, worker, scheduler, node — `node` is a
  placeholder, non-functional until the frontend skeleton exists) and `docker-compose.prod.yml`
  (no db/redis, connects to dbvm; Traefik host rules use `erp.x1techs.com` /
  `api-erp.x1techs.com` — DNS A records for both already point at mainvm, confirmed)
- `docker/backend/Dockerfile` — multi-stage (`base`/`dev`/`vendor`/`production`), pinned to
  `php:8.4.21-fpm-alpine3.23@sha256:2e8d5b74437b02cbc3c632903d20a10fdcc956ba56d25bff951cc2b610767c9a`
  — verified byte-for-byte match to the digest `invoiceflow-backend`/`crm_backend` currently run
  on mainvm (Alpine 3.23.4, PHP 8.4.21), so base layers should share. Uses
  `mlocati/docker-php-extension-installer` instead of raw `docker-php-ext-install` + PECL for the
  `redis` extension — `pecl.php.net`'s channel service returned 504s mid-build, this sidesteps it.
- `backend/` — Laravel 12 scaffolded via a one-off `composer:2` container run (ownership fixed
  back to the host user afterward — the container writes as root). `.env` points at the dev
  Postgres/Redis compose services. `phpstan.neon` (Larastan, level 6) and `pint.json` written.
  `app/Support/Casts/MoneyCast.php` written (brick/money, decimal(19,4), paired currency column).
  `app/Modules/`, `app/Support/`, `app/Contracts/` created per code-structure.md.
- Root `README.md`, `.gitignore`, `.env.example` (production)

**Not done yet — pick up here:**
1. **The `app` dev image has never successfully built.** Last attempt was rebuilding after the
   PECL fix; the build process was still running when the session/VS Code window changed and got
   killed before finishing — unknown whether that build would have succeeded. Re-run
   `docker compose build app` first, from a plain foreground command (not piped through `tail`,
   which buffers all output until the command exits and made the previous build look silent/hung).
2. Once it builds: `docker compose up -d`, confirm `app`/`nginx`/`postgres`/`redis`/`worker`/
   `scheduler` come up healthy, `docker compose exec app php artisan migrate` runs clean against
   Postgres (not the sqlite that `composer create-project` set up by default — already removed).
3. Still needs installing (none of these are in `composer.json` yet): Pest (+ `pest-plugin-laravel`,
   then `php artisan pest:install` to convert the default PHPUnit `tests/`), `larastan/larastan`,
   `brick/money`. Install through the built `app` container, not the host, so the lockfile matches
   the actual PHP 8.4.21 + extensions that run in prod.
4. Tick the Environment/Backend-skeleton boxes in
   [phase-0-foundation.md](phases/phase-0-foundation.md) once each is actually verified working —
   none are ticked yet on purpose, only "move repo into WSL2" is genuinely done.
5. Commit this batch (currently untracked) once steps 1–3 are verified, or sooner as an explicit
   WIP checkpoint — ask if unsure, nothing has been pushed.

Session note: work moved from a Windows-side VS Code window (browsing the repo over
`\\wsl.localhost\...`) to a proper **Remote-WSL** VS Code window on 2026-08-11 — do Docker/git
commands natively from the WSL shell now, no `wsl.exe`/`MSYS_NO_PATHCONV` wrapping needed.

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

Infrastructure prerequisites — all done as of 2026-08-11:

- ~~Register an org-level or second self-hosted ARM runner~~ — done, `mainvm-erp-arm64`
- ~~Create ERP databases on dbvm's existing PostgreSQL~~ — done, `erp` owned by `erp_app`
- ~~Prune stale images on mainvm~~ — done 2026-07-29, ~20 GB reclaimed. See
  [ci-cd.md](architecture/ci-cd.md#image-retention) for the retention policy going forward.

Remaining before code starts: `btree_gist` extension (via first Laravel migration, no manual
step) and confirming the Redis key prefix (`REDIS_PREFIX=erp_`) once `.env` exists. Next actual
work is Docker Compose + the Laravel/React skeletons.

## Blocked / awaiting input

None currently blocking. Q1–Q7 resolved 2026-08-10 (see ADRs 0023–0028 above).

Full list: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 1 non-blocking (second customer
profile). Q7's actual role/permission list is still deferred to Phase 4, per ADR 0028.

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
