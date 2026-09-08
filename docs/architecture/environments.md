[← Architecture](README.md)

# Environments

Surveyed **2026-07-29** by direct inspection of both machines. Re-verify before relying on it —
this is a snapshot, not a live source of truth.

Both VMs are **Oracle Ampere, ARM64 (`aarch64`)**.

> **These VMs are the build and staging environment, not the product's permanent home.** Once
> the ERP is sellable it will run on customer infrastructure, most likely x86. The development
> machine is *already* x86_64. Images therefore build for **both** `linux/amd64` and
> `linux/arm64` — see [ADR 0021](../decisions/0021-multi-architecture-images.md).

---

## mainvm — application tier

| | |
|---|---|
| Arch | aarch64 (Ampere) |
| CPU / RAM | 3 vCPU / 17 GB (14 GB available) |
| Disk | 117 GB — **45 GB used, 72 GB free (39%)** |
| Docker | 29.0.1, Compose v2.40.3 |
| Ingress | **Traefik v3.6.1** on the `proxy-net` network |
| CI | Self-hosted GitHub Actions runner, user `github-runner`, `~/actions-runner` |

### Already running

Nine Compose projects: `invoiceflow`, `x1techs-crm-app`, `n8n`, `monitoring`
(Grafana/Loki/Prometheus/promtail/node-exporter), `traefik`, `portainer`, `pgadmin`,
`phpmyadmin`, `x1techs-com` (WordPress).

**No native PHP, Node or Composer** — everything is containerised. This matters: containerising
our app adds no duplication versus an existing native install, because there isn't one.

### PHP in the existing Laravel apps

| App | PHP | Base |
|---|---|---|
| invoiceflow-backend | 8.4.21 | Alpine 3.23.4 aarch64 |
| crm_backend | 8.4.20 | Alpine, aarch64 |

Extensions present in both:

```
bcmath ctype curl dom fileinfo gd(*) mbstring opcache pcntl
pdo_pgsql pdo_sqlite redis sodium zip mysqlnd
```
*(\*gd in invoiceflow only)*

**Missing and required by us:** `intl` (Laravel localisation and number formatting). `pdo_pgsql`
is present, which is all we need since dropping the MySQL family
([ADR 0020](../decisions/0020-postgresql-only.md)).

### Disk usage

`docker system df` reports:

```
Images        49 total, 17 active   34.03 GB   10.12 GB reclaimable (29%)
Build cache   107 entries            2.46 GB    0.81 GB reclaimable
Volumes       16 total,  7 active    1.83 GB    0.19 GB reclaimable
```

⚠️ **These are logical sizes and overstate real disk usage.** Measured on disk:

```
/var/lib/docker    15 GB total   (of which volumes 1.8 GB)
/                  45 GB used of 117 GB
```

Docker's `SIZE` column counts every layer once **per image**, so layers shared between images are
counted repeatedly — 34 GB reported against ~13 GB of actual image data. `RECLAIMABLE` is
computed the same way and is inflated by the same factor.

**Realistic reclaim from pruning unused images and build cache: roughly 4–6 GB**, not the
10–11 GB the summary implies. Always verify with `df -h /` before and after.

The underlying issue is still real — there is **no image retention policy**. 10 stale
`invoiceflow-backend` tags, 10 stale frontend tags, two superseded n8n versions, and a genuine
orphan: `phpmyadmin/phpmyadmin:latest` (1.09 GB), left over from before the running `phpmyadmin`
container switched to `lscr.io/linuxserver/phpmyadmin` (232 MB) — two different images, only one
in use. Images are tagged by commit SHA and never pruned, so this grows without bound.

`pgadmin` (`dpage/pgadmin4`) is running and correctly connects to dbvm's PostgreSQL over the
network — admin tooling on the app tier, data on the db tier, as intended.

See [ci-cd.md](ci-cd.md#image-retention) for the retention policy and the safe cleanup procedure.

---

## dbvm — data tier

| | |
|---|---|
| Arch | aarch64 (Ampere) |
| CPU / RAM | **1 vCPU** / 5.8 GB (4.7 GB available) |
| Disk | 78 GB — 11 GB used, **68 GB free (14%)** |
| Docker | 29.0.1 |

### Already running

| Service | Image | Version |
|---|---|---|
| PostgreSQL | `x1-postgres-postgis:16.11-postgis3.6.4` | **16.11** + PostGIS 3.6.4 |
| MariaDB | `mariadb:10.11` | **10.11** |
| Redis | `redis:7-bookworm` | **7** |
| Portainer agent | `portainer/agent:latest` | |

⚠️ **1 vCPU.** This is the constrained resource in the whole setup, and it is the database tier.
Heavy reporting queries will contend with three other applications. Index carefully, and treat
any long-running analytical query as a problem to solve rather than tune later.

---

## Consequences for this project

### Version alignment

| Component | Existing | Our spec | Action |
|---|---|---|---|
| PHP | **8.4** | was 8.3 | **Align to 8.4** — [ADR 0016](../decisions/0016-php-84-alignment.md) |
| PostgreSQL | **16** | 16 | ✅ matches |
| Redis | **7** | 7 | ✅ matches |
| MariaDB 10.11 | present | — | **Not used.** MySQL family dropped — [ADR 0020](../decisions/0020-postgresql-only.md) |
| Architecture | arm64 | amd64 + arm64 | **Multi-arch** — [ADR 0021](../decisions/0021-multi-architecture-images.md) |

### Image layer sharing

Docker shares layers between images **only when the layer digests are identical** — which in
practice means starting `FROM` the same base image at the same digest.

Matching the existing base (`php:8.4-fpm-alpine`, Alpine 3.23) means our unique layers are just
our extensions, `vendor/` and application code — roughly **400–600 MB**, not the ~1.6 GB an
unrelated base would cost.

Two conditions:

- **Pin by index digest, not platform digest.** An index digest resolves per-architecture; a
  platform digest locks the image to one and breaks the other build.
- **Keep the base in step with the other apps.** A drifting `:latest` defeats sharing silently.

Sharing applies per-architecture — the arm64 variant shares with invoiceflow and crm on mainvm;
the amd64 variant has nothing to share with there, which is fine since it does not run there.

### Do not run our own Postgres or Redis in production

dbvm already runs both. Our production Compose file connects to them; it does not start its own.
Local development runs its own Postgres via Compose — a dev-only concern.

### Development demo data

Demonstration data is deliberately opt-in and separate from Laravel's ordinary
`DatabaseSeeder`, which stays empty. After migrations, run:

```bash
docker compose exec app php artisan demo:seed
```

The command is idempotent: stable codes update the records owned by the demo dataset without
deleting unrelated local records. Its orchestrator calls small seeders for Foundation,
Accounting, Parties, Inventory and Purchasing in dependency order; a new module adds its own
seeder to that list. Both the command and every module seeder refuse to run unless Laravel's
environment is `local` or `testing`, so a normal production `db:seed` cannot install demo data.
The demo login defaults to `user@erp.x1techs.com` / `!User@Erp@123`; local environment variables
`DEMO_USER_EMAIL`, `DEMO_USER_PASSWORD` and `DEMO_USER_NAME` may override it.

### Follow the existing deployment pattern

Traefik on `proxy-net` with `Host()` routing rules and an HTTPS-redirect middleware; images in
GHCR tagged by commit SHA; Compose project per app. Consistency with the existing nine projects
is worth more than any improvement we might invent.

## Related

- [deployment.md](deployment.md) · [ci-cd.md](ci-cd.md)
- [ADR 0017 — Deployment topology](../decisions/0017-deployment-topology.md)
- [database.md](database.md)
