# Estimating

Owns Phase 3's costing layer: company-defined rate dimensions and application rates, and
formulations with versioning, customer overrides and cost roll-up. Overhead policies and
assemblies land here in later Phase 3 slices.

**Governed by:** [application-rates.md](../../../../docs/domain/application-rates.md),
[formulations.md](../../../../docs/domain/formulations.md),
[ADR 0009](../../../../docs/decisions/0009-company-defined-rate-dimensions.md) and
[phase-3-estimating-core.md](../../../../docs/phases/phase-3-estimating-core.md).

## Owns

- `RateDimension`/`RateDimensionValue` — company-defined axes an application rate can vary by
  (e.g. Surface Type), and their values
- `ApplicationRate`/`ApplicationRateDimension` — how much of an item is consumed per one unit of
  work, tagged with zero or more dimension values
- The resolution algorithm (`ResolveApplicationRate`): exact match → required-dimensions-only
  match → default → loud failure, never a silent 1:1 assumption
- `Formulation`/`FormulationVersion`/`FormulationLine` — a recipe, its versions and their
  ingredient lines. A version is editable only while DRAFT; `ActivateFormulationVersion` is the
  only path to ACTIVE/SUPERSEDED, this phase's concrete reading of "immutable once referenced by
  any document" ahead of Phase 4 actually having documents
- `ResolveFormulation` (customer override → company default → loud failure) and
  `ExplodeFormulationVersion` (recursive cost roll-up: any SEMI_FINISHED input with its own
  ACTIVE formulation is priced from that formulation's own `cost_per_output_unit`, never from its
  AVCO stock value — Phase 3 never posts, so a nested ingredient's cost is always theoretical)
- `FormulationCycleGuard` — direct or transitive self-consumption, re-checked on create, update
  *and* activation (two formulations built independently while both DRAFT can each look
  cycle-free and only complete a live cycle on the second activation)
- `IngredientCostResolver` — leaf ingredient cost from stock valuation; supports exactly one
  configuration today (AVCO, company-wide `COMPANY_ITEM` pool scope) and fails loudly rather than
  guessing for FIFO/SPECIFIC items or a warehouse/batch-scoped pool

## Does not own

- Items, UoMs or their conversions — Phase 1 core/Inventory own those; this module only
  references them (`App\Support\UomConversionService` handles a formulation line specified in a
  different-but-convertible unit than its input item's stock UoM or a nested formulation's output
  UoM)
- Overhead policies, assemblies, full cost roll-up across all five component kinds — later Phase
  3 slices, same module
- Anything that issues a document or posts stock — Phase 4/5. Physically producing a
  `SEMI_FINISHED` item is Phase 5's `work_orders`, not this module

## Key actions

`CreateRateDimension`/`UpdateRateDimension`/`ArchiveRateDimension` and
`CreateRateDimensionValue`/`UpdateRateDimensionValue`/`DeleteRateDimensionValue` are plain
master-data CRUD. `CreateApplicationRate`/`UpdateApplicationRate` additionally sync the rate's
dimension-value combination and run it through `ApplicationRateCombinationGuard`, which rejects
an exact duplicate combination for the same item+work unit — the resolution algorithm assumes
"exact match" finds at most one candidate. `ResolveApplicationRate` is read-only and reusable:
called directly from the preview endpoint today, and by the assembly cost roll-up once that
slice lands.

`CreateFormulation` creates the header together with its first DRAFT version and lines in one
call. `CreateFormulationVersion` always creates N+1 as DRAFT regardless of the current ACTIVE
version's state; only `UpdateFormulationVersion` (DRAFT only) and `DeleteFormulationVersion`
(DRAFT only) touch a version's content. `ActivateFormulationVersion` is the sole place a
version's status/effective dates change — it supersedes whatever was previously ACTIVE, closing
its period the instant the new one opens.

## Events

No events emitted yet.

## Related

- [Phase 3](../../../../docs/phases/phase-3-estimating-core.md)
- [formulations.md](../../../../docs/domain/formulations.md) ·
  [assemblies.md](../../../../docs/domain/assemblies.md)
- [Invariants 1 and 4](../../../../docs/architecture/invariants.md) — snapshot-on-issue and
  versioned-immutability
- [Inventory module](../Inventory/README.md)
