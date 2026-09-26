[← Domain index](README.md)

# Inventory packages and material selection

This document is the implementation-facing domain contract for
[ADRs 0051–0053](../decisions/README.md). It describes fixed package definitions, progressive
physical tracking and Phase 3's advisory package selection. It does not design supplier sourcing
or customer pricing.

## Concepts that must remain separate

| Concept | Example | Owner |
|---|---|---|
| Stock UoM and quantity | 28 L on hand | Inventory ledger |
| Item quantity conversion | one can = 5 L for this item | Item master / ADR 0050 |
| Package definition | named/barcoded “5 L can” | Item master |
| Lot/batch | paint batch B-104 | Inventory traceability |
| Package balance | four sealed 5 L cans plus 8 L open/loose | Inventory |
| Physical container | Can C-009 has 3.5 L remaining | Inventory |
| Material proposal | use C-009 plus three 1 L cans | Estimating resolution |
| Reservation/issue | stock committed or moved for a job | Phase 5 execution |
| Supplier replenishment | buy the 4 L shortage | Purchasing |

A package definition never proves that a package is in stock. A lot may span many packages. A
proposal does not reserve anything.

## Package definitions

The conceptual first-generation shape is:

```text
item_packagings
  id, company_id, item_id
  item_quantity_unit_id
  code, name
  barcode nullable
  active
```

`item_quantity_unit_id` supplies the fixed contained quantity through ADR 0050. The definition is
item-specific; “box” or “drum” is not a globally convertible UoM. Archiving prevents new use but
must not break historical snapshots.

Whether a package is offered for purchasing, selling or internal issue is workflow metadata, not
its physical identity. Add those applicability controls only with the workflow that consumes them.

## Progressive tracking modes

### `QUANTITY_ONLY`

Uses existing on-hand quantity. It can report coverage and calculate package equivalents, but it
must label them approximate: 10 L on hand does not prove that two sealed 5 L cans exist.

### `PACKAGE_BALANCE`

Tracks counts of sealed package definitions and grouped open/loose quantity under the relevant
warehouse/location/batch/expiry scope. It answers “how many 5 L cans?” without assigning a unique
identity to every can.

Conceptually:

```text
stock_package_balances
  company_id, item_id, item_packaging_id nullable
  warehouse/location/batch/expiry scope
  state                         SEALED | OPEN_LOOSE | QUARANTINED
  package_count nullable
  stock_quantity
```

Exact keys depend on ADR 0041's active location mode and the item's traceability settings.

### `CONTAINER_TRACKED`

Tracks every physical package/container:

```text
stock_containers
  id, company_id, item_id, item_packaging_id
  identifier
  warehouse/location/batch/expiry scope
  original_stock_quantity, remaining_stock_quantity
  status                        SEALED | OPEN | EMPTY | QUARANTINED | SCRAPPED
```

Container identity is distinct from lot identity. A container can move, open, empty or be
quarantined while remaining traceable to its receipt and package definition.

These are conceptual shapes, not migration-ready DDL. Implementation must settle constraints,
locking, event/audit representation and reconciliation indexes without weakening the invariants.

## Configuration and transitions

Package tracking resolves once, before an item's first movement:

```text
explicit item choice → nearest category default → company default (QUANTITY_ONLY)
```

The concrete mode is persisted on the item. Category/default changes affect newly resolved items,
not historical ones. Moving upward requires a counted/reconciled opening package state. Moving
downward is blocked while detail or reservations would be destroyed.

Selection strategy resolves for every new preview:

```text
item override → nearest category default → company default (MINIMISE_WASTE)
```

It may therefore change prospectively. Adopted/issued plans snapshot the effective strategy and
version so history remains reproducible.

## Proposal contract

Input includes:

- item and planned requirement in a validated item quantity unit;
- permitted inventory scope and availability as-of time;
- tracking-mode-appropriate package/balance/container facts;
- effective strategy and internal valuation evidence.

Hard filters exclude expired, quarantined, reserved, inaccessible and otherwise unusable stock.
The engine then applies one preset:

| Preset | Ordered intent |
|---|---|
| `MINIMISE_WASTE` | open remnants → FEFO → least surplus → lowest internal cost → fewest packs/picks |
| `LOWEST_INTERNAL_COST` | internal cost → least surplus → FEFO → fewest packs/picks |
| `FEWEST_PACKAGES` | newly opened packages/picks → least surplus → cost → FEFO |

The order is lexicographic, with stable identifiers as a final tie-breaker. Missing evidence skips
that criterion with a warning; it never becomes a favourable zero or invented fact.

Output includes:

- standard and planned requirement;
- effective strategy/version and applied criteria;
- recommended lines and useful alternatives;
- covered quantity, shortage and expected residual;
- internal-cost evidence and tracking limitations;
- availability as-of time and a clear no-reservation warning.

The user may choose another valid mix. The adopted plan retains both the generated recommendation
and final selection. Under ADR 0054, that choice normally needs no reason while planned quantity
is unchanged, unless its cost/waste consequence crosses a configured control band. ADR 0057
decides permissions and field visibility. Phase 3 choices are hypothetical; ADR 0058
defers persisted adoption until a Phase 4 quotation revision owns the plan.

## Inventory invariants

- `stock_moves` and the established valuation records remain authoritative for quantity and value.
- Package/container state must update transactionally with stock operations and reconcile under
  the same company/item/location/traceability scope.
- Opening a sealed package may change state without changing ledger quantity or value.
- An issue/return/scrap changes both ledger and enabled package state.
- Package cost is derived from inventory valuation evidence; package definitions do not maintain
  a competing cost truth.
- Phase 3 reads availability but never locks, reserves or posts it.

## Phase boundaries

- **Phase 3:** advisory internal-inventory package proposals and user selection.
- **Phase 4:** quotation revision/adoption and immutable cost/material snapshots; customer selling
  price is resolved independently.
- **Phase 5:** reservation, final allocation, issue, return, scrap and actual consumption.
- **Purchasing:** replenishes reported shortages and may later own supplier/package offers.
- **Phase 6:** actual acquisition cost, variances and accounting consequences.

## Deferred cases

- Variable-content/catch-weight packages remain
  [Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages).
- Customer-supplied material, substitutions, recoverable scrap and returnable containers remain
  [Q24](../OPEN-QUESTIONS.md#q24--phase-5-material-exceptions).
- Planned-variance audit/escalation is decided by ADR 0054 and permissions by ADR 0057.
- A custom policy builder comes only after preset usage provides real requirements; its policies
  will be ordered, versioned and explainable rather than arbitrary code or opaque weights.

## Related

- [Material resolution](material-resolution.md) · [Items](items.md) · [Stock](stock.md)
- [Purchasing](purchasing.md) · [Sales pipeline](sales-pipeline.md)
- [ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md)
- [ADR 0051](../decisions/0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md)
- [ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md)
- [ADR 0053](../decisions/0053-explainable-inventory-package-selection-presets.md)
- [ADR 0054](../decisions/0054-risk-based-material-plan-variance-controls.md)
- [ADR 0057](../decisions/0057-stable-material-resolution-capabilities-with-dynamic-assignment.md)
- [ADR 0058](../decisions/0058-phase-3-material-resolution-preview-contract.md)
