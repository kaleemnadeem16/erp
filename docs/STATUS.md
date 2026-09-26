# Project Status

**Updated:** 2026-09-26
**Current phase:** Phase 3 — Estimating core (in progress; two vertical slices shipped
2026-09-23 — rate dimensions/application rates, then formulations with versioning, customer
overrides and nested cost roll-up, now followed by the 2026-09-24 draft-editing, human-readable
history, unit-defaulting and item-navigation usability pass. Before starting Assemblies, a genericity review on
2026-09-24 caught and corrected a real structural error in the overhead-policy design —
[ADR 0049](decisions/0049-assembly-group-overhead-scope.md), superseding
[ADR 0048](decisions/0048-configurable-hierarchical-overhead-allocation.md). All three steps of
the resulting Assembly plan — foundation, overhead resolver, then the remaining
assembly_versions/assembly_components cost roll-up — are now shipped (2026-09-24/25); the
originally-scoped ad-hoc **assembly cost preview** (live application-rate resolution with
default-fallback visibility) remains open. Its cross-module quantity/sourcing/pack/cost/price
baseline is now recorded in [material-resolution.md](domain/material-resolution.md). Q20 is
resolved ([ADR 0050](decisions/0050-item-specific-uom-conversion-and-divisibility.md),
2026-09-25); Q17–Q22 and Q25's Phase 3 boundary decisions are resolved by accepted ADRs
0050–0055 and 0057; [ADR 0058](decisions/0058-phase-3-material-resolution-preview-contract.md)
now finalises the read-only preview contract; see
[phase-3-estimating-core.md](phases/phase-3-estimating-core.md). Phase 2's remaining
acceptance criteria depend on later phases' correction-package work and are picked up alongside
Phase 3; UI polish on existing Phase 1/2 screens continues in parallel. Phase 1 — Master data is
complete. Phase 0 — Foundation is done, including production deployment (2026-09-22, ahead of
the rest of Phase 2 at the user's request).
**Application code:** Laravel 12 backend with Phase 0 foundations, Phase 1 master-data APIs and
Phase 2 purchase orders, receipts, QA/reversal and the full stock movement/valuation engine.
Inventory cutover now posts and strictly reconciles AVCO/FIFO/SPECIFIC opening state; controlled
negative openings and staged open purchase-order commitments, live negative-stock overrides,
backdated recalculation and physical counts are also complete. Each workflow has a read/write API
and React UI; the stock ledger, layers and AVCO
pools have read surfaces. The React 19 + Vite frontend uses the generated client for every
implemented endpoint. Only the later Phase 4/5/6 multi-owner correction package remains.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **Material-preview permission foundation — module-owned registration replaces the central vocabulary** — Inventory, Purchasing and Estimating now contribute owned permission lists through their service providers; the registry validates names/duplicates and still performs one idempotent company-team registration. ADR 0057's capabilities are registered, and the explicit retrofit plan now prevents already-built authenticated-only CRUD from being forgotten or switched unsafely without assignments, backend/UI enforcement and tests → [authorization.md](architecture/authorization.md#incremental-retrofit-of-already-built-features), [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) | 2026-09-26 |
| **ADR 0050 implemented — item-specific UoM conversion and divisibility, closing the live Phase 2 gap it named** — `item_quantity_units` (per-item, per-UoM pack factor, may cross UoM dimension) and `items.quantity_precision` now exist, backed by `ItemUomConversionService` (item-specific override resolves first, falling back to the global, dimension-locked `UomConversionService` only when none exists) and a CRUD API (`/api/v1/item-quantity-units`, identity create-only like `ItemSupplier`). `BuildPurchaseOrderLineAttributes` now calls it instead of the global service directly, so box/each and cross-dimension pack purchase UoMs (e.g. a roll priced by the metre) actually convert — proved against ADR 0050's own roll↔metre worked example. `PostGoodsReceipt`'s over-receipt tolerance ceiling now floors to an item's explicit `quantity_precision` instead of inferring wholeness from a `COUNT` dimension; a behaviour change flagged deliberately, not silently: a `COUNT` item with no explicit precision set no longer auto-floors, matching the ADR's own critique of the old dimension-inferred rule. No UI yet — API/backend only, following the ADR's own "Requires" list, which named no screen → [units-of-measure.md](domain/units-of-measure.md#item-specific-conversions), [purchasing.md](domain/purchasing.md) | 2026-09-26 |
| **Cutover negative-opening exceptions resolve item and location instead of raw IDs** — `inventory-cutover-detail-screen.tsx`'s exceptions table rendered a bare `location_id`; `item` resolution sat right next to it but was equally dead, since neither `InventoryCutoverController::show()` nor `InventoryCutoverNegativeOpening` ever eager-loaded either relation, so `whenLoaded()` silently omitted both regardless of the frontend's `include` query. Added a `location()` relation and gave the model `$with = ['item', 'location']` rather than special-casing the controller's include handling, which was tried first and found to trip Scramble into over-broadly marking the whole `negative_openings` array required on the show endpoint (caught by diffing the generated OpenAPI spec) → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md) | 2026-09-26 |
| **ADRs 0051–0058 — the Material Resolution / Assembly Preview contract is complete** — Phase 3 will compose technical resolution, hypothetical planning, advisory internal-stock/package selection, variance evaluation and permission-filtered cost/availability in one read-only preview; it stores no temporary draft, reservation or approval. Phase 4 revalidates/persists quotation adoption, while Purchasing, Sales pricing, tax and Phase 5 execution remain separate → [ADR 0058](decisions/0058-phase-3-material-resolution-preview-contract.md), [material-resolution.md](domain/material-resolution.md), [authorization.md](architecture/authorization.md) | 2026-09-26 |
| **ADR 0050 — item-specific UoM conversions decided; Q20 resolved, Q17 narrowed** — researched how SAP, Oracle EBS/Fusion SCM, NetSuite, Business Central and GS1 model item-specific unit conversions, divisibility and catch weight, then locked down the parts the research made clear: divisibility is always an item-and-UoM rounding-precision rule, never inferred from `dimension`; item-specific pack conversions (`item_quantity_units`) resolve before falling back to the existing global `UomConversionService`, and may deliberately cross UoM dimensions (roll↔metre, sheet↔m²) where that service may not. Also surfaced a live Phase 2 gap this closes conceptually: `BuildPurchaseOrderLineAttributes` still only calls the global, dimension-locked service, so box/each-style purchase UoM conversion doesn't actually work today despite `item_suppliers.purchase_uom_id` existing since ADR 0033 — not yet implemented, shape only. Catch weight ([Q23](OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages)) stayed separate; the then-open commercial-package boundary is now resolved by ADRs 0051–0053 → [ADR 0050](decisions/0050-item-specific-uom-conversion-and-divisibility.md), [material-resolution.md](domain/material-resolution.md), [units-of-measure.md](domain/units-of-measure.md) | 2026-09-25 |
| **Permission registry hardening closes the audited registration gap** — `PermissionRegistry` was missing four permissions already enforced in code (`inventory.negative-stock-policy.manage`, `inventory.backdating-horizon-policy.manage`, `inventory.recalculation-policy.manage`, `purchasing.cutover.commit`), silently denying everyone until `permission:register` happened to be re-run after a code change added the check. Now registered, and a new `tests/Architecture/PermissionCheckRegistrationTest.php` scans every `->can()`/`->authorize()`/`Gate::*` call site in `app/` (including the one local-variable indirection the codebase actually uses) and fails if any literal permission name it finds is unregistered, so the gap can't reopen silently → [authorization.md](architecture/authorization.md#registration-and-maintenance-standard) | 2026-09-25 |
| **Full demo seeding is idempotent across live-stock drift** — `StockDemoSeeder` now recognises its create-once negative-stock override requests by natural source identity and never recalculates immutable historical payloads from current stock. A regression test posts a real intervening receipt, proves both request rows remain byte-identical across the next full `demo:seed`, and confirms the final Estimating demo seeder still runs → [environments.md](architecture/environments.md#immutable-transactional-demo-data-create-once-never-reconcile) | 2026-09-24 |
| **Step 3 — the remaining Assembly slice: components, resource rates and the five-part cost roll-up** — `assembly_versions`/`assembly_components` (same DRAFT/ACTIVE/SUPERSEDED + exclusion-constraint shape as `formulation_versions`/`formulation_lines`), four stored component kinds (`MATERIAL`/`LABOUR`/`PLANT`/`SUBCONTRACT`, still no stored `OVERHEAD` row). `ExplodeAssemblyVersion` computes material/labour/plant/subcontract from components alone, *then* resolves and computes overhead from those four figures — never the reverse, so overhead can never land inside its own base; `AssemblyCostRollUpTest` hand-verifies every driver's basis, MATERIAL-only wastage/multiplier, margin-vs-markup (docs/domain/assemblies.md's own 100→120/125 worked example), a nested-formulation and an application-rate-priced MATERIAL component, and a rejected incompatible-UoM pairing. A real gap surfaced mid-build: `items` has no rate column and a non-stocked LABOUR/PLANT item has no AVCO pool, so nothing anywhere could price one — closed with `resource_rates`, a new company-scoped effective-dated table shaped like `exchange_rates` (dated rows, "most recent on-or-before wins," no update endpoint — a correction is a new row) rather than the heavier versioned-policy lifecycle, since a resource rate is a market fact like AVCO cost, not a governed policy. The cost-breakdown response is fully explainable — which version, which overhead tier/driver resolved, each component's resolved qty/unit-cost/source (AVCO, a named formulation, a resource rate as of a date, or a manual override), and cost/sell per unit. Demo data deliberately avoids `demo-f-tinted-base` for a MATERIAL `formulation_id` — that seeder's delete-and-recreate idempotency would conflict with a real `assembly_components` FK on every re-seed; that path is covered by the automated test instead. Also found, flagged, and deliberately left alone: `demo-spray-unit` (Phase 2) is a stocked, serial-tracked capital asset wearing the same `PLANT` type a costing *resource* now also uses — two different concepts sharing one type, not fixed here → [items.md](domain/items.md#resource-rates), [assemblies.md](domain/assemblies.md#cost-roll-up), [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) | 2026-09-25 |

## Next

The new [product journeys](journeys/README.md) layer records the implemented Phase 2 flow from
login and master-data setup through PO, receipt, QA, stock visibility and controlled exceptions.
Keep it current whenever a workflow screen, status or consequence changes; later phases add their
own journeys.

Phase 2's first stock-engine UI/API slice is complete, and every screen the operational UI journey
needs now exists — settings screens and the inventory overview were the last two. Acceptance
criteria are now 18 of 22 satisfied: the formal keyboard/focus/WCAG audit and explicit
PO→receipt→QA→availability end-to-end journey both pass in Playwright. Of the four remaining
criteria, three require Phase 4/5/6 correction-package owners and one is the future
valuation-policy boundary.

The first production deployment is done (2026-09-22) — `main`, `https://erp.x1techs.com` and
`https://api-erp.x1techs.com` are live, TLS/CORS/CSRF/cookie-session login verified end-to-end
against the real domains. Full runbook, and the three real gaps found while running it (dbvm
resolving only as an SSH alias, not inside mainvm's containers; the GHCR images being private
with no working pull credential on mainvm; `erp:create-admin --permissions=all` silently
granting nothing because no permission strings are registered outside the production-blocked
demo seeder) → [deployment.md](architecture/deployment.md#first-deploy-runbook). The current
permission vocabulary is now registered safely by `permission:register`; future modules must
extend the shared registry and its tests when they introduce new permissions. Still open, not
blocking: the first release was manual; the self-hosted deploy job is implemented but its first
automated run is pending. The old personal GHCR credential remains as an emergency fallback. The
second production database check-in is resolved — the user owns invoiceflow and crm as well as
ERP, so `REDIS_DB`/`REDIS_CACHE_DB` collisions are avoided by that single owner directly, not a
cross-team re-confirmation.

## Blocked / awaiting input

The Phase 3 Material Resolution / Assembly Preview has no remaining blocking question and its
consolidated ADR is accepted; implementation is next. [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) —
0 blocking, 8 non-blocking.

## Roadmap at a glance

| Phase | Name | Status |
|---|---|---|
| 0 | [Foundation](phases/phase-0-foundation.md) | Complete (2026-09-22; non-blocking reference-doc and infrastructure follow-ups remain) |
| 1 | [Master data](phases/phase-1-master-data.md) | Complete |
| 2 | [Purchasing & stock](phases/phase-2-purchasing-stock.md) | In progress |
| 3 | Estimating core | In progress |
| 4 | Sales pipeline | Not started |
| 5 | Job execution | Not started |
| 6 | Finance | Not started |
| 7 | Mobile & integrations | Not started |

Detail: [phases/README.md](phases/README.md)

---

## How to update this file

This file is loaded by every agent at the start of every session — its size is a recurring
cost. Rules, per [ADR 0044](decisions/0044-status-log-context-budget.md):

1. **One table row per completed item**: a bold keyword, at most two plain sentences, a link to
   the phase doc/domain doc/ADR that holds the detail, and the date — in the same commit as the
   work. The narrative belongs in the linked doc first; if it doesn't fit there, it doesn't
   belong here either.
2. **Keep at most 8 rows in "Recently done".** When adding a row past that, move the oldest row
   to the bottom of [STATUS-LOG.md](STATUS-LOG.md) verbatim.
3. **Keep this file under ~90 lines.** Move the **Next** section forward when a phase completes.

Session note: development runs in a Remote-WSL VS Code window — run Docker/git natively from the
WSL shell.

Docker note: `docker compose restart <service>` restarts the process inside the existing
container — it does **not** re-read `env_file`. After editing `backend/.env`, use
`docker compose up -d <service>` instead (Compose detects the config diff and recreates the
container); `restart` alone silently keeps serving the old environment values.
