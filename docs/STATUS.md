# Project Status

**Updated:** 2026-09-05
**Current phase:** Phase 2 — Purchasing & stock (in progress). Phase 1 — Master data is
complete. Phase 0 — Foundation is done except the deliberately deferred production deployment.
**Application code:** Laravel 12 backend — Phase 0 foundations (auth, company scoping, UoM,
custom fields, sequences, currencies, Scramble→Orval type pipeline), Phase 1 master data
(`Parties`, `Inventory`, `Accounting` CRUD APIs), and Phase 2 purchasing & stock (purchase
orders, goods receipts, QA dispositions, reversal, and the full stock movement/valuation engine:
issues, transfers, ADR 0037 negative-stock workflows). React 19 + Vite frontend skeleton with a
generated TS client covering every implemented endpoint.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **Layer-consumption ledger** — `stock_layer_consumptions` permanently links each FIFO/SPECIFIC allocation to the move it served (previously discarded once `qty_remaining` was decremented), closing the data gap the AVCO-only recalculation slice named. Purely additive — existing issue/transfer behaviour and tests unchanged → [stock.md](domain/stock.md), [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-04 |
| **FIFO/SPECIFIC recalculation replayer** — `LayerCounterfactualReplayer` (one class covers both methods) reconstructs a layer group's state from the consumption ledger and re-walks later events using the real, unmodified `LayerAllocator::allocate()`. `RequestStockRecalculation`/`DecideRecalculationApproval` now branch by valuation method. Recalculation is real for all three methods, receipts only; issues/transfers, risk tiers, execution and correction packages remain — phase-2 checklist stays unticked → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-04 |
| **Recalculation risk classification + second approver** — `RecalculationRiskClassifier` marks every impact version `NORMAL_RISK`/`HIGH_RISK` at creation (closed-period overlap and an optional bounded company threshold; two other named hard triggers stay honestly unbuilt — no invoicing/job-costing model exists yet, and self-review still can't happen at all). `DecideRecalculationApproval` now requires one or two independent approvers by risk tier, DB-enforced (`guard_recalculation_approval_insert`) as well as in the Action; only the completing approval carries the TTL expiry. Issues/transfers, authority-only rerouting, execution and correction packages remain — phase-2 checklist stays unticked → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-04 |
| **Recalculation audit fixes** — exact-layer reversals, transfer arrival/FIFO-age separation, incomplete/shortage/QA history guards, complete impact revalidation, dedicated second-approver authority and capped regeneration with audited manual resumption → [stock-recalculation.md](domain/stock-recalculation.md#replay-integrity-and-regeneration-review) | 2026-09-05 |
| **Backdated issues and transfers** — clean-history previews now derive outbound costs and follow both transfer sides plus onward FIFO/SPECIFIC or configured AVCO dependencies, retaining exact per-scope/per-movement differences for review → [stock-movement-replay.md](domain/stock-movement-replay.md) | 2026-09-05 |
| **Recalculation approval expiry per ADR 0040** — every required approval now persists its own TTL the moment it's granted, not just whichever one completes the requirement; an approver may renew their own expired decision (a fresh append, never a mutation) without needing high-risk authority re-checked. A different approver still cannot claim a slot an expiry frees — that's authority-only rerouting, which remains unbuilt → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-05 |
| **Recalculation execution — AVCO receipts** — `ExecuteRecalculation` posts an approved backdated receipt truthfully at its physical date and converges the AVCO pool to the exact approved counterfactual via one immutable, idempotent `RecalculationExecution` record — the Inventory-owned correction package slice, since Sales/Job Execution/Accounting don't exist yet to own the rest. FIFO/SPECIFIC and issue/transfer execution, authority-only rerouting and multi-owner reconciliation remain → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-05 |
| **Recalculation execution — SPECIFIC receipts** — a new serial's layer group is scoped so tightly that nothing could have consumed it before it existed, so its adjustment is provably always its own value; `ExecuteRecalculation` now posts it as a plain layer insert, no pool, no correction entry. FIFO execution still needs real downstream consumption re-linking (a backdated receipt can shift which layer a later issue drew from), not just a converged average → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-05 |

## Next

Continue [Phase 2 — Purchasing & stock](phases/phase-2-purchasing-stock.md):

1. Extend execution to FIFO receipts — needs correcting the immutable
   `stock_layer_consumptions` ledger itself (paired reversal/re-consumption, cascading through any
   transfer that relayed a shifted layer onward), then to issues/transfers, then build
   authority-only approval rerouting.
2. The multi-owner correction package (Sales/Job Execution/Accounting routing, cross-module
   reconciliation) waits on those modules existing — Phase 4/5/6.
3. Longer-term: API endpoints + first UI screens for the stock engine, and the first real
   deployment to the production VMs (both planned for the end of Phase 2).

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
