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
posted variance through the ordinary movement boundary) are also complete. React 19 + Vite frontend
skeleton with a generated client covering every implemented endpoint.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **Recalculation execution — outbound changed-history case, ADR 0040 complete** — a backdated ISSUE/TRANSFER now executes even when its replay reprices other real history, closing the last executable gap: FIFO/SPECIFIC reuse the receipt case's real-layer-id engine (`LayerCounterfactualReplayer::planOutboundInsertion()` + `FifoReceiptReallocator::applyCorrections()`, arbitrary transfer-hop depth) with no synthetic-id bridge needed; AVCO converges every pool a connected component touched, not just source/destination. Only the multi-owner correction package (Phase 4/5/6) remains → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |
| **Generic inventory-cutover import contract** — ADR 0027's shared Imports foundation now stages arbitrary mapped rows under an idempotent source identity and validates Inventory's `INVENTORY_CUTOVER`/`OPENING_STATE` schema without any generic domain-write callback. Raw/mapped evidence is immutable and purpose-bound; cutover orchestration is next → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-07 |
| **Inventory cutover orchestration** — one immutable company cutover now stages validated generic batches into normalised source facts, previews/reconciles exact controls and enforces independent approval through dedicated permissions. Finalisation is present but cannot succeed until the next method-specific opening-state Action commits every batch → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-07 |
| **Cutover valuation-policy transition ledger** — validation now derives immutable, idempotent source method/scope/pool → target method/scope records effective exactly at `cutover_at`, including structured revaluation evidence for method changes. This is a deliberately small provenance slice; it posts no stock → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-07 |
| **Reconciled cutover opening-state posting** — `CommitInventoryCutoverOpeningState` posts every validated `OPENING_STATE` batch as real `stock_moves`/`stock_layers`, converging target AVCO pools (with new `InventoryCutoverAvcoPool` provenance), real/synthetic FIFO layers and per-serial SPECIFIC layers, retargeting each item's live valuation method/scope first since this is its first-ever movement. Finalisation can now actually succeed → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-08 |
| **Cutover negative-opening exception, ADR 0036/0037 opening-state posting complete** — `InventoryCutoverNegativeOpening` records an evidenced AVCO/FIFO negative opening (provisional cost, reason, evidence, owner, resolution plan, target date), requested and dedicated-approved by different actors. Approved, `CommitInventoryCutoverOpeningState` posts an `OPEN_CUTOVER_SHORTAGE` settled afterwards exactly like a live shortage — `negative_stock_shortages` now accepts either authority, never both. SPECIFIC/serial always blocked → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-08 |
| **Physical-count documents** — `CreatePhysicalCount`/`AddPhysicalCountLine` snapshot `book_qty` from `stock_layers` (method-agnostic); `RecordPhysicalCountLine` lets a recount replace the working count while every attempt stays immutable; `PostPhysicalCount` posts each non-zero variance through `PostInventoryMovement` itself — a found-more line always needs an explicit reviewer-supplied cost, a found-less line is an ordinary issue with no override authority → [physical-count.md](domain/physical-count.md) | 2026-09-08 |
| **API polish for existing Phase 2 resources** — goods receipts gained the `index` endpoint it was missing entirely, quality dispositions gained a `disposition` filter, purchase orders gained `filter[search]` on number, and a global `23514` CHECK-violation handler mirrors the existing `23503` one so a genuine DB-only race returns 422 instead of an uncaught 500. Orval client regenerated; frontend type-checks and production build verified. Inventory cutover, recalculation, negative-stock, physical counts and the raw stock ledger remain deliberately API-less until Phase 2 close-out's UI work needs each one → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md) | 2026-09-08 |

## Next

ADR 0040's executable-today scope is done — every backdated receipt/issue/transfer case across all
three valuation methods now previews, approves and executes, clean or changed-history alike. The
multi-owner correction package (Sales/Job Execution/Accounting routing, cross-module
reconciliation) waits on those modules existing — Phase 4/5/6, tracked at each phase's entry in
[phases/README.md](phases/README.md). Continue the rest of
[Phase 2 — Purchasing & stock](phases/phase-2-purchasing-stock.md):

1. A Phase 2 integrity audit covering raw-write tenant protection, concurrency, rollback,
   duplicate submission, precision, tracking and historical-retention guarantees.
2. Phase 2 close-out: first UI screens for the stock engine (will need HTTP APIs building for
   whichever of cutover/recalculation/negative-stock/physical-count/stock-ledger each screen
   touches first — deliberately not built ahead of a real screen's needs) and the first real
   deployment to the production VMs.

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
