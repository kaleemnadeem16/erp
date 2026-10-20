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

## Integration workflow

1. **Develop and test on your branch** in your worktree: focused tests while working; the checks in [pre-push-checks.md](pre-push-checks.md) for what you changed; one full suite before handing off (it waits its turn).
2. **Commit only your own work, explicit paths** ([AGENTS.md](../../AGENTS.md#working-alongside-other-agents)). No stash, reset, or rebase of a branch someone else has seen.
3. **Report** the branch, the commit(s), and the verification you ran (commands, counts, failures with their cause).
4. **Integrate through the main checkout** (a person or the designated integrator, one branch at a time): `git merge --no-ff <agent>/<topic>` into `develop`, never a force push or history rewrite; then run `docker/backend/codegen.sh --check` and the affected suites there, and push for CI. A merge that conflicts is resolved by the integrator together with the author, not by whoever merges.
5. **Update your worktree from the accepted state** before starting dependent work: `git merge develop` in your worktree (a merge, not a rebase, so no history is rewritten). Run `docker/dev/worktree.sh list` to see how far behind you are.
6. **Afterwards** `docker/dev/worktree.sh remove <topic>` (clean and merged only).

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

**The dominant risk is the Windows host, not the setup.** While verifying this, `docker run --rm alpine true` went from 0.7 s to 7–13 s and the runner from 0.6 s to
30–40 s with the WSL side idle. The host showed 1.8 GB free of 15.5 GB, **4,734 pages/s** of paging, 22.8 GB committed, and `vmmemWSL` holding 5.3 GB resident of
7.8 GB private, that is about 2.5 GB of the WSL VM paged out to the Windows pagefile (Chrome, VS Code, ChatGPT and XAMPP were among the largest processes). Everything that
shares the VM slows together: Docker, PostgreSQL and every agent's runs. Before adding agents, free host memory (close Windows applications) or lower the WSL allocation in
`C:\Users\<you>\.wslconfig` (`[wsl2] memory=6GB`) so the VM is not paged; this was not changed here.


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

State checked 2026-10-20: the main checkout is on `develop`, clean, nothing staged or uncommitted; the other two worktrees (`erp-auth-retrofit`, `erp-release-sync`) have no unstaged work. Before moving anyone,
**the agent itself confirms** `git status --short` shows nothing of theirs uncommitted. An agent with uncommitted work stays in the main checkout until it commits that work by explicit path; it is never moved or stashed for it.
Substitute your real agent names for `<agentA>` and `<agentB>` (lowercase letters, digits, dashes). Open the new folder as the agent's workspace so its session starts inside it.

**Agent A — Sales quotation workflows** (QL0 is on `develop`; next slices per [phase-4-ql0-delivery-and-decisions.md](../phases/phase-4-ql0-delivery-and-decisions.md) and [phase-4-sales-quotations-and-handover.md](../phases/phase-4-sales-quotations-and-handover.md))

```sh
cd /home/kaleem/projects/erp && git status --short && git log -3 --oneline        # re-read STATUS.md; nothing of yours uncommitted
docker/dev/worktree.sh add sales-quotes --agent <agentA>                            # ../erp-sales-quotes, branch <agentA>/sales-quotes, database erp_testing_<agentA>_sales_quotes
cd ../erp-sales-quotes
docker/backend/run-tests-tmpfs.sh --filter=Quotation                                # sanity check: QL0 tests pass here
```

**Agent B — Documents (shared Document & Print Framework)** ([document-print-framework-slices.md](../phases/document-print-framework-slices.md), [documents.md](../domain/documents.md))
*Application code for DF0 needs the owner's separate authorisation; until then the worktree is for reading, design and documentation only.*

```sh
cd /home/kaleem/projects/erp && git status --short && git log -3 --oneline
docker/dev/worktree.sh add documents --agent <agentB>                              # ../erp-documents, branch <agentB>/documents, database erp_testing_<agentB>_documents
cd ../erp-documents
docker/backend/run-tests-tmpfs.sh --filter=Numbering                               # sanity check on an unrelated, stable area
```

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
| Paging under load (2026-10-19) | 4,734 pages/s average with 1.8 GB free; at idle 22 pages/s |
| WSL memory during a full backend suite | available never below 3.8 GB of 7.5 GB; swap about 2 MB |
| Cost of one runner / test Postgres / dev stack | 470 MiB peak / about 480 MiB (its RAM disk counts) / about 0.7 GB for the eight services |
| In WSL, editors and agents | VS Code server about 0.6 GB, Claude 0.35 GB, Antigravity 0.18 GB, Codex 0.15 GB |
| Effect observed while paging | Docker start-up 0.7 s → 7–40 s; a trivial test 5 s → 170 s with the WSL side idle |

**Assumptions** (not measured here): that capping WSL lower than 7.5 GB will stop the VM being paged — the measured peak need is about 3.7 GB plus page cache, so **6 GB** should cover the stack, two
concurrent focused runs and one full suite, but nobody has run that configuration; that adding RAM removes the paging (very likely, since the measured stall coincides with 1.8 GB free, but the speed gain was not measured).

**Recommendation (nothing was changed):**

1. *WSL limit:* try `memory=6GB` in `C:\Users\<you>\.wslconfig` (`[wsl2]`), plus `[experimental] autoMemoryReclaim=gradual`; leave `processors` alone (CPU was never the limit: the test Postgres peaked at 3.6 cores, load stayed under 12 on 16). Re-measure with `Get-Counter "\Memory\Pages/sec"` during a full suite; revert if the suite slows.
2. *Close during heavy tests (full suite, browser run, codegen, a rebuild):* extra Chrome windows and tabs (largest single item), the ChatGPT desktop app, Edge WebView processes, the Docker Desktop dashboard window, XAMPP control panel and its services, and any second editor window on the same repository. Keep one VS Code window per active worktree; each adds a WSL server of about 0.6 GB.
3. *Two agents running focused tests at once:* **yes**, measured: pairs and triples of focused runs, and nine concurrent fork tests, ran correctly. Condition (assumption): host free memory stays above about 3 GB; below it, one run alone was already slow.
4. *Keep serial:* one full backend suite, one browser run, one stack restart or rebuild at a time (enforced by the locks).
5. *More RAM:* worth it. 32 GB would take the host out of the paging regime this machine was in during verification, which is the only thing measured to cause 10–100× slowdowns of container start-up and tests. It would not speed up CPU-bound work, and it is not a substitute for the locks.

## Tracked infrastructure issues (open)

| # | Issue | State |
|---|---|---|
| 1 | PostgreSQL backend exit code 2 (103 on the test server, 33 on the dev server) — cause unknown | A SIGQUIT to one backend reproduces the signature; the fork tests do not; none since the diagnostics were added except a deliberate probe. `pg-crash-report.sh` collects evidence on the next event. See [environments.md](environments.md#unexplained-backend-exits-updated-2026-10-19) |
| 2 | Browser tests against a feature branch are not isolated | Browser specs run only from the main checkout, after integration; a private per-run stack needs its own design review |
| 3 | Restarting the shared stack | Use `docker/dev/stack-exclusive.sh <command>`; it refuses while anyone is testing |
| 4 | Worker restart fix | Config deployed 2026-10-19; the container then restarted itself 17 times in 17 hours (the hourly `--max-time` exit), which is the intended behaviour |
| 5 | Dev images still carry the Stage 1 entrypoint (it migrates the shared `erp_testing` on every start) | Needs one rebuild; procedure below |
| 6 | Windows host memory pressure | Recommendations above; no setting changed |

**Rebuilding the dev images (item 5)** — `app`, `worker` and `scheduler` bake `docker/backend/entrypoint.dev.sh` into their images (checked: all three still contain the old text). When nobody is testing, and ideally off-peak:

```sh
docker/dev/stack-exclusive.sh docker compose up -d --build app worker scheduler
```

It refuses while any test or browser run holds the stack. It builds three images from one Dockerfile (cached; the first build under host memory pressure can take minutes), recreates only those three containers (nginx, node, both Postgres servers and Redis keep running; the API returns 502 for a few seconds while `app` restarts), and keeps all volumes. Afterwards verify:
`docker exec erp-app-1 grep -c erp_testing /usr/local/bin/entrypoint.dev.sh` prints `1` (a comment), `docker logs erp-worker-1` shows no "Running migrations on test database", and `curl http://127.0.0.1:8090/up` returns 200. The leftover `erp_testing` database on the dev server is harmless and can stay.

## Limits and what to check before adding agents

- Three implementation agents plus one planning worktree is the tested size. Memory is the tight resource (7.5 GB shared with editors and the stack); the throwaway test PostgreSQL holds up to about 8 private databases.
- Still serial on purpose: one full backend suite at a time, one browser run at a time. Parallel Pest and per-agent browser stacks are not enabled.
- The shared dev stack's code is the main checkout's: after merging into `develop`, `app`/`nginx` pick the change up through the bind mount; a `composer.json` change still needs a container restart (use `docker/dev/stack-exclusive.sh`).
- The unexplained PostgreSQL backend exits are not resolved ([environments.md](environments.md#unexplained-backend-exits-updated-2026-10-19)); `docker/backend/pg-crash-report.sh` names the runs that were live.

## Related

[environments.md](environments.md) · [pre-push-checks.md](pre-push-checks.md) · [testing-standards.md](testing-standards.md) · [AGENTS.md](../../AGENTS.md)
