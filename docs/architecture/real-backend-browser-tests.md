# Real-backend browser tests

The unit browser specs (`*.unit.spec.ts`) mount real React screens against a scripted in-memory gateway. They prove the screens, not the system. The real-backend journeys
(`*.real.spec.ts`, Playwright project `real-backend`) mount the same production screens with their **live gateways and the generated client** in Chromium against **this
checkout's actual Laravel application** and a private, throwaway database. Nothing is intercepted: every request reaches the real routes, middleware, Actions, policies and
database. They check what a scripted gateway cannot: the server's own verdicts (for example that a person whose grant was revoked is refused the review *and* the release, so a stale screen can offer nothing, and that a business replay names the same Job).

## Running

```bash
ERP_AGENT=<you> docker/frontend/run-real-backend-e2e.sh               # every *.real.spec.ts
ERP_AGENT=<you> docker/frontend/run-real-backend-e2e.sh sales-handover # one spec
ERP_REAL_BACKEND_KEEP=1 ERP_AGENT=<you> docker/frontend/run-real-backend-e2e.sh --list   # leave the server up to probe it (docker rm -f erp-e2e-… to stop)
```

Build the production CSS first (`npm run build --workspace apps/main`, the fixtures read `apps/main/dist/assets`). Unlike `run-e2e.sh` this is **safe from a worktree**: it runs *that
checkout's* code, uses the private database `erp_testing_<agent>_<workspace>_e2e` on the throwaway test Postgres, a loopback-only port (18090–18190), and never touches the dev
stack, the dev database or the shared saved login. CI runs the same thing as the job `e2e-real-backend` (part of the `e2e` gate).

What it does: migrates the private database from scratch → builds the committed **test world** with the real Actions (`backend/tests/E2E/RealBackend/BuildWorldTest.php`) → starts the
real application with `php -S` through the **test-only router** `backend/tests/E2E/RealBackend/serve.php` → runs the specs → removes the server.

## Test-only authority: why it cannot become production authority

The Phase 5 duties (`sales.handover.*`, the Jobs duties) are **STAGED** and nobody may be granted them in production, so a journey needs authority that exists only in its own
process. Everything lives under `backend/tests/E2E/RealBackend/` and is applied by `serve.php` alone:

- `RealBackendAuthority::apply()` rebinds, **in that process's container**, the two duty manifests, the company resolver (`TokenCompanyContext`: the company of the bearer token's
  owner, read with raw queries and falling back to company 1, so a forged token can never select another company) and the source-family registry (`NoBindingFamilies`, stand-ins for the
  Inventory/Purchasing family authorities whose production registration belongs to their owners). It ends with the process, is never persisted and is never reachable over HTTP.
- `serve.php` refuses (HTTP 503, nothing served) unless `ERP_E2E_BACKEND=1`, `APP_ENV=testing` **and** the database is a private `erp_testing_*e2e` one (`RealBackendAuthority::refusal()`).
- It is **not** referenced by any file in `app/`, `bootstrap/`, `config/`, `routes/`, `database/` or `public/`; `tests/` is `autoload-dev`, so a `--no-dev` production install cannot load it;
  no `phpunit.xml` suite includes `tests/E2E`. `tests/Architecture/RealBackendHarnessIsolationTest` pins all of that, the refusal matrix, and that the shipped manifests and
  `SalesDevPreview` still leave every handover duty STAGED / excluded.
- The people are explicit throwaway users in the throwaway database with explicit Spatie grants made by the world builder. `SalesDevPreview` and its drift test are untouched.
- The Playwright `real-backend` project is only defined when `E2E_REAL_BACKEND_URL` is set (by the runner/CI job), so the shared-stack and sharded runs can never execute these specs.

## The reusable fixture (for the Jobs execution journey and later ones)

- **World** (`BuildWorldTest` → JSON, typed in `frontend/apps/e2e/tests/real-backend/world.ts`, loaded with `world()`): people with real Sanctum tokens and their duties — `officer`, `revoked_officer`,
  `preparer`, `viewer`, `releaser_without_jobs`, `officer_without_job_view`, `evidence_recorder`, `foreign_officer` (another company) — and accepted services in the states a journey needs:
  `remote`, `onsite` (accepted, unprepared, with a written agreement message), `evidence` (prepared; a corrected message and its correction), `bare` (prepared, nothing else),
  `stale`, `race`, `dup`, `norecv` (prepared, genuine conditions recorded, ready to release), `released` (released through the real Actions: **one Job**). Duties granted: `world.duties`.
- **For Jobs:** `world.jobs.released_job` / `released_job_code` is a Job in state RELEASED with no responsibility yet, and `jobs.job_holders` lists the holders that hold the Jobs duties
  (`job.receive`, `job.view`, `job.assign`, `job.start`, …). Start the execution journey from `/jobs/{released_job}` as `officer`. Each scenario is consumed by the test that uses it;
  add a new scenario in `BuildWorldTest` (one `ready('tag')` call) rather than sharing one between tests.
- **Mounting** (`frontend/apps/e2e/tests/real-backend/mount.ts`): `openAs(page, user, '/path', width?)` serves the fixture page from the backend's own origin and sets the person's token and duties;
  routes are declared in `frontend/apps/e2e/tests/fixtures/real-backend-app.tsx` (add a `<Route>` for a new screen). `api(request, user, method, path, body?, idempotencyKey?)` calls the real API as that
  person; `jobCodes(request, user)` lists the Jobs they can see (a count is how "exactly one Job" is proved); `recordApi(page)` lists the API calls the page made.
- Add duties for another module in `RealBackendAuthority` (`SALES` / `JOBS` lists and a manifest for that module) and grant them in the world builder. Never add a switch to production code.

## What the Sales journey proves (`sales-handover-journey.real.spec.ts`)

Accepted service → Prepare handover → record genuine completion evidence → preview release → release → existing Job → reopen; remote and on-site; only the accepted customer's own contacts
and sites offered (another customer's refused by the server); missing evidence; corrected evidence (the old message cannot be chosen, and a correction after recording blocks the release
server-side even from an open page); stale review (new conditions elsewhere → refused → fresh review → one release); duplicate submission (double-click and a second browser → exactly one
Job); a second release attempt names the same Job and creates nothing; second preparation refused; viewer / preparer / release-without-Jobs-duty / revoked person / Jobs-blind person / another
company; 320 px and axe on the real screens.

## Limits (deliberate)

- Quotation **issuing** and the **acceptance** are built by the world builder through the real Actions (their screens are not driven here); the journey begins at the accepted service.
- The other company is simulated by `TokenCompanyContext` (production resolves one company until the multi-company gate, issue #119), exactly as the in-process HTTP tests simulate it.
- A revocation is a person whose grant was revoked after their token was issued (the server's verdict on a stale screen); live mid-request revocation races are covered by the PostgreSQL
  two-session tests (`tests/Feature/Security/*Race*`), not here.
- `php -S` serves the loopback port with a few workers; this is a test server, not a deployment model.
