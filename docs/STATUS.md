# Project Status

**Updated:** 2026-09-29
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
| **Research-before-design is now an explicit repository rule, with a reuse-first stop condition** — agents must first follow research already preserved in the relevant ADR/domain docs; only genuinely new or materially changed ERP behaviour triggers a bounded comparison of mature products using primary sources. The durable synthesis, differences and applicability are recorded once before implementation, preventing both unsupported invention and repeated browsing while keeping product decisions local to this ERP → [research-and-design.md](architecture/research-and-design.md) | 2026-09-28 |
| **Access Administration makes the permission engine operable without server/database access** — permitted administrators can invite and retain/suspend members, maintain company roles, assign additive roles/direct exceptions and inspect effective access; protected `administration.*` capabilities, reasoned audit evidence and anti-escalation/self-lockout guards keep that authority bounded. Invitation acceptance uses a single-use set-password link, and production rollout now names its frontend-URL/mail prerequisites → [ADR 0062](decisions/0062-company-member-and-access-administration.md), [access-administration.md](domain/access-administration.md) | 2026-09-29 |
| **Items and Purchasing authorisation retrofit — operational writes are no longer authenticated-only** — Items/Categories, supplier relationships, PO draft/issue/cancel/short-close, receipt create/post/reverse and QA dispositions now have registered capabilities, backend enforcement, matching SPA control gating and allowed/denied API plus non-HTTP tests; shared reference reads remain open, while sensitive cost/GL field projection stays as the separately tracked next step. Rollout is gated on administrator sync plus explicit grants for every other operational user/role → [authorization-retrofit.md](architecture/authorization-retrofit.md) | 2026-09-28 |
| **The dev app no longer loads ~1,300 empty modules on every page load** — asked to look at why the machine (and the dev app in particular) had become unusably slow, measurement showed sustained disk-I/O stalls rather than CPU or memory, and in the repo a real multiplier: orval's models barrel `export *`s 1,363 schema files, which a browser cannot skip, though 1,281 are pure types compiling to empty modules — ~1,500 requests per cold dev load. A post-generation hook now rewrites the barrel to `export type *` for pure-type schemas (erased at build time) and keeps `export *` only for the 82 that export runtime values: a cold load makes 352 requests instead of ~1,500 and the production build transforms 2,122 modules instead of 3,396, with the importable names unchanged (regeneration changes only the barrel; all typechecks pass). The dev server is still slow per request on this machine, which is below the repo → [api-conventions.md](architecture/api-conventions.md#generated-client) | 2026-09-28 |
| **A failed list now says why, at once, and can be retried (Q31 resolved, ADR 0061)** — measured rather than assumed: the tables already had an error state, but every failure, even a definitive 403 or 422, was retried three times, so it sat behind ~10 s of loading skeletons (four identical requests, a "0 records" footer) and then showed one message for every cause. Now only transient failures (network, 408, 429, 5xx) are retried, twice, via the query client; a shared `ListError` in the table, card and cursor views names the cause and offers "Try again"; and all 19 list screens pass `error`/`onRetry`. The earlier description of this as "invisible" was wrong and is corrected. Pickers and lookups that fail still read as empty (Q32) → [ADR 0061](decisions/0061-retry-only-transient-failures-and-say-why-a-list-failed.md), [ui-building-blocks.md](architecture/ui-building-blocks.md) | 2026-09-28 |
| **A purchase order line starts on the unit the supplier sells the item in** — ADR 0033 keeps the purchase unit on the item-supplier row because it varies per item and supplier, but the PO form ignored it and started every line on the item's stock unit, so an item bought by the roll had to be switched by hand each time. Choosing a line's item now defaults its Purchase UoM from the PO supplier's active row (falling back to the stock unit), read once per supplier and applied only at item choice — it stays editable, and changing the supplier never rewrites lines already entered. Proven in the browser (the old behaviour started the tape on "m — Metre"); the demo's masking tape and sanding discs now start on Roll and Box at Industrial Coatings, and stale text saying pack purchasing was unimplemented is corrected → [purchasing.md](domain/purchasing.md#purchase-unit-default), [ADR 0033](decisions/0033-item-suppliers-relationship.md) | 2026-09-28 |
| **Boolean list filters work — the Warehouses and Item Suppliers status filters were silently returning nothing (Q29 resolved, ADR 0060)** — the generated client sends `filter[active]=true`, which Laravel's `boolean` rule rejects with a 422, and a list screen renders a failed request as an empty list, so choosing "Active" showed "0 records". `ValidatesListQuery` now accepts `true`/`false` for any `filter.*` key its own request declares boolean (a search for the word "true" and a value like `yes` are untouched), covering six endpoints in one place; the three screens that had dodged it by filtering client-side filter on the server again. Proven both ways at the API (7 tests fail without the change) and in the browser, where the spec asserts the request's own status because two of the affected screens hide a failure differently. It also prompted a look at how a failed list looks (Q31, resolved separately — it turned out to be late and vague, not invisible) → [ADR 0060](decisions/0060-accept-true-and-false-for-boolean-list-filters.md), [api-conventions.md](architecture/api-conventions.md#filtering-and-sorting) | 2026-09-28 |
| **Locations are named warehouse / location everywhere (Q30 resolved, ADR 0059)** — a location code is unique only per warehouse, so about a dozen screens showed every transfer between the two demo warehouses as "stock → stock". `Location` now always eager-loads its warehouse (one extra query per result set, proven not to grow with page size) and one shared `formatLocation`/`formatLocationOption` names it at every site; the stock-moves API already sent the warehouse and its screen ignored it, while the layer, count-line, recalculation, override and cutover endpoints did not. Tested both ways (each test fails without the change) and pinned by an e2e spec that reads the old defect back as `stockstock` → [ADR 0059](decisions/0059-name-a-location-as-warehouse-and-location.md), [stock.md](domain/stock.md) | 2026-09-28 |
| **Recalculation and stock-transfer demo data — every later state and every way a transfer treats value** — `RecalculationLifecycleDemoSeeder` adds an AVCO request executed (pool converged with a valuation adjustment), a FIFO request executed (a cheaper layer reordered, so the later issue is corrected by append-only reversal and replacement), one rejected and one awaiting its first decision, beside StockDemoSeeder's own. `StockTransfersDemoSeeder` adds a same-pool AVCO transfer, a cross-pool AVCO transfer (value at the source average, destination re-averaged, company value conserved) and a FIFO transfer whose layers keep their cost and age. All are create-once on dedicated items via a new shared `SeedsDedicatedStockItems` concern, mutation-checked, with an e2e spec each for the two derived-status lists. Seeding transfers showed the ledger reading "stock → stock" (Q30); the older seeded recalculation's approval has also expired, so it shows as pending review again. A cutover negative-opening exception is not seeded: only one non-failed cutover is allowed per company and the existing one is already approved, so it could appear on a fresh database only → [stock-recalculation.md](domain/stock-recalculation.md#user-interface), [stock.md](domain/stock.md#transfers), [environments.md](architecture/environments.md#development-demo-data) | 2026-09-28 |
| **Negative-stock lifecycle demo data — a record in every later state, plus a real list bug it exposed** — `NegativeStockLifecycleDemoSeeder` adds, on dedicated items and through the app's own Actions, a rejected and an approved-and-posted prior-approval request (leaving an `OPEN` shortage), an immediate override reviewed `JUSTIFIED` and `PARTIALLY_SETTLED` at a higher actual cost, and one reviewed `FLAGGED` and fully `SETTLED`, beside StockDemoSeeder's two deliberately undecided requests. Each scenario is create-once and skipped by natural key, so a receipt settling one by hand never breaks a rerun; the tests were mutation-checked. Having decided requests to look at exposed that the override list asked for `item` only, so its status column showed every request as pending — fixed by also requesting `approval` and `review`, pinned by a new e2e spec that fails without it. Recalculation execution, the cutover negative-opening exception and stock transfers still have no later-state demo data → [negative-stock.md](domain/negative-stock.md#user-interface), [environments.md](architecture/environments.md#development-demo-data) | 2026-09-28 |
| **Pack Conversions demo data — one scenario per decision point of ADR 0050's lookup** — `PackConversionsDemoSeeder` (plus five COUNT pack units, two new items and two pack-unit item-supplier rows) shows the same `bag` holding 25 kg vs 20 kg by item, two pack levels each stored directly against litres, a roll crossing `COUNT` → `LENGTH`, and a box that must override the global 1:1 for a same-dimension item; thinner in millilitres shows the global fallback and blue pigment in drums the loud rejection. `PackConversionsDemoSeederTest` proves each through the real purchase-order-line conversion (and was mutation-checked), rather than reading the rows back. At that point the PO form still started a line on the item's stock unit rather than the supplier's purchase unit (since fixed — see the purchase-unit-default row) → [units-of-measure.md](domain/units-of-measure.md#item-specific-conversions), [environments.md](architecture/environments.md#development-demo-data) | 2026-09-28 |
| **Material Preview — inventory proposal slice shipped, quantity-only** — the preview now adds advisory stock availability for callers holding `estimating.inventory_availability.view`: usable stock per location/batch in one consistent read (QA hold attributed to its layer, quarantine/scrap/transit/etc. reported as not counted), a deterministic ADR 0053 plan per preset with the other two as alternatives, shortage, and cost fields still behind `estimating.internal_cost.view`. `/material-preview` gained warehouse/location narrowing and the strategy under Advanced options; driving the real screen also caught a 422 on `filter[active]=true` (Laravel's boolean rule rejects the serialised string) that unit tests could not, fixed by filtering client-side like the physical-count form. Stored strategy settings, hand-built mixes and package-level tracking are deliberately not built → [inventory-packages.md](domain/inventory-packages.md#first-implementation-quantity_only-availability-and-allocation), [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) | 2026-09-28 |
| **Production admin permission drift fixed — deploy now keeps the administrator on the full vocabulary** — the live admin, created once via `erp:create-admin --permissions=all` at first deploy (2026-09-22), never received any permission shipped by a later feature slice, because the deploy job's `permission:register` only creates new `Permission` rows, it grants them to nobody; `--permissions=all` was always a creation-time snapshot, not a standing wildcard. Confirmed as the cause of the live admin missing access to the newly-shipped Material Preview screen. New `erp:sync-admin-permissions {email}` command re-grants the full current registry to a named administrator and is safe to re-run every deploy; the pipeline now runs it right after `permission:register`, gated on a new `ERP_ADMIN_EMAIL` repository variable (skips with a warning, not a failed deploy, if unset — the variable is now set to the production administrator's email, so it takes effect on the first deploy that includes this change). Local dev never hit this because `StockDemoSeeder` already re-syncs the demo login's permissions on every `demo:seed` run → [authorization.md](architecture/authorization.md#safe-rollout-rule), [deployment.md](architecture/deployment.md#first-deploy-runbook) | 2026-09-26 |
| **Material Preview — technical slice shipped, first client-side permission gating in the app** — `POST /api/v1/assemblies/{assembly}/material-preview` resolves an Assembly's leaf material requirement for a hypothetical work quantity/UoM/customer/dimension context: the winning application-rate row (not the stored family anchor), nested formulation expansion, and per-unit cost/sell gated behind its own `estimating.internal_cost.view` separate from `estimating.material_preview.run` to run it at all. `GET /api/v1/user` now returns the caller's own effective permissions, and the new `/material-preview` screen (Estimating nav) is the first in the app to gate a control on them client-side rather than just reacting to a 403. Inventory/package proposals, planned-quantity variance and broader frontend permission gating remain later slices of the same ADR 0058 contract. Picked up and finished after the implementing session hit its usage limit mid-task (uncommitted: routes, permission checks, generated client, tests all present and passing; the screen simply wasn't wired into the router/nav yet, and its comboboxes had no `aria-label`, so the WCAG-required label association was missing — both fixed here) → [phase-3-estimating-core.md](phases/phase-3-estimating-core.md) | 2026-09-26 |
| **Authorisation retrofit audit — Items/Categories, Purchase Orders/Receipts, existing Estimating CRUD** — a read-only audit (no code changed) of every list endpoint's filters/sorts/includes against its screen's actual controls, every write path's permission check, and every Resource's field-level exposure, run in parallel with the Material Preview slice. Confirms `OverheadPolicy` is currently the only resource in these three areas with real capability permissions; everything else is authenticated-only, including core Purchase Order/Goods Receipt create/issue/post/reverse. Found concrete unprotected sensitive fields (Item's GL account columns, PO/receipt line cost fields, `ResourceRate.rate`) and named reference/lookup data (Items, Categories, Formulations, Resource Rates) as a blast-radius trap — field-level protection, not an index/show permission, is the right fix there. Recorded as a tracked, risk-ordered task checklist rather than prose so progress is visible as retrofit commits land → [authorization-retrofit.md](architecture/authorization-retrofit.md) | 2026-09-26 |
| **Material Preview what-if and configurable variance control — the ADR 0054 slice is complete** — companies now define their own variance reasons and versioned item/category/company rules of typed bands (over/under, as a quantity, a percentage or a cost impact; the strongest matching effect wins) on Settings → Material variance, and the Material Preview lets a user plan a different quantity per material, see it judged against the standard with the conditions that matched or were skipped, and see whether the plan could be adopted. The stock proposal follows the planned amount. Nothing is saved or reserved. `MaterialVarianceDemoSeeder` supplies an example paint-company setup (demo only, three tiers proven through the real resolver). Deliberately not built and tracked in [phase-3-estimating-core.md](phases/phase-3-estimating-core.md#deferred-and-blocked-work): approval-required rules (shared approval workflow, Q27), waste/residual bands (package tracking, ADR 0052), adoption on a quotation revision (Phase 4) → [material-resolution.md](domain/material-resolution.md) | 2026-09-29 |

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
0 blocking, 9 non-blocking.

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
