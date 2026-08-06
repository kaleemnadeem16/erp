[← Decisions](README.md)

# 0017 — Deployment topology: reuse the existing two-VM setup

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** Phase 0, all deployment and CI

## Context

Two Oracle Ampere ARM64 VMs already run nine Compose projects
([environments.md](../architecture/environments.md)):

- **mainvm** — 3 vCPU / 17 GB / 117 GB (72 GB free). Applications, Traefik v3.6.1 ingress,
  monitoring stack, self-hosted GitHub Actions runner.
- **dbvm** — 1 vCPU / 5.8 GB / 78 GB (68 GB free). PostgreSQL 16.11, MariaDB 10.11, Redis 7.

Everything is containerised; there is no native PHP or Node on either host. Disk on mainvm is
the constrained resource, with ~10 GB currently reclaimable from stale images.

## Decision

Adopt the existing pattern rather than introducing a parallel one.

1. **App tier on mainvm** — backend, queue worker, scheduler, frontend as a Compose project
2. **Data tier on dbvm** — connect to the existing PostgreSQL and Redis. **Do not run our own
   database or cache containers in production.** Local development runs its own via Compose.
3. **Ingress via the existing Traefik** on `proxy-net`, using `Host()` router rules and an
   HTTPS-redirect middleware, matching the invoiceflow label style
4. **Images in GHCR**, tagged by commit SHA, `linux/arm64` only
5. **Build on the self-hosted ARM runner** — cross-building arm64 on hosted x86 runners means
   QEMU emulation and roughly 10× build times
6. **Image retention from day one** — keep the 3 most recent tags, prune build cache

## Consequences

- **Positive:** No duplicated Postgres or Redis. dbvm's instances are already provisioned,
  backed up and monitored.
- **Positive:** Traefik, Prometheus, Loki and Portainer pick the new services up automatically —
  monitoring and TLS come free.
- **Positive:** Sharing the PHP 8.4 Alpine base ([ADR 0016](0016-php-84-alignment.md)) keeps our
  disk footprint to roughly 400–600 MB of unique layers.
- **Negative:** **dbvm has 1 vCPU** and now serves a fourth application. This is the tightest
  resource in the system and it is the database tier. Reporting queries must be indexed
  deliberately; a slow analytical query degrades three other products, not just ours.
- **Negative:** Builds on mainvm compete with production workloads. Mitigated by limiting
  concurrency to one job and capping build cache.
- **Negative:** A shared Redis means key namespacing is not optional. Prefix everything, and
  keep the company-scoped cache keys from
  [multi-tenancy.md](../architecture/multi-tenancy.md) — a collision with invoiceflow or crm
  would be very hard to diagnose.
- **Requires:** the runner is currently repo-scoped to `invoiceflow`. An organisation-level or
  second runner must be registered before CI can run.

## Alternatives considered

**A third VM for this project** — clean isolation. Rejected as unnecessary cost; mainvm has
headroom once stale images are pruned.

**Our own Postgres and Redis containers on dbvm** — better isolation, independent versioning.
Rejected: it duplicates ~850 MB of images and a second Postgres instance on a 1 vCPU host would
compete with the existing one for the same CPU. Separate databases within the existing instance
give sufficient isolation.

**Hosted CI (GitHub-hosted runners)** — no load on mainvm. Rejected: arm64 builds under QEMU are
prohibitively slow, and this is presumably why the self-hosted runner already exists.

**A different ingress (nginx, Caddy)** — rejected outright. Traefik is already terminating TLS
for nine projects; a second ingress would fight it for ports 80/443.

## Related

- [architecture/environments.md](../architecture/environments.md) — the survey
- [architecture/ci-cd.md](../architecture/ci-cd.md)
- [ADR 0016](0016-php-84-alignment.md) · [ADR 0013](0013-docker-dev-and-production.md)
