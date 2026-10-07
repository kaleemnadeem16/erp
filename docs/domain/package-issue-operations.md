[← Domain index](README.md)

# Grouped package-aware stock issue

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

## Not yet

Refusing scalar issues once a mode is authoritative (needs the activation slice that persists the mode), transfers, composition-only count
correction, receipt reversal / QA split / negative-stock settlement / cutover / recalculation adapters, container-level tracking, screens.

## Demo and verification

`PackageIssueDemoSeeder` (create-once, real Actions): XYZ Paints, layers 6 L at 8.00 and 14 L at 10.00, counted as 3 cans + 5 L loose; request
`demo-xyz-issue-12l` takes 12 L (loose 5 L, one can, 2 L from a second opened can), leaving 1 can + 3 L loose, FIFO cost 108.00. Tests: allocation
seam (7), grouped issue (FIFO and AVCO, both 12 L draws, nested box unpack, empty successor, retries, rollback on evidence failure, database
conservation, container refusal, invalid draws, stale-evidence-then-recount transition, quantity-only unchanged, authority, scope) and the demo.

## Related

- [ADR 0090](../decisions/0090-package-aware-stock-issue-semantics.md), [ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md)
- [Saved opening and unpacking](package-opening-operations.md), [package kernel](package-balance-integration.md)
- [Delivery plan](../phases/package-balance-delivery.md), [handoff](../phases/package-balance-handoff.md)
