[← Domain index](README.md)

# Formulations

A recipe: the inputs required to produce **one base unit of an output item**. "To make 1 litre
of Shade #4471: 0.12 kg pigment + 0.40 L binder + 0.25 L solvent."

Generic — this is a bill of materials for process manufacturing. Paint, mortar, food, chemical
dilution, fertiliser blends all use the same structure.

## Where it sits

```
assembly component (MATERIAL)
      ↓ how much per work unit?
application rate
      ↓ what does one unit of that item cost?
FORMULATION  ← you are here
      ↓ what does each ingredient cost?
stock valuation (AVCO)
```

Built in [Phase 3](../phases/README.md).

## Schema

```
formulations
  id, company_id
  output_item_id     → items
  code, name
  output_qty         decimal(19,6)     -- usually 1
  output_uom_id      → uoms            -- e.g. litre
  customer_id        NULLABLE → customers   ← the override mechanism
  status             DRAFT | ACTIVE | ARCHIVED

  unique(['company_id', 'code'])

formulation_versions
  id, formulation_id
  version_no
  effective_from, effective_to  NULLABLE
  status             DRAFT | ACTIVE | SUPERSEDED
  notes
  -- IMMUTABLE once referenced by any document

formulation_lines
  id, formulation_version_id
  input_item_id      → items
  qty                decimal(19,6)
  uom_id             → uoms
  wastage_pct        decimal(9,4)      -- material lost in process
  sequence
```

## Behaviour

### Customer-specific formulations

`formulations.customer_id` is nullable. Resolution:

```
1. (output_item, customer_id = X)     ← this customer's own formulation
2. (output_item, customer_id = NULL)  ← company default
```

One nullable column and one resolver. No special-casing anywhere else in the system.

### Versioning and immutability

**A formulation version is frozen the moment any document references it.** Changing a recipe
creates version N+1 with a new `effective_from`; the old version is marked `SUPERSEDED` and kept.

Documents pin to a `formulation_version_id`, so a job costed last March still reflects March's
recipe. This is [invariant 4](../architecture/invariants.md#4-versioned-records-are-immutable-once-used).

### Cost of one output unit

```
cost_per_output_unit = Σ (line.qty × (1 + line.wastage_pct) × input_item.avco_cost)
                       ÷ formulation.output_qty
```

Ingredient cost comes from [stock valuation](stock.md), not from a price list — it reflects what
was actually paid.

### Nesting

A formulation's input may itself be a `SEMI_FINISHED` item with its own formulation (a base
mixed separately, then tinted). Roll-up recurses.

**Cycle detection is required.** A formulation must not, directly or transitively, consume its
own output. Validate on save, not at roll-up time.

Roll-up uses a **recursive CTE** rather than an N+1 loop in PHP — see
[database.md](../architecture/database.md#recursive-ctes-for-formulation-explosion). Keep the
depth guard regardless; it catches cycles that slipped past validation.

## Landmines

**Editing a version in place.** Silently rewrites the cost basis of every historical job that
used it. The single most damaging mistake available in this model.

**Wastage compounding through nesting.** A nested formulation applies wastage at each level.
That is usually correct — material really is lost at each stage — but it surprises people. Show
the breakdown in the UI rather than only the total.

**Rounding intermediate quantities.** Ingredient quantities are small; rounding at each line
compounds visibly across a large job. Round only at presentation.

**Unbounded recursion depth.** Nesting is legitimate but deep chains make roll-up slow and
debugging hard. Cap depth and surface the limit clearly.

## Related

- [application-rates.md](application-rates.md) — the layer above
- [stock.md](stock.md) — where ingredient cost comes from
- [assemblies.md](assemblies.md) — how formulations enter a sellable price
- [ADR 0008](../decisions/0008-assembly-abstraction.md)
