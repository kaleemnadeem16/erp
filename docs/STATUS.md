# Project Status

**Updated:** 2026-09-09
**Current phase:** Phase 2 — Purchasing & stock (in progress). Phase 1 — Master data is
complete. Phase 0 — Foundation is done except the deliberately deferred production deployment.
**Application code:** Laravel 12 backend — Phase 0 foundations (auth, scoping, UoM, custom fields,
sequences, currencies, Scramble→Orval), Phase 1 master-data APIs, and Phase 2 purchasing/stock:
purchase orders, receipts, QA/reversal and the full movement/valuation engine. ADR 0037 negative
stock and ADR 0040 backdated recalculation are complete for every executable receipt/issue/transfer
case across all valuation methods; only the Phase 4/5/6 multi-owner correction package remains.
ADR 0036 inventory cutover now posts a real reconciled opening state (AVCO/FIFO/SPECIFIC) including
ADR 0037's cutover-shortage negative-opening exception, ADR 0037's live negative-stock override
workflow (request, independent decide/review), and ADR 0040's backdated stock recalculation
workflow (request/preview, multi-approver decide, execute, regeneration resumption) are complete
too. Physical-count documents (count/recount, posted variance through the ordinary movement
boundary) are also complete. All five now have a first read/write API and UI screen — physical
counts, inventory cutover, negative-stock overrides and stock recalculation with a full
multi-actor workflow, the stock ledger read-only including its `stock_layers`/`avco_pools`
detail. React 19 + Vite frontend skeleton with a generated client covering every implemented
endpoint.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **Phase 2 missing test proofs closed** — a real forked-process PostgreSQL test now races two receipts against one PO line and proves its cumulative ceiling cannot be exceeded; tracking rejection coverage now spans receipt/issue paths and caught/fixed serial receipts accepting a batch; all three post-first-move item-policy locks are verified through the API. Phase 2 acceptance is now 15/21, with the remaining criteria requiring design or later-module ownership rather than missing tests → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md#acceptance-criteria) | 2026-09-09 |
| **Pre-deployment audit and its fixes** — a full audit before the first deploy found, and this closes: a backdated recalculation could be valuation-effective *before* a finalised inventory cutover (the cutover boundary is now composed into `BackdatingHorizonEvaluator`, and `ExecuteRecalculation` re-evaluates the horizon rather than trusting the request-time snapshot); negative-stock `available_qty` was taken from client input and is now derived from live stock; decimals were documented as JSON numbers on every write path and now cross the wire as strings both ways (one Scramble rule transformer, no validation change); and purchasing's over-receipt policy gained the permission, reason and audit row it never had. Deploy blockers fixed too: the SPA no longer builds pointing at localhost, trusted proxies are configured, and `erp:create-admin` makes a fresh database signable-into → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md), [deployment.md](architecture/deployment.md#first-deploy-runbook) | 2026-09-09 |
| **Stock layer and AVCO pool read APIs** — `GET /api/v1/stock-layers` (cursor-paginated, `filter[open]` for an item's still-open FIFO/SPECIFIC layers) and `GET /api/v1/avco-pools` (page-paginated, an item's current converged balance) close the last gap on the stock-ledger close-out item. Deliberately backend-only — reference data, not a workflow, so no dedicated screen this time. Closes Phase 2's entire close-out checklist except production deployment → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md#close-out) | 2026-09-09 |
| **Stock recalculation HTTP API and screens** — `RequestStockRecalculation` (header, create/read-only, also the preview) plus `POST .../decide`, `.../execute` and `.../resume-regeneration` on `RecalculationImpactVersion`, a distinct addressable child resource since a request accumulates several versions over its life. The `/recalculation-requests` screens are the most involved yet — version history, multi-approver approvals with individual expiry/renewal, risk-tier routing — with computed flags (`is_fully_approved` etc.) added server-side rather than pushed onto the frontend → [stock-recalculation.md](domain/stock-recalculation.md#user-interface) | 2026-09-08 |
| **Negative-stock override HTTP API and screens** — the header (`RequestNegativeStockOverride`) is create/read-only over HTTP; `POST .../decide` and `.../review` are named actions on the same resource (no separate exception resource). The `/negative-stock-overrides` list/create/detail screens are the first UI with no server-side status column — status is derived client-side from `authorisation_mode` plus whether `approval`/`review` exists. `UpdateInventorySettings` was already exposed → [negative-stock.md](domain/negative-stock.md#user-interface) | 2026-09-08 |
| **Inventory cutover HTTP API and lifecycle screens** — the header is create/read-only over HTTP; every state change is a named action (`.../batches`, `.../validate`, `.../approve`, `.../commit`, `.../finalise`, plus `.../negative-openings` and its own approve route for ADR 0037's cutover-shortage exception). `GET .../preview` is read-only. The `/inventory-cutovers` list, create and `/inventory-cutovers/:id` detail screens are the frontend's first multi-actor approval lifecycle UI, action buttons gated by status; "attach batch" and the exception form's "responsible owner" take raw numeric IDs since Imports/Users have no picker endpoints yet → [inventory-cutover.md](domain/inventory-cutover.md#user-interface) | 2026-09-08 |
| **Physical count HTTP API and workflow screens** — the header is create/read-only over HTTP; every state change is a named action (`POST .../lines`, `POST /api/v1/physical-count-lines/{id}/count`, `.../post`, `.../cancel`). The `/physical-counts` list, create and `/physical-counts/:id` detail screens are the frontend's first document-workflow UI — add a line, record/recount inline, supply a found cost for a positive variance, then post or cancel, verified end-to-end including separation-of-duties rejection → [physical-count.md](domain/physical-count.md#user-interface) | 2026-09-08 |
| **Stock ledger read API and Stock Ledger screen** — `stock_moves` gained its first HTTP surface: a cursor-paginated `GET /api/v1/stock-moves` (filterable by item/warehouse/location/batch/source type/date range) and a `show` endpoint, both including `item`/locations/`batch`/`valuation_entries` on request. A new `/stock-moves` list screen and `/stock-moves/:id` detail screen consume it — the first UI built against Phase 2's stock engine, and the first cursor-paginated list in the frontend (`CursorDataTable`, `useCursorQueryState`) → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md#close-out) | 2026-09-08 |
| **Phase 2 integrity audit** — three real gaps found and fixed: the stock ledger (`stock_moves`/`stock_valuation_entries`/`stock_layers`) and purchasing documents were model-guarded only and are now database-immutable like every other Phase 2 history table; `recalculation_requests` was the one bare location reference with no company trigger; and the "two concurrent AVCO receipts" acceptance criterion had the lock but no proof — now `AvcoPoolConcurrencyTest`. Rollback proven for physical-count and cutover posting too. Duplicate-submission, precision and tracking had no gaps → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md) | 2026-09-08 |

## Next

Phase 2's UI/API close-out is complete. Its acceptance criteria are now 15 of 21 satisfied; the
three previously missing proof groups are closed (ADR 0043 receipt concurrency, every
`StockIdentity` rejection branch, and all three post-first-move item-policy locks). Of the six
remaining criteria, three require Phase 4/5/6 correction-package owners; the others require
explicit reconciliation/design work rather than more tests around existing enforcement,
including per-location cutover reconciliation and the future valuation-policy boundary.

The first production deployment remains deliberately last, after all Phase 2 criteria are
complete. Its runbook is ready ([deployment.md](architecture/deployment.md#first-deploy-runbook))
but unrehearsed, and two prerequisites sit outside this repository: registering the self-hosted
ARM64 runner the image build targets, and agreeing Redis database indices with invoiceflow and
crm. `main` is ~103 commits behind `develop`, so that merge is itself the first real exercise of
the multi-arch build.

## Blocked / awaiting input

Nothing blocking. Open questions: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 4
non-blocking (second customer profile; cross-entity master data; movement posting actor; whether
location mode and default AVCO pool scope are governed policies).

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
