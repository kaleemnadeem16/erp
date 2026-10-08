[← Domain index](README.md)

# Grouped package-aware stock issue and transfer

Implemented backend slice of [ADR 0090](../decisions/0090-package-aware-stock-issue-semantics.md) (accepted). `IssuePackagedStock` posts an
ordinary stock issue and writes the successor package evidence in one transaction. Tracking activation is **not** part of this slice: the
item mode stays `QUANTITY_ONLY`, and quantity-only items keep posting exactly as before.

## What it does

The caller names a **predecessor** — the latest, fresh, reconciled evidence for one item/location/batch (a counted checkpoint or a later
saved successor) — and a **draw**, applied in order to a running composition:

| Line | Meaning |
|---|---|
| `SEALED {packaging_id, count}` | take whole sealed packages |
| `LOOSE {quantity}` | take from the single open/loose quantity |
| `UNPACK {packaging_id, count}` | first replace outer sealed packages with their inner sealed packages (a recorded `UNPACK` step) |
| `OPEN_AND_DRAW {packaging_id, count, quantity}` | first open whole sealed packages (a recorded `OPEN` step), then take a quantity from the result; the rest stays loose |

The posted quantity is the sum of the draw, exact at four decimals; a stated quantity must equal it. A partly used package is open: the
remainder joins the single grouped open/loose quantity and its origin is forgotten (no container identity in this mode).

## Order of work (one transaction)

1. Request advisory lock (shared with all package evidence writers), then the location lock.
2. Replay the predecessor; refuse unless it still matches current stock (otherwise "a fresh counted checkpoint is required" with the reasons).
3. Record any opening steps through `PostPackageOpening` (needs `inventory.package-state.open`).
4. Validate whole-package counts and loose quantity against the composition.
5. Post the issue through `PostInventoryMovement::issuePosted()`; take the **actual allocation vector** (layer, quantity), identical in shape for FIFO and AVCO.
6. Apply the draw, then relabel contributions so each layer's total equals the layer's remaining quantity (`PackageIssueComposition`). Cost is never an input.
7. Write the `ISSUE` operation, successor states and contributions, the draw lines and the allocation lines. Deferred database checks re-prove conservation at commit.

## Evidence and constraints

```text
inventory_package_operations   + stock_move_id (unique, ISSUE only), tracking_mode (PACKAGE_BALANCE only for now)
inventory_package_issue_draws        operation, line_no, SEALED|LOOSE, packaging, whole count, quantity, container_id (always NULL)
inventory_package_issue_allocations  operation, stock layer, quantity (unique per layer)
```

An `ISSUE` operation is a complete successor snapshot with `stock_move_watermark = stock_move_id`. The database checks: its predecessor is the
latest evidence in scope; its move is an issue from this item/location/batch and the **only** move after the predecessor's watermark; draws
and allocations each equal the move's quantity; sealed draws equal whole packages × content; per-layer contributions equal predecessor less
allocation; successor composition equals predecessor less the draw. The successor may be empty only when everything on hand was drawn.
`container_id` exists on draw lines for the future container mode and is forced empty (and `tracking_mode` constrained) until that slice.

## Guards and authority

Needs `inventory.package-state.issue` and `inventory.package-state.view` in the current company, rechecked on retries; opening needs
`inventory.package-state.open`; a person limited to their organisation units is refused outside their warehouses (ADR 0089). It refuses
SERIAL/SPECIFIC items, held or QA-dependent layers, open shortages, a stale predecessor, a back-dated issue and any attempt to attach
evidence to stock already issued for the same source. It never takes an override request, so no negative stock.
An equal retry returns the original move and evidence; a changed payload on the same key is refused.

## Transfer (2026-10-07)

`TransferPackagedStock` applies the same draw lines to a transfer and writes **two legs of evidence for one stock move**: `TRANSFER_OUT` at
the source (an issue-shaped successor of the source's evidence) and `TRANSFER_IN` at the destination (the destination's evidence plus what
arrived). It needs `inventory.package-state.transfer` and `.view` (and `.open` for an opening step), and scope at **both** ends.

| What moves | What arrives |
|---|---|
| whole sealed packages | the same packages, still sealed |
| loose quantity | loose quantity |
| part of a package opened at the source (`OPEN_AND_DRAW`) | **loose** at the destination; the unmoved remainder stays loose at the source |
| a tracked box (`SEALED` box) | a sealed box; or `UNPACK` it at the source first and the cans arrive sealed |

Nested levels follow the item's tracked levels: an untracked outer carton never exists as a state, so nothing about it moves. Arrivals merge
into the destination's existing states of the same identity, so there is still one open/loose quantity per scope.

The destination must either hold fresh, reconciled evidence (pass its latest operation as `destination_predecessor_id`) or hold no stock at
all (pass null; its chain then starts with the arrival). A destination holding stock without evidence, or with an open shortage, is refused
with "count first" and nothing posts. The destination's contributions are mapped, line by line in allocator order, onto the destination layers
the transfer actually created (`PostedMovement` carries each source layer and its destination layer); the source side is relabelled to the
source layers exactly as for an issue. Both locations are locked in ascending id order. Back-dated transfers, shortage settlement consuming
the arrival, serial/SPECIFIC items and stale evidence are refused; an equal retry returns both legs and the original move.

Database checks (migration `2026_10_07_110000`): each leg's lineage (latest evidence, its move the only later movement, destination chain may
start empty only where nothing else sits), and per-leg conservation — draws and allocations equal the move quantity, per-layer totals equal the
predecessor less (out) or plus (in) the allocation, composition equals predecessor less/plus the draw. The draw and allocation tables carry each
leg's lines despite their "issue" names. Quantity-only items transfer exactly as before.

Demo: `PackageTransferDemoSeeder` moves 7 L from the main depot to Karachi (one sealed can plus 2 L from a second can opened for it): source 1 can +
8 L loose, Karachi 1 can + 2 L loose, FIFO cost 58.00.

## Activation and enforcement (2026-10-08)

[ADR 0091](../decisions/0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md). Activation is one transaction: lock the item, then its
locations in id order; refuse unless the item is a stocked NONE/BATCH FIFO/AVCO item with no open shortage, no unfinished cutover that includes it and
no unexecuted recalculation request; every stocked scope has exactly one fresh counted checkpoint (the existing replay rules); write the activation, its
scopes and the item update. An equal retry returns the original; a changed payload, a second activation or a missing count writes nothing.

**After activation nothing can change the item's stock without package evidence or an explicit refusal:**

| Writer | Result for a grouped item |
|---|---|
| grouped issue, grouped transfer | allowed (they run inside `PackageWriteScope`) |
| goods receipt posting | package-aware: the observed composition is mandatory and becomes a `RECEIPT_IN` leg of the scope's chain (a scope holding nothing starts its chain there); QA-required lines and unobserved receipts are refused before anything posts |
| scalar issue, transfer, receipt; receipt reversal; physical count posting; cutover opening-state posting; recalculation request and execution; negative-stock override request; QA disposition; edits to valuation method, tracking, unit or stocked flag | refused with a message naming the operation |
| any other writer | stopped by the database |

The database guard is the guarantee: triggers on `stock_moves` and `stock_layers` reject any write to a grouped item's quantities unless the transaction
carries that item's flag (`erp.package_aware_item`, set only around a package-aware Action's own posting call), and a deferred trigger requires every
later move to have package evidence anchored on it by commit (issue; both transfer legs; a `RECEIPT_IN`). `StockWriterMatrixTest` lists every code path
that creates stock rows so a new writer must be given a decision. Not yet: count and receipt-reversal replacements, QA splitting, deactivation, container
tracking, screens. Activation scans for an unfinished cutover only where it includes the item, and for an unexecuted recalculation request for the item.

Demo: `PackageActivationDemoSeeder` counts a new item (3 cans + 5 L loose), activates it, then issues 7 L through the package-aware door.

## Physical count of a grouped item (2026-10-08)

[ADR 0092](../decisions/0092-package-aware-physical-count-posting.md). A count line of an item with grouped tracking counts a **composition**: whole sealed
packages of the item's *tracked levels* plus one open/loose quantity (`RecordPhysicalCountComposition`; the counted quantity is derived and blind counting is
unchanged). The line captures the scope's latest evidence and movement watermark when added (`AddPhysicalCountLine` via `PackageCountCapture`); a stale line is
re-snapshotted and recounted with `RefreshPhysicalCountLine`, never rebased. A scalar `RecordPhysicalCountLine` is refused for a grouped line.

`PostPhysicalCount` (needs `inventory.physical_count.post`, a poster distinct from the creator, and `inventory.package-state.recount` + `.view` for grouped
lines) proves every grouped line fresh first, so one stale line refuses the whole count and names it. Then, per line, in the count's single transaction:

| Counted vs book | Stock | Evidence |
|---|---|---|
| equal | **no movement** | a `COUNTED` operation linked to the line replaces the composition over the same layers |
| less | the governed issue of the difference (allocator-led cost, never an override) | a `COUNT_VARIANCE` operation anchored on that move whose successor is the counted composition |
| more | the governed receipt of the difference at the reviewer-supplied found cost | the same, with the new layer in its allocation |

`COUNT_VARIANCE` is checked by the database at commit like the other movement legs, except that its composition is the count's finding rather than a declared
draw: allocations equal the move, per-layer totals equal the predecessor less (loss) or plus (gain) the allocation, and the successor total equals the
predecessor total adjusted by the variance. Nothing is backdated, a retry cannot double-post (evidence is unique per count line), and any failed line rolls
back every earlier line's movement and evidence. Quantity-only items count exactly as before.

**Tracked levels (ADR 0092 §1a).** Activation fixes the sealed definitions an item tracks (`inventory_package_tracked_levels`; by default what the opening
count used, closed over the packages inside; an explicit list may add outer levels). After that no evidence state may name any other definition: a database
trigger refuses it for every writer, and counts and receipts say so first. Changing tracked levels is not a count outcome and is not built.

Demo: `PackageCountDemoSeeder` — a recount (1 can + 7 L, ledger right, no movement) and then a 1 L loss (1 can + 6 L, governed issue at 8.00) on XYZ's emulsion.

## Receipt reversal of a grouped item (2026-10-08)

[ADR 0093](../decisions/0093-package-aware-goods-receipt-reversal.md). `ReverseGoodsReceipt` keeps its whole-receipt, full-quantity semantics; for a line of an item
with grouped tracking it calls `ReverseGroupedReceiptLine` (needs `purchasing.goods-receipt.reverse` plus `inventory.package-state.reverse` and `.view`, a mandatory
reason, warehouse scope). The existing stock reversal runs unchanged — only while the receipt's layer is untouched, AVCO pool sufficiency, the same valuation
entry reversed — and a `RECEIPT_REVERSAL` successor **removes the declared physical draw** from the scope's latest evidence. It never restores the pre-receipt
composition and never invents state.

The draw is sealed packages and loose quantity (no opening), must total exactly the line's received quantity and must be on hand now. Omitted, it defaults to
the composition the receipt's own `RECEIPT_IN` evidence recorded; a receipt that predates activation has none, so the draw must be stated. Passing
`$packageDraws[lineId]` to `ReverseGoodsReceipt` declares it. Anything that cannot be safely unwound is refused with nothing posted: a layer already
drawn on (use a package-aware issue or return plus a count), a draw that exceeds the current sealed counts or loose quantity, a stale scope.

The database checks (migration `2026_10_08_130000`) that the move is the `REVERSAL` of an inbound move at this scope, that the evidence names the `RECEIPT_IN` leg it undoes
(unique, so one reversal per receipt line), that draws and allocation equal the move quantity, per-layer and composition conservation, and that every reversal
move of a grouped item carries this evidence. A retry is idempotent and any failure rolls back the whole reversal. Quantity-only receipts reverse exactly as before.

Demo: `PackageReceiptReversalDemoSeeder` reverses a wrong delivery of 2 sealed cans back to the supplier while a second delivery stays.

## Not yet

Future enhancements (none started; each is a new design slice, see the [handoff](../phases/package-balance-handoff.md)): supplier return / scrap movement for rejected stock, a governed change of tracked levels, deactivation or collapse, container-level tracking, line-level or partial receipt reversal, count approval thresholds, QA/count reconciliation, standalone Issue/Transfer screens, package-aware proposals in the Material Preview. Writers that stay refused for a grouped item (with a message and a database guard): cutover opening state, recalculation, negative-stock overrides, shortage settlement and scalar issue/transfer/count.

## Demo and verification

`PackageIssueDemoSeeder` (create-once, real Actions): XYZ Paints, layers 6 L at 8.00 and 14 L at 10.00, counted as 3 cans + 5 L loose; request
`demo-xyz-issue-12l` takes 12 L (loose 5 L, one can, 2 L from a second opened can), leaving 1 can + 3 L loose, FIFO cost 108.00. Tests: allocation
seam (7), grouped issue (FIFO and AVCO, both 12 L draws, nested box unpack, empty successor, retries, rollback on evidence failure, database
conservation, container refusal, invalid draws, stale-evidence-then-recount transition, quantity-only unchanged, authority, scope) and the demo.

## Granting the package capabilities (rollout, 2026-10-08)

Capabilities are ordinary role permissions (company roles via the Access screens); there are no package-specific role semantics. Release steps are the standard ones: `permission:register`, `erp:sync-admin-permissions` for the administrator, and migration `2026_10_08_140000`, which **continues existing duties only**: holders of `inventory.stock.view`, `inventory.physical_count.post` or `purchasing.goods-receipt.reverse` get `inventory.package-state.view`; `physical_count.post` holders also get `.recount`; `goods-receipt.reverse` holders also get `.reverse`. A count-only role is never granted `.view` (it counts blind). `.activate` (one-way) and the new duties `.issue`, `.open`, `.transfer` are never granted by inference.

Suggested role recipes, built in the role editor: **Grouped stock handler** — `view`, `issue`, `open`, `transfer`; **Grouped stock controller** — the handler's set plus `recount` (with `physical_count.post`) and `reverse` (with `purchasing.goods-receipt.reverse`); **Grouped tracking owner** — `activate`. The demo assistant (`demo-ops`) holds the handler recipe and `demo-count-reviewer` the reviewer set.

**Rejected-stock exit recipes (ADR 0097):** the four capabilities are dedicated and nothing grants them by inference. **Rejected-stock preparer** — `purchasing.supplier-return.create`, `inventory.scrap.create`, plus the reads (`purchasing.goods-receipt.view`, `inventory.package-state.view`, `inventory.stock.view`); drafts only, someone else posts. **Rejected-stock controller** — the preparer's set plus `purchasing.supplier-return.post`, `inventory.scrap.post` and `inventory.valuation.view` (the only recipe that sees the stock value per unit). **Site returns clerk** — prepare and post, with the role limited to *My units*, so only the warehouses of the person's own organisation units are reachable (a limit applies to what a role grants, never to a direct grant). The demo creates all three (`demo-exit-preparer`, `demo-exit-controller`, `demo-exit-clerk`; demo password) in `RejectedStockExitDemoSeeder`; real companies build them in the role editor. After release an administrator who should hold the capabilities runs `permission:register` and `erp:sync-admin-permissions <email>` (the same re-sync as for the package capabilities).

## Supplier QA of a grouped item (ADR 0094, 2026-10-08)

A QA-required receipt line of a grouped item still needs its observed composition, but every package of it arrives `HELD`: part of on-hand and of the location total, shown as "held for inspection", never issued, transferred, opened or counted as usable. `PostQualityDisposition` (unchanged for quantity-only items) takes, for a deciding outcome, the declared `package_portion` — whole held sealed packages and loose quantity totalling the disposition quantity — and in the same transaction writes a `QA_DISPOSITION` evidence successor: `PASS`/`ACCEPT_WITH_CONCESSION` relabel the portion usable, `RETURN_TO_SUPPLIER`/`SCRAP` relabel it `REJECTED`; `REWORK`/`PENDING_DECISION` change nothing. No stock movement, layer or cost changes. `GET /goods-receipt-lines/{line}/quality-disposition-portion?quantity=` proposes the portion (largest held cans first, then loose) only when it adds up exactly. A reversal of a QA line nobody has dispositioned removes its held packages; once any disposition exists reversal stays refused. Counts are refused while a scope holds held or rejected packages. Rejected packages stay on hand until a supplier-return/scrap movement exists (not built, as for quantity-only stock). Demo: `PackageQaDemoSeeder`.

## Rejected-stock exit — shared core (ADR 0097, slice 1, 2026-10-08)

Rejected stock — a terminal `RETURN_TO_SUPPLIER` or `SCRAP` QA quantity — leaves for good through `PostRejectedStockExit`, the shared posting core of the future Supplier Return and Scrap documents (no HTTP route or screen yet). It needs `purchasing.supplier-return.post` or `inventory.scrap.post` (dedicated, not inferred from QA or stock capabilities), warehouse scope on the delivery's location and, for a grouped line, `inventory.package-state.view`. It consumes **the receipt's own layer** for at most *rejected − already exited* (`RejectedStockBalance`), never the allocator, so usable, held and pending stock cannot be touched: FIFO/SPECIFIC at that layer's cost, AVCO at the pool average. The movement is an ordinary outbound `ISSUE` whose `source_type` (`SUPPLIER_RETURN` or `SCRAP_WRITE_OFF`) is the accounting class for the valuation feed; no journal and no supplier credit are posted. The immutable `rejected_stock_exits` row records the kind, quantity, the part that matches the QA decision of its own kind and the part that differs (which requires an outcome-change reason), the reason, request key and actor.

For a grouped item the declared portion — whole `REJECTED` sealed packages and `REJECTED` loose quantity, never opening or splitting a package — is removed by a `REJECTED_EXIT` evidence successor in the same transaction; usable and held rows are untouched. A quantity-only item declares no portion and writes no evidence. The database re-proves, at commit: the exit matches its move and delivery layer, never exceeds what QA rejected, has evidence if the item is grouped, and the evidence conserves quantity and leaves rejected package stock equal to *rejected − exited*. A supplier-return or scrap movement without its ledger row cannot commit, and the ledger is append-only. Retries return the original for an identical payload and refuse a changed one; the whole exit rolls back on any failure. Reason codes: `NOTHING_REJECTED_TO_EXIT`, `OUTCOME_CHANGE_REASON_REQUIRED`, plus `NEEDS_DECLARED_PORTION`, `DRAW_NOT_ON_HAND` and `STALE_EVIDENCE`. Availability and overview figures no longer count exited rejected stock as held, and released stock stays issuable from the same layer.

## Supplier Return and Scrap documents (ADR 0097, slice 2, 2026-10-08)

Two **separate documents** sit above the shared exit core, with the four layers kept distinct: the **QA disposition** is the quality decision; the **Supplier Return** (Purchasing, `purchasing.supplier-return.create/.post`) or **Scrap** (Inventory, `inventory.scrap.create/.post`) is the actual later business outcome; the **inventory exit** (`PostRejectedStockExit`) is the stock and valuation effect; settlement in accounts payable and the ledger is future (Phase 6). A document is `DRAFT → POSTED | CANCELLED`, belongs to one warehouse, and lists delivery lines with a quantity, an outcome-change reason when it differs from the QA decision and, for a grouped item, the declared whole-package portion. **Drafting and editing move nothing** — no stock, valuation or package evidence — and a draft is only a proposal that posting re-proves; **posting is the one atomic point** that exits every line, numbers the document (`SRN-`/`SCR-` by default) and freezes it, and it is idempotent. The database freezes a document once it leaves draft and refuses a POSTED document without a matching exit for every line. A return's expected supplier credit (amount, currency, reference) is information only: it never values inventory and creates no credit note, supplier balance or journal. A person limited to their units sees and acts on documents of their own warehouses only; reads need the create or post capability of that document. `GET goods-receipt-lines/{line}/rejected-stock` and the two `…/preview` endpoints show, before anything is drafted or posted, the rejected quantity left, the packages that would leave, the cost basis and every blocker.

**Screens (slice 3):** Supplier Return and Scrap each have a list, a form (create and edit while draft) and a detail page, entered from a delivery's QA panel (*Return to supplier…*, *Scrap…*, shown only when rejected stock is left and the person may prepare that document) or from the item's package card. The form previews each line before anything is saved — rejected and remaining quantity, the whole-package portion for a grouped item, the reason needed when the exit differs from the QA decision — and tells a person at once that a supplier return holds one supplier's deliveries and a document one warehouse (the server still refuses a mix). The detail page re-previews a draft, posts or cancels it with a plain consequence, and shows a posted or cancelled document frozen. The expected credit is labelled *information only*; the layer's unit cost appears only for `inventory.valuation.view`. See [rejected-stock-exit-plan.md](../phases/rejected-stock-exit-plan.md).

## Related

- [ADR 0090](../decisions/0090-package-aware-stock-issue-semantics.md), [0091](../decisions/0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md), [0092](../decisions/0092-package-aware-physical-count-posting.md), [0093](../decisions/0093-package-aware-goods-receipt-reversal.md), [0094](../decisions/0094-package-aware-supplier-qa-receipts.md); [ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md) (superseded by 0090–0094)
- [Saved opening and unpacking](package-opening-operations.md), [package kernel](package-balance-integration.md)
- [Screens and API, with the v1 sign-off](../phases/package-balance-ui.md), [delivery plan](../phases/package-balance-delivery.md), [handoff](../phases/package-balance-handoff.md)
- [User journey](../journeys/grouped-package-tracking-journey.md)
