# Project Status

**Updated:** 2026-09-08
**Current phase:** Phase 2 — Purchasing & stock (in progress). Phase 1 — Master data is
complete. Phase 0 — Foundation is done except the deliberately deferred production deployment.
**Application code:** Laravel 12 backend — Phase 0 foundations (auth, scoping, UoM, custom fields,
sequences, currencies, Scramble→Orval), Phase 1 master-data APIs, and Phase 2 purchasing/stock:
purchase orders, receipts, QA/reversal and the full movement/valuation engine. ADR 0037 negative
stock and ADR 0040 backdated recalculation are complete for every executable receipt/issue/transfer
case across all valuation methods; only the Phase 4/5/6 multi-owner correction package remains.
ADR 0036 inventory cutover now posts a real reconciled opening state (AVCO/FIFO/SPECIFIC) including
ADR 0037's cutover-shortage negative-opening exception. Physical-count documents (count/recount,
posted variance through the ordinary movement boundary) are also complete, and all three now have a
first read/write API and UI screen — physical counts and inventory cutover with a full multi-actor
document workflow, the stock ledger read-only. React 19 + Vite frontend skeleton with a generated
client covering every implemented endpoint.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **Inventory cutover HTTP API and lifecycle screens** — the header is create/read-only over HTTP; every state change is a named action (`.../batches`, `.../validate`, `.../approve`, `.../commit`, `.../finalise`, plus `.../negative-openings` and its own approve route for ADR 0037's cutover-shortage exception). `GET .../preview` is read-only. The `/inventory-cutovers` list, create and `/inventory-cutovers/:id` detail screens are the frontend's first multi-actor approval lifecycle UI, action buttons gated by status; "attach batch" and the exception form's "responsible owner" take raw numeric IDs since Imports/Users have no picker endpoints yet → [inventory-cutover.md](domain/inventory-cutover.md#user-interface) | 2026-09-08 |
| **Physical count HTTP API and workflow screens** — the header is create/read-only over HTTP; every state change is a named action (`POST .../lines`, `POST /api/v1/physical-count-lines/{id}/count`, `.../post`, `.../cancel`). The `/physical-counts` list, create and `/physical-counts/:id` detail screens are the frontend's first document-workflow UI — add a line, record/recount inline, supply a found cost for a positive variance, then post or cancel, verified end-to-end including separation-of-duties rejection → [physical-count.md](domain/physical-count.md#user-interface) | 2026-09-08 |
| **Stock ledger read API and Stock Ledger screen** — `stock_moves` gained its first HTTP surface: a cursor-paginated `GET /api/v1/stock-moves` (filterable by item/warehouse/location/batch/source type/date range) and a `show` endpoint, both including `item`/locations/`batch`/`valuation_entries` on request. A new `/stock-moves` list screen and `/stock-moves/:id` detail screen consume it — the first UI built against Phase 2's stock engine, and the first cursor-paginated list in the frontend (`CursorDataTable`, `useCursorQueryState`). `stock_layers`/`avco_pools` still have no endpoint of their own → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md#close-out) | 2026-09-08 |
| **Phase 2 integrity audit** — three real gaps found and fixed: the stock ledger (`stock_moves`/`stock_valuation_entries`/`stock_layers`) and purchasing documents were model-guarded only and are now database-immutable like every other Phase 2 history table; `recalculation_requests` was the one bare location reference with no company trigger; and the "two concurrent AVCO receipts" acceptance criterion had the lock but no proof — now `AvcoPoolConcurrencyTest`. Rollback proven for physical-count and cutover posting too. Duplicate-submission, precision and tracking had no gaps → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md) | 2026-09-08 |
| **Items frontend reference implementation** — reusable server-driven list/detail/print and form-error foundations now power item list, detail, create and edit routes; an authenticated company-scoped read-only UoM API supplies the item form without hardcoded units. The generated client includes validated page parameters, all frontend type-checks/build and focused Item/UoM API tests pass → [items.md](domain/items.md) | 2026-09-08 |
| **Warehouse frontend workflow** — warehouse list, detail, create and edit routes now reuse the Items table/form/print foundation, including URL-backed search/status filters and inline API errors. Creation collects the mandatory initial location atomically while updates remain warehouse-only, preserving SIMPLE/STRUCTURED location rules → [stock.md](domain/stock.md#progressive-warehouse-structure) | 2026-09-08 |
| **Category and item-supplier frontend workflows** — list/detail/create/edit routes now cover category hierarchy/inherited valuation and GL defaults plus approved supplier sourcing terms. Item-supplier identity is selectable only on create and locked on edit per ADR 0042; URL-backed filters and quick print reuse the shared foundation → [items.md](domain/items.md#user-interface), [purchasing.md](domain/purchasing.md#supplier-sourcing-identity) | 2026-09-08 |
| **Login feedback and password visibility** — failed login now shows the API's specific credential validation message instead of a generic HTTP 422 error, and the password input has an accessible show/hide toggle. The development account was updated operationally (not seeded or committed) and verified against Laravel authentication → [phase-0-foundation.md](phases/phase-0-foundation.md#frontend-skeleton) | 2026-09-08 |

## Next

ADR 0040's executable-today scope is done — every backdated receipt/issue/transfer case across all
three valuation methods now previews, approves and executes, clean or changed-history alike. The
multi-owner correction package (Sales/Job Execution/Accounting routing, cross-module
reconciliation) waits on those modules existing — Phase 4/5/6, tracked at each phase's entry in
[phases/README.md](phases/README.md). Phase 2's backend work is complete and audited; what remains
is its close-out, itemised under "Close-out" in
[Phase 2 — Purchasing & stock](phases/phase-2-purchasing-stock.md):

1. HTTP APIs for the two feature areas still Action-only (stock recalculation, negative-stock
   overrides) — each built when its first screen is designed, not before; stock ledger reads,
   physical counts and inventory cutover shipped first. The exact Action list to expose is in
   that Close-out section so nothing gets missed.
2. Further UI screens for the stock engine, against those endpoints as each lands.
3. First real deployment to the production VMs.

## Blocked / awaiting input

Nothing blocking. Open questions: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 3
non-blocking (second customer profile; cross-entity master data; movement posting actor).

## Roadmap at a glance

| Phase | Name | Status |
|---|---|---|
| 0 | [Foundation](phases/phase-0-foundation.md) | In progress (only production deployment left, deliberately deferred) |
| 1 | [Master data](phases/phase-1-master-data.md) | Complete |
| 2 | [Purchasing & stock](phases/phase-2-purchasing-stock.md) | In progress |
| 3 | Estimating core | Not started |
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
