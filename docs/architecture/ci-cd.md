[← Architecture](README.md)

# CI/CD

Built to match the existing pipeline pattern on [mainvm](environments.md), not to replace it.

---

## Runners: one per architecture

Images are built for **both** `linux/amd64` and `linux/arm64`
([ADR 0021](../decisions/0021-multi-architecture-images.md)) — the development machine is x86,
the current VMs are ARM, and a customer's VPS is more likely x86 than either.

Each architecture builds **natively**, then the manifests are merged:

| Platform | Runner |
|---|---|
| `linux/arm64` | Self-hosted Ampere runner on mainvm |
| `linux/amd64` | GitHub-hosted `ubuntu-latest` |

Cross-building either one under QEMU makes `composer install` and `npm ci` several times slower
— often 10–20 minutes instead of 2 — which means it eventually gets skipped.

mainvm already runs a self-hosted runner (`github-runner`, `~/actions-runner`), currently
**scoped to the `invoiceflow` repository**.

**Decision:** invoiceflow, the CRM and this ERP repo all sit under a **personal GitHub account**,
not an organisation — GitHub does not offer org-level runners for personal accounts. So this
project registers a **second, independent repo-scoped runner** on mainvm, alongside the existing
one rather than sharing it.

**Done (2026-08-11)** — `mainvm-erp-arm64` is registered and running; confirmed online again
2026-09-22. Step-by-step setup → [ci-runner-setup.md](ci-runner-setup.md).

### Runner constraints

mainvm has 3 vCPU and runs production workloads. Builds compete with them.

- Limit concurrent jobs to 1
- Cap build cache and prune on a schedule (see below)
- Keep the test job light — see [Tests](#tests)

---

## Branching and deploy gating

Two long-lived branches ([ADR 0029](../decisions/0029-develop-main-branching-model.md)):

- **`develop`** — default working branch. `feature/*` branches PR into it. Push freely; this is
  the sync mechanism. **Never deploys.**
- **`main`** — deploy-triggering only. A `develop → main` merge is the deliberate act that starts
  a deploy. `hotfix/*` branches off `main` for urgent production fixes, then back-merge into
  `develop`.

A commit landing on GitHub is not the same act as a commit going live — only a merge to `main`
deploys.

## Pipeline

```
push / PR (any branch)
   ├─ lint        Pint, Larastan L6, ESLint, tsc
   ├─ codegen     Scramble → OpenAPI → Orval; FAIL ON DIFF
   └─ test        PostgreSQL 16 service container

main branch only ──→ build   amd64 (hosted) ┐
                                arm64 (self-hosted) ┴→ merge manifest → GHCR, tagged by commit SHA
                                                  │
                      ──────────────────────────┴─→ deploy to mainvm → prune old images
```

Image build and deploy are scoped to `main` only — a WIP push to a feature branch or `develop`
gets full lint/codegen/test feedback without tying up the single-concurrency ARM runner
building an image nobody's going to deploy (see [runner constraints](#runner-constraints) above).

### Tests

**PostgreSQL 16 only** ([ADR 0020](../decisions/0020-postgresql-only.md)). The former dual-engine
matrix is gone, roughly halving CI time.

Postgres runs as a **service container**, never the dbvm instances. **CI never touches
production databases.**

The Playwright job sets `API_RATE_LIMIT_PER_MINUTE=10000`, as the development stack does.
Its scripted page loads exceed the production limit of 120/min and otherwise make the
identity endpoint return 429, redirecting valid browser sessions to sign-in. Production
continues to use the default limit.

The 2026-10-07 pipeline repair formats the packaged-purchasing migrations, corrects
Larastan annotations (including JSON offer snapshots, model relations and record scopes),
and removes a demo receipt's unsupported `notes` attribute. Receipt scenarios remain
identified by their idempotency keys and the linked purchase order's explanatory notes;
no receipt schema extension is needed. Verify the complete demo seeder against a freshly
migrated isolated database as well as its existing outcome/rerun tests: this is the setup
the browser job actually runs. Removing the always-true catalogue abort also removes its
unreachable 403 response from the regenerated client. Permission vocabulary and access
behaviour are unchanged: the package catalogue remains open reference data, as recorded
in `OpenReadRoutesTest`; maintenance retains its capability guards. No new grant is needed.
The Artisan isolation test clears an inherited `TEST_DB_DATABASE` in its subprocess so
its default-database assertion also works when the parent suite uses a private test database.
Larastan runs with `--memory-limit=1G`: a fresh analysis of the expanded backend can
exceed PHP's default 256 MB worker limit even when an incremental analysis passes.
Validation: Pint and Larastan pass; frontend typechecks/build, fresh migration/seed,
unseeded rollback/reapply and repeat client generation pass. The full backend run passed
2,155 tests; its one inherited-database assertion passed on a focused rerun after the
isolation fix. Playwright itself was not rerun.

What replaced the portability matrix as a discipline: migrations are reviewed for lock duration,
and any query against a table expected to exceed ~100k rows gets an `EXPLAIN` in review — dbvm
has 1 vCPU.

### Codegen drift check

```
php artisan scramble:export && npx orval && git diff --exit-code
```

A backend field change that wasn't regenerated fails the build here rather than surfacing as
`undefined` in the browser. This is the mechanism [ADR 0006](../decisions/0006-generated-api-client.md)
depends on — without the CI check, the generation step is optional and will be skipped.

---

## Images

Tagged by commit SHA, matching the existing convention:

```
ghcr.io/<owner>/erp-backend:<sha>
ghcr.io/<owner>/erp-frontend:<sha>
```

`latest` moves on successful deploy. The previous SHA stays available for rollback — rollback is
redeploying a tag, never rebuilding.

### Image retention

**The existing projects have no retention policy** — 10 stale `invoiceflow-backend` tags, 10
stale frontend tags, and superseded images from replaced services
([environments.md](environments.md#disk-usage)). Tagged by commit SHA, never pruned, growing
without bound.

Our pipeline must not repeat that:

```yaml
# after successful deploy — keep the 3 most recent tags per image
- docker image prune -af --filter "until=168h" --filter "label=app=erp"
- docker builder prune -af --keep-storage 2GB
```

Plus a weekly scheduled workflow across all `erp-*` images, and GHCR retention configured to
delete untagged versions.

### Safe cleanup of the existing host

Out of scope for this project but worth doing. **Realistic reclaim is 4–6 GB**, not the 10+ GB
`docker system df` implies — see [environments.md](environments.md#disk-usage) for why the
reported figure is inflated.

Ordered by risk:

| Risk | Action | Notes |
|---|---|---|
| **None** | `docker builder prune -af --keep-storage 1GB` | Only slows the next build |
| **None** | Remove `phpmyadmin/phpmyadmin:latest` (1.09 GB) | **Orphaned duplicate.** The running `phpmyadmin` container uses `lscr.io/linuxserver/phpmyadmin` (232 MB) — a different image. This one is attached to nothing. |
| **None** | Remove `traefik:v3.0` | Superseded by the running `v3.6.1`; referenced only in a stale editor history file, not a real compose file |
| **None*** | Remove `postgres:16`, `redis:7-bookworm`, `php:8.4-cli`, `alpine` | No running container uses them, **but** they're referenced by invoiceflow/crm's dev-compose and Dockerfiles. Safe to remove — nothing breaks — but the next CI run or local dev startup re-pulls them. Small time cost, not a risk. |
| **Low** | Remove all but the 3 most recent `invoiceflow-*` SHA tags | **Each removed tag is a rollback target you lose** |
| **Low** | Remove superseded n8n versions (2.14.2, 2.21.3) | Same — these are n8n rollback targets |

pgAdmin (`dpage/pgadmin4`) is running and correctly connects to dbvm's PostgreSQL over the
network — admin UI on the app tier, data on the db tier. Not a candidate for removal.

**Never run these:**

- **`docker system prune -a`** — removes *every* image not currently running, including all
  rollback tags. `crm_backend:rollback` and `crm_frontend:rollback` are distinct image IDs from
  their `:latest` counterparts, so they are genuinely at risk.
- **`docker volume prune`** — several volumes show `LINKS=0` but hold real data, notably
  `x1techs-crm-app_crm_redis_data`. A volume with no attached container is *not* the same as an
  unused volume. Inspect and remove individually.

Removing an unused image **cannot** affect a running container: Docker refuses to delete an image
a container references, so the blast radius is bounded by design. The genuine risks are losing
rollback targets and deleting volumes.

Verify with `df -h /` before and after, and `docker ps` afterwards.

---

## Deployment

Compose project per app, matching the existing nine on mainvm.

```
/srv/apps/erp/
  docker-compose.yml          production
  .env                        secrets — never in the repo
```

`/srv/apps/erp` is owned by the `github-runner` OS user that runs the deploy job — the same
convention the existing `invoiceflow` deploy on this host already uses
([ci-runner-setup.md](ci-runner-setup.md)). A path under a human user's home directory (the
original choice here) doesn't work: `github-runner` isn't in that user's group and can't
traverse into their home directory at all, regardless of the target folder's own permissions.
Don't route around that with `sudo` to hop accounts either — that means granting a CI runner
passwordless root access, which is worse than just owning the directory it operates on.

`docker-compose.yml` on the server is not hand-maintained — the deploy job copies the
repo's [docker-compose.prod.yml](../../docker-compose.prod.yml) over it fresh on every run, so
the two can never drift. Only `.env` (secrets) lives solely on the server.

Containers attach to `proxy-net` and carry Traefik labels in the existing style:

```yaml
labels:
  traefik.enable: "true"
  traefik.docker.network: "proxy-net"
  traefik.http.routers.erp-api.rule: "Host(`api-erp.<domain>`)"
  traefik.http.routers.erp-api.entrypoints: "websecure"
  traefik.http.routers.erp-api-http.middlewares: "erp-redirect-https"
```

### Near-zero-downtime on a single replica

Plain Compose (no Swarm, no multi-replica — see [runner constraints](#runner-constraints))
recreates a service in place on every deploy: stop the old container, start the new one. Three
things close that gap without adding orchestration:

- **Graceful shutdown** — `docker/backend/supervisord.conf` sets `stopsignal=QUIT` for both
  `nginx` and `php-fpm`. Docker sends `SIGTERM` on recreate; both processes treat plain `TERM`
  as an immediate/fast shutdown that drops in-flight requests, where `QUIT` finishes them first.
- **Traefik won't route to a container until it's actually ready** — each service's
  `loadbalancer.healthcheck.path` (`/up` for the API, `/` for the frontend) means a freshly
  started container isn't added to the pool the instant Docker reports it running, only once it
  responds.
- **A `retry` middleware** on both routers re-sends a request that still lands in the narrow
  remaining gap against the (by then updated) pool, rather than surfacing the failure to the
  browser as `ERR_CONNECTION_RESET`/`ERR_CONNECTION_CLOSED`.

The deploy job itself also passes `--wait` to `docker compose up -d`, so it doesn't run
`permission:register` against a container its own healthcheck hasn't yet confirmed is healthy.

None of this is a substitute for the frontend's PWA caching being correct. The app registers a
service worker (`vite-plugin-pwa`); `docker/frontend/nginx.conf` sets `Cache-Control: no-cache`
on `index.html`/`sw.js`/`registerSW.js`/`manifest.webmanifest` since they have stable filenames,
unlike everything content-hashed under `/assets/`. A stale cached copy of any of them means the
browser can run a service worker whose precache manifest references asset hashes a later deploy
has already removed outright — each deploy replaces the container's filesystem completely,
nothing from the previous build persists.

Production Compose connects to **dbvm's** PostgreSQL and Redis. It does not start its own —
see [environments.md](environments.md#do-not-run-our-own-postgres-or-redis-in-production).

### Migrations

Run as a one-shot container before the app rolls over, not in the container entrypoint —
otherwise three replicas race to migrate the same database.

PostgreSQL has transactional DDL, so a failed migration rolls back cleanly. Large data backfills
still belong in their own migration — not for correctness, but because a backfill inside a schema
migration holds locks for its full duration. See [database.md](database.md).

---

## Secrets

GitHub Actions secrets for CI. On the host, a `.env` file outside the repo.

No secrets in the image, no secrets in the repo, no secrets in build args (build args persist in
image history and are readable by anyone who can pull the image).

## Related

- [environments.md](environments.md) — the machines this runs on
- [deployment.md](deployment.md) — artifacts and targets
- [ADR 0017](../decisions/0017-deployment-topology.md) · [ADR 0018](../decisions/0018-single-repository.md)
- [ADR 0021](../decisions/0021-multi-architecture-images.md) — why two runners
- [ADR 0022](../decisions/0022-code-documentation-strategy.md) — `docs/reference/` generation also runs here
