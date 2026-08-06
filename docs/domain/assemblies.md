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
  ├─ MATERIAL     → application rate → formulation → stock cost
  ├─ LABOUR       → hours per work unit × labour item rate
  ├─ PLANT        → equipment hours
  ├─ SUBCONTRACT  → lump or per unit
  └─ OVERHEAD     → allocation
```

Built in [Phase 3](../phases/README.md); consumed by [sales-pipeline.md](sales-pipeline.md).

## Schema

```
assemblies
  id, company_id
  code, name, description
  work_uom_id      → uoms      -- what it is sold by: sqm, metre, hour, each
  margin_type      MARGIN | MARKUP        -- NOT interchangeable, see below
  margin_pct       decimal(9,4)
  status           DRAFT | ACTIVE | ARCHIVED

  unique(['company_id', 'code'])

assembly_versions
  id, assembly_id, version_no
  effective_from, effective_to NULLABLE
  status           DRAFT | ACTIVE | SUPERSEDED
  -- IMMUTABLE once referenced by any document

assembly_components
  id, assembly_version_id
  kind                 MATERIAL | LABOUR | PLANT | SUBCONTRACT | OVERHEAD
  item_id              NULLABLE → items
  formulation_id       NULLABLE → formulations
  application_rate_id  NULLABLE → application_rates
  qty_per_work_unit    decimal(19,6)
  uom_id               → uoms
  wastage_pct          decimal(9,4)
  multiplier           decimal(9,4)   -- e.g. number of coats
  rate_override        NULLABLE decimal(19,4)
  sequence
```

The `kind` discriminator is the standard estimating breakdown, and is what makes variance
reporting meaningful ([job-costing.md](job-costing.md)).

## Behaviour

### Cost roll-up

```
material/unit    = Σ (qty_per_work_unit × (1 + wastage) × multiplier × item_cost)
labour/unit      = Σ (hours_per_work_unit × labour_item_rate)
plant/unit       = Σ (hours_per_work_unit × plant_rate)
subcontract/unit = Σ (rate)
overhead/unit    = allocation rule

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

The least well-defined component kind. Decide the rule explicitly — percentage of direct cost,
per labour hour, or per work unit — and record it. An unexamined overhead rule quietly makes
every job look profitable.

## Related

- [application-rates.md](application-rates.md) · [formulations.md](formulations.md)
- [sales-pipeline.md](sales-pipeline.md) — where assemblies become quotation lines
- [job-costing.md](job-costing.md) — estimate vs actual
- [ADR 0008](../decisions/0008-assembly-abstraction.md)
