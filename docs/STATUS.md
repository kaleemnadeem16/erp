# Project Status

**Updated:** 2026-08-20
**Current phase:** Phase 0 — Foundation (in progress — everything except deploying to mainvm is
done, deliberately deferred until there's a real feature worth shipping) and Phase 1 — Master
data (in progress — design decided, build under way: `parties`/`party_contacts`/`party_sites`,
`categories` and `items` done; see [phase-1-master-data.md](phases/phase-1-master-data.md))
**Application code:** Laravel 12 skeleton + company scoping + auth (Sanctum, roles/permissions, activity log) + UoM conversion + custom fields mechanism + gapless numbering sequences + currencies/exchange rates schema + Scramble→Orval type pipeline + `Parties` module (party identity layer) + `Inventory` module (categories, items, hierarchical GL-default resolution); React 19 + Vite frontend skeleton (login, one authenticated page, mobile-first shell)

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
| **Currencies** — `currencies` table (**global, not company-scoped**, per multi-tenancy.md rule 1), `exchange_rates` (company-scoped, composite unique on company/pair/date/type), `App\Support\RateType` enum. Schema only — rate data and its source (manual vs. an API) deferred to Phase 1+ as Q9, later resolved to manual entry only, see [ADR 0031](decisions/0031-manual-exchange-rate-entry.md). **All four Phase 0 cross-cutting mechanisms (UoM, custom fields, sequences, currencies) are now done** → [phase-0-foundation.md](phases/phase-0-foundation.md#cross-cutting-mechanisms) | 2026-08-13 |
| **Branching/deploy model decided**: `develop` (default, never deploys) + `main` (deploy-triggering only), `hotfix/*` off `main` → [ADR 0029](decisions/0029-develop-main-branching-model.md); [ci-cd.md](architecture/ci-cd.md) pipeline updated to scope image build/deploy to `main` only. Root `.dockerignore` added. | 2026-08-14 |
| **Scramble → Orval type pipeline** — `dedoc/scramble` installed and exporting the OpenAPI spec from the existing auth routes; `frontend/packages/core-api` (Orval, `client: react-query`, `httpClient: fetch`, a Sanctum-aware custom mutator) generates typed TanStack Query hooks from it — verified deterministic across re-runs and type-checks clean. `.github/workflows/pipeline.yml` wires lint/test/codegen-drift-check on every branch and gates the amd64+arm64 image build to `main`, per ADR 0029, and `frontend/` is committed → [phase-0-foundation.md](phases/phase-0-foundation.md#api--type-pipeline) | 2026-08-14 |
| **CI pipeline verified green end-to-end on real GitHub Actions** — fixed 4 CI-only bugs found on the first real run: `codegen-check` had no postgres service at all (Scramble's type inference queries real table schema), `migrate --force` tried to reach a nonexistent `redis` host (added `CACHE_STORE=array`/`SESSION_DRIVER=array`/`QUEUE_CONNECTION=sync` to that job only), `LoginTest`/`LogoutTest` had a hardcoded `Referer: localhost:5173` that broke Sanctum's stateful-session detection after the port move to 5176, and `storage/api-docs/` (gitignored) didn't exist in a fresh checkout. All jobs (`lint-backend`, `lint-frontend`, `test`, `codegen-check`, `build` amd64+arm64, `merge-manifest`) now pass | 2026-08-18 |
| **Dev port moved 5173→5176** (collision with another local app) — `docker-compose.yml`, `.env`/`.env.example`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, and its fallback default in `config/cors.php` all updated; recurring dev `nginx` "bad gateway" (stale upstream IP cached at container startup, fixed with `docker compose restart nginx` each time) documented as a known dev-environment quirk, not structurally fixed | 2026-08-18 |
| **Branching model operationalized** — `develop` branch cut from `main` and set as the GitHub default; `main` is now deploy-triggering only, per ADR 0029. Branch protection on `main` (required CI checks, no direct pushes) is **not enforced by GitHub** — private repos need GitHub Pro for branch protection on the Free plan — so it's followed by convention (merge via PR only) until that's revisited | 2026-08-18 |
| **Frontend skeleton** — `packages/core-ui` (shadcn `Button`/`Input`/`Label` primitives, `AppShell` with a responsive bottom-tabs/sidebar layout, `LoginScreen`, `DashboardScreen`, a Zustand `useAuthStore` mirroring the session TanStack Query resolves) and `apps/main` (Vite + React 19, Tailwind v4, `react-router` v7, `vite-plugin-pwa`) wired to the generated `core-api` client. Found and fixed a real bug in the process: `fetchClient` was returning the raw response body instead of the `{data, status, headers}` envelope Orval's generated hook types expect — nothing caught it earlier since no frontend code had consumed the hooks until now. Full login→session-check→logout HTTP cycle verified against the dev backend with curl, and confirmed with a real browser login (screenshots: sign-in form, dashboard, sidebar with logout) → [phase-0-foundation.md](phases/phase-0-foundation.md#frontend-skeleton) | 2026-08-18 |
| **Frontend production Dockerfile** (`docker/frontend/Dockerfile`) — multi-stage: `node:22-alpine` runs `vite build`, discarded; `nginx:1.27-alpine` serves the static output, with SPA fallback (`try_files … /index.html`, so a direct hit on `/login` doesn't 404) and immutable long-cache headers on Vite's content-hashed `/assets/`. Built and smoke-tested locally (`/`, `/login`, and the asset `Cache-Control` header all verified). CI's `build`/`merge-manifest` jobs now push `erp-frontend` alongside `erp-backend`, so `docker-compose.prod.yml` is fully runnable once deployment itself is picked up | 2026-08-18 |
| **Phase 1 master-data design decided**: customers/suppliers modelled as a unified party (identity + role-specific `customer_accounts`/`supplier_accounts` + shared `party_sites`), company-scoped with no exception to the tenant-isolation invariant, organisations-only for now → [ADR 0030](decisions/0030-unified-party-model.md), [parties.md](domain/parties.md); item categories made hierarchical with GL-default inheritance walking up the tree → [items.md](domain/items.md); exchange rates resolved to manual entry only, closing Q9 → [ADR 0031](decisions/0031-manual-exchange-rate-entry.md). Surfaced a real architecture gap in the process — `company_id` is the full tenant-isolation boundary with no construct for sharing data across one customer's own multiple legal entities — logged as [Q10](OPEN-QUESTIONS.md#q10--sharing-master-data-across-a-customers-own-legal-entities), not blocking. Phase 1 scoped backend-only; frontend CRUD stays deferred to Phase 4 → [phase-1-master-data.md](phases/phase-1-master-data.md) | 2026-08-19 |
| **`Parties` module built** — the identity layer from ADR 0030 is live: `parties`/`party_contacts`/`party_sites` migrations and models, following `Uom.php`/`Sequence.php` conventions (`BelongsToCompany`, `casts()`, guarded ids). `customer_accounts`/`supplier_accounts` remain deferred pending `chart_of_accounts`, per the agreed sequencing. Settling *where* this code lives surfaced two real gaps, both closed rather than worked around: (1) `app/Modules/` was completely empty — this is the first phase to actually populate it, so the `Parties` module scaffold (`README.md`, one module owning all seven ADR 0030 tables rather than splitting by Sales/Purchasing) sets the pattern, and `code-structure.md` now documents the read/write boundary between modules (reads are direct Eloquent, writes stay behind the owning module's Actions) that was previously only implicit in the `JobConfirmed` example; (2) no mechanism existed for tenant-scoping a table with no `company_id` of its own, despite every domain doc already designing child tables that way (`invoice_lines`, `journal_lines`, `job_lines`, `assembly_components`, `locations`) — closed with `App\Support\Concerns\BelongsToCompanyThroughParent` (read-side global scope composing through relation chains, write-side `creating` guard against a raw foreign key), proven with a 2-hop test and documented in [multi-tenancy.md](architecture/multi-tenancy.md#child-tables-scope-through-their-parent). `party_sites.country_code` is a plain ISO 3166-1 alpha-2 string, not a `countries` FK — no such table exists and nothing yet needs more than a code. Full toolchain green throughout (Pest, Larastan, Pint) → [phase-1-master-data.md](phases/phase-1-master-data.md) | 2026-08-19 |
| **Verified the two pre-Phase-1 infra checks directly against dbvm/mainvm** (no app deployment needed — both are checks against infra that already runs independently of our app, per [environments.md](architecture/environments.md#do-not-run-our-own-postgres-or-redis-in-production)): `btree_gist` is available on dbvm's Postgres (`default_version 1.7`) but not yet installed on the `erp` database — it's a trusted extension since PG13, so `erp_app` shouldn't need superuser to `CREATE EXTENSION IF NOT EXISTS btree_gist;` in whichever migration first needs an exclusion constraint; actual install deferred to that migration. `REDIS_PREFIX=erp_` (explicit in our `.env.example`) confirmed distinct from both existing apps on mainvm, neither of which sets `REDIS_PREFIX` explicitly: invoiceflow defaults to `invoiceflow_database_` (from `APP_NAME=InvoiceFlow`), CRM defaults to `x1techs_crm_database_` (from `APP_NAME="X1Techs CRM"`) — checked via `docker inspect` on the running `invoiceflow-backend`/`crm_backend_prod` containers | 2026-08-19 |
| **`categories` built** — `App\Modules\Inventory\Models\Category` (hierarchical, self-referencing `parent_id`, `BelongsToCompany`), first feature in the previously-empty `Inventory` module (got its `README.md` scaffold). `resolveIncomeAccountId()`/`resolveExpenseAccountId()`/`resolveInventoryAccountId()` implement the GL-default inheritance walk from [items.md](domain/items.md); the three account columns are plain nullable FKs-to-be, unconstrained until `chart_of_accounts` exists later in this phase. Closed the cycle-guard landmine items.md flagged: a `saving` hook rejects a reparent that would create a cycle and a `parent_id` pointing at another company's row — a lighter version of `BelongsToCompanyThroughParent`'s guarantee, since `Category` already carries its own `company_id`. Full toolchain green (Pest 101/101, Larastan, Pint) → [phase-1-master-data.md](phases/phase-1-master-data.md) | 2026-08-20 |
| **`items` built** — `App\Modules\Inventory\Models\Item` (`BelongsToCompany`, `HasCustomFields`, `SoftDeletes`), `code` normalised to lowercase on write per [items.md](domain/items.md) "Codes and case sensitivity". `type`/`tracking` got their own module-local enums (`App\Modules\Inventory\Support\ItemType`/`ItemTracking`) rather than `App\Support`, since they're Inventory-only vocabulary; `valuation_method` stayed a plain string default `'AVCO'` — no second method exists or is named in the doc, so a one-case enum would be speculative. `resolveIncomeAccountId()`/`resolveExpenseAccountId()`/`resolveInventoryAccountId()` prefer the item's own column and fall through to `category`'s resolution otherwise, proving the phase's "GL accounts walk up the category tree" acceptance criterion. `default_supplier_id` and the three account columns are unconstrained nullable bigints, same forward-reference reason as `categories` — `supplier_accounts`/`chart_of_accounts` don't exist yet. Full toolchain green (Pest 112/112, Larastan, Pint) → [phase-1-master-data.md](phases/phase-1-master-data.md) | 2026-08-20 |
| **`activity_log` got its own `company_id`** — the original migration deferred this until a real company-scoped model started logging (see its comment); `Party` crossed that line. Follow-up migration adds `company_id` (`default(1)`, FK to `companies`, plain index — no natural composite unique on an append-only log); `App\Models\Concerns\LogsActivityWithDefaults::tapActivity()` stamps it from `CompanyContext` on every entry, so no per-model change was needed. Deliberately write-only for now: no read-side global scope, no API to query it — those are deferred until the audit-log API is designed (access level, who can see what). Full toolchain green (Pest, Larastan, Pint) → [multi-tenancy.md](architecture/multi-tenancy.md#polymorphic-logs-tag-via-tapactivity-not-belongstocompany) | 2026-08-19 |

## In progress

Auth & access, all four cross-cutting mechanisms (UoM, custom fields, sequences, currencies), the
branching/deploy model, the Scramble → Orval pipeline, and the frontend skeleton are done (see
table above), built on `feature/frontend-skeleton` off `develop` per ADR 0029. See
[phase-0-foundation.md](phases/phase-0-foundation.md) for what's left in the phase.

**Not done yet — pick up here:**
1. `docker-compose.prod.yml` is written but never deployed/verified against dbvm. Deliberately
   deferred until there's a real feature worth deploying, rather than verifying against an empty
   skeleton — no deploy job exists in `pipeline.yml` yet either (by design, see its trailing
   comment); the `build`/`merge-manifest` jobs only build and push images to GHCR.

Session note: work moved from a Windows-side VS Code window (browsing the repo over
`\\wsl.localhost\...`) to a proper **Remote-WSL** VS Code window on 2026-08-11 — do Docker/git
commands natively from the WSL shell now, no `wsl.exe`/`MSYS_NO_PATHCONV` wrapping needed.

Phase 1's master-data design is decided (see Done table above) but no build work has started —
see [phase-1-master-data.md](phases/phase-1-master-data.md) for the task list.

## Next

**[Phase 1 — Master data](phases/phase-1-master-data.md).** The `parties`/`party_contacts`/
`party_sites` identity layer and `categories`/`items` (the `Inventory` module) are built (see
Done table above). Remaining, per the phase doc's task list: `chart_of_accounts`/
`accounting_periods`; then `customer_accounts`/`supplier_accounts`/`customer_sites`/
`supplier_sites` once `chart_of_accounts` exists to reference; manual exchange-rate entry; API
endpoints + Orval regeneration for everything built so far. Backend-only; no new
frontend screens this phase.

[Phase 0 — Foundation](phases/phase-0-foundation.md) remains in progress in parallel — the two
former blockers are resolved (`btree_gist` confirmed available on dbvm, `REDIS_PREFIX=erp_`
collision check came back clean) and nothing else is outstanding except the deliberately-deferred
production deployment.

Neither phase has a dependency on the open questions below and **both can proceed.**

## Blocked / awaiting input

None currently blocking. Q1–Q7 resolved 2026-08-10 (see ADRs 0023–0028 above).

Full list: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 2 non-blocking (second customer
profile; sharing master data across a customer's own legal entities). Q7's actual
role/permission list is still deferred to Phase 4, per ADR 0028. Q9 (exchange rate source)
resolved 2026-08-19 — see ADR 0031.

## Roadmap at a glance

| Phase | Name | Status |
|---|---|---|
| 0 | [Foundation](phases/phase-0-foundation.md) | In progress |
| 1 | [Master data](phases/phase-1-master-data.md) | In progress |
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
