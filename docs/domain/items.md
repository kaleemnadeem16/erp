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
| `SERVICE` | ❌ | Sold work — the output of an [assembly](assemblies.md) |
| `LABOUR` | ❌ | A labour class with an hourly rate |
| `PLANT` | ❌ | Equipment with an hourly rate |

`LABOUR` and `PLANT` are items so that assembly components reference one thing uniformly, and
their rates change over time through the same mechanism as material costs.

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

**Locked once the item has any inventory transaction** (Phase 2, once `stock_moves` exists — not
enforceable yet, nothing to check against today). Changing an item's method after that point is a
controlled conversion/revaluation, not a field edit.

For an AVCO item, `avco_pool_scope` is resolved before its first movement from an explicit choice
or the company's `COMPANY_ITEM` default, then persisted. Supported scopes are `COMPANY_ITEM`,
`WAREHOUSE_ITEM` and `COMPANY_ITEM_BATCH`; the last requires `BATCH` tracking. Batch tracking does
not itself choose batch valuation. Non-AVCO items leave the field null. Like valuation method,
scope is locked after the first movement and never dynamically follows later company-default
changes. A live change requires an effective-dated, approved valuation-policy migration with
rebased pools and quantity/value reconciliation, per
[ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md).

**Three independent concepts — do not conflate them:** `valuation_method` (accounting cost basis),
`tracking` (whether units/lots are traceable at all), and a warehouse's physical removal strategy
(not built — future `FIFO`/`FEFO`/`MANUAL`, picking order on the floor). `BATCH` tracking does not
imply FIFO costing. `SERIAL` tracking does not imply `SPECIFIC` valuation. Physical picking order
has no obligation to match the accounting cost basis. See ADR 0032 for the full reasoning.

### Archiving, not deleting

Items are archived, never deleted. Historical documents reference them and must remain
readable. Soft deletes throughout.

### User interface

The React application exposes item list, detail, create and edit screens. The list is the
reference implementation for the shared server-driven table, URL-persisted filter/sort/page
state and quick-print components used by later master-data screens. Create and edit share one
validated form and map the API's standard `422` field errors back onto that form.

The UoM selector reads the company-scoped, read-only `/api/v1/uoms` endpoint; it does not
hardcode units. Category and valuation choices follow the stored-resolution rules above:
creation may request the category default, while edit always submits a concrete valuation
method.

Category list/detail/create/edit screens expose hierarchy, valuation defaults and nullable GL
account defaults; blank values mean inheritance rather than an invented application default.
Item-supplier list/detail/create/edit screens expose the approved-supplier catalogue. The edit
form deliberately locks `item_id` and `supplier_account_id`, matching the immutable relationship
identity rule below, while allowing sourcing terms to change.

## Landmines

**Codes and case sensitivity.** Postgres is case-sensitive, so `PIG-001` and `pig-001` are
distinct rows and the unique constraint will not catch the duplicate. **Normalise to lowercase in
the application before saving.** See [database.md](../architecture/database.md).

**Changing an item's stock UoM after transactions exist.** Silently invalidates every historical
quantity. Block it once any stock move or document line references the item.

**Assuming purchase UoM equals stock UoM.** Pigment is bought in 25 kg drums and consumed in
grams. Purchase UoM is a property of the purchase line, not the item.

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

**Tenant-safe references.** Every company-scoped reference on `items` and `categories` is
validated through the current company in the application. Composite PostgreSQL foreign keys
also pair each referenced id with `company_id`, so raw SQL cannot attach an item to another
company's category, UoM or GL account. `item_suppliers` applies the same protection to its item,
supplier-account and optional purchase-UoM references.

## Related

- [units-of-measure.md](units-of-measure.md)
- [stock.md](stock.md) — valuation of stocked items
- [formulations.md](formulations.md) — items as recipe inputs and outputs
- [parties.md](parties.md) — supplier accounts referenced by Phase 2's `item_suppliers` catalogue
- [ADR 0033](../decisions/0033-item-suppliers-relationship.md) — approved supplier relationships
- [stock.md](stock.md) — where `valuation_method` actually posts
- [ADR 0032](../decisions/0032-per-item-valuation-method.md) — why valuation method is per-item,
  not company-wide
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md) — constrained AVCO scope,
  locking and historical pool identity
