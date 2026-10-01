[← Architecture](README.md)

# Authorisation retrofit backlog

- **Status:** operational-write, Purchasing commercial-field, Inventory valuation and Estimating filter retrofits complete; Item/Category GL-field policy awaits a Finance decision
- **Depends on:** the Material Preview slice's effective-permissions endpoint (`GET
  /api/v1/user`) for every frontend-gating task below — do not build UI gating before it exists.
- **Blocked by:** nothing for registration/field-filtering work; the Purchasing/Purchase-order
  enforcement steps additionally depend on [Q27](../OPEN-QUESTIONS.md#q27--shared-approval-workflow-contract)/[Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)
  for the submit/approve seam, though plain create/issue/cancel capabilities don't need to wait
  for that.

The SPA navigation/direct-route presentation gap is closed by [ADR 0066](../decisions/0066-capability-driven-screen-navigation.md): effective capabilities select visible screens. Purchasing document list/detail reads have dedicated view capabilities and minimum action-read dependencies under [ADR 0068](../decisions/0068-purchasing-document-read-capabilities.md). Purchasing commercial fields, Inventory valuation fields and Estimating CRUD writes have since been protected as recorded below. This does **not** decide GL-account field policy, organisation/record scope, or deliberately open cross-module reference reads (Items, Categories and other pickers). Do not count a hidden route as an API authorisation test.

This is the concrete, per-feature version of
[authorization.md](authorization.md#incremental-retrofit-of-already-built-features)'s "Incremental
retrofit of already-built features" table, for the first three areas audited: Items & Categories,
Purchase Orders/Receipts (+ Item Suppliers, Quality Dispositions), existing Estimating CRUD. Read
`authorization.md` first — this file assumes its RBAC model, registration standard and safe-rollout
rule and doesn't repeat them.

**Read before starting any task below**, and **tick each task in the same commit as the work**,
matching the phase-doc convention this file borrows.

## Scope

### In

- Items, Categories (Inventory)
- Purchase Orders, Goods Receipts, Item Suppliers, Quality Dispositions (Purchasing)
- Rate Dimensions, Application Rates, Formulations, Assembly Groups, Assemblies, Assembly
  Versions, Resource Rates (Estimating) — `OverheadPolicy` already has the target shape and is
  used below as the template, not a retrofit target itself

### Out

- Sales, Jobs, Finance — not yet implemented; authorization.md already says these must start
  permission-complete, not be added to this backlog later
- Record-level/organisational-unit scope (departments, sites, delegated administration) — depends
  on [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope), out of scope
  here
- Multi-stage PO submit/approve workflow itself — [Q27](../OPEN-QUESTIONS.md#q27--shared-approval-workflow-contract)/[Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)'s
  own decision, not this audit's to make; this backlog only covers plain create/issue/cancel/etc.
  capabilities that don't depend on that decision

## How this was produced

Read-only audit, 2026-09-26: every `Http/Requests/*/Index*Request.php`'s validated
`filter.*`/`sort` compared against the matching `*-list-screen.tsx`'s actual controls; every
`authorize()` and Action checked for a `->can()`; every `Http/Resources/*Resource.php` read for
fields with no separate protection; the whole frontend grepped for any client-side permission
check at all. No repository files were changed by the audit itself — this document is the first
commit that follows from it.

## Cross-cutting findings from the 2026-09-26 baseline audit

The findings below describe the initial audit, **not the current implementation**. The task
checkboxes and current-boundary table later in this document track subsequent fixes.

1. **No client-side permission gating exists anywhere in the frontend today** — confirmed by
   `grep -rl ".can(\|hasPermission\|effective_permissions" frontend/packages/core-ui/src/features`
   returning nothing. Every write action renders unconditionally; the only thing between an
   unpermitted click and a request is whichever backend check exists, which — per the tables below
   — is almost nowhere yet.
2. **Sensitive fields with no separate protection today:**
   - `ItemResource`: `income_account_id`, `expense_account_id`, `inventory_account_id`
   - `PurchaseOrderLineResource`: `unit_price`, `base_unit_price`, `line_amount`,
     `base_line_amount`, `discount_amount`, `tax_amount`
   - `GoodsReceiptLineResource`: `provisional_unit_cost`, `base_unit_cost`
   - `ResourceRateResource`: `rate`
   - Same class of concern [material-resolution.md#authorisation-impact](../domain/material-resolution.md#authorisation-impact)
     already named for the preview; applies equally to the raw data today, independent of the
     preview shipping.
3. **Reference/lookup data is a blast-radius trap — do not gate `index`/`show` on it.** Items,
   Categories, Rate Dimensions, Formulations, Resource Rates and UoMs are read via `Combobox`
   pickers from many other screens. Field-level protection is the right tool for finding 2, not an
   `index`/`show` permission wall — restricting read on these would cascade-break every picker
   across the app.
4. **No allowed/denied/scope/field-filtering tests exist for any resource audited here** — nothing
   to test yet. `OverheadPolicy`'s own test suite is the template to copy for every capability
   added below: allowed-with-permission, denied-without, and a separate check for the
   `.manage`/`.policy-history.view`-style split where one applies.

## A. Items & Categories (Inventory)

| Resource | List filters (backend) | UI coverage | Write permission | Read permission | Sensitive fields |
|---|---|---|---|---|---|
| Item | `category_id,search,status,type` | Full (4/4) | `inventory.item.manage` | Shared authenticated lookup | GL account columns — decision open |
| Category | `parent_id,search` | Full (2/2) | `inventory.category.manage` | Shared authenticated lookup | GL account columns — decision open |

**Recommended capabilities:** `inventory.item.manage`, `inventory.category.manage` — write only,
per finding 3. Whether GL-account assignment needs its own finer permission, split from general
item/category editing, is explicitly **not decided here** — flag to whoever owns the
Finance/Accounting relationship rather than deciding unilaterally.

### Tasks

- [x] Register `inventory.item.manage`, `inventory.category.manage`
- [ ] Decide (with Finance/Accounting) whether GL-account fields need their own permission, separate
      from general item/category editing
- [ ] Field-level protection: hide GL account columns from `ItemResource`/`CategoryResource` for
      callers without the relevant permission
- [x] Assignment/backfill pass, then enforce `inventory.item.manage`/`inventory.category.manage` on
      create/update/archive (index/show stay open)
- [x] Frontend action gating (Archive/Activate/Edit) once `/api/v1/user` effective permissions ship
- [x] Allowed/denied capability tests; field-filtering remains with the unresolved GL-account decision
- [x] Update `app/Modules/Inventory/README.md` and [items.md](../domain/items.md)

## B. Purchase Orders, Receipts, Item Suppliers, Quality Dispositions (Purchasing)

| Resource | List filters (backend) | UI coverage | Write permission | Read permission | Sensitive fields |
|---|---|---|---|---|---|
| PurchaseOrder | `search,status,supplier_account_id` | Full (3/3); advanced filters added later | Create/issue/cancel/short-close capabilities | View or minimum action-read capability | Commercial fields projected by `purchasing.commercial-cost.view` (creator draft-read exception) |
| GoodsReceipt | `search,status,supplier_account_id` | Full (3/3) | Create/post/reverse capabilities | View or minimum action-read capability | Cost fields projected by `purchasing.commercial-cost.view` |
| QualityDisposition | `disposition` | No standalone screen — resolved inline in `goods-receipt-detail-screen.tsx` from server-derived balances | `purchasing.quality-disposition.post` | Minimum receipt read | — |
| ItemSupplier | `active,item_id,preferred,search,supplier_account_id` | Full (5/5) | `purchasing.item-supplier.manage` | View or minimum action-read capability | `minimum_order_qty` (mild) |
| PurchasingSetting/PurchasingPolicyChange | — | n/a (settings, not a list) | Already correct — `purchasing.over-receipt-policy.manage` / `.policy-history.view` | — | — |

At audit time, [Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)
identified authenticated-only PO issue; the same gap applied to cancel, short-close and
receipt lifecycle actions. The capabilities above now close those operational-write gaps.

**Recommended capabilities:**

- `purchasing.purchase-order.create`, `purchasing.purchase-order.issue` — **kept separate even if
  one actor holds both today**; Q27/Q28 will need the seam later, and splitting a merged
  permission apart afterwards breaks whoever was already assigned it.
- `purchasing.purchase-order.cancel`, `purchasing.purchase-order.short-close`
- `purchasing.goods-receipt.create`, `purchasing.goods-receipt.post`,
  `purchasing.goods-receipt.reverse` — reversal kept distinct from post, matching how reversal is
  its own risk tier elsewhere in this codebase (recalculation, negative-stock overrides)
- `purchasing.quality-disposition.post`
- `purchasing.item-supplier.manage`
- The earlier recommendation to leave document `index`/`show` authenticated-only has been
  superseded by [ADR 0068](../decisions/0068-purchasing-document-read-capabilities.md): PO,
  receipt and item-supplier reads now have dedicated `*.view` permissions plus minimum
  action-read dependencies. Shared master-data picker reads remain open.

### Tasks

- [x] Register all capabilities above
- [x] Register/enforce Purchasing document view capabilities on list/show and QA disposition
      reads, retaining the minimum action-dependent reads for Buyer/Receiving/QA. Existing
      company roles and direct grants require a pre-deploy review for read-only users →
      [ADR 0068](../decisions/0068-purchasing-document-read-capabilities.md)
- [x] Purchasing commercial-cost field projection for PO/receipt reads (2026-09-29) — new
      `purchasing.commercial-cost.view` (not `estimating.internal_cost.view`: ADR 0057 excludes supplier offers
      from it); PO create carries the minimum read to draft a priced PO. Restricted keys are omitted, monetary
      PO sort is refused without it, and a receipt creator without it submits no monetary field: the server
      derives cost, currency and FX from the issued PO. SPA columns, detail sections and print views follow.
      Demo: `demo-receiver@erp.x1techs.com` (Receiving Clerk, demo password) with a draft receipt.
      → [ADR 0069](../decisions/0069-separate-purchasing-commercial-visibility-from-receiving.md)

- [x] Inventory operational read projection, first slice (2026-09-30) —
      `inventory.valuation.view` gates monetary fields in stock moves (including requested
      valuation entries), stock layers, AVCO pools and inventory-overview rows/summary; the
      quantity view remains available. Ledger/overview screens and print views follow the API
      projection → [ADR 0073](../decisions/0073-separate-inventory-valuation-visibility-from-stock-operations.md).
- [x] Inventory valuation visibility, current workflow/export audit complete
      (2026-10-01) — the only Inventory print tables, Stock Ledger and Inventory
      Overview, reuse permission-filtered columns and API-projected rows; browser
      coverage checks both permission states. No Inventory CSV/download/export
      endpoint currently exists. Future exports must reapply the backend valuation
      boundary; this sign-off covers current Inventory surfaces, not other modules'
      commercial data or future features → [stock](../domain/stock.md#user-interface).
- [x] Inventory policy settings/history cost audit (2026-10-01) — the shared
      settings read now omits the recalculation high-risk amount threshold without
      `inventory.recalculation-policy.manage` or `inventory.policy-history.view`;
      the policy-history endpoint already requires the latter and may carry old/new
      thresholds. No stock cost is returned by the other settings →
      [stock recalculation](../domain/stock-recalculation.md#review-staleness-and-risk-routing).
- [x] Negative-stock/settlement HTTP cost audit (2026-10-01) — override
      list/show/action resources expose shortage quantities and decisions but no costs;
      shortage and settlement tables have no direct HTTP read route. Economic effects
      flow through stock-move valuation entries and overview value fields already gated
      by `inventory.valuation.view` → [negative stock](../domain/negative-stock.md#user-interface).
- [x] Physical-count found-cost projection (2026-10-01) — quantity-only company reads
      omit `found_unit_cost`; a poster or `inventory.valuation.view` reader sees it in
      list/show and post responses. The poster continues to supply positive-variance cost
      explicitly; the SPA gates create/count/post and cost controls by capability →
      [physical count](../domain/physical-count.md#user-interface).
- [x] Recalculation economic-record read boundary (2026-09-30) — list/show now require a
      recalculation duty or `inventory.valuation.view`; workflow actors retain the complete
      economic evidence their decisions require, while valuation-only readers cannot act.
      The SPA hides unauthorised create/decision/execute/resume controls and gates bookmarked
      New → [ADR 0074](../decisions/0074-authorise-recalculation-economic-record-reads-by-workflow-duty.md).
- [x] Inventory cutover economic-record read boundary (2026-09-30) — list/show/preview now
      require a cutover duty or `inventory.valuation.view`; cutover actors retain complete
      source/control value evidence, while valuation-only readers cannot act. The SPA gates
      New, attachment, lifecycle and negative-opening controls by specific capability →
      [ADR 0075](../decisions/0075-authorise-inventory-cutover-economic-record-reads.md).
- [x] Confirm the missing `QualityDisposition` list screen stays an intentional non-gap
- [x] Assignment/backfill pass, then enforce `purchasing.item-supplier.manage`
- [x] Assignment/backfill pass, then enforce goods-receipt create/post/reverse
- [x] Assignment/backfill pass, then enforce purchase-order create/issue/cancel/short-close
      (sequence last — highest daily-user volume, highest lockout risk in this whole backlog)
- [x] Frontend action gating (Issue/Cancel/Short-close/Post/Reverse buttons) once `/api/v1/user`
      effective permissions ship
- [x] Allowed/denied API and non-HTTP Action tests, including both cost projections
- [x] Update `app/Modules/Purchasing/README.md` and [purchasing.md](../domain/purchasing.md)

The rollout/backfill path is explicit: `permission:register` creates the names, the deploy-time
`erp:sync-admin-permissions` step grants them to the configured administrator, and `demo:seed`
syncs the local demo user before any newly protected Action is called. Before this branch is
deployed, operational users or roles beyond that administrator must be granted their intended
subset; registration alone grants nobody access.

## C. Existing Estimating CRUD

| Resource | List filters (backend) | UI coverage | Write permission | Read permission |
|---|---|---|---|---|
| RateDimension | `search` | Full (1/1) | `estimating.rate-dimension.manage` | Shared authenticated lookup |
| RateDimensionValue | n/a, nested | n/a — managed inline in the rate-dimension edit screen | `estimating.rate-dimension.manage` | Shared authenticated lookup |
| ApplicationRate | `is_default,item_id,work_uom_id` | Full (3/3) | `estimating.application-rate.manage` | Shared authenticated lookup |
| Formulation | `customer_id,output_item_id,search,status` | Full (4/4) | `estimating.formulation.manage` | Shared authenticated lookup |
| FormulationVersion | n/a | n/a — managed inline in the formulation edit screen | `estimating.formulation.manage` | Shared authenticated lookup; cost breakdown separately gated |
| AssemblyGroup | `search` | Full (1/1) | `estimating.assembly-group.manage` | Shared authenticated lookup |
| Assembly | `assembly_group_id,search,status` | Full (3/3) | `estimating.assembly.manage` | Shared authenticated lookup; commercial fields projected |
| AssemblyVersion | n/a | n/a — managed inline (`assembly-versions-section.tsx`) | `estimating.assembly.manage` | Shared authenticated lookup; cost breakdown separately gated |
| ResourceRate | `item_id` | Full (1/1) | `estimating.resource-rate.manage` (append-only create/delete) | Shared authenticated lookup; numeric rate projected |
| **OverheadPolicy** | `scope_id,scope_type,status` | n/a — scope-bound panel by design | **`estimating.overhead-policy.manage`** ✓ | **`estimating.policy-history.view`** ✓ |

`OverheadPolicy` already has the full target shape end to end — separate manage/history-view
permissions, reason capture, audit trail, tests. **Use it as the literal template**, not a new
shape, for every capability below.

All validated Estimating list filters now have controls. The shared lookup selectors still
fetch at most 100 options; server-side search/pagination is a separate scale follow-up.

**Recommended capabilities**, matching `OverheadPolicy`'s naming shape: `estimating.rate-dimension.manage`,
`estimating.application-rate.manage`, `estimating.formulation.manage` (covers `FormulationVersion`
— a version is formulation content, not an independent audience), `estimating.assembly-group.manage`,
`estimating.assembly.manage` (covers `AssemblyVersion`/`AssemblyComponent` the same way),
`estimating.resource-rate.manage`.

### Tasks

- [x] Formulations and Assemblies list filters (2026-10-01) — all seven validated
      filters are URL-backed on their existing screens; search stays primary, the
      other filters use a mobile bottom sheet, and both lists offer mobile cards.
      Browser tests exercise the controls. The shared lookup pickers currently
      fetch up to 100 options; server-searchable pickers remain a scale follow-up.
- [x] Rate Dimensions and Assembly Groups search (2026-10-01) — URL-backed search
      controls and mobile cards, with browser coverage; no change to read authority.
- [x] Application Rates filters (2026-10-01) — item, work unit and default
      status use URL-backed controls, including a mobile filter sheet and cards.
      Browser tests cover default and context-specific rates.
- [x] Register all six capabilities above (2026-09-30). Registration alone grants no
      operational user access and must not be mistaken for a protected endpoint. All six are
      enforced after the resource-rate slice below.
- [x] Rate Dimensions and Values (2026-09-30): `estimating.rate-dimension.manage` gates create,
      update and delete at HTTP and non-HTTP Action boundaries. Index/show/value reads remain
      authenticated-only shared lookups. The SPA setup route and New action require the same
      capability; allowed/denied API and Action tests cover every write. Before deployment,
      grant it explicitly to existing dimension maintainers through a company role or direct
      assignment; the configured administrator receives it through the normal sync. No
      production-role assignment is inferred from a starter-role name.
- [x] Application Rates (2026-09-30): `estimating.application-rate.manage` gates create,
      update and delete at HTTP and non-HTTP Action boundaries. List/show/resolve remain
      authenticated company reads. The SPA permits preview users to inspect rates and run the
      resolution tester but restricts New/edit routes to managers. Allowed/denied API and Action
      tests cover the boundary. Before deployment, explicitly grant existing rate maintainers
      through company roles or direct assignments; administrator sync is not a grant to every
      operational role.
- [x] Formulations and Versions (2026-09-30): `estimating.formulation.manage` gates header and
      version create/update/delete/activate at HTTP and non-HTTP Action boundaries. List/show/
      resolve stay authenticated company reads for shared lookups. The separate cost-breakdown
      endpoint now requires `estimating.internal_cost.view`, including for recipe managers; the
      SPA hides that control without it. Allowed/denied API and Action tests cover the write
      boundary and cost projection. The demo seeder supplies its permission-synchronised actor
      to activation. Grant existing formulation maintainers before deployment; company role and
      direct assignments remain dynamic.
- [x] Assembly Groups (2026-09-30): `estimating.assembly-group.manage` gates create, update
      and delete at HTTP and non-HTTP Action boundaries. Index/show remain authenticated shared
      lookups for assembly and overhead forms. The SPA setup route follows the capability, and
      allowed/denied API and Action tests cover the boundary. Grant existing group maintainers
      before deployment through company roles or direct assignments; the configured administrator
      receives it through normal sync.
- [x] Assemblies and Versions (2026-09-30): `estimating.assembly.manage` gates header and
      version/component create, update, archive/delete and activation at HTTP and non-HTTP Action
      boundaries. List/show remain authenticated company lookups. The explainable version
      cost-breakdown endpoint separately requires `estimating.internal_cost.view`, and the SPA
      hides its control without it. Allowed/denied API and Action tests cover the writes and cost
      endpoint. The demo seeder supplies its permission-synchronised actor to activation. Grant
      existing assembly maintainers before deployment through company roles or direct grants.
- [x] Resource Rates (2026-09-30): `estimating.resource-rate.manage` gates append-only create
      and delete at HTTP and non-HTTP Action boundaries. List/show remain authenticated company
      lookups but omit the numeric rate without either manage or `estimating.internal_cost.view`;
      the SPA allows cost-only viewers to inspect rates and hides add/delete from them. API and
      Action denied-path tests cover both writes and the field projection. Grant existing rate
      maintainers before deployment through company roles or direct assignments.
- [x] Assignment/backfill procedure documented; all six write capabilities enforced. The
      configured administrator syncs on deploy; other existing maintainers need explicit
      company-role/direct grants before production rollout (not an automatic backfill).
- [x] Frontend route and action gating uses `/api/v1/user` effective permissions.
- [x] Allowed/denied API and non-HTTP Action tests landed with each capability slice.
- [x] `app/Modules/Estimating/README.md` and matching domain docs updated per slice.

## Recommended sequence across all three areas, by risk (lowest first)

Historical rollout order from the baseline audit; operational enforcement steps 2, 4–7
have landed. Step 3 remains open only for the Item/Category GL-account decision and
its resulting field treatment.

1. Formulations/Assemblies filter UI (section C) — zero risk, no permission dependency
2. Register every capability listed above, everywhere — zero risk, registration without
   enforcement is explicitly safe per authorization.md
3. Field-level protection for the sensitive fields in finding 2 — narrows responses, can't lock
   anyone out of an action they could previously complete (unlike a new `authorize()` gate)
4. Estimating CRUD + Item Supplier enforcement, with an assignment/backfill pass first — smaller,
   more specialised set of daily users than section B
5. Item/Category write-side enforcement — similar risk profile to step 4; read stays open per
   finding 3, so no combobox-breakage risk to sequence around
6. Purchase Order / Goods Receipt / Quality Disposition write enforcement — **last**. Largest,
   most operationally continuous set of daily users; the one most likely to actually lock out work
   in progress if the backfill pass is incomplete
7. Frontend action gating and tests land with each feature's own enforcement step (not batched),
   and only once `/api/v1/user`'s effective-permissions shape exists

## Acceptance criteria

- [ ] Every capability listed in sections A–C is registered and, where ticked as enforced above,
      has an assignment/backfill procedure that ran before enforcement went live
- [ ] Every sensitive field in finding 2 is protected or explicitly accepted as non-sensitive with
      a recorded reason
- [x] Every list screen in section C exposes every filter its endpoint validates
- [x] Every enforced capability has allowed/denied tests; sensitive-field projection tests
      cover Purchasing costs, Inventory valuation and Estimating rate/cost fields.
- [x] No enforcement step shipped without frontend action gating landing in the same or an
      immediately following commit

## Docs to update as tasks land

- [x] `docs/STATUS.md` — progress recorded with each shipped slice
- [x] This file — per-slice tasks reconciled with implementation
- [x] `authorization.md` — implementation assessment and boundaries refreshed
- [x] Module READMEs and domain docs named per section above updated with their slices;
      the unresolved GL-account policy will need its own documentation

## Related

- [authorization.md](authorization.md) — RBAC model, registration standard, safe-rollout rule this
  backlog assumes throughout
- [extensibility.md](extensibility.md#enablement-vs-access-two-separate-axes-not-one-toggle) — the
  enablement-vs-access pattern for any of these that later grows a company-level advanced-mode
  toggle rather than a plain permission
- [material-resolution.md](../domain/material-resolution.md#authorisation-impact) — the same
  sensitive-field concern, for the Phase 3 preview specifically
- [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope) — record/org-unit
  scope, out of scope here
- [Q27](../OPEN-QUESTIONS.md#q27--shared-approval-workflow-contract) /
  [Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy) — the PO submit/approve seam this
  backlog's create/issue/cancel capabilities are built to not block on, and must not collide with

## Later additions

- [x] **Inventory locations** (2026-09-29, [ADR 0067](../decisions/0067-governed-per-location-usability-mark.md)) —
      create/update/archive now need `inventory.location.manage`; marking a location as counting or not
      counting as usable stock also needs `inventory.location-policy.manage`, a reason and STRUCTURED mode.
      Allowed/denied tests: `LocationUsabilityOverrideTest`. Frontend: the warehouse detail screen's Locations
      panel hides Add/Edit without `inventory.location.manage` and the usable-stock control without
      `inventory.location-policy.manage` (the `/warehouses` route itself is still behind the legacy setup gate). Rollout needs the same administrator sync and explicit grants as the sections above.
- [x] **Warehouses and warehouse-location roles** (2026-09-29) — warehouse create/update/archive need the new
      `inventory.warehouse.manage`; warehouse-location role writes need `inventory.location.manage`. Reads stay open
      shared reference data. Allowed/denied tests: `WarehouseApiTest`. Frontend: the Warehouses list hides
      "New warehouse" and the detail screen hides "Edit" without `inventory.warehouse.manage`, and the `/warehouses`
      route now needs either capability instead of the legacy setup gate. No screen edits location roles yet.
