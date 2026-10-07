# 0092 — Package-aware physical count: recount the composition, reconcile the quantity through a governed movement

- **Status:** proposed
- **Date:** 2026-10-08
- **Affects:** Inventory (physical count, package kernel), Phase 2 stock counting

## Context

[ADR 0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md) made `PostPhysicalCount` refuse items with grouped package
tracking, because a count posts stock and would otherwise change quantity without package evidence. A grouped item still has to be counted:
warehouses find that the cans on the shelf differ from the book, and that litres are missing or extra.

Verified in code 2026-10-08: a count line snapshots `book_qty` when it is added; a counter (blind, `inventory.physical_count.count`) records only
`counted_qty`; posting needs `inventory.physical_count.post`, a different person from the creator, every line counted, and is one transaction.
A positive variance posts as an ordinary receipt at a reviewer-supplied found cost, a negative one as an ordinary issue with no override, both
stamped `now()`. The existing count compares against the line's *snapshot*, so stock that moved after capture is not detected for quantity-only
items (unchanged here; a grouped item is held to a stricter rule below).

Two different facts can be wrong, and they need different remedies. A count must **never rewrite history**: where quantity changes it
reconciles through a governed movement, where it does not it replaces the composition through new immutable evidence.

## Decision

### 1. A grouped count line counts a composition

For an item with grouped tracking, the counter records what is on the shelf as the kernel already describes stock: whole sealed packages per
definition plus one open/loose quantity (no container identities, no per-vessel remainders). `counted_qty` is then derived — the exact sum — not
typed separately. A line also captures, when it is added, the scope's latest package evidence and movement watermark (`package_operation_id`,
`package_watermark`); an authoritative scope that holds nothing captures none. The blind-count rule is unchanged: entering a composition shows
the counter nothing of the book.

### 2. Posting: three outcomes per line, all in the count's one transaction

Posting first proves every grouped line **fresh**: its captured evidence is still the latest and still matches current stock, and no stock
movement has touched the scope since capture. Otherwise the **whole count refuses, naming the lines**, and nothing is posted — the count stays
draft and the stale lines are refreshed (the line is re-snapshotted and its counted composition cleared, so it is recounted, never silently
rebased). Then, per line, compare the counted total with the ledger total:

| Counted vs ledger | What posts | Package evidence |
|---|---|---|
| **Equal** (composition-only recount) | **No stock movement.** Ledger and layers are untouched | a new `COUNTED` operation carrying the counted composition, linked to the count line; per-layer contributions re-apportioned over the *same* layers (the existing counted-checkpoint rule, labelled counted, not provenance) |
| **Less** (loss) | an ordinary issue of exactly the difference through the sole boundary, allocator-led cost, no override | a `COUNT_VARIANCE` operation anchored on that move, whose successor **is** the counted composition |
| **More** (gain) | an ordinary receipt of exactly the difference at the reviewer-supplied found cost (required, never inferred) | a `COUNT_VARIANCE` operation anchored on that move; the new layer is in its allocation and the counted composition apportioned over old and new layers |

Example 1 — composition only. Book: 2 sealed 5 L cans + 2 L loose = 12 L. Count: 1 can + 7 L loose = 12 L. Variance 0: no movement, no cost; a
`COUNTED` successor replaces the composition; the earlier composition stays replayable. Example 2 — loss. Book 12 L, found 11 L (2 cans + 1 L loose,
or 1 can + 6 L — either is a valid count): the 1 L issue is costed by the allocator (FIFO takes it from the oldest layer; AVCO at the pool average)
exactly as any issue is; the evidence then states the counted composition. The physical count states the composition; it does not choose the
layers or the cost.

### 3. The variance leg is conserved by the database

`COUNT_VARIANCE` is a complete successor snapshot like an issue or a receipt leg, with `stock_move_watermark = stock_move_id` (the move must be
the only one after its predecessor's watermark). At commit the database checks: allocation total equals the move quantity; per-layer totals equal the
predecessor less (loss) or plus (gain) the allocation; successor total equals the predecessor total ∓ the variance; every state's own quantity and
contribution constraints hold. Unlike an issue, **the composition is not required to equal predecessor ∓ a declared draw** — it is the count's
finding, and that is the point. The deferred "stock move needs evidence" check accepts `COUNT_VARIANCE` for issue and receipt moves. The zero-variance
`COUNTED` successor adds no new check: it already must reconcile to the unchanged layer vector. An operation links to its count line
(`physical_count_line_id`, unique, `COUNTED` or `COUNT_VARIANCE` only).

### 4. Permissions and approvals

Counters keep `inventory.physical_count.count`; adding lines keeps `.create`; posting keeps `.post` with a poster distinct from the creator. Replacing an
authoritative composition is a stronger act than counting, so posting a grouped line additionally needs a new `inventory.package-state.recount` (plus
`.view`), and warehouse scope (ADR 0089) applies to every line's warehouse. **No new approval workflow:** parity with quantity-only counts, whose control is
separation of duties plus the found-cost requirement. A variance-value threshold routed through the shared approval engine is a later company policy and
would apply to both kinds of item; it is recorded as open, not invented here.

### 5. Time, scope, idempotency, rollback

- **No backdating.** Variance movements post at the moment of posting, as today; a recount takes effect at posting; the existing poster refuses any
  issue/receipt dated before later stock history. A count cannot be dated into the past.
- **Scope.** A line is exactly one item/location/batch scope, i.e. one evidence chain; one line per scope per count (existing rule). Locations are locked in
  ascending id order before replay. An authoritative scope holding nothing may be counted: a gain starts its chain (null predecessor), zero against zero is a no-op.
- **Idempotent.** Posting an already posted count returns it; each line's evidence is unique per count line, so a retry can never double-post a movement or a
  composition, and a changed payload cannot attach to an existing line.
- **Atomic.** Every line's movement and evidence, or none: a stale line, a missing found cost, a failed database check or any other error rolls back the whole
  count, including movements and evidence already written for earlier lines.
- **No negative grouped stock.** A loss is at most the book quantity, which freshness has proved equals the ledger; there is no override path, and a counted
  composition cannot hold a negative count. Serial, SPECIFIC, QA-held and shortage scopes are refused as for activation.

### 6. Quantity-only items

Untouched: lines, counting, the snapshot-based variance and the poster behave exactly as before. A grouped item is recognised by its activation; the new
columns, the composition table and the checks apply to its lines only. `PostPhysicalCount` stops refusing grouped items and routes their lines here; every
other refused writer from ADR 0091 stays refused.

## Consequences

- **Positive:** the two kinds of error get two different, honest remedies; no history is rewritten; cost is never chosen by the counter; the common
  "right litres, wrong cans" case posts nothing to the ledger.
- **Negative:** a grouped line is stricter than a quantity-only one (freshness, composition entry), and a stale count must be refreshed line by line. More
  evidence kinds and one more table.
- **Requires:** additive migration (composition table, line capture columns, operation link, `COUNT_VARIANCE` kind and its database function, widened
  evidence anchor); capability `inventory.package-state.recount` registered and granted to the demo persona; tests for each example here, staleness, rollback
  of a mixed count, idempotency, blind counting, scope and quantity-only parity; a demo.

## Alternatives considered

**Always post a movement and always rewrite via issue-then-receipt** — rejected: a pure recount would create cost noise and ledger entries for nothing.
**Let the counter choose which layers lose stock** — rejected: cost is the allocator's decision, not the counter's (ADR 0090).
**Compare against the book snapshot like quantity-only counts** — rejected for grouped items: composition and quantity would be silently rebased onto stale evidence.
**Allow the variance leg only with a declared draw** — rejected: the count's finding is the new composition; forcing a synthetic draw adds a fictional step.
**Allow backdated recounts** — rejected: it would reorder stock history, which the movement boundary refuses and which has no governed workflow here.
**A new approval per count** — deferred: no threshold policy exists for any count today; adding one only for grouped items would be inconsistent.

## Research basis

Reused ADRs 0082/0090/0091 and the existing physical-count design (ADR 0083's blind counting, the count's separation of duties and found-cost rule). The
rule that a count reconciles through governed movement and never edits history is the project's own (ledger immutability); no new market comparison was
needed because the question is how to fit a composition correction into already-settled movement and evidence rules.

## Related

- [ADR 0090](0090-package-aware-stock-issue-semantics.md), [0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md), [0083](0083-layered-stock-read-capabilities-and-blind-counting.md)
- [Physical count](../domain/physical-count.md), [grouped issue, transfer and activation](../domain/package-issue-operations.md)
- [Delivery plan](../phases/package-balance-delivery.md), [handoff](../phases/package-balance-handoff.md)
