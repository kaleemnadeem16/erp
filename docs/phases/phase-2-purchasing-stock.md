[← Roadmap](README.md)

# Phase 2 — Purchasing & stock

- **Status:** **complete (closed 2026-10-08).** Purchasing, receipts, QA and reversal, the stock-movement and valuation engine, cutover, negative-stock overrides, backdated recalculation and physical counts are built with their screens. The few items still unchecked below are **carried forward** to named later owners (see the close-out note); none is unfinished Phase 2 build
- **Depends on:** [Phase 1 — Master data](phase-1-master-data.md) (items, supplier accounts,
  currencies, UoMs and chart-of-account references)
- **Blocked by:** nothing currently; negative stock is fixed by
  [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md) and backdated receipts by
  [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)

> **Close-out (2026-10-08, owner decision).** Phase 2 is formally closed. Every item still shown unchecked below is **carried forward** and marked *Carried forward* at its place — none keeps Phase 2 open:
> - **To the Phase 4–6 correction package** (needs Sales/COGS, Job Execution and Accounting to exist; [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)): cross-module correction packages and
>   the acceptance criteria that depend on them, and the valuation-policy boundary of the backdating horizon (Phase 6).
> - **To later Purchasing/operations slices:** PO fields with no schema yet (ship-to, per-PO payment terms, buyer, shipping method — ship-to also unlocks Q26 purchasing record scope); the first automated production deploy
>   run and removing mainvm's emergency GHCR credential ([deployment.md](../architecture/deployment.md)).
> - **Closed by other documents:** the "agree the interaction specification" item is satisfied by the [journeys](../journeys/README.md), the [ERP Blue standard](../architecture/erp-blue-design-standard.md), the design gate in
>   [user-interface.md](../architecture/user-interface.md#design-gate-before-a-workflow-is-built) and the [feature-completion checklist](../architecture/feature-completion-checklist.md); recalculation requests with replacement
>   versions are implemented ([stock-recalculation.md](../domain/stock-recalculation.md)).
> Package-aware inventory (grouped tracking, QA-held stock) shipped as its own workstream: [package-balance-handoff.md](package-balance-handoff.md). New correction work is a Phase 4 dependency or its own slice, not a reason to reopen this phase.

## Goal

Make purchasing and inventory operational: a company can define approved suppliers per item,
raise and receive a purchase order, move traceable stock through warehouses and locations,
value every receipt and issue under the item's official AVCO/FIFO/SPECIFIC method, and reconcile
physical counts. Phase 2 produces a complete valuation feed for later GL posting; it does not
create journal entries itself.

## Scope

### In

- The purchasing and acquisition-costing domain designs: purchase-order/receipt schema, QA and
  matching boundaries, partial receipt and UoM/currency/cost snapshots
- `item_suppliers` per [ADR 0033](../decisions/0033-item-suppliers-relationship.md), including
  migration of `items.default_supplier_id` into priority-1 relationships and removal of that
  obsolete column
- A `Purchasing` module owning supplier sourcing, purchase orders and goods-receipt documents
- `warehouses` and `locations` in the existing `Inventory` module
- `stock_moves`, `stock_layers`, explicit `avco_pools` and `stock_valuation_entries` per
  [stock.md](../domain/stock.md), with retained batch/serial and source-document identity
- Inventory cutover foundations per [ADR 0036](../decisions/0036-controlled-inventory-cutover.md):
  explicit cutover timestamp/mode, provenance-capable stock schema, opening AVCO/FIFO/SPECIFIC
  state Actions and reconciliation; generic mapping UI/engine delivery remains separately scoped
- Transactional AVCO, FIFO and SPECIFIC valuation selected by `items.valuation_method`
- Goods receipt, internal transfer, stock issue/return, adjustment and physical-count workflows
- Locking `items.valuation_method` and `stock_uom_id` after the first stock move
- Backend APIs, validation, company scoping, module Actions, Pest coverage and regenerated Orval
  client for every Phase 2 resource exposed over HTTP

### Out

- Supplier-invoice/payables tables, matching approval/payment holds and payment runs — Phase 6
  Finance; Phase 2 preserves the receipt/allocation contract those workflows require
- Balanced `journal_entries`/`journal_lines`; Phase 2 only writes valuation-feed rows — Phase 6
- Sales orders, customer returns initiated from sales, quotations and frontend CRUD — Phase 4
- Job/work-order material issue screens and job-cost entries — Phase 5
- Analytical replay under a valuation method other than the item's official method — retained
  history must support it, but the reporting engine is deferred to Phase 3 or later
- Vendor price lists and quantity-break pricing — not required by ADR 0033; purchase price is
  snapshotted on the PO line unless a later requirement produces a separate ADR
- Physical picking strategies such as FEFO/MANUAL — distinct from accounting valuation and not
  required in this phase
- Production deployment from Phase 0 — remains independently deferred until explicitly picked up

## Sequencing

The order matters because later work needs stable document and stock boundaries:

1. Resolve Q14 as an ADR; Q13 is resolved by
   [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md), and the purchasing foundation is recorded in
   [purchasing.md](../domain/purchasing.md), [acquisition-costing.md](../domain/acquisition-costing.md)
   and [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md).
2. Build supplier sourcing and warehouse/location foundations.
3. Build purchase orders and goods-receipt documents with immutable issued-line snapshots.
4. Build the single stock-movement Action and valuation engines behind it.
5. Add transfers, returns, adjustments and physical counts through that same movement boundary.
6. Expose/finish APIs, regenerate the client, audit integrity and reconcile documentation.

## Tasks

### Design and module boundaries

- [x] Write the purchasing/acquisition-costing domain design before the Purchasing module's first
      feature; define document boundaries, partial receipts, QA/matching integration, provisional
      costing, purchase-UoM conversion and transaction/base-currency snapshots
- [x] Resolve Q14 in an accepted ADR and remove it from `OPEN-QUESTIONS.md`; Q13 is resolved by
      ADR 0037 and both policies are reconciled into `stock.md`
- [x] Fix partial/over-receipt, short-close, QA-disposition, FX, reversal, GR/IR and posting
      concurrency behaviour in ADR 0043; keep later Phase 6 matching tolerance separate
- [x] Scaffold `app/Modules/Purchasing/README.md`, provider and routes; document the boundary:
      Purchasing owns sourcing and source documents, Inventory owns physical movement/valuation,
      and cross-module writes go through Actions rather than direct model mutation

### Supplier sourcing

- [x] Build `item_suppliers` with tenant-safe item/supplier/UoM references, pair uniqueness,
      priority, active flag, supplier SKU, lead-time override and minimum-order quantity
- [x] Enforce at most one active priority-1 supplier per item with a PostgreSQL partial unique
      index; define deterministic behaviour when no preferred supplier exists
- [x] Migrate each populated `items.default_supplier_id` to an active priority-1 relationship,
      then remove the obsolete column, model relation, request/resource fields and generated types
- [x] Add CRUD/query APIs and tests proving multiple approved suppliers, same-company ownership,
      preference uniqueness and lead-time fallback to `supplier_accounts.lead_time_days`

### Warehouse foundation

- [x] Build company-scoped `warehouses` and child `locations`; require at least one location per
      usable warehouse and enforce one default warehouse per company with a partial unique index
- [x] Add APIs and tests for warehouse/location lifecycle, composite uniqueness, tenant isolation
      and deletion restrictions once stock history exists
- [x] Add progressive `SIMPLE`/`STRUCTURED` location capability modes, same-warehouse hierarchy,
      fixed types/barcodes/stock semantics, operational roles and guarded downgrade per ADR 0041

### Purchase orders and receipts

- [x] Build purchase orders and lines from `purchasing.md`, using gapless numbering through the
      existing `NumberingScheme`, supplier-account/site references and explicit currency/FX data
- [x] Snapshot supplier item code, description, ordered quantity, purchase UoM, converted stock
      quantity, unit price, currency and pinned FX rate when a PO is issued; never reconstruct an
      issued PO from current supplier/item/rate master data
- [x] Implement transactional draft create/update, issue, cancel and hard line short-close Actions;
      close the PO when every line is terminal and expose validated query/settings APIs
- [x] Implement partial/full receipt Actions with concurrency-safe outstanding quantities and
      idempotency protection against duplicate receipt submission
- [x] Build goods-receipt headers/lines that retain the PO-line link, received purchase quantity,
      converted stock quantity, location, batch/serial identity and actual receipt timestamp
- [x] Implement V1 cumulative `BLOCK`/`ALLOW_WITHIN_TOLERANCE` in pinned stock UoM, hard
      short-close, line-level receipt currency/cost/FX and same-supplier/currency grouping
- [x] Post QA outcomes as repeatable immutable dispositions over pending receipt quantity; lock
      concurrent dispositions and keep QA state independent from physical location
- [x] Implement explicit receipt reversal with dependency checks and Inventory/GR-IR valuation
      feed effects; do not conflate reversal with the separately deferred Supplier Return

### Stock movement and valuation

- [x] Build the stock tables in `stock.md` with company-aware foreign keys, decimal quantities and
      costs, immutable source identity, and indexes for item/location/date and open FIFO layers
- [x] Implement one transactional inventory movement boundary used by receipts, issues, returns,
      transfers and adjustments; no other code path may alter quantity or valuation state
      (`PostInventoryMovement`; physical-count variances route through it too, and the ledger
      tables it writes are now database-immutable behind it — see the integrity audit below)
- [x] Resolve and persist each AVCO item's `COMPANY_ITEM`, `WAREHOUSE_ITEM` or
      `COMPANY_ITEM_BATCH` pool scope (default `COMPANY_ITEM`), enforce tracking compatibility and
      lock it after the first movement; defer `WAREHOUSE_ITEM_BATCH`, per ADR 0039
- [x] Implement AVCO with row locking around resolved `avco_pools`; prove two concurrent receipts
      into one pool cannot calculate from stale state while independent pools remain independent
      (`AvcoPoolManager::lock()`; proven by `AvcoPoolConcurrencyTest` — two forked processes post
      into one pool through `PostInventoryMovement` and the pool lands on the exact weighted average)
- [x] Implement same-pool transfer neutrality and cross-pool transfer value conservation,
      destination-average recalculation and immutable source/destination pool identities
- [x] Price a cross-pool transfer from a frozen source pool at its final frozen rate (ADR 0045),
      without permitting a negative physical source or creating/destroying company inventory value
- [x] Implement FIFO oldest-layer consumption with partial-layer splits and row locking
- [x] Implement SPECIFIC receipt/issue validation: exact serial identity is required and a serial
      cannot be received twice or issued more than once while on hand
- [x] Enforce tracking rules (`NONE`/`BATCH`/`SERIAL`) independently from valuation method and
      preserve batch/serial data on moves and layers
- [x] Exclude non-`PASS`/`ACCEPT_WITH_CONCESSION` quantity (never-dispositioned, open `REWORK`/
      `PENDING_DECISION`, and terminal `SCRAP`/`RETURN_TO_SUPPLIER`) from an item's
      available-to-issue balance for a `qa_required` receipt line
- [x] Write one `stock_valuation_entries` feed for every costed movement; store transaction and
      base amounts using the pinned FX rate, without creating GL journals
- [x] Prevent normal edits to an item's `valuation_method`, `avco_pool_scope` or `stock_uom_id`
      after its first move; scope changes use a separate effective-dated valuation cutover
- [x] Build ADR 0037's conservative company policy foundation: `BLOCK` default, constrained
      movement eligibility, prior/immediate mode, bounded review SLA, elevated permission,
      mandatory reason and immutable old/new audit history
- [x] Build immutable ADR 0037 override requests, independent prior-approval decisions and bounded
      immediate-review outcomes with tenant-safe actors, permissions and snapshotted deadlines
- [x] Consume authorised override records in the movement boundary, revalidate the exact shortage,
      derive provisional AVCO/FIFO cost and atomically create linked shortage state
- [x] Settle compatible inbound against oldest exact-identity shortages, support partial recovery,
      expose only excess stock and append provisional-to-actual valuation repairs
- [x] Implement company backdating horizon modes (`CURRENT_AND_PREVIOUS_PERIOD` default,
      `PERIOD_LOCK_ONLY`, `POSTING_FLOOR` with fixed/rolling subtype), audited policy changes and
      most-restrictive-boundary evaluation; rolling mode requires a valid company IANA accounting
      timezone and trusted server time, never user-controlled clocks
- [x] Snapshot request-time rolling-floor context, preserve eligibility across normal clock
      progression, revalidate material policy/boundary changes at execution and reject per-request
      horizon extensions. `RequestStockRecalculation` snapshots `horizon_mode`/
      `horizon_permitted_floor`; no request field can extend the horizon; and
      `ExecuteRecalculation` re-evaluates the horizon before acting, so a tightened policy, a newly
      closed period or a cutover approved after the request all stop execution
      (`ExecuteRecalculationTest`)
- [x] Build recalculation requests with immutable append-only impact versions, exact-version
      approvals, state-based staleness and automatic replacement-version generation per
      [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md). Receipt,
      issue and transfer previews are implemented for supported histories;
      [connected replay](../domain/stock-movement-replay.md) includes both transfer sides
- [x] Enforce bounded company-configurable review SLAs (72-hour default), overdue escalation that
      preserves requester/approver separation, and hard approved-execution TTLs (24-hour default)
      using persisted deadlines and trusted server time. Each required approval persists its own
      expiry and supports same-actor renewal after expiry, and `ExecuteRecalculation` enforces
      `ALL_REQUIRED_APPROVALS_UNEXPIRED` uniformly before branching by movement kind, covering every
      movement kind and valuation method
      ([stock-recalculation.md](../domain/stock-recalculation.md))
- [x] Implement exact semantic impact comparison, authority-only approval rerouting, per-version
      normal/high-risk classification and mandatory second independent approval for hard high-risk
      consequences per
      [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)
- [x] Audit every consequential company-policy change with elevated permission, mandatory reason,
      actor, old/new values and timestamp without rewriting snapshotted request context/deadlines
      - Done for Inventory's three governed groups (`InventoryPolicyChange`) and, since the
        pre-deployment audit, for Purchasing's over-receipt policy (`PurchasingPolicyChange`,
        `purchasing.over-receipt-policy.manage`) — which previously had no permission, reason or
        audit trail at all.
      - ADR 0047 brings `inventory_location_mode` and `default_avco_pool_scope` under separate
        location-structure and valuation-policy permissions, with the same mandatory reason and
        immutable old/new-value audit contract (`NegativeStockPolicyTest`).
- [x] Serialise overlapping recalculation execution with deterministic locks over the complete
      method-specific replay closure, count only post-approval material invalidations toward the
      regeneration cap, and escalate repeated cap hits without resetting the counter.
      `ExecuteRecalculation` does this for AVCO, SPECIFIC and FIFO receipts through later direct
      issues and any depth of transfer chain, and for backdated issues/transfers whether or not
      their replay reprices other history (`OutboundRecalculationExecutor`, same real-layer-id
      correction engine as the receipt case for FIFO/SPECIFIC, multi-pool convergence for AVCO)
- [ ] **Carried forward → Phase 4–6 correction package.** Build idempotent append-only correction packages preserving exact approved deltas, historical
      FX bases, deterministic downstream allocation, explicit pending owners and zero-residual
      reconciliation per
      [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md).
      `RecalculationExecution` is the idempotent, append-only Inventory-owned slice
      ([stock-recalculation.md](../domain/stock-recalculation.md)); cross-module allocation,
      pending owners and reconciliation need Sales/Job Execution/Accounting, which don't exist yet

### Inventory cutover foundation

- [x] Extend the generic ADR 0027 import contract for `INVENTORY_CUTOVER`; do not build a parallel
      uploader/mapper or permit the generic importer to write stock domain tables directly
- [x] Build company cutover records with one explicit timestamp/mode, immutable source provenance,
      dedicated permissions, staging/preview/approval/finalisation and idempotent batch identity
- [x] Implement reconciled opening-state Actions: target AVCO pools, FIFO residual or labelled
      synthetic layers, and per-serial SPECIFIC costs; preserve source method/scope/pool identity
      and effective-dated transitions across a method or AVCO-scope change
      (`CommitInventoryCutoverOpeningState`/`OpeningStateItemPoster`)
- [x] Validate quantity/value by exact location, target AVCO pool and company total, plus UoM/FX,
      batch/serial identity and layer totals; reject unsupported broad→narrow pool allocation or
      require an explicitly approved revaluation basis rather than inventing detail. ADR 0046's
      strict V1 boundary permits only approver-attributed defaults for optional fields or an exact
      opening location within a supplied warehouse; validation rejects missing critical identity
      or inconsistent arithmetic, and finalisation independently reconciles posted location/pool/
      company totals (`InventoryCutoverImportContractTest`, `InventoryCutoverWorkflowTest`)
- [x] Keep historical negative/backdated source facts separate from live posting, and test that no
      ordinary API, mapping template or post-cutover batch can invoke migration-only exceptions
      — the cutover-shortage exception (`InventoryCutoverNegativeOpening`) is a structurally
      separate table/Action pair from live ADR 0037 overrides; the shared Imports target only
      validates and never writes stock state
- [x] Leave open-PO materialisation to Purchasing and the balancing opening journal to Phase 6,
      connected through immutable cutover/source references. Purchasing registers the
      `OPEN_PURCHASE_ORDER` staged target and materialises each predecessor order's remaining
      lines as one issued target PO, retaining immutable cutover/batch/row/source identity; retry
      resolves the existing result. The Phase 6 balancing opening journal remains deferred
      (`OpenPurchaseOrderCutoverTest`).

### Counts, API and audit

- [x] Build physical-count documents with snapshot lines, count/recount workflow and posted
      variance adjustments routed through the same movement Action
      ([physical-count.md](../domain/physical-count.md))
- [x] Add validated list filters, sorting, includes and pagination for all Phase 2 APIs; return
      domain conflicts as stable 409/422 responses rather than leaking database exceptions
      - Every Phase 2 index endpoint validates `filter`, `sort`, `include` and its pagination
        parameters through `ValidatesListQuery::listQueryRules()`; the cursor-paginated lists
        (`stock-moves`, `stock-layers`) add `cursor` in place of `page`. Error mapping is global
        in `bootstrap/app.php`: `DomainInvariantException` and unique-constraint violations →
        422, SQLSTATE `23503` → 409, `23514` → 422, so no database exception reaches a client.
      - All five originally Action-only areas now have HTTP surfaces — see Close-out below.
        Remaining nit, deliberately left: `negative-stock-override-requests` and
        `recalculation-requests` expose no `filter[status]`, because neither has a stored status
        column (both derive it).
- [x] Regenerate and commit the Orval client; verify all frontend type-checks and production build
- [x] Run a Phase 2 integrity audit covering raw-write tenant protection, concurrency, rollback,
      duplicate submission, precision, tracking and historical-retention guarantees. Findings,
      each fixed in the same commit:
      - **Historical retention** — `stock_moves`, `stock_valuation_entries` and `stock_layers` (the
        ledger itself), plus `goods_receipts`/`goods_receipt_lines` and `purchase_orders`/
        `purchase_order_lines`, had only Eloquent guards; raw SQL could rewrite or delete posted
        history. Every other immutable Phase 2 table already had a database trigger. Added
        triggers mirroring each model's own guard one-to-one (a layer may still consume
        `qty_remaining`; a document may still make its draft→posted/issued transition and the
        status/reversal/short-close/cancel columns those flows legitimately write) →
        `StockLedgerIntegrityTest`, `GoodsReceiptPostingTest`, `PurchaseOrderApiTest`.
      - **Raw-write tenant protection** — `recalculation_requests.location_id`/
        `destination_location_id` were the one bare location reference with no company trigger
        (every other one had one). Added `rr_scope` → `StockLedgerIntegrityTest`. Structural
        proof for everything else: `CompanyScopedForeignKeyTest`, `CompanyScopedUniqueIndexTest`.
      - **Concurrency** — the "two concurrent AVCO receipts" acceptance criterion had the lock
        but no proof → `AvcoPoolConcurrencyTest` (real forked processes, same technique as
        `QualityDispositionPostingTest`'s). QA dispositions, gapless numbering and location-mode
        locking were already proven.
      - **Rollback** — receipt posting already proved it; physical-count posting and cutover
        commit now do too: a failing later line/item leaves no earlier line's/item's move, pool,
        provenance row or item retargeting behind → `PhysicalCountTest`,
        `InventoryCutoverOpeningStatePostingTest`.
      - **Duplicate submission** — already covered: receipt post retry, QA idempotency key,
        cutover batch attach/commit, physical-count re-post, negative-opening request are all
        idempotent-return. No gap found.
      - **Precision** — no `real`/`double precision` column exists in the schema; every quantity
        and money column is `numeric(19,4)` (FX `19,9`) with `BigDecimal` HALF_UP everywhere. No
        gap found. Observation, not changed: `created_at`/`updated_at` from `$table->timestamps()`
        are `timestamp without time zone` while every business timestamp is `timestamptz`; the app
        runs in UTC so this is cosmetic.
      - **Tracking** — batch/serial rules independent of valuation method are already covered
        (`StockIdentity` + `stock_layers_active_serial_unique`). One consistency gap fixed: cutover
        opening-state posting didn't check `is_stocked` the way every other posting path does.

### Close-out

Phase 2 closes with UI, not with the backend alone — and the first screens can only be built
against HTTP APIs that exist. Five feature areas were complete as Actions with no HTTP surface;
each was exposed when its first screen was designed, so the API shape was driven by a real
screen's needs rather than guessed upfront (the architecture doc flagged the stock ledger as a
cursor-pagination candidate, unlike every existing page-based list). **All five now have both an
HTTP surface and screens**. This closes the stock-engine exception/document slice, not the normal
purchasing and inventory operating UI; that remaining boundary is listed below:

- [x] **Stock ledger** read APIs — `stock_moves` has a cursor-paginated list and a show endpoint
      (`GET /api/v1/stock-moves`), filterable by item/warehouse/location/batch/source type/date
      range, with `item`/`from_location`/`to_location`/`batch`/`valuation_entries` available via
      `include`. `stock_layers` (`GET /api/v1/stock-layers`, cursor-paginated, `filter[open]` for
      an item's still-open FIFO/SPECIFIC layers) and `avco_pools` (`GET /api/v1/avco-pools`,
      page-paginated, an item's current converged balance) now each have their own read endpoint
      too — deliberately backend-only, no dedicated screen: this is reference data, not a
      workflow, unlike the other four close-out areas. `PostInventoryMovement`,
      `AvcoPoolManager` and the recalculation executors remain the only write paths.
- [x] **Inventory cutover** — full HTTP surface: `POST/GET /api/v1/inventory-cutovers`,
      `GET /api/v1/inventory-cutovers/{id}`, `POST .../batches` (`AttachInventoryCutoverBatch`),
      `POST .../validate` (`ValidateInventoryCutover`), `GET .../preview`
      (`PreviewInventoryCutover`, read-only), `POST .../approve` (`ApproveInventoryCutover`),
      `POST .../commit` (`CommitInventoryCutoverOpeningState`), `POST .../finalise`
      (`FinaliseInventoryCutover`), `POST .../negative-openings`
      (`RequestInventoryCutoverNegativeOpening`) and
      `POST /api/v1/inventory-cutover-negative-openings/{id}/approve`
      (`ApproveInventoryCutoverNegativeOpening`) — the header stays create/read-only over HTTP,
      every state change is a named action per api-conventions.md. "Attach batch" takes a raw
      Import Batch ID: the Imports module has no HTTP API/UI of its own yet to browse or create
      batches through. Operational/full-history cutovers additionally expose `POST
      .../commit-open-purchase-orders`, the Purchasing-owned action for staged remaining
      commitments
- [x] **Stock recalculation** — full HTTP surface: `POST/GET /api/v1/recalculation-requests`,
      `GET .../{id}` (header, `RequestStockRecalculation`, create/read-only — it's also the
      preview, computing the first impact version synchronously), `POST
      /api/v1/recalculation-impact-versions/{id}/decide` (`DecideRecalculationApproval`),
      `.../execute` (`ExecuteRecalculation`) and `.../resume-regeneration`
      (`ResumeRecalculationRegeneration`) — the three state-change actions apply to
      `RecalculationImpactVersion`, a genuinely distinct addressable child resource (a request
      accumulates several versions over its life), not the header, mirroring the cutover
      negative-opening exception's own route prefix. This closes the **entire** close-out
      checklist's action areas — only the small `stock_layers`/`avco_pools` read-endpoint gap on
      the stock-ledger item and production deployment remain
- [x] **Negative-stock overrides** — full HTTP surface: `POST/GET
      /api/v1/negative-stock-override-requests`, `GET .../{id}`, `POST .../decide`
      (`DecideNegativeStockOverride`) and `POST .../review` (`ReviewNegativeStockOverride`) —
      the header (`RequestNegativeStockOverride`) stays create/read-only over HTTP, and decide/
      review are named actions on the same resource (no separate exception resource, unlike the
      cutover negative-opening exception). `UpdateInventorySettings` was already exposed via
      `PATCH /api/v1/inventory-settings`. No status column exists on the request — the frontend
      derives display status from `authorisation_mode` plus whether `approval`/`review` exists
- [x] **Physical counts** — full HTTP surface: `POST/GET /api/v1/physical-counts`,
      `GET /api/v1/physical-counts/{id}`, `POST .../lines` (`AddPhysicalCountLine`),
      `POST /api/v1/physical-count-lines/{id}/count` (`RecordPhysicalCountLine`),
      `POST .../post` (`PostPhysicalCount`) and `.../cancel` (`CancelPhysicalCount`) — the header
      stays create/read-only over HTTP, every state change is a named action per
      api-conventions.md
- [x] First UI screens for the stock engine, built against the endpoints above as each lands —
      the Stock Ledger list/detail screens (`/stock-moves`) were first, reading `stock_moves`;
      Physical Counts (`/physical-counts`) is the first screen with a document workflow (create,
      add lines, record counts, post/cancel); Inventory Cutovers (`/inventory-cutovers`) is the
      first with a multi-actor approval lifecycle (validate, independent approve, commit,
      finalise), a distinct open-PO commit action when such a batch is pending, plus its own
      negative-opening exception sub-flow; Negative-Stock Overrides
      (`/negative-stock-overrides`) is the first screen with no server-side status column,
      deriving its display status entirely from `authorisation_mode` and child-record presence;
      Stock Recalculation (`/recalculation-requests`) is the most involved — a version history
      (a request can accumulate several impact versions as staleness regenerates replacements),
      multi-approver approvals with individual expiry/renewal, and risk-tier routing (1 vs 2
      required approvers), with computed convenience flags (`is_fully_approved`,
      `has_unexpired_full_approval`, etc.) added to the API response rather than pushed onto the
      frontend, unlike every earlier derived-status screen
- [x] *(Closed 2026-10-08 by the journeys, ERP Blue standard, design gate and completion checklist.)* Research and agree the Phase 2 interaction specification before adding more screens. Cover
      user roles and task frequency, document status/permission actions, keyboard-efficient line
      entry, responsive table/card behaviour, validation recovery, consequential-action review,
      accessibility and realistic end-to-end prototypes per
      [user-interface.md](../architecture/user-interface.md).
- [x] Build the inventory overview: clearly separate on-hand, available, QA-held, shortage and
      on-order quantities; filter and drill down by item, warehouse, location and tracking identity;
      link every aggregate back to the stock ledger. Add purpose-built read models/endpoints rather
      than calculating decimal aggregates in React. `GET /api/v1/inventory-overview`
      (`BuildInventoryOverviewQuery`) computes all five figures server-side in one set-based query
      (avco_pools/stock_layers branched on `valuation_method` — they're kept in lockstep for AVCO
      items, not mutually exclusive as first assumed, so summing both would have double-counted
      on-hand; caught by the Feature test before it shipped); `/inventory-overview` filters by item
      code/name and category, and each row's "View ledger" action opens the existing Stock Ledger
      screen pre-filtered to that item, which already has warehouse/location/date filters — rather
      than duplicating a second, competing drill-down UI on this screen (`InventoryOverviewApiTest`,
      535 Purchasing/Inventory tests passing; verified end-to-end on desktop and mobile viewports).
- [x] Build purchase-order list, draft create/edit and detail workspaces, including issue, cancel
      and line short-close actions, immutable issued snapshots, totals and receipt progress. The
      server-paginated URL-filtered list, repeatable-line draft creation and status-gated detail
      actions use generated APIs throughout. Exact transaction/base totals are calculated on the
      server with decimal arithmetic; received/outstanding progress is derived only from posted
      receipts, while currency and purchase/stock UoMs are presented with human reference labels.
- [x] Build goods-receipt list/create/detail/post/reverse screens and receipt-line QA disposition
      workflow, including unresolved QA quantity, tracking entry and disposition history. Draft
      entry draws only from the selected supplier's issued, outstanding PO lines; posting and
      reversal use explicit consequence review, and QA roots/open branches are resolved inline
      from server-derived unallocated/unresolved balances.
- [x] Build purchasing/inventory policy settings screens with permission-aware reason capture and
      audit context. `/settings/purchasing` (over-receipt mode/tolerance, PO-required) and
      `/settings/inventory` (location mode, default AVCO pool scope — ADR 0047/Q17's two fields
      only, not the other three policy groups the same `InventorySettingsResource` also carries)
      each require a reason on change and show their own audit trail via two new read-only
      endpoints, `GET /api/v1/{purchasing,inventory}-policy-changes`
      (`PurchasingPolicyChangeApiTest`, `InventoryPolicyChangeApiTest`; verified end-to-end,
      including the 403-before-permission-grant path). Reading either history requires its own
      `{purchasing,inventory}.policy-history.view` permission, separate from the settings' own
      manage permission — a user who can edit a policy isn't automatically allowed to see who
      changed it before; both screens render "you don't have permission" rather than a misleading
      empty list when that permission is missing.
- [x] Retrofit operational authorisation across Items/Categories and Purchasing: reference reads
      stay available, while item/category maintenance, supplier relationships, PO draft/issue/
      cancel/short-close, receipt create/post/reverse and QA disposition posting each use their
      registered capability, matching SPA control gating and non-HTTP checks on consequential
      Actions. Deployment must grant intended users/roles before enforcement goes live.
- [x] ~~Replace temporary raw-ID controls in cutover screens when the required lookup endpoints
      exist~~ — done 2026-09-26. `inventory-cutover-detail-screen.tsx`'s negative-opening
      exceptions table rendered a bare `location_id`; `item` resolution sat right next to it but
      was equally dead, since neither `InventoryCutoverController::show()` nor
      `InventoryCutoverNegativeOpening` eager-loaded either relation, so `whenLoaded()` always
      omitted both from the response regardless of the frontend's `include` query. Added a
      `location()` relation and, rather than special-casing the controller's `include` handling
      (which trips Scramble into over-broadly marking the whole `negative_openings` array
      required — checked via the generated OpenAPI diff), gave the model
      `protected $with = ['item', 'location']`: every screen showing an exception needs its
      identity, never just the foreign keys. Covered by
      `InventoryCutoverApiTest`'s existing negative-opening workflow test, extended to assert both
      fields on the approve response and on `GET .../inventory-cutovers/{id}?include=negativeOpenings`.
- [ ] **Carried forward → a later Purchasing slice.** PO fields with no schema, API or UI yet, surfaced while building supplier sites
      (2026-09-22): a ship-to/deliver-to location on the PO (today only the supplier's own
      order-from/ship-from site is captured, see [parties.md](../domain/parties.md#shared-sites));
      a per-PO payment-terms override (`payment_terms_days` lives only on `supplier_accounts`
      today, applying to every PO from that supplier); a buyer/requester field; a shipping-method
      field. None are blocked on anything and none have an ADR — add one only if a real
      requirement narrows the design; otherwise implement directly when picked up.
- [x] ~~Audit and improve existing Phase 2 screens for consistent hierarchy, terminology, empty/
      loading/error states, keyboard/focus behaviour, responsive operation and WCAG 2.2 AA; test
      the critical PO→receipt→QA and inventory-drill-down journeys~~ — keyboard/focus/WCAG fixes
      landed in the UI audit pass; the Playwright suite now covers the critical accessibility
      checks and the full PO→receipt→QA→availability journey (`18 passed`, verified locally on
      2026-09-22).
- [x] First real deployment to the production VMs (deferred from Phase 0) — done 2026-09-22,
      ahead of the rest of Phase 2 at the user's request. Runbook and the gaps found while
      executing it: [deployment.md](../architecture/deployment.md#first-deploy-runbook).
- [x] ~~Production-safe permission registration~~ — the application permission vocabulary now
      lives in `App\Support\PermissionRegistry`; `permission:register` is safe to run in any
      environment and idempotently registers it, while `erp:create-admin --permissions=all`
      registers before assigning. Fresh-database and repeat-registration behaviour are covered by
      `PermissionRegistrationTest`; the deployment runbook no longer needs an ad hoc tinker call.
- [ ] **Carried forward → operations.** Deploy automation — the self-hosted production job is now implemented after the image
      manifest: it will pull from GHCR with the workflow token, run migrations, recreate the
      Compose services, register permissions and check both public endpoints. The first release
      was manually verified against the real VMs; the first automated `main` run remains to be
      verified.
- [ ] **Carried forward → operations.** mainvm's persistent GHCR pull credential can be removed after the automated job succeeds —
      the job authenticates with its short-lived workflow token. Keep the manual credential as
      emergency fallback until that verification is complete.
- [x] ~~Second production database check-in: re-confirm dbvm's `REDIS_DB=3`/`REDIS_CACHE_DB=4`
      assignment with whoever owns invoiceflow/crm once either of those reaches production~~ —
      not a coordination risk: the user owns all three applications directly and assigns every
      app's `REDIS_DB`/`REDIS_CACHE_DB` themselves, so a future collision is avoided by that
      single owner rather than needing a cross-team re-confirmation step.

Tick these in the same commit as the work.

## Acceptance criteria

- [x] One item can have several approved suppliers but no more than one active preferred supplier;
      supplier-specific SKU/UoM/MOQ/lead time round-trip through the API (`ItemSupplierApiTest`;
      preference uniqueness enforced by the `item_suppliers` partial unique index)
- [x] An issued PO remains historically unchanged after item, supplier, exchange-rate or UoM
      master data changes (snapshotted at issue; commercial data and line snapshots are now also
      database-immutable — `PurchaseOrderApiTest`)
- [x] A partial receipt updates outstanding PO quantity exactly once and creates the matching
      stock move/layer/valuation feed atomically (`GoodsReceiptPostingTest`; outstanding quantity is
      derived from posted rows rather than a mutable counter, so it cannot double-count)
- [x] Exact/above tolerance, indivisible flooring, cumulative partials and hard short-close behave
      per ADR 0043; simultaneous receipts and duplicate posting cannot exceed or duplicate state.
      The tolerance ceiling, one step above it, COUNT flooring (10 + 15% floors to 11, not 12) and
      cumulative partials against one ceiling are all proven, as are short-close and duplicate
      posting (`GoodsReceiptPostingTest`). Two separate processes racing 6-unit receipts against
      the same 10-unit PO line prove the PO header/line lock makes one re-read the other's commit;
      exactly one posts and the loser leaves no inventory state (`GoodsReceiptConcurrencyTest`)
- [x] Partial/repeated QA dispositions cannot allocate more than pending quantity, including under
      concurrent posting, and never mutate the original receipt
- [x] Simultaneous receipts into one AVCO pool produce the mathematically correct quantity/value
      and average under a real PostgreSQL concurrency test; supported scopes resolve uniquely and
      remain tenant-isolated (`AvcoPoolConcurrencyTest`; scope shape/uniqueness is enforced by
      `avco_pools`' checks and partial unique indexes)
- [x] FIFO issues consume the oldest available layers, including an issue spanning multiple layers;
      SPECIFIC issues consume only the named on-hand serial (`StockIssueTest`,
      `StockLayerConsumptionTest`)
- [x] Batch/serial requirements follow `items.tracking`, not `valuation_method`; every receipt and
      issue rejection branch is covered, including the mutually-exclusive serial-with-batch case,
      across AVCO/FIFO independently (`StockIdentityTrackingTest`)
- [x] Same-pool transfers leave pool value/average unchanged; cross-pool transfers carry source
      AVCO into the destination, recalculate destination AVCO and preserve total company quantity
      and value; a frozen source pool's rate is final and never later repaired (ADR 0045)
      (`StockIssueTest`; ADR 0045 finality proven by `StockPoolRecoveryTest`)
- [x] Negative-stock and backdated-receipt behaviour exactly matches the accepted Q13/Q14 ADRs.
      Q13's block/authorised-override, exact identity, approval/review, provisional valuation and
      shortage settlement paths are covered by `NegativeStockOverrideWorkflowTest`,
      `StockIssueTest` and `StockPoolRecoveryTest`; Q14's horizon, preview, exact-version approval,
      staleness, risk routing and deterministic receipt/issue/transfer execution are covered by
      `BackdatingHorizonEvaluatorTest`, `RequestStockRecalculationTest`,
      `RecalculationApprovalAuditTest`, `ExecuteRecalculationTest` and
      `ExecuteOutboundRecalculationTest`.
- [x] A finalised inventory cutover reconciles exact locations, each target AVCO pool and company
      totals; continues or explicitly converts source valuation scope without rewriting history;
      and establishes valid AVCO/FIFO/SPECIFIC state without weakening Q13/Q14 controls
      (`InventoryCutoverWorkflowTest`, `InventoryCutoverOpeningStatePostingTest`, ADR 0046)
- [x] An item's valuation method, AVCO pool scope and stock UoM cannot be changed normally after
      its first move; all three API rejection paths are proven after a real receipt, and historical
      valuation entries retain their original immutable pool identity (`GoodsReceiptPostingTest`,
      `StockLedgerIntegrityTest`)
- [x] A negative location does not freeze a positive wider AVCO pool; each configured pool freezes
      and recovers independently when its own quantity becomes non-positive (`StockPoolRecoveryTest`;
      freezing is decided per pool row in `AvcoPoolManager`)
- [x] Authorised backdated replay follows affected configured AVCO pools and real cross-pool
      dependencies while excluding unrelated pools (`MovementReplayScope`;
      `OutboundRecalculationReviewTest`, `OutboundRecalculationTest`, `ExecuteRecalculationTest`)
- [ ] **Carried forward → Phase 6 (valuation policy).** Horizon eligibility uses the configured period/floor mode, snapshots trusted rolling context,
      cannot cross stricter cutover/period/policy boundaries and routes outside-horizon differences
      to the explicit correction workflow without falsifying physical dates. Mode/floor/timezone,
      the request-time snapshot and the **cutover** boundary are all proven
      (`BackdatingHorizonEvaluatorTest`); the valuation-policy boundary is still uncomposed because
      that subsystem does not exist yet
- [ ] **Carried forward → Phase 4–6 correction package.** A backdated correction package is idempotent, preserves approved historical FX/deltas,
      allocates its economic lines without changing physical quantity, reconciles GL representation
      without double counting, reconciles Tax/integration/ancillary obligations on their own bases,
      and cannot finish with an unexplained residual or unresolved required obligation
- [ ] **Carried forward → Phase 4–6 correction package.** Retrying after a correction commit/response timeout resolves the unique existing package and
      never duplicates value; an unlinked manual journal cannot resolve its Accounting obligation
- [ ] **Carried forward → Phase 4–6 correction package.** A failure by any synchronous implemented participant rolls the declared internal transaction
      back in full; only deliberately deferred/asynchronous/external obligations may remain pending
- [x] A posted physical count creates auditable variance moves and cannot be posted twice
- [x] Cross-company access and raw cross-company foreign-key writes fail for every new table
      (`CompanyScopedForeignKeyTest` structurally, per-table scope triggers for every bare
      `locations` reference — the last missing one, `recalculation_requests`, closed by the audit)
- [x] Full Pest/Pint/Larastan/frontend/codegen pipeline passes on PostgreSQL 16
- [x] A user can complete the Phase 2 operational journeys without direct API/database access:
      understand current inventory, create and issue a PO, receive it, resolve required QA and
      inspect the resulting availability/ledger trail on desktop and narrow screens. Critical
      actions are keyboard accessible, permission/status gated and recover clearly from validation
      errors. Every screen this journey touches now exists (inventory overview was the last
      screen-level gap); the formal keyboard/focus/WCAG audit and explicit end-to-end journey test
      are covered by the committed Playwright accessibility and journey suites (`18 passed`,
      verified locally on 2026-09-22).

## Docs to update on completion

- [x] [STATUS.md](../STATUS.md) — reconcile current Phase 2 status; final Phase 2 closure and
      moving `Next` to Phase 3 remain pending the deferred correction-package decision.
- [x] ~~`docs/domain/purchasing.md` — reconcile the implemented document/API contract~~ —
      receipt/QA immutability, supplier sites, reversal semantics and operational UI/API boundaries
      are reconciled.
- [x] ~~[stock.md](../domain/stock.md) — reconcile tables, valuation and movement policies~~ —
      AVCO/FIFO/SPECIFIC movement, QA availability enforcement, transfers, replay and policy
      boundaries are reconciled.
- [x] ~~[items.md](../domain/items.md) and [parties.md](../domain/parties.md) — remove the Phase 1
      `default_supplier_id` bridge and record supplier-catalog/stock-lock behaviour~~ — the
      migration, catalogue relationship and supplier-site API/UI are documented.
- [x] ~~Inventory and Purchasing module READMEs~~ — current ownership, Actions, cutover,
      movement and policy boundaries are documented.
- [x] ~~New ADRs for every Phase 2 decision, especially Q13/Q14~~ — Q13/Q14 and subsequent
      inventory/purchasing governance decisions are recorded in ADRs 0037 and 0040–0047.

## Related

- [stock.md](../domain/stock.md)
- [purchasing.md](../domain/purchasing.md) ·
  [acquisition-costing.md](../domain/acquisition-costing.md)
- [items.md](../domain/items.md) · [parties.md](../domain/parties.md)
- [ADR 0032](../decisions/0032-per-item-valuation-method.md)
- [ADR 0033](../decisions/0033-item-suppliers-relationship.md)
- [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md)
- [ADR 0036](../decisions/0036-controlled-inventory-cutover.md) ·
  [inventory-cutover.md](../domain/inventory-cutover.md)
- [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)
- [physical-count.md](../domain/physical-count.md)
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md)
- [ADR 0043](../decisions/0043-control-purchase-order-receipt-and-qa-posting.md)
- [ADR 0047](../decisions/0047-govern-inventory-structure-and-default-valuation-policies.md)
- [multi-currency.md](../domain/multi-currency.md) ·
  [units-of-measure.md](../domain/units-of-measure.md)
- [database.md](../architecture/database.md) ·
  [multi-tenancy.md](../architecture/multi-tenancy.md)
