[← Architecture](README.md)

# Testing standards

What a feature's tests must cover before it is complete. This is an **applicability checklist, not a quota**: for each row ask whether the feature touches that concern; if it does, the test is required;
if it does not, say so in the pull request (one line is enough). The list is distilled from what the access audit, the stock engine and the package-tracking work actually needed. The stack and
CI jobs are in [ci-cd.md](ci-cd.md#tests); the generic test types are in [code-structure.md](code-structure.md#testing).

## Backend — what to prove

| Concern | Applies when | The test proves | Pattern to copy |
|---|---|---|---|
| **Happy path** | always | the feature does what it says, against a real PostgreSQL | any `*ApiTest`; Pest feature tests |
| **Permissions** | any new action, endpoint or sensitive field | allowed with the capability; denied without it; one capability alone does not unlock another duty | the module's allowed/denied suite; `PackageStockApiTest` |
| **Direct Action call** | any mutation | the Action refuses an unauthorised actor when called with no HTTP (the second guard) | `ActionAuthorization` tests; `WriteRouteGuardTest` ratchet |
| **Cross-company isolation** | any company-scoped resource | another company's record is unreachable **by id** on show, update and every action route — not merely absent from the list | `AccessAdministrationTenantIsolationTest`; `another companys …` cases |
| **Record scope** (organisation unit / warehouse) | any surface that carries stock or scoped records | a limited person gets the declared answer (`403` or `404` or a narrowed list) on response bodies; a person with no unit and an unowned warehouse are covered | `WarehouseRecordScopeTest`; `RecordScopeCoverageTest` |
| **Sensitive fields** | any price, cost, margin, GL, credit or approval amount | omitted from list, show, include, lookup, print and evidence without the capability; denied filter/sort/include | field-visibility tests; `OpenReadRoutesTest` |
| **Database invariant** | any trigger or constraint you added | raw SQL (or a bypassing Action) is rejected at commit; the constraint cannot be disabled by the Action's own checks | `PackageKernelStorageTest`, `GroupedQaDispositionTest` |
| **Idempotency / retry** | any retryable write | same key and payload returns the original and creates nothing; a changed payload is refused; a retry re-checks authority | `…is idempotent`, `retry still requires current authority` |
| **Rollback / atomicity** | a write touching several tables or lines | a failure at the last step leaves nothing, including rows written for earlier lines | `mismatched composition rolls back every package row` |
| **Stale / concurrent state** | evidence or drafts that capture state | a moved watermark is refused with `STALE_EVIDENCE`; two connections cannot both succeed | `request lock excludes a second database connection`; stale-line count test |
| **Legacy parity** | adding an advanced mode or optional capability | an item/company that never opts in behaves exactly as before | `quantity-only` parity cases and `package-quantity-only.spec.ts` |
| **Rollout** | a new permission, setting or state | the grant/backfill migration is additive, idempotent, per-company and does not over-grant | `StockVisibilityRolloutTest`, `PackageStateRolloutTest` |
| **Regression** | fixing any discovered gap | a test that fails without the fix, named for the gap | — |

## Architecture and ratchet tests

Ratchets fail the day a new route, writer or table silently skips a rule. A feature that adds the thing a ratchet watches must register it (with a reason) in the same change.

| Test | What it keeps true |
|---|---|
| `WriteRouteGuardTest` | every POST/PUT/PATCH/DELETE route has a request-layer guard, or a recorded reason; and no new route relies on the HTTP boundary alone (Action-level guard) |
| `OpenReadRoutesTest` (Feature/Support) | every id-less API read answers 403 to a caller with no capability, unless listed as open on purpose |
| `RecordScopeCoverageTest` | every stock-bearing route has a record-scope status (scoped / refused when limited / shared reference); limited-person routes actually answer `403` |
| `StockWriterMatrixTest` | every code path that creates or changes stock rows is listed with its treatment for an authoritative mode |
| `PackageOperationsHaveNoEntryPointTest`, `ImportStagingHasNoEntryPointTest` | internal operations stay unreachable until deliberately given an entry point |
| `PermissionCheckRegistrationTest` | every literal permission string checked in code is registered |
| `CompanyScopedForeignKeyTest`, `CompanyScopedUniqueIndexTest` | company ownership participates in every relationship and unique constraint |
| `ControllerActionBoundaryTest` | controllers delegate to Actions |
| `TestingEnvironmentIsolationTest` | tests never touch the development or production database |

## Browser (Playwright) — user-facing workflows

Required for any new or changed workflow screen; they run against the seeded dev stack and in CI.

- **Journey:** drive the main happy path through the real UI, with API setup via the shared helpers (`support.ts`, `package-stock-support.ts`). Create the data the test needs by business identity; **do not depend
  on record IDs, on a row being first on a list, or on demo data that grows** — filter, search or open by identity.
- **Denied and limited users:** a bookmarked URL and a hidden control for a user without the capability (ADR 0066).
- **Backend refusals:** the screen's reaction to a stable `reason_code` (mock the response where the state cannot be produced legitimately, and say so in a comment).
- **Phone width:** a workflow people may use on a phone is checked at about 390 px: dialogs and panels fit inside the viewport, the page has no horizontal scroll, and the primary action is the topmost
  element at its position (not under the bottom navigation or an overlay). Pattern: `package-mobile.spec.ts`.
- **Accessibility and theme:** new pages join `accessibility.spec.ts` (serious/critical axe violations fail); check contrast in light and dark (`screen-design-standard.spec.ts`); keyboard and focus order
  for new dialogs.
- **Second actor:** where separation of duties applies (a different person posts), sign in a second persona in a fresh context instead of weakening the rule.
- Assert **outcomes**, not that a class exists; assert the server's state through the API after a mutation.

## Frontend unit and type checks

The three TypeScript projects (`apps/main`, `packages/core-ui`, `packages/core-api`) must typecheck and `vite build` must pass. Generated client drift fails CI ([api-conventions.md](api-conventions.md#generated-client)).

## Test data and independence

- Fixtures create their own records; nothing depends on mutable demo data or on a specific auto-increment id.
- Tests that need a permission grant it explicitly; do not give a persona a broad role to make a test pass ([authorization.md](authorization.md) R5).
- `PurchasingActor::make` and similar helpers can hold more capabilities than the name suggests — assert the actor's actual capabilities when a test depends on a denial.
- Deferred database checks fire at commit; in a test that wants them to run mid-way, use `SET CONSTRAINTS ALL IMMEDIATE` then `DEFERRED` ([GroupedPackageActivationTest](../../backend/tests/Feature/Modules/Inventory/GroupedPackageActivationTest.php)).
- **A page of a list is not a lookup table.** A fixture finds a record by its code through the server (`findByCode` → `filter[search]` + exact match) or by id, never "the first match in the first 100 rows" — leftovers from other runs push demo rows off page one. The same applies to the product: see [ui-building-blocks.md](ui-building-blocks.md#lookups-that-survive-large-datasets).
- **Leave no avoidable records behind.** A spec that must create bulk data (for example more than 100 items) removes exactly what it created in a `finally`; `tests/global-teardown.ts` archives only the `e2e-`/`aaa-filler-` items created during the run (by creation time — never a wipe), so the shared dev database does not drift run after run.
- Sign-in is throttled; a spec that signs a persona in once per test stays inside the limit, a loop of failed logins does not.

## Running

Which of these to run for which change, and what CI adds, is in [pre-push-checks.md](pre-push-checks.md).

Backend: `docker compose exec app php artisan test` (or `docker/backend/run-tests-tmpfs.sh`), `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=2G`. Frontend: `npx tsc --noEmit` in each project, `npx playwright test`
in `frontend/apps/e2e`. Regenerate the client with `php artisan scramble:export --path=storage/api-docs/openapi.json` then `npm run codegen`, and commit only what your change moved.

## Related

- [feature-completion-checklist.md](feature-completion-checklist.md) · [domain-integrity.md](domain-integrity.md) · [authorization.md](authorization.md#build-rules-every-new-feature-follows)
- [access-security-audit.md](access-security-audit.md) — the coverage map the ratchets protect
- [ci-cd.md](ci-cd.md) · [code-structure.md](code-structure.md) · [database.md](database.md)
- [erp-blue-design-standard.md](erp-blue-design-standard.md) — the screen checklist the browser tests verify
