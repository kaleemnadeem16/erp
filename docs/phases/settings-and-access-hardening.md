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

1. **A3/A4 — Finance field visibility** (open question [Q34](../OPEN-QUESTIONS.md#q34--finance-owned-field-visibility-for-accounts-and-credit-terms)):
   who may read or write Item/Category GL-account IDs, customer credit and terms, and control-account IDs. Then implement the projection and
   write checks everywhere those fields appear (list, detail, lookup, print) with field-presence tests.
2. **Approvals** (open question [Q35](../OPEN-QUESTIONS.md#q35--approval-evidence-exception-and-authority-ceiling-visibility)): confirm that an approver sees the amount of the
   request they must decide without seeing PO line prices, and decide who may list per-person authority ceilings.
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

## Known CI and environment issues (not caused by this workstream)

- `lint-backend`: Pint flags two package migrations (`…180000_create_packages_and_supplier_offers`, `…180100_add_packaged_purchasing_snapshots`)
  and previously `PackagedPurchasingTest`. They belong to the package workstream; a formatting-only fix once nobody is editing them.
- `e2e`: the demo seed fails on a fresh database because `PackagedPurchasingDemoSeeder::receipt()` passes `notes` to a goods receipt and
  `goods_receipts` has no such column in any migration. The package workstream owns the fix (add the column or drop the key); rerun `e2e` after.
- `TestingEnvironmentIsolationTest` fails only under `run-tests-tmpfs.sh` (the script sets `TEST_DB_*`); it passes in CI.
- Migrations that create SQL functions must use `CREATE OR REPLACE FUNCTION` (tests run `migrate:fresh`, which does not drop functions).
- Dev database: `journey.spec.ts` and the line-UX specs leave drafts and receipts behind; `purchasing-commercial-visibility.spec.ts` assumes
  which draft receipt is first and can fail after them.

## Related

- [STATUS.md](../STATUS.md), [access-security-audit.md](../architecture/access-security-audit.md), [authorization.md](../architecture/authorization.md)
- [ADR 0079](../decisions/0079-document-numbering-system-templates-over-a-variable-registry.md), [ADR 0081](../decisions/0081-central-company-profile-and-company-wide-settings.md), [ADR 0083](../decisions/0083-layered-stock-read-capabilities-and-blind-counting.md)
- [package-balance-handoff.md](package-balance-handoff.md) — the other workstream sharing this checkout
