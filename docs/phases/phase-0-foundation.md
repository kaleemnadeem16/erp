[← Roadmap](README.md)

# Phase 0 — Foundation

- **Status:** in progress — dev environment, company scoping, auth & access, UoM and custom
  fields done; remaining cross-cutting mechanisms (sequences, currencies) next
- **Depends on:** nothing
- **Blocked by:** nothing

## Goal

A working development environment and every cross-cutting mechanism the rest of the system
depends on, with **no business logic**.

At the end of this phase a developer can clone the repo, run one command, log in, and see a
generated TypeScript client for a real endpoint — with tests passing against PostgreSQL.

The mechanisms built here (company scoping, UoM, custom fields, sequences, the type pipeline)
are all things that are cheap now and expensive to retrofit. That is why they come before any
feature.

## Scope

### In

- Docker development environment and production image
- Laravel + React skeletons, wired together
- CI on PostgreSQL 16 (single engine)
- Authentication, companies, users, roles, audit log
- UoM system
- Custom field mechanism
- Numbering sequences
- Scramble → Orval type pipeline

### Out

- Items, customers, suppliers → Phase 1
- Any domain model beyond UoM → Phase 3+
- Real permission definitions beyond a basic set → Phase 4 ([ADR 0028](../decisions/0028-dynamic-namespaced-permissions.md))
- Plugin packages → not until a second customer exists
- Frontend design system beyond shadcn defaults → Phase 4

## Tasks

### Environment

- [x] ~~**Move the repo into the WSL2 filesystem**~~ (`~/projects/erp`) — done, see [deployment.md](../architecture/deployment.md#-windows-development)
- [x] ~~`docker-compose.yml` (dev): app, nginx, postgres 16, redis 7, worker, scheduler, node~~ — done
      2026-08-11; `app`/`nginx`/`postgres`/`redis`/`worker`/`scheduler` verified healthy, migrations
      run clean, HTTP 200 from the app. `node` stays failing until the frontend skeleton exists
      (documented placeholder, not a bug)
- [ ] `docker-compose.prod.yml`: **no** database or redis services — connects to dbvm (written, not
      yet deployed/verified against dbvm)
- [ ] Multi-stage `Dockerfile`, **`linux/amd64` + `linux/arm64`**, `FROM php:8.4-fpm-alpine` pinned by **index** digest
      (amd64 build verified this session; arm64 not yet built/tested)
- [x] ~~Verify the Dockerfile hardcodes no architecture~~ — confirmed by inspection, uses the
      index-digest base and no arch-specific `RUN`/`COPY --platform` lines
- [x] ~~Add the `intl` extension~~ (absent from the existing images) — builds and loads, confirmed
      via `php -m`
- [x] ~~`.env.example`~~
- [x] ~~`README.md` at repo root with setup steps~~ (exists; not yet re-verified from a clean clone —
      that's tracked separately under "Docs to update on completion" below)

### Infrastructure prerequisites

- [x] ~~Register a **second self-hosted ARM runner**~~ — done 2026-08-11, `mainvm-erp-arm64`
      (labels `self-hosted, arm64, erp`), registered under the existing `github-runner` OS user in
      a separate `~/actions-runner-erp` directory, running as its own systemd service. See
      [ci-runner-setup.md](../architecture/ci-runner-setup.md)
- [x] ~~Create the ERP database on dbvm's PostgreSQL~~ — done 2026-08-11, `erp` database owned by
      new role `erp_app` on the existing shared `postgres` container (no new instance provisioned)
- [ ] Enable the `btree_gist` extension — needed for version-period exclusion constraints
- [ ] Confirm the Redis key prefix does not collide with invoiceflow or crm
- [ ] Traefik labels + `proxy-net` attachment, matching the invoiceflow pattern
- [x] ~~Prune stale images on mainvm~~ — done 2026-07-29, ~20 GB reclaimed (2 GB build cache/orphaned
      images + 18 GB stale rollback and version tags). See [ci-cd.md](../architecture/ci-cd.md#image-retention)
      for the retention policy that keeps it from recurring.

### Backend skeleton

- [x] ~~Laravel 12 install, PHP 8.4~~ — running, confirmed `php -v` reports 8.4.21 inside the container
- [x] ~~Module directory structure per [code-structure.md](../architecture/code-structure.md)~~
- [x] ~~Pest, Larastan (level 6+), Pint configured~~ — done 2026-08-11: `pestphp/pest` +
      `pest-plugin-laravel` + `larastan/larastan` installed, `pest --init` run (Pest 3 has no
      `pest:install` artisan command, `--init` is the current equivalent), example suite passes,
      `phpstan analyse` clean at level 6, `pint --test` clean
- [x] ~~`Money` cast using `brick/money`, `decimal(19,4)`~~ — `brick/money` installed as a real
      dependency (it was only referenced in code before); `MoneyCast` now passes Larastan level 6

### Company scoping

- [x] ~~`companies` table, seeded with company `1`~~ — done 2026-08-12
- [x] ~~`CompanyContext` singleton (returns `1` for now)~~ — done 2026-08-12, bound in
      `AppServiceProvider`
- [x] ~~`BelongsToCompany` trait with global scope + auto-fill on create~~ — done
      2026-08-12, `App\Support\Concerns\BelongsToCompany`; no model uses it yet, so
      Larastan's `trait.unused` is silenced with a note pointing at this task
- [x] ~~Company-namespaced cache key helper and storage path helper~~ — done 2026-08-12,
      folded into `CompanyContext::cacheKey()`/`storagePath()` rather than a separate
      helper — one place resolves the company id
- [x] ~~**Architecture test:** no single-column unique index on a company-scoped table~~ —
      done 2026-08-12, `tests/Architecture/CompanyScopedUniqueIndexTest.php`, verified
      against `information_schema` on real Postgres, including a deliberately-added
      violation to prove the check actually catches something. This required fixing
      `phpunit.xml`, which had been left on the Laravel-default in-memory sqlite
      connection — tests now run against the `testing` Postgres connection
      (`erp_testing`), per [database.md](../architecture/database.md)

### Auth & access

- [x] ~~Sanctum — cookie mode for SPA, token mode for mobile~~ — done 2026-08-12.
      `statefulApi()` + CORS (`supports_credentials`, explicit origins — never `*` with
      credentials) for the SPA; a `device_name` field on `/login` switches to issuing a
      bearer token for mobile instead. `SANCTUM_STATEFUL_DOMAINS`/`CORS_ALLOWED_ORIGINS`
      added to dev and prod env files
- [x] ~~Users, login, logout, password reset~~ — done 2026-08-12,
      `App\Http\Controllers\Api\V1\AuthController` +
      `/api/v1/{login,logout,user,forgot-password,reset-password}`. `users` got
      `company_id` (composite-unique with `email`, replacing the anti-pattern bare
      `unique('email')`) and the email-lowercasing rule from
      [database.md](../architecture/database.md) — including in `Auth::attempt()`
      itself, which bypasses the model's mutator by querying raw
- [x] ~~`spatie/laravel-permission` — namespaced permissions (`module.action`), **Teams
      enabled** from the first migration, minimal role set~~ — done 2026-08-12. No roles
      seeded yet (deliberately — the real list is Phase 4, per
      [authorization.md](../architecture/authorization.md)); mechanism verified by tests
      covering both role-based and direct-to-user permissions. `team_id` is resolved
      per-request from `CompanyContext` (`App\Http\Middleware\SetPermissionsTeam`) rather
      than a second hardcoded value — team and company coincide today but are not the
      same dimension
- [x] ~~`spatie/laravel-activitylog` wired to a base model~~ — done 2026-08-12,
      `App\Models\Model` + `App\Models\Concerns\LogsActivityWithDefaults` (dirty-only,
      never logs `password`/`remember_token`). `User` uses the trait directly since it
      must extend `Authenticatable`, not the new base model

### Cross-cutting mechanisms

- [x] ~~`uoms` table + conversion service, dimension-guarded~~ — done 2026-08-13,
      `uoms` (company-scoped, `unique(company_id, code)`), `App\Support\UomDimension`
      backed enum, `App\Support\UomConversionService` (arithmetic within a dimension,
      throws `InvalidArgumentException` across dimensions). Postgres partial unique
      index (`where is_base`) enforces exactly one base unit per dimension per
      company at the DB level rather than in application code → [units-of-measure.md](../domain/units-of-measure.md)
- [x] ~~`custom_field_definitions` + `custom_fields` JSON column pattern~~ — done
      2026-08-13, `custom_field_definitions` (company-scoped, `unique(company_id,
      entity_type, code)`), `App\Support\CustomFieldDataType` enum (string, number,
      date, bool, select, reference), `App\Support\Concerns\HasCustomFields` trait for
      entity models (adds the `custom_fields` array cast + a `whereCustomField` scope;
      requires the host table to already have a `custom_fields` jsonb column),
      `App\Rules\Support\ValidCustomFields` validation rule (rejects values with no
      matching definition, missing required fields, and type mismatches). No entity
      model uses the trait yet — items/quotations arrive in Phase 1 — so
      `HasCustomFields`'s `trait.unused` Larastan error is suppressed with a note to
      remove it once one does → [extensibility.md](../architecture/extensibility.md#runtime-custom-fields)
- [ ] `sequences` table + `NumberingScheme` contract, using `lockForUpdate()`
- [ ] `currencies` + `exchange_rates` tables (data comes in Phase 1)
- [ ] Container bindings for the [contracts](../architecture/extensibility.md#container-bound-contracts) that exist so far

### API & type pipeline

- [ ] `/api/v1` route group, conventions per [api-conventions.md](../architecture/api-conventions.md)
- [ ] Scramble generating OpenAPI
- [ ] Orval generating typed TanStack Query hooks into `packages/core-api`
- [ ] Generated output committed; **CI fails on diff**

### Frontend skeleton

- [ ] Vite + React 19 + TypeScript monorepo (`packages/core-ui`, `packages/core-api`, `apps/main`)
- [ ] Tailwind v4 + shadcn/ui initialised
- [ ] TanStack Query + Zustand
- [ ] Router, app shell, login screen, one authenticated page
- [ ] Mobile-first layout: bottom tabs below `md`, sidebar above
- [ ] `vite-plugin-pwa`

### CI

- [ ] PostgreSQL 16 service container — single engine
- [ ] Pint, Larastan, Pest, TypeScript build, orval drift check
- [ ] `docs/reference/` generators (actions, events, routes, schema) + **fail on diff** — [ADR 0022](../decisions/0022-code-documentation-strategy.md)
- [ ] Build `linux/amd64` (hosted) + `linux/arm64` (self-hosted) → merge manifest → GHCR by commit SHA
- [ ] **Image retention: keep the 3 most recent tags**, prune build cache

## Acceptance criteria

- [ ] `docker compose up` from a clean clone gives a running app
- [ ] Full test suite passes against PostgreSQL 16
- [ ] `docker manifest inspect` on a built tag shows **both** `linux/amd64` and `linux/arm64`
- [ ] The same tag runs on the x86 dev machine **and** on mainvm
- [ ] The arm64 variant shares base layers with `invoiceflow-backend` (verify with
      `docker history` — the Alpine and PHP layers should be identical)
- [ ] `docs/reference/` regenerates identically on a clean run (no diff)
- [ ] A user can log in from the SPA; an unauthenticated request to a protected route returns 401
- [ ] Changing a Laravel API resource field and regenerating produces a **TypeScript compile
      error** in the frontend
- [ ] A model using `BelongsToCompany` cannot return another company's rows, including from a
      queued job
- [x] `sqft → sqm` converts correctly; `L → sqm` throws
- [ ] A custom field defined via `custom_field_definitions` appears in the API response with no
      code change
- [ ] Two concurrent requests for a sequence number return different, gapless values
- [x] The architecture test catches a deliberately added single-column unique index
- [ ] The production image builds and runs

## Docs to update on completion

- [ ] [STATUS.md](../STATUS.md) — mark Phase 0 done, move Next to Phase 1
- [ ] Expand [phase-1](README.md#phase-1--master-data) into its own doc
- [ ] New ADRs for any decisions made during the phase
- [ ] Root `README.md` setup steps verified from a clean clone

## Related

- [architecture/invariants.md](../architecture/invariants.md)
- [architecture/tech-stack.md](../architecture/tech-stack.md)
- [architecture/deployment.md](../architecture/deployment.md)
- [architecture/authorization.md](../architecture/authorization.md) — permission naming, Teams
- [ADR 0006](../decisions/0006-generated-api-client.md) — why the type pipeline is Phase 0
