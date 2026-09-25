[← Domain index](README.md)

# Assemblies

**The central abstraction of the system** ([ADR 0008](../decisions/0008-assembly-abstraction.md)).

An assembly is a sellable unit of work, priced per unit of a work measure, whose cost is built
up from components. Construction estimating calls this a *composite rate*.

> *"Paint exterior wall, 2 coats"* — sold per sqm, costed from material + labour + plant.

## Where it sits

The top of the costing chain. Everything below it is optional.

```
ASSEMBLY ← you are here
  ├─ MATERIAL     → application rate → formulation → stock (AVCO) cost   ┐
  ├─ LABOUR       → hours per work unit × resource_rates rate            │ stored
  ├─ PLANT        → hours per work unit × resource_rates rate            │ component
  ├─ SUBCONTRACT  → a direct rate, always manually entered                ┘ rows
  └─ OVERHEAD     → resolved from an overhead policy at roll-up time — never a
                     stored row, see "Overhead allocation" below (ADR 0049)
```

Built in [Phase 3](../phases/README.md); consumed by [sales-pipeline.md](sales-pipeline.md).

## Schema

```
assembly_groups                            -- see "Overhead allocation" below
  id, company_id
  code, name
  status           ACTIVE | ARCHIVED

  unique(['company_id', 'code'])

assemblies
  id, company_id
  code, name, description
  work_uom_id        → uoms      -- what it is sold by: sqm, metre, hour, each
  assembly_group_id  NULLABLE → assembly_groups   -- optional; see "Overhead allocation"
  margin_type        MARGIN | MARKUP        -- NOT interchangeable, see below
  margin_pct         decimal(9,4)
  status             DRAFT | ACTIVE | ARCHIVED

  unique(['company_id', 'code'])

assembly_versions
  id, assembly_id, version_no
  effective_from, effective_to NULLABLE
  status           DRAFT | ACTIVE | SUPERSEDED
  notes
  -- IMMUTABLE once referenced by any document

assembly_components
  id, assembly_version_id
  kind                 MATERIAL | LABOUR | PLANT | SUBCONTRACT   -- no OVERHEAD, see below
  item_id              NULLABLE → items
  formulation_id       NULLABLE → formulations
  application_rate_id  NULLABLE → application_rates
  qty_per_work_unit    decimal(19,6)
  uom_id               → uoms
  wastage_pct          decimal(9,4)
  multiplier           decimal(9,4)   -- e.g. number of coats
  rate_override        NULLABLE decimal(19,4)
  sequence

overhead_policies                          -- see "Overhead allocation" below
  id, company_id
  scope_type        COMPANY | ASSEMBLY_GROUP | ASSEMBLY
  scope_id          NULLABLE → assembly_groups | assemblies  -- NULL iff scope_type = COMPANY
  driver_type       PCT_MATERIAL | PCT_LABOUR | PCT_DIRECT_COST | PER_LABOUR_HOUR | PER_WORK_UNIT
  rate              decimal(19,6)
  status            DRAFT | ACTIVE | SUPERSEDED
  effective_from, effective_to  NULLABLE
  reason            -- mandatory on change, audited like Phase 2's policy changes
  -- IMMUTABLE once referenced by a resolved cost roll-up that has been snapshotted into an
  -- issued document

  unique(['company_id', 'scope_type', 'scope_id']) WHERE status = 'ACTIVE'

resource_rates                             -- see items.md "Resource rates"; the LABOUR/PLANT
  id, company_id                           -- costing source, since items itself has no rate
  item_id           → items                -- column and non-stocked items have no AVCO pool
  rate              decimal(19,6)
  rate_uom_id       → uoms
  effective_from    date
  -- no update endpoint — a correction is a new dated row

  unique(['company_id', 'item_id', 'effective_from'])
```

The `kind` discriminator on `assembly_components` is four of the standard five-part estimating
breakdown, and is what makes variance reporting meaningful
([job-costing.md](job-costing.md)). `OVERHEAD` is the fifth — it's a real `kind` value on
`job_cost_entries` for *actuals* (Phase 5), but on the *estimate* side it is never a stored
`assembly_components` row; see "Overhead allocation" below for why and where its number actually
comes from. Variance reporting compares the two sides by dollar amount per `kind`, which works
identically regardless of which side computed its figure versus stored it.

## Behaviour

### Cost roll-up

```
material/unit    = Σ (qty_per_work_unit × (1 + wastage) × multiplier × item_cost)
labour/unit      = Σ (qty_per_work_unit × resource_rates rate)      -- wastage/multiplier
plant/unit       = Σ (qty_per_work_unit × resource_rates rate)      -- never apply here
subcontract/unit = Σ (rate_override)                                -- its only value
overhead/unit    = resolved overhead policy's driver applied to the four direct costs above —
                    see "Overhead allocation"; never summed from assembly_components rows

cost/unit  = material + labour + plant + subcontract + overhead
sell/unit  = margin_type = MARGIN
               ? cost / (1 - margin_pct)
               : cost × (1 + margin_pct)
line_total = sell/unit × qty
```

`item_cost` for a MATERIAL component resolves, in order: `rate_override` if set → the referenced
`formulation_id`'s own ACTIVE version's `cost_per_output_unit` if set (an explicit, deliberate
choice per component — not an automatic lookup the way a nested formulation *line* resolves one
implicitly, since an assembly component has no implicit customer context to resolve against) →
`IngredientCostResolver`'s AVCO cost otherwise. Where a component has an `application_rate_id`,
`qty_per_work_unit` and the item are read live from that specific rate row instead of the
component's own stored values — a live pointer, re-read on every roll-up, the same
never-snapshotted-within-Phase-3 shape a nested formulation's own price already has.

**The four direct costs are always computed before overhead, from components alone, and overhead
is never folded back into them** — the same number can never be used as its own basis. Overhead's
driver bases, spelled out explicitly since nothing else does:

| Driver | Basis |
|---|---|
| `PCT_MATERIAL` / `PCT_LABOUR` | That one direct cost alone |
| `PCT_DIRECT_COST` | `material + labour + plant + subcontract` — all four stored kinds; subcontract is a direct cost of producing this unit of work, same as the other three, not overhead |
| `PER_LABOUR_HOUR` | Every LABOUR component's `qty_per_work_unit`, summed and converted to the company's base TIME-dimension UoM (by convention, hours) |
| `PER_WORK_UNIT` | Exactly `1` — every figure here is already expressed "per unit" (this whole formula is `/unit`), so costing one work unit means the basis is `1`, not some document's line quantity, which doesn't exist in Phase 3 |

A LABOUR/PLANT component's `qty_per_work_unit` UoM must be dimensionally convertible to its
resolved `resource_rates` row's `rate_uom_id` (e.g. both TIME) before costing — an incompatible
pair (say, a component quantified in `kg`) is a loud, rejected error, never a silent
same-number-different-unit multiplication.

**The breakdown is explainable, not just a total.** The cost-breakdown read returns which version
was costed, each component's resolved quantity/unit-cost and where that unit cost came from (AVCO,
a named formulation version, a resource rate as of a date, or a manual override), which overhead
scope tier and driver resolved and the resulting amount, and the final cost/sell per unit —
everything a later quotation, job-costing or audit screen needs without recomputing anything.

### Versioning

Frozen once used, like formulations —
[invariant 4](../architecture/invariants.md#4-versioned-records-are-immutable-once-used).

### Optional layers

| Business | Uses |
|---|---|
| Paint contractor | All layers — application rate → formulation → stock |
| Retailer | One MATERIAL component pointing at a stocked item |
| Consultancy | LABOUR components only |
| Installer | MATERIAL (bought-in) + LABOUR + PLANT |

## Landmines

### Margin is not markup

```
cost 100, 20%    MARKUP → 100 × 1.20        = 120
                 MARGIN → 100 ÷ (1 − 0.20)  = 125
```

Companies conflate these constantly and it is a **real money bug** — a 5% error on every quote.
`margin_type` is stored explicitly and must be visible in the UI. Never infer it.

### Consumption basis is not pricing basis

250 sqm of wall at 2 coats **consumes 500 sqm** worth of material but is **priced per sqm of
wall**.

- `multiplier` on the component handles consumption
- Document line quantity handles pricing

Conflating them makes every estimate wrong by the coat count — and it looks plausible, so it is
not caught by inspection.

### Snapshot on issue

When a quotation is issued, the **fully resolved breakdown** — every component, quantity, unit
cost, labour rate and which application rate was selected — is copied into the document.

Not a pointer to the assembly version. The version is immutable, but the *costs it references*
(stock AVCO, labour rates) are not.

> *Failure mode:* a pigment price is updated. Every historical quote silently changes value.
> Nobody notices for months.

[Invariant 1](../architecture/invariants.md#1-snapshot-on-issue).

### Overhead allocation

Configurable and hierarchical, not a single fixed basis — different manufacturing businesses
drive overhead differently, and a general-purpose ERP can't hardcode one
([ADR 0049](../decisions/0049-assembly-group-overhead-scope.md), superseding
[ADR 0048](../decisions/0048-configurable-hierarchical-overhead-allocation.md)). Resolution walks
a fixed scope order and stops at the first match — exactly one policy wins, never a sum of
several:

```
Assembly's own overhead_policies row
  ↓ (none?)
Its assembly_group's overhead_policies row, if the assembly has one — a flat, optional,
  company-defined grouping (assembly_groups), NOT the Inventory item-category tree; an assembly
  has no output item to walk a category from (ADR 0008)
  ↓ (none?)
Company default policy
  ↓ (none?)
No overhead applied — not an error, overhead/unit = 0
```

`overhead_policies` versions and freezes the same way formulation/assembly versions do — a
resolved policy is immutable once a cost roll-up that used it has been snapshotted into an
issued document (see "Snapshot on issue" below); changing the rule creates a new version rather
than rewriting the old one, and each change carries a mandatory reason, audited the same way
Phase 2's `PurchasingPolicyChange`/`InventoryPolicyChange` are.

`assembly_groups` is deliberately flat (no `parent_id`) — a reusable classification for shared
costing configuration ("Machining," "Welding," "Installation" are company-entered examples, never
hardcoded), not a hierarchy. An assembly needing exceptional overhead treatment gets its own
`ASSEMBLY`-scoped policy row directly; there is no manually-entered overhead component as an
alternative path — see the schema note above for why that would reintroduce the exact ambiguity
this design exists to avoid.

A small company can ignore all of this and configure a single `COMPANY`-scope policy; the group
and assembly-level tiers only exist for companies that create rows there. Which UI exposes the
group/assembly-level tiers is a separate, later decision — see
[extensibility.md](../architecture/extensibility.md#progressive-capability-modes).

An unexamined overhead rule quietly makes every job look profitable — this configurability
doesn't remove the need to actually pick a sensible one per assembly/group, it just stops the
whole company being forced onto one.

## Related

- [material-resolution.md](material-resolution.md) — the pending preview boundary from standard
  requirement through plan, price and actual
- [application-rates.md](application-rates.md) · [formulations.md](formulations.md)
- [items.md](items.md#resource-rates) — `resource_rates`, the LABOUR/PLANT costing source
- [sales-pipeline.md](sales-pipeline.md) — where assemblies become quotation lines
- [job-costing.md](job-costing.md) — estimate vs actual
- [ADR 0008](../decisions/0008-assembly-abstraction.md) — why assemblies have no output item
- [ADR 0049](../decisions/0049-assembly-group-overhead-scope.md) — overhead allocation policy
  and Assembly Group *(supersedes [ADR 0048](../decisions/0048-configurable-hierarchical-overhead-allocation.md))*
- [extensibility.md](../architecture/extensibility.md#progressive-capability-modes) — simple vs
  advanced overhead UI, same backend either way
