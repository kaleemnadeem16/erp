[← Architecture index](README.md)

# Multi-agent workspaces: one worktree per agent, one shared stack

**Status: implemented 2026-10-19 (Stage 2).** For about three implementation agents working at once. Backend test mechanics (private databases, locks, the throwaway
PostgreSQL) are in [environments.md](environments.md#test-database-on-tmpfs); this page is about *whose code runs* and how work is integrated.

## The arrangement

```
/home/kaleem/projects/erp            main checkout, branch develop      integration workspace; the dev stack serves THIS checkout
/home/kaleem/projects/erp-<topic>    one worktree per agent and task    branch <agent>/<topic>; its own files, .env, node_modules, caches
        │
        └── all share: Git history (one .git), the Docker stack (app/nginx/node/postgres/redis/worker), the throwaway test PostgreSQL,
            the read-only PHP vendor volume, the image, the network, and the lock directory /tmp/erp-test-locks
```

No per-agent application stack is started. A test, lint or codegen run is a **throwaway container** (`docker compose -p erp run --rm --no-deps`) that mounts
*the calling checkout's* `backend/` and the shared vendor read-only, runs as you, and disappears. Idle cost: none. Start-up: about 0.6 s.

## Set up and tear down

```sh
docker/dev/worktree.sh add sales-fr3 --agent claude     # ../erp-sales-fr3 on branch claude/sales-fr3, from develop (about 8 s)
cd ../erp-sales-fr3
docker/backend/run-tests-tmpfs.sh --filter=Quotation    # tests of THIS checkout, database erp_testing_claude_sales_fr3
docker/backend/run.sh vendor/bin/pint --test app/Modules/Sales/Foo.php
docker/backend/run.sh vendor/bin/phpstan analyse --memory-limit=1G
docker/backend/codegen.sh --check                       # OpenAPI spec + API client for this checkout (private database)
docker/dev/worktree.sh list                             # every worktree: dirty files, ahead/behind develop, its test database
docker/dev/worktree.sh remove sales-fr3                 # only when clean and merged into develop; drops its test database
```

`add` writes the per-worktree pieces: `backend/.env` (copied from main), `frontend/node_modules` (copied, see below), `.erp-agent` (who works here, so the wrappers
need no `ERP_AGENT`), and a root `.env` whose `COMPOSE_PROJECT_NAME` matches no running project. It never touches an existing checkout or branch, and
it does not move an agent that already has uncommitted work: **an agent with work in progress in the main tree stays there until it commits or
chooses to move.** Name the topic after the task (`sales-fr3`), not the agent. Phase 5 planning can use its own worktree (`add phase5-plan --agent claude`)
when its documentation edits would collide with implementation work.

## Why the right code runs (and what stops the wrong code)

| Hazard | What prevents it |
|---|---|
| `docker compose exec app …` always runs in the container that has the **main** checkout mounted — from a worktree it would test or lint main and say nothing | The worktree's root `.env` sets `COMPOSE_PROJECT_NAME=erp_wt_<topic>`, so a bare `docker compose exec app` fails loudly (`service "app" is not running`). The wrappers pass `-p erp` themselves and use the worktree's own compose file, so `./backend` means this checkout |
| Running another checkout's script by path (`/…/erp/docker/backend/run-tests-tmpfs.sh` from inside a worktree) | Every wrapper compares the checkout of your current directory with the checkout the script lives in and refuses (exit 64) if they differ |
| A plain `php artisan test` | Refused by `tests/bootstrap.php` outside CI (exit 78): a run must name a private database |
| Dependencies of another branch | See *Dependencies* below: the shared vendor is checked against *this* checkout's `composer.lock` before each run |
| Another agent's database | Database name = `erp_testing_<agent>_<topic>`; creation, migration and cleanup touch only that name; runs on one name serialise |
| Two whole suites at once | One global `full-suite` lock in `/tmp/erp-test-locks`, shared by all worktrees |

Each run sets `ERP_CHECKOUT=<absolute path>` inside the container; a test can print it to prove which checkout it is running (the Stage 2 verification did).

## Dependencies and caches

| Item | Strategy | Why |
|---|---|---|
| PHP `vendor/` | One named volume (`erp_backend_vendor`, 149 MB) mounted **read-only** into every runner. `vendor-check.php` compares each package reference in this checkout's `composer.lock` with `vendor/composer/installed.php` and refuses (exit 65) on a difference. A branch that changes dependencies runs `docker/dev/worktree.sh vendor <topic>`: it copies the volume to `erp_vendor_<topic>`, runs `composer install` against the branch's lock, and the runners use it automatically | No duplicate 149 MB per agent in the common case, and no way to test the wrong dependencies without being told |
| `frontend/node_modules` | **Copied** into each worktree (327 MB, about 6 s, disk is not a constraint here) | npm workspace links (`@erp/core-ui → ../../packages/core-ui`) are *relative*, so a copy resolves `@erp/*` to the worktree's own packages. A shared or symlinked `node_modules` would resolve them to main's source and typecheck/build the wrong code. Hard links were rejected: a rewrite in place would change two checkouts |
| PHPStan cache, Pint cache, Laravel `bootstrap/cache`, `storage/framework/*` | Per checkout (they live in the checkout, ignored by Git) | Nothing shared, nothing to invalidate |
| OpenAPI spec and `frontend/packages/core-api/src/generated` | Per checkout; `codegen.sh` exports the spec from a container that mounts this checkout and connects to **your private test database** (not the dev database), then runs this checkout's `orval` | No agent can regenerate over another's files |
| File ownership | Runners run as your uid/gid; `add` pre-creates the `vendor` mount point so Docker does not leave a root-owned directory | Verified: no root-owned files appear in a worktree |

## Browser (Playwright) tests: the boundary

The dev stack (Vite `:5176`, nginx `:8090`) serves the **main** checkout and the shared dev database `erp`. Playwright specs run from a worktree would
drive main's code and look green while testing nothing of the branch. So:

- `docker/frontend/run-e2e.sh` **refuses to run browser specs from a worktree** (exit 66) unless `ERP_E2E_ALLOW_SHARED_STACK=1`; `--project=unit` specs need no stack and run anywhere.
- Browser runs stay **one at a time** (the `e2e` lock) and hold the shared stack lock. They write to the dev database (`journey.spec.ts` creates data) — unchanged and documented.
- Each checkout already has its own `frontend/apps/e2e/.auth/user.json`, `test-results/` and `dist/` build output, so those never collide.
- To browser-test a branch: integrate it into `develop` (below), then run from the main checkout. A per-run private stack (Vite + a backend runner on private ports with a private database
  and seeded personas) is feasible with the pieces above but was **not** built: it adds fragile pieces (CORS/Sanctum origins, the PWA service worker, a multi-minute `demo:seed` per run) and
  needs its own verification. It is the next step if branch-level UI testing becomes a bottleneck.

## Integration workflow and ownership

**One designated integrator** merges completed feature branches into `develop`, one at a time. Until the owner names someone else, that is the infrastructure agent working with the owner. Two roles stay separate:

| Role | Does | Does not |
|---|---|---|
| Feature agent | Commits and verifies in its own worktree; reports the commit, the checks it ran and what it depends on; updates its branch with `git merge develop` | Merge into `develop`, push `develop`, or restart the shared stack |
| Integrator | Checks the branch and the repository state; merges **one approved** branch; watches CI; runs the browser specs on the integrated stack; tells the other agents to update | Decide that a business feature is complete or approved — that is the owner's call, given explicitly per branch |

Procedure for each branch:

1. **Feature agent** finishes in its worktree: focused tests, the pre-push checks that apply, one full suite (it waits its turn), `git status` clean of its files.
2. **Feature agent reports**: branch and tip commit, the commands it ran with their results (counts, failures with cause), migrations and API changes, and which other branches or decisions it depends on. Browser results are not claimed for the branch (they cannot be: see the boundary above).
3. **Integrator inspects** with `docker/dev/integrate-check.sh <branch>` (read-only): commits and files, behind-develop, migrations that sort before develop's newest, generated-client changes without an API source change, shared files and `docker/` scripts touched, uncommitted work in the owning worktree, and a conflict dry-run. Any ATTENTION line goes back to the agent first.
4. **Owner approval** of the business feature for that branch is recorded (a message naming the branch and commit). Without it nothing is merged.
5. **Integrator merges** from the main checkout, which must be clean and on `develop`: `git merge --no-ff <branch>` (never a force push, rebase or history rewrite). Then `docker/backend/migrate-cycle.sh` if migrations came in, `docker/backend/codegen.sh --check` if the API moved, the affected suites, and a push of `develop` as a normal fast-forward only when the owner has authorised publication.
6. **CI** must pass on the integrated commit (`gh run list --branch develop`); a failure is classified (regression, stale test, environment, drift, another branch) and reported, not retried blindly.
7. **Browser specs** run from the main checkout against the integrated stack (`docker/frontend/run-e2e.sh`, one at a time) and are reported as *"develop@<sha>"*.
8. **Agents update**: each active worktree runs `git merge develop` before dependent work; `docker/dev/worktree.sh list` shows who is behind. After a merge that changes `composer.json`/`package.json`, run `docker/dev/worktree.sh vendor|deps <topic>`.

Specific conflicts:

- **Migrations.** Names carry a timestamp, so two branches rarely collide on a file, but two migrations can still conflict on the *same table or the same column*. Before handing off, `git diff --name-only develop... -- backend/database/migrations` and read any migration on `develop` that touches your tables. After merging `develop`, re-run the apply → rollback → re-apply recipe on your private database. A migration must sort after the one that adds anything it uses.
- **Generated API client.** Never merge generated files by hand. After a merge that moved the API, regenerate with `codegen.sh` in the integrating checkout and commit the result by explicit path, tracing every change to a source change ([pre-push-checks.md](pre-push-checks.md#generated-files-openapi--client)). Conflicts in `src/generated` are resolved by regenerating, not by choosing a side.
- **Shared documentation** (`docs/STATUS.md`, `docs/decisions/README.md`, `docs/OPEN-QUESTIONS.md`, `docs/STATUS-LOG.md`). Make minimal, surgical edits; an ADR number is taken from the index at merge time and renumbered by the later commit if it collides ([AGENTS.md](../../AGENTS.md)). Append-only files merge cleanly; edit table rows, do not reformat them.
- **Shared scripts and Compose files** (`docker/`, `docker-compose.yml`): change them only from the main checkout, in their own commit, and announce them; every worktree gets them by merging `develop`.

## Resource cost

Measured 2026-10-19 on the 16-core / 15.5 GB host (7.5 GB for WSL/Docker):

| What | Cost |
|---|---|
| Idle runner containers | **0** — nothing stays running per worktree; the stack is the same eight services as before |
| Runner start-up (Docker healthy) | about **0.6 s**; with the dependency check about 1.3 s |
| Runner while running seeder-heavy tests | peak **470 MiB** and 160 % of one core (280 one-second samples); a typical focused test is far smaller |
| A new worktree | **7 s**, **361 MB** on disk (the 327 MB of copied `node_modules` is nearly all of it); the 149 MB PHP vendor is shared |
| Codegen in a worktree (migrate a private database, export the spec, run orval) | **16.5 s**, and it reproduced the committed client exactly |
| `tsc` core-api / core-ui / main, `vite build` in a worktree | 3 s / 12 s / 13 s, 6 s (all passed; output stayed in the worktree) |
| Test Postgres (shared) | RAM disk 19–37 % used with up to 8 private databases; one full suite peaked at 37 % |

**The dominant risk is the Windows host, not the setup (cause not proven).** While verifying this, `docker run --rm alpine true` went from 0.7 s to 7–13 s and the runner from 0.6 s to
30–40 s with the WSL side idle. At that time the host showed 1.8 GB free of 15.5 GB, 22.8 GB committed, a combined Pages/sec of about 4,700, and `vmmemWSL` resident 5.3 GB against 7.8 GB private
(Chrome, VS Code, ChatGPT and XAMPP were among the largest processes). **What this does and does not show:** low free memory and the slowdowns coincided, but Pages/sec also counts ordinary
file reads, and a resident-versus-private gap can be memory the VM simply is not using; page *writes* (the pagefile signal) were not captured then. On 2026-10-20, re-measured with the discriminating counters, Page Writes/sec and Pages Output/sec were **0** both idle and while two agents ran seeder tests, so no paging out was happening in
those samples. Everything that shares the VM slows together when the host is starved: Docker, PostgreSQL and every agent's runs. Free host memory (close Windows applications) before heavy runs; the `.wslconfig`
change below remains an option to test, not a conclusion.


## Agent cheat sheet (inside your worktree)

Run everything from the root of **your** worktree (`cd ../erp-<topic>`); your agent name comes from `.erp-agent`, your test database is `erp_testing_<agent>_<topic>`.

| To do this | Run | Notes |
|---|---|---|
| Focused Laravel tests | `docker/backend/run-tests-tmpfs.sh --filter=Quotation` (or a file: `… tests/Feature/Modules/Sales/QuotationFoundationTest.php`) | Never blocked by another agent's full suite |
| Ratchet / architecture tests | `docker/backend/run-tests-tmpfs.sh --filter='WriteRouteGuardTest|RecordScopeCoverageTest|StockWriterMatrixTest|PermissionCheckRegistrationTest|ControllerActionBoundaryTest'` | Before committing a route, write, permission or stock writer |
| Full backend suite | `docker/backend/run-tests-tmpfs.sh` | Takes the one global lock and **waits its turn** (says who holds it). About 20 minutes when the machine is healthy. Before handing off, not while iterating |
| Pint | `docker/backend/run.sh vendor/bin/pint --test <your paths>` (without `--test` to fix) | Only the paths you changed; never reformat others' files |
| Larastan | `docker/backend/run.sh vendor/bin/phpstan analyse --memory-limit=1G` | Whole project (it reads types across files) |
| Migrations: fresh → rollback → re-apply | `docker/backend/migrate-cycle.sh` | Own database `…_mig`. Not the `docker compose exec app` recipe, which acts on the main checkout |
| Frontend type checks | `cd frontend/packages/core-api && npx tsc --noEmit`, the same in `packages/core-ui` and `apps/main`, then `cd frontend/apps/main && npx vite build` | Uses your worktree's own `node_modules`; output stays in your worktree |
| Frontend pure-function specs | `docker/frontend/run-e2e.sh --project=unit` | No stack needed; allowed anywhere |
| API client | `docker/backend/codegen.sh` (`--check` to compare with the commit) | Private database; stage generated files by explicit path, only what traces to your API change |
| Browser specs | **Not from a worktree** (refused, exit 66). After your branch is merged into `develop`, from the main checkout: `ERP_AGENT=<you> docker/frontend/run-e2e.sh <spec> --project=chromium` | One browser run at a time, against the shared dev stack and dev database. Report it as *"develop@<sha> after integration"*, never as verification of your branch |
| Commit | `git add <explicit paths> && git commit` | Your branch only; no stash, reset or rebase of shared history |
| Update from the accepted state | `git merge develop` | A merge, not a rebase |
| Status of all workspaces | `docker/dev/worktree.sh list` | Dirty files and ahead/behind develop |

Do not use bare `docker compose exec app …` for any of the above in a worktree: it fails with `service "app" is not running` on purpose.

## Onboarding the first two Phase 4 agents

State checked 2026-10-20: the main checkout is on `develop`, clean, nothing staged or uncommitted; all agent sessions were working in it; the other two worktrees (`erp-auth-retrofit`, `erp-release-sync`) have no unstaged work and were not touched. The two workspaces below were created **empty and clean** from `develop` at `afe70e1` and nobody has been moved into them:
an agent switches only when it says so. **Before switching, the agent confirms** `git status --short` in the main checkout shows nothing of its own uncommitted; an agent with uncommitted work stays in the main checkout until it commits that work by explicit path (never stashed, never copied). Open the new folder as the agent's workspace so its session starts inside it. Both worktrees record the agent as `phase4` in `.erp-agent`; an agent may replace that with its own short name (it renames its test database; the old one can be dropped with `docker/backend/test-db.sh drop`).

| | Path | Branch | Test database |
|---|---|---|---|
| **Agent A — Sales quotations** (QL1 and later authorised slices; see [phase-4-ql0-delivery-and-decisions.md](../phases/phase-4-ql0-delivery-and-decisions.md), [phase-4-sales-quotations-and-handover.md](../phases/phase-4-sales-quotations-and-handover.md)) | `/home/kaleem/projects/erp-sales-quotes` | `phase4/sales-quotes` | `erp_testing_phase4_sales_quotes` |
| **Agent B — Documents** (DF0–DF5; DF0 needs separate authorisation: [handoff](../phases/phase-4-documents-df0-handoff.md)) | `/home/kaleem/projects/erp-documents` | `phase4/documents` | `erp_testing_phase4_documents` |

Exact onboarding command for each agent (paste in a terminal; nothing is created, it only enters and checks the workspace):

```sh
# Agent A
cd /home/kaleem/projects/erp-sales-quotes && git status --short && git log -3 --oneline && docker/backend/run-tests-tmpfs.sh --filter=Quotation
# Agent B
cd /home/kaleem/projects/erp-documents && git status --short && git log -3 --oneline && docker/backend/run-tests-tmpfs.sh --filter=Numbering
```

Then tell the integrator *"agent A/B is working in <path> on <branch>"*, and re-read `docs/STATUS.md`.

Where the two meet: Documents' Sales adapter needs real quotation issue contracts (QL3), so A's slices land first; B's DF0/DF1 foundation is independent and delivered disabled. Both touch hotspots, so
merge `develop` often and expect to resolve: new migrations (check each other's tables), the generated API client (regenerate, never choose a side), and `docs/STATUS.md`/`STATUS-LOG.md`.
Integration is one branch at a time through the main checkout (steps above); neither agent merges into `develop` itself.

## Host memory on a 16 GB machine

**Measured** (2026-10-19/20, this machine):

| Measurement | Value |
|---|---|
| Host RAM / free at idle | 15.5 GB / 2.3 GB free; 20.7 GB committed of a 32.5 GB limit |
| Largest Windows consumers (working set) | Chrome 2.6 GB (20 processes), ChatGPT 1.1 GB (15), VS Code 1.0 GB (12), Windows services 1.1 GB, Edge WebView 0.25 GB, Docker Desktop 0.35 GB |
| WSL VM | allowed 7.5 GB (the default, half of host RAM); `vmmemWSL` 3.0 GB resident of 5.1 GB private at idle, 5.3 of 7.8 GB under load |
| Pages/sec (combined reads and writes, includes file reads) | 2026-10-19 during the slowdown: about 4,700 with 1.8 GB free; 2026-10-20: 4–4,300 depending on the moment. **Page Writes/Pages Output (pagefile writes): 0 in every 2026-10-20 sample**; pagefile 12 % used (about 2 GB of 17 GB) |
| WSL memory during a full backend suite | available never below 3.8 GB of 7.5 GB; swap about 2 MB |
| Cost of one runner / test Postgres / dev stack | 470 MiB peak / about 480 MiB (its RAM disk counts) / about 0.7 GB for the eight services |
| In WSL, editors and agents | VS Code server about 0.6 GB, Claude 0.35 GB, Antigravity 0.18 GB, Codex 0.15 GB |
| Slowdown observed while the host had 1.8 GB free | Docker start-up 0.7 s → 7–40 s; a trivial test 5 s → 170 s with the WSL side idle |

**Assumptions** (not measured here): that capping WSL lower than 7.5 GB would make the slowdowns stop (the cause of the 2026-10-19 slowdown is not proven to be paging) — the measured peak need is about 3.7 GB plus page cache, so **6 GB** should cover the stack, two
concurrent focused runs and one full suite, but nobody has run that configuration; that adding RAM would remove the slowdowns (plausible, since they coincided with 1.8 GB free, but neither the cause nor any speed gain was measured).

**Recommendation (nothing was changed):**

1. *WSL limit:* try `memory=6GB` in `C:\Users\<you>\.wslconfig` (`[wsl2]`), plus `[experimental] autoMemoryReclaim=gradual`; leave `processors` alone (CPU was never the limit: the test Postgres peaked at 3.6 cores, load stayed under 12 on 16). Re-measure with `Get-Counter "\Memory\Pages/sec"` during a full suite; revert if the suite slows.
2. *Close during heavy tests (full suite, browser run, codegen, a rebuild):* extra Chrome windows and tabs (largest single item), the ChatGPT desktop app, Edge WebView processes, the Docker Desktop dashboard window, XAMPP control panel and its services, and any second editor window on the same repository. Keep one VS Code window per active worktree; each adds a WSL server of about 0.6 GB.
3. *Two agents running focused tests at once:* **yes**, measured: pairs and triples of focused runs, and nine concurrent fork tests, ran correctly. Condition (assumption): host free memory stays above about 3 GB; below it, one run alone was already slow.
4. *Keep serial:* one full backend suite, one browser run, one stack restart or rebuild at a time (enforced by the locks).
5. *More RAM:* worth it. 32 GB would take the host out of the low-free-memory state it was in during the 2026-10-19 slowdown (10–100× slower container start-up and tests); the link is a coincidence in time, not a demonstrated cause. It would not speed up CPU-bound work, and it is not a substitute for the locks.

**Baseline during normal work** (`docker/dev/host-baseline.sh [label]` appends one line to `/tmp/erp-test-locks/baseline.csv`; run it a few times a day, especially during heavy runs). First samples, 2026-10-20, current configuration:

| Condition | WSL available | Windows free | Pages/sec (not pagefile writes) | Runner start | Focused test (3 tests) |
|---|---|---|---|---|---|
| Idle, after the rebuild | 4.1 GB | 1.8 GB | 18 pages/s | 0.6 s | 4.4 s |
| Two agents running focused seeder tests (24 s and 46 s) | 4.0 GB | 1.8 GB | 4,308 pages/s, **0 page writes** | 1.2 s | 8.2 s |
| *For comparison, 2026-10-19 during the slowdown* | 3.9 GB | 1.8 GB | 4,734 pages/s, page writes not captured | 30–40 s | up to 170 s |

Two concurrent agents are fine on today's numbers; Windows free memory is the same low 1.8 GB in all three rows, so the difference is what else the host is doing (Chrome, ChatGPT, builds), not these tests; and Pages/sec swings from single digits to thousands with no pagefile writes, so it is not a pressure gauge by itself. If runs become slow again, capture **Page Writes/sec, Pages Output/sec and pagefile % usage** at that moment (the helper now records them) and take the evidence to a separate approval for a `.wslconfig` change: **try** `memory=6GB` and `autoMemoryReclaim=gradual` in `C:\Users\<you>\.wslconfig`, run `wsl --shutdown`, re-run the baseline; **roll back** by deleting those lines (or the file) and running `wsl --shutdown` again. Nothing has been changed.

## Tracked infrastructure issues (open)

| # | Issue | State |
|---|---|---|
| 1 | PostgreSQL backend exit code 2 (103 on the test server, 33 on the dev server) — cause unknown | A SIGQUIT to one backend reproduces the signature; the fork tests do not; none since the diagnostics were added except a deliberate probe. `pg-crash-report.sh` collects evidence on the next event. See [environments.md](environments.md#unexplained-backend-exits-updated-2026-10-19) |
| 2 | Browser tests against a feature branch are not isolated | Browser specs run only from the main checkout, after integration; a private per-run stack needs its own design review |
| 3 | Restarting the shared stack | Use `docker/dev/stack-exclusive.sh <command>`; it refuses while anyone is testing |
| 4 | Worker restart fix | Config deployed 2026-10-19; the container then restarted itself 17 times in 17 hours (the hourly `--max-time` exit), which is the intended behaviour |
| 5 | Dev images carried the Stage 1 entrypoint | **Done 2026-10-20**: `app`, `worker`, `scheduler` rebuilt and recreated through `stack-exclusive.sh` (119 s); their start-up no longer touches the shared `erp_testing`; PostgreSQL, Redis, nginx and Node were not restarted. nginx needed a reload afterwards (below) |
| 6 | Windows host memory pressure | Recommendations above; no setting changed |

**Rebuilding the dev images (item 5)** — `app`, `worker` and `scheduler` bake `docker/backend/entrypoint.dev.sh` into their images (checked: all three still contain the old text). When nobody is testing, and ideally off-peak:

```sh
docker/dev/stack-exclusive.sh docker compose up -d --build --no-deps app worker scheduler
docker exec erp-nginx-1 nginx -s reload      # nginx resolves `app` once at start; reload so it follows the recreated container
```

`--no-deps` is deliberate: without it Compose re-evaluates the Postgres and Redis services too. It refuses while any test or browser run holds the stack. It builds three images from one Dockerfile (cached; the first build under host memory pressure can take minutes), recreates only those three containers (nginx, node, both Postgres servers and Redis keep running; the API returns 502 for a few seconds while `app` restarts), and keeps all volumes. Afterwards verify:
`docker exec erp-app-1 grep -c erp_testing /usr/local/bin/entrypoint.dev.sh` prints `1` (a comment), `docker logs erp-worker-1` shows no "Running migrations on test database", and `curl http://127.0.0.1:8090/up` returns 200. The leftover `erp_testing` database on the dev server is harmless and can stay.

## Limits and what to check before adding agents

- Three implementation agents plus one planning worktree is the tested size. Memory is the tight resource (7.5 GB shared with editors and the stack); the throwaway test PostgreSQL holds up to about 8 private databases.
- Still serial on purpose: one full backend suite at a time, one browser run at a time. Parallel Pest and per-agent browser stacks are not enabled.
- The shared dev stack's code is the main checkout's: after merging into `develop`, `app`/`nginx` pick the change up through the bind mount; a `composer.json` change still needs a container restart (use `docker/dev/stack-exclusive.sh`).
- The unexplained PostgreSQL backend exits are not resolved ([environments.md](environments.md#unexplained-backend-exits-updated-2026-10-19)); `docker/backend/pg-crash-report.sh` names the runs that were live.

## Related

[environments.md](environments.md) · [pre-push-checks.md](pre-push-checks.md) · [testing-standards.md](testing-standards.md) · [AGENTS.md](../../AGENTS.md)
