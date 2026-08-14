# Project Status

**Updated:** 2026-08-14
**Current phase:** Phase 0 — Foundation (in progress — dev environment, company scoping, auth & access, all cross-cutting mechanisms, the branching/deploy model and the Scramble → Orval pipeline done; frontend skeleton next)
**Application code:** Laravel 12 skeleton + company scoping + auth (Sanctum, roles/permissions, activity log) + UoM conversion + custom fields mechanism + gapless numbering sequences + currencies/exchange rates schema + Scramble→Orval type pipeline; no domain code yet

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
| **Custom fields** — `custom_field_definitions` table (company-scoped), `App\Support\CustomFieldDataType` enum, `App\Support\Concerns\HasCustomFields` trait for entity models, `App\Rules\Support\ValidCustomFields` validation rule. No entity model uses the trait yet — that starts in Phase 1 → [phase-0-foundation.md](phases/phase-0-foundation.md#cross-cutting-mechanisms) | 2026-08-13 |
| **Sequences** — `sequences` table (company-scoped, prefix/padding are configuration), `App\Contracts\NumberingScheme` + `App\Support\DefaultNumberingScheme` (`lockForUpdate()`, bound in `AppServiceProvider`). A raw-PDO test proves the row lock genuinely blocks a second session → [phase-0-foundation.md](phases/phase-0-foundation.md#cross-cutting-mechanisms) | 2026-08-13 |
| **Fixed the test suite silently running against the dev database** — `phpunit.xml`'s `<env>` overrides were no-ops (PHPUnit only forces `putenv()`/`$_ENV`, but this container sets `DB_CONNECTION`/`APP_ENV`/`CACHE_STORE`/etc. as real process env vars that Laravel's `env()` reads from `$_SERVER` first, which PHPUnit never touched) — every `php artisan test` run this whole session, and likely before, had actually been hitting the dev `erp` database and dev Redis, not the isolated `erp_testing`/array/sync test config. Fixed with a `tests/bootstrap.php` that sets `$_SERVER` directly before Laravel loads → [phase-0-foundation.md](phases/phase-0-foundation.md#cross-cutting-mechanisms) | 2026-08-13 |
| **Currencies** — `currencies` table (**global, not company-scoped**, per multi-tenancy.md rule 1), `exchange_rates` (company-scoped, composite unique on company/pair/date/type), `App\Support\RateType` enum. Schema only — rate data and its source (manual vs. an API) is Phase 1+, logged as [Q9](OPEN-QUESTIONS.md#q9--exchange-rate-source-manual-entry-an-api-or-both). **All four Phase 0 cross-cutting mechanisms (UoM, custom fields, sequences, currencies) are now done** → [phase-0-foundation.md](phases/phase-0-foundation.md#cross-cutting-mechanisms) | 2026-08-13 |
| **Branching/deploy model decided**: `develop` (default, never deploys) + `main` (deploy-triggering only), `hotfix/*` off `main` → [ADR 0029](decisions/0029-develop-main-branching-model.md); [ci-cd.md](architecture/ci-cd.md) pipeline updated to scope image build/deploy to `main` only. Root `.dockerignore` added. | 2026-08-14 |
| **Scramble → Orval type pipeline** — `dedoc/scramble` installed and exporting the OpenAPI spec from the existing auth routes; `frontend/packages/core-api` (Orval, `client: react-query`, `httpClient: fetch`, a Sanctum-aware custom mutator) generates typed TanStack Query hooks from it — verified deterministic across re-runs and type-checks clean. `.github/workflows/pipeline.yml` wires lint/test/codegen-drift-check on every branch and gates the amd64+arm64 image build to `main`, per ADR 0029 — **written but not yet exercised on real GitHub Actions**, and the stray empty `frontend/` scaffold (root-owned, from an earlier session) was cleaned up as part of this → [phase-0-foundation.md](phases/phase-0-foundation.md#api--type-pipeline) | 2026-08-14 |

## In progress

Auth & access and all four cross-cutting mechanisms (UoM, custom fields, sequences, currencies),
the branching/deploy model, and the Scramble → Orval pipeline are done (see table above). See
[phase-0-foundation.md](phases/phase-0-foundation.md) for what's left in the phase.

**Not done yet — pick up here:**
1. `docker-compose.prod.yml` is written but never deployed/verified against dbvm; the Dockerfile's
   only been built for `amd64` this session, `arm64` untested. Deliberately deferred until there's
   a real feature worth deploying, rather than verifying against an empty skeleton.
2. `frontend/` (including `packages/core-api`) is written but **not yet committed** — worth a look
   before committing. `.github/workflows/pipeline.yml` has never run on actual GitHub Actions; the
   self-hosted arm64 runner build job in particular is unverified.
3. Next real work: the frontend skeleton (`packages/core-ui`, `apps/main`, Tailwind/shadcn, router,
   login screen) — see [phase-0-foundation.md](phases/phase-0-foundation.md).

Session note: work moved from a Windows-side VS Code window (browsing the repo over
`\\wsl.localhost\...`) to a proper **Remote-WSL** VS Code window on 2026-08-11 — do Docker/git
commands natively from the WSL shell now, no `wsl.exe`/`MSYS_NO_PATHCONV` wrapping needed.

## Next

**[Phase 0 — Foundation](phases/phase-0-foundation.md).** Dev environment, company scoping, auth
& access, all four cross-cutting mechanisms, and the Scramble → Orval type pipeline are done;
remaining scope is the frontend skeleton and verifying the CI pipeline actually runs on GitHub —
see the phase doc for the full task list.

Phase 0 has no dependency on the open questions below and **can proceed.**

Remaining before code beyond the skeleton starts: enable `btree_gist` (via a migration) and
confirm the `REDIS_PREFIX=erp_` doesn't collide with invoiceflow/crm on mainvm (unverified from
this dev environment — needs a look at their actual runtime config).

## Blocked / awaiting input

None currently blocking. Q1–Q7 resolved 2026-08-10 (see ADRs 0023–0028 above).

Full list: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 2 non-blocking (second customer
profile; exchange rate source). Q7's actual role/permission list is still deferred to Phase 4,
per ADR 0028.

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
