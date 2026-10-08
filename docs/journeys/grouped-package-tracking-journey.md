[← Product journeys](README.md)

# Journey — Tracking paint by sealed can and loose litre

- **Implementation status:** implemented (grouped package tracking v1, 2026-10-08)
- **Audience:** inventory controller (activates and counts), receiving clerk, QA inspector, storeman (issues and transfers), count reviewer
- **Starting point:** an item's page → *Tracked by quantity only* → **Start grouped tracking**
- **Successful end state:** the item's stock is known by physical form — whole sealed packages of the tracked sizes plus one loose quantity — at every location, and every movement,
  count, reversal and QA decision keeps that picture true
- **Last verified:** 2026-10-08 (Playwright: lifecycle, transfer, count, reversal, QA, phone width, quantity-only parity)

## Purpose

A company that sells or uses paint by the litre but handles it in 5 L cans needs to know, for example, "3 sealed cans and 5 L loose" — not just "20 L". The litres in the ledger stay
authoritative and cost stays with FIFO/AVCO; the package picture explains the stock's *form* and is proven consistent by the database. Items that were never switched on behave exactly as
before.

## Before you start

- A stocked item valued FIFO or AVCO (batch-tracked is fine; serial and SPECIFIC are not supported), with named package definitions (e.g. *Sealed 5 L can*) — [inventory-packages.md](../domain/inventory-packages.md).
- Capabilities from `inventory.package-state.*` (view, activate, issue, open, transfer, recount, reverse) plus the usual receiving, counting and QA permissions. Existing count, receipt-reversal and
  stock-view holders are carried over automatically; activation and the issue/open/transfer duties are granted deliberately —
  [role recipes](../domain/package-issue-operations.md#granting-the-package-capabilities-rollout-2026-10-08).
- A fresh count of every location that holds the item (the wizard collects it).

## Journey map

```text
Activate → Receive (usable, or held for QA) → [QA decision] → Issue / Transfer → Count → Reverse a wrong delivery
```

## Steps

1. **Activate** — item page → *Start grouped tracking*. The wizard shows what stands in the way, asks which sizes to track, takes an opening count per location (sealed cans per size; the loose litres are the
   rest) and activates. *System:* one atomic, database-checked change; there is no way back for now.
2. **Receive** — draft goods receipt → *What arrived*: sealed packages per tracked size and the loose rest must be recorded before *Post*. *System:* posts the stock and the package evidence together.
   A line that needs supplier inspection arrives **held**: counted in the location total, shown as "held for inspection", never issuable.
3. **QA decision** — posted receipt → *Post QA disposition*. For pass/concession/return/scrap the screen asks **which packages this applies to** (proposed, editable; a sealed can moves whole). Released packages
   become usable; rejected ones are shown apart. Rework/pending leave the packages held.
4. **Return or scrap rejected stock** — the delivery's QA panel (or the item's package card) → *Return to supplier…* / *Scrap…*. A QA decision of "return" or "scrap" only marks the stock; this is the actual exit. Pick the delivery
   lines, confirm the quantity and — for a grouped item — the rejected packages that leave (a sealed can leaves whole), say why, and *Save draft*; nothing has moved yet. If the exit differs from the QA decision the screen asks why.
   On the draft, review the preview and *Post*: the stock leaves the warehouse and the document is numbered and frozen. A supplier return goes to one supplier and a document covers one warehouse. The expected credit is for
   reference only. Someone who may only prepare drafts leaves the posting to a person who may post.
5. **Issue** — item page → location row → *Issue stock*: say how much you need; the screen proposes loose first, then whole cans, then one can opened for the last litres, and shows what is left. Confirm.
6. **Transfer** — *Transfer stock…*: pick the destination and quantity; the screen shows both balances before and after. Whole cans arrive sealed, loose and opened-can litres arrive loose. A destination that holds stock
   with no current package count blocks the transfer and offers *Count this location*.
6. **Count** — physical count → a grouped line is counted by package (blind counters see no book figure). A reviewer sees whether the count is a *composition fix* (same litres, different cans: no stock movement) or a
   *variance* (short or found, found stock needs a cost). A line whose stock moved after capture turns red and is refreshed and recounted. A different person posts.
7. **Reverse** — posted receipt → *Reverse receipt*: the screen proposes the packages that arrived, shows what is on hand now, and lets you change what physically goes back when stock changed form.

## Status and consequence table

| Object | State | Meaning | Possible / blocked |
|---|---|---|---|
| Item | Quantity only → grouped | One-way switch from a reconciled count | Issue, transfer, count, reversal and QA become package-aware; scalar issue/transfer/count are refused |
| Packages | Usable | Normal stock | Issue, transfer, open |
| Packages | Held | Received, awaiting QA | In the total, never issued, transferred or counted |
| Packages | Rejected | QA decided not usable | On hand until a posted Supplier Return or Scrap takes it out |
| Location evidence | Fresh / needs a count | Whether the package picture matches the ledger | Stale or missing evidence blocks issue/transfer until recounted |

## Exceptions and corrections

Every refusal carries a stable reason (`NEEDS_COUNT_FIRST`, `STALE_EVIDENCE`, `DRAW_NOT_ON_HAND`, `LAYER_CONSUMED`, `NEEDS_OBSERVED_COMPOSITION`, `NEEDS_DECLARED_PORTION`, `QA_STOCK_HELD`, …) and the screen says what to do.
A wrong delivery is reversed only while untouched (any use of its own stock, or any QA decision, blocks it); otherwise correct with an issue or return and a recount. History is never rewritten: counts and QA decisions are new
immutable evidence.

## Not yet available

Changing tracked sizes; switching an item back; individual-vessel (container) tracking; partial receipt reversal; count approval thresholds; standalone Issue/Transfer menu screens;
package-aware proposals in the Material Preview. See the [handoff](../phases/package-balance-handoff.md).

## Source of truth

[package-issue-operations.md](../domain/package-issue-operations.md) · [package-balance-ui.md](../phases/package-balance-ui.md) (screens, API, v1 sign-off) · ADRs [0090](../decisions/0090-package-aware-stock-issue-semantics.md)–[0094](../decisions/0094-package-aware-supplier-qa-receipts.md).

## Related

- [Product journeys](README.md) · [Phase 2 operational journey](phase-2-operational-journey.md) (the quantity-only flow this extends)
- [Package feature handoff](../phases/package-balance-handoff.md)
