# Estimating

Owns Phase 3's costing layer: company-defined rate dimensions and application rates,
formulations with versioning, customer overrides and cost roll-up, assemblies (a sellable unit of
work) and the overhead-policy resolver. Assembly itself is still Step 1 identity only —
code/name/work UoM/margin and an optional `AssemblyGroup` classification; its own
versions/components (the actual cost roll-up) land in Step 3.

**Governed by:** [application-rates.md](../../../../docs/domain/application-rates.md),
[formulations.md](../../../../docs/domain/formulations.md),
[assemblies.md](../../../../docs/domain/assemblies.md),
[ADR 0009](../../../../docs/decisions/0009-company-defined-rate-dimensions.md),
[ADR 0049](../../../../docs/decisions/0049-assembly-group-overhead-scope.md) and
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
- `FormulationUnitGuard` — output and ingredient units may differ from their items' stock units,
  but must remain in the same convertible dimension. The UI defaults from the item; this guard
  enforces the rule for every API caller
- `IngredientCostResolver` — leaf ingredient cost from stock valuation; supports exactly one
  configuration today (AVCO, company-wide `COMPANY_ITEM` pool scope) and fails loudly rather than
  guessing for FIFO/SPECIFIC items or a warehouse/batch-scoped pool
- `Assembly`/`AssemblyGroup` — Step 1 identity only. Assembly has no output item (ADR 0008) and no
  `assembly_versions`/`assembly_components` yet; every assembly stays DRAFT through this slice.
  `AssemblyGroup` is flat, optional, company-defined classification (not the Inventory category
  tree) that gives the overhead-policy resolver below a real scope target
  (`Assembly → AssemblyGroup → Company → none`, ADR 0049)
- `OverheadPolicy` — a versioned rule (driver + rate) at one of the three scope tiers above, same
  DRAFT/ACTIVE/SUPERSEDED shape as `FormulationVersion`. `ResolveOverheadPolicy` walks the
  hierarchy and returns which tier won (or `NONE` — not an error, overhead/unit = 0);
  `OverheadAmountCalculator` is the one place a driver's `rate` is turned into a dollar amount,
  a pure function so it's fully testable ahead of Step 3 having real
  material/labour/plant/subcontract totals to feed it. `scope_id` carries no database-level FK —
  it targets `assembly_groups` or `assemblies` depending on `scope_type`, or is `NULL` for
  `COMPANY` — validated at the request layer instead

## Does not own

- Items, UoMs or their conversions — Phase 1 core/Inventory own those; this module only
  references them (`App\Support\UomConversionService` handles a formulation line specified in a
  different-but-convertible unit than its input item's stock UoM or a nested formulation's output
  UoM)
- The remaining Assembly slice (`assembly_versions`/`assembly_components`, full cost roll-up
  across all five component kinds, actually applying a resolved overhead policy to real
  component totals) — Step 3 of this same phase, same module
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

`CreateAssemblyGroup`/`UpdateAssemblyGroup`/`ArchiveAssemblyGroup` and
`CreateAssembly`/`UpdateAssembly`/`ArchiveAssembly` are plain CRUD, same shape as the rate
dimension actions. `assembly_group_id` is deliberately mutable — no repoint guard, unlike
`Formulation`'s `output_item_id`/`customer_id` — since it is a classification, not identity.
`ArchiveAssemblyGroup` hard-deletes (blocked by FK while any assembly still refers to it, same as
`RateDimension`); `ArchiveAssembly` sets `status = ARCHIVED` (same as `ArchiveFormulation`) since
Assembly already carries a status column ahead of Step 3's activation mechanism needing it.

`CreateOverheadPolicy` always creates a DRAFT — every row is a governed change (mandatory
`reason` + `changed_by`), there is no "current settings" row this is a delta from, unlike
`InventoryPolicyChange`/`PurchasingPolicyChange`'s singleton-plus-log shape. `UpdateOverheadPolicy`
and `DeleteOverheadPolicy` only act on DRAFT rows. `ActivateOverheadPolicy` is the sole place a
policy's status/effective dates change — it supersedes whatever was previously ACTIVE *for the
same scope* within the same transaction, same pattern as `ActivateFormulationVersion`. Reading a
policy (`index`/`show`) requires `estimating.policy-history.view`; writing one requires
`estimating.overhead-policy.manage` — deliberately separate permissions, same split
`InventoryPolicyChange` uses. `ResolveOverheadPolicy`'s read is unrestricted (ordinary auth only,
same as `ResolveApplicationRate`/`ResolveFormulation`) since every estimator needs "what applies
right now," not just whoever can manage or audit policies.

## Events

No events emitted yet.

## Related

- [Phase 3](../../../../docs/phases/phase-3-estimating-core.md)
- [formulations.md](../../../../docs/domain/formulations.md) ·
  [assemblies.md](../../../../docs/domain/assemblies.md)
- [Invariants 1 and 4](../../../../docs/architecture/invariants.md) — snapshot-on-issue and
  versioned-immutability
- [Inventory module](../Inventory/README.md)
