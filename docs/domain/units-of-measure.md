[← Domain index](README.md)

# Units of Measure

Every quantity in the system carries a unit. Conversion is generic and data-driven — no
conversion factor is ever hardcoded in application code.

## Where it sits

Foundational. Items, formulations, application rates, assemblies and stock all reference UoMs.
Built in [Phase 0](../phases/phase-0-foundation.md).

## Schema

```
uoms
  id, company_id
  code            'sqm', 'sqft', 'L', 'ml', 'kg', 'g', 'hr', 'ea'
  name
  dimension       AREA | VOLUME | MASS | LENGTH | TIME | COUNT
  factor_to_base  decimal(19,9)   -- relative to the dimension's base unit
  is_base         bool            -- exactly one per dimension per company
```

Example, AREA with sqm as base:

| code | factor_to_base |
|---|---|
| sqm | 1.0 |
| sqft | 0.092903040 |

## Behaviour

**Conversion is permitted only within a dimension.**

```
qty_in_target = qty × (source.factor_to_base ÷ target.factor_to_base)
```

sqft → sqm is arithmetic. Attempting L → sqm must throw — that is not a conversion.

### Crossing dimensions is not a UoM concern

Litres → square metres depends on business context (what surface, how thick, how many coats).
That is an **[application rate](application-rates.md)**, and keeping the two concepts separate
is what makes the model work across industries.

| Question | Answered by |
|---|---|
| How many sqft in 5 sqm? | UoM conversion — arithmetic, universal |
| How many sqm does 5 L cover? | Application rate — contextual, company-specific |
| How many EA in 1 BOX of *this* item? | Item-specific conversion — fixed catalog fact, but item-owned (below) |

Collapsing these into one table is the most common way this kind of model goes wrong.

### Item-specific conversions

This table's global `factor_to_base` correctly expresses litre↔millilitre, kilogram↔gram and
metre↔millimetre — the same ratio for every item. It cannot correctly express each↔box,
tablet↔strip↔carton, metre↔roll, sheet↔m² or drum↔litre, because those ratios depend on the
specific item, not the unit pair: one item's box holds 12 each, another's holds 24.
[ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md) decided the
mechanism for this — a per-(item, UoM) `item_quantity_units` override, resolved *before* falling
back to this global service, never instead of it, implemented as
`App\Modules\Inventory\Actions\ItemUomConversionService`:

1. An `item_quantity_units` row for this item and UoM, if one exists.
2. Otherwise, if the two UoMs share a `dimension`, this service.
3. Otherwise, fail — there is no conversion between an unrelated UoM and this item.

`item_quantity_units` is managed through its own CRUD endpoints (`/api/v1/item-quantity-units`,
filterable by `item_id`); the (item, UoM) pair is create-only, matching `ItemSupplier`'s
identity lock — repointing an override is delete-and-recreate, not an update.
`BuildPurchaseOrderLineAttributes` is the first real caller, replacing its previous direct call to
the global service.

**Pack conversion screens — coverage** (`ItemQuantityUnitResource`; routes index, store, show, update, destroy).

| Backend element | Where it appears | Notes |
|---|---|---|
| Filter `item_id` | Item picker beside Print, as a chip with Clear all, in the URL | The endpoint has no text search, so the server-searched item picker is the primary filter |
| Sort `created_at` | *Added* is an optional, sortable column | |
| Resource facts | Default columns: item, pack unit, what it means ("1 drum = 200 L", from the pack unit and the item's stock unit), factor, rounding precision. Optional: added, last changed. The detail shows all of them ("Unrestricted" when there is no precision) | |
| Create | Side panel: item, pack unit, factor, rounding precision | The (item, unit) pair is unique; the server's refusal is shown on the form |
| Update `factor`, `quantity_precision` | Edit panel; the item and unit are shown as fixed text | Repointing is delete and re-add |
| Delete (hard) | Row menu and panel, each with a confirmation; in bulk on the ticked rows, one request each, with the result kept on screen even when the rows leave the list | Posted lines keep the factor they snapshotted |
| Print | List (visible columns and filter), selected rows, one conversion | Quick Print |
| On an item | The Packaging tab keeps the item's own conversions in place, with add and edit in a dialog over the item | An item is itself a side panel, so no second panel is stacked on it |

**An item-specific conversion may cross dimensions** (a roll is `COUNT`, its length is `LENGTH`;
a sheet is `COUNT`, its area is `AREA`) — the one deliberate exception to this file's own
same-dimension-only rule, scoped to that override table alone. This service's own refusal to
convert across dimensions for every other caller is unchanged.

Divisibility (can a fractional quantity exist) is decided the same way: never inferred from
`dimension` — `COUNT` does not mean indivisible — always an explicit, item(-and-UoM) rounding
precision (`items.quantity_precision`, and per-override on `item_quantity_units`), defaulting to
unrestricted. `PostGoodsReceipt`'s over-receipt tolerance ceiling is the first real consumer,
replacing its previous ad hoc floor for any `COUNT`-dimension item — a `COUNT` item with no
explicit `quantity_precision` now keeps a fractional ceiling instead of silently rounding down. See
ADR 0050 for the full reasoning and schema.

**Demo data.** `PackConversionsDemoSeeder` seeds one deliberate scenario per decision point of the
lookup above, and `PackConversionsDemoSeederTest` proves each through the real purchase-order-line
conversion: the same `bag` unit holding 25 kg of `demo-pig-blue` but 20 kg of `demo-pig-red`
(the reason the conversion is item-owned); two pack levels on `demo-thinner` (can = 5 L,
drum = 200 L), each stored directly against litres rather than chained; a roll of
`demo-masking-tape` = 50 m crossing `COUNT` → `LENGTH`; and a box of `demo-sanding-disc` = 50 each,
where box and each share `COUNT` so the override must beat the global 1:1. Thinner in millilitres
exercises the global fallback with no row at all, and blue pigment in drums the loud rejection.
The sanding discs carry an item-level `quantity_precision`, which `PostGoodsReceipt`'s
over-receipt ceiling reads; the masking-tape roll's per-override precision is a stored, displayed
fact that `BuildPurchaseOrderLineAttributes` does not apply.

### Precision

> **2026-10-15:** increment validation and quantity representability are refined by [ADR 0142](../decisions/0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md) and described in [precision.md](precision.md). A factor stays nine places as evidence; its precision is not the destination quantity's.


`decimal(19,9)` for factors. Ingredient quantities are frequently small (0.00012 kg per unit),
and rounding at storage compounds through the whole cost roll-up.

**Round only at presentation.** Never round intermediate values in a calculation chain.

### HTTP access

Authenticated clients can read company-scoped UoMs through `GET /api/v1/uoms` and
`GET /api/v1/uoms/{uom}`. The collection supports page-based pagination, sorting by code, name
or creation time, and filters for dimension or a code/name search. It is deliberately read-only:
forms such as Item's stock-UoM selector need authoritative options, while UoM administration is
not yet part of the user interface.

## Landmines

**Hardcoding a conversion.** `$sqm * 10.7639` will appear somewhere and will be wrong for
someone. All conversion goes through the service.

**Assuming a base unit.** Companies differ — one works in litres, another in millilitres. Never
assume; read `is_base`.

**Displaying in the storage unit.** Stock is held in the item's stock UoM, but users think in
their own. Display UoM is a presentation preference, like display currency
([multi-currency.md](multi-currency.md)).

## Conversion maintenance and receipt agreement

Conversion writes require inventory.item.manage in both HTTP and Actions (ADR 0080).
Receiving in the issued order unit reuses the frozen PO factor; alternate units use the
item-aware conversion service. Receipt prices are normalised to the received unit.

## Related

- [ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md) — item-specific
  conversion and divisibility decision, implemented (increment validation partly superseded by [0142](../decisions/0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md); see [precision.md](precision.md))
- [material-resolution.md](material-resolution.md) — cross-module research baseline; remaining
  commercial-package and supplier-offer gaps across later phases
- [items.md](items.md) — each item has a stock UoM
- [application-rates.md](application-rates.md) — cross-dimension consumption
- [architecture/invariants.md](../architecture/invariants.md)
