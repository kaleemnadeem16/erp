[← Decisions](README.md)

# 0128 — Observe the ERP with the existing Prometheus, Loki and Grafana stack

- **Status:** accepted
- **Date:** 2026-10-14
- **Affects:** [observability.md](../architecture/observability.md), `docker-compose.prod.yml`, `docker/backend/`, the application's logging

## Context

Users reported slow screens on the live deployment and nothing could say which layer was responsible. The monitoring
stack on mainvm already covered the host (node-exporter) and the edge (Traefik metrics and access log); it had nothing
about the application, php-fpm or the database. Production application errors were not visible anywhere: php-fpm's stderr
went to an auto-named temp file that supervisor never forwarded, and Laravel wrote to a file nothing shipped.

## Decision

Use the stack that already runs, and add what is missing without a new product:

1. The application writes **one structured line per request** (route pattern, status, duration, query count, database
   time, memory, Cloudflare ray id) and **one line per slow query** (SQL with placeholders, never bindings) to stderr.
   Promtail already ships every container's logs to Loki; Grafana derives percentiles from them.
2. Application errors use a JSON log channel on stderr, and supervisor forwards stderr, so errors reach Loki.
3. php-fpm and nginx expose status on **loopback only**; exporter sidecars share the app container's network namespace and
   Prometheus scrapes them. php-fpm no longer listens on every interface.
4. Traefik's latency histogram uses buckets from 25 ms to 10 s so percentiles are meaningful.
5. One provisioned dashboard, kept as JSON in the repository, reads outermost-in: edge, endpoint, query, workers, host.

## Consequences

- **Positive:** the slow layer can be named from data; production errors are finally visible; no new service or database tables; one php-fpm exposure closed.
- **Negative:** log volume grows (one line per request); percentiles come from logs, not a metrics store, so they are only as good as the retention in Loki; the dashboard is installed on the shared monitoring stack by hand ([observability.md](../architecture/observability.md#installing-it-on-mainvm-done-2026-10-14)).
- **Requires:** never log request bodies, bindings or personal data in these channels; re-check `cf-cache-status` after any Cloudflare change.

## Alternatives considered

**Laravel Pulse** — its own tables in the production database and a second dashboard, for what Grafana already shows.
**A Prometheus client in the application (`/metrics`)** — needs shared storage across php-fpm workers and an authenticated route; log-derived metrics need neither.
**`pg_stat_statements` for query timing** — requires `shared_preload_libraries` and a restart of the Postgres instance shared with other applications; the application-side slow-query log gives per-query time without it. A read-only `postgres_exporter` role (connections, cache hit ratio, locks) is the separate database-side step.
**A hosted APM (Datadog, New Relic)** — new cost and data leaving the host, for a single-host deployment that already has Grafana.

## Related

[observability.md](../architecture/observability.md) · [ADR 0121](0121-public-repository-hosted-runners-and-manual-deploy.md) · [ci-cd.md](../architecture/ci-cd.md)
