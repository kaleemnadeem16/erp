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

Step 2 means exactly what it says: once every *required* dimension the document supplied matches
a candidate rate, that rate wins at this tier regardless of what the document supplied — or the
candidate carries — on any *optional* dimension. An optional-dimension mismatch is not a partial
failure that falls through to step 3; it is simply not evaluated. `demo-metal` (tagged only
`Surface Type = Metal`) matching a request for `Metal` + `Coats = 1` is the correct outcome, not
a gap — see `ApplicationRatesDemoSeeder`.

Step 3 also means what it says, and it is easy to misread step 4 against it: **once an item+work
unit has a rate flagged `is_default`, step 4 is unreachable for it** — every request that fails
steps 1 and 2 lands on the default, regardless of which dimension values were supplied. A rate
with **no** dimension tags at all is only ever selected via `is_default`; it never wins step 1
against an empty request either, which would otherwise collide the two tiers (a real bug this
document's own demo data caught while being built — see `ResolveApplicationRate`'s docblock). A
genuine step-4 failure therefore requires an item+work-unit combination with candidate rates but
**no default configured** — that is a deliberate gap, not a missing feature, and
`ApplicationRatesDemoSeeder`'s per-metre scenario exists specifically to demonstrate it.

Step 4 matters regardless. A missing rate must fail loudly — silently defaulting produces a
plausible estimate that is wrong by an unknown factor.

### Direction

Rates are stored as **consumption per work unit** (L per sqm), not coverage (sqm per litre),
even though users think in coverage. Storing one direction and presenting the other avoids
division-by-zero and keeps roll-up arithmetic uniform.

The UI should accept either and convert.

### Access to dimension setup

Creating, changing or deleting a rate dimension or one of its values requires
`estimating.rate-dimension.manage`. The API checks both the HTTP request and the underlying
Action, so a job or other non-HTTP caller cannot bypass it. Listing dimensions and values remains
available to authenticated company users because application-rate and assembly forms need them
as shared lookups. The Rate Dimensions setup screen is shown only to holders of the manage
capability; no read-only detail screen exists yet. The permission is additive and company-scoped,
not tied to a hardcoded role name. Administrators must grant it to existing roles or members who
maintain dimensions before deploying this gate; registration by itself does not assign it.
The Rate Dimensions list exposes its API code/name search through URL-backed state and
offers a card view on mobile; this does not alter the shared lookup read authority.

### Access to application rates

Creating, changing or deleting an application rate requires `estimating.application-rate.manage`
at both the HTTP and Action boundaries. Listing, showing and resolving rates remain authenticated
company reads, because estimators need to inspect and test the consumption rule without gaining
authority to alter it. In the SPA, a material-preview user can reach the rate list and resolution
tester but not the New/edit forms; managers can use both. Existing maintainers need an explicit
role or direct grant before deployment. The configured administrator receives new capabilities
through normal deploy-time permission sync; no other role gains one simply because it is named
Estimator or Master Data Steward.

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
