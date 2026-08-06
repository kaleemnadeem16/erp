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
  valuation_method  AVCO                 -- see stock.md
  tracking          NONE | BATCH | SERIAL
  category_id
  default_supplier_id
  income_account_id, expense_account_id, inventory_account_id   -- GL mapping
  custom_fields     json
  status            ACTIVE | ARCHIVED

  unique(['company_id', 'code'])         -- composite, always
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
([general-ledger.md](general-ledger.md)). Defaults come from the category; overrides are
per-item.

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

## Related

- [units-of-measure.md](units-of-measure.md)
- [stock.md](stock.md) — valuation of stocked items
- [formulations.md](formulations.md) — items as recipe inputs and outputs
