[← Architecture](README.md)

# Tech Stack

Every dependency and why it's here. Adding one means adding a row.

## Backend

| Package | Purpose | Notes |
|---|---|---|
| Laravel 12 / **PHP 8.4** | Framework | Aligned with existing infra — [ADR 0016](../decisions/0016-php-84-alignment.md) |
| `laravel/sanctum` | Auth — cookies for web, tokens for mobile | [ADR 0005](../decisions/0005-sanctum-authentication.md) |
| `dedoc/scramble` | Generates OpenAPI from controllers, no annotations | [ADR 0006](../decisions/0006-generated-api-client.md) |
| `spatie/laravel-permission` | Roles and permissions | Core ERP requirement |
| `spatie/laravel-activitylog` | Audit trail | Non-negotiable for ERP |
| `brick/money` | Money value object | Never float — [ADR 0012](../decisions/0012-multi-currency-dual-amounts.md) |
| `maatwebsite/excel` | Import/export | Memory-hungry — always chunk |
| `spatie/browsershot` | PDF generation | Headless Chrome — now viable, shared hosting withdrawn ([ADR 0020](../decisions/0020-postgresql-only.md)) |
| `laravel/socialite` | Outbound OAuth2 (Gmail etc.) | Phase 7 |
| `pestphp/pest` | Testing | |
| `larastan/larastan` | Static analysis | Level 6+ |
| `laravel/pint` | Formatting | |

## Frontend

| Package | Purpose | Notes |
|---|---|---|
| React 19 + TypeScript | UI | |
| Vite | Build | |
| `@tanstack/react-query` | Server state | Replaces Redux for ~90% of the app |
| `zustand` | Client state | Small: auth user, UI prefs, active company |
| `@tanstack/react-table` | Data grids | ERP is mostly tables |
| `react-hook-form` + `zod` | Forms and validation | |
| Tailwind CSS v4 | Styling | |
| shadcn/ui | Components | Copied in, not a dependency — fully editable |
| `orval` | Generates typed query hooks from OpenAPI | [ADR 0006](../decisions/0006-generated-api-client.md) |
| `vite-plugin-pwa` | Installable, offline shell | |
| Capacitor | Native mobile shell | Phase 7 — [ADR 0015](../decisions/0015-capacitor-for-mobile.md) |

## Infrastructure

| Component | Choice | Notes |
|---|---|---|
| Architecture | **linux/amd64 + linux/arm64** | Dev is x86, prod is ARM — [ADR 0021](../decisions/0021-multi-architecture-images.md) |
| Base image | `php:8.4-fpm-alpine`, **pinned by index digest** | Shares layers with existing apps — [ADR 0016](../decisions/0016-php-84-alignment.md) |
| Extra PHP extensions | `intl` | Not in the existing images |
| Database | **PostgreSQL 16 only** | MySQL family dropped — [ADR 0020](../decisions/0020-postgresql-only.md) |
| Cache / queue / session | Redis 7 | Shared instance on dbvm — **namespace all keys** |
| Search | Native Postgres `tsvector` + GIN | Scout no longer needed — it was a portability abstraction |
| Containers | Docker 29 + Compose v2 | [ADR 0013](../decisions/0013-docker-dev-and-production.md) |
| Ingress | Existing Traefik v3.6 on `proxy-net` | [ADR 0017](../decisions/0017-deployment-topology.md) |
| Registry | GHCR, tagged by commit SHA | With retention — [ci-cd.md](ci-cd.md#image-retention) |
| CI | Two native runners (ARM self-hosted + hosted x86); PostgreSQL only | [ci-cd.md](ci-cd.md) |

## Deliberately not used

| Not using | Instead | Why |
|---|---|---|
| Inertia.js | Separate SPA | Mobile + integrations need an API — [ADR 0002](../decisions/0002-api-first-react-spa.md) |
| Laravel Sail | Hand-rolled Compose | Production build needed anyway; two setups is worse than one |
| `nwidart/laravel-modules` | Plain PSR-4 folders | Magic costs more than structure saves — [ADR 0014](../decisions/0014-modular-monolith.md) |
| Redux | TanStack Query + Zustand | Most ERP state is server state |
| `laravel/scout` | Native Postgres full-text search | Scout was a portability abstraction; Postgres-only removes the need |
| MySQL / MariaDB | PostgreSQL only | [ADR 0020](../decisions/0020-postgresql-only.md) |
| Kubernetes | Compose on a VPS | Solves problems this project won't have |
| Laravel Passport | Sanctum | Integrations are outbound; we're the OAuth client |

## Related

- [deployment.md](deployment.md)
- [code-structure.md](code-structure.md)
- [phases/phase-0-foundation.md](../phases/phase-0-foundation.md)
