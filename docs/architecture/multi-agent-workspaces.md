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


## Limits and what to check before adding agents

- Three implementation agents plus one planning worktree is the tested size. Memory is the tight resource (7.5 GB shared with editors and the stack); the throwaway test PostgreSQL holds up to about 8 private databases.
- Still serial on purpose: one full backend suite at a time, one browser run at a time. Parallel Pest and per-agent browser stacks are not enabled.
- The shared dev stack's code is the main checkout's: after merging into `develop`, `app`/`nginx` pick the change up through the bind mount; a `composer.json` change still needs a container restart (use `docker/dev/stack-exclusive.sh`).
- The unexplained PostgreSQL backend exits are not resolved ([environments.md](environments.md#unexplained-backend-exits-updated-2026-10-19)); `docker/backend/pg-crash-report.sh` names the runs that were live.

## Related

[environments.md](environments.md) · [pre-push-checks.md](pre-push-checks.md) · [testing-standards.md](testing-standards.md) · [AGENTS.md](../../AGENTS.md)
