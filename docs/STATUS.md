# Project Status

**Updated:** 2026-09-03
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
| **Goods receipt reversal** — ADR 0043's reversal contract, lock-order aligned with posting → [phase-2](phases/phase-2-purchasing-stock.md) | 2026-09-03 |
| **Normal outbound stock issues** — AVCO/FIFO/SPECIFIC blocking-mode issues at the sole movement boundary → [stock.md](domain/stock.md) | 2026-09-03 |
| **Negative-stock policy + override workflows** — ADR 0037 company controls, immutable request/approval/review records → [negative-stock.md](domain/negative-stock.md) | 2026-09-03 |
| **Authorised negative posting + inbound settlement** — provisional AVCO/FIFO cost, oldest-first exact-identity settlement, append-only repairs → [negative-stock.md](domain/negative-stock.md) | 2026-09-03 |
| **Value-conserving transfers + provisional repair propagation** — same-pool neutrality, cross-pool conservation, exactly-once cascading repairs → [negative-stock.md](domain/negative-stock.md) | 2026-09-03 |
| **Stock-engine audit fixes** — frozen pools no longer block receiving at non-shortage locations; pool-level insufficiency no longer blocks physically available issues; transfers preserve FIFO age and settle at exact slice costs; pools lock in id order (deadlock fix); `PostInventoryMovement` split into `Movements/` collaborators per the new [file-size rule](architecture/code-structure.md#file-size-and-splitting); a seeded conservation property test now guards the whole engine; docs restructured per [ADR 0044](decisions/0044-status-log-context-budget.md); Q15/Q16 opened | 2026-09-03 |

## Next

Continue [Phase 2 — Purchasing & stock](phases/phase-2-purchasing-stock.md):

1. Build ADR 0040's backdating horizon controls and recalculation workflow.
2. Then: API endpoints + first UI screens for the stock engine, and the first real deployment
   to the production VMs (both planned for the end of Phase 2).

## Blocked / awaiting input

Nothing blocking. Open questions: [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) — 0 blocking, 4
non-blocking (second customer profile; cross-entity master data; provisional transfer repair
rate; movement posting actor).

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
