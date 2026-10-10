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
  customer_id        NULLABLE → customer_accounts   ← the override mechanism
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

Most manufactured products use only the company-default formulation. The customer field exists
solely for the exceptional case where the same output item is made differently for a particular
customer (for example a private-label colour, allergen substitution or contract-specific mix).
It is not ownership and it does not make an ordinary make-to-stock formulation customer-bound.

The item detail screen lists every formulation that produces the item, including whether each is
the company default or a customer override. This is operational navigation, not a new ownership
relationship; normal authenticated item access is sufficient until the application introduces a
dedicated formulation-view permission.

### Versioning and immutability

**A formulation version is frozen the moment any document references it.** Changing a recipe
creates version N+1 with a new `effective_from`; the old version is marked `SUPERSEDED` and kept.

Documents pin to a `formulation_version_id`, so a job costed last March still reflects March's
recipe. This is [invariant 4](../architecture/invariants.md#4-versioned-records-are-immutable-once-used).

Phase 3 itself never creates that first document reference — assemblies (a later Phase 3 slice)
are the first thing that will. Its own concrete boundary is simpler and self-contained: a version
is editable only while `DRAFT`; `ACTIVATE` is the sole transition to `ACTIVE` (superseding
whatever was previously active) and both `ACTIVE` and `SUPERSEDED` are frozen from that point on.
The cycle guard is re-run at activation too, not only on save — two formulations built
independently, each individually cycle-free while both are still `DRAFT` (the guard only ever
walks *active* sub-formulations, since a draft recipe isn't real consumption yet), could
otherwise each activate successfully and only complete a live cycle on the second activation,
with nothing having ever rejected either save.

### Cost of one output unit

```
cost.unit_cost = Σ line costs (line.qty × (1 + line.wastage_pct) × output factor, costed through Inventory)
                 ÷ formulation.output_qty        (only when every applicable line is costed)
```

Ingredient cost comes from [Inventory cost evidence](material-cost-resolution.md), never a price list.
The existing cost-breakdown operation now returns one typed `cost` resolution per batch and line,
with COSTED/PARTIAL/UNKNOWN certainty, nullable complete amounts and separately labelled known
contributions. Genuine source-supported zero remains complete; missing material evidence never
becomes free. FIFO costs the actual combined demand without reusing layers. SPECIFIC needs identified
stock; an ordinary recipe without that context remains unknown. Built-in scope is COMPANY; governed
configurable defaults remain gated C1d. Unsupported historical/future costs remain unknown.
Nested uncertainty propagates through the parent. Compatible technical units convert through the
ordinary UoM service before pricing. Current estimates do not persist, reserve or post stock;
quotation adoption/history remains Phase 4/R5. See accepted ADRs 0077/0110.

The UI defaults the output unit from the output item's stock UoM and each line unit from the input
item's stock UoM. Both remain editable because a real manufacturing batch or ingredient may be
expressed in another compatible unit; conversion still fails loudly across different dimensions.

### Nesting

A formulation's input may itself be a `SEMI_FINISHED` item with its own formulation (a base
mixed separately, then tinted). Roll-up recurses.

**Cycle detection is required.** A formulation must not, directly or transitively, consume its
own output. Validate on save, not at roll-up time.

Roll-up uses a **recursive CTE** rather than an N+1 loop in PHP — see
[database.md](../architecture/database.md#recursive-ctes-for-formulation-explosion). Keep the
depth guard regardless; it catches cycles that slipped past validation.

`ExplodeFormulationVersion` implements this phase's roll-up as depth-capped PHP recursion
instead, a deliberate deviation: it also builds the per-level breakdown the wastage-compounding
landmine below requires, which a flat CTE result can't produce in one pass, and at this phase's
usage pattern (a human previewing one formulation on demand, not a hot path across many
assemblies) the bounded extra queries aren't a real concern. Reconsider the CTE if formulation
costing ever runs at bulk/hot-path scale.

## Access

`estimating.formulation.manage` permits creating and editing formulation headers and versions,
deleting drafts, archiving headers, and activating a draft. It is checked in the request and the
underlying Action; a background caller must supply an authorised actor. Listing, showing and
resolving a formulation requires recipe access ([ADR 0086](../decisions/0086-gate-recipe-reads-by-capability.md)):
`estimating.recipe.view`, or a duty that needs recipes — formulation, application-rate or assembly
management, running the Material Preview, or reading internal costs. Without it the API answers 403
(before this, any signed-in user could read every recipe's inputs, quantities and wastage). An
administrator grants the capability through a company role or directly; no role is built in. The
Formulations screen admits those users, read-only unless they also hold the manage capability, and
the demo company has a "Production planner" role holding only the recipe capability.

The existing Formulations list exposes its API's code/name search, output-item,
customer and status filters. They are URL-backed, so a filtered list survives refresh
and shared links; on narrow screens the non-search filters live in a bottom sheet and
the records can be viewed as cards. The output-item and customer filters search
their APIs as the user types and resolve selected IDs for bookmarked URLs, so
choices beyond the first 100 results remain available. Other Estimating form
pickers still need a separate scale pass.

The `cost-breakdown` endpoint is a separate sensitive projection: it requires
`estimating.internal_cost.view`. Managing a recipe does not automatically disclose AVCO-derived
unit costs. The edit screen hides the breakdown control without that capability. The two grants
are additive and dynamically assigned through company roles or direct exceptions, not hardcoded
role names. Existing formulation maintainers need the manage grant before deployment; only the
configured administrator is synchronised automatically.

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

**Confusing a batch amount with its unit rate.** A formulation cost names its actual output quantity;
divide by that same quantity to obtain its unit cost. Do not divide a scaled amount by an unrelated
natural batch quantity. Supported AVCO recipe arithmetic remains covered by the original nested
regressions. FIFO is quantity-dependent: evaluate the actual nested demand in the shared scenario,
never multiply an unrelated one-unit layer estimate into a large job. Public recipe breakdowns use
the recipe’s natural output batch; a contextual Assembly demand names its actual required output.

## Related

- [material-resolution.md](material-resolution.md) — keeps technical composition separate from
  source and pack selection
- [application-rates.md](application-rates.md) — the layer above
- [stock.md](stock.md) — where ingredient cost comes from
- [assemblies.md](assemblies.md) — how formulations enter a sellable price
- [parties.md](parties.md) — `customer_id` references a `customer_accounts` row
- [ADR 0008](../decisions/0008-assembly-abstraction.md)
