# 0090 — Package-aware stock issue: one contract at three levels of detail; quantity, packaging and cost stay separate

- **Status:** proposed (revised twice on 2026-10-07 after owner review; the owner's confirmations are listed under Decision)
- **Date:** 2026-10-07
- **Affects:** Inventory (issue/transfer posting, package kernel), Phase 5 job issue, Phase 3 material proposals

## Context

[ADR 0082](0082-reconcile-package-balances-with-stock-operations.md) (proposed) says every stock writer must update package state
in the same transaction and that a scalar quantity must not "silently consume whichever package happens to be available". It left
open how an *issue* works. [ADR 0052](0052-progressive-inventory-package-tracking.md) (accepted) already defines three progressive
modes, resolved item → nearest category → company and pinned at item creation. A first draft of this ADR specified only the middle
mode (`PACKAGE_BALANCE`) and refused any scalar issue of a "tracked" item; it never said what a small company, a company with
boxes, or a regulated company would do. That was one mandatory level of detail. This revision fixes it.

Verified in code 2026-10-07:

- `IssuePoster` allocates by quantity, oldest layer first (`LayerAllocator`, `received_at` then `id`). FIFO persists
  `stock_layer_consumptions`; **AVCO persists no per-layer vector** (cost comes from the pool average).
- Issue is idempotent on `(source_type, source_id)`; it refuses back-dating and shortages without an authorised override.
- The package kernel stores immutable full-scope snapshots (`COUNTED`, `RECEIPT`, `OPEN`, `UNPACK`): sealed counts, grouped
  open/loose quantity and ordered **layer contributions** that must sum to the layers. Contributions are bookkeeping, not proof of
  which receipt a can came from. Package definitions already nest (`inner_packaging_id`, `inner_count`). There is no `ISSUE` kind and
  no container-identity table. No issue screen exists (Phase 5), so the adapter is an internal Action first.

## Decision

Owner confirmations, 2026-10-07: keep the three per-item levels; quantity stays authoritative in the stock unit and packaging
explains form only; costing is independent and allocator-led; grouped mode forgets a package's origin once opened; container mode
keeps the individual container and its remaining quantity; strict package rules apply only after the mode becomes authoritative
through a reconciled count; scalar-only issues are refused once a package mode is authoritative; opening during an issue is allowed
with the proper permission; inner packages are tracked by default and outer cartons are opt-in.

### 1. Three things that never merge

| Concept | Question it answers | Authority |
|---|---|---|
| **Stock quantity** | How much exists / moved, in the stock unit | The stock ledger — always, in every mode |
| **Physical packaging state** | In what form does that quantity exist (sealed box, sealed can, opened, loose, a named container) | Package evidence — explains the quantity, never replaces it |
| **Cost allocation** | What the quantity cost (AVCO, FIFO, …) | The existing posting engine — independent of packaging |

A package record never carries cost and never changes a quantity. The posted quantity is the same whether the company tracks
nothing, cans, or every container.

### 2. A ladder of detail, chosen per item, never forced

XYZ Paints stocks emulsion in litres; a 5 L can is a package definition, and 1 box = 4 cans. The mode is the ADR 0052 mode,
resolved item → category → company default and pinned at creation; this ADR does not change that. Moving up the ladder is a
reconciled, governed transition (ADR 0052); no screen or Action asks for detail the item's mode does not record.

| Mode | XYZ sees | An issue carries | Integrity while the mode is authoritative |
|---|---|---|---|
| `QUANTITY_ONLY` | **59 L** available | a quantity, as today | the ledger only; no package evidence exists or may be written |
| `PACKAGE_BALANCE` | **10 sealed cans + 9 L open/loose** (5 L from opened cans, 4 L decanted — "unpacked" is treated as loose) | a draw: counts of sealed packages and loose quantities | sealed counts × content + loose = ledger quantity, checked at commit |
| `CONTAINER_TRACKED` | **Can A 3 L left, Can B 2 L left,** 10 sealed cans each with its own identity, 4 L in a bulk vessel | a draw naming containers and the quantity taken from each | Σ container remaining = ledger quantity, checked at commit |

A mode is **authoritative** only after its reconciled opening checkpoint (ADR 0082 §5). Before that, posting behaves exactly as it
does today and nothing is refused on package grounds. After it, every writer must keep the evidence consistent in the same
transaction, or be guarded with a clear message.

### 3. Which packaging levels are tracked (nesting) — `PACKAGE_BALANCE` only

An item with nested definitions (box → can) tracks a **chain of levels starting from the innermost sealed level**. The normal
default is the innermost level only (cans): 5 L cans are tracked without tracking the cartons they arrived in. Outer levels
(boxes, cartons) are opt-in, because they cost counting effort.

| Tracked levels | 59 L at XYZ |
|---|---|
| none (`QUANTITY_ONLY`) | 59 L |
| cans | 10 sealed cans + 9 L loose |
| boxes + cans | 2 sealed boxes + 2 sealed cans + 9 L loose |

Rules: an untracked outer level **dissolves on receipt** (receiving 3 boxes records 12 sealed cans in the receipt's physical evidence; the commercial carton, its price basis and the
purchase order are unchanged, ADR 0080); tracking boxes without cans is
refused, because the loose pool must mean "open", not "sealed but uncounted"; unpacking a tracked box is an existing `UNPACK`
(box → 4 sealed cans) and opening a can is an existing `OPEN` (can → loose). Tracked levels are set at the reconciled
checkpoint; adding an outer level needs a new count, removing one is refused while sealed stock exists at it (unpack first, ADR 0052).

### 3a. What grouped mode records — and deliberately refuses to record

Grouped mode reports the useful physical **forms**, nothing finer:

| Recorded | Not recorded, and not accepted as input |
|---|---|
| sealed count per tracked level (sealed cans; sealed boxes if opted in) | an identity, code or barcode for one can |
| **one** open/loose quantity per scope (item, location, batch) | how many cans were opened, or how much each holds |
| availability class (usable / held) as a separate fact | "this litre came from that can or that receipt" |

It must not drift into container tracking. Enforced, not merely documented: grouped evidence has no container column and a database
check requires it to be empty; draw lines in grouped mode name only a level and a count or a quantity; a line naming a container is
refused; and no read model groups loose stock by origin. Anything that needs "which can" is a container-mode requirement, reached
only by the upgrade in §10.

### 4. One issue contract, mode-specific draw lines

Whatever the mode, the posted quantity is the sum of what was taken, in the stock unit, exact at four decimals. If a quantity is
also stated it must match; any difference is a refusal, never a rounding. Whole sealed counts are integers. A sealed package that is
partly used is open — there is no "partly sealed".

**`QUANTITY_ONLY`** — unchanged: a scalar issue. No draw, no package evidence.

**`PACKAGE_BALANCE`** — an explicit, ordered **draw** applied to a running composition: `UNPACK(level, n)`, `SEALED(level, n)`,
`LOOSE(q)` and `OPEN_AND_DRAW(level, n, q)`. A scalar issue is refused in this mode (it would change the physical state without
saying how); the [ADR 0053](0053-explainable-inventory-package-selection-presets.md) presets can propose a draw the person edits.

Two valid ways to take **12 L** from 10 sealed cans + 9 L loose (59 L); both post 12 L, both leave 47 L, different states:

| Draw | Sealed after | Loose after |
|---|---|---|
| (a) `LOOSE` 5, `SEALED` 1 can, `OPEN_AND_DRAW` 1 can / 2 L | 8 cans (40 L) | 9 − 5 + 3 = 7 L |
| (b) `SEALED` 2 cans, `OPEN_AND_DRAW` 1 can / 2 L | 7 cans (35 L) | 9 + 3 = 12 L |

Nested, 3 sealed boxes (60 L), take 12 L: `UNPACK` 1 box, `SEALED` 2 cans, `OPEN_AND_DRAW` 1 can / 2 L → 2 boxes + 1 can + 3 L loose (48 L).

**`CONTAINER_TRACKED`** — draw lines name the container: `CONTAINER(id, q)`. Taking the full content empties it; less leaves it
open with the rest. 12 L: Can A 3 L + Can B 2 L (both emptied) + sealed can S1 whole (5 L) + 2 L from bulk vessel C → A, B and S1
empty, C keeps 2 L, 9 sealed cans remain (47 L). In this mode every litre lives in a container; decanted stock needs a bulk vessel
record, so "loose" is not a separate pool.

Both authoritative modes refuse: more than is on hand (no negative stock for tracked items), a finer quantity than the ledger
holds, a line naming stock the person's scope cannot reach, and held/QA-dependent stock.

### 5. What happens to a partial package — the explicit rule

| Mode | Remainder of a package after a partial draw |
|---|---|
| `QUANTITY_ONLY` | Nothing is recorded; the ledger just shows fewer litres |
| `PACKAGE_BALANCE` | The remainder **joins the single grouped open/loose pool** of that scope and its origin is forgotten. The system can say "7 L open", never "can 3 has 3 L" |
| `CONTAINER_TRACKED` | The remainder **stays attached to that container** (state OPEN, remaining 3 L) until emptied or scrapped |

Example, Can A (5 L), draw 2 L: grouped → loose +3 L; container → Can A remaining 3 L. In neither mode is anything re-sealed; returning
an unused remainder is a later inbound movement (Phase 5). Opening inside an issue is allowed and recorded as its own `OPEN`
step before the `ISSUE`, under the same request key; it needs `inventory.package-state.open` as well as the issuing authority.

### 6. One transaction, fixed order, anchored on the stock move

1. Take the request advisory lock and the location lock; lock layers in the allocator's order.
2. Replay the predecessor evidence: latest full-scope, matching layers, ledger total and movement watermark (stale ⇒ "count again").
3. Validate the draw against it (counts, loose, containers, availability class, scope; no SPECIFIC/serial, QA-held or open shortage).
4. Post the issue through `PostInventoryMovement::issue()` — quantity, valuation and idempotency rules unchanged.
5. Receive the **actual allocation vector** (layer, quantity) from the poster — captured at posting, never derived.
6. Re-apportion contributions (§7), write the `ISSUE` evidence, draw lines and allocation lines.
7. Deferred constraints check conservation at commit; any failure rolls back move, valuation and evidence together.

`inventory_package_operations.stock_move_id` is unique for `ISSUE`; the move's `(source_type, source_id)` makes a retry idempotent.
An equal retry returns the original move and evidence (authority rechecked); a changed draw on the same key is refused.

### 7. Cost is allocator-led, whatever the draw

FIFO still consumes the oldest layer and AVCO still prices at the pool average, whichever can or vessel was physically taken.
Layers: A = 6 L at 8.00 (older), B = 14 L at 10.00; the person draws 5 L the bookkeeping had attributed to B. FIFO costs **40.00**
(AVCO 5 × 9.40 = **47.00**), and the same cost results if a sealed can had been taken instead. Draws (a) and (b) above therefore cost
the same. Afterwards the evidence's contributions are re-apportioned deterministically — draws first from the selected states' own
ordered contributions, then any per-layer difference against the allocator's vector moved state by state in stable order, keeping
every state's total — and recorded as bookkeeping attribution. The spike's "physical vector must equal the allocator or refuse"
would have refused this ordinary action; strict identity applies only where identity is real (container mode keeps each container's
own contribution; SPECIFIC/serial are out of scope).

### 8. Evidence stored so an issue can be reconstructed

`IssuePoster`/`TransferPoster` return a typed allocation vector, stored for every valuation method (AVCO included).

| Record | Holds |
|---|---|
| `inventory_package_operations` (kind `ISSUE`) | predecessor, `stock_move_id` (unique), the item's `tracking_mode` at the time, actor, reason, request key, payload hash |
| `inventory_package_issue_draws` (new) | operation, line kind, tracked level or definition, whole count, quantity taken, quantity left loose, nullable `container_id` |
| `inventory_package_issue_allocations` (new) | operation, stock layer, quantity — the allocator's actual vector |
| complete successor states and contributions | as today; immutable |
| container identity/lifecycle tables | **seam only**; designed with the container slice, not here |

Replay uses stored facts only: draws sum to the move quantity; allocations equal the move's consumption records (FIFO) or valuation
quantity (AVCO); successor = predecessor − draws (plus any opened remainder); each layer's contribution total equals its remaining
quantity. Package evidence carries no money, so reading it needs no valuation capability.

### 9. Eligibility, and what must exist before transfers and activation

First release: tracking NONE or BATCH with FIFO or AVCO. SPECIFIC and SERIAL items, negative stock, back-dated issues,
recalculation, cutover and QA-required layers are refused for a tracked item until their own adapters exist. This slice builds
`PACKAGE_BALANCE` issue only; container mode is designed so its draw lines and evidence need no rework (§8), and built later.

Transfer is the next slice: source and destination successors in one transaction with deterministic lock order, destination
contributions mapped to the destination layers the poster actually created, and no undeclared quantity. A mode stays inactive
until each writer is integrated or guarded: issue, transfer, composition-only count correction, receipt reversal and QA split,
negative-stock settlement, cutover and recalculation, a hard refusal of scalar issue/transfer for authoritative items, registered
permissions, demo scenarios, a journey and activation reconciliation.

### 10. The upgrade path — always through a count, never by inference

Quantity only → Grouped → Container-level. Each step is a **counted, reconciled checkpoint**; until it commits, the item behaves in
its old mode and nothing about the new one is enforced.

| Step | What must be counted | Reconciliation | Result |
|---|---|---|---|
| Quantity only → Grouped | sealed packages per tracked level and the open/loose quantity, per location and batch (the existing counted checkpoint) | decomposition must equal the ledger quantity exactly at four decimals, under the location lock | grouped mode authoritative from that checkpoint |
| Grouped → Container-level | each sealed state is expanded into *n* registered containers (a code or barcode, confirmed physically) and every litre of loose stock is declared in a named container or bulk vessel with its remaining quantity | Σ container remaining must equal the same ledger quantity | container mode authoritative from that checkpoint |
| Quantity only → Container-level | the container count above, taken directly | same | same (no hidden pass through grouped) |

Common rules (ADR 0082 §5, ADR 0052 §2): a reason and actor, a stale-stock refusal if any movement posted after the count was
taken, a non-zero discrepancy resolved first by the existing physical-count adjustment path, no open shortage, and **no state is
ever derived by dividing old scalar stock into packages**. Receipts, issues and transfers posted before the checkpoint stay scalar
history; their evidence is not back-filled. The previous snapshots remain readable. Downgrades follow ADR 0052: refused while the
finer detail would be lost; a deliberate "collapse" needs its own decision and is not designed here.

### 11. The container seam — what this slice must not hard-code

The grouped build must leave container mode as an addition, not a rewrite of the posting model. The contracts that guarantee it:

- The stock-anchored flow in §6 (lock, replay, validate, post the move, capture the vector, write evidence, check at commit) and
  the `ISSUE` operation, allocation lines, request key and payload hash are **mode-agnostic**; only "validate" and "conserve" differ.
- Every operation records the item's `tracking_mode` at the time it was written, so replay applies the right invariants even if the
  item's mode later changes.
- A container is later one more *state* in the same immutable full-scope snapshot: a `CONTAINER` form with a nullable
  `container_id`, count one and its remaining quantity. It therefore reuses the existing per-state contributions, per-layer
  conservation, append-only guards and successor model. Adding it is an additive migration: a nullable column, a new form value
  and an identity registry (`inventory_containers`: company, item, definition, code, creating operation).
- `inventory_package_issue_draws` already has a nullable `container_id` and a line kind that can be `CONTAINER`; grouped mode
  forbids both (§3a), container mode requires them.
- The poster's returned allocation vector, the stock-move anchor and the permission model (view / open / issue authority) do not
  mention packages' form at all.

Container-mode build is deferred, but nothing above is a grouped-only shortcut that it would have to undo.

## Consequences

- **Positive:** a company pays only for the detail it chooses; quantity and cost never depend on packaging; the same 12 L can be
  drawn several valid physical ways and each is reconstructable; partial packages have one explicit rule per mode.
- **Negative:** a package-tracked issue needs a draw, so the Phase 5 screen must offer one (the presets make it one click). Grouped
  mode cannot answer "which can has 3 L"; container mode is real operational burden and needs a bulk-vessel concept. Contributions
  are bookkeeping, not provenance. Two new tables, a new kind, and poster signatures that return the vector.
- **Requires:** an additive migration with deferred `ISSUE` conservation checks; tests for each example here (both 12 L draws,
  nested unpack, allocator mismatch, rollback, retry, concurrency, `QUANTITY_ONLY` unchanged); a demo and journey with the slice.

## Alternatives considered

**One mandatory package level for every tracked item** — the first draft; rejected as disproportionate for small companies and
too coarse for regulated ones (the reason ADR 0052 has three modes).
**Always track every nesting level** — rejected: counting boxes is effort many companies will not want; outer levels are opt-in.
**Allow outer-level tracking without the inner level** — rejected: the loose pool would hide sealed-but-uncounted cans.
**Physical selection must equal the allocator vector, else refuse (the spike's rule)** — rejected for grouped stock; kept only
where identity exists.
**Package-led costing** — rejected: silently turns FIFO/AVCO into specific costing; companies wanting that use SPECIFIC items.
**Scalar issue with automatic package choice** — rejected: ADR 0082 forbids silent consumption.
**Container remainder always pooled into loose** — rejected: it would discard the identity container mode exists to keep.
**Issue may take part of a sealed package without opening** — rejected: a partly used package is open.

## Research basis

Question: when a pick takes part of a package, who decides which stock is taken, and how do mature ERPs keep that consistent with
costing and with optional container identity? Reused ADRs 0052/0053/0082 and their sources; additionally checked 2026-10-07:

- [Odoo least-packages removal](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies/least_packages.html)
  prefers an already-opened package and uses an unopened one only when needed; open packages are used first next time. Supports
  "open, then draw; remainder stays open".
  [Removal strategies](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/shipping_receiving/removal_strategies.html)
  choose *which* stock by date, expiry, location or package size; the pages do not state the link to costing, so independence is
  **our inference**, not a quoted claim.
- [Dynamics 365 FIFO with physical value and marking](https://learn.microsoft.com/dynamics365/supply-chain/cost-management/fifo-physical-value-marking):
  FIFO matches first receipt to first issue by default and an issue may be explicitly *marked* to a receipt; physical and financial
  updates are separate. Precedent for allocator-led cost with an explicit, separate specific-cost choice.
- [Dynamics license-plate build](https://learn.microsoft.com/en-us/dynamics365/supply-chain/warehousing/license-plate-build):
  on-hand sits on leaf plates, parents group them, and moving a parent moves nested stock — supports no double-counting and
  optional nesting depth.
- SAP handling units: the page could not be read through the fetch tool, so no new claim beyond ADR 0082.

Convergence: choosing physical stock and costing are separate concerns, with an opt-in link when cost must follow a unit; nesting is
optional. Divergence: those ERPs identify every plate/container once warehouse-managed; we make identity an upgrade, not the base.
Remaining uncertainty: how companies weigh "fewest packages opened" against cost is Phase 5 policy.

## Related

- [ADR 0052](0052-progressive-inventory-package-tracking.md), [0053](0053-explainable-inventory-package-selection-presets.md),
  [0080](0080-packaged-purchasing-and-independent-price-basis.md), [0082](0082-reconcile-package-balances-with-stock-operations.md)
- [Inventory packages](../domain/inventory-packages.md), [package balance integration](../domain/package-balance-integration.md),
  [saved opening and unpacking](../domain/package-opening-operations.md)
- [Delivery plan](../phases/package-balance-delivery.md), [handoff](../phases/package-balance-handoff.md), [Stock](../domain/stock.md)
