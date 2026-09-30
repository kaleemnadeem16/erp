[← Architecture](README.md)

# Authorisation retrofit backlog

- **Status:** in progress — Items/Categories and Purchasing operational writes enforced; sensitive-field and Estimating work remains
- **Depends on:** the Material Preview slice's effective-permissions endpoint (`GET
  /api/v1/user`) for every frontend-gating task below — do not build UI gating before it exists.
- **Blocked by:** nothing for registration/field-filtering work; the Purchasing/Purchase-order
  enforcement steps additionally depend on [Q27](../OPEN-QUESTIONS.md#q27--shared-approval-workflow-contract)/[Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)
  for the submit/approve seam, though plain create/issue/cancel capabilities don't need to wait
  for that.

The SPA navigation/direct-route presentation gap is closed by [ADR 0066](../decisions/0066-capability-driven-screen-navigation.md): effective capabilities select visible screens. Purchasing document list/detail reads have since gained dedicated view capabilities and minimum action-read dependencies under [ADR 0068](../decisions/0068-purchasing-document-read-capabilities.md). This does **not** complete backend field projection, Estimating CRUD permissions, record scope, or deliberately open cross-module reference reads (Items, Categories and other pickers). Do not count a hidden route as an API authorisation test.

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

## Cross-cutting findings

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
| Item | `category_id,search,status,type` | Full (4/4) | None | None (authenticated only) | GL account columns |
| Category | `parent_id,search` | Full (2/2) | None | None | GL account columns |

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
| PurchaseOrder | `search,status,supplier_account_id` | Full (3/3) | None on create/update/issue/cancel/short-close | None | Line cost fields |
| GoodsReceipt | `search,status,supplier_account_id` | Full (3/3) | None on create/post/reverse | None | Line cost fields |
| QualityDisposition | `disposition` | No standalone screen — resolved inline in `goods-receipt-detail-screen.tsx` from server-derived balances; not a gap, confirm it stays intentional | None on post | — | — |
| ItemSupplier | `active,item_id,preferred,search,supplier_account_id` | Full (5/5) | None | None | `minimum_order_qty` (mild) |
| PurchasingSetting/PurchasingPolicyChange | — | n/a (settings, not a list) | Already correct — `purchasing.over-receipt-policy.manage` / `.policy-history.view` | — | — |

This confirms, at finer grain, what [Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy)
already names ("today any authenticated caller reaching the endpoint can create and issue a PO") —
and finds the same gap across cancel, short-close and the entire goods-receipt lifecycle, not only
issue.

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
| RateDimension | `search` | Missing — no search box | None | None |
| RateDimensionValue | n/a, nested | n/a — managed inline in the rate-dimension edit screen | None | None |
| ApplicationRate | `is_default,item_id,work_uom_id` | Partial (1/3) — only `item_id` | None | None |
| Formulation | `customer_id,output_item_id,search,status` | Missing (0/4) | None | None |
| FormulationVersion | n/a | n/a — managed inline in the formulation edit screen | None | None |
| AssemblyGroup | `search` | Missing — no search box | None | None |
| Assembly | `assembly_group_id,search,status` | Missing (0/3) | None | None |
| AssemblyVersion | n/a | n/a — managed inline (`assembly-versions-section.tsx`) | None | None |
| ResourceRate | `item_id` | Full (1/1) | None (append-only, no update endpoint by design; `Destroy` also unchecked) | None |
| **OverheadPolicy** | `scope_id,scope_type,status` | n/a — scope-bound panel by design | **`estimating.overhead-policy.manage`** ✓ | **`estimating.policy-history.view`** ✓ |

`OverheadPolicy` already has the full target shape end to end — separate manage/history-view
permissions, reason capture, audit trail, tests. **Use it as the literal template**, not a new
shape, for every capability below.

The Formulations/Assemblies missing filter UI has **zero permission dependency** and can land
independently of this backlog's sequencing whenever wanted.

**Recommended capabilities**, matching `OverheadPolicy`'s naming shape: `estimating.rate-dimension.manage`,
`estimating.application-rate.manage`, `estimating.formulation.manage` (covers `FormulationVersion`
— a version is formulation content, not an independent audience), `estimating.assembly-group.manage`,
`estimating.assembly.manage` (covers `AssemblyVersion`/`AssemblyComponent` the same way),
`estimating.resource-rate.manage`.

### Tasks

- [ ] Add missing filter UI: Formulations (`customer_id,output_item_id,search,status`), Assemblies
      (`assembly_group_id,search,status`), Rate Dimensions/Assembly Groups (`search`), Application
      Rates (`is_default,work_uom_id`) — no permission dependency, can land anytime
- [x] Register all six capabilities above (2026-09-30). Registration alone grants no
      operational user access and must not be mistaken for a protected endpoint. Five remain
      reserved after the rate-dimension slice below.
- [x] Rate Dimensions and Values (2026-09-30): `estimating.rate-dimension.manage` gates create,
      update and delete at HTTP and non-HTTP Action boundaries. Index/show/value reads remain
      authenticated-only shared lookups. The SPA setup route and New action require the same
      capability; allowed/denied API and Action tests cover every write. Before deployment,
      grant it explicitly to existing dimension maintainers through a company role or direct
      assignment; the configured administrator receives it through the normal sync. No
      production-role assignment is inferred from a starter-role name.
- [ ] Assignment/backfill pass, then enforce (lower daily-write volume than Purchasing, so this can
      land before section B's enforcement steps)
- [ ] Frontend action gating once `/api/v1/user` effective permissions ship
- [ ] Allowed/denied tests, `OverheadPolicyApiTest`-shaped
- [ ] Update `app/Modules/Estimating/README.md`

## Recommended sequence across all three areas, by risk (lowest first)

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
- [ ] Every list screen in section C exposes every filter its endpoint validates
- [ ] Every enforced capability has allowed/denied/field-filtering tests
- [ ] No enforcement step shipped without frontend action gating landing in the same or an
      immediately following commit

## Docs to update as tasks land

- [ ] `docs/STATUS.md` — tick progress in the same commit as each task
- [ ] This file — tick tasks in the same commit as the work, per the phase-doc convention it borrows
- [ ] `authorization.md` — once every capability here is enforced, fold the "Not yet sufficient"
      bullets this backlog resolves back into "Strong foundation already present"
- [ ] Module READMEs and domain docs named per section above

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
