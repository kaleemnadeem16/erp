[← Decisions](README.md)

# 0013 — Docker for development and production

- **Status:** accepted — **partially amended by [0020](0020-postgresql-only.md) and [0021](0021-multi-architecture-images.md)**
- **Date:** 2026-07-29
- **Affects:** Phase 0, all deployment

> **Amended 2026-07-29.** The Docker decision below stands. Two parts no longer apply: the
> **shared-hosting zip artifact is withdrawn** ([0020](0020-postgresql-only.md)), and images are
> built for **both amd64 and arm64**, not arm64 only ([0021](0021-multi-architecture-images.md)).

## Context

Development happens on Windows. The stack needs PHP 8.3 with specific extensions, **both**
PostgreSQL and MySQL ([0003](0003-postgres-with-mysql-compatibility.md)), Redis, Node, a queue
worker and a scheduler.

Separately, the product will be deployed per customer, each with a different plugin package
([0010](0010-core-plus-plugins.md)).

## Decision

Docker for both development and production.

- **Development:** Compose with app, nginx, postgres, mysql, redis, worker, scheduler and Vite
- **Production:** multi-stage image (Node build → PHP deps → runtime), deployed per customer

Shared hosting remains supported as a **separate, non-containerised zip artifact** with
documented feature degradation. Docker and shared hosting are mutually exclusive — shared hosts
provide no root, no daemon and no kernel namespace access.

## Consequences

- **Positive:** Dual-database testing is two services and an environment variable rather than a
  hostile local setup.
- **Positive:** Each customer's deployment is a tagged image built from core plus their plugin.
  Rollback is redeploying a previous tag. Without this, N servers drift independently in their
  Composer state.
- **Positive:** Onboarding is `docker compose up`.
- **Negative:** **Docker Desktop on Windows runs through WSL2, and cross-boundary file I/O is
  severely slow.** A repo under `C:\Users\...` mounts as `/mnt/c/...` and Composer installs,
  npm installs and test runs crawl — 10× slowdowns are routine, and people wrongly conclude
  Docker itself is slow. **The repository must live inside the WSL2 filesystem** (e.g.
  `~/code/erp`), opened via the VS Code WSL Remote extension.
- **Negative:** Two build pipelines to maintain (image and zip). Accepted as the cost of keeping
  the shared-hosting tier.

## Alternatives considered

**Native development, no Docker** — rejected. On Windows this means PHP extension management by
hand and no realistic way to run two database engines side by side.

**Laravel Sail for dev, custom Dockerfile for prod** — Sail is faster to start. Rejected because
a production multi-stage build is needed regardless, and maintaining two parallel container
setups is worse than hand-rolling one.

**Docker for dev, Forge/Ploi native for production** — genuinely reasonable, and simpler to
operate for a single deployment. Rejected because per-customer plugin builds are the core
operational problem, and images solve it directly. Revisit if the plugin model doesn't
materialise.

**Kubernetes** — rejected. Compose on a VPS is sufficient for years; K8s solves problems this
project will not have.

## Related

- [architecture/deployment.md](../architecture/deployment.md)
- [0010 — Core + plugins](0010-core-plus-plugins.md)
