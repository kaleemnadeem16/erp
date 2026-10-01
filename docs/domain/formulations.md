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
cost_per_output_unit = Σ (line.qty × (1 + line.wastage_pct) × input_item.avco_cost)
                       ÷ formulation.output_qty
```

Ingredient cost comes from [stock valuation](stock.md), not from a price list — it reflects what
was actually paid. v1 supports exactly one configuration for a leaf ingredient: AVCO valuation
with a company-wide (`COMPANY_ITEM`) pool, the only case with one unambiguous current cost. A
FIFO/SPECIFIC item, or an AVCO item whose pool is scoped by warehouse or batch (see
[items.md](items.md#valuation-method)'s multi-pool scopes), fails loudly rather than guessing
which layer or which pool a pure recipe —
not tied to any warehouse or batch — should read; a never-purchased item returns a real `0`
instead, since "no cost known yet" is a legitimate state, not an error. This is the *current* behaviour only: [ADR 0077](../decisions/0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md)
designs the generic replacement (strategies per valuation method, a configurable costing scope, an evidence-carrying result that
Phase 4 snapshots, and `unknown` instead of `0`), adopted when Phase 4 plan adoption is built. A line specified in a
different-but-convertible unit than its input item's stock UoM converts through the ordinary UoM
system (`units-of-measure.md`) before pricing.

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
resolving technical recipes remain authenticated company reads because they serve other
Estimating lookups. The Formulations setup screen requires the manage capability and does not
offer a read-only detail mode yet.

The existing Formulations list exposes its API's code/name search, output-item,
customer and status filters. They are URL-backed, so a filtered list survives refresh
and shared links; on narrow screens the non-search filters live in a bottom sheet and
the records can be viewed as cards. Item/customer pickers currently use the shared
100-option lookup pattern, so a server-searchable picker is a separate scale follow-up.

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

**Scaling a nested formulation's rate by how much the parent needs.** A formulation's own
`cost_per_output_unit` must be computed from its own recipe alone, at its own natural scale
(`quantity_multiplier` of 1) — never pre-scaled by the quantity a parent formulation happens to
need. A nested formulation is just another priced ingredient: the parent multiplies the quantity
it needs by the child's (unscaled) `cost_per_output_unit`, exactly as it would multiply by a leaf
ingredient's AVCO unit cost. Threading a running "fraction of the top-level batch" multiplier
through the recursion and dividing by each level's own *unscaled* `output_qty` looks plausible
but silently produces the wrong number — the two scalings don't cancel out. This was an actual
bug in `ExplodeFormulationVersion`'s first implementation, caught by a two-level nesting test
case, not by inspection.

## Related

- [material-resolution.md](material-resolution.md) — keeps technical composition separate from
  source and pack selection
- [application-rates.md](application-rates.md) — the layer above
- [stock.md](stock.md) — where ingredient cost comes from
- [assemblies.md](assemblies.md) — how formulations enter a sellable price
- [parties.md](parties.md) — `customer_id` references a `customer_accounts` row
- [ADR 0008](../decisions/0008-assembly-abstraction.md)
