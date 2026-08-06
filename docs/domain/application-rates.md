[← Domain index](README.md)

# Application Rates

How much of an item is consumed per **one unit of work**, varying by context. "1 litre covers
12 square metres on smooth plaster."

The context axes are **defined by each company, not by core code**
([ADR 0009](../decisions/0009-company-defined-rate-dimensions.md)). This is the main mechanism
by which the system supports different industries.

## Where it sits

Between the [assembly](assemblies.md) (which sells work) and the [formulation](formulations.md)
(which prices material). Built in [Phase 3](../phases/README.md).

## Schema

```
rate_dimensions                    -- company-defined axes
  id, company_id
  code, name, sort_order
  is_required        bool          -- must a rate specify a value on this axis?

rate_dimension_values
  id, rate_dimension_id
  code, name, sort_order

application_rates
  id, company_id
  item_id            → items       -- what is consumed
  work_uom_id        → uoms        -- the denominator: sqm, metre, hour
  qty_per_work_unit  decimal(19,6) -- the numerator
  uom_id             → uoms        -- unit of the numerator
  is_default         bool          -- fallback when no combination matches

application_rate_dimensions        -- which combination this rate applies to
  application_rate_id
  rate_dimension_value_id
```

## Behaviour

### Worked example — paint

The company creates one dimension, *Surface Type*:

| Dimension | Values |
|---|---|
| Surface Type | Smooth plaster · Rough plaster · Metal |

Then three rates for Shade #4471, all with `work_uom = sqm`:

| Surface Type | qty_per_work_unit | Meaning |
|---|---|---|
| Smooth plaster | 0.0833 L/sqm | 12 sqm per litre |
| Rough plaster | 0.1333 L/sqm | 7.5 sqm per litre |
| Metal | 0.0714 L/sqm | 14 sqm per litre |

### Worked example — screeding

A different company creates *Thickness (mm)* with values 25/50/75 and defines rates for mortar
per sqm. **Same tables, same code, no core change.** That is the whole point.

### Resolution

Given an item, a work UoM and a set of dimension values from the document:

```
1. Exact match on all specified dimension values
2. Match on required dimensions only, ignoring optional ones
3. The rate flagged is_default
4. No rate → error, do not silently assume 1:1
```

Step 4 matters. A missing rate must fail loudly — silently defaulting produces a plausible
estimate that is wrong by an unknown factor.

### Direction

Rates are stored as **consumption per work unit** (L per sqm), not coverage (sqm per litre),
even though users think in coverage. Storing one direction and presenting the other avoids
division-by-zero and keeps roll-up arithmetic uniform.

The UI should accept either and convert.

## Landmines

**Confusing this with UoM conversion.** Litres → sqm is *not* arithmetic; it depends on surface,
thickness and coats. Keep [units-of-measure.md](units-of-measure.md) and this file's concerns
separate.

**Hardcoding a dimension.** The moment `surface_type_id` appears as a column, the model is
paint-only and every other industry needs a code change. This is exactly what
[ADR 0009](../decisions/0009-company-defined-rate-dimensions.md) exists to prevent.

**Combinatorial explosion.** Three dimensions with five values each is 125 possible rates.
Companies will not enter them all. Hence `is_default` and a well-defined fallback chain — and a
UI that makes gaps visible.

**Silent fallback.** If resolution falls back to a default, the document must record *which*
rate was used. Otherwise nobody can explain the number later.

## Related

- [assemblies.md](assemblies.md) — consumes these rates
- [formulations.md](formulations.md) — prices the item consumed
- [ADR 0009](../decisions/0009-company-defined-rate-dimensions.md)
