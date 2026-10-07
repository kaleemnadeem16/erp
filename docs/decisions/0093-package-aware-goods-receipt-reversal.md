# 0093 — Package-aware goods receipt reversal: remove what is declared, never restore or invent a past composition

- **Status:** accepted (2026-10-08)
- **Date:** 2026-10-08
- **Affects:** Purchasing (goods receipt reversal), Inventory (receipt reversal posting, package kernel)

## Context

[ADR 0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md) refuses receipt reversal for an item with grouped package tracking, so a
wrongly posted receipt cannot be undone. This ADR makes reversal available without weakening the rule that evidence is never invented or rewritten.

Verified in code 2026-10-08. `ReverseGoodsReceipt` reverses a **whole** posted receipt (every line), needs `purchasing.goods-receipt.reverse` and a reason, and is
refused if any line has a QA disposition. For each line `ReceiptPoster::reverse` is deliberately narrow: exactly the one original inbound move, its **full original
quantity**, and only while that receipt's **layer is still untouched** (`qty_remaining = qty_received`; any issue, transfer or count that drew on it makes it
"partly consumed" and refuses), and for AVCO only while the pool still holds the quantity. It posts a `REVERSAL` move, consumes that one layer, and reverses the
valuation entry exactly. A grouped receipt line has a `RECEIPT_IN` leg recording what was observed arriving (sealed counts per tracked level plus loose quantity).

Two facts follow. Layer state and physical state are independent (ADR 0090): a layer can be untouched while the cans that arrived with it were physically used
(the allocator charged an older layer), or opened (a neutral `OPEN`), and the grouped model deliberately keeps no per-can provenance. So an untouched layer proves
nothing about which cans are still on the shelf, and the pre-receipt composition no longer exists to be restored.

## Decision

### 1. Reversal removes a declared physical draw, validated against current evidence

Reversing a grouped receipt line posts the existing `REVERSAL` move **and** a successor of the scope's latest evidence in which the **returned physical stock is
removed** — a draw of whole sealed packages and loose quantity (the same lines as an issue, no opening steps), whose total equals the line's original quantity
exactly. It never restores a past composition and never creates stock: the draw must be coverable by what is on hand now, counted by whole packages and
loose litres, or the whole reversal is refused. **Default:** if the line has a `RECEIPT_IN` leg, its originally observed composition is the proposed draw
(the common case: all received cans still sealed and present — one confirmation). When the stock has changed shape the person declares what is actually
going back instead.

### 2. The cases

| Situation | Outcome |
|---|---|
| All received sealed packages still present | the original observation is removed; success |
| Some received cans opened since (neutral opening) | allowed if the declared draw is on hand: e.g. 1 sealed can and 7 L loose go back; the evidence states exactly what was removed |
| Some received stock issued or transferred **from this receipt's layer** | **refused** by the existing layer check; correct with a package-aware issue or return and a count |
| Later issues charged older layers but physically used the received cans | the layer is untouched, so reversal is allowed; the declared draw must still be coverable, otherwise refused (the shortfall is a count or issue problem) |
| Declared draw exceeds current sealed counts or loose quantity | refused naming the shortfall; nothing posts |
| Receipt older than activation (no `RECEIPT_IN`) | no default; the person declares the returned draw, validated the same way |
| AVCO pool no longer holds the quantity | refused by the existing pool check |

The refusal rule is the point: when later operations depend on the received stock so that it cannot be safely unwound, **reversal is refused and a correcting
transaction is used** (a package-aware issue or transfer, a supplier return as a movement, or a recount), never a rewrite of later history.

### 3. Layers and cost stay separate from packaging

Valuation is exactly the existing reversal: the same move, the same valuation entry reversed at the original cost, the same AVCO pool arithmetic. The package
evidence only states which physical packages left. Its allocation line is the single reversed layer; the draw's contributions are relabelled so each layer total
equals the layer's remaining quantity (as for an issue), and cost is never an input to or output of that step.

### 4. Evidence, scope and the database

A new `RECEIPT_REVERSAL` evidence kind is an issue-shaped successor anchored on the reversal move, with its draw lines and allocation, and a unique
`reverses_operation_id` pointing at the `RECEIPT_IN` it undoes (one reversal per receipt line; absent for pre-activation receipts). At commit the database checks:
the move is a `REVERSAL` of an inbound move at this scope with `reverses_move_id` matching the reversed leg; draws and allocation equal the move quantity; per-layer
totals equal the predecessor less the allocation; composition equals the predecessor less the draw; it is the latest evidence and its move the only later movement.
The "stock move needs evidence" check accepts it for `REVERSAL` moves. A line is one item/location/batch scope; a receipt's lines reverse in ascending line order,
locking locations in ascending id order, several lines of one scope chaining their legs inside the transaction.

### 5. Full versus partial

Unchanged from today: a receipt reverses **whole** (all lines) at the **full** original quantity. Reversing one line, or part of a quantity, stays unsupported;
returning part of a delivery is a package-aware issue, which already exists, plus the purchasing correction. No new partial-reversal semantics are invented here.

### 6. Permissions, audit, idempotency

Existing `purchasing.goods-receipt.reverse` plus a mandatory reason (stored as today on the receipt and on every evidence leg) and, for grouped lines, a new
`inventory.package-state.reverse` with `.view`; warehouse scope applies to each line's location. No approval workflow, matching quantity-only reversal (a
threshold policy is a later company decision for both). An already-reversed receipt returns as today; each leg is unique per line, so a retry cannot repeat a
movement or an evidence row; any failed line (stale evidence, uncoverable draw, layer or pool check, database check) rolls back the whole reversal including
movements and evidence already written for earlier lines.

### 7. Quantity-only items

Unchanged: no draw, no evidence, the same refusals and the same result. The grouped path is selected by the item's activation; `ReverseGoodsReceipt` stops
refusing grouped lines and routes them here, and every other refused writer from ADR 0091 stays refused.

## Consequences

- **Positive:** a wrongly posted grouped receipt can be undone when it is genuinely untouched, with exact evidence of what went back; no history is rewritten;
  cost handling is exactly today's.
- **Negative:** reversal needs the person to confirm or declare the physical draw; once received stock has been consumed from its layer there is still no
  reversal (as for quantity-only items), only corrections.
- **Requires:** additive migration (`RECEIPT_REVERSAL` kind and link column, lineage/reconcile/anchor updates), capability `inventory.package-state.reverse`,
  `ReverseGoodsReceipt` accepting declared draws per line, tests for each case above, a demo.

## Alternatives considered

**Automatically remove exactly the originally observed composition** — rejected: those cans may have been opened or used; refusing a legitimate return of what is
physically there, or removing stock that is not, would both be wrong. Kept as the default proposal only.
**Restore the pre-receipt composition** — rejected: it no longer exists, and rebuilding it would invent state.
**Infer which cans came from the receipt** — rejected: the grouped model keeps no per-can provenance by design (container mode would).
**Allow reversal of a partly consumed layer by removing the rest** — rejected: it changes the existing, deliberate "full quantity, untouched layer" rule for every item.
**Rewrite later evidence** — rejected: evidence is append-only.
**Line-level or partial reversal now** — rejected: unsupported for quantity-only items; a separate Purchasing decision.

## Research basis

Local mechanics extending reused decisions (ADR 0082/0090/0091 on physical evidence versus cost, the existing narrow receipt reversal, ADR 0092's declared
composition); no new market comparison is needed. The general ERP pattern is that a goods-receipt reversal is a counter-posting limited to stock still
available, with later consumption blocking it (SAP movement-type reversal, Dynamics receipt cancellation) — corroborating, not a basis, since those sources were not re-read.

## Related

- [ADR 0090](0090-package-aware-stock-issue-semantics.md), [0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md), [0092](0092-package-aware-physical-count-posting.md)
- [Grouped issue, transfer, activation and count](../domain/package-issue-operations.md), [receipt evidence](../domain/receipt-package-evidence.md)
- [Delivery plan](../phases/package-balance-delivery.md), [handoff](../phases/package-balance-handoff.md)
