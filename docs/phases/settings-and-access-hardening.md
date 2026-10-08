[← Roadmap](README.md)

# Settings, forms and access hardening — workstream record and continuation point

Recorded 2026-10-03 after commit **6956a11**. This is the continuation point for the company/settings work, the Purchase Order form
redesign and the access-security audit. It is scoped to this workstream; the package-balance work
([package-balance-handoff.md](package-balance-handoff.md)) and the project's next phase continue independently in the same checkout.
Update this file when one of its open items is picked up, alongside [STATUS.md](../STATUS.md) and the
[audit tracker](../architecture/access-security-audit.md).

> **Reconciliation note (2026-10-08).** This record's resume text below was written on 2026-10-06 and parts of it are out of date. Where it conflicts with this note, this note wins:
> - **Pushing:** the "nothing is pushed / pushing is paused" statements are superseded — the owner authorised pushing completed `develop` commits (2026-10-07), and `develop` is
>   pushed to origin apart from a few recent local commits. Pushing or merging to `main` (production) is still only with the owner's go-ahead; production last released 2026-10-02.
> - **Q26 record scope:** the "apply warehouse scope to negative-stock overrides, recalculation and cutover" step is **done** (ADR 0089 second slice); `RecordScopeCoverageTest` has no
>   PENDING routes. What remains of Q26: purchasing documents (needs creator and ship-to recorded plus a backfill — an owner decision), Estimating availability, approvals, a
>   warehouse-limited overview and AVCO pool view, own/assigned scopes, time-bounded task access and delegated administration.
> - **Still open and current:** Q28's default-policy half, Q37, the staging sign-off for A1–A9 and ADRs 0084–0089, and the release steps in the final section (re-run the listed
>   browser specs, bring the dev database up to date, check generated-client drift, decide on scheduling `ExpireOverdueApprovals`).
> - The checkout-stability caveat in the last section reflected a moment on 2026-10-06; treat it as a checklist to run before release, not as a current blocker.
> Package-balance work, which shares this checkout, is complete for v1: [package-balance-handoff.md](package-balance-handoff.md).

## Resume here (checkpoint 2026-10-06, end of session)

**Read this first when continuing on another machine.**

- **Nothing here is pushed.** `develop` holds about 22 commits that exist only on the machine they were made on; the owner has paused pushing
  until told. To continue elsewhere the commits must first leave this machine: either the owner says "push" (then `git push origin develop`), or
  copy the repository (`git bundle create erp-develop.bundle origin/develop..develop`, then `git pull erp-develop.bundle develop` on the other
  machine). Do not start on another machine without one of these, or the work below will not exist there.
- **What this workstream delivered since the 2026-10-03 checkpoint** (each has an ADR and a row in [STATUS.md](../STATUS.md)): recipe visibility
  as a capability ([0086](../decisions/0086-gate-recipe-reads-by-capability.md), A9); document review for approvals
  ([0085](../decisions/0085-request-specific-document-review-for-approvals.md), Q35); approval limits and written reasons as auditor capabilities
  ([0087](../decisions/0087-approval-limits-and-reasons-are-separate-auditor-capabilities.md), Q35); device tokens that expire and sign-in seams for
  stronger authentication ([0088](../decisions/0088-device-tokens-expire-and-sign-in-has-seams-for-stronger-authentication.md), Q36); organisation
  units and record scope for inventory by warehouse ([0089](../decisions/0089-organisation-units-and-record-scope.md), Q26 first slice).
- **Q26 next steps** (record scope; the resolver and pattern exist, so each slice is mostly applying them):
  - *Needs an owner decision:* (1) whether to start recording a **creator and ship-to on purchase orders and receipts**, with a backfill, which
    is what lets buyers be limited to their own or their site's documents (they cannot be scoped without it); (2) when to build **delegated
    administration** (a unit head assigning access to their people); (3) whether a limited person should get a **warehouse-limited inventory
    overview and AVCO pool view** instead of today's 403.
  - *Can be done without a new decision, in this order:* apply warehouse scope to **negative-stock overrides, recalculation and cutover** (still
    company-wide; `RecordScopeCoverageTest` lists them as PENDING; move each to SCOPED as its slice lands), then Estimating stock availability
    and approvals. For each: apply the scope at the API boundary (list, count, search, filter, sort, include, show, and the entry of writes; a
    404 when out of scope), never as a global model scope; prove it on response bodies as `WarehouseRecordScopeTest` does, including a limited
    person in no unit and a warehouse owned by no unit; mutation-check it.
  - *Not mine to do:* the staging check. Nobody is limited automatically; define units, bind warehouses and limit people one at a time with real
    warehouse staff.
- **Also still open:** Q28 (purchase-order approval policy), Q37 (two-factor, step-up and mobile re-authentication: research first), the
  staging sign-off for A1–A9 and ADRs 0084–0089, and the owed release steps in the section below.
- **Working agreements still in force:** commit only your own files by explicit path (other agents share the checkout, so check the diff of a shared
  file before committing it and commit from the HEAD content plus only your lines when it holds someone else's edits); never `git stash`; no push to
  `main`; research before design; the owner decides policy, the agent builds and proves it.

The original 2026-10-03 note follows.

Nothing is half-done or uncommitted from this workstream. Pick any item under **Still to do**; the two things that need *a decision from
the owner first* are marked. The order the owner last confirmed for this workstream was: A6 → governance Action guards → master-data Action
guards → **report the remaining audit items before starting another major slice** (this file is that report).

Working agreements recorded during the work (the owner stated them; keep following them):

- Commit only your own files, by explicit path, with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; other agents share the
  checkout. For files two agents edit (`docs/STATUS.md`, `docs/decisions/README.md`) **inspect the diff first** and preserve the other
  agent's uncommitted lines — never `git checkout` them blind. Commit shared docs from the HEAD blob (a temporary index) when the working copy
  holds someone else's edits, then put your own lines into the working copy too so their next commit does not drop them.
- Never `git stash`. Push `develop` freely; no push or merge to `main` without the owner's go-ahead (the owner merges; production steps are theirs).
- Research before design ([research-and-design.md](../architecture/research-and-design.md)); decide first when the owner asks for a design
  ("do not implement until we agree"); explain in plain terms; give per-file plain-English summaries after work; demo data accompanies a feature.
- Keep UI alternatives switchable and catalogued; fix a defect once in the shared component or global CSS, not per screen. Never store the
  owner's shared passwords.
- Generated API client: regenerate (`php artisan scramble:export --path=storage/api-docs/openapi.json`, then `npx orval` in
  `frontend/packages/core-api`) and commit only the files your change moved; CI fails on drift.

Practical commands (WSL, repository root `/home/kaleem/projects/erp`):

- Backend tests on the throwaway tmpfs database: `TEST_DB_NAME=erp_<yours> docker/backend/run-tests-tmpfs.sh <paths>` (a private name avoids
  colliding with another agent's run; drop it afterwards — the tmpfs fills up). `docker compose --profile test-db restart postgres-test` frees it.
- Pint: `docker compose exec -T app vendor/bin/pint <paths>` (give explicit paths — it will reformat other agents' files otherwise).
- Browser checks: `cd frontend/apps/e2e && npx playwright test <spec>` against the dev stack (`journey.spec.ts` creates dev data — skip it
  locally). After a permission change run `php artisan permission:register && php artisan erp:sync-admin-permissions <admin email>`.

## Delivered

| Slice | What it is | Commits | Records |
|---|---|---|---|
| Central document numbering | One framework: series of templates over a variable registry, versioned series, explicit counter scope, gapless or allow-gaps, constants, tester, allocation log; PO/GR migrated; **Settings → Document Numbering** screens | `0c1b6af`, `27d1463` | [ADR 0079](../decisions/0079-document-numbering-system-templates-over-a-variable-registry.md), [document-numbering.md](../architecture/document-numbering.md) |
| Settings → Company | Profile, stable short code, time zone (moved from Inventory), base currency and fiscal-year start with structural lifecycle locks, registration/tax numbers, governed history, `{COMPANY}` numbering variable, searchable IANA time-zone selector | `7ede292`, `61b0a85` | [ADR 0081](../decisions/0081-central-company-profile-and-company-wide-settings.md), [company-settings.md](../architecture/company-settings.md) |
| Purchase Order form (four passes) | Per-line and per-order "More details/options" disclosure, aligned line grid, one review/summary, shared steps for create and edit, field hints, replaces-order picker, unit limited to convertible units, table/card line views on phones, mobile fixes, animated `Collapse`, `SectionStack` and the `--section-gap`/`--collapse-*` tokens | `59a06d6`, `5d37243`, `32a4e41`, `1488d3a` | [ui-building-blocks.md](../architecture/ui-building-blocks.md) |
| Access audit A5 | Approval amount and currency evidence follow each subject's own field policy (`canViewEvidence`) plus need-to-know for the decider | `13f2f7b` | [audit tracker](../architecture/access-security-audit.md), [approvals.md](../domain/approvals.md) |
| Access audit A6 | `inventory.availability.view` / `.stock.view` / `.movement.view`, blind counting by role, grant migration that preserves existing screen holders (count-only roles deliberately not granted) | `f6826e7` | [ADR 0083](../decisions/0083-layered-stock-read-capabilities-and-blind-counting.md) |
| Access audit A7 and negative paths | Forgot-password no longer reveals accounts; tenant-isolation tests for Administration and Numbering; test that nothing reaches the ungated Imports Actions | `3311647` | tracker |
| Write-route guard inventory | `WriteRouteGuardTest`: every API write route has a capability guard or a recorded reason | `48dfcf5` | [authorization.md](../architecture/authorization.md) |
| Action-level guards | Every write Action checks its own capability through `ActionAuthorization` — governance (approval policies/authorities/delegations/mode, Estimating policies and reasons, Inventory/Purchasing/Company settings) then master data (POs, item suppliers, items, categories, warehouses, locations, location roles). The ratchet list is empty and must stay empty | `409b4d3`, `6956a11` | tracker |
| Earlier audit slices (other agent) | A1 exchange-rate, A2 chart-of-account, A3 Parties write boundaries | `f0f3ddb`, `1c83817`, `0aa4db1` | tracker |

## Still to do

### Needs an owner decision first

1. ~~**A3/A4 — Finance field visibility**~~ — accepted and implemented under ADR 0084; staging readership sign-off remains open.
2. ~~**Approvals** (Q35)~~ — resolved: document review under ADR 0085, limits and reasons under [ADR 0087](../decisions/0087-approval-limits-and-reasons-are-separate-auditor-capabilities.md). The original wording follows for history: decide who may list per-person authority ceilings and read confidential decision reasons.
3. ~~**Bearer-token lifetime** (Q36)~~ — resolved by [ADR 0088](../decisions/0088-device-tokens-expire-and-sign-in-has-seams-for-stronger-authentication.md); two-factor, step-up and mobile re-authentication are researched later as [Q37](../OPEN-QUESTIONS.md#q37--two-factor-step-up-and-mobile-re-authentication).
4. **Record-level scope and delegated administration** — [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope) is decided in part ([ADR 0089](../decisions/0089-organisation-units-and-record-scope.md)): inventory by warehouse is built; the remaining slices and delegated administration stay open there. Other stock-bearing surfaces are company-wide until their slice lands (`RecordScopeCoverageTest` names them).

### Ready to implement

- ~~**Negative-stock override request reads**~~ — done 2026-10-06 (Part 1 of the two-agent audit): list/show need a negative-stock duty or
  `inventory.stock.view`; create/decide/review now also refuse at the request boundary
  ([negative-stock.md](../domain/negative-stock.md#who-may-read-and-act-on-a-request-2026-10-06)).
- **Deployment verification (every slice A1–A6):** enumerate real members, roles, direct grants and service actors; verify effective access for
  representative warehouse, sales, counting, finance and administrator users in staging; record who approved the map and the result. The A6
  grant migration and the deploy job's `permission:register` + admin sync are the release steps; nothing has been verified outside dev.
- **Information-flow reviews still to do as surfaces appear:** Phase 4 quotations/adoption, package-balance routes (ADR 0082), any export or
  download, a future import screen (the staging Actions check no capability and a test fails when an entry point appears), the allocation-log
  screen for numbering.
- **Demo data:** `demo-counter@erp.x1techs.com` (blind counter) has a random password; set one through Access Administration to try the
  blind-count screens.

### Product follow-ups recorded, not started

- Company: branding/logo and per-company print defaults (deferred by decision until there is file storage); registration/tax numbers are stored
  but not printed on any document yet; a company switcher is neither built nor decided.
- Numbering: allocation-log read screen, PO `{WAREHOUSE}` after ship-to exists, numbers-must-follow-dates validation for gapless series.
- PO form: the detail screen shows a draft line's item as `#<id>` (draft lines have no item code) — show the name; quantity and unit could sit
  side by side on a phone; a closed line "More details" leaves a few extra pixels; move other forms onto `MoreOptionsToggle`/`Collapse`/
  `SectionStack`/`FieldHint` and `useDebouncedSearch` as they are touched.
- Approval-section enhancements are recorded in [approvals.md](../domain/approvals.md).

## Part 2 audit checkpoint — 2026-10-06

Core/Auth, Accounting, Parties, Administration, Approvals and Imports endpoints, Actions,
Resources, nested includes and available print/export entry points reviewed; exact coverage and
remaining policies are in the [tracker](../architecture/access-security-audit.md#coverage-map-and-next-inspection).
Administration Actions now refuse hydrated foreign members/roles and restrict profile attributes;
approval governance Actions re-query company-scoped targets; import staging re-queries its profile.
Approval decisions now refuse ineligible actors before stale/expiry preflight can change state.
Focused Part 2 checks pass 264 tests / 1,084 assertions; explicit-path Pint and PHPStan pass.
New response-body/Action tests accompany reused allowed/denied suites; no new grants or permissions.

The four [decision briefs](../OPEN-QUESTIONS.md) are recommendations awaiting the owner:
Q34 Finance fields, Q35 amount exception/ceilings/reasons, Q36 tokens, Q26 scope/delegated administration.
No policy implementation is included; A3/A4 field projections wait for Q34. The read-only
`erp:audit-access-inventory` command (now also flagging members who lost the A8/A9 reads that used to be open to everyone) and staging sign-off template are delivered; no live staging
assignments have been reviewed or changed. API response contracts are unchanged, so no client regeneration.

## Part 2 follow-up — remaining request/delegation boundaries (2026-10-06)

The six FX/chart/Party DELETE controllers now perform their own HTTP capability check, retaining
all existing Action gates. Approval eligibility now denies suspended/invited/temporary-password
actors and principals; a suspended principal's delegate previously still saw and decided requests.
New body/Action assertions prove denial and restored access. Delegation-list reads are explicitly
classified as involvement-scoped under ADR 0070; Q35's ceiling/reason policy remains undecided.

The read-only inventory also passed a development runtime smoke check; two unresolved assignment
actors need owner review. This is not staging verification. Q26/Q34/Q35/Q36 remain pending, and
an independent staging target plus approved assignment/classification map are still required.
Success payloads are unchanged; the six affected clients and 403 error models plus their barrel
were regenerated because the HTTP guards add explicit OpenAPI 403 responses. No new grants.
Focused backend checks pass 187 tests / 771 assertions; explicit-path Pint, PHPStan and TypeScript
checks pass.

## Known CI and environment issues (not caused by this workstream)

- `lint-backend`: Pint flags two package migrations (`…180000_create_packages_and_supplier_offers`, `…180100_add_packaged_purchasing_snapshots`)
  and previously `PackagedPurchasingTest`. They belong to the package workstream; a formatting-only fix once nobody is editing them.
- `e2e`: the demo seed fails on a fresh database because `PackagedPurchasingDemoSeeder::receipt()` passes `notes` to a goods receipt and
  `goods_receipts` has no such column in any migration. The package workstream owns the fix (add the column or drop the key); rerun `e2e` after.
- `TestingEnvironmentIsolationTest` fails only under `run-tests-tmpfs.sh` (the script sets `TEST_DB_*`); it passes in CI.
- Migrations that create SQL functions must use `CREATE OR REPLACE FUNCTION` (tests run `migrate:fresh`, which does not drop functions).
- Dev database: `journey.spec.ts` and the line-UX specs leave drafts and receipts behind; `purchasing-commercial-visibility.spec.ts` assumes
  which draft receipt is first and can fail after them.

## Q34 implementation checkpoint — 2026-10-06

The owner accepted the recommended split in [ADR 0084](../decisions/0084-separate-financial-master-data-fields.md).
A3/A4 financial fields now have separate read/change capabilities, omitted restricted response keys,
request/Action write checks and an additive prior-maintainer grant migration. Category forms,
detail and print follow the same policy; generated optional Resource types accompany the change.
Verification: 248 backend tests passed (the full run also hit the already documented
`TestingEnvironmentIsolationTest` wrapper failure: it expects `erp_testing`, but the required
private wrapper sets `erp_codex_q34`). The read-only inventory test passes separately (20 assertions).
The category browser spec passes all three permission cases plus sign-in (4 tests), including
hidden print content and submitted field absence. Explicit-path Pint, PHPStan and TypeScript pass.
API regeneration changed only four Resource types; the private test database was dropped afterwards.
For a development walkthrough, use existing categories with an ordinary category-maintainer,
a mapping reader, and a mapping manager; the browser fixtures exercise these without changing
live categories or broadening demo roles. Before release, register permissions, run the additive
migration and normal designated-admin sync, then complete the staging assignment sign-off.

Staging Finance/sales readership and service-actor verification remain open. The earlier checkpoints
are historical evidence; Q34 is resolved, while Q26/Q35/Q36 remain undecided.

## Approval demo fixture repair — 2026-10-06

The stricter actor/principal eligibility exposed unhydrated database defaults on newly created
demo approvers. The shared demo-member helper creates once, loads persisted lifecycle state,
preserves password changes on reruns and refuses restricted personas. Approval seeding keeps
its narrow persona permissions and renews lapsed authority through `CreateApprovalAuthority`,
without replacing the live request. `PermissionedActor` and production eligibility are unchanged.
Final requested full run (`tests/Feature/Modules tests/Feature/Support tests/Architecture`):
**1,676 passed, one remaining known failure, 8,582 assertions** (416.36 seconds).
The sole failure is `TestingEnvironmentIsolationTest.php:24`, which expects `erp_testing` while
the required wrapper deliberately selects the private `erp_codex_fixture_probe` database.
The package-opening concurrency reproduction now passes; fresh/rerun/live-drift demo suites,
`DemoMemberLifecycleTest`, authority/delegation renewal and the existing suspended/invited/
temporary-password approval-denial tests pass. Explicit-path Pint and PHPStan pass; both private
test databases were dropped. No real application-flow failure was found, no production eligibility
or persona capability was relaxed, and no owner decision is needed for this fixture repair.
No API contract changed, so there is no client regeneration.

### Q35 document-review delivery — 2026-10-06

Full commercial PO review is the default; optional technical steps omit money from API bodies
and print. Frozen sections, immutable decision profiles and fingerprint invalidation protect
historical and same-total changes. The additive migration preserves existing full approvers,
adds no broad role and prepares only current pending requests. Legacy completed reviews are not
reconstructed. The narrow technical demo includes a separate commercial step and idempotent
replenishment. Client regeneration changes only approval schemas. Staging sign-off remains open;
Q35 ceiling/reason access, Q26 and Q36 remain owner decisions. See [ADR 0085](../decisions/0085-request-specific-document-review-for-approvals.md).

## To do when the shared checkout is stable (recorded 2026-10-06)

Several agents work in one checkout, and on 2026-10-06 another workstream (goods-receipt editing in Purchasing, with Administration and Item
changes) had uncommitted work in progress. Its regenerated API client dropped `usePurchaseOrdersUpdate`, a second `PATCH purchase-orders` route
had appeared, and the dev app's login page crashed. That is not this workstream's change, but it blocked verification, so these steps are owed:

1. **Re-run the browser specs against the live dev stack** once the tree builds again. **Done 2026-10-06 (11 checks pass):** `recipe-access.spec.ts`,
   `approval-reason-visibility.spec.ts` and `signed-in-devices.spec.ts`. **Still to re-run:** `approval-document-review.spec.ts`,
   `financial-master-data-visibility.spec.ts` and `approvals.spec.ts`
   (`cd frontend/apps/e2e && npx playwright test <spec> --project=chromium --workers=1`; sign-in is throttled to 5 a minute, so leave a minute between runs).
   Backend tests passed; only the browser check against the live app is outstanding for ADR 0087.
2. **Bring the dev database up to date** (not done, because `migrate` would also run another workstream's pending migrations): `php artisan migrate`,
   `php artisan permission:register`, `php artisan erp:sync-admin-permissions user@erp.x1techs.com`, then `php artisan demo:seed`. That creates the
   ADR 0087 backfill and the demo personas `demo-auditor@erp.x1techs.com` and `demo-historian@erp.x1techs.com`.
3. **Check the generated client** after the other workstream lands: regenerate (`scramble:export`, then `orval`) and confirm `git status` shows no drift;
   CI fails on drift. Commit only what your change moved.
4. **Staging sign-off for A1–A9 and ADR 0087:** run `erp:audit-access-inventory` on staging and decide per person who keeps `approvals.authority.view`,
   `approvals.reason.view` and the other A8/A9 reads (the report's `read_access_lost` section lists who lost one).
5. **Pushing `develop` is paused** until the owner says otherwise.
   **Q26 (ADR 0089) release steps:** `php artisan migrate` (organisation tables), `permission:register` and the administrator sync (two new capabilities); nobody
   is limited by the release, so access is unchanged. Before limiting anyone on staging: define the units, bind each warehouse to one, put people in
   them, and limit assignments one person at a time, checking with real warehouse staff; `erp:audit-access-inventory` lists who is limited and their units.
   Remember the overview and AVCO pools refuse a limited person, and that the pending surfaces are still company-wide.
6. **Q36 (ADR 0088) release steps:** `php artisan migrate` (adds `meta` to `personal_access_tokens`); count bearer tokens in production before release
   (`erp:audit-access-inventory`, or `select count(*) from personal_access_tokens`) — existing tokens expire 30 days after creation; the browser spec
   `signed-in-devices.spec.ts` passes against the live stack (3 checks, the API mocked).
7. **Found while doing Q36, not changed:** the scheduler container runs every minute, but until Q36 nothing was scheduled, and `ExpireOverdueApprovals`
   (flagging and expiring overdue approval steps) is called by nothing but tests, so an approval only expires lazily when someone tries to decide it. Decide
   whether to schedule it (ADR 0070 designed it to run on a timer); it changes what happens to overdue requests in production, so it is the owner's call.

## Related

- [STATUS.md](../STATUS.md), [access-security-audit.md](../architecture/access-security-audit.md), [authorization.md](../architecture/authorization.md)
- [ADR 0079](../decisions/0079-document-numbering-system-templates-over-a-variable-registry.md), [ADR 0081](../decisions/0081-central-company-profile-and-company-wide-settings.md), [ADR 0083](../decisions/0083-layered-stock-read-capabilities-and-blind-counting.md)
- [package-balance-handoff.md](package-balance-handoff.md) — the other workstream sharing this checkout
