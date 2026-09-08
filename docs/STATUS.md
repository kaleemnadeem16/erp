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
ADR 0037's cutover-shortage negative-opening exception. React 19 + Vite frontend skeleton with a
generated client covering every implemented endpoint.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **Recalculation execution — clean-replay issue/transfer execution** — `ExecuteRecalculation` now posts an approved backdated ISSUE or TRANSFER too, when its revalidated replay changes no other move (`OutboundRecalculationExecutor`, mirroring `IssuePoster`/`TransferPoster`'s posting shape at the true historical date). A request whose replay reprices other history still refuses — that needs a real→synthetic layer-id bridge `MovementReplayState`'s preview simulation doesn't have yet → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |
| **Authority-only recalculation approval rerouting** — ADR 0040's `REROUTE_OR_INVALIDATE_APPROVAL_WITHOUT_NEW_IMPACT_VERSION`: a different, eligible approver can now claim a slot an expiry freed, on the same impact version, with no new version generated. The required-approver count for a new claimant is measured in currently-unexpired distinct approvers (`unexpiredApprovedActorIds()`); renewal detection stays expiry-blind. DB-enforced in the same trigger as well as in the Action → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |
| **Recalculation execution — outbound changed-history case, ADR 0040 complete** — a backdated ISSUE/TRANSFER now executes even when its replay reprices other real history, closing the last executable gap: FIFO/SPECIFIC reuse the receipt case's real-layer-id engine (`LayerCounterfactualReplayer::planOutboundInsertion()` + `FifoReceiptReallocator::applyCorrections()`, arbitrary transfer-hop depth) with no synthetic-id bridge needed; AVCO converges every pool a connected component touched, not just source/destination. Only the multi-owner correction package (Phase 4/5/6) remains → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |
| **Generic inventory-cutover import contract** — ADR 0027's shared Imports foundation now stages arbitrary mapped rows under an idempotent source identity and validates Inventory's `INVENTORY_CUTOVER`/`OPENING_STATE` schema without any generic domain-write callback. Raw/mapped evidence is immutable and purpose-bound; cutover orchestration is next → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-07 |
| **Inventory cutover orchestration** — one immutable company cutover now stages validated generic batches into normalised source facts, previews/reconciles exact controls and enforces independent approval through dedicated permissions. Finalisation is present but cannot succeed until the next method-specific opening-state Action commits every batch → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-07 |
| **Cutover valuation-policy transition ledger** — validation now derives immutable, idempotent source method/scope/pool → target method/scope records effective exactly at `cutover_at`, including structured revaluation evidence for method changes. This is a deliberately small provenance slice; it posts no stock → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-07 |
| **Reconciled cutover opening-state posting** — `CommitInventoryCutoverOpeningState` posts every validated `OPENING_STATE` batch as real `stock_moves`/`stock_layers`, converging target AVCO pools (with new `InventoryCutoverAvcoPool` provenance), real/synthetic FIFO layers and per-serial SPECIFIC layers, retargeting each item's live valuation method/scope first since this is its first-ever movement. Finalisation can now actually succeed → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-08 |
| **Cutover negative-opening exception, ADR 0036/0037 opening-state posting complete** — `InventoryCutoverNegativeOpening` records an evidenced AVCO/FIFO negative opening (provisional cost, reason, evidence, owner, resolution plan, target date), requested and dedicated-approved by different actors. Approved, `CommitInventoryCutoverOpeningState` posts an `OPEN_CUTOVER_SHORTAGE` settled afterwards exactly like a live shortage — `negative_stock_shortages` now accepts either authority, never both. SPECIFIC/serial always blocked → [inventory-cutover.md](domain/inventory-cutover.md) | 2026-09-08 |

## Next

ADR 0040's executable-today scope is done — every backdated receipt/issue/transfer case across all
three valuation methods now previews, approves and executes, clean or changed-history alike. The
multi-owner correction package (Sales/Job Execution/Accounting routing, cross-module
reconciliation) waits on those modules existing — Phase 4/5/6, tracked at each phase's entry in
[phases/README.md](phases/README.md). ADR 0036 inventory cutover's opening-state posting, including
its negative-opening exception, is complete. Continue the rest of
[Phase 2 — Purchasing & stock](phases/phase-2-purchasing-stock.md):

1. Physical-count documents (snapshot lines, count/recount, posted variance through the same
   movement Action).
2. API polish across Phase 2 (list filters/sorting/pagination, stable 409/422 conflict responses),
   Orval client regeneration, and a Phase 2 integrity audit.
3. Phase 2 close-out: first UI screens for the stock engine and the first real deployment to the
   production VMs.

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
