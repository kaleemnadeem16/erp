[← Roadmap](README.md)

# Rejected-stock exit (supplier return and scrap) — implementation plan

- **Status:** planned — not started. Decision: [ADR 0097](../decisions/0097-supplier-return-and-scrap-of-rejected-stock.md) (accepted 2026-10-08). Workstream: grouped package tracking follow-on ([handoff](package-balance-handoff.md)).
- **Depends on:** QA disposition and held/rejected package evidence ([ADR 0094](../decisions/0094-package-aware-supplier-qa-receipts.md)); the sole movement boundary; the central numbering framework.
- **Goal:** rejected stock — grouped or quantity-only — gets a normal, audited, atomic exit through two separate documents, **Supplier Return** and **Scrap**, over one shared posting path. Nothing financial is settled (no AP/GL).

Every slice passes the [feature-completion checklist](../architecture/feature-completion-checklist.md); the lines below name what is specific here. Slices land in order, each committed with its tests, docs and (where user-facing) browser coverage.

## Facts to verify before coding

1. How `IssuePoster` and `LayerAllocator` consume a layer, so a **named-layer outbound** can be added without touching allocation for ordinary issues (the pattern is `ReceiptPoster::reverse`, which locks and consumes a specific layer).
2. That FIFO/SPECIFIC exact-cost consumption and the AVCO pool outflow work for a layer whose quantity is excluded from `issuableQuantity`.
3. The `stock_moves.source_type` constraints and the deferred "move needs evidence" trigger's evidence list (it must learn the new evidence kind).
4. The numbering framework's document-type registration, the Purchasing and Inventory permission vocabularies, and `RecordScopeCoverageTest`/`StockWriterMatrixTest` entry format.

## Slice 1 — shared exit core (backend, no HTTP)

- **Ledger:** an immutable `rejected_stock_exits` table (company-scoped, composite keys): kind `SUPPLIER_RETURN | SCRAP`, document type and line id, `goods_receipt_line_id`, `stock_move_id`, quantity, the QA decision it relates to, `differs_from_qa` and the **outcome-change reason** (required when it differs), request key + payload hash (unique per company). It is the single source for the cap: `available to exit = Σ rejected dispositions − Σ exited`.
- **Read model:** `RejectedStockBalance` (per receipt line: rejected, exited, available, and the per-kind balance that decides "differs"), used by previews and posting.
- **Posting Action:** `PostRejectedStockExit` — locks location and receipt layer in the stable order, re-reads the cap, posts the outbound move **from the receipt layer** through the movement boundary (`PostInventoryMovement` gains a layer-specific entry; FIFO/SPECIFIC at the layer cost, AVCO through the normal engine), writes the valuation feed row with `source_type SUPPLIER_RETURN` or `SCRAP_WRITE_OFF`, the ledger row, and — for a grouped item — the package evidence, all in one transaction. Idempotent per request key; retry reuses the original moment.
- **Package evidence:** a new `REJECTED_EXIT` kind, issue-shaped and anchored on the move, drawing whole `REJECTED` sealed packages and `REJECTED` loose quantity (never opening or splitting). Migration: kind and link column, draw availability allowed for `REJECTED`, a conservation function (composition = predecessor − draw, single allocation = the receipt layer, `REJECTED` at that layer = decided − exited), and the evidence requirement for the two new `source_type`s of a grouped item. Reuses `PackageIssueComposition` with `$from = 'REJECTED'`. `down()` refuses once exits exist.
- **Tests:** FIFO, AVCO and SPECIFIC cost; grouped (sealed whole, loose, mixed usable/held/rejected untouched) and quantity-only parity; cap and over-exit refusal; differing outcome needs a reason; idempotent retry and changed-payload refusal; rollback of a mixed multi-line post; raw-SQL attacks on the new checks; stale evidence; cross-company; the writer matrix entry.

## Slice 2 — documents, permissions and API

- **Documents:** Purchasing `SupplierReturn` (supplier, supplier reference/RMA, carrier note, expected credit amount/currency/reference — informational) and Inventory `Scrap` (disposal method/reference); each with lines, `DRAFT → POSTED | CANCELLED`, central numbering (new document types). Draft moves nothing; edit and cancel only while draft; post calls the shared Action. A return's lines must share one supplier, derived from the receipt line.
- **Capabilities:** `purchasing.supplier-return.create/.post`, `inventory.scrap.create/.post` (dedicated; no inference); registered in the owning vocabularies; **no grant migration by inference** (no equivalent duty exists) — administrators via the re-sync, role recipes in the domain doc.
- **API:** Form Requests with capability guards, Resources, `GET` preview of a receipt line's rejected balance and proposed portion (like the QA portion preview), stable `reason_code`s (`NOTHING_REJECTED_TO_EXIT`, `OUTCOME_CHANGE_REASON_REQUIRED`, plus the existing `NEEDS_DECLARED_PORTION`, `DRAW_NOT_ON_HAND`, `STALE_EVIDENCE`); warehouse scope on the layer's location (404 outside); both document routes added to `RecordScopeCoverageTest`; OpenAPI and client regenerated.
- **Tests:** permissions and direct Action calls; cross-company by id; scope; draft vs post; retries; the ratchets (`WriteRouteGuardTest`, `OpenReadRoutesTest`, `PermissionCheckRegistrationTest`).

## Slice 3 — screens

- **Entry points:** *Rejected awaiting exit* on the receipt's QA panel and the item card, with **Return to supplier…** and **Scrap…**; each opens a draft dialog (a portion step for grouped items reusing the shared draw editor, one quantity for quantity-only), a reason, and for a return the supplier reference and optional expected credit shown as *information only*. A differing outcome shows the QA decision and asks for the outcome-change reason.
- **Documents:** list and detail screens for each document (search, status filter, post/cancel for drafts, posted result), on the shared shells, permission-aware.
- **Standards:** operator language ("returned to supplier", "scrapped"), no accounting vocabulary; phone-width spec; accessibility and contrast spec entries; journey updated.
- **Browser tests:** return of 2 rejected cans, scrap of 1 can, rejected loose litres, a mixed usable/rejected location, quantity-only, the refused partial-can attempt, the differing-outcome reason, and a second persona without the capability.

## Slice 4 — demo, rollout and close-out

- **Demo:** a create-once `RejectedStockExitDemoSeeder` (a delivery with QA-rejected cans and loose litres: one return, one scrap, one left awaiting), plus a quantity-only scenario; a seeder test proving the rerun adds nothing.
- **Docs:** domain doc sections (stock, purchasing, package operations), the grouped journey, role recipes, audit-tracker rows, API and ERP Blue notes where reusable pieces were added; the handoff and STATUS row updated; ADR left unchanged (accepted).
- **Release notes:** migration order, permission registration and administrator re-sync.

## Out of scope (deliberate)

AP credit note, supplier balance and GL journals (Phase 6); return reversal (goods coming back); approval thresholds; opening or splitting rejected packages; a standalone rejected-stock list screen (later, with the other menu screens).

## Related

- [ADR 0097](../decisions/0097-supplier-return-and-scrap-of-rejected-stock.md) · [ADR 0094](../decisions/0094-package-aware-supplier-qa-receipts.md) · [ADR 0091](../decisions/0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md)
- [Package feature handoff](package-balance-handoff.md) · [feature-completion checklist](../architecture/feature-completion-checklist.md) · [testing standards](../architecture/testing-standards.md)
