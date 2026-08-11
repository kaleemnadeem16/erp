# ERP

Formulation-based estimating, job costing and field service delivery. Laravel API backend +
React SPA frontend.

**Planning and architecture docs live in [docs/](docs/README.md).** Start there for anything
beyond "how do I run this" — domain model, decisions, phase roadmap.

## Prerequisites

- Docker Desktop, with the WSL2 integration enabled for your distro
- The repository checked out **inside the WSL2 filesystem** (e.g. `~/projects/erp`), not under
  `C:\Users\…` — cross-boundary file I/O is severely slow. See
  [deployment.md](docs/architecture/deployment.md#-windows-development).

## Quick start

```bash
cp backend/.env.example backend/.env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

- API: http://localhost:8080
- Frontend dev server: http://localhost:5173 (once the frontend skeleton exists — see
  [phase-0-foundation.md](docs/phases/phase-0-foundation.md))

`vendor/` and `node_modules/` live in named Docker volumes, not the bind mount — first boot
installs them automatically (see `docker/backend/entrypoint.dev.sh`). A code change on the host
is reflected immediately; a `composer.json`/`package.json` change needs a container restart to
reinstall.

## Common commands

```bash
docker compose exec app php artisan test          # Pest
docker compose exec app ./vendor/bin/pint          # format
docker compose exec app ./vendor/bin/phpstan analyse  # Larastan, level 6+
```

## Repository layout

```
erp/
  docs/                 architecture, decisions, domain, phases — read AGENTS.md first
  backend/              Laravel API
  frontend/              pnpm workspace — packages/core-ui, core-api; apps/*
  docker/                Dockerfiles and container config, backend/ and frontend/
  docker-compose.yml     development
  docker-compose.prod.yml  production — no database/redis, connects to dbvm
```

See [ADR 0018](docs/decisions/0018-single-repository.md) for why docs and code share one repo,
and [code-structure.md](docs/architecture/code-structure.md) for what goes inside `backend/app/`.

## Production

Builds to `linux/amd64` + `linux/arm64` (see [ADR 0021](docs/decisions/0021-multi-architecture-images.md)),
deployed as its own Compose project alongside invoiceflow and crm on mainvm — see
[ci-cd.md](docs/architecture/ci-cd.md) and [environments.md](docs/architecture/environments.md).
