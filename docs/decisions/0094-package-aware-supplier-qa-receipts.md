# 0094 — Package-aware supplier QA: held packages stay in on-hand, a disposition relabels a declared physical portion

- **Status:** proposed (2026-10-08)
- **Date:** 2026-10-08
- **Affects:** Purchasing (receipt posting, QA disposition), Inventory (package kernel, issue/transfer/count/reversal planners)

## Context

[ADR 0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md) refuses a QA-required receipt line for an item with grouped package tracking
(`SUPPLIER_QA_UNSUPPORTED`), so a company that inspects deliveries cannot use grouped tracking for those items. [ADR 0082](0082-reconcile-package-balances-with-stock-operations.md) §1
already fixed the principle: *held stock is part of physical on-hand and excluded from usable availability; accepting a hold does not open a package; cost never moves.* This ADR applies it.

Verified in code 2026-10-08. Quantity-only QA ([ADR 0043](0043-control-purchase-order-receipt-and-qa-posting.md)): a QA-required purchase-order line makes the whole received quantity sit
in its receipt layer and in on-hand, but not **issuable**: `LayerAllocator::issuableQuantity` offers only the quantity released by `PASS` or `ACCEPT_WITH_CONCESSION` dispositions, less what was
consumed. `PostQualityDisposition` posts an immutable, idempotent, quantity-only row (`PASS`, `ACCEPT_WITH_CONCESSION`, `REWORK`, `PENDING_DECISION`, `RETURN_TO_SUPPLIER`, `SCRAP`), terminal for
all but `REWORK`/`PENDING_DECISION`, which can be dispositioned again through `source_disposition_id`; capacity is the line's received quantity (or the parent's). **It posts no stock movement**
(`resulting_stock_move_id` is never written), so rejected, returned and scrapped quantity stays in on-hand, never issuable, until a physical removal workflow that does not exist yet. A line
with any disposition cannot be reversed. The package kernel already carries `availability` on every state (`USABLE`/`HELD`), conserves composition grouped by it, and issue and transfer draw only
from `USABLE`. Receipt evidence for a QA line is currently refused before it can write `HELD` rows.

## Decision

### 1. Before disposition: the arrival is observed and every package is HELD

A QA-required line of a grouped item is received like any other: the observed composition (sealed packages of tracked levels plus loose quantity) is mandatory and recorded in the `RECEIPT_IN`
leg, but **all of its states are `HELD`**, attributed to the receipt's single layer. Stock quantity, valuation and the layer are exactly today's. Held packages are counted in the location's total
and shown as a separate "held for inspection" group; they cannot be issued, transferred, opened or counted as usable. `SUPPLIER_QA_UNSUPPORTED` is removed.

### 2. A disposition of a grouped line declares the physical portion

`PostQualityDisposition` stays the only QA authority and is unchanged for quantity-only items. For a grouped item it additionally takes the **declared physical portion** the disposition
concerns — whole sealed packages per tracked level plus loose quantity, which must come from the line's `HELD` packages and total exactly the disposition quantity. The server proposes it
(everything still held when the disposition covers the whole pending quantity; otherwise the nearest coverable draw) and the person confirms or edits. In the **same transaction** as the disposition row it writes an
immutable `QA_DISPOSITION` evidence successor of the scope's latest evidence, linked one-to-one to the disposition. No stock movement and no cost: evidence relabels, it never creates stock.

| Outcome | Package evidence | Becomes |
|---|---|---|
| `PASS`, `ACCEPT_WITH_CONCESSION` | the declared portion `HELD → USABLE` | normal usable package stock (issuable, transferable, openable); matches `issuableQuantity` |
| `RETURN_TO_SUPPLIER`, `SCRAP` | the declared portion `HELD → REJECTED` (a new availability label) | on-hand but never usable, shown as "rejected — awaiting return or scrap", until a physical removal workflow exists |
| `REWORK`, `PENDING_DECISION` (non-terminal) | none: the portion stays `HELD` and can be dispositioned again | still held |

A sealed package is never opened to satisfy a disposition: a declared portion that the held sealed and loose counts cannot supply is refused naming the shortfall. Splits are exactly the existing
disposition splits: several dispositions per line, child dispositions of a non-terminal one, each declaring its own portion.

### 3. The database proves it

`QA_DISPOSITION` is a complete successor like an issue. At commit it is checked that: it links to a disposition of a receipt line at this scope; its composition equals the predecessor with the
declared portion moved from `HELD` to the outcome's availability (total unchanged, per-layer totals unchanged, so no movement is required); the portion totals the disposition quantity; and — a
cross-check between QA and evidence — the `HELD` contributions at the receipt layer always equal that line's still-pending quantity (received less every terminal disposition), so evidence and the QA table cannot drift.
The "stock move needs evidence" check is unaffected (no move). The existing issue/transfer conservation functions already ignore anything but `USABLE`; the reversal function gains the `HELD` selection in §5.

### 4. Cost, layers and permissions

Valuation and the allocator are untouched: dispositions never alter a layer or a cost, and `issuableQuantity` keeps governing which layers an issue may charge. Authority is the existing
`purchasing.quality-disposition.post` plus `inventory.package-state.view`, with warehouse scope on the line's location — no new capability and no new approval rule (an approval threshold, if wanted,
is the same later decision as for counts). Idempotent per disposition key; the disposition row and its evidence commit or roll back together.

### 5. Interactions

- **Receipt reversal** (ADR 0093) stays refused once any disposition exists. A QA line with none reverses by removing the declared draw from its `HELD` packages (the draw selection learns `HELD` for QA lines).
- **Physical count** refuses a grouped line whose scope holds `HELD` or `REJECTED` packages ("finish the QA dispositions first"), because a counted composition carries no availability and would silently turn held stock usable. Counting held packages separately is a later decision.
- **Activation** keeps refusing an item with QA-dependent layers (ADR 0091 §2).
- **Issue, transfer, opening** use `USABLE` states only (today's behaviour); a transfer moves only usable packages and leaves held ones at the source.
- **Batch/serial:** unchanged (batch scopes work as today; serial stock stays unsupported).

### 6. Screens

The receipt composition panel stops refusing QA lines and says the delivery will be held for inspection. The existing "Post QA disposition" panel on a posted receipt gains, for grouped lines only, a
"which packages" step: the proposed portion (editable with the shared draw editor limited to held packages), what is held now, and the plain-words result ("2 cans released to stock", "1 can rejected — awaiting return"). The item card breakdown shows held and rejected groups beside usable.

## Consequences

- **Positive:** QA-inspected deliveries can use grouped tracking; usable stock stays exactly what QA released; no history rewritten; cost untouched; the quantity-only QA path is unchanged.
- **Negative:** disposing a grouped line needs a physical declaration (one confirmation in the common whole-delivery case); a partly consumed held can cannot be released as a sealed can; counts are blocked in a scope while stock is held.
- **Requires:** additive migration (`REJECTED` availability, `QA_DISPOSITION` kind and link column, its conservation/pending-quantity function, `HELD` selection for reversal), `RecordReceiptPackageEvidence` writing `HELD` states,
  `PostQualityDisposition` routing grouped lines, count refusal, a preview/propose endpoint, UI step, tests for every outcome/split/refusal, a demo.

## Alternatives considered

**Treat held stock as loose until released** — rejected: it destroys the observed composition and would make a released can look opened.
**Keep rejected portions `HELD`** — rejected: pending and decided-bad stock would be indistinguishable on the shelf.
**Infer the released packages from the quantity** — rejected: the model keeps no per-package provenance; inference would invent state (ADR 0090 §3).
**A separate QA capability for the package step** — rejected: the QA authority already decides the outcome; the evidence is its consequence.
**Move rejected stock out at disposition** — rejected here: ADR 0043 posts no movement at disposition today; a supplier-return/scrap movement is its own Purchasing/Inventory decision.

## Decisions requested

1. Label terminal non-releasing portions `REJECTED` (recommended) versus leaving them `HELD`.
2. Refuse physical counts while a scope holds held or rejected packages (recommended) versus counting them separately now.
3. Require the declared physical portion on every grouped disposition, proposed by the server (recommended) versus deriving it silently when the whole line is disposed.
4. Reuse `purchasing.quality-disposition.post` + `inventory.package-state.view` with no new capability (recommended).
5. Refuse releasing a sealed package that is not wholly held (recommended) versus allowing opening of held packages.

## Related

- [ADR 0043](0043-control-purchase-order-receipt-and-qa-posting.md), [0082](0082-reconcile-package-balances-with-stock-operations.md), [0090](0090-package-aware-stock-issue-semantics.md), [0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md), [0093](0093-package-aware-goods-receipt-reversal.md)
- [Receipt package evidence](../domain/receipt-package-evidence.md), [grouped issue, transfer, count and reversal](../domain/package-issue-operations.md)
