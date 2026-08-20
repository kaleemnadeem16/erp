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
  code, name, description
  type              RAW | SEMI_FINISHED | FINISHED | SERVICE | LABOUR | PLANT
  stock_uom_id      → uoms
  is_stocked        bool
  is_purchasable    bool
  is_sellable       bool
  valuation_method  AVCO | FIFO | SPECIFIC   -- see "Valuation method" below
  tracking          NONE | BATCH | SERIAL
  category_id
  default_supplier_id
  income_account_id, expense_account_id, inventory_account_id   -- GL mapping
  custom_fields     json
  status            ACTIVE | ARCHIVED

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

**Three independent concepts — do not conflate them:** `valuation_method` (accounting cost basis),
`tracking` (whether units/lots are traceable at all), and a warehouse's physical removal strategy
(not built — future `FIFO`/`FEFO`/`MANUAL`, picking order on the floor). `BATCH` tracking does not
imply FIFO costing. `SERIAL` tracking does not imply `SPECIFIC` valuation. Physical picking order
has no obligation to match the accounting cost basis. See ADR 0032 for the full reasoning.

### Archiving, not deleting

Items are archived, never deleted. Historical documents reference them and must remain
readable. Soft deletes throughout.

## Landmines

**Codes and case sensitivity.** Postgres is case-sensitive, so `PIG-001` and `pig-001` are
distinct rows and the unique constraint will not catch the duplicate. **Normalise to lowercase in
the application before saving.** See [database.md](../architecture/database.md).

**Changing an item's stock UoM after transactions exist.** Silently invalidates every historical
quantity. Block it once any stock move or document line references the item.

**Assuming purchase UoM equals stock UoM.** Pigment is bought in 25 kg drums and consumed in
grams. Purchase UoM is a property of the purchase line, not the item.

**Category cycles.** `parent_id` is a plain self-reference with no schema-level cycle guard — a
category accidentally set as its own ancestor would infinite-loop the GL-mapping walk above.
Closed at the application level: `App\Modules\Inventory\Models\Category`'s `saving` hook walks
the new parent chain and rejects any reparent that would revisit the category's own id, tested
in `CategoryTest`.

## Related

- [units-of-measure.md](units-of-measure.md)
- [stock.md](stock.md) — valuation of stocked items
- [formulations.md](formulations.md) — items as recipe inputs and outputs
- [parties.md](parties.md) — `default_supplier_id` references a supplier account. It's a Phase 1
  data-entry default only, not a restriction to one supplier; Phase 2 adds a real `item_suppliers`
  catalog (supplier-specific code, priority, lead time, MOQ, purchase UoM) and
  `default_supplier_id` is deprecated once it exists, see
  [ADR 0033](../decisions/0033-item-suppliers-relationship.md)
- [stock.md](stock.md) — where `valuation_method` actually posts
- [ADR 0032](../decisions/0032-per-item-valuation-method.md) — why valuation method is per-item,
  not company-wide
