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
| CI | Self-hosted GitHub Actions runner, user `github-runner`, `~/actions-runner`, scoped to `invoiceflow` only — the ERP repository has none ([ADR 0121](../decisions/0121-public-repository-hosted-runners-and-manual-deploy.md)); ERP deploys with `deploy/deploy.sh` |

### Already running

Nine Compose projects, including this repository's sibling applications, a monitoring stack,
Traefik and database admin tools (named in the operator's private notes, not here).

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

> **Demo environment (deferred, [ADR 0129](../decisions/0129-demo-and-sandbox-data-live-in-a-separate-demo-deployment.md)):** the long-term home for people trying the product is a **separate demo deployment** (own database, own hostnames, resettable), to be built when the product nears completion. Until then `demo:seed` is for local development, production Company 1 keeps the demo data it already has, and **`demo:seed` must not be re-run against production** — the seeders hard-code company 1, overwrite company settings and delete-and-rebuild some records, and the append-only database triggers make an in-place reset impossible.

Demonstration data is deliberately opt-in and separate from Laravel's ordinary
`DatabaseSeeder`, which stays empty. After migrations, run:

```bash
docker compose exec app php artisan demo:seed
```

The command is idempotent: stable codes (or, where a screen has no natural code, a marker like
`GoodsReceipt.idempotency_key` or `PhysicalCount.reference`) update or skip the records owned by
the demo dataset without deleting unrelated local records. Its orchestrator calls small seeders
for Foundation, Accounting, Parties, Inventory, Pack Conversions, Purchasing, Packaged Purchasing, Stock, the
negative-stock and recalculation lifecycles, Stock Transfers and Estimating in dependency order
(Pack Conversions before Purchasing because some item-supplier rows buy in a pack unit; the
lifecycles after Stock because they reuse that seeder's actors and resting policy; the stock
workflow seeders share `SeedsDedicatedStockItems`, one dedicated item per scenario); a new module
adds its own seeder to that list. Both the command and every module seeder
refuse to run unless Laravel's environment is `local` or `testing`, so a normal production
`db:seed` cannot install demo data. The demo login defaults to `user@erp.x1techs.com` /
`!User@Erp@123`; local environment variables `DEMO_USER_EMAIL`, `DEMO_USER_PASSWORD` and
`DEMO_USER_NAME` may override it. `StockDemoSeeder` grants this login every
`inventory.physical_count.*`, `inventory.cutover.*`, `inventory.negative-stock.*` and
`inventory.recalculation.*` permission those screens gate on — without it the demo login could
not use any of the four workflows at all.

#### Demo data is part of the feature, not a later cleanup task

**A meaningful new domain feature ships with representative demo data in the same slice**,
exactly like it ships with tests and docs. Add the module's seeder(s) when the feature is built,
not retroactively — this is what keeps `demo:seed` trustworthy as the codebase grows instead of
becoming a stale snapshot of whatever existed when someone last remembered to update it.

Demo data exists to be **manually inspected**, not just to make row counts non-zero. Prefer a
small set of intentional, named scenarios that exercise the feature's real decision points over
a large batch of interchangeable rows — a scenario nobody can distinguish from another proves
nothing when something looks wrong in the UI. When a feature has a resolution/fallback/state
algorithm, the demo data should let someone exercise every branch of it by hand: Estimating's
`ApplicationRatesDemoSeeder` (`database/seeders/Demo/Estimating/`) is the reference example — one
item, tagged rates and an untagged default deliberately shaped so the resolution-tester screen
demonstrates an exact match, a required-dimensions-only fallback, the default fallback, *and* a
genuine loud failure (a second work unit with no default configured, since once any default
exists for an item+work-unit it always catches everything — that only became obvious by actually
running the scenarios, not by reasoning about the algorithm on paper). Verify each scenario
actually produces the outcome its comment claims — a demo seeder is real code and can be wrong in
exactly the way this one initially was.

Every demo record or tightly-related scenario must also make its **business purpose** obvious in
the UI-facing name, description or notes (and in the seeder comment). State what the record is
meant to demonstrate and how it relates to dependent demo records — for example, identify a
semi-finished formulation as the base consumed by a named derived formulation. Codes such as
`demo-...` establish ownership and idempotency; they do not explain the scenario to a person
exploring the application.

**Conventions every demo seeder follows** (the checklist's demo line): it is a module seeder registered in `DemoDatabaseSeeder` in dependency order and refuses to run outside `local`/`testing`; it is **idempotent**
(stable codes or keys; mutable reference data reconciled, **immutable history created once and recognised-and-skipped on rerun — never recomputed, never mutated**); it builds the scenario through the real Actions
(never raw inserts that bypass the rules) with a named actor who holds the needed capabilities; it creates a small number of named, explained scenarios that exercise the feature's decision points, verified by a
test that also proves the rerun adds nothing (`…DemoSeederTest`); and it leaves another developer able to exercise the workflow by hand from the UI. Anything a demo grants (a capability, a persona) is documented
here or in the domain doc; a persona that must sign in gets the demo password, one that only supplies a second actor does not.

**File layout**: default to one flat `Demo/{Module}DemoSeeder.php`, matching
Foundation/Accounting/Parties/Inventory/Purchasing/Stock above. Promote to a `Demo/{Module}/`
folder with one seeder per business capability only once a module's demo data will span more
than one independently-meaningful capability — known in advance, not speculative. Estimating is
`Demo/Estimating/{RateDimensions,ApplicationRates}DemoSeeder.php` because formulations and
assemblies are separate worked scenarios still to come in the same module; Parties stays one file
because suppliers and customers are one coherent "trading partner" concept. Don't fragment a
single scenario into one file per table.

**Idempotency without a natural code**: most tables here have a stable `code` to key
`updateOrCreate` on. A table with no natural business key (e.g. `application_rates`, whose real
identity is item + work unit + a dimension-value *combination* that lives in a pivot table) can
instead delete the narrow slice it owns and recreate it every run — see
`ApplicationRatesDemoSeeder` — rather than force a fragile partial-match key.

The [packaged-purchasing walkthrough](../journeys/packaged-purchasing-demo.md) documents the named/nested packages, price bases, receipts and replacement-order examples. Its immutable definitions and workflow records are created once and skipped on reruns; manual master edits and completed receiver steps are preserved.

#### Immutable transactional demo data: create once, never reconcile

Two different idempotency shapes exist in this codebase, and confusing them produces a real bug.
**Mutable reference/master data** (an item, a rate dimension, an assembly) may be safely
reconciled against current input on every run — `updateOrCreate` overwriting a name or a rate is
fine, since the row carries no separate "as of when" meaning of its own. **Immutable
transactional/workflow data** (a stock move, a negative-stock override request, an approval, a
posted physical count — anything a database trigger or an Action enforces can never be edited or
deleted once created) must instead be **created once and recognised-and-skipped on every later
run**, never recalculated against today's state and compared against what history already says.

`StockDemoSeeder::seedPhysicalCounts`/`seedInventoryCutover`/`seedRecalculationRequest` already
get this right: each checks whether its own record already exists (`PhysicalCount::where(...)`,
`InventoryCutover::query()->exists()`) and returns immediately if so, only computing anything
live when the record doesn't exist yet. `seedNegativeStockOverrides` originally didn't — it
recomputed `requested_qty`/`available_qty` from live stock on *every* run and handed them to
`RequestNegativeStockOverride`, which correctly refused to silently rewrite an existing request
whose immutable historical figures no longer matched current stock. On a long-lived dev database,
where stock naturally drifts between `demo:seed` runs, this meant the method could only ever
succeed once — a real gap in the seeder, not a data-cleanup problem, found and fixed while
building Phase 3's Estimating demo data (2026-09-25). The fix was the same "does this natural key
already exist?" guard the other three methods already use — not weakening
`negative_stock_override_requests`' database-level immutability trigger or the Action's own
mismatch check, both of which stay exactly as strict as before, and are exactly what caught this
in the first place.

Production initialization must never depend on demo data — system/reference data (currencies,
UoMs, permissions) belongs in ordinary migrations or a `permission:register`-style command, never
gated behind `demo:seed`.

`StockDemoSeeder` also creates two throwaway accounts, `demo-ops@erp.x1techs.com` and
`demo-supervisor@erp.x1techs.com` — **not meant to be logged into** (their passwords are random
and never surfaced). They exist only as distinct actors so the seeded physical count, inventory
cutover, negative-stock override and stock recalculation requests satisfy separation-of-duties
rules a single login cannot (a count's poster, a cutover's approver, an override's decider/
reviewer, and a recalculation's approver must differ from the record's own creator/requester) —
they show up as `created_by`/`approved_by`/`requested_by`/`decided_by` on that seeded data, the
same way a second real staff member would in production.

The grouped-package demos add one account that **can** be logged into with the demo password: `demo-count-reviewer@erp.x1techs.com` (created by `PackageCountDemoSeeder`), a second person who
may post counts, recount package compositions and see costs — the browser specs sign it in because nobody may post a count they created. `PackageCountDemoSeeder` also grants the (non-login)
`demo-ops` assistant the grouped-stock handler capabilities. The package demos themselves (`PackageIssueDemoSeeder`, `PackageTransferDemoSeeder`, `PackageActivationDemoSeeder`,
`PackageCountDemoSeeder`, `PackageReceiptReversalDemoSeeder`, `PackageQaDemoSeeder`) each create one create-once scenario through the real Actions; see
[package-issue-operations.md](../domain/package-issue-operations.md).

### Follow the existing deployment pattern

Traefik on `proxy-net` with `Host()` routing rules and an HTTPS-redirect middleware; images in
GHCR tagged by commit SHA; Compose project per app. Consistency with the existing nine projects
is worth more than any improvement we might invent.

## Known local issue: the dev Postgres restarts itself

Observed 2026-09-04 to 2026-09-30 on the WSL2/Docker dev stack (not production). Every so often the Postgres postmaster logs
`server process (PID n) exited with exit code 2` (or `terminated by signal 13`), then `terminating any other active server
processes`, `all server processes terminated; reinitializing` and `automatic recovery in progress`. Every open connection is
dropped, so a running test suite fails in bulk and passes on rerun.

What the evidence says, and does not say:

- **Not memory.** The container's `OOMKilled` is false, its restart count is 0, and the WSL host has free memory.
- **Not a data problem.** Recovery completes in seconds and no committed data was lost; the exit-code-2 lines are preceded by no
  SQL error from our code. One crash (2026-09-30 02:36) happened overnight with no test or job running.
- **The disk is stalling.** Checkpoints normally sync in 0.1–0.5 s. Around the crashes they take 12–66 s, with one at 397 s
  (2026-09-29 12:52), while the checkpoint write phase alone stays around 100 s.
  That points at the WSL2/Docker volume (or the VM being paused and resumed), not at our queries.
- **Not proven.** Postgres logs no cause for exit code 2, so a Postgres-side fault is not excluded; it is the least likely reading.
  Nothing in the ERP code can cause it: production runs on dedicated VMs ([dbvm](#dbvm--data-tier)) and has not shown it.

Consequences: it does not block Phase 3. A red run that shows `SQLSTATE[08006]`/"recovery mode" or a mass of unrelated failures
should simply be rerun. Two concurrent test runs against `erp_testing` (two agents) also deadlock each other on schema drops.

**Done (2026-10-01): a throwaway test database on tmpfs** — see below.

## Test database on tmpfs

A second Postgres service, `postgres-test`, keeps its data directory in RAM (`tmpfs`, 768 MB) with `fsync`, `synchronous_commit`
and `full_page_writes` off. It is opt-in (compose profile `test-db`), so a plain `docker compose up` is unchanged. Nothing it
holds survives a stop, which is fine: the suite builds its own data and the schema is rebuilt on each run.

```
docker/backend/run-tests-tmpfs.sh                          # whole suite
docker/backend/run-tests-tmpfs.sh --filter=Estimating      # arguments go to `php artisan test`
TEST_DB_NAME=erp_testing_b docker/backend/run-tests-tmpfs.sh   # a private database for a second person or agent
```

The script starts the service, creates the named database if needed, migrates it, and runs the tests with `TEST_DB_HOST`,
`TEST_DB_PORT` and `TEST_DB_DATABASE` pointing at it. Those three variables are the only change to `config/database.php`'s
`testing` connection; unset (CI, and the default `docker compose exec app php artisan test`), behaviour is exactly as before.

- **Why:** the disk is out of the test path (the dev server's slow checkpoints and self-restarts no longer touch a run), the dev
  database is never involved, and two runs on two database names cannot deadlock on schema drops.
- **Parity check:** the full suite, 1274 tests, passes against it in about 4 minutes; two runs at once on `erp_testing_a` and
  `erp_testing_b` both passed.
- **Cost:** a few hundred MB of RAM while it runs (`docker compose --profile test-db stop postgres-test` frees it). It cannot
  reveal problems that depend on real disk behaviour; the pipeline still runs on a real Postgres service.
- **Not changed:** CI, the dev entrypoint's migration of `erp_testing`, and the dev `postgres` service.

## Related

- [deployment.md](deployment.md) · [ci-cd.md](ci-cd.md)
- [ADR 0017 — Deployment topology](../decisions/0017-deployment-topology.md)
- [database.md](database.md)
