# 0090 — Package-aware stock issue: the person declares what was physically taken, the allocator still decides cost

- **Status:** proposed
- **Date:** 2026-10-07
- **Affects:** Inventory (issue/transfer posting, package kernel), Phase 5 job issue, Phase 3 material proposals

## Context

[ADR 0082](0082-reconcile-package-balances-with-stock-operations.md) (proposed) says every stock writer must
update package state in the same transaction, and that a scalar quantity must not "silently consume whichever
package happens to be available". It left open how an *issue* works: what the person selects, what is posted,
how the selection is tied to the real stock move, and what happens to leftovers. The compatibility spike
([package-balance-integration.md](../domain/package-balance-integration.md)) proposed that a physical choice must
produce exactly the allocator's per-layer vector or be refused. Building the evidence kernel showed that rule is
stricter than the data supports (see Decision 4).

What exists today (verified in code 2026-10-07):

- `IssuePoster` allocates by quantity, oldest layer first (`LayerAllocator`, `received_at` then `id`). FIFO and
  SPECIFIC persist `stock_layer_consumptions`; **AVCO persists no per-layer vector** — cost comes from the pool average.
- Issue is idempotent on `(source_type, source_id)` and refuses a back-dated issue and a shortage without an
  authorised override.
- The kernel stores immutable full-scope snapshots (`COUNTED`, `RECEIPT`, `OPEN`, `UNPACK`): sealed counts,
  grouped open/loose quantity, and per-state ordered **layer contributions** whose per-layer totals must equal the
  layers. Contributions of counted/opened stock are deterministic bookkeeping, labelled as such, not proof of which
  receipt a physical can came from. There is no `ISSUE` kind; a scalar issue today simply makes the newest snapshot stale.
- No operational issue screen exists; job issue is Phase 5. The adapter is therefore an internal Action first.

The grouped model (owner-confirmed: unopened counts plus combined open/loose, no per-vessel identity) is the premise.

## Decision

### 1. The person declares the physical draw; quantity is derived and checked

An issue of a tracked item carries an explicit **draw**: a list of lines, each one of

- `SEALED` — *n* whole sealed packages of one state (one definition, one availability class);
- `LOOSE` — a quantity from the grouped open/loose pool;
- `OPEN_AND_DRAW` — open *n* whole sealed packages and draw a quantity from the result (the rest joins the loose pool).

The posted stock quantity is the sum of the draw, in the item's stock unit, exact at four decimals. If the caller
also states a quantity it must equal that sum; any difference is a refusal, never a rounding. Sealed counts are whole
numbers; a package cannot be partly consumed without being opened. A scalar issue with no draw is **refused** for a
tracked item (it would change the physical composition without saying how). A suggested draw comes from the
[ADR 0053](0053-explainable-inventory-package-selection-presets.md) presets as an advisory the person confirms or edits.

**Example.** Thinner in litres: 3 sealed 5 L cans and 5 L loose (20 L).

| Request | Draw lines | Posted quantity | After |
|---|---|---|---|
| Take one can | `SEALED` 1 can | 5.0000 L | 2 cans + 5 L loose |
| Take 3 L | `LOOSE` 3 | 3.0000 L | 3 cans + 2 L loose |
| Need 12 L (preset: loose first, fewest opened) | `LOOSE` 5, `SEALED` 1 can, `OPEN_AND_DRAW` 1 can / 2 L | 12.0000 L | 1 can + 3 L loose |
| Take 21 L | any | refused: more than on hand (no negative stock for tracked items) | unchanged |
| Take 0.00005 L | `LOOSE` 0.00005 | refused: finer than the ledger | unchanged |

### 2. Opening inside an issue is allowed, and recorded as its own step

`OPEN_AND_DRAW` is shorthand for "open, then issue" in one atomic request. It writes the existing `OPEN` operation
first (same evidence, same conservation rules) and then the `ISSUE` operation, both under the same request key, so
the history reads exactly as if the person had opened the can and then drawn from it. It needs
`inventory.package-state.open` as well as the issuing authority. Opening is never implied by a quantity: a person who
draws 2 L is told which can is being opened and sees the 3 L that remains.

### 3. One transaction, in a fixed order, and the move is the anchor

1. Take the existing request advisory lock and the location lock; lock layers in the allocator's order.
2. Replay the predecessor snapshot: it must be the latest full-scope evidence and match the layer vector, ledger
   total and movement watermark (existing freshness rules). Stale evidence is refused with "count again" guidance.
3. Validate the draw against the predecessor (counts available, loose sufficient, same availability class, no QA-held
   or SPECIFIC/serial scope, no open shortage).
4. Post the stock issue through `PostInventoryMovement::issue()` — unchanged quantity, valuation and idempotency rules.
5. Receive the **actual allocation vector** from the poster (Decision 5) and the move id.
6. Re-apportion contributions (Decision 4), write the `ISSUE` snapshot, the draw lines and the allocation lines.
7. Deferred database constraints check conservation at commit; any failure rolls back the move, valuation and package evidence together.

The stock move is the anchor: `inventory_package_operations.stock_move_id` is unique for `ISSUE`, and the move's own
`(source_type, source_id)` makes the retry idempotent. An equal retry returns the original move **and** the original
evidence (with current authority rechecked); a changed draw on the same key is refused.

### 4. The allocator decides cost and layers; the physical draw does not override it

The physical choice and the cost allocation are different facts. FIFO still consumes the oldest layer, AVCO still
prices at the pool average, whichever can the person physically picked. The package kernel then makes its
bookkeeping contributions agree with the layers afterwards.

**Example (FIFO).** Layers: A = 6 L at 8.00 (older), B = 14 L at 10.00. Physical: 3 cans + 5 L loose.
The person draws the 5 L loose, which the counted bookkeeping had attributed to layer B.

- Cost: the allocator takes 5 L from layer A = **40.00**. (AVCO would price 5 L × 9.40 average = **47.00**.) The same cost
  results if they had taken a can instead.
- Layers after: A 1 L, B 14 L.
- Contributions after: the cans were attributed A 6 + B 9; the layers now say A 1 + B 14, so 5 L of the cans' attribution
  moves from A to B. Quantities, counts and cost are untouched; only the bookkeeping label moves.

Rule: draws are taken from the selected states' own ordered contributions first; any per-layer difference against the
allocator's vector is then removed deterministically, state by state in stable order, latest contribution first, keeping
every state's total unchanged. The result is recorded as bookkeeping attribution, exactly as counted evidence already is.
The spike's stricter rule ("the physical selection's per-layer vector must equal the allocator, else refuse") would have
refused this ordinary action; it remains correct only where identity is real (CONTAINER_TRACKED, SPECIFIC/serial), which
are out of scope here.

### 5. The allocation vector becomes first-class evidence, for AVCO too

`IssuePoster` and `TransferPoster` return a typed result carrying the allocations they actually used (layer, quantity),
captured at the posting boundary rather than derived from a before/after quantity. The `ISSUE` operation stores it as
allocation lines for every valuation method, so FIFO's `stock_layer_consumptions` and AVCO (which stores none) look the
same to package replay. Money stays where it is (the move's valuation entries); package evidence stores layers and
quantities only, so reading it needs no valuation capability.

### 6. Evidence stored, so an issue can be reconstructed

| Record | Holds |
|---|---|
| `inventory_package_operations` (new kind `ISSUE`) | predecessor, `stock_move_id` (unique), actor, reason, request key, payload hash; its movement watermark is this issue's own move |
| `inventory_package_issue_draws` (new) | operation, source state, `SEALED` / `LOOSE` / `OPEN_AND_DRAW`, whole count, quantity drawn, quantity left loose |
| `inventory_package_issue_allocations` (new) | operation, stock layer, quantity — the allocator's actual vector |
| complete successor states and contributions | as today; immutable; the predecessor stays readable |

Replay of an issue checks, from stored facts only: draws sum to the move's quantity; allocation lines equal the move's
consumption records (FIFO) or its valuation quantity (AVCO); successor = predecessor − draws (plus any opened remainder);
each layer's contribution total equals that layer's remaining quantity at that point. It reports tracking inactive and never
recomputes from today's balances or today's package names.

### 7. Partials, leftovers and mixes

- Only whole sealed packages leave sealed stock. Part of a package means open it first (Decision 2).
- A remainder from an opened package joins the single grouped loose quantity of that scope; opened containers are not
  counted or identified (owner decision).
- Several lines may be mixed in one issue. Lines of different availability class (held vs usable), batch or location
  are separate scopes and cannot be combined; first release refuses held and QA-dependent layers outright.
- Returning an unused remainder is a later inbound movement (Phase 5), not an issue concern, and never re-seals anything.
- No reservation. A stale draw fails freshness; the second of two concurrent issues against one predecessor is refused
  and told to refresh (unique successor index plus locking, as for opening).

### 8. Valuation methods and tracking

| Case | Behaviour |
|---|---|
| FIFO, AVCO; tracking NONE or BATCH | Supported by this design. |
| SPECIFIC and SERIAL items | Not eligible; a unit already has identity. Revisit with CONTAINER_TRACKED. |
| Negative stock / shortage override | Not eligible for tracked items in the first release. |
| Back-dated issue, recalculation, cutover | Refused for tracked items until their own adapters exist (existing refusals stay). |
| QA-required receipt layers | Refused until QA splitting has physical evidence. |

### 9. What must exist before transfers, and before tracking can be switched on

Transfer is the next slice, not part of this one. It needs, beyond the issue adapter: a source successor and a
destination successor written in one transaction with deterministic location lock order; the destination states'
contributions mapped to the **destination layers the poster actually created**; a refusal when the person tries to
move a quantity not declared as a draw; and a unique source link per move.

Tracking stays disabled until every item below is either integrated or guarded with a clear message for tracked items:

1. issue adapter and the allocation-vector seam (this ADR);
2. transfer adapter;
3. composition-only count correction (quantity variances already use normal receipt/issue posting);
4. receipt reversal, QA disposition/split, and negative-stock settlement guards;
5. cutover and recalculation direct writers guarded for tracked items;
6. a hard refusal of scalar issue/transfer for items whose mode is `PACKAGE_BALANCE`;
7. permissions registered with their enforcing slice, demo scenarios, journey and activation reconciliation.

## Consequences

- **Positive:** the warehouse records what was physically taken; cost stays as accounting expects; no new valuation
  method; every posted issue is traceable to a move and a vector and can be replayed; partial packages cannot be consumed
  by accident.
- **Negative:** an issue needs a draw, so the Phase 5 screen must offer one (the presets make it a one-click default).
  Contributions are bookkeeping, so the system never claims "this can came from that receipt". Two tables and a new
  operation kind; the poster signatures change to return the vector.
- **Requires:** a new additive migration with deferred conservation checks for `ISSUE`; tests for each example here,
  allocator mismatch, rollback, retry and concurrency; a demo and journey with the slice.

## Alternatives considered

**Physical selection must equal the allocator vector, else refuse (the spike's rule)** — rejected: refuses ordinary
actions such as taking loose stock while older layers remain, and pushes people back to untracked scalar issues.

**Package-led costing (the packages chosen decide the layer cost)** — rejected: silently turns FIFO/AVCO into specific
costing and breaks the accepted valuation method. Companies that want that use SPECIFIC items.

**Scalar issue with automatic package choice** — rejected: ADR 0082 forbids silent consumption, and it hides which can was opened.

**Drop per-state layer contributions and keep only layer totals** — rejected: transfers, QA release and reversal need them.

**Issue may take part of a sealed package without opening** — rejected: a sealed package that is partly used is open.

**Per-container identity now** — rejected for this release (owner's grouped decision); it remains ADR 0052's later mode.

## Research basis

Question: when a pick takes part of a package, who decides which stock is taken, and how do mature ERPs keep that
consistent with costing? Reused ADRs 0052/0053/0082 and their sources; additionally checked 2026-10-07:

- [Odoo least-packages removal](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies/least_packages.html):
  prefers an already-opened package, uses an unopened one only when needed, and says open packages are used first
  next time. This supports "open, then draw; the remainder stays open" and the presets.
  [Removal strategies](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies.html)
  describe choosing *which* stock by incoming date, expiry, location or package size. The pages do not state how that
  relates to costing, so that independence is **our inference** from the separate valuation setting, not a quoted claim.
- [Dynamics 365 FIFO with physical value and marking](https://learn.microsoft.com/dynamics365/supply-chain/cost-management/fifo-physical-value-marking):
  FIFO matches the first receipt to the first issue by default; an issue can be explicitly *marked* to a specific receipt
  to override it. Physical and financial updates are separate. This is the precedent for keeping allocator-led cost as
  the default and making a package-driven cost an explicit, separate choice (our SPECIFIC items).
- [Dynamics license-plate build](https://learn.microsoft.com/en-us/dynamics365/supply-chain/warehousing/license-plate-build):
  on-hand sits on leaf plates; moving a parent moves the nested stock. Supports no double-counting and moving declared contents together.
- SAP handling units: the page could not be read through the fetch tool, so no new claim is made beyond ADR 0082's use of it.

Convergence: picking choice and costing are separate concerns, with an explicit, opt-in link when cost must follow a
specific unit. Divergence: those ERPs identify each container or plate; we deliberately do not. Remaining uncertainty:
how a company will want to weigh "fewest packages opened" against cost is Phase 5 policy, not decided here.

## Related

- [ADR 0052](0052-progressive-inventory-package-tracking.md), [0053](0053-explainable-inventory-package-selection-presets.md),
  [0080](0080-packaged-purchasing-and-independent-price-basis.md), [0082](0082-reconcile-package-balances-with-stock-operations.md)
- [Package balance integration spike](../domain/package-balance-integration.md)
- [Saved opening and unpacking](../domain/package-opening-operations.md)
- [Delivery plan](../phases/package-balance-delivery.md) and [handoff](../phases/package-balance-handoff.md)
- [Stock](../domain/stock.md)
