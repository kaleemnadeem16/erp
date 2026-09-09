[← Architecture](README.md)

# Deployment

Docker for development and production ([ADR 0013](../decisions/0013-docker-dev-and-production.md)),
built for **both amd64 and arm64** ([ADR 0021](../decisions/0021-multi-architecture-images.md)).

The shared-hosting tier described in earlier revisions has been **withdrawn** — see
[ADR 0020](../decisions/0020-postgresql-only.md). This is a containerised application with
background workers, a formulation engine and a double-entry ledger; it does not run meaningfully
on shared infrastructure.

For the specific machines and pipeline, see [environments.md](environments.md) and
[ci-cd.md](ci-cd.md).

---

## Targets

| Target | Notes |
|---|---|
| **Current VMs** (mainvm + dbvm, ARM64) | Build and staging. [ADR 0017](../decisions/0017-deployment-topology.md) |
| **Customer VPS** (likely x86) | The product's eventual home |
| **Managed containers** (Fly, Railway, Render, Cloud Run) | Take the image directly |
| **Managed VPS** (Forge, Ploi) | Possible, but bypasses the image |
| ~~Shared hosting~~ | **Withdrawn** — no Docker, no workers, no Redis, usually no Postgres |

Requirements for any target: PostgreSQL 16+, Redis, the ability to run background processes,
and a container runtime.

## Architecture portability

The application is architecture-agnostic and must stay that way. Three rules, all from
[ADR 0021](../decisions/0021-multi-architecture-images.md):

1. **Never hardcode an architecture** — no `aarch64`/`x86_64` in base tags, paths or download
   URLs. Use BuildKit's `TARGETARCH` where a build step genuinely needs to know.
2. **Pin base images by index digest**, which resolves per-platform. A platform digest silently
   locks to one architecture.
3. **Build each architecture natively**, then merge manifests.

This is not future-proofing — the development machine is x86_64 and production is ARM64 today.

## Development

```
docker compose (development):
  app        PHP-FPM 8.4 + Laravel        ← same base as existing apps
  nginx      serves Laravel public/ and the built frontend
  postgres   16
  redis      7
  worker     php artisan queue:work
  scheduler  php artisan schedule:work
  node       Vite dev server with HMR
```

### ⚠️ Windows development

**Docker Desktop on Windows runs through WSL2, and cross-boundary file I/O is severely slow.**

A repo under `C:\Users\…` mounts into containers as `/mnt/c/…`. Composer installs, npm installs
and test runs crawl — 10× slowdowns are routine, and it is usually misdiagnosed as Docker being
slow.

**Keep the repository inside the WSL2 filesystem** (e.g. `~/code/erp`), opened via the VS Code
WSL Remote extension. Same editor, dramatically faster. Do this before scaffolding.

The one-shot development `node-volume-init` service makes the Docker-managed `node_modules`
volume and any existing workspace caches writable, then exits. The long-running `node` service
runs npm, Vite, Orval and `docker compose exec node ...` commands as `${HOST_UID:-1000}` and
`${HOST_GID:-1000}`. The defaults match a normal first WSL user and require no setup. A Linux
developer with different IDs starts Compose with `HOST_UID=$(id -u) HOST_GID=$(id -g) docker
compose up -d`; no tracked file needs editing. This prevents bind-mounted generated source from
becoming `root:root`, which would block Git pulls and local edits. Do not override the service
user or run npm/code generation with `docker compose exec --user root node ...`.

## Production

```
docker compose (production):
  app        PHP-FPM 8.4 + Laravel
  worker     php artisan queue:work
  scheduler  php artisan schedule:work
  frontend   nginx serving the built SPA
```

**No `postgres` or `redis` service.** Production connects to dbvm's existing instances
([ADR 0017](../decisions/0017-deployment-topology.md)). A customer deployment would provide its
own, but the compose file still does not embed a database — data lives outside the app.

Containers attach to `proxy-net` with Traefik labels; migrations run as a one-shot container
before the app rolls over. Detail in [ci-cd.md](ci-cd.md#deployment).

## First deploy runbook

Never executed yet. These steps exist because each is a prerequisite the automated
pipeline does **not** cover — CI builds and pushes images but does not deploy
([pipeline.yml](../../.github/workflows/pipeline.yml)).

**On dbvm, once, as a superuser:**

1. Create the database and the application role, then grant it ownership of the schema.
2. `CREATE EXTENSION btree_gist;` in the `erp` database. The `fiscal_years` migration
   needs it and `erp_app` is not a superuser, so migrating first fails with
   *permission denied to create extension*.
3. Agree ERP's own `REDIS_DB` and `REDIS_CACHE_DB` indices with invoiceflow and crm, and
   record them. They share the instance, and `cache:clear` issues `FLUSHDB`, which
   ignores `REDIS_PREFIX` — so a clear on a shared index wipes a neighbour's cache.
   **Keep `cache:clear` and `optimize:clear` out of the deploy sequence entirely.**

**On mainvm, before the first `up`:**

4. Copy `docker-compose.prod.yml` and a production `.env` into the project directory.
   Set at minimum: `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`,
   the dbvm database and Redis hosts, `SESSION_SECURE_COOKIE=true`, `TRUSTED_PROXIES`,
   and `SANCTUM_STATEFUL_DOMAINS`/`CORS_ALLOWED_ORIGINS` matching the real SPA origin.
5. Set the repository variable `ERP_API_BASE_URL` to the public API origin **before**
   the image build — Vite inlines it at build time, so an image built without it
   permanently points the SPA at localhost.
6. Register the self-hosted ARM64 runner with the labels `self-hosted, arm64, erp`
   ([ci-runner-setup.md](ci-runner-setup.md)). The `build` and `merge-manifest` jobs
   target it and will queue forever without it.

**Deploy:**

7. Merge `develop` into `main` — the only branch that builds images
   ([ADR 0029](../decisions/0029-develop-main-branching-model.md)). Wait for both
   architectures and the manifest merge.
8. `docker compose pull`.
9. Run migrations as a one-shot container: `docker compose run --rm app php artisan migrate --force`.
10. `docker compose up -d`.
11. Create the first sign-in — migrations create company 1 but no user and no
    permissions, and `demo:seed` refuses to run outside local/testing:
    `docker compose run --rm app php artisan erp:create-admin --email=… --name=… --permissions=all`.

**Rollback:** redeploy a previous image tag from `main`'s history by setting the image
tag and repeating steps 8 and 10. Migrations are not automatically reversed — check
whether the rollback target predates a schema change before rolling back.

## What a customer deployment needs

Worth stating now, because it constrains design:

- A container runtime
- PostgreSQL 16+ (a managed instance is fine)
- Redis
- A reverse proxy with TLS
- The ability to run two long-lived processes (worker, scheduler)

Everything above is ordinary for a VPS or any managed container platform.

## Related

- [environments.md](environments.md) · [ci-cd.md](ci-cd.md) · [database.md](database.md)
- [ADR 0020](../decisions/0020-postgresql-only.md) — why shared hosting was withdrawn
- [ADR 0021](../decisions/0021-multi-architecture-images.md)
