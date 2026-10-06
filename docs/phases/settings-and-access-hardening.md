[← Roadmap](README.md)

# Settings, forms and access hardening — workstream record and continuation point

Recorded 2026-10-03 after commit **6956a11**. This is the continuation point for the company/settings work, the Purchase Order form
redesign and the access-security audit. It is scoped to this workstream; the package-balance work
([package-balance-handoff.md](package-balance-handoff.md)) and the project's next phase continue independently in the same checkout.
Update this file when one of its open items is picked up, alongside [STATUS.md](../STATUS.md) and the
[audit tracker](../architecture/access-security-audit.md).

## Resume here

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
2. **Approvals** (open question [Q35](../OPEN-QUESTIONS.md#q35--approval-evidence-exception-and-authority-ceiling-visibility)): document review is implemented under ADR 0085; decide who may list per-person authority ceilings and read confidential decision reasons.
3. **Bearer-token lifetime** (open question [Q36](../OPEN-QUESTIONS.md#q36--bearer-token-lifetime-and-device-sessions)) — before any mobile client ships.
4. **Record-level scope and delegated administration** — already [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope); the stock-read capabilities are company-wide until it is decided.

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
`erp:audit-access-inventory` command and staging sign-off template are delivered; no live staging
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

## Related

- [STATUS.md](../STATUS.md), [access-security-audit.md](../architecture/access-security-audit.md), [authorization.md](../architecture/authorization.md)
- [ADR 0079](../decisions/0079-document-numbering-system-templates-over-a-variable-registry.md), [ADR 0081](../decisions/0081-central-company-profile-and-company-wide-settings.md), [ADR 0083](../decisions/0083-layered-stock-read-capabilities-and-blind-counting.md)
- [package-balance-handoff.md](package-balance-handoff.md) — the other workstream sharing this checkout
