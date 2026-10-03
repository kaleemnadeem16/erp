[← Domain index](README.md)

# Opening and unpacking preview

Read-only backend slice under proposed [ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md).
PreviewPackageOpening proposes a physical transformation against a fresh full-scope counted
checkpoint. It does not save an opening, reserve stock, activate tracking or change valuation.
Persisted operations with predecessor lineage and concurrency/idempotency controls remain next.

## Behaviour

OPEN turns selected whole sealed packages into combined usable open/loose contents.
UNPACK removes selected outer cartons and replaces them with sealed inner packages from the
same-item nested definition. Outer contents must equal inner count times inner contents exactly.
For two 2 L cartons of twenty 100 mL bottles each, unpacking one proposes one carton plus
twenty sealed bottles; opening one carton instead proposes one carton plus 2 L open/loose.
Both retain 4 L total. The operation does not double-count inner and outer packages.

PackageOpeningComposition splits the selected cohort's ordered layer contributions exactly.
Opening 2 L from contributions of 1 L and 3 L retains 1 L from each layer in the opened portion,
and 2 L of the second layer in the remaining sealed carton. No cost is read or exposed.
Contribution ordering is deterministic bookkeeping, not proof of an individual vessel's identity.
Usable loose rows combine and aggregate duplicate layer references; held loose stock remains
separate. Held sealed selections, excess/zero counts and sub-ledger precision are refused.

The Action delegates company/view authority and scope locking to ReplayPackageCheckpoint inside
its own transaction. It requires inventory.package-state.view and refuses stale, superseded,
unknown-watermark, partial receipt, QA, shortage or otherwise unsupported evidence. It selects
only a state belonging to that checkpoint. Archived definitions remain usable; remaining outer
identity retains historical snapshot labels, while new inner proposals use current definition
labels. There is no HTTP endpoint or screen in this slice and no new write permission.

The result states posted=false, reserved=false and tracking_active=false, carries the source
checkpoint and movement watermark, and returns the proposed composition. A proposal expires
when the underlying stock or checkpoint changes; a future writer must revalidate within its
own transaction. An immutable original checkpoint remains unchanged by every preview.

## Demo and verification

PackageOpeningDemoSeeder adds dedicated item demo-package-opening, two sealed 2 L cartons,
the nested 100 mL bottle definition and two FIFO receipt layers (1 L at 800; 3 L at 900).
It uses real item/package/stock/count Actions and records explicit fictional counted evidence.
Its local/testing guard blocks production; a stable checkpoint key skips immutable history on
reruns, including after manual stock issues. It does not borrow the live Purchasing draft stock.

To inspect internally, find PackageOperation with request_key demo-package-opening-checkpoint
and its sealed PackageState. Invoke PreviewPackageOpening with their IDs, count 1, kind OPEN
or UNPACK and the authorised demo actor. No UI is available yet.

Feature tests check opening/unpacking, historical definition labels, stock/value non-mutation,
authority, wrong selection and stale/excess refusal. Demo tests verify multi-layer splitting and
preservation after manual progress; unit cases cover combined leftovers, QA separation and exact
precision. Existing receipt and checkpoint tests remain regression coverage.
The focused opening, checkpoint replay and receipt demo run passes 88 tests / 416 assertions,
including inherited stock/checkpoint cases. The module demo loaded successfully locally.

## Related

- [Inventory packages](inventory-packages.md)
- [Receipt package evidence](receipt-package-evidence.md)
- [Package kernel](package-balance-integration.md)
- [Delivery plan](../phases/package-balance-delivery.md)
- [Demo journey](../journeys/packaged-purchasing-demo.md)
