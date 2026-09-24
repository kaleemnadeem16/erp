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
  ├─ MATERIAL     → application rate → formulation → stock cost      ┐
  ├─ LABOUR       → hours per work unit × labour item rate           │ stored
  ├─ PLANT        → equipment hours                                  │ component
  ├─ SUBCONTRACT  → lump or per unit                                 ┘ rows
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
labour/unit      = Σ (hours_per_work_unit × labour_item_rate)
plant/unit       = Σ (hours_per_work_unit × plant_rate)
subcontract/unit = Σ (rate)
overhead/unit    = resolved overhead policy's rate applied to this roll-up — see
                    "Overhead allocation"; not summed from assembly_components rows

cost/unit  = material + labour + plant + subcontract + overhead
sell/unit  = margin_type = MARGIN
               ? cost / (1 - margin_pct)
               : cost × (1 + margin_pct)
line_total = sell/unit × qty
```

Where a component has an `application_rate_id`, `qty_per_work_unit` is resolved from that rate
using the document's dimension values rather than stored on the component.

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

- [application-rates.md](application-rates.md) · [formulations.md](formulations.md)
- [sales-pipeline.md](sales-pipeline.md) — where assemblies become quotation lines
- [job-costing.md](job-costing.md) — estimate vs actual
- [ADR 0008](../decisions/0008-assembly-abstraction.md) — why assemblies have no output item
- [ADR 0049](../decisions/0049-assembly-group-overhead-scope.md) — overhead allocation policy
  and Assembly Group *(supersedes [ADR 0048](../decisions/0048-configurable-hierarchical-overhead-allocation.md))*
- [extensibility.md](../architecture/extensibility.md#progressive-capability-modes) — simple vs
  advanced overhead UI, same backend either way
