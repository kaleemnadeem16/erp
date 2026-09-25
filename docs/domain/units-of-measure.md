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

Collapsing these into one table is the most common way this kind of model goes wrong.

### Precision

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

## Related

- [material-resolution.md](material-resolution.md) — item-specific conversion, pack and
  quantity-policy gaps across later phases
- [items.md](items.md) — each item has a stock UoM
- [application-rates.md](application-rates.md) — cross-dimension consumption
- [architecture/invariants.md](../architecture/invariants.md)
