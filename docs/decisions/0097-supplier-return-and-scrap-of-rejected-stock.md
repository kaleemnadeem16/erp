# 0097 — Supplier return and scrap of rejected stock: one exit movement from the receipt's own layer

- **Status:** proposed (2026-10-08) — design only; awaiting the owner's answers to *Decisions requested* below
- **Date:** 2026-10-08
- **Affects:** Inventory (movement boundary, valuation feed, package evidence), Purchasing (supplier return document, QA), Accounting boundary (Phase 6 feed), grouped package tracking, quantity-only stock

## Context

A QA disposition of `RETURN_TO_SUPPLIER` or `SCRAP` is **terminal and final but moves nothing** ([ADR 0043](0043-control-purchase-order-receipt-and-qa-posting.md), unchanged by
[ADR 0094](0094-package-aware-supplier-qa-receipts.md)). Verified in code and docs 2026-10-08:

- The rejected quantity stays in the receipt's stock layer and in on-hand for ever. `LayerAllocator::issuableQuantity` offers a QA layer only the quantity released by `PASS`/`ACCEPT_WITH_CONCESSION`
  less what was consumed, so **no existing issue can take rejected quantity out** — for any item. The dead end is therefore not specific to grouped stock; grouped stock only made it visible (a `REJECTED`
  package group that never empties, and a location that can never be counted again).
- ADR 0043 already names *Supplier Return* "a distinct outbound source" that "does not rewrite receipt or PO history" and "does not reopen the PO"; `stock.md` lists *return to supplier* and
  *write-off* as ordinary outbound moves (`from = location`, `to = NULL`) distinguished only by `source_type`.
- Phase 2 does **not** post to the GL. Each move writes a `stock_valuation_entries` row, a signed **feed** with no debit/credit; turning it into balanced journals is Phase 6 ([general-ledger.md](../domain/general-ledger.md),
  `stock.md` "GL posting"). There is no supplier invoice, credit note or AP ledger yet.
- Grouped stock: rejected packages are `REJECTED` states, attributed to the receipt's single layer; held and rejected packages cannot be issued, transferred, opened or counted ([ADR 0094](0094-package-aware-supplier-qa-receipts.md) §5).
  `PackageIssueComposition` can already act on a chosen availability (`$from`), and ADR 0093's reversal already removes a declared draw from a specific layer — the pieces exist.

This ADR settles the business semantics and the accounting boundary so the exit can be built once, correctly, for both grouped and quantity-only stock.

## Decision (proposed)

### 1. One business operation, two named exits

A **rejected-stock exit** removes rejected quantity from stock permanently. It has two named kinds, which differ in documents, permissions and accounting class but share one posting path:

| | **Supplier return** | **Scrap / write-off** |
|---|---|---|
| Physical meaning | the goods go back to the supplier | the goods are destroyed or disposed of |
| Document | `Supplier Return` (Purchasing): supplier, RMA/reference, carrier note, lines | `Scrap Record` (Inventory): lines, disposal method/reference |
| Movement `source_type` | `SUPPLIER_RETURN` | `SCRAP_WRITE_OFF` |
| Accounting class (feed, §5) | recoverable from the supplier | loss |
| Permission | `purchasing.supplier-return.post` | `inventory.scrap.post` |

The movement is an ordinary outbound move (`from_location` = where the rejected stock sits, `to` = NULL) posted through the **sole movement boundary**, never a bespoke writer. It happens at the moment of posting; there is
no backdating, as for counts and QA decisions.

### 2. It consumes the receipt's own layer — never the allocator

Rejected quantity belongs to the layer its receipt line created (it was never issuable). The exit therefore charges **that exact layer** (like `reverseReceipt`'s consumption of a named layer), not FIFO/AVCO allocation, and
is limited to the quantity QA has finally rejected and not yet removed:

`available to exit (receipt line) = Σ qty of RETURN_TO_SUPPLIER + SCRAP dispositions of the line − Σ qty already exited`

An exit cannot exceed it, cannot touch usable, held or pending quantity, and cannot create stock. Each exit line references the receipt line it comes from, so the supplier, PO, price basis and batch/serial identity are known
without inference; an exit line can never mix receipt lines, and a document may carry lines from several receipt lines of the **same supplier** (return) or the same warehouse scope (scrap).

### 3. Grouped package evidence

For an item on grouped tracking the same transaction writes a new immutable successor, **`REJECTED_EXIT`** evidence, issue-shaped and anchored on the exit move (the pattern of `RECEIPT_REVERSAL`, ADR 0093):

- The person **declares the physical portion that leaves**: whole `REJECTED` sealed packages per size and `REJECTED` loose quantity, totalling the exit line's quantity. The server proposes it; the person confirms. Never inferred.
- **Sealed rejected packages leave whole.** Rejected loose quantity may leave as loose quantity. **Opening or splitting a rejected sealed package is never part of an exit** — the same rule as ADR 0094 §5. If the business needs to open
  or split rejected material (decant, salvage), that is a separate governed operation designed later; until then a partly-rejected can must be returned or scrapped whole, and the screen says so.
- Only `REJECTED` states change. Usable, held and any other rejected rows of the location are untouched (their per-layer attribution is preserved exactly as ADR 0094 §3 requires), so a location holding usable and rejected
  stock simply loses the rejected part.
- The database proves it at commit: the draw is `REJECTED`, equals the move quantity, the single allocation is the receipt layer, composition equals predecessor minus draw, `REJECTED` contributions at that layer equal
  `Σ rejected dispositions − Σ exited`, and every `SUPPLIER_RETURN`/`SCRAP_WRITE_OFF` move of a grouped item requires this evidence (the existing fail-closed rule, ADR 0091).
- Quantity, package evidence and cost stay independent: the move posts quantity and cost; the evidence says which packages left; cost is never an input to the evidence or the reverse.
- After the last rejected package leaves, the scope holds no held or rejected stock and counting is possible again.

### 4. Quantity-only stock uses the same operation

Same documents, same permissions, same layer-specific consumption and same cap; there is simply no package evidence and no portion to declare. The cap is the arithmetic above, derived from dispositions and prior exits.
This also gives quantity-only stock the exit it never had.

### 5. Valuation, cost and the accounting boundary

- **Cost.** FIFO and SPECIFIC items: the exit costs at the receipt layer's own unit cost (exact). AVCO items: the move leaves the pool at the **pool's current average**, as every AVCO outbound does ([ADR 0038](0038-company-wide-per-item-avco-pool.md)/[0039](0039-configurable-avco-valuation-pools.md)), so
  the value that leaves may differ from what the supplier originally charged; that difference is an **AP/price-variance matter**, not an inventory one, and is deferred with the credit-note handling below.
- **The feed.** The move writes a `stock_valuation_entries` row like any outbound, carrying the exit's `source_type` (`SUPPLIER_RETURN` or `SCRAP_WRITE_OFF`) so Phase 6 can map it. Phase 2 posts **no journal**.
- **Intended GL treatment (Phase 6, recorded now so the feed is shaped correctly):** *Supplier return* — credit Inventory at cost; debit the supplier liability / GR-IR (a credit the supplier owes or has not yet invoiced), plus
  a price-variance line where an AVCO average differs from the invoiced cost. *Scrap* — credit Inventory at cost; debit a scrap / inventory write-off **expense** (a loss). The two are distinguished by `source_type`, never by a flag buried in a
  note; changing the class later is not possible (a new correcting document is).
- **Supplier credit.** A supplier return records an **expected credit** snapshot (quantity × the receipt line's price basis, and currency) and an optional supplier RMA / credit-note reference as *information*. It creates no
  credit note, no AP entry and no matching: those arrive with supplier invoices in Phase 6. The return's status does not wait for them.
- **PO.** A return does not reopen or alter the PO or the receipt (ADR 0043); the receipt's quantities and the disposition rows stay exactly as posted. A replacement delivery is a normal new order/receipt (the existing replacement-order linkage).

### 6. Scope, batch and location

The exit leaves from the layer's own location and batch/serial identity; rejected stock cannot be transferred, so it is where it was received. Warehouse record scope (ADR 0089) applies to that location: a limited person only
sees and posts exits for their warehouses (404 outside, as for every scoped stock record), and the new routes join `RecordScopeCoverageTest`. Rejected stock in one warehouse is never exited by someone scoped to another.

### 7. Permissions, reasons and approvals

- **Capabilities** (new — physically removing stock and writing it off is a different duty from deciding QA, R14): `purchasing.supplier-return.create`/`.post` and `inventory.scrap.create`/`.post`, plus `inventory.package-state.view` for a
  grouped line; no new package capability. `purchasing.quality-disposition.post` is **not** reused: deciding that stock is bad and disposing of it are separately assignable (and separable duties for audit).
- **Mandatory reason** on every exit, stored on the document and the evidence; a supplier return also takes the supplier's reference where known, scrap a disposal method.
- **Approvals.** No new approval workflow in this slice. Existing purchasing/inventory policy does not require approval for QA decisions or physical counts; an approval threshold for scrap (a value limit through the shared approval
  engine, [ADR 0070](0070-shared-configurable-approval-engine.md)) is a later company policy decision, the same one deferred for count variances. Separation of duties is enforced where it is cheap and meaningful: the person who
  creates the document may not be the person who posts it only if the company sets that policy (decision below); the default is create-and-post allowed.
- **Rollout (R5):** register the capabilities; grant continuity to people who already hold the QA disposition and stock-adjustment duties only where the owner agrees (decision below); administrator re-sync; role recipe documented.

### 8. Retries, rollback and evidence

The document, the movement, the valuation feed row and the package evidence commit together or not at all. Each document line has a request key (the `request_key` pattern, ≤ 85 chars) and a payload hash; the same key and payload
returns the original, a different payload is refused; leg identities (`source_id`) derive deterministically from the key so a retry cannot repeat a movement. A retry re-checks authority and reuses the original moment. Posted documents and
their evidence are immutable; a mistake is corrected by a new, named correcting transaction (a "return reversal" — receiving the goods back — is **not** built here and would be its own decision).

### 9. Documents and screens

Both documents are numbered by the central numbering framework ([ADR 0079](0079-document-numbering-system-templates-over-a-variable-registry.md)) and listed under their module. Entry points, in order of need: the receipt's QA panel and the item card show
*Rejected awaiting exit* with **Return to supplier…** and **Scrap…** (a dialog proposing the declared portion for grouped stock, one editable quantity for quantity-only); a rejected-stock list later. Operator language: "returned to
supplier", "scrapped", "rejected, awaiting return or scrap" — no valuation or evidence terms. Phone width and the shared draw editor apply.

## Examples

1. **2 rejected sealed 5 L cans returned.** QA rejected 10 L (`RETURN_TO_SUPPLIER`). The person starts a Supplier Return for the receipt line, quantity 10 L; the screen proposes *2 sealed 5 L cans* from the rejected group; they confirm. One move leaves 10 L at the
   receipt layer's cost; `REJECTED_EXIT` evidence removes 2 cans; the expected credit (10 L × PO price) is recorded; the PO is untouched.
2. **1 rejected sealed 5 L can scrapped.** A Scrap Record for 5 L, portion *1 sealed can*, reason "damaged in storage". Same movement shape with `source_type SCRAP_WRITE_OFF`; the valuation feed marks it a loss; no supplier credit.
3. **Rejected loose litres.** 3 L were rejected from an opened delivery (loose). The exit takes 3 L loose from the rejected loose group; nothing sealed moves. Taking 3 L out of a rejected sealed can is refused ("a sealed can leaves whole").
4. **Usable and rejected together.** A location holds 3 usable cans + 5 L usable loose and 1 rejected can. Scrapping the rejected can leaves usable stock exactly as it was (3 cans + 5 L); the location can now be counted.
5. **Quantity-only item.** 8 kg were rejected and scrapped by QA. The same Scrap Record posts 8 kg out of the receipt layer; no packages, no portion.

## Consequences

- **Positive:** rejected stock has a normal exit for every item; cost stays allocator-free and exact for FIFO/SPECIFIC; evidence is conserved by the database; no GL or AP is invented before Phase 6; quantity-only parity is kept.
- **Negative:** a new document type per exit, new capabilities and a new evidence kind; AVCO returns can differ from the supplier's charge, left to AP; partly-rejected sealed cans cannot be split.
- **Requires (when accepted):** additive migration (`REJECTED_EXIT` kind with its conservation and `rejected = decided − exited` checks, exit tables/documents, move-evidence requirement for the two new `source_type`s), the layer-specific outbound path in the movement boundary,
  Actions and requests for both documents, capabilities and rollout, scope coverage entries, UI dialogs, journey and domain docs, tests (grouped, quantity-only, FIFO/AVCO/SPECIFIC, retries, rollback, scope, cross-company, parity) and a demo.

## Alternatives considered

**Issue the rejected quantity through the ordinary issue path** — rejected: the allocator deliberately excludes rejected quantity and would charge a different layer's cost; the exit must consume the receipt's own layer.
**One generic "disposal" without a supplier-return/scrap distinction** — rejected: the accounting class, the document, the permission and the supplier relationship all differ; the feed must classify by `source_type`.
**Post the GL journal now** — rejected: Phase 2 has no GL; the feed is the established boundary and Phase 6 owns balanced journals.
**Build the supplier credit note / AP matching with the return** — rejected: no supplier invoice or AP ledger exists; record the expected credit and reference as information and defer matching.
**Let a rejected sealed can be opened and part returned** — rejected: opening/splitting held or rejected material is a separate governed operation (ADR 0094 decision 5).
**Move rejected stock to a quarantine location instead of removing it** — rejected: ADR 0043 — location never substitutes for QA state; it would leave the stock on hand.
**Reuse `purchasing.quality-disposition.post` for the exit** — rejected: deciding quality and physically disposing of or writing off stock are different duties (R14).

## Decisions requested

1. **Two documents or one?** Recommended: two (Supplier Return in Purchasing, Scrap Record in Inventory) over one shared posting path. Alternative: a single "Rejected stock disposal" document with a kind.
2. **Must the exit kind match the QA outcome?** Recommended: **no** — a `RETURN_TO_SUPPLIER` quantity may later be scrapped (supplier refused it) and the reverse, with a mandatory reason; the cap is the total rejected. Alternative: strict match via separate rejected labels.
3. **New capabilities and grants:** four new capabilities (create/post for each) as above — agree; and whether existing holders of `purchasing.quality-disposition.post` / `inventory.physical_count.post` are given continuity grants or everything is granted deliberately (recommended: deliberate, with recipes).
4. **Create-and-post or draft then post?** Recommended: draft then post for both (a return needs paperwork), with same-person posting allowed unless the company sets a maker/checker policy.
5. **AVCO returns:** leave at the pool average and defer the price difference to AP (recommended) versus returning at the layer's original cost for AVCO items (would break the pool's average rule).
6. **Expected credit snapshot:** record quantity × price basis and an optional reference as information only (recommended) versus not recording it until AP exists.
7. **Scrap approvals:** none now; threshold policy through the approval engine later (recommended) versus a threshold now.
8. **Return reversal** (goods come back): not built; separate decision when needed (recommended).

## Related

- [ADR 0043](0043-control-purchase-order-receipt-and-qa-posting.md) · [ADR 0094](0094-package-aware-supplier-qa-receipts.md) · [ADR 0093](0093-package-aware-goods-receipt-reversal.md) · [ADR 0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md) · [ADR 0089](0089-organisation-units-and-record-scope.md)
- [stock.md](../domain/stock.md) · [purchasing.md](../domain/purchasing.md) · [general-ledger.md](../domain/general-ledger.md) · [package-issue-operations.md](../domain/package-issue-operations.md)
- [domain-integrity.md](../architecture/domain-integrity.md) · [authorization.md](../architecture/authorization.md) · [feature-completion-checklist.md](../architecture/feature-completion-checklist.md)
- [Package feature handoff](../phases/package-balance-handoff.md)
