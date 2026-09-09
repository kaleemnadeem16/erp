# Project Status

**Updated:** 2026-09-09
**Current phase:** Phase 2 — Purchasing & stock (in progress). Phase 1 — Master data is
complete. Phase 0 — Foundation is done except the deliberately deferred production deployment.
**Application code:** Laravel 12 backend with Phase 0 foundations, Phase 1 master-data APIs and
Phase 2 purchase orders, receipts, QA/reversal and the full stock movement/valuation engine.
Inventory cutover now posts and strictly reconciles AVCO/FIFO/SPECIFIC opening state; controlled
negative openings, live negative-stock overrides, backdated recalculation and physical counts are
also complete. Each workflow has a read/write API and React UI; the stock ledger, layers and AVCO
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
| **Strict staged inventory-cutover reconciliation** — ADR 0046 allows explicitly approved defaults for optional fields or a documented opening location while forbidding defaults for quantity/value, warehouse allocation, item/UoM and valuation identity, batch or serial. Validation now rejects untrustworthy staged facts; preview exposes exact location totals/default decisions; finalisation independently reconciles posted locations, target AVCO pools and company controls, raising Phase 2 acceptance to 16/21 → [inventory-cutover.md](domain/inventory-cutover.md), [ADR 0046](decisions/0046-strict-staged-inventory-cutover-reconciliation.md) | 2026-09-09 |
| **Phase 2 missing test proofs closed** — a real forked-process PostgreSQL test now races two receipts against one PO line and proves its cumulative ceiling cannot be exceeded; tracking rejection coverage now spans receipt/issue paths and caught/fixed serial receipts accepting a batch; all three post-first-move item-policy locks are verified through the API. Phase 2 acceptance is now 15/21, with the remaining criteria requiring design or later-module ownership rather than missing tests → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md#acceptance-criteria) | 2026-09-09 |
| **Pre-deployment audit and its fixes** — a full audit before the first deploy found, and this closes: a backdated recalculation could be valuation-effective *before* a finalised inventory cutover (the cutover boundary is now composed into `BackdatingHorizonEvaluator`, and `ExecuteRecalculation` re-evaluates the horizon rather than trusting the request-time snapshot); negative-stock `available_qty` was taken from client input and is now derived from live stock; decimals were documented as JSON numbers on every write path and now cross the wire as strings both ways (one Scramble rule transformer, no validation change); and purchasing's over-receipt policy gained the permission, reason and audit row it never had. Deploy blockers fixed too: the SPA no longer builds pointing at localhost, trusted proxies are configured, and `erp:create-admin` makes a fresh database signable-into → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md), [deployment.md](architecture/deployment.md#first-deploy-runbook) | 2026-09-09 |
| **Stock layer and AVCO pool read APIs** — `GET /api/v1/stock-layers` (cursor-paginated, `filter[open]` for an item's still-open FIFO/SPECIFIC layers) and `GET /api/v1/avco-pools` (page-paginated, an item's current converged balance) close the last gap on the stock-ledger close-out item. Deliberately backend-only — reference data, not a workflow, so no dedicated screen this time. Closes Phase 2's entire close-out checklist except production deployment → [phase-2-purchasing-stock.md](phases/phase-2-purchasing-stock.md#close-out) | 2026-09-09 |
| **Stock recalculation HTTP API and screens** — `RequestStockRecalculation` (header, create/read-only, also the preview) plus `POST .../decide`, `.../execute` and `.../resume-regeneration` on `RecalculationImpactVersion`, a distinct addressable child resource since a request accumulates several versions over its life. The `/recalculation-requests` screens are the most involved yet — version history, multi-approver approvals with individual expiry/renewal, risk-tier routing — with computed flags (`is_fully_approved` etc.) added server-side rather than pushed onto the frontend → [stock-recalculation.md](domain/stock-recalculation.md#user-interface) | 2026-09-08 |
| **Negative-stock override HTTP API and screens** — the header (`RequestNegativeStockOverride`) is create/read-only over HTTP; `POST .../decide` and `.../review` are named actions on the same resource (no separate exception resource). The `/negative-stock-overrides` list/create/detail screens are the first UI with no server-side status column — status is derived client-side from `authorisation_mode` plus whether `approval`/`review` exists. `UpdateInventorySettings` was already exposed → [negative-stock.md](domain/negative-stock.md#user-interface) | 2026-09-08 |
| **Inventory cutover HTTP API and lifecycle screens** — the header is create/read-only over HTTP; every state change is a named action (`.../batches`, `.../validate`, `.../approve`, `.../commit`, `.../finalise`, plus `.../negative-openings` and its own approve route for ADR 0037's cutover-shortage exception). `GET .../preview` is read-only. The `/inventory-cutovers` list, create and `/inventory-cutovers/:id` detail screens are the frontend's first multi-actor approval lifecycle UI, action buttons gated by status; "attach batch" and the exception form's "responsible owner" take raw numeric IDs since Imports/Users have no picker endpoints yet → [inventory-cutover.md](domain/inventory-cutover.md#user-interface) | 2026-09-08 |
| **Physical count HTTP API and workflow screens** — the header is create/read-only over HTTP; every state change is a named action (`POST .../lines`, `POST /api/v1/physical-count-lines/{id}/count`, `.../post`, `.../cancel`). The `/physical-counts` list, create and `/physical-counts/:id` detail screens are the frontend's first document-workflow UI — add a line, record/recount inline, supply a found cost for a positive variance, then post or cancel, verified end-to-end including separation-of-duties rejection → [physical-count.md](domain/physical-count.md#user-interface) | 2026-09-08 |

## Next

Phase 2's UI/API close-out is complete. Its acceptance criteria are now 16 of 21 satisfied. The
previously missing proof groups and strict cutover-reconciliation gap are closed: ADR 0043 receipt
concurrency, every `StockIdentity` rejection branch and all three post-first-move item-policy
locks. Of the five remaining criteria, three require Phase 4/5/6 correction-package
owners; the others are the consolidated Q13/Q14 behaviour proof and the future valuation-policy
boundary.

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
