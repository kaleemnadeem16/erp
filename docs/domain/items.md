[← Domain index](README.md)

# Items

Everything the system buys, holds, makes, consumes or sells. A single table with a type
discriminator rather than separate product/service/resource tables — they share too much
(codes, units, costing, GL mapping) to justify splitting.

## Where it sits

Master data. Referenced by formulations, application rates, assembly components, stock and
purchasing. Built in [Phase 1](../phases/README.md).

## Schema

```
items
  id, company_id
  code, name
  description       NULLABLE
  type              RAW | SEMI_FINISHED | FINISHED | SERVICE | LABOUR | PLANT
  stock_uom_id      → uoms
  is_stocked        bool DEFAULT false
  is_purchasable    bool DEFAULT false
  is_sellable       bool DEFAULT false
  valuation_method  AVCO | FIFO | SPECIFIC   -- see "Valuation method" below
  avco_pool_scope   NULLABLE COMPANY_ITEM | WAREHOUSE_ITEM | COMPANY_ITEM_BATCH
  tracking          NONE | BATCH | SERIAL DEFAULT NONE
  category_id
  income_account_id, expense_account_id, inventory_account_id   -- NULLABLE GL mapping
  custom_fields     jsonb NULLABLE DEFAULT '{}'
  status            ACTIVE | ARCHIVED DEFAULT ACTIVE
  deleted_at        NULLABLE   -- soft deletion/archive

  unique(['company_id', 'code'])         -- composite, always

categories
  id, company_id
  parent_id                  NULLABLE → categories                  -- null = top-level
  code, name
  default_valuation_method   NULLABLE  AVCO | FIFO | SPECIFIC        -- see "Valuation method" below
  income_account_id, expense_account_id, inventory_account_id      -- NULLABLE, see GL mapping

  unique(['company_id', 'code'])
```

## Behaviour

### Types

| Type | Stocked | Typical use |
|---|---|---|
| `RAW` | ✅ | Purchased ingredients — pigment, binder, solvent |
| `SEMI_FINISHED` | ✅ | Formulation output held in stock — a mixed shade |
| `FINISHED` | ✅ | Sold as-is, no further work |
| `SERVICE` | ❌ | Non-stock service; direct commercial source or part of an optional estimate ([ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)) |
| `LABOUR` | ❌ | A labour class, priced via a resource rate (below) |
| `PLANT` | ❌ | An hourly-rated piece of equipment, priced via a resource rate (below) |

`LABOUR` and `PLANT` are items so that assembly components reference one thing uniformly. Their
rate is **not** a column on `items` and does not come from AVCO stock valuation the way `RAW`
costs do — see "Resource rates" below for where it actually lives and why.

### Resource rates

`items` itself carries no rate/standard-cost column, and a non-stocked `LABOUR`/`PLANT` item has
no AVCO pool to price it from either — Inventory's material-cost query (see [material-cost-resolution.md](material-cost-resolution.md))
only ever resolves from stock valuation. A separate, Estimating-owned `resource_rates` table is the actual
source:

```
resource_rates
  id, company_id
  item_id            → items                 -- LABOUR or PLANT, enforced at the request layer
  rate               decimal(19,6)            -- per rate_uom_id, company base currency
  rate_uom_id        → uoms                   -- e.g. hour, day, shift — whatever the item is priced by
  effective_from     date

  unique(['company_id', 'item_id', 'effective_from'])
```

Shaped like `multi-currency.md`'s exchange-rate pattern deliberately, not like formulation/
assembly/overhead-policy's heavier DRAFT/ACTIVE/SUPERSEDED lifecycle: a resource rate is a market
fact (the same kind of thing an AVCO unit cost is), not a governed policy needing a reason or
audit trail. Resolution is "the most recent row with `effective_from` on or before the costing
date wins"; a correction is a new dated row, never an edit to an already-used historical one —
there is deliberately no update endpoint. See
[assemblies.md#cost-roll-up](assemblies.md#cost-roll-up) for how an assembly component resolves
and uses this, including the UoM-compatibility check between a component's own quantity unit and
the resolved rate's `rate_uom_id`.

Creating or deleting a rate requires `estimating.resource-rate.manage` at both the API and Action
boundary. Authenticated company users may list/show rate records as estimating lookups, but the
numeric `rate` is omitted from those responses unless the caller can manage rates or has
`estimating.internal_cost.view`. Managers need the amount to review and enter corrections;
cost viewers may inspect it without write access. The UI admits either capability to the rate
list and shows add/delete only to managers. No role name is hardcoded; existing maintainers need
an explicit company-role or direct grant before deployment.

### Stocked vs not

`is_stocked` determines whether stock moves and valuation apply. Services, labour and plant
carry a rate but no quantity on hand.

### GL mapping

Each item carries account references so postings are derivable without hardcoding
([general-ledger.md](general-ledger.md)). Resolution order: the item's own account column, if
set → its category's account column, if set → walk up `categories.parent_id` to the nearest
ancestor with that column set. Any level can leave a column null to fall through to its parent;
there is no forced default at the root, so a chain that resolves to nothing is a data problem to
catch — e.g. at posting time — not something the schema silently papers over.

Two things a plain FK doesn't catch, both deliberately deferred to Phase 6 rather than
implemented here: whether the assigned account actually belongs to the account's expected type
(`income_account_id` → `INCOME`, `expense_account_id` → `EXPENSE`, `inventory_account_id` →
`ASSET` — see [general-ledger.md](general-ledger.md#account-type-and-normal-balance)); and
whether an `ARCHIVED` or non-`is_postable` account should still be selectable here at all.
Cross-*company* misassignment, by contrast, is guarded now — `Category` and `Item` both reject
saving an account id that doesn't resolve to a `chart_of_accounts` row in the current company
(`GuardsRelatedCompanyScope`).

### Valuation method

Not one method for the whole company — [ADR 0032](../decisions/0032-per-item-valuation-method.md).
Chosen per item, defaulted from category: `categories.default_valuation_method` resolves the same
way as the GL account columns above — the item's category, or the nearest ancestor with that
column set, walking `parent_id`, no forced root default. Typical defaults: Raw Materials → AVCO,
Resale Goods → FIFO, Unique/Serialized Equipment → SPECIFIC.

Unlike the GL columns, `items.valuation_method` is **not** re-resolved on every read. It's
resolved once, at creation — an explicit value overrides the category default; otherwise the
category chain's resolution is stored directly on the item — and stays fixed from then on. A
later change to the category's default must not silently change an already-created item's
method. Every item ends up with exactly one method; unlike GL accounts, a chain that resolves to
nothing is not tolerated here — item creation fails rather than leaving `valuation_method` unset.

**Locked once the item has any inventory transaction.** `Item`'s `updating` hook rejects the
change once a `stock_move` references the item
(`GoodsReceiptPostingTest::test_item_valuation_policy_is_locked_after_receipt`). Changing an
item's method after that point is a controlled conversion/revaluation, not a field edit.

For an AVCO item, `avco_pool_scope` is resolved before its first movement from an explicit choice
or the company's `COMPANY_ITEM` default, then persisted. Supported scopes are `COMPANY_ITEM`,
`WAREHOUSE_ITEM` and `COMPANY_ITEM_BATCH`; the last requires `BATCH` tracking. Batch tracking does
not itself choose batch valuation. Non-AVCO items leave the field null. Like valuation method,
scope is locked after the first movement (same `updating` hook,
`test_item_avco_pool_scope_is_locked_after_receipt`) and never dynamically follows later
company-default changes. A live change requires an effective-dated, approved valuation-policy
migration with rebased pools and quantity/value reconciliation, per
[ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md).

**Three independent concepts — do not conflate them:** `valuation_method` (accounting cost basis),
`tracking` (whether units/lots are traceable at all), and a warehouse's physical removal strategy
(not built — future `FIFO`/`FEFO`/`MANUAL`, picking order on the floor). `BATCH` tracking does not
imply FIFO costing. `SERIAL` tracking does not imply `SPECIFIC` valuation. Physical picking order
has no obligation to match the accounting cost basis. See ADR 0032 for the full reasoning.

### Archiving, not deleting

Items are archived, never deleted. Historical documents reference them and must remain
readable. Soft deletes throughout.

Archiving stops *new* orders; it never strands stock already received. Returning or scrapping
rejected stock, issuing it, reading its package balances and tracking, and saving any record that
points at the item all keep working for an archived item (relationships use `withTrashed()`, and
the shared related-company guard checks tenancy, not liveness). Document line resources embed
`item {id, code, name}` so a historical document never needs a separate item request. Whether *new*
work may reference an archived item is a validation rule on the entry point, not a model guard.

### User interface

The React application exposes item list, detail, create and edit screens. The list is the
reference implementation for the shared server-driven table, URL-persisted filter/sort/page
state and quick-print components used by later master-data screens. Create and edit share one
validated form and map the API's standard `422` field errors back onto that form.

Quick Print uses a dedicated paper layout shared by all current master-data list and detail
screens. The browser page margin is the only outer inset (8 mm); the report does not add a second
container padding, so content starts at the top-left printable boundary and uses the available
paper width. Report headers and metadata stay with the following content, table headers repeat on
later pages, and individual rows avoid splitting across pages. Browser-supplied URL/date/page
headers and footers remain controlled by the user's print-dialog setting, not by the application.

The shared table always supplies an empty row-selection state when selection is not enabled;
passing an explicit `undefined` controlled state causes TanStack Table's selected-row model to
fail when the common pagination footer reads it.

The UoM selector reads the company-scoped, read-only `/api/v1/uoms` endpoint; it does not
hardcode units. Category and valuation choices follow the stored-resolution rules above:
creation may request the category default, while edit always submits a concrete valuation
method.

Category list/detail/create/edit screens expose hierarchy, valuation defaults and nullable GL
account defaults; blank values mean inheritance rather than an invented application default.
Item-supplier list/detail/create/edit screens expose the approved-supplier catalogue. The edit
form deliberately locks `item_id` and `supplier_account_id`, matching the immutable relationship
identity rule below, while allowing sourcing terms to change.

Items and Categories are company-wide reference data: authenticated users may list and view them
so cross-module selectors keep working. Write controls and endpoints require
`inventory.item.manage` and `inventory.category.manage` respectively. These capabilities do not
grant GL-field access. Under [ADR 0084](../decisions/0084-separate-financial-master-data-fields.md),
`accounting.account-mapping.view` reads these mappings and `.manage` changes them (and supplies
minimum read), in addition to resource maintenance. Restricted keys are omitted from list/detail,
lookup and nested Resources. Category detail/quick print hide the GL section without field read;
forms omit mapping keys without field management. Requests and Actions reject any unauthorised
submitted mapping key, including null or unchanged values; omission preserves stored mappings.
The additive migration grants existing Item/Category maintainers mapping read/change in their
existing companies. No Item GL editor or standalone Item/Party GL print path exists.

Item details embed the existing pack-conversion editor, scoped to the current item, so the
post-create detail screen is also the entry point for adding pack sizes. Write controls require
`inventory.item.manage`; the existing conversion API/Action authorisation gap is recorded in
[the delivery sequence](../phases/item-purchasing-improvements.md#authorisation-review).
Create/edit expose the optional stock quantity increment; detail and Quick Print show its stored
value or “Unrestricted”. This is divisibility metadata, not a supplier order multiple, and does
not add PO enforcement. See [the incremental plan](../phases/item-purchasing-improvements.md).

### Category screen delivery (2026-10-16)

The Categories workflow provides hierarchical item grouping, inheritance of default valuation methods,
and optional GL account mapping defaults. Delivered under the ERP Blue design standard and screen delivery
contract (`apps/main/src/presentation.ts` defaults to `sheet` with fallback `page` routes).

| Contract element | UI destination | Availability | Acceptance scenario / omission |
|---|---|---|---|
| List categories | `CategoriesListScreen` (`/categories`) | Authenticated read (`inventory.category.manage` for actions) | Filter by parent, debounced code/name search, active chips, Quick Print, column preferences |
| Create category | `CategoryCreateScreen` (`/categories/new`, sheet by default) | `inventory.category.manage` | Code, name, parent combobox (cycles prevented), default valuation, GL defaults (`accounting.account-mapping.manage`) |
| View category | `CategoryDetailScreen` (`/categories/:id`, sheet by default) | Authenticated read | Identity, parent and child category navigation links, GL defaults, Quick Print, delete dialog |
| Edit category | `CategoryEditScreen` (`/categories/:id/edit`, sheet by default) | `inventory.category.manage` | Edit code, name, parent (descendants excluded to prevent cycle), valuation, GL defaults |
| Delete category | Delete dialog on detail screen | `inventory.category.manage` | In-place confirmation; 409 conflict message reported when items or child categories reference the row |
| Bulk actions | List table | Not applicable | No bulk endpoint exists; category hierarchy changes require deliberate individual inspection |

## Landmines

**Codes and case sensitivity.** Postgres is case-sensitive, so `PIG-001` and `pig-001` are
distinct rows and the unique constraint will not catch the duplicate. **Normalise to lowercase in
the application before saving.** See [database.md](../architecture/database.md).

**Changing an item's stock UoM after transactions exist.** Silently invalidates every historical
quantity. Block it once any stock move or document line references the item.

**Assuming purchase UoM equals stock UoM.** Pigment is bought in 25 kg drums and consumed in
grams. Purchase UoM is a property of the purchase line, not the item. A pack unit that shares no
dimension with the stock unit (a box of eaches, a roll of metres) converts through the item-specific
conversion of
[ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md), and a line's unit
starts on whatever the chosen supplier sells the item in
([purchasing.md](purchasing.md#purchase-unit-default)).

**Supplier sourcing.** Approved suppliers are represented by Purchasing's company-scoped
`item_suppliers` relationship, not a single column on `items`. Each relationship may carry the
supplier SKU, priority, purchase UoM, MOQ and an item-specific lead-time override. Priority 1 is
the preferred data-entry default; no preferred row is a valid deterministic state and means the
caller must choose a supplier explicitly. Pricing remains transactional on the PO line. Existing
Phase 1 `default_supplier_id` values were migrated to active priority-1 relationships before that
column was removed.

**Category cycles.** `parent_id` is a plain self-reference with no schema-level cycle guard — a
category accidentally set as its own ancestor would infinite-loop the GL-mapping walk above.
Closed at the application level: `App\Modules\Inventory\Models\Category`'s `saving` hook walks
the new parent chain and rejects any reparent that would revisit the category's own id, tested
in `CategoryTest`. Category deletion is a real delete, not a soft archive; PostgreSQL foreign
keys reject it while items or child categories still reference the row.

**`PLANT` currently means two different things.** The demo dataset's `demo-spray-unit` is a
stocked, serial-tracked, `SPECIFIC`-valued capital asset (owned equipment tracked by serial
number) — a genuinely different concept from an hourly-rated `PLANT` *resource* priced via
`resource_rates` (e.g. `demo-plant-scaffold`), even though both currently share the `PLANT` item
type. Found while building resource rates (Phase 3 Step 3) and left as-is rather than fixed —
changing the domain model to split them is a separate decision, not a demo-data correction.

**Tenant-safe references.** Every company-scoped reference on `items` and `categories` is
validated through the current company in the application. Composite PostgreSQL foreign keys
also pair each referenced id with `company_id`, so raw SQL cannot attach an item to another
company's category, UoM or GL account. `item_suppliers` applies the same protection to its item,
supplier-account and optional purchase-UoM references.

### Item form completeness (2026-10-06)

Create and edit expose status, stock quantity increment, the existing stock/tracking/valuation
options and generic custom-field inputs. Account mappings are shown and submitted only with
`accounting.account-mapping.manage`; omitted keys preserve existing mappings. Blank mapping values
inherit category defaults. `inventory.item.manage` still gates the form and its backend writes;
there is no new Item permission or approval consequence. The API validates custom values against
company/entity definitions, including required fields on creation.

Pack conversions, packages and supplier offers still belong to the saved item's detail workspace:
creation explains that next step rather than offering child records without an item identity.
Custom-field configuration and its supported types are described in
[extensibility](../architecture/extensibility.md#implemented-custom-field-settings-2026-10-06).

## Related

- [material-resolution.md](material-resolution.md) — accepted quantity, package, cost and phase
  boundaries
- [inventory-packages.md](inventory-packages.md) — named package identity above ADR 0050's
  quantity conversion and company/category/item tracking defaults
- [ADR 0051](../decisions/0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md)
  — internal-inventory package proposals
- [units-of-measure.md](units-of-measure.md)
- [ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md) — item-specific
  purchase-UoM conversion and divisibility, implemented via `item_quantity_units`/
  `quantity_precision`
- [ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md) — resolved package
  tracking mode is persisted before first movement
- [stock.md](stock.md) — valuation of stocked items
- [formulations.md](formulations.md) — items as recipe inputs and outputs
- [assemblies.md](assemblies.md#cost-roll-up) — where `resource_rates` (LABOUR/PLANT pricing) is
  actually consumed
- [multi-currency.md](multi-currency.md) — the exchange-rate pattern `resource_rates` mirrors
- [parties.md](parties.md) — supplier accounts referenced by Phase 2's `item_suppliers` catalogue
- [ADR 0033](../decisions/0033-item-suppliers-relationship.md) — approved supplier relationships
- [stock.md](stock.md) — where `valuation_method` actually posts
- [ADR 0032](../decisions/0032-per-item-valuation-method.md) — why valuation method is per-item,
  not company-wide
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md) — constrained AVCO scope,
  locking and historical pool identity
