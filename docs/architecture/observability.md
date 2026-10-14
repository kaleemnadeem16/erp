[← Architecture](README.md)

# Observability — finding which layer is slow

How to tell whether a slow screen is the browser, the network, Cloudflare, Traefik, nginx, PHP, the
application's queries or the database. Built on the Prometheus, Loki, Promtail and Grafana stack that
already runs on mainvm (project `monitoring`); no new product.

## The layers, outermost first

| Layer | What measures it | Where to look |
|---|---|---|
| Browser, TLS, Cloudflare | Browser DevTools (Network → Timing); `curl -w` with `time_connect` / `time_appconnect` / `time_starttransfer` | A fresh connection costs one or two round trips plus TLS; a reused connection is fast. Many requests per screen multiply it. |
| Traefik (server-side latency) | Prometheus `traefik_service_request_duration_seconds` | Dashboard row "Where is the time going". Excludes the user's network. |
| nginx and php-fpm workers | `phpfpm_*` and `nginx_*` via exporters | "max children reached" above zero, or a growing listen queue, means requests wait for a worker (`pm.max_children`). |
| Laravel, per endpoint | One JSON line per request in Loki (`http_request`) | Duration, query count and database time by route. A high query count on one route is an N+1. |
| Database queries | `slow_query` lines (SQL with placeholders, never bindings) | Queries at or above `PERF_SLOW_QUERY_MS` (default 100 ms), with the route. |
| Host | node-exporter | CPU, memory, load. |

Read it outermost-in: if Traefik's p95 is small but the user waits seconds, the time is outside the
server (network, TLS, number of requests). If Traefik's p95 is large, compare it with Laravel's: a gap
means queueing in php-fpm; no gap means look at database time versus total time.

## What the application logs

`App\Http\Middleware\LogRequestPerformance` writes one line per request **after the response is sent**
(`terminate()`), to the `perf` channel (JSON on stderr). Fields: `method`, `route` (the pattern, never
the URL with ids), `status`, `duration_ms`, `slow`, `queries`, `db_ms`, `slow_queries`, `memory_mb`,
`user_id`, `cf_ray` (the Cloudflare ray id, to follow one page load from the edge into the log).
`App\Support\Performance\QueryTimings` counts queries and logs each slow one on its own line.

Never logged: request or response bodies, query bindings, passwords, tokens, email addresses.
`/up` is skipped. Tuning (all optional): `PERF_LOG_ENABLED`, `PERF_SLOW_QUERY_MS`, `PERF_SLOW_REQUEST_MS`
([config/performance.php](../../backend/config/performance.php)).

Application errors use the `json` channel (`LOG_STACK=json` in
[docker-compose.prod.yml](../../docker-compose.prod.yml)) so they reach Loki. **Before this change
production errors were not visible anywhere:** php-fpm's stderr went to an auto-named temp file that
supervisor never forwarded, and the application wrote to a file nothing shipped. `redirect_stderr=true`
in [supervisord.conf](../../docker/backend/supervisord.conf) is the root-cause fix.

## Components

- **Request log and slow-query log** — in the image; shipped by the existing Promtail (it already tails every container).
- **php-fpm pool** ([php-fpm-erp.conf](../../docker/backend/php-fpm-erp.conf)): listens on `127.0.0.1:9000` only (the base image listened on every interface, reachable from any container on a shared network), `pm.status_path`, and a stack dump for any request still running after 3 s.
- **nginx**: `stub_status` on `127.0.0.1:8081` (not routed by Traefik); health checks excluded from the access log.
- **Exporters** — `erp-fpm-exporter` and `erp-nginx-exporter` share the `app` container's network namespace (`network_mode: service:app`), so php-fpm and the status page are never exposed on a network; Prometheus scrapes ports 9253 and 9113 over `monitoring_monitoring-net`.
- **Prometheus** (on mainvm, `/srv/apps/monitoring/prometheus/prometheus.yml`): jobs `erp-php-fpm` and `erp-nginx` (`erp-backend:9253`, `erp-backend:9113`). Traefik's histogram buckets are `0.025 … 10` s so percentiles are meaningful.
- **PostgreSQL** — a `postgres-exporter` container in the monitoring project on mainvm, connecting to dbvm as the `monitoring_exporter` role: login only, no superuser/createdb/createrole, `pg_monitor` membership only (read-only statistics, no table privileges), `CONNECTION LIMIT 3`, `statement_timeout 5s`, `lock_timeout 1s`. Scraped every 30 s (about 50 ms, one connection, 7 MiB). Credentials are in `/srv/apps/monitoring/postgres-exporter.env` (mode 600), never in git; the exporter publishes no port. No application role was changed.
- **Grafana dashboard** — [erp-performance.dashboard.json](../../docker/monitoring/erp-performance.dashboard.json), loaded into `/var/lib/grafana/dashboards` on mainvm (the provider polls every 30 s). Datasources are chosen by dashboard variables, so the JSON holds no instance-specific ids.

## Installing it on mainvm (done 2026-10-14)

Both steps edit the shared monitoring stack, not this repository's compose file:

1. **Scrape jobs** — append `erp-php-fpm` (`erp-backend:9253`) and `erp-nginx` (`erp-backend:9113`) to
   `/srv/apps/monitoring/prometheus/prometheus.yml`, check with
   `docker exec prometheus promtool check config /etc/prometheus/prometheus.yml`, then reload with
   `docker exec prometheus wget -qO- --post-data= http://localhost:9090/-/reload` (lifecycle is enabled; no restart).
2. **Dashboard** — `docker cp docker/monitoring/erp-performance.dashboard.json grafana:/var/lib/grafana/dashboards/erp-performance.json`;
   the file provider picks it up within 30 s. Edit the JSON in the repository and copy it again; do not edit it in the Grafana UI
   (a provisioned dashboard is overwritten on the next copy).

After a deploy, confirm both targets are `up` and every panel query runs (Grafana → Explore, or the
dashboard itself). Panels for slow requests, slow queries, 5xx and errors are legitimately empty when nothing is wrong.

### The database exporter (done 2026-10-14)

dbvm runs PostgreSQL 16 in a container shared with other applications. To rebuild it: create the role on dbvm
(`CREATE ROLE monitoring_exporter LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION CONNECTION LIMIT 3 PASSWORD '…'`,
`GRANT pg_monitor TO monitoring_exporter`, then `ALTER ROLE … SET statement_timeout='5s'`, `lock_timeout='1s'`,
`idle_in_transaction_session_timeout='10s'`), put `DATA_SOURCE_URI`, `DATA_SOURCE_USER`, `DATA_SOURCE_PASS` in
`postgres-exporter.env`, add the service from the monitoring compose file (`prometheuscommunity/postgres-exporter`,
`monitoring-net` only), and add a `postgres` job (30 s) to Prometheus. Check `pg_up` is 1.

**Observed on dbvm, not changed (shared infrastructure, outside this task):** PostgreSQL publishes port 5432 on all
interfaces with `listen_addresses=*`, `pg_hba` accepts any address with a password (`scram-sha-256`), and `ssl=off`.
Whether the port is reachable from the internet depends on the cloud network rules, which this repository cannot see;
confirm they restrict 5432 to the private network, and consider requiring TLS and narrowing `pg_hba` to mainvm's private address.

## Decision and alternatives

Use the stack already running and put the application's own timings into Loki as structured logs.
*Laravel Pulse* was rejected for now: its own tables in the production database and a second dashboard,
for what Grafana already shows. *An application `/metrics` endpoint* (Prometheus client in PHP) needs shared
storage across php-fpm workers and an authenticated route; log-derived metrics need neither. *`pg_stat_statements`*
needs `shared_preload_libraries` and a restart of the Postgres instance shared with other applications; the
application-side slow-query log already gives per-query time without it. A `postgres_exporter` (connections,
cache hit ratio, locks) is the remaining database-side step and needs a read-only monitoring role on dbvm.
Recorded as [ADR 0128](../decisions/0128-observe-with-the-existing-grafana-loki-stack.md).

## Caching and stale data — a reminder, not a change

Many people edit the same data, so **no layer in front of the API may cache API responses.** Current state
(verified 2026-10-13): hashed files under `/assets/` are `immutable` for a year (safe: a changed file gets a
new name); `index.html`, `sw.js`, `registerSW.js` and `manifest.webmanifest` are `no-cache` (so a deploy is
picked up and the service worker never references removed assets); API responses carry `no-cache, private`;
Cloudflare reports `cf-cache-status: DYNAMIC` for both hosts. Cloudflare's default caches only static file
extensions, so it caches neither JSON nor HTML.

When adjusting Cloudflare (free plan), keep these true:
1. **Never add a Cache Rule that caches `/api/*`, the API hostname or `index.html`** ("Cache Everything", Edge Cache TTL overrides).
2. Leave **Browser Cache TTL** on *Respect Existing Headers*.
3. Leave **Always Online** off for the app hostnames (it serves stale copies when the origin is down).
4. Safe to enable: HTTP/3, 0-RTT off unless understood, Brotli, "Always Use HTTPS", Early Hints.
5. After any Cloudflare change, re-check `curl -sI` on `/`, an `/assets/…` file and an API route: `cf-cache-status` must stay `DYNAMIC` for the HTML and API.
6. If the application ever adds server-side caching (Redis, response or query caches), every cached read of shared
   data needs an invalidation on every write path and a short TTL; settle that in a design note first.

## Related

[ci-cd.md](ci-cd.md) · [deployment.md](deployment.md) · [environments.md](environments.md) ·
[testing-standards.md](testing-standards.md)
