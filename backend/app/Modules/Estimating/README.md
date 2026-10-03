# Estimating

Owns Phase 3's costing layer: company-defined rate dimensions and application rates,
formulations with versioning, customer overrides and cost roll-up, the overhead-policy resolver,
and assemblies (a sellable unit of work) with their own versioned components and full five-part
cost roll-up. It also owns ADR 0058's read-only Material Resolution preview contract as the next
implementation slice.

**Governed by:** [application-rates.md](../../../../docs/domain/application-rates.md),
[formulations.md](../../../../docs/domain/formulations.md),
[assemblies.md](../../../../docs/domain/assemblies.md),
[items.md](../../../../docs/domain/items.md#resource-rates),
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
- `Assembly`/`AssemblyGroup` — Assembly has no output item (ADR 0008). `AssemblyGroup` is flat,
  optional, company-defined classification (not the Inventory category tree) that gives the
  overhead-policy resolver below a real scope target (`Assembly → AssemblyGroup → Company →
  none`, ADR 0049)
- `OverheadPolicy` — a versioned rule (driver + rate) at one of the three scope tiers above, same
  DRAFT/ACTIVE/SUPERSEDED shape as `FormulationVersion`. `ResolveOverheadPolicy` walks the
  hierarchy and returns which tier won (or `NONE` — not an error, overhead/unit = 0);
  `OverheadAmountCalculator` is the one place a driver's `rate` is turned into a dollar amount, a
  pure function. `scope_id` carries no database-level FK — it targets `assembly_groups` or
  `assemblies` depending on `scope_type`, or is `NULL` for `COMPANY` — validated at the request
  layer instead
- `ResourceRate` — the LABOUR/PLANT estimating-rate source (`items` itself has no rate column,
  and non-stocked items have no AVCO pool); an effective-dated table shaped like `exchange_rates`,
  not the heavier versioned-policy lifecycle — see [items.md#resource-rates](../../../../docs/domain/items.md#resource-rates).
  `ResolveResourceRate` returns the most recent row with `effective_from` on or before the target
  date, or `null` — a legitimate "not configured yet" state, not an error
- `AssemblyVersion`/`AssemblyComponent` — the assembly's own recipe. Same DRAFT/ACTIVE/SUPERSEDED
  + exclusion-constraint shape as `FormulationVersion`/`FormulationLine`. Four component kinds
  (`MATERIAL`/`LABOUR`/`PLANT`/`SUBCONTRACT` — no stored `OVERHEAD` row, ADR 0049);
  `ExplodeAssemblyVersion` computes the four direct costs from components alone, then resolves
  and computes overhead from those four figures — never the reverse, so overhead can never end up
  inside its own base. Returns a fully explainable breakdown: each component's resolved
  quantity/unit-cost/source, which overhead tier/driver resolved, and cost/sell per unit

## Does not own

- Items, UoMs or their conversions — Phase 1 core/Inventory own those; this module only
  references them (`App\Support\UomConversionService` handles a formulation line specified in a
  different-but-convertible unit than its input item's stock UoM or a nested formulation's output
  UoM, and the same check between an `AssemblyComponent`'s own UoM and its resolved
  `ResourceRate`'s `rate_uom_id`)
- Anything that issues a document or posts stock — Phase 4/5. Physically producing a
  `SEMI_FINISHED` item is Phase 5's `work_orders`, not this module
- The remainder of the ad-hoc **material preview** (ADR 0058): planned-quantity adjustments, ADR
  0054 variance evaluation, and the completion slice (stale-result handling, product journey).
  The technical resolution, internal-cost gating and the `QUANTITY_ONLY` inventory proposal
  already ship; see
  [phase-3-estimating-core.md](../../../../docs/phases/phase-3-estimating-core.md)

## Key actions

`CreateRateDimension`/`UpdateRateDimension`/`ArchiveRateDimension` and
`CreateRateDimensionValue`/`UpdateRateDimensionValue`/`DeleteRateDimensionValue` are plain
master-data CRUD. Each write requires `estimating.rate-dimension.manage` at both HTTP and Action
boundaries; list/show/value reads stay authenticated-only because other estimating forms use them
as lookups. `CreateApplicationRate`/`UpdateApplicationRate` additionally sync the rate's
dimension-value combination and run it through `ApplicationRateCombinationGuard`, which rejects
an exact duplicate combination for the same item+work unit — the resolution algorithm assumes
"exact match" finds at most one candidate. `ResolveApplicationRate` is read-only and reusable:
called directly from the preview endpoint today, and by the assembly cost roll-up once that
slice lands.

Application-rate create/update/delete require `estimating.application-rate.manage` at both
HTTP and Action boundaries. List/show/resolve remain authenticated company reads, including
the resolution tester for preview users. The SPA hides New/edit routes from users without the
write capability, while a preview user can inspect rates and test resolution.

`PreviewAssemblyMaterials` is the read-only Material Preview (`POST
/api/v1/assemblies/{assembly}/material-preview`, ADR 0058). For a caller holding
`estimating.inventory_availability.view` (stock context inside a plan — it grants no Inventory screen and the Inventory stock-read capabilities do not expose this preview, ADR 0083) it also runs `PreviewInventoryAvailability`: per leaf
material it reads usable stock through Inventory's `QueryStockAvailability`, and
`AllocateInventory` — a pure function — turns requirement plus balances into an ADR 0053 draw plan
under the effective strategy (built-in company default `MINIMISE_WASTE`, or a per-request what-if
override), with the other two presets as alternatives. Only `QUANTITY_ONLY` tracking exists, so
open-remnant, expiry and package-surplus criteria are reported as not applied rather than faked.
Neither action writes or reserves anything; `MaterialPreviewApiTest` and
`QueryStockAvailabilityTest` each assert that no stock or valuation row changes. Details and the deliberate deferrals are in
[inventory-packages.md](../../../../docs/domain/inventory-packages.md#first-implementation-quantity_only-availability-and-allocation).

`CreateFormulation` creates the header together with its first DRAFT version and lines in one
call. `CreateFormulationVersion` always creates N+1 as DRAFT regardless of the current ACTIVE
version's state; only `UpdateFormulationVersion` (DRAFT only) and `DeleteFormulationVersion`
(DRAFT only) touch a version's content. `ActivateFormulationVersion` is the sole place a
version's status/effective dates change — it supersedes whatever was previously ACTIVE, closing
its period the instant the new one opens.

Formulation header and version create/update/delete/activate require
`estimating.formulation.manage` at both HTTP and Action boundaries. List/show/resolve remain
authenticated company reads for shared lookups. The version cost-breakdown response is separate:
it requires `estimating.internal_cost.view` even for someone who can maintain formulations; the
SPA hides its control without that capability. The demo seeder activates versions as the
permission-synchronised demo actor rather than bypassing the Action check.
Formulation list search/status/output-item/customer filters and assembly list
search/status/group filters are already supported by their Index requests; their
React lists now expose all seven as URL-backed controls with mobile filter sheets
and card views. This changes no read or write authority.
The Formulations output-item and customer filters search their respective APIs
as the user types and resolve selected IDs for bookmarked URLs, rather than
relying on the first 100 lookup rows.
The Assemblies list group filter searches the Assembly Groups API as the user types
and resolves a selected ID for bookmarked URLs, rather than relying on the first 100 groups.
Rate Dimension and Assembly Group lists likewise expose their existing code/name
search as URL-backed controls and offer mobile cards. The Assembly Group New/edit
affordances follow its manage capability; server-side enforcement remains authoritative.
The Application Rates list exposes its existing item, work-unit and default-status
filters as URL-backed controls, with a mobile filter sheet and cards. Boolean
`false` is preserved to select context-specific rates; no API contract changes.
Its Item and work-unit filters now query the existing Items and UoMs search
endpoints as the user types and resolve selected IDs separately, rather than
relying on the first 100 results.
The read-only Application Rate resolution tester now uses the same server-search
lookups for Item and Work unit; rate form pickers remain a separate scale pass.

`CreateAssemblyGroup`/`UpdateAssemblyGroup`/`ArchiveAssemblyGroup` and
`CreateAssembly`/`UpdateAssembly`/`ArchiveAssembly` are plain CRUD, same shape as the rate
dimension actions. `assembly_group_id` is deliberately mutable — no repoint guard, unlike
`Formulation`'s `output_item_id`/`customer_id` — since it is a classification, not identity.
`ArchiveAssemblyGroup` hard-deletes (blocked by FK while any assembly still refers to it, same as
`RateDimension`); `ArchiveAssembly` sets `status = ARCHIVED` (same as `ArchiveFormulation`) since
Assembly already carries a status column ahead of Step 3's activation mechanism needing it.

Assembly-group create/update/delete require `estimating.assembly-group.manage` at HTTP and
Action boundaries. Index/show remain authenticated shared lookups for assembly and overhead
forms. The SPA exposes its setup screen to managers only; grants are company-role or direct-user
assignments, not hardcoded to an administrator or starter role.

Assembly header and version/component create, update, archive/delete and activation require
`estimating.assembly.manage` at HTTP and Action boundaries. List/show remain authenticated company
reads for shared estimating lookups. The explainable version cost-breakdown endpoint separately
requires `estimating.internal_cost.view`, even for an assembly manager; the SPA hides its control
without that capability. Demo activation uses the permission-synchronised demo actor.
Shared assembly list/show responses omit margin settings and nested component rate overrides
unless the caller can manage assemblies or view internal costs. This gives editors the values
needed for correction without disclosing commercial configuration to unrelated readers.

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
right now," not just whoever can manage or audit policies. Its monetary policy rate and computed
overhead amount are projected only to policy managers, policy-history viewers or internal-cost
viewers; an ordinary resolver caller still sees the winning tier without a cost side channel.

`CreateResourceRate` inserts a new dated row; there is deliberately no `UpdateResourceRate` — a
rate correction is always a new row with a later `effective_from`, never an edit to an
already-used historical one. Create and delete require `estimating.resource-rate.manage` at
HTTP and Action boundaries. Delete has no "in use" guard yet, but is not unrestricted by actor.
Index/show remain authenticated company reads for lookups; the `rate` amount is omitted unless
the caller can manage rates or has `estimating.internal_cost.view`. The SPA admits either
capability to the list but shows the add/delete controls only to managers.

`CreateAssemblyVersion`/`UpdateAssemblyVersion`/`DeleteAssemblyVersion`/`ActivateAssemblyVersion`
mirror the `FormulationVersion` Action family exactly, including managing `components` as an
array payload on the version rather than separate per-component endpoints. No cycle guard is
needed here (unlike `FormulationCycleGuard`) — assembly components never reference other
assemblies, only items/formulations/application rates, and formulation-level cycles are already
guarded entirely within `Formulation`'s own domain. `ExplodeAssemblyVersion` is read-only and
reusable, called directly by the cost-breakdown endpoint.

## Permissions

Estimating contributes its own vocabulary to `PermissionRegistry`; the registry no longer owns a
central cross-module list. Existing overhead permissions remain unchanged. ADR 0057 adds:

```text
estimating.material_preview.run
estimating.inventory_availability.view
estimating.internal_cost.view
estimating.material_plan.adjust
estimating.package_plan.adopt
estimating.material_variance.request
estimating.material_variance.approve
estimating.material_resolution_history.view
```

The existing Estimating CRUD retrofit also reserves six write capabilities:
`estimating.rate-dimension.manage` (including values), `estimating.application-rate.manage`,
`estimating.formulation.manage` (including versions), `estimating.assembly-group.manage`,
`estimating.assembly.manage` (including versions), and `estimating.resource-rate.manage`.
`estimating.rate-dimension.manage`, `estimating.application-rate.manage`,
`estimating.formulation.manage`, `estimating.assembly-group.manage`, and
`estimating.assembly.manage` are now enforced for their writes, with matching screen access and
denied-path tests. All six write capabilities are enforced. Existing operational maintainers
still need explicit company-role or direct assignments before deployment; registry and
administrator sync do not infer their roles. See the
[authorisation retrofit](../../../../docs/architecture/authorization-retrofit.md#c-existing-estimating-crud).

`estimating.material_variance_policy.manage` (not in ADR 0057's list — added with the configurable variance
policy) creates, edits, activates and deletes-while-draft variance policies and the company's reason
list. Listing policies (audit detail: reason, actor, superseded versions) also needs
`estimating.policy-history.view`, exactly like overhead policies; reading the reason list is open to
anyone who can run a preview, since a previewer must be able to pick one. Reasons are retired, never
deleted, and a reason's code is immutable. `APPROVAL_REQUIRED` is refused everywhere until the shared
approval workflow exists (ADR 0054 §7).

The first three of ADR 0057's capabilities are enforced by the Material Preview and its field-filtered response, each
independently: `run` alone returns the technical requirement; `inventory_availability.view` adds the
`inventory` block and is also required to send `inventory_scope` or `selection_strategy` (without
it those inputs are rejected with 422, never silently ignored, and no availability is queried);
`internal_cost.view` adds every cost field — for a caller without it those fields are `null`, never
zero, although cost still ranks balances internally. Scope narrowing can only reduce the
company-wide scope; department-limited availability waits on Q26. The remaining five deliberately
reserve stable Phase 4 transaction boundaries for draft adjustment/adoption, approval and history;
Phase 3 does not invent a temporary persisted draft to exercise them.

## Events

No events emitted yet.

## Related

- [Phase 3](../../../../docs/phases/phase-3-estimating-core.md)
- [formulations.md](../../../../docs/domain/formulations.md) ·
  [assemblies.md](../../../../docs/domain/assemblies.md) ·
  [items.md](../../../../docs/domain/items.md#resource-rates)
- [Invariants 1 and 4](../../../../docs/architecture/invariants.md) — snapshot-on-issue and
  versioned-immutability
- [Inventory module](../Inventory/README.md)
