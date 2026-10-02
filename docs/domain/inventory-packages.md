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

## First implementation: QUANTITY_ONLY availability and allocation

Shipped with the Phase 3 Material Preview's inventory proposal slice
(`POST /api/v1/assemblies/{assembly}/material-preview`, ADR 0058). Only the `QUANTITY_ONLY` mode
exists, so this section is what "approximate, quantity per location" concretely means today.

**Availability read.** Inventory's `QueryStockAvailability` reads every open `stock_layers` row of
the requested items, grouped by item, location and batch, in one SQL statement — one consistent
snapshot, no lock, no write. Layers are the only table carrying a location, and they stay current
for AVCO items too, so quantity never comes from `avco_pools`. The caller's requested
warehouse/location scope can only narrow the company-wide scope (department-limited availability
waits on [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope)); both
filters together are an intersection.

Each balance's `on_hand` is partitioned into three so a shortfall can be reconciled by eye:

| Part | Meaning |
|---|---|
| `held` | Stock awaiting QA release: a QA-required receipt line holds `stock_qty` minus PASS/ACCEPT_WITH_CONCESSION releases, attributed to the layer that receipt created and capped at what the layer still holds |
| `unusable_location` | Free stock in a location that cannot supply work: an inactive location/warehouse, or a location that does not count as usable. A location counts by its own mark (`locations.counts_as_usable`) when the company set one, otherwise by its type — only `STORAGE`/`RECEIVING` (`LocationType::holdsUsableStock()`). See [ADR 0067](../decisions/0067-governed-per-location-usability-mark.md) |
| `usable` | The rest — the only stock a proposal may draw from |

This mirrors the inventory overview's QA rule but is stricter in two deliberate ways: it is
location-aware, and it does not subtract QA hold from stock already consumed out of a held layer
(the overview's known over-subtraction). With no unusable location in play the two "available"
figures agree — `QueryStockAvailabilityTest` asserts it. The location-type default is a conservative
policy: it may under-report usable stock, and says so, but never over-reports it. A STRUCTURED-mode company can
mark a location as counting or not counting (permission `inventory.location-policy.manage`, a reason, and an
`inventory_policy_changes` entry); each balance reports `location_usable_source` (`TYPE` or `MARKED`).

**Cost evidence** follows what a real issue would consider defensible, and is `null` — never zero —
otherwise: AVCO uses the pool the movement engine would resolve for that location/batch
(`COMPANY_ITEM`, `WAREHOUSE_ITEM` or `COMPANY_ITEM_BATCH`), only while it is `ACTIVE` with a positive
average; FIFO and SPECIFIC use the remaining layers' weighted-average base cost.

**Stored strategy** ([ADR 0071](../decisions/0071-stored-material-selection-strategy-as-governed-history.md)): each material's preset resolves
item → nearest category ancestor → company → the built-in `MINIMISE_WASTE`, and a strategy chosen for one preview overrides all of
them and stores nothing. Each item block names the level that answered (`ITEM`, `ITEM_CATEGORY`, `COMPANY`, `BUILT_IN_DEFAULT`,
`REQUEST_OVERRIDE`). A setting is a row per change with a required reason and actor, never edited (clearing is a row with no
strategy); it affects only later previews, and the evidence fingerprint includes it. Changing needs
`estimating.selection-policy.manage` (Settings → Material selection); reading it needs that or `estimating.policy-history.view`.

**Allocation** (`AllocateInventory`, a pure function, `RULES_VERSION` 1) applies the three presets
lexicographically with the stable location/batch key as the final tie-breaker. Criteria that need
evidence `QUANTITY_ONLY` does not have — open remnants, expiry, package surplus, newly opened
packages — are reported per item as not applied, with the reason; the ordering continues on what
can be evaluated, which is cost and physical picks:

| Preset | Effective order today |
|---|---|
| `MINIMISE_WASTE`, `LOWEST_INTERNAL_COST` | cheapest balance first, larger balance first among equals (so fewest picks among minimum-cost plans) |
| `FEWEST_PACKAGES` | fewest locations that can cover the requirement, then the cheapest fill among equally-sized sets; that set search is bounded and reports when the bound skips the cost tie-break |

Because the two cost-first presets coincide here, the response lists the other two presets as
alternatives and flags each `same_as_recommendation`. A balance without cost evidence ranks after
every costed one and makes the plan's estimated cost unknown rather than partial.

**Hand-built mix** ([ADR 0072](../decisions/0072-hand-built-stock-mix-in-base-quantity.md)): a person may instead choose the quantity to take from
each location/batch. The canonical line is `(item, location, batch, quantity in the item's stock unit)` — the same shape as an automatic
proposal — typed in any unit that converts through the item's conversion (0.75 of a 2 kg drum is 1.5 kg; a part-used pack is just a
smaller quantity). The server refuses, rather than warns about, stock that is not a usable balance in scope (absent, held for QA, in a
location that does not count, exhausted), a draw above a balance, a repeated location/batch, a quantity that breaks the item's
precision, and an unconvertible unit. Lines adding up to *more than the plan* are refused too — using more is a planned-quantity change
the variance rules must judge — while adding up to less is reported as a shortage. Pricing uses the proposal's cost evidence (never a
zero) and the result names, per line, the source, entered quantity and factor, usable before and remaining after, and how the cost
compares with the proposal's. Package tracking will add facts about these lines (a package identity, sealed or open); it does not
replace them.

**Not yet built** — each is a deliberate boundary, not an oversight:

- `PACKAGE_BALANCE` and `CONTAINER_TRACKED` modes and the package definitions
  (`item_packagings`), so no sealed-pack, remnant or container claim is ever made.
- Package equivalents of a quantity draw ("about two 5 L cans"), which would be approximate in
  `QUANTITY_ONLY` and are better delivered with `PACKAGE_BALANCE`.

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

## Fixed purchasing definitions (ADR 0080)
 
Named fixed packages now have their own item-owned direct stock quantity, increment, barcode
and optional inner package/count. Content and identity cannot be edited; archive/create replaces
them. Maintenance needs inventory.item.manage in HTTP and Actions. Inner content must match
the same item and direct quantity; immutable links cannot introduce a cycle.
This implements package masters only: PACKAGE_BALANCE and CONTAINER_TRACKED remain unbuilt.
 
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

Package content and inner-package composition are immutable at both the model and database boundary. An item stock unit cannot change after a package definition exists, even before its first stock movement, because every package factor is defined against that unit. Archive/create preserves identity when content changes.
