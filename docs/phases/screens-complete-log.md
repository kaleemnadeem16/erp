[← Phases](README.md)

# Screens completion log (`cloud/screens-complete`)

Working log for the branch `cloud/screens-complete` (cut from `origin/develop` at `f3e7d0d2`). It records the rules applied, the audit,
what was built or brought to the standard, what was blocked and why, and the decisions taken. It is **not** a status page
([STATUS.md](../STATUS.md) is not touched by this branch). The owner and the integrator review and merge; nothing here is merged by the author.

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
| **Estimating masters** | Rate dimensions, Application rates, Formulations, Assembly groups, Assemblies, Resource rates (lists + create/edit pages) | same list gaps; create/edit are pages (S6) |
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
| Member "issue temporary password" | complete (ADR 0065) | Step 4 if the contract is unambiguous |
| Forgot-password (`POST /forgot-password`) | no mail channel specified; ADR 0065 chose manual credential recovery | listed, not built (ambiguous) |
| AVCO pools / stock layers | read-only reference data | deliberately no screen (D2) |
| Estimating `destroy` endpoints, Item/Location hard delete | guards differ / documented as not exposed one-click | listed, not built |
| Quotation approvals, owner/transfer/share, delivery correction, branding, customer acceptance, jobs, finance | backend incomplete or owned by other agents | **blocked — see section 5** |

## 3. Plan and order of work

1. Shared additive list pieces (`ActiveFilterChips`, `BulkActionBar`, `ListFiltersSheet`) used by every new/fixed list — reuse, no fork of `DataTable`.
2. Customers + Trading partners (+ contacts) with the smallest additive backend parity change (customer-account index filters/sort/search match the supplier index).
3. Chart of accounts and Exchange rates.
4. Stock-operations group to the standard, then Estimating masters.
5. Unexposed features (location roles, supplier-site edit/remove).
6. Demo seeders for every new screen, docs, screenshots, report.

## 4. Done so far

_(updated as each stage lands)_

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
| D3 | Chart of accounts and Exchange rates are reached from **Settings → Company** (existing top-bar menu + settings index) rather than a new sidebar group, so no navigation structure that affects several modules is changed | **awaiting owner** — recommended default implemented; moving them is a one-array edit |

## Related

- [Phases index](README.md) · [Screen design standard](../architecture/erp-blue-design-standard.md) · [Screen delivery contract](../architecture/screen-delivery-contract.md)
