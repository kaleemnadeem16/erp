[← Roadmap](README.md)

# Phase 0 — Foundation

- **Status:** not started
- **Depends on:** nothing
- **Blocked by:** nothing — **can start immediately**

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

- [ ] **Move the repo into the WSL2 filesystem** (`~/code/erp`) — see [deployment.md](../architecture/deployment.md#-windows-development)
- [ ] `docker-compose.yml` (dev): app, nginx, postgres 16, redis 7, worker, scheduler, node
- [ ] `docker-compose.prod.yml`: **no** database or redis services — connects to dbvm
- [ ] Multi-stage `Dockerfile`, **`linux/amd64` + `linux/arm64`**, `FROM php:8.4-fpm-alpine` pinned by **index** digest
- [ ] Verify the Dockerfile hardcodes no architecture — use `TARGETARCH` where needed
- [ ] Add the `intl` extension (absent from the existing images)
- [ ] `.env.example`
- [ ] `README.md` at repo root with setup steps

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

- [ ] Laravel 12 install, PHP 8.4
- [ ] Module directory structure per [code-structure.md](../architecture/code-structure.md)
- [ ] Pest, Larastan (level 6+), Pint configured
- [ ] `Money` cast using `brick/money`, `decimal(19,4)`

### Company scoping

- [ ] `companies` table, seeded with company `1`
- [ ] `CompanyContext` singleton (returns `1` for now)
- [ ] `BelongsToCompany` trait with global scope + auto-fill on create
- [ ] Company-namespaced cache key helper and storage path helper
- [ ] **Architecture test:** no single-column unique index on a company-scoped table

### Auth & access

- [ ] Sanctum — cookie mode for SPA, token mode for mobile
- [ ] Users, login, logout, password reset
- [ ] `spatie/laravel-permission` — namespaced permissions (`module.action`), **Teams enabled**
      from the first migration, minimal role set → [authorization.md](../architecture/authorization.md)
- [ ] `spatie/laravel-activitylog` wired to a base model

### Cross-cutting mechanisms

- [ ] `uoms` table + conversion service, dimension-guarded → [units-of-measure.md](../domain/units-of-measure.md)
- [ ] `custom_field_definitions` + `custom_fields` JSON column pattern → [extensibility.md](../architecture/extensibility.md#runtime-custom-fields)
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
- [ ] `sqft → sqm` converts correctly; `L → sqm` throws
- [ ] A custom field defined via `custom_field_definitions` appears in the API response with no
      code change
- [ ] Two concurrent requests for a sequence number return different, gapless values
- [ ] The architecture test catches a deliberately added single-column unique index
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
