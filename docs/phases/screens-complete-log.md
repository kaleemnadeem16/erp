[← Phases](README.md)

# Screens completion log (`cloud/screens-complete`)

Working log for the branch `cloud/screens-complete` (cut from `origin/develop` at `f3e7d0d2`). It records the rules applied, the audit,
what was built or brought to the standard, what was blocked and why, and the decisions taken. It is **not** a status page
([STATUS.md](../STATUS.md) is not touched by this branch). The owner and the integrator review and merge; nothing here is merged by the author.
**To pick the work up, start at the [handoff](screens-complete-handoff.md)** (where the branch stands, what is done, what remains in order, how to check and push).

## 1. Rules applied (Step 0 — read before coding)

Sources read: [ERP Blue standard](../architecture/erp-blue-design-standard.md), [UI building blocks](../architecture/ui-building-blocks.md),
[screen delivery contract](../architecture/screen-delivery-contract.md), [user-interface](../architecture/user-interface.md),
[authorization](../architecture/authorization.md) build rules, [pre-push checks](../architecture/pre-push-checks.md),
[parties domain](../domain/parties.md), [stock domain](../domain/stock.md), the shared components in
`frontend/packages/core-ui/src/components/*`, and the reference screens delivered by issue #67 (merge commits `44492064`, `4c8986e2`: the Stock ledger list and
movement sheet) plus Categories / Suppliers as master-data references.

What "standard" means (the checklist every screen here is judged against):

| # | Rule | Source |
|---|---|---|
| S1 | List/detail/form pages sit in `ScreenTemplate kind=list/detail/form` with `PageHeader`; one prominent create action (`primaryAction`, becomes the FAB below `md`) | standard §Template recipes |
| S2 | Search + filter + sort + page live in the URL (`useListQueryState` / `useCursorQueryState`); closing a child record restores the exact list context | standard, `list-query-state` |
| S3 | Every filter/sort the screen's own endpoint validates is reachable (frequent beside search, rare in the animated advanced panel, mobile in the Filters sheet); server-searchable comboboxes for growing references (never "first 100 rows") | contract, user-interface |
| S4 | Active filter chips with a named remove control + **Clear all**; distinct loading / empty / filtered-empty / **error-with-retry** states (never "0 records" for a failed request) | standard, ADR 0061/0064 |
| S5 | Desktop table with **selection checkboxes** and a bulk-action bar (count, selected styling, clear on page/sort/filter change) **where a safe bulk action exists**; phone gets cards that share the selection | contract §Row actions and selection |
| S6 | Record detail/create/edit open as a right-hand **`RecordSheet` over the list** by default (page alternative kept behind `presentation.ts`); the list stays mounted and keeps its query string | contract §Record presentation |
| S7 | Touch targets ≥ 44 px for frequent phone actions (24 px floor), 40 px control baseline, no horizontal overflow at 390 px, dialogs/sheets scroll internally | standard §Responsive |
| S8 | Permission-aware: read `usePermission`, hide what a person can never use, **disable with a reason** an unmet precondition; the server stays authoritative (R1–R14); protected fields never reach the browser | authorization, standard |
| S9 | Only the generated client/hooks from `@erp/core-api` — regenerate through `docker/backend/codegen.sh` / `npm run codegen`, never hand-edit generated files; no new UI library; semantic `--ui-*` tokens only | AGENTS.md, ADR 0006 |
| S10 | Retryable writes carry an idempotency key (`Idempotency-Key`) and stale-state writes carry the expected generation/version where the API requires it; server `reason_code` → one hint map, never parse messages | api-conventions, standard §Validation |
| S11 | Accessible: real labels, `aria-*` on icon buttons, focus moves into a sheet/dialog and returns, errors announced (`role=alert`), axe passes | standard §Accessibility |
| S12 | Tests: pure-function/markup `*.unit.spec.ts` + Playwright browser specs for the critical journey, permissions, 390 px and keyboard; deterministic fixtures, never "first row" | testing-standards |
| S13 | Demo data ships with every feature: a create-only, idempotent demo seeder that goes through the real Actions, takes the company as a parameter and never overwrites settings (`demo:seed` must pass) | environments.md §Development demo data |

Hard limits observed on this branch: no push/merge to `develop`; no force-push/rebase/history rewrite; no migration or seeder is run against the owner's shared
`erp` database or the running stack (a private throw-away PostgreSQL is used for checks); `QUOTATION_UI_ENABLED` stays `false`; no permission activation, grants
or STAGED duty changes; no secrets; `STATUS.md`, `STATUS-LOG.md`, `phases/README.md` untouched; no test/ratchet weakened, skipped or deleted.

## 2. Audit (Step 1)

Method: every file under `backend/app/Modules/*/routes.php` and `backend/routes/api/*.php` was read; the generated client (`core-api`) was diffed against
`core-ui` + `apps/main` to find hooks no screen imports; every `*screen*.tsx` was matched against the checklist with a grep matrix.

### 2a. Backend route groups → the screen that exposes them

| Route group | Screen(s) | State |
|---|---|---|
| `auth/*`, `account*`, `devices` | Login, Accept invitation, Change password, Settings → Account (+ signed-in devices) | exposed (forgot-password route has no entry point — see 2c) |
| `parties`, `party-contacts`, `customer-accounts` | **none** (only pickers: Sales `RegisterPartyDialog`, `ContactPicker`; quotation pickers) | **gap → Customers + Trading partners built (Step 2)** |
| `supplier-accounts`, `supplier-sites` | Purchasing → Suppliers (list/sheet/form); site create only | exposed; site edit/remove not (2c) |
| `categories`, `items`, `item-quantity-units`, `item-packagings`, `items/*/package-tracking`, `package-*` | Items, Categories, Pack conversions, item sheets | exposed |
| `warehouses`, `locations` | Warehouses list/sheet | exposed |
| `warehouse-location-roles` | **none** (authorization-retrofit.md: "No screen edits location roles yet") | gap → Step 4 |
| `stock-moves` | Stock ledger (reference screen, #67) | exposed |
| `stock-layers`, `avco-pools` | none — **deliberately backend-only** (`docs/domain/stock.md`: reference data, not a document workflow) | no screen (decision D2) |
| `inventory-overview` | Inventory overview | exposed |
| `physical-counts`, `inventory-cutovers`, `negative-stock-override-requests`, `recalculation-requests` | Stock operations screens (lists + create + detail pages) | exposed but **not on the standard (3b)** |
| `stock-scraps`, `supplier-returns`, `rejected-stock` | Rejected-exit screens (Scrap, Supplier returns) | exposed, on standard |
| `stock-issues`, `stock-transfers` | no standalone screen: used by job/issue flows not built yet; package-aware dialogs reuse them | not a screen gap (no owning workflow screen specified) |
| `inventory-settings`, `inventory-policy-changes`, `purchasing-settings`, `purchasing-policy-changes` | Settings → Inventory / Purchasing | exposed |
| `supplier-offers`, `item-suppliers` | Item suppliers list/sheet, item sheet | exposed |
| `purchase-orders*`, `goods-receipts*`, `quality-dispositions` | PO / GR screens (reference) | exposed |
| `leads`, `opportunities`, `sales-stages`, `sales-owners` | Enquiries, Opportunities, Pipeline stages | exposed, on standard |
| `quotations*`, `quotation documents/deliveries` | Quotation screens (**another agent's area; untouched**) | excluded |
| `assemblies*`, `assembly-groups`, `formulations*`, `rate-dimensions*`, `application-rates`, `resource-rates`, `material-preview` | Estimating screens | exposed but **not on the standard (3b)** |
| `overhead-policies`, `material-selection/stock-mix policies`, `material-variance-*` | Settings → Estimating / Material selection / Material variance | exposed |
| `approval-*` | Approvals inbox, Settings → Approvals | exposed |
| `members`, `roles`, `permissions`, `organisation-units`, `custom-field-definitions` | Settings → Members / Roles / Organisation / Custom fields | exposed (temporary-password action not — 2c) |
| `company`, `company-changes`, `numbering/*` | Settings → Company, Document numbering | exposed |
| `chart-of-accounts` | pickers only | **gap → Chart of accounts built (Step 2)** |
| `currencies`, `exchange-rates` | currency pickers only | **gap → Exchange rates built (Step 2)** |
| `uoms` | pickers only (read-only reference, no write endpoint) | not a gap |

### 2b. Screens against the standard checklist

`ScreenTemplate` (S1) is the clearest discriminator; the grep matrix over all 111 `*screen*.tsx` files found these groups **not** on the standard:

| Group | Screens | What is missing |
|---|---|---|
| **Stock operations** | Physical counts, Inventory cutovers, Negative-stock overrides, Stock recalculation (each: list + create page + detail page) | no `ScreenTemplate` (S1); no active-filter chips / Clear all (S4); no mobile Filters sheet (S3); no selection + bulk bar (S5, see per-screen note); create/detail are full pages not sheets (S6); `h-9`-sized controls (S7) |
| **Estimating masters** | Rate dimensions, Application rates, Formulations, Assembly groups, Assemblies, Resource rates (lists + create/edit pages), Material Preview | same list gaps; create/edit are pages (S6). **All six lists and all create/edit forms are now on the standard, the forms as sheets (stages 5 and 6); Material Preview is on `ScreenTemplate`** |
| **Settings** | Company, Purchasing, Inventory, Estimating, Material selection, Material variance, Numbers, Printing, Account, Custom fields, Organisation, Approvals settings, Members, Roles | no `ScreenTemplate` (settings are forms, not lists: S1 only) |
| Auth | Login, Accept invitation, Change password | out of scope (not workspace screens) |
| On the standard (reference or adopted) | Purchase orders, Goods receipts, Items, Item suppliers, Suppliers, Categories, Warehouses, Pack conversions, Inventory overview, Stock ledger, Scrap/Supplier returns, Leads, Opportunities, Pipeline stages, Approvals inbox | — |

Per-screen detail for the fixed groups is recorded in section 4 when each group is done.

### 2c. Backend features with no UI (verified from the generated hooks no screen imports)

| Feature | Backend contract | Decision |
|---|---|---|
| Trading partners (identity) list/edit/archive/restore | complete (`parties.party.manage`; ADR 0105) | **built** — Trading partners |
| Party contacts create/edit | complete (`parties.party.manage`); no remove endpoint | **built** (add/edit; removal listed as blocked: ADR-owned rule missing) |
| Customer accounts create/edit/archive | complete (ADR 0084 field-level permissions) | **built** — Customers |
| Customer sites / party sites | **no endpoint** (parties.md: "no equivalent `customer-sites` endpoint yet") | **blocked** — backend incomplete |
| Exchange rates | complete (`currency.exchange-rate.manage`, ADR 0031 manual entry) | **built** — Exchange rates |
| Chart of accounts | complete (`accounting.chart-of-account.manage`) | **built** — Chart of accounts |
| Warehouse-location roles | complete (`inventory.location.manage`) | Step 4 (structured-mode panel in the warehouse sheet) |
| Supplier site edit/remove | complete (`parties.supplier-site.manage`) | Step 4 |
| Member "issue temporary password" | complete (ADR 0065) | **already present** on the Members screen (called through the plain generated function, so the hook-name analysis missed it) |
| Forgot-password (`POST /forgot-password`) | no mail channel specified; ADR 0065 chose manual credential recovery | listed, not built (ambiguous) |
| AVCO pools / stock layers | read-only reference data | deliberately no screen (D2) |
| Item/Location hard delete | documented as deliberately not exposed one-click | listed, not built |
| Estimating (full audit of `Modules/Estimating` routes, requests, actions, guards — stage 6) | see the table at the head of stage 6 | **built** (G1–G9) |
| Quotation approvals, owner/transfer/share, delivery correction, branding, customer acceptance, jobs, finance | backend incomplete or owned by other agents | **blocked — see section 5** |

## 3. Plan and order of work

1. Shared additive list pieces (`ActiveFilterChips`, `BulkActionBar`, `ListFiltersSheet`) used by every new/fixed list — reuse, no fork of `DataTable`.
2. Customers + Trading partners (+ contacts) with the smallest additive backend parity change (customer-account index filters/sort/search match the supplier index).
3. Chart of accounts and Exchange rates.
4. Stock-operations group to the standard, then Estimating masters.
5. Unexposed features (location roles, supplier-site edit/remove).
6. Demo seeders for every new screen, docs, screenshots, report.

## 4. Done so far

### Stage 1 — Customers and Trading partners (Step 2, first slice)

- **Built:** Customers (`/customers`) and Trading partners (`/parties`) with contacts, as full vertical slices: list (search, filters, chips, selection + bulk bar, phone
  cards, error/empty/loading), create/edit/detail as sheets, contacts add/edit, archive/activate, duplicate check on registration, "Make a customer" shortcut.
  Specification and coverage matrix: [parties-screens.md](../domain/parties-screens.md).
- **Backend (smallest additive change):** `GET /customer-accounts` gained the supplier index's search (partner name, tax id, registration), currency and
  receivable-account filters (the latter only for a reader of the mapping, ADR 0084) and the extra sorts; Pest tests + regenerated client.
- **Shared:** `list-controls.tsx` (search, chips, bulk bar, phone Filters sheet, advanced panel), `AccountCombobox`, `useAccountsById`.
- **Demo data:** `PartnersAndContactsDemoSeeder` (real Actions, create-only, idempotent); tested on a fresh schema with a rerun.
- **Evidence:** `docs/ui-evidence/customers/` (1280 px and 390 px of the list, the record sheets, the forms and the Filters sheet; no horizontal overflow).
- **Verified locally:** `tsc` in core-api, core-ui and apps/main; `vite build`; 137 unit specs; the new 25-test browser spec (journey, filters, permissions via an
  intercepted `/user`, error + retry, phone width with 44 px targets, axe, keyboard); 37 neighbouring browser specs; Pint on every PHP file touched; the
  Parties, architecture/ratchet and seeder Pest suites; codegen reproduced with no drift.
- **Not verified locally:** PHPStan/Larastan (the package is dist-only and this container cannot reach its download), the whole backend suite and the whole
  Playwright suite (run by CI), real-browser screen-reader behaviour.

### Stage 2 — Stock operations group to the standard (Step 3, first group)

Screens: Physical counts, Inventory cutovers, Negative-stock overrides, Stock recalculation (4 lists, 4 create forms, 4 record screens).

| Standard rule | Before | After |
|---|---|---|
| S1 template | bare `<div className="space-y-4 p-4 pb-24 md:p-6">` | `ScreenTemplate kind="list"` + `PageHeader` (the template owns spacing and the phone's bottom clearance) |
| S2 URL state | kept (already `useListQueryState`); record navigation dropped the query string | search/filter/sort/page stay in the URL and **every open/close/create carries it** |
| S3 filters | enum codes as option text, `h-9` controls, hand-made search without a clear control | shared `ListSearchInput` / `FilterSelect` (words, not codes), 44 px on a phone, named clear control, one Filters sheet with the active count |
| S4 chips / states | none; "0 records" style empty text | active-filter chips with named removers + Clear all; empty vs filtered-empty vs error-with-retry |
| S5 selection | none | checkboxes (table and phone cards) + a bulk bar with count; **only *Print selected*** — a decision, post, approval or recalculation is a per-record judgement, so no common bulk transition is offered (recorded in each list's doc comment) |
| S6 records | full pages at `/x/new` and `/x/:id` | right-hand sheets over the list through `RecordPresenter`; the page alternative is kept in `presentation.ts`; same URLs |
| S8 permissions | override "New request" always shown | gated by the two duties the API accepts (`negative-stock.request` or `.override`) |
| Operator language | `MATERIAL_CONSUMPTION`, `PRIOR_APPROVAL` | "Material consumption", "Prior approval" in filters, chips, cells, cards and print |

Behaviour unchanged: every query, include, mutation, permission gate on the detail actions and every refetch is as before.
Tests: `stock-operations-standard.spec.ts` (33 browser tests: template, filter → request + chip, selection and bulk bar, record sheet keeps the list
context, create sheet is not dismissed by an outside click, error + retry, axe, create hidden without the duty, 390 px with 44 px targets).
Existing tests changed only where the standard deliberately changes the markup, each with its reason: `negative-stock-overrides.spec.ts` now reads the on-screen
table (`getByRole('table')`) because the list carries a hidden Quick Print table with the same rows; `package-count.spec.ts` looks for the posted status inside
the sheet (`getByRole('dialog')`) because the list behind a sheet also shows statuses. Neither assertion was weakened (same counts and same texts).
Evidence: `docs/ui-evidence/stock-operations/`.
Remaining on these screens: status chips still use `Badge` variants, not `StatusBadge` tones (a one-line mapping per screen, not done here).

### Stage 3 — Exchange rates and Chart of accounts (Step 2)

- **Built** as full vertical slices under Settings: Exchange rates (list with currency/type/date filters, create, rate-only edit, delete with its consequence stated) and
  Chart of accounts (list with search/type/status/level/parent filters, create/edit, archive/activate singly and in bulk). Specification and coverage matrices:
  [reference-data-screens.md](../domain/reference-data-screens.md).
- **A gap found by the audit and closed:** the generated `exchange-rate` module was never re-exported from `@erp/core-api`'s hand-maintained `src/index.ts`, so no
  screen could import its hooks; it is exported now (the generated files themselves are unchanged).
- **Demo data:** none added — `CurrencyRatesDemoSeeder` (rates, through the guarded Action) and `AccountingDemoSeeder` (the chart) already seed both.
- **Tests:** 8 pure unit specs; 22 browser tests (list standard, filter → request + chip, create/edit/delete with problems beside fields, duplicate refused, bulk
  archive with an honest result, access, error + retry, Settings index, axe on four pages, 390 px with 44 px targets).
- **Also fixed here:** `recalculation-requests.spec.ts` read rows from every `tbody` and so matched the hidden Quick Print table once the list gained Print (same
  scoping fix as the override spec; the hosted run caught it).

### Stage 4 — backend features wired into the UI (Step 4)

- **Supplier addresses: edit and remove** on the supplier record (`parties.supplier-site.manage`): the shared `SupplierSiteDialog` gained an edit mode (create
  behaviour unchanged; the purchase-order form still uses it as before); removal states that only the supplier role join goes and the shared address is kept.
- **Warehouse location roles** panel in the warehouse sheet, STRUCTURED mode only (ADR 0041): read for anyone who can open the warehouse; assign / change / clear
  for `inventory.location.manage`, with the picker limited to the warehouse's own locations.
- **Barrel gap closed:** `warehouse-location-assignment` (like `exchange-rate`) was generated but never exported from `@erp/core-api`'s hand-maintained `src/index.ts`.
  The only remaining unexported module is `avco-pool`, deliberately (no screen, decision D2).
- **Tests:** `wired-features.spec.ts` (edit with the stored values, remove with its stated consequence, no controls without the duty, 44 px phone targets, roles
  shown / changed / cleared with the demo assignment restored, no role controls without the duty, nothing role-related in SIMPLE mode). Known property: the API
  has no endpoint that deletes a shared `party_sites` row, so the stamped sites these specs create leave unlisted orphan rows (invisible in every screen).
- **Audit correction:** the hook-name analysis of step 1c listed *member issue temporary password* as unused, but the Members screen already offers it (create-without-mail
  and *Issue temporary password* with a one-time reveal) through the plain generated function `membersIssueTemporaryPassword`; it is present and needs nothing. The same
  check found the quotation hooks used the same way (not touched: other agents' area).
- **Not wired, with reasons:** forgot-password link (no product decision: ADR 0065 chose manual, administrator-issued recovery; section 5), estimating `destroy`
  endpoints (their referential guards differ per resource and none is documented as safe to expose one-click), item/location hard delete (documented as deliberately
  not exposed), AVCO pools / stock layers (reference data, deliberately backend-only, decision D2).

### Stage 5 — Estimating masters, first batch (Step 3)

Done: the **Rate dimensions** and **Assembly groups** lists (search is the only filter their endpoints validate): `ScreenTemplate`, shared search with a clear control,
chip + Clear all, selection with Print selected, phone cards and Filters sheet, error-with-retry vs empty, and the create action no longer shown as a bare page button
on a phone. Their rows still open the existing edit pages. Spec: `estimating-lists-standard.spec.ts` (11 tests); `estimating-list-filters.spec.ts` (Formulations /
Assemblies) re-run unchanged and green. Evidence: `docs/ui-evidence/estimating/`.

*Superseded by stage 6: the remaining four lists and all six masters' forms were done there once the owner answered D7 (sheets).*

### Stage 6 — Estimating group completed (backend audit, four lists, sheets, missing features)

The owner asked for the whole Estimating group to follow the standard, for D7 to be answered "sheets", and for the backend to be checked in full first. Spec and
coverage matrix: [estimating-screens.md](../domain/estimating-screens.md).

**Audit of `Modules/Estimating` (routes, form requests, actions, resources, migrations, permissions) against the screens.** Backend contracts that had no screen or
an incomplete one, and what was done:

| # | Gap found | Done |
|---|---|---|
| G1 | Rate dimension `DELETE` (hard delete; the FK from its values restricts) | *Delete dimension* with a confirmation; the server's refusal is shown |
| G2 | Dimension value `PATCH` (name, code, sort order) | inline *Rename* on the value row |
| G3 | Application rate `DELETE` (hard delete; an assembly component's FK restricts it) | *Delete rate*; refusal shown and tested against a real component |
| G4 | Assembly group `DELETE` (refused while an assembly belongs to it) | *Delete group*; refusal shown (tested on the demo group) |
| G5 | Formulation `DELETE` = archive (no un-archive endpoint) | *Archive* behind a confirmation that says so |
| G6 | Assembly `DELETE` = archive (same) | same |
| G7 | Assembly version `PATCH` (notes + components of a DRAFT) | *Edit draft* opens the editor on the stored values; new versions also carry notes |
| G8 | `GET /formulations/resolve` | *Test resolution* above the Formulations list |
| G9 | Overhead policy `PATCH` and `DELETE` of a DRAFT | *Edit* and *Delete* on each draft (company, group and assembly panels); activation asks first |
| — | Application rates had no search; the assembly-component pickers (formulation, application rate) silently stopped at the first 100 records | **backend, smallest additive:** `GET /application-rates?filter[search]=` (item code or name; Pest test; client regenerated by `codegen.sh`/orval, `codegen-check` stays green) → a search box on the list and server-searched pickers |

Also fixed on the way: the Application rate form could not be saved unless every dimension picker had been touched (an untouched picker showed "Not tagged" but held
no value, which the schema rejected — found by the new spec, existed before); its item picker was editable although the item cannot change; a resource rate was deleted
from the list with no confirmation; `screen-access` let a reader (`estimating.recipe.view`) open `/formulations/new` and `/assemblies/new` (the API refused, the screen
did not) — now they need the maintaining duty, and `/resource-rates/new` needs `estimating.resource-rate.manage`.

**Lists (Step 3):** Formulations, Application rates, Assemblies and Resource rates on the shared list furniture, with every label the existing specs rely on kept
(`Search formulations`, `Output item`, `Customer`, `Status`, `Assembly group`, `Default status`, `Item`, `Work unit`). Bulk is print only (see the doc: deletes are
refused while in use, archives have no way back, activation supersedes the live version).

**Forms as sheets (D7 resolved):** all six masters' create/edit forms open over their lists via `RecordPresenter` (`presentation.*`, page alternative kept, same URLs).
Resource rates, which have no update, get a read-only record sheet with *Delete*. New shared pieces: `ConfirmActionDialog`, `RecordUnavailable`,
`CustomerCombobox`, `UomCombobox`, `AssemblyGroupCombobox`, `FormulationCombobox`, `ApplicationRateCombobox`. Material Preview adopts `ScreenTemplate` (behaviour unchanged).

**Specs:** `estimating-lists-standard.spec.ts` now covers all six lists (template, chip, selection, retry, axe, access, phone); new `estimating-records.spec.ts` (sheets, every
G1–G9 feature and its refusal, phone width, Material Preview a11y). The existing `estimating-list-filters`, `application-rate-resolution-lookup`, `cost-evidence`,
`recipe-access`, `material-preview`, `material-*-settings` and `lookup-load-error` specs pass unchanged. The first run of the new spec caught an include the assembly
screen is not allowed to ask for (the whole sheet showed "Couldn't load this assembly") and the dimension-picker bug above.

## 5. Blocked (not built) and why

| Item | Reason |
|---|---|
| Quotation approvals, quotation owner/transfer/share, delivery correction, branding, customer acceptance | Backend incomplete / being built by other agents; `features/quotations` is not edited except the navigation entry (not needed: the Quotations entry already exists) |
| Jobs, finance (invoices, receipts, journals, periods) | No backend routes yet (Phase 5 / 6) |
| Customer addresses (sites) | No `customer-sites` / `party-sites` endpoint |
| Party contact removal | Backend deliberately omits it until an archive rule exists (parties.md §Contacts) |
| Forgot-password link | ADR 0065 chose manual, administrator-issued credential recovery; no product decision for self-service reset mail |

## 6. Decisions

| # | Decision | Status |
|---|---|---|
| D1 | Customers sit in the **Sales** nav group (mirror of Suppliers in Purchasing); gate = the Parties capabilities already used by the API | taken (docs silent → mirror the existing pattern) |
| D2 | AVCO pools and stock layers get no screen: `stock.md` records them as deliberately backend-only reference data | taken (existing accepted doc) |
| D3 | Chart of accounts and Exchange rates are reached from **Settings** (Company / Numbers, money and rounding: the existing top-bar menu, mobile More sheet and settings index) rather than a new sidebar group, so no navigation structure that affects several modules is changed | **awaiting owner** — recommended default implemented; moving them is a one-array edit |
| D4 | Trading partners sit beside Customers in the Sales group (the identity is shared with Suppliers) | taken; one entry in `navGroups` |
| D5 | Exchange rates and Chart of accounts have no separate read-only detail screen: the record sheet shows every fact and is the edit form for a person who may change it (a rate has five fields) | taken as recommended (owner asked what other ERPs do and did not object to keeping the combined sheet); owner may still reverse |
| D6 | The shared demo creates no customer account (the Sales demo guards that a prospect has none); archived / no-limit / dual-role customers are made by the specs through the API | taken; changing that guard is the owner's call |
| D7 | Estimating create/edit forms (formulation and assembly versions especially) are version-managing workspaces; pages (the contract's workspace exception) or sheets | **resolved by the owner: sheets**. Formulations and Assemblies use the wide sheet; `presentation.ts` can still switch any one back to a page |
| D8 | Estimating bulk actions are print-only; archive/delete/activate stay on the record, where the consequence is stated and the server's refusal is shown | taken (contract: do not infer bulk safety from a single-record endpoint) |
| D9 | Application rates are searched by their item (code or name) — the one backend filter added in stage 6 | taken (smallest additive change, tested, no authorisation change) |

## 7. Not verified

- **Visual states beyond light mode at the default accent.** Screenshots and the axe checks are default light; dark mode and the Ocean/Amber accents were not looked at for the new
  screens (they use only semantic tokens, so no per-screen colour exists to retune, but nobody has seen them).
- **Widths other than 1280 px and 390 px** (320 px, tablets) and any real touch device; real-browser keyboard/focus beyond Playwright's Chromium (no Firefox/Safari), and
  any screen reader. Focus is asserted (it lands in a sheet, returns on Escape, goes to the first invalid field), not heard.
- **Performance.** No timing, large-dataset or slow-network measurements were made. Pagination is server-side everywhere, pickers are server-searched, and the lookups avoid
  first-page assumptions, but that is design, not measurement.
- **Backend suite and PHPStan locally.** PHPStan/Larastan could not be installed here (its package is dist-only and unreachable), so they ran only in CI; the whole backend suite also
  ran only in CI. Locally: the Parties, architecture/ratchet and demo-seeder suites, Pint on the whole backend, and a fresh-schema `demo:seed`.
- **Estimating sheets: dark mode, Ocean/Amber accents, widths other than 1280/390, keyboard order inside the wide Formulation/Assembly sheets beyond what axe checks** (the same limits as above).
- **The whole Playwright suite locally.** A full local run was started and stopped (it blocks the stack for over an hour); CI runs it in three shards. Locally every spec that touches
  a changed screen or shared component was run.

## 8. Remaining work

1. **Estimating:** done in stage 6. What is left there: the Estimating, Material selection and Material variance *Settings* screens (item 2), and there is no endpoint to
   bring an archived formulation or assembly back (the screens say so; whether one should exist is a product call).
2. **Settings screens** (Company, Purchasing, Inventory, Estimating, Material selection, Material variance, Numbers, Printing, Account, Custom fields, Organisation, Approvals,
   Members, Roles): adopt `ScreenTemplate` (they are forms, not lists); the Members and Roles *lists* also lack chips / selection.
3. **Stock operations status chips** still use `Badge` variants rather than `StatusBadge` tones.
4. **Backend-blocked:** customer addresses (no `customer-sites` / `party-sites` endpoint), contact removal (no archive rule), forgot-password (no product decision).
5. **A product rule is missing for archiving a trading partner that has roles:** the API refuses until both role accounts are *deleted* (soft delete), but the screens only
   archive a role by status, so such a partner can never be archived from the UI. The screen says so up front; an ADR should decide the intended path.
6. **Supplier address edit/remove** is on the supplier record only, not on the supplier edit form's address list.
7. **Owner decisions still open: D3** (Exchange rates / Chart of accounts live under Settings, not a new sidebar group) **and D5** (taken as recommended, may be reversed). D7 is resolved.

## Related

- [Handoff — start here to continue](screens-complete-handoff.md) · [Phases index](README.md) · [Screen design standard](../architecture/erp-blue-design-standard.md) · [Screen delivery contract](../architecture/screen-delivery-contract.md)
