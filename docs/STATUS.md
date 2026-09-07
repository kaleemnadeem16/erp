# Project Status

**Updated:** 2026-09-07
**Current phase:** Phase 2 — Purchasing & stock (in progress). Phase 1 — Master data is
complete. Phase 0 — Foundation is done except the deliberately deferred production deployment.
**Application code:** Laravel 12 backend — Phase 0 foundations (auth, company scoping, UoM,
custom fields, sequences, currencies, Scramble→Orval type pipeline), Phase 1 master data
(`Parties`, `Inventory`, `Accounting` CRUD APIs), and Phase 2 purchasing & stock (purchase
orders, goods receipts, QA dispositions, reversal, and the full stock movement/valuation engine:
issues, transfers, ADR 0037 negative-stock workflows, append-only FIFO receipt reallocation
through direct issues and however many transfer hops real history contains, clean-replay backdated
issue/transfer execution, and authority-only recalculation approval rerouting). React 19 + Vite
frontend skeleton with a generated TS client covering every implemented endpoint.

---

## Where we are

Planning and architecture are established and implementation is well under way. The domain
model, tech stack, extensibility strategy and deployment approach are recorded as
[ADRs](decisions/). Detail for completed work lives in the phase docs; the full narrative
history is [STATUS-LOG.md](STATUS-LOG.md).

## Recently done

| Item | Date |
|---|---|
| **Recalculation execution — AVCO receipts** — `ExecuteRecalculation` posts an approved backdated receipt truthfully at its physical date and converges the AVCO pool to the exact approved counterfactual via one immutable, idempotent `RecalculationExecution` record — the Inventory-owned correction package slice, since Sales/Job Execution/Accounting don't exist yet to own the rest. FIFO/SPECIFIC and issue/transfer execution, authority-only rerouting and multi-owner reconciliation remain → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-05 |
| **Recalculation execution — SPECIFIC receipts** — a new serial's layer group is scoped so tightly that nothing could have consumed it before it existed, so its adjustment is provably always its own value; `ExecuteRecalculation` posts it as a plain layer insert, no pool and no correction entry. FIFO uses its separate exact allocation-plan path → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-05 |
| **Recalculation execution — untouched FIFO receipts** — if the exact replay plan leaves every later layer allocation unchanged, the backdated receipt executes as a plain layer insert without manufacturing a correction. Allocation comparison is now independent of monetary residual, so an equal-cost provenance change cannot hide → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-05 |
| **Recalculation execution — direct-issue FIFO reallocation** — approved backdated FIFO receipts now append exact `ALLOCATION_REVERSAL` links and replacement allocations, update live layer balances atomically and post the approved residual valuation adjustment without mutating original issue facts. Transfer-connected histories still refuse because their immutable destination-layer provenance needs the next cascading slice → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |
| **Recalculation execution — one-hop FIFO transfer reallocation** — a transfer whose corrected source draw changes now gets its destination layer-component(s) superseded by freshly minted ones carrying the right cost/age (an append-only `REVERSAL` row, not a mutation), and the same reallocation cascades recursively into whatever the destination's own later issues already consumed from them. A second transfer hop out of that destination still refuses, DB-enforced as well as in the Action → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |
| **Recalculation execution — arbitrary-depth FIFO transfer reallocation** — the one-hop cap is gone: `FifoReceiptReallocator` recurses through however many transfer hops a chain actually has (bounded only by real history — each hop strictly later than the one before), and the DB guard now authorises a correction row anywhere reachable from the request's location by a recursive transfer-connected-component query instead of one hardcoded hop → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |
| **Recalculation execution — clean-replay issue/transfer execution** — `ExecuteRecalculation` now posts an approved backdated ISSUE or TRANSFER too, when its revalidated replay changes no other move (`OutboundRecalculationExecutor`, mirroring `IssuePoster`/`TransferPoster`'s posting shape at the true historical date). A request whose replay reprices other history still refuses — that needs a real→synthetic layer-id bridge `MovementReplayState`'s preview simulation doesn't have yet → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |
| **Authority-only recalculation approval rerouting** — ADR 0040's `REROUTE_OR_INVALIDATE_APPROVAL_WITHOUT_NEW_IMPACT_VERSION`: a different, eligible approver can now claim a slot an expiry freed, on the same impact version, with no new version generated. The required-approver count for a new claimant is measured in currently-unexpired distinct approvers (`unexpiredApprovedActorIds()`); renewal detection stays expiry-blind. DB-enforced in the same trigger as well as in the Action → [stock-recalculation.md](domain/stock-recalculation.md) | 2026-09-07 |

## Next

Continue [Phase 2 — Purchasing & stock](phases/phase-2-purchasing-stock.md):

1. Extend issue/transfer execution to the case where the replay changes other history — needs a
   real-layer-id bridge for `MovementReplayState`'s FIFO/SPECIFIC simulation.
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
