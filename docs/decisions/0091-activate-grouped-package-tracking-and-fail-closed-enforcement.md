# 0091 — Activate grouped package tracking atomically, and enforce it fail-closed in the database

- **Status:** proposed
- **Date:** 2026-10-07
- **Affects:** Inventory (item policy, every stock writer, package kernel), Purchasing receipts/QA/reversal, cutover, recalculation, negative stock

## Context

[ADR 0052](0052-progressive-inventory-package-tracking.md) (accepted) pins an item's tracking mode at creation and requires a reconciled
count before an upgrade; [ADR 0082](0082-reconcile-package-balances-with-stock-operations.md) §5 says activation needs a reconciled opening
state and that every writer must be integrated or guarded; [ADR 0090](0090-package-aware-stock-issue-semantics.md) built grouped issue and
transfer. Today the mode column is immutable and constrained to quantity-only, so "authoritative" only means "has fresh counted evidence".
This ADR settles how an item becomes authoritative and how the system guarantees nothing can then change its stock without package evidence.

Verified 2026-10-07. Stock is written (a) through `PostInventoryMovement` — receipt, issue, transfer, receipt reversal (callers: goods receipt
posting, goods receipt reversal, physical count posting, grouped issue/transfer) — and (b) by **direct writers that bypass it**: cutover
`OpeningStateItemPoster`, and recalculation (`ExecuteRecalculation`, `OutboundRecalculationExecutor`, `FifoReceiptReallocator`,
`LayerCounterfactualReplayer`); `ShortageSettler` edits layers inside receipts/transfers; QA dispositions change *availability* without a move.
A list of writers kept in code review will rot, so the guarantee must not depend on the list being complete.

## Decision

### 1. State stored on the item, and one atomic transition

`items.package_tracking_mode` becomes `PACKAGE_BALANCE` **only** through the activation Action, and `items.package_tracking_activation_id`
(new, immutable, FK to an immutable `inventory_package_activations` row) is set in the same statement. A CHECK ties them: mode
`PACKAGE_BALANCE` ⇔ activation id present. There is **no "pending" or half state**: an item is `QUANTITY_ONLY` (nothing enforced, nothing
advertised) or `PACKAGE_BALANCE` (enforced everywhere). Company and category defaults stay `QUANTITY_ONLY`; ADR 0052's creation-time pinning is
unchanged. `package_tracking_source` keeps its original provenance; the activation row records the prior mode.

`inventory_package_activations` (company, item, unique request key, payload hash, actor, reason, prior/new mode, activated_at) has child rows
`inventory_package_activation_scopes` (activation, location, batch, the counted/latest evidence operation used, quantity). Both are append-only.

### 2. Activation: a reconciled count of every scope, validated at the flip

`ActivateGroupedPackageTracking` (new capability `inventory.package-state.activate` plus `.view`, reason mandatory) takes the item and, for each
location/batch scope that **holds stock**, the latest fresh counted checkpoint. In one transaction it locks the item row, then every location
holding the item in ascending id order, and refuses unless: tracking is NONE/BATCH and valuation FIFO/AVCO; no QA-dependent layer, open
shortage, or unfinished cutover/recalculation request exists for the item; every stocked scope has exactly one fresh evidence operation
(latest, matching layers, ledger total and movement watermark — the existing replay rules); and no checkpoint is offered for an empty scope.
Scopes holding no stock need nothing: their chain starts when stock first arrives. It writes the activation, its scopes and the item update.

The same readiness is **re-proved by the database at the flip**: a trigger on `items` allows `QUANTITY_ONLY → PACKAGE_BALANCE` only if the
activation row exists and every scope with positive layers has latest evidence whose `stock_move_watermark` equals that scope's maximum move id.
So the mode cannot be set by any other path, and not on stale evidence. A read-only `PreviewGroupedActivation` lists blockers per scope.
There is **no deactivation** (ADR 0052: refused while detail would be lost); a collapse path needs its own decision. Equal retry returns the
original activation; a changed payload on the key is refused; any failure leaves the item `QUANTITY_ONLY` with nothing written.

**Before the count is committed** the item is simply quantity-only: counted checkpoints may exist, scalar posting continues to work exactly as
today, and a movement after a checkpoint just makes it stale (activation then refuses with the reason). Concurrent writers cannot slip through the
flip: they lock the same location rows, and anything arriving after it meets the database guard below.

### 3. Fail-closed enforcement — the guarantee does not depend on a writer list

For an authoritative item (mode `PACKAGE_BALANCE`):

1. **Immediate guard.** BEFORE INSERT/UPDATE triggers on `stock_moves` and `stock_layers` (quantity columns) reject the write unless the
   transaction-local setting `erp.package_aware_item` equals that item's id. Only the package-aware Actions set it, immediately around their own
   posting call, and clear it afterwards. Every present and future writer — cutover, recalculation, settlement, a script — fails closed.
2. **Deferred anchor.** A deferred constraint trigger on `stock_moves` requires, at commit, package evidence anchored on that move (`ISSUE`;
   both `TRANSFER_OUT` and `TRANSFER_IN`; `RECEIPT_IN`). A move cannot commit without its evidence, and the evidence's own deferred checks
   already prove conservation.
3. **Friendly PHP guards** at each entry point give an actionable message before any work (matrix below). They are courtesy; 1 and 2 are the guarantee.
4. **A ratchet test** enumerates code that creates stock rows, so a new writer must be listed in the matrix.

Quantity-only items pay one primary-key lookup per stock-row write (the trigger exits early); behaviour and results are otherwise identical.

### 4. Writer matrix

| Writer | Authoritative item | Why / mechanism |
|---|---|---|
| Grouped issue, grouped transfer | allowed | built (ADR 0090) |
| Scalar issue / transfer (`PostInventoryMovement::issue/transfer`) | **refuse** | message points to the grouped Actions; DB guard behind it |
| Receipt (goods receipt post, direct receipt) | **package-aware replacement:** new `RECEIPT_IN` leg (successor of the scope's chain plus the observed arrival; chain starts empty if no stock) | needed for the mode to be usable; physical composition mandatory, no inference |
| QA-required receipt line | **refuse** | availability split has no adapter; QA dispositions on an authoritative item's layers also refused |
| Receipt reversal (`reverseReceipt`) | **refuse** (this slice) | needs proof the received stock is still sealed and untouched; own slice |
| Physical count posting | **refuse** (this slice) | counts reconcile a variance by posting stock; the replacement (composition-only recount plus governed variance) is its own slice |
| Cutover opening-state posting | **refuse** | cutover is pre-activation by nature |
| Recalculation (request, execute, reallocators) | **refuse** | rewrites layers; no adapter, would silently break contributions |
| Negative-stock override request, shortage settlement | **refuse** | tracked stock cannot go negative; activation already refuses open shortages |
| Item/definition edits (valuation method, tracking, content of a used package) | **refuse** for an authoritative item | would reinterpret evidence |
| Any other writer | **fails closed** (guard 1) | until it is listed and given a decision |

Recommendation: the activation slice ships with the receipt replacement, because refusing receipts would leave an authoritative item unable
to receive stock. Count and reversal replacements follow; until then they refuse, and activation is permissioned and previewed so it is not
done casually.

### 5. Migration and safety

Additive migration: two tables, one item column, relaxed item CHECK/immutability trigger (only the activation path may flip the mode), the
stock-table triggers, the `RECEIPT_IN` kind. Existing items are untouched (mode and provenance rows unchanged; early-exit triggers). `down()`
refuses while any activation exists. Tests prove: raw SQL cannot flip the mode without a valid activation or on stale evidence; every writer
in the matrix refuses with its message **and** is stopped by the database when its PHP guard is bypassed; a move cannot commit without
evidence; activation is atomic and idempotent; quantity-only items post identically; and the writer ratchet.

## Consequences

- **Positive:** no half-enabled state is representable; correctness does not depend on remembering every writer; quantity-only items unaffected.
- **Negative:** activation is one-way for now; until the count and reversal replacements exist those operations are unavailable for activated
  items (a deliberate, explained refusal); every stock-row write for any item takes one extra indexed lookup.
- **Requires:** permission `inventory.package-state.activate` registered and granted to the demo persona; a demo that activates a fresh item;
  the writer ratchet; documentation of the refusal messages.

## Alternatives considered

**Persist a "pending" mode that becomes authoritative later** — rejected: a half-state invites writers that assume it is enforced.
**Guard only in PHP at each writer** — rejected: the bypassing direct writers show a list rots; one missed path corrupts evidence silently.
**Refuse every writer including receipt in the first slice** — rejected as the default: an authoritative item that cannot receive stock is unusable.
**Deactivation now** — rejected: contradicts ADR 0052's no-detail-loss rule; needs its own decision.
**Flip the mode per scope** — rejected: mode is an item property; the guard must not depend on which location a writer touches.

## Research basis

Local mechanics extending reused research (ADR 0052's progressive modes, ADR 0082's "no writer may bypass" obligation and its SAP/Dynamics
comparison, ADR 0090's physical-vs-cost separation); no new market comparison is needed because the question is how to enforce an already
accepted invariant. Convergence with those sources: a mode that tracks physical units must be maintained by every movement or it is
unreliable (SAP handling units, Dynamics license plates); the divergence is that we make the database, not convention, the last line of defence.

## Related

- [ADR 0052](0052-progressive-inventory-package-tracking.md), [0082](0082-reconcile-package-balances-with-stock-operations.md),
  [0090](0090-package-aware-stock-issue-semantics.md), [0047](0047-govern-inventory-structure-and-default-valuation-policies.md)
- [Package tracking policy](../domain/package-tracking-policy.md), [grouped issue and transfer](../domain/package-issue-operations.md)
- [Delivery plan](../phases/package-balance-delivery.md), [handoff](../phases/package-balance-handoff.md)
