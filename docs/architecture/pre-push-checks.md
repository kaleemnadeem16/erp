# Before you push: the checks CI will run

CI ([pipeline.yml](../../.github/workflows/pipeline.yml), [ci-cd.md](ci-cd.md)) runs six jobs on every push: **lint-backend** (Pint, Larastan), **lint-frontend** (three `tsc` runs and `vite build`), **test** (Pest), **codegen-check** (migrate, rollback and
re-apply, OpenAPI export, client generation, `git diff --exit-code`), **e2e** (fresh migrate and demo seed, production build, the whole Playwright suite) and, on `main`, the image build. A feature that "works" but breaks one of these has not been
handed off. This page is the short list of what to run for what you changed; the reasons live in the linked canonical docs.

## The rule

Before you say work is complete, run every check below that **applies to what you changed** (matrix), and for each one either report it **passed**, or report the **verified** cause and owner of a failure that is not yours. Never write "CI failed
elsewhere" without having identified the failing job, test and cause.

## What to run for what you changed

| You changed… | Run (from the repo root of YOUR checkout; backend commands via `docker/backend/run.sh <command>` — in a worktree `docker compose exec app` would act on the main checkout, see [multi-agent-workspaces.md](multi-agent-workspaces.md)) |
|---|---|
| Any PHP | `docker/backend/run.sh vendor/bin/pint --test <your paths>` · `docker/backend/run.sh vendor/bin/phpstan analyse --memory-limit=1G` (whole project: it reads types across files) · the suites for the code you touched and your module's regression (feature agents do **not** run the whole suite for each change: CI runs it on every pushed branch and the coordinator runs it at checkpoints, [agent-coordination.md](agent-coordination.md#test-responsibilities)) — always through the wrapper, `ERP_AGENT=<you> docker/backend/run-tests-tmpfs.sh [--filter=…]` (a private database per agent; a whole-suite run waits for the one full-suite lock; a plain `php artisan test` is refused — [environments.md](environments.md#test-database-on-tmpfs)) |
| A migration | All of the above, plus **fresh → rollback → re-apply on an isolated database** (below), Pint over the migration, and a look at what a *fresh* database does with the demo seed (the e2e job's setup) |
| A route, write, read, permission, stock writer or scope behaviour | The **ratchets** (below) — run `WriteRouteGuardTest`, `OpenReadRoutesTest`, `RecordScopeCoverageTest`, `StockWriterMatrixTest`, `PermissionCheckRegistrationTest`, `ControllerActionBoundaryTest` *before* committing; register the new thing with a reason |
| A Resource, Form Request, route or anything the OpenAPI document describes | **Regenerate** the spec and client and commit the result (below); then re-run the frontend typechecks |
| A demo seeder, or a table a seeder writes | The seeder's own test **and** a fresh-database `demo:seed` (the `*DemoSeederTest` tests run it on a freshly migrated schema); rerun must add nothing |
| Any frontend file | `npx tsc --noEmit` in **each** of `packages/core-api`, `packages/core-ui`, `apps/main`, and `npx vite build` in `apps/main` (CI does all four; a type error or an unresolved import in a file you did not stage shows up only here) |
| A screen, label, button text, picker or route | The browser specs for that screen **and** for every screen that shares the component you changed (`grep` the spec folder for the label/test id). Copy changes break specs: search the specs for the old words before you commit |
| Test fixtures or e2e support | The specs that use them, run twice (once with a fresh login after `php artisan cache:clear`) |
| Docs only | Links and numbering (`docs/decisions/README.md`); no code checks |

Not blindly: a docs-only or a purely backend change needs no browser run; a UI change needs no Larastan. When unsure what shares your change, search for it.

### Migrations: apply → rollback → re-apply, on an isolated database

**From a worktree (or anywhere): `docker/backend/migrate-cycle.sh`** runs the whole check below on your own `…_mig` database against your checkout's migrations. The manual recipe below uses `docker compose exec app`, which acts on the main checkout, so use it only there.

CI migrates a fresh database, rolls it back and migrates again — the only place any `down()` runs. Locally, never on the development database:

```
docker compose --profile test-db up -d --wait postgres-test
docker compose exec -T postgres-test psql -U erp -d postgres -c 'CREATE DATABASE erp_mig_<you> OWNER erp'
R="docker compose exec -T -e TEST_DB_HOST=postgres-test -e TEST_DB_PORT=5432 -e TEST_DB_DATABASE=erp_mig_<you> app"
$R php artisan migrate:fresh --database=testing --force && $R php artisan migrate:rollback --database=testing --force && $R php artisan migrate --database=testing --force
```

Rules that keep breaking CI otherwise ([database.md](database.md#migration-standards) has the full list):

- **Order is the timestamp.** A migration, seeder or test that uses a column must come *after* the migration that adds it; check the filename order, not the order you wrote them. A seeder that works on a long-lived dev database can fail on a fresh one (an unsupported `notes` column did exactly this).
- **A real `down()`**, or it refuses loudly when it would lose data; never an empty one.
- **Do not edit an old migration** unless the task requires it; add a new one. Production has already run the old one.
- **Format migrations.** Pint covers `database/`, and unformatted migrations failed the pipeline repeatedly. Run Pint on *your* migration only — never reformat another agent's file as a side effect.
- Tests that rely on migration state use the shared test schema; do not assume rows survive between tests.

### Ratchets (architecture tests)

Adding a **route, a write, a read, a permission, a stock writer, or scope behaviour** can make an existing registry fail the day CI runs. The list and what each keeps true is in [testing-standards.md](testing-standards.md#architecture-and-ratchet-tests). Run them
**before** the commit and register your addition (with a reason) in the same change.

### Generated files (OpenAPI → client)

- **When:** any change to a Resource, Form Request, route, enum or validation rule that Scramble reads.
- **How:** `docker/backend/codegen.sh` (exports the spec from a container that mounts your checkout and uses your private test database, then runs `npm run codegen` with your checkout's `node_modules`; `--check` fails if the result differs from what is committed). The two manual steps it replaces were `php artisan scramble:export --path=storage/api-docs/openapi.json` in the `app` container and `npm run codegen` in `frontend/`; do not use them in a worktree. The spec itself is gitignored; the client in `frontend/packages/core-api/src/generated/` **is committed**, including the
  `models/index.ts` barrel and `src/index.ts` exports for any new module.
- **Detect drift:** after regenerating, `git status -- frontend/packages/core-api` and `git diff` — every changed generated file must be explained by your change.
- **Shared checkout:** codegen cleans the whole generated folder. Before running it, `git status`: if another agent has uncommitted generated files, **do not** regenerate over them and do not commit theirs. Either wait for their commit, or regenerate and then
  restore only what is theirs by re-adding their files from their working copy — never `git checkout`/`stash` their work. Commit only the generated files your change moved. If CI later shows drift that is not yours, say whose change it is.
- Never hand-edit a generated file.
- **A generated change must belong to an API change that is in the commits you deliver.** The client is a function of the backend *at the commit being
  pushed*, not of the working tree you generated it in. If another agent has uncommitted Resource/Request/enum/route changes, `codegen` in the shared tree
  emits their symbols too, and a directory-wide `git add` stages them: `codegen-check` then fails on the pushed head because the backend that produces
  those files was never published (found 2026-10-12: a `packageTrackingMode` type and its barrel export were committed ahead of the Items filter change that
  produces them). A green working tree or a successful local build proves nothing here — both include the other agent's unpublished code.
  - **Stage generated files by explicit path, never `git add -A -- …/generated`.** Every new/removed generated file and every `models/index.ts` barrel line
    you stage must trace to a Resource, Form Request, enum, route or validation change in *your* commit. A new untracked generated file you did not cause is
    someone else's uncommitted work: leave it out (and note it).
  - **Before you commit:** `git diff --cached --stat -- frontend/packages/core-api` against `git diff --cached --name-only | grep -E 'Http/(Requests|Resources|Controllers)|routes|Support/'` —
    each generated change needs a source change in the same list. (The OpenAPI document itself is gitignored, so it is not a committed reference: the committed
    reference is the backend source in the commits, and the authority is the CI `codegen-check`.)
  - **Before you push:** the generator must run against the tree you are delivering, not your working tree. Either run `export` + `codegen` when `git status`
    shows no uncommitted backend/API change of anyone else (and nothing of yours left out), or generate in a clean checkout of exactly the commits you push, then
    `git diff --exit-code -- frontend/packages/core-api`. Push only after that is empty, and watch `codegen-check` on the hosted run.
  - **If it still mismatches, do not "fix" it by publishing another agent's commits.** Remove the stray generated file and its barrel line in a corrective commit
    (or coordinate with its owner to deliver their backend change first, with their authorisation). Their next regeneration restores it with their change.

## Browser (Playwright) tests

**Run them through `ERP_AGENT=<you> docker/frontend/run-e2e.sh <spec> --project=chromium`**: one browser run at a time against the shared dev stack (they share its database, the sign-in throttle, `.auth/` and the ports), and it holds the stack lock so nobody restarts it underneath you. Pure-function specs (`--project=unit`) are exempt. Never `docker compose restart/up --force-recreate` while others test — use `docker/dev/stack-exclusive.sh <command>`.

What keeps breaking, and the rule:

- **Do not assume a demo record is on page one or is "the first row".** Datasets grow and other runs leave records behind. Find by code through the server (`findByCode` in `package-stock-support.ts`) or by id; use deterministic filters.
- **Product code has the same rule.** A known record is resolved by id; a large searchable set is a server-driven picker; only a genuinely small bounded reference list may be listed whole ([ui-building-blocks.md](ui-building-blocks.md#lookups-that-survive-large-datasets)).
- **Clean up what you create** where it matters: a test that makes bulk data removes it in a `finally`; the suite's teardown archives only the items the run created. Do not "fix" crowding by wiping shared data.
- **Server-driven pickers:** wait for the current result (the picker says "Searching…" until it has it); do not act on a list that is still answering the previous text.
- **Choose Radix options by keyboard, not pointer, at narrow widths.** An option can sit outside the viewport of its scrollable portal (seen at 320 px, in the production build CI uses and not in the dev server); a pointer click then times out with "`<html>` intercepts pointer events". Use `option.press('Enter')` (as `pickSelect` does).
- **Copy and layout changes make specs stale.** If you rename a label, a button or an option, update the specs in the same commit; if a spec fails on wording you did not touch, it is stale — classify it as such and fix it, do not retry it.
- **Personas come from the normal demo seeders** (`demo:seed`), not from Docker-only setup, so CI and another developer get the same people.
- **Environment is not product.** Postgres "recovery mode", Docker down, a login throttle (`php artisan cache:clear` clears it) or a browser that will not launch are environment failures: confirm in the logs, fix the environment, re-run the *same* specs, and say so.
- **CI differs from your machine** in one important way: it runs the **production build** (`vite preview`), a freshly seeded database and no leftovers. A spec that passes against a crowded dev stack can still fail there, and the reverse.

## Shared checkout: several agents, one tree

**Preferred since 2026-10-19: one worktree per agent** ([multi-agent-workspaces.md](multi-agent-workspaces.md)). The rules below remain for anyone still sharing the main tree, and for the main tree itself, which is the integration workspace.

- **Before editing or generating anything shared:** `git status` and `git log -5`. Another agent's uncommitted files are theirs.
- **Never** `stash`, `reset`, `checkout --`, restore or format a file you did not write. Do not run Pint, Prettier or codegen over the whole tree when the tree is not clean.
- **Stage explicit paths** (`git add <path>`), and for a file with both your and their hunks stage only yours (`git add -p`, or apply a patch to the index). A commit that includes someone else's half-finished file — or that leaves *your* new file untracked while a
  committed file imports it — breaks `tsc`/`vite build` for everyone (this has happened).
- **Check `git status` is clean of your files before you finish**: no untracked file your commit needs.
- **Hotspots:** `docs/STATUS.md`, `docs/decisions/README.md`, `docs/OPEN-QUESTIONS.md`, generated clients, migrations, `app-shell`, shared components. Make minimal, surgical edits; if another agent changed one mid-task, reconcile rather than overwrite.

## When CI fails

1. **Find the actual job, step and test** (`gh run list`, `gh run view <id> --json jobs`, `gh api repos/:owner/:repo/actions/jobs/<job>/logs`). Name them.
2. **Reproduce** the failing step locally where possible (the matrix above).
3. **Classify:** regression you caused · stale test (a UI/API change by someone changed what it asserts) · environment or flaky (with evidence) · generated drift · migration issue · another agent's change.
4. **Fix what belongs to the task**; fix a stale test when the change that staled it is committed; a genuine cross-cutting product defect gets fixed or recorded, not hidden.
5. **Report remaining failures honestly**: job, test, cause, owner.

Recurring causes so far, and the preventive rule that closes each:

| Cause (seen repeatedly) | Rule |
|---|---|
| Typecheck/build fails on a file the author did not stage, or an import of an untracked file | Stage explicit paths, check `git status` for untracked files you need, run all four frontend checks |
| Pint fails on migrations | Run `pint --test` on every PHP path you add, migrations included |
| Demo seed fails on a fresh database in the e2e job | Seeders and seeder tests run on a *fresh* migrated schema; never rely on columns or rows from a long-lived dev DB |
| Codegen drift | Regenerate and commit whenever the API surface moves; check the diff is only yours |
| Codegen-check fails on the pushed head although the working tree is clean | A generated symbol was staged whose backend change is not in the pushed commits (another agent's uncommitted API change, or a directory-wide `git add`). Stage generated files by explicit path and verify against the delivered tree — see Generated files |
| Stale Playwright specs after wording/layout changes | Search the specs for what you renamed; update them in the same commit |
| Page-one / first-row assumptions | Deterministic lookups; see above |
| A new route or writer not registered in a ratchet | Run the ratchets before committing |
| A spec passing on the dev server fails only on the hosted production build | Layout/pointer differences (an off-screen Radix option); use keyboard activation and deterministic waits |
| Timeouts after Postgres recovery or login throttle | Classify as environment, verify, re-run |

## What you cannot reproduce locally

The GitHub-hosted runner's resources and timing (the e2e job is the slowest and most timing-sensitive), the arm64 image build and deploy on the self-hosted runner, and a pristine database *at the moment of the push*. Mitigate by running the e2e specs for your area
against a freshly seeded isolated stack when the change is large, and by keeping specs deterministic.

## Related

- [feature-completion-checklist.md](feature-completion-checklist.md) (row 13 and "Definition of complete") · [testing-standards.md](testing-standards.md) · [ci-cd.md](ci-cd.md) · [database.md](database.md#migration-standards) · [api-conventions.md](api-conventions.md)
- [AGENTS.md](../../AGENTS.md#working-alongside-other-agents) — shared-checkout rules
