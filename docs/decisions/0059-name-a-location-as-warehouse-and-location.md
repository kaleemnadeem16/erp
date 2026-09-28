# 0059 — Name a location as warehouse / location, and always return its warehouse

- **Status:** accepted
- **Date:** 2026-09-28
- **Affects:** Inventory (`Location` and every resource that embeds one), every screen that shows a
  location, [stock.md](../domain/stock.md)

## Context

A location's `code` is unique only within its warehouse. That is deliberate and tested
(`WarehouseApiTest::test_location_codes_are_unique_within_a_warehouse_only`): each warehouse owns its
own location namespace ([ADR 0041](0041-progressive-inventory-location-capabilities.md)), and a company
with two depots naturally has a `stock` or `receiving` location in each.

Most screens nonetheless labelled a location by its code alone. Seeding the first stock transfer between
the two demo warehouses made the consequence visible: the stock ledger read **"stock → stock"** for every
transfer, and a location picker offered two indistinguishable "stock" options. About a dozen sites were
affected — the ledger's From/To columns and detail, the goods-receipt, physical-count, recalculation,
negative-stock and cutover screens.

The data was not the problem for every screen. The stock-moves endpoint already nested
`fromLocation.warehouse`; the screen simply ignored it. The other endpoints that embed a location
(stock layers, count lines, recalculation and override requests, cutover exceptions) did not load it.
[Q30](../OPEN-QUESTIONS.md#q30--location-labels-are-ambiguous-across-warehouses) asked where the fix
belongs.

## Decision

1. **A location is named `warehouse / location`** (`main / stock`), and in a picker
   `warehouse / location — Name`. One shared formatter, `formatLocation` /
   `formatLocationOption` in `frontend/packages/core-ui/src/lib/format-location.ts`, produces it; a
   screen never composes a location label itself.
2. **A location always carries its warehouse.** `Location` eager-loads `warehouse` (`$with`), so every
   resource that embeds a location includes it without each controller remembering a nested include, in
   one extra query per result set rather than one per row. The location endpoints' generated types now
   mark `warehouse` required.
3. **Location codes stay unique per warehouse only.** No schema change.

## Consequences

- **Positive:** A transfer reads `main / stock → khi / stock`. The ambiguity is fixed once, at the layer
  that owns it, and a new screen gets the right label by using the formatter.
- **Positive:** No new API field and no per-endpoint include work; the warehouse object was already the
  source of truth.
- **Negative:** Every embedded location now carries a small warehouse object, including where a screen
  only wants the code.
- **Negative:** A location whose warehouse somehow did not load falls back to its bare code, silently
  reintroducing the ambiguity for that row.
- **Requires:** New screens use `formatLocation` rather than `location.code`; the e2e spec
  `location-labels.spec.ts` and `LocationWarehouseLabelTest` pin both halves.

## Alternatives considered

**Make location codes unique across the company** — rejected. It contradicts the deliberate
per-warehouse namespace, forces one depot to rename a location to avoid another's, and would need a data
migration for existing tenants and for imports from systems that reuse codes per site.

**Label by the location's name** — rejected. Names are not unique either, and a warehouse-qualified code
is what people already type and scan.

**Add a flat `warehouse_code` field to `LocationResource`** — rejected. It duplicates what the embedded
warehouse already says and adds a second field to keep in step.

**Patch each screen to read the nested warehouse** — rejected. That is how nine screens came to disagree;
the label is decided once in a shared formatter.

## Related

- [ADR 0041](0041-progressive-inventory-location-capabilities.md)
- [Stock](../domain/stock.md) · [UI building blocks](../architecture/ui-building-blocks.md)
