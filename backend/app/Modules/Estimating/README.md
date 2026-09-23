# Estimating

Owns Phase 3's costing layer: company-defined rate dimensions and the application rates resolved
against them. Formulations, overhead policies and assemblies land here in later Phase 3 slices.

**Governed by:** [application-rates.md](../../../../docs/domain/application-rates.md),
[ADR 0009](../../../../docs/decisions/0009-company-defined-rate-dimensions.md) and
[phase-3-estimating-core.md](../../../../docs/phases/phase-3-estimating-core.md).

## Owns

- `RateDimension`/`RateDimensionValue` — company-defined axes an application rate can vary by
  (e.g. Surface Type), and their values
- `ApplicationRate`/`ApplicationRateDimension` — how much of an item is consumed per one unit of
  work, tagged with zero or more dimension values
- The resolution algorithm (`ResolveApplicationRate`): exact match → required-dimensions-only
  match → default → loud failure, never a silent 1:1 assumption

## Does not own

- Items, UoMs or their conversions — Phase 1 core/Inventory own those; this module only
  references them
- Formulations, assemblies, overhead policies, cost roll-up — later Phase 3 slices, same module
- Anything that issues a document or posts stock — Phase 4/5

## Key actions

`CreateRateDimension`/`UpdateRateDimension`/`ArchiveRateDimension` and
`CreateRateDimensionValue`/`UpdateRateDimensionValue`/`DeleteRateDimensionValue` are plain
master-data CRUD. `CreateApplicationRate`/`UpdateApplicationRate` additionally sync the rate's
dimension-value combination and run it through `ApplicationRateCombinationGuard`, which rejects
an exact duplicate combination for the same item+work unit — the resolution algorithm assumes
"exact match" finds at most one candidate. `ResolveApplicationRate` is read-only and reusable:
called directly from the preview endpoint today, and by the assembly cost roll-up once that
slice lands.

## Events

No events emitted yet.

## Related

- [Phase 3](../../../../docs/phases/phase-3-estimating-core.md)
- [formulations.md](../../../../docs/domain/formulations.md) ·
  [assemblies.md](../../../../docs/domain/assemblies.md)
- [Inventory module](../Inventory/README.md)
