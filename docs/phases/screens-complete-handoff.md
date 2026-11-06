[← Phases](README.md)

# Screens completion — handoff (`cloud/screens-complete`)

Written so the next session (or person) can pick the work up cold. It says where the branch stands, what is done, what is left in the order to do it, what the owner
still has to decide, how to run and check things, and how to hand the result over. The detail behind every line is in the
[working log](screens-complete-log.md); the screens' specs are in [parties-screens.md](../domain/parties-screens.md),
[reference-data-screens.md](../domain/reference-data-screens.md) and [estimating-screens.md](../domain/estimating-screens.md). This is not a status page
([STATUS.md](../STATUS.md) is not touched by this branch).

## 1. Where the branch stands

| | |
|---|---|
| Branch | `cloud/screens-complete`, cut from `origin/develop` at `f3e7d0d2`; 16 commits ahead of `develop` at `71a39818` (`develop` was `09c4d9d3` at the last check), plus the commit that adds this file |
| CI | **green** on `71a39818` (lint-backend, lint-frontend, codegen-check, test 1–4/4, e2e 1–3/3) — [run 42031590907](https://github.com/kaleemnadeem16/erp/actions/runs/42031590907). The commit that adds this file is docs-only; check its run before relying on it |
| Merge state | **Not merged, no pull request opened** (nobody asked for one). A dry-run merge of `develop` into the branch had no conflicts at the last check; redo it (`git merge-tree --write-tree HEAD origin/develop`) before opening a PR |
| Issue | The full reports are comments on issue #85 (first the stages 1–5 report, then the Estimating update) |
| Rules that still hold | never force-push, rebase or rewrite history; never touch other branches; nothing against the owner's shared dev database; no secrets in this public repo; do not edit `docs/STATUS.md`, `docs/STATUS-LOG.md` or `docs/phases/README.md`; never loosen, skip or delete a test to get green; no production-gate changes (`QUOTATION_UI_ENABLED` stays false); no `features/quotations` edits |

## 2. What is done

| Stage | What | Where to read more |
|---|---|---|
| 0–1 | Rules summary (S1–S13), audit of routes → screens, non-standard screens and backend features without UI | log §1–§3 |
| 1 | **Customers** (Sales) and **Trading partners** (+ contacts), customer accounts, demo data through the real Actions; one additive backend change (customer-account index search/filters/sorts) | [parties-screens.md](../domain/parties-screens.md) |
| 2 | **Stock operations** — Physical counts, Inventory cutovers, Negative-stock overrides, Stock recalculation — on the list standard, records as sheets (`RecordPresenter`) | log §4 stage 2 |
| 3 | **Exchange rates** and **Chart of accounts** (under Settings) | [reference-data-screens.md](../domain/reference-data-screens.md) |
| 4 | Supplier address edit/remove, warehouse location roles; two generated API modules exported from `@erp/core-api` | log §4 stage 4 |
| 5–6 | **The whole Estimating group**: all six lists (Rate dimensions, Application rates, Formulations, Assembly groups, Assemblies, Resource rates) on the list standard; all create/edit forms as **sheets** (decision D7); Material Preview on `ScreenTemplate`; the nine backend features that had no screen (G1–G9: deletes with the server's refusal shown, value rename, archive, draft assembly-version edit, formulation resolve tester, overhead-policy draft edit/delete); one additive backend filter (`GET /application-rates?filter[search]=`) | [estimating-screens.md](../domain/estimating-screens.md), log §4 stage 6 |

Shared pieces added along the way (all additive, `DataTable` untouched): `list-controls.tsx`, `RecordPresenter`, `ConfirmActionDialog`, `RecordUnavailable`, `RecipeStatusBadge`,
`AccountCombobox`, `CustomerCombobox`, `UomCombobox`, `AssemblyGroupCombobox`, `FormulationCombobox`, `ApplicationRateCombobox`, `useAccountsById`. They are listed in
[ui-building-blocks.md](../architecture/ui-building-blocks.md).

Specs written: `customers-rules.unit`, `customers-screen`, `stock-operations-standard`, `exchange-rate-rules.unit`, `reference-data-screens`, `wired-features`,
`estimating-lists-standard` (all six lists + Resource rates), `estimating-records` (19 tests). Three existing specs changed only where the markup changed
(`negative-stock-overrides`, `recalculation-requests`, `package-count`); no assertion was weakened, skipped or deleted.

## 3. What remains, in the order to do it

> Items 1-4 below were done afterwards (log section 9), except the Company settings screen (another agent's) and bulk actions on Members/Roles (none is safe). Only item 5 and the blocked table remain.

Each item says what is wrong today, where to start, and what "done" means (the delivery contract: [screen-delivery-contract.md](../architecture/screen-delivery-contract.md)).

1. **Settings screens onto `ScreenTemplate` (kind `form`).** Today none of these uses it (checked with `grep`; Appearance is the only Settings screen that does): the Settings index, Company, Purchasing, Inventory,
   Estimating, Material selection, Material variance, Numbers and rounding (two files: `settings/numbers-and-rounding-screen.tsx` and `numbering/numbering-settings-screen.tsx`),
   Printing, Account, Custom fields, Organisation and Approvals settings. Files:
   `features/settings/*`, `features/company`, `features/material-selection`, `features/material-variance`, `features/numbering`, `features/custom-fields`,
   `features/organisation`, `features/approvals/approvals-settings-screen.tsx`. Mechanical: wrap in `ScreenTemplate`, keep each screen's own behaviour and specs
   (`material-selection-settings`, `material-variance-settings` and the settings specs match labels — search the specs before changing words). Done = template present, 44 px
   targets on a phone, axe clean, existing specs green.
2. **Members and Roles *lists*** (`features/access-administration/members-settings-screen.tsx`, `roles-settings-screen.tsx`) lack applied-filter chips and selection. Only add a
   bulk action if a safe one exists (do not infer bulk safety from a single-record endpoint); otherwise chips + `ScreenTemplate` only.
3. **Stock operations status chips** (`physical-counts/physical-count-columns.tsx`, `inventory-cutovers/inventory-cutover-columns.tsx`,
   `negative-stock-overrides/negative-stock-override-columns.tsx`, `recalculation-requests/recalculation-request-columns.tsx`) still use `Badge` variants; move them to
   `StatusBadge` tones (pattern: `features/estimating/estimating-status.tsx`, `features/customers/customers-columns.tsx`). Check the specs that read the status text.
4. **Supplier address edit/remove on the supplier *edit form*.** Today it exists on the supplier record only (`features/suppliers/supplier-detail-screen.tsx`), not on the
   address list inside the edit form.
5. **Review the Estimating work visually** — the one thing nobody has looked at: dark mode, the Ocean/Amber accents, 320 px and tablet widths, and the wide Formulation /
   Assembly sheets by keyboard (axe passes; nobody has tabbed through them).

### Blocked — not to be started until the cause changes

| Item | Cause | Unblocked by |
|---|---|---|
| Quotation approvals, owner/transfer/share, delivery correction, branding, customer acceptance | other agents own `features/quotations` and the backend is incomplete | their completion; this branch must not edit that folder |
| Jobs and finance (invoices, receipts, journals, periods) | no backend routes (Phase 5/6) | the backend |
| Customer addresses | no `customer-sites` / `party-sites` endpoint | the endpoint |
| Party contact removal | backend omits it until an archive rule exists | an ADR-owned rule |
| Forgot-password link | ADR 0065 chose manual, administrator-issued recovery | a product decision |
| Archiving a trading partner that has roles | the API wants both role accounts *deleted*; the screens can only archive a role by status, so such a partner cannot be archived from the UI (the screen says so up front) | an ADR for the intended path |
| Bringing an archived formulation or assembly back | no un-archive endpoint (the dialogs say "create a new one") | a product decision, then an endpoint |

## 4. Owner decisions still open

| # | Question | State |
|---|---|---|
| D3 | Exchange rates and Chart of accounts live under **Settings**, not a new sidebar group (recommended: no new navigation structure across modules) | implemented as recommended; moving them is one entry in `layouts/app-shell.tsx` and `settings-catalogue.ts` |
| D5 | Those two screens have no separate read-only detail: the record sheet is the detail and the edit form (five fields) | taken as recommended; the delivery contract wants the owner's agreement to depart from list + detail |

Resolved: **D7** (Estimating forms are sheets — the owner's answer). Taken by the author, recorded in the log: D1 (Customers in Sales), D2 (no screen for AVCO pools/layers), D4, D6,
D8 (Estimating bulk is print-only), D9 (application rates searched by item).

## 5. Picking it up

**Check out and orient**

```bash
git fetch origin cloud/screens-complete develop
git switch cloud/screens-complete
git log --oneline origin/develop..HEAD      # the 16+ commits
git merge-tree --write-tree HEAD origin/develop   # clean merge? (prints a tree id when there are no conflicts)
```

**Before any push, run what [pre-push-checks.md](../architecture/pre-push-checks.md) lists for what you changed.** For this branch's usual changes:

- Frontend: `npx tsc --noEmit` in `frontend/packages/core-api`, `frontend/packages/core-ui` and `frontend/apps/main`; `npm run build --workspace apps/main` from `frontend/`.
- Browser specs (they run against an already-running stack: Laravel on 8090, `vite preview` on 5176 serving `apps/main/dist`, so **rebuild `dist` after every frontend change**):
  from `frontend/apps/e2e`, `npx playwright test --project=chromium tests/<spec>`. Run the specs for every screen that shares a component you changed.
- Backend: Pint on what you touched, and the suites for it. Use your **own** private test database (the test harness refuses the shared `erp_testing`); never run migrations, seeders
  or tests against the owner's shared dev database.
- Any Resource / Form Request / route change: regenerate the OpenAPI spec and the client (`php artisan scramble:export --path=storage/api-docs/openapi.json`, then `npm run codegen`
  in `frontend/`), commit only the files that moved, and make sure only the intended generated files changed.

**Gotchas that cost time**

- A push **cancels the run in progress** (CI concurrency). Finish a stage, push once, wait for green on that exact head, then start the next.
- `getByRole('table').locator('tbody tr').first()` matches the **loading skeleton** row before data arrives. Wait for `toHaveAttribute('tabindex', '0')` (a real row) before clicking.
- Each list also carries a hidden Quick Print table with the same rows; scope row locators to `getByRole('table')` (the visible one) or to `.ui-order-link`.
- `?include=` is a **whitelist** per endpoint; an include the controller does not list returns 422 and a record sheet shows "Couldn't load". Check the controller's `INCLUDABLE`.
- `DetailSection` lays its fields out with the **viewport** breakpoint (`sm:`), `DetailPageLayout` with the **container** (`@2xl:`); inside a sheet use `@3xl:`-style container classes for wide multi-column rows.
- A filter that is "Not tagged" but holds no form value is an error for a zod `z.record(z.string(), z.string())`; make such entries optional.
- Records an e2e spec cannot delete (assemblies, formulations, items) are archived by the spec or by `global-teardown.ts`; keep that hygiene when adding specs, and do `finally` cleanup through the API.
- Commit messages end with the two trailer lines the session gives (`Co-Authored-By` and `Claude-Session`); no model name goes anywhere else in the repository.

## 6. Handing it over

1. Push the branch (`git push -u origin cloud/screens-complete`) — already done up to `71a39818`; only push after the checks above, once per finished stage, and wait for CI.
2. Update the log (`screens-complete-log.md`: the stage, decisions, "Not verified", "Remaining work") and this file in the same push, so the docs never describe a state the code is not in.
3. Post the stage report as a comment on issue #85 (use `gh api repos/kaleemnadeem16/erp/issues/85/comments`; `gh issue comment` needs GraphQL, which is blocked in cloud sessions).
4. **Do not merge, and do not open a pull request unless asked.** When the owner asks for one: base `develop`, mirror the headings of `.github/pull_request_template.md`, link the log and issue #85, state
   the open decisions (D3, D5) and the "Not verified" list as they stand, and end the description with the attribution lines the session provides. The owner and the integrator review and merge.
