[← Domain index](README.md)

# Saved package opening and unpacking

Implemented backend evidence slice under proposed
[ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md).
PostPackageOpening saves a full-scope successor to fresh COUNTED, OPEN or UNPACK evidence.
It records composition changes without changing stock moves, layers, pools or valuation entries.
PACKAGE_BALANCE activation remains blocked. Ordinary scalar movements invalidate this evidence
until a new count or a future integrated adapter reconciles it; this is not an enabled live balance.

## Posting and history

Input identifies predecessor operation, its sealed state, positive whole selected count,
OPEN/UNPACK kind, request key and mandatory reason. OPEN moves selected contents into combined
usable open/loose quantity. UNPACK replaces outer cartons with same-item sealed inner packages;
inner contents times inner count must equal outer contents exactly. It does not open bottles or
count both levels. Quantities remain exact at four decimals; excessively large counts are refused.

The Action requires inventory.package-state.open and inventory.package-state.view in the current
company. Opening authority is registered now that a writer enforces it. It conveys no receipt,
QA release, stock issue, valuation or activation authority. There is no HTTP endpoint or screen;
a later endpoint must also enforce authority in its Request. Administrators register permissions
and explicitly assign both capabilities through Access Administration. The demo actor receives both.

The writer shares counted evidence's company/request advisory lock namespace and holds the stock
location lock through validation and complete successor creation; location and warehouse must be
active and permit stock. Replay checks movement watermark,
current layer vector, ledger total and latest full-scope evidence. Partial receipt observations,
QA-dependent scopes, shortages, SERIAL/SPECIFIC, unknown watermark, superseded evidence and later
scalar movements are refused. No package count is inferred from total stock.

An equal retry returns its immutable original result even after later stock progress, but still
requires current read/write authority. The hash includes actor, predecessor, selection, count,
kind and reason. Changed input on the same key is refused. Two requests against one predecessor
cannot both create successors: location locking, freshness and a unique successor index prevent
branches. The second caller must refresh and select from the latest composition.

Saved operations retain predecessor, selected state/count, actor, reason, timestamp and unchanged
movement watermark. Each has its own complete immutable states/contributions. Replay chooses the
latest COUNTED/OPEN/UNPACK snapshot and exposes lineage metadata. RECEIPT remains partial inbound
history. Superseded snapshots remain readable and are never edited or deleted.

## Storage and conservation

```text
inventory_package_operations
  predecessor_id     nullable company-safe FK to operation
  selected_state_id  nullable company-safe FK to state
  selected_count     nullable positive integer
  evidence_kind      COUNTED | RECEIPT | OPEN | UNPACK
  unique(company_id, predecessor_id) where predecessor is present
inventory_package_states
  origin_state_id    nullable company-safe FK to immediate predecessor state
```

Kind checks require receipt source only for RECEIPT, lineage only for OPEN/UNPACK, neither for
COUNTED. Insert guards validate same company/item/location/batch/watermark, latest predecessor
and usable sealed selection/count. Deferred constraints require complete successor composition,
preservation of every layer's total contribution and the exact physical transformation by
form/package/availability. Existing state reconciliation and append-only guards remain enforced.

Inherited identities must match immediate predecessor form, definition, content, availability and
snapshot labels. Archived/renamed definitions therefore retain the remaining sealed historical
identity. New inner states validate current same-item definitions and snapshot labels at posting.
Origin is bookkeeping lineage, not a vessel identifier. Layer contributions are deterministic;
opening does not silently redefine FIFO/AVCO allocation or calculate costs.

Downgrade refuses while OPEN/UNPACK history exists rather than deleting immutable facts.
Separate QUANTITY_ONLY policy constraints remain unchanged.

## Demo and verification

PackageOpeningOperationsDemoSeeder creates dedicated item demo-package-opened and atomic history
COUNTED → UNPACK → OPEN → OPEN through real item/package/stock/count/opening Actions. Two FIFO
layers hold 1 L at 800 and 3 L at 900. Final composition: one sealed 2 L carton, eighteen sealed
100 mL bottles and 0.2 L combined open contents; still 4 L and unchanged value. The preview
fixture stays separate and usable. Inspect internally using request keys demo-saved-opening-count,
demo-saved-unpack and demo-saved-open-1/-2. No screen exists yet.

The local/testing module guard refuses production. A stable counted-scenario key skips immutable
history on reruns, even after manual stock issues; no history is recomputed from current balances.

Tests cover chained operations, layer conservation, combined leftovers, original-history
preservation, exact retries, key conflicts, stale/conflicting requests, read/write authority,
archived labels, outer rollback, SQL immutability and deferred transformation checks. A second
database connection verifies request-lock exclusion. Demo tests force commit-time constraints
and check rerun preservation after manual stock progress. The final focused run passes
230 tests / 1,061 assertions, with 41 architecture tests / 81 assertions also passing.
Targeted production-code static analysis and Pint pass. The additive migration and module
demo seeder ran successfully in local development.

## Related

- [Opening preview](package-opening-preview.md)
- [Inventory packages](inventory-packages.md)
- [Package kernel](package-balance-integration.md)
- [Receipt evidence](receipt-package-evidence.md)
- [Tracking policy](package-tracking-policy.md)
- [Delivery plan](../phases/package-balance-delivery.md)
- [Demo journey](../journeys/packaged-purchasing-demo.md)
