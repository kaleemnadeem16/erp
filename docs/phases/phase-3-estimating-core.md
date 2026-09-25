[← Roadmap](README.md)

# Phase 3 — Estimating core

- **Status:** in progress
- **Depends on:** Phase 1 (items, categories, parties) · Phase 2 (stock valuation — AVCO cost is
  the ingredient-cost input to every formulation roll-up)
- **Blocked by:** nothing. [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md) has no blocking questions.
  Q8 (second customer profile) is non-blocking and does not gate this phase — see "Sequencing
  notes" below.

## Goal

Let a company price a unit of work from its real ingredient and labour costs, without hardcoding
any one industry's cost structure. After this phase, a company can define what it takes to make
one unit of an output item (a formulation), how much of an item a unit of work consumes in a
given context (an application rate), and roll all of that — plus labour, plant, subcontract and a
configurable overhead policy — into a priced, marginable assembly. This is
[the differentiator](README.md#phase-3--estimating-core): everything before it is table stakes,
everything after it (quotations, jobs, invoicing) consumes what this phase produces.

This phase does **not** issue anything or move stock. It produces costed, resolvable recipes and
composite rates; Phase 4 is what snapshots them into an issued quotation, and Phase 5's
`work_orders` is what actually consumes raw stock and produces a `SEMI_FINISHED` quantity
([job-costing.md](../domain/job-costing.md#work-orders)).

## Scope

### In

- `rate_dimensions` / `rate_dimension_values` — company-defined axes, with management UI
  ([application-rates.md](../domain/application-rates.md))
- `application_rates` / `application_rate_dimensions` — consumption-per-work-unit, resolution
  (exact match → required-dimensions-only → default → loud failure), management UI
- `formulations` / `formulation_versions` / `formulation_lines` — recipe, customer-override
  resolution, version freeze-on-reference, cycle detection on save, recursive-CTE explosion with
  a depth guard, management UI including a version history view
- `assembly_groups` and a minimal `assemblies` foundation (identity, status, the optional
  `assembly_group_id` relationship) — built *first*, specifically so overhead policies have a
  real `ASSEMBLY`/`ASSEMBLY_GROUP` scope to resolve against from the start; see "Sequencing
  notes"
- `overhead_policies` — company/assembly-group/assembly scope resolution, the five v1 drivers,
  version freeze-on-reference, mandatory-reason audit trail, onboarding default,
  Manufacturing/Estimating settings UI
  ([ADR 0049](../decisions/0049-assembly-group-overhead-scope.md))
- `assembly_versions` / `assembly_components` (the remaining Assembly slice) — four stored
  component kinds (material/labour/plant/subcontract; `OVERHEAD` is computed, not stored — see
  ADR 0049), cost roll-up including the computed overhead line, margin-vs-markup (explicit,
  never inferred), component builder UI
- `resource_rates` — the LABOUR/PLANT estimating-rate source Step 3 discovered was missing
  (`items` itself has no rate column, and non-stocked items have no AVCO pool); an
  effective-dated table shaped like `exchange_rates`, not full explored earlier here — see
  [items.md](../domain/items.md#resource-rates)
- A read-only **per-version cost breakdown**, shipped with Step 3: every component's resolved
  quantity/unit-cost and source (AVCO, a named formulation, a resource rate as of a date, or a
  manual override), which overhead scope tier and driver resolved, and the final cost/sell per
  unit — see [assemblies.md#cost-roll-up](../domain/assemblies.md#cost-roll-up). The fuller,
  originally-scoped **assembly cost preview** below — ad-hoc quantity/dimension-value/customer
  selection with live application-rate default-fallback visibility — remains a distinct,
  unshipped follow-up; Step 3's components reference one fixed, explicitly-chosen
  `application_rate_id` per component rather than a dynamic per-request resolution, so there is
  no "fell back to default" signal to surface yet.
- Exclusion constraints preventing overlapping effective periods, for formulation, assembly and
  overhead-policy versions alike
  ([database.md](../architecture/database.md#exclusion-constraints-for-version-periods))

### Out

- **Issuing anything.** Snapshot-on-issue — copying a resolved breakdown permanently into a
  document — is Phase 4's quotation, not this phase. Phase 3's versions freeze once *referenced*,
  but nothing in Phase 3 itself creates that first reference.
- **Physically producing a `SEMI_FINISHED` item.** Consuming raw stock and posting a finished
  quantity against a formulation is a `work_orders` concern
  ([job-costing.md](../domain/job-costing.md), Phase 5). Phase 3's `cost_per_output_unit` is
  always a live theoretical figure from current AVCO — it never posts a stock move.
- **Make-to-stock manufacturing with a labour-inclusive standard cost.** A company that wants "the
  standard cost to produce 1 unit of stock, including assembly labour, independent of any
  specific job" has no single construct in Phase 3 — `formulations` are material-only, and
  `assemblies` price *work* (no output item at all, ADR 0008), not a stocked SKU's standard cost.
  Confirmed and accepted as a deliberate scope boundary during the Assembly genericity review
  (2026-09-24), not a defect: full standard-cost manufacturing is exactly the SAP/Dynamics-grade
  complexity this phase isn't chasing. Nothing in the current design blocks adding it later.
- **Work-centre/routing as a first-class entity, or any deeper manufacturing structure** —
  operations, work centres, setup/run time, co-/by-products, production orders (already
  effectively `work_orders`, Phase 5). Confirmed absent from the whole domain model and
  confirmed additive later without replacing Phase 3's model, except co-/by-products, which would
  need a real (if moderate) migration to `formulations`' single-output shape — see ADR 0049's
  context. Machine-hour overhead uses the existing `PLANT` item type's hourly rate for now.
- **Overhead-policy accumulation** (multiple simultaneous policies summing at one scope) — v1
  resolves to exactly one policy per scope. See ADR 0049's "Alternatives considered" for what
  would need deciding first.
- **A deep Assembly Group hierarchy.** `assembly_groups` is flat in v1 (no `parent_id`) — no
  validated requirement for multi-level grouping exists yet. Addable later without replacing the
  model.
- **Item-level overhead pinning**, separate from the assembly that produces the item — see ADR
  0049.
- **Deciding the mechanism that controls advanced-costing UI visibility** (a company setting, a
  permission, a subscription tier, deployment configuration). The backend supports the full
  scope hierarchy regardless; which companies see the Assembly Group/assembly-override UI is a
  packaging decision deferred past this phase — see
  [extensibility.md](../architecture/extensibility.md#progressive-capability-modes).

## Sequencing notes

Q8 (second customer profile, [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md#q8--second-customer-profile))
stays open and non-blocking through this phase. [ADR 0009](../decisions/0009-company-defined-rate-dimensions.md)'s
company-defined dimension model is the abstraction under test; while building each slice, check
that rate dimensions, resolution rules and terminology stay generic rather than assuming
paint/screeding. If implementation ever exposes a genuine structural limit — something that would
need a schema/API redesign for a plausible second industry — stop and raise that specific issue
rather than building around it silently. Do not invent an artificial second industry to validate
against; a real one, when it exists, is the actual test.

A full Assembly-domain genericity review ran on 2026-09-24, before any Assembly code existed,
specifically checking the model (not the paint *examples*, which are demo data only) against
furniture, fabrication, machinery, installation, maintenance, food/process and packaging. It
found and corrected one real structural error — see [ADR 0049](../decisions/0049-assembly-group-overhead-scope.md)
— and confirmed the rest of the model (the flat MATERIAL/LABOUR/PLANT/SUBCONTRACT component
shape, no output item on assemblies, no hardcoded categories anywhere in schema) already
generalises. The **Out** section above records what that review confirmed as deliberate scope
boundaries rather than gaps.

**Overhead's dependency on Assembly forced a task reorder**, split into three steps rather than
the original two ("overhead policies" then "assemblies"). ADR 0049's `ASSEMBLY`/`ASSEMBLY_GROUP`
scope tiers need real `assemblies`/`assembly_groups` rows to resolve against; building overhead
first against a placeholder and revisiting it once Assemblies landed would mean building the
resolver twice. See the Tasks list below for the three steps.

Backend and UI ship together, per vertical slice — not backend-complete-then-UI, matching how
Phase 2 actually shipped. Each slice below is independently demoable.

## Tasks

Each task below closes as: domain capability implemented → tests → API/UI → representative
module-scoped demo data (`database/seeders/Demo/Estimating/`, per
[environments.md](../architecture/environments.md#demo-data-is-part-of-the-feature-not-a-later-cleanup-task))
→ docs updated — in the same commit, not a later pass.

- [x] **Rate dimensions + application rates** — schema, resolution algorithm, management UI
      (define dimensions/values, define rates, see the resolution fallback chain and gaps in the
      combination grid), demo scenarios covering every resolution tier — 2026-09-23
- [x] **Formulations** — schema, customer-override resolution, cycle detection (re-checked on
      activation, not just save), depth-guarded recursive cost roll-up (PHP recursion, not the
      CTE originally proposed — see formulations.md's "Nesting"), cost-per-output-unit
      calculation, management UI (create/edit, version list with activate/delete, nested-formulation
      breakdown showing wastage compounding per level), demo scenarios covering nesting,
      versioning and customer override — 2026-09-23
- [x] **Step 1 — Minimal Assembly foundation** — `assemblies` (identity, status) and
      `assembly_groups` (flat, company-scoped, optional classification) with the optional,
      mutable `assembly_group_id` relationship (no repoint guard — a classification, not
      identity); company/tenant scoping and FK guards. No `assembly_versions`/`assembly_components`
      in this step — `overhead_policies.scope_id` (Step 2) points at `assemblies`/
      `assembly_groups` rows directly, not at a version, so a version shell would have had no
      referent yet; every assembly stays DRAFT through this slice. Minimal Actions/API/UI/demo
      data (one grouped, one ungrouped assembly) to create and reference these records — not the
      full costing feature. This exists specifically to give overhead policies (Step 2) something
      real to scope against — 2026-09-24
- [x] **Step 2 — Overhead policies** — `overhead_policies` (versioned, same DRAFT/ACTIVE/SUPERSEDED
      shape as `formulation_versions`, exclusion constraint + partial-unique "one ACTIVE per scope"
      index — both `COALESCE(scope_id, 0)`-adjusted since `scope_id` is `NULL` for `COMPANY`), the
      full v1 `Assembly → Assembly Group → Company → none` scope resolution (flat lookup at the
      group tier, no ancestor walk — `ResolveOverheadPolicy`), five v1 drivers
      (`OverheadAmountCalculator`, a pure function tested standalone ahead of Step 3 having real
      component totals to feed it), DRAFT-editable/ACTIVE+SUPERSEDED-frozen (this phase's concrete
      reading of "freeze on reference," same boundary formulations use), mandatory-reason audit
      behind two permissions (`estimating.overhead-policy.manage` to write,
      `estimating.policy-history.view` to read — registered via `PermissionRegistry`), an
      unrestricted `resolve` preview endpoint every estimator can use regardless of either
      permission. Onboarding default: no dedicated wizard — zero policy rows already *is* "no
      overhead configured," a valid terminal state (`overhead/unit = 0`, not an error), so a new
      company needs nothing beyond the Estimating settings screen's ordinary "set a new policy"
      flow whenever it's ready to. UI: a reusable scope-bound panel embedded on the new Estimating
      settings screen (COMPANY), and on Assembly Group's and Assembly's own edit screens
      (ASSEMBLY_GROUP/ASSEMBLY) — built once, complete, against Step 1's real
      `assemblies`/`assembly_groups` rows — 2026-09-24
      ([ADR 0049](../decisions/0049-assembly-group-overhead-scope.md))
- [x] **Step 3 — Remaining Assembly slice** — `assembly_versions`/`assembly_components` in full
      (four stored component kinds — material/labour/plant/subcontract, no `OVERHEAD` row; same
      DRAFT/ACTIVE/SUPERSEDED + exclusion-constraint shape as `formulation_versions`), cost
      roll-up (`ExplodeAssemblyVersion`) computing the four direct costs from components alone
      before resolving/computing overhead from them — never the reverse, so overhead can never
      land in its own base — margin/markup, `rate_override`, MATERIAL-only wastage/multiplier
      (validated away for LABOUR/PLANT/SUBCONTRACT, not silently ignored), component-builder UI
      with a full explainable cost-breakdown view. Discovered and closed a real gap along the way:
      `resource_rates`, since nothing previously gave a LABOUR/PLANT item a dollar rate — see
      [items.md#resource-rates](../domain/items.md#resource-rates). Demo data deliberately does
      not reuse `demo-f-tinted-base` for a MATERIAL component's `formulation_id` — that
      formulation's own delete-and-recreate demo-seeder idempotency would conflict with a real
      `assembly_components` FK on every re-seed; that code path is covered by
      `AssemblyCostRollUpTest` instead — 2026-09-25
- [ ] **Assembly cost preview** — resolution across all four layers (application rate →
      formulation → overhead policy → roll-up), read-only inspection UI surfacing which rate/
      policy was selected and any default-fallback warning. Step 3 shipped a narrower per-version
      breakdown (material/labour/plant/subcontract/overhead, resolved tier/driver, cost/sell per
      unit) — see [assemblies.md#cost-roll-up](../domain/assemblies.md#cost-roll-up) — but not
      this item's ad-hoc quantity/dimension-value/customer selection or live application-rate
      default-fallback visibility; still open. Before implementation, resolve the Phase 3
      boundary questions in [material-resolution.md](../domain/material-resolution.md) and
      [Q17–Q21/Q25](../OPEN-QUESTIONS.md#q17--phase-3-source-and-pack-resolution-scope); the preview
      must not accidentally absorb Purchasing, Sales pricing or Phase 5 inventory execution
- [x] Exclusion-constraint pattern applied consistently across formulation, overhead-policy and
      assembly version tables. The recursive-CTE roll-up originally proposed here was
      deliberately not used for formulations — see formulations.md's "Nesting" — so this item
      tracked the exclusion constraint only, never a CTE requirement for every version table

Tick these in the same commit as the work.

## Acceptance criteria

- [x] A company can define its own rate dimensions and values with no core-code change, and
      define per-item application rates against them; resolution falls back correctly through
      exact-match → required-only → default, and fails loudly (not silently 1:1) when nothing
      matches
- [x] A formulation can be created, versioned, and resolved for both a company default and a
      customer-specific override for the same output item; editing an in-use version is
      impossible, only superseding it with a new version is
- [x] A nested formulation (a `SEMI_FINISHED` input with its own formulation) explodes correctly
      (depth-guarded PHP recursion, not the CTE originally proposed here — see formulations.md),
      a cycle is rejected at save time *and* at activation time, and depth beyond the guard is
      rejected with a clear error
- [x] An overhead policy resolves deterministically through assembly → assembly group → company
      → none, changing a policy requires a reason and produces an audit row, an in-use policy
      version cannot be edited in place, and a small company that never creates an Assembly
      Group or an assembly-level override still gets a correct result from the company default
      alone
- [x] An assembly rolls up material, labour, plant, subcontract (stored components) and overhead
      (computed via the policy resolver, never a stored `assembly_components` row) into a
      cost/unit and, given an explicit `margin_type`, a correct sell/unit — `MARGIN` and `MARKUP`
      produce verifiably different numbers for the same inputs — `AssemblyCostRollUpTest` hand-
      verifies this against docs/domain/assemblies.md's own MARGIN-vs-MARKUP worked example
      (cost 100, 20% → MARKUP 120, MARGIN 125)
- [ ] The cost-preview screen shows a full breakdown for an assembly, including which application
      rate resolved (and whether that was a default fallback) and which overhead policy resolved
      (and from which scope) — the overhead half of this is done (see Step 3 above); the
      application-rate resolve/fallback half is not, since Step 3 didn't build ad-hoc
      quantity/dimension-value/customer selection
- [ ] Every new screen meets the same keyboard/focus/WCAG bar Phase 2's audit established

## Docs to update on completion

- [ ] `docs/STATUS.md`
- [ ] [formulations.md](../domain/formulations.md), [application-rates.md](../domain/application-rates.md),
      [assemblies.md](../domain/assemblies.md) — mark implemented, correct any detail that
      changed during build
- [ ] New ADRs for any decision made during the phase beyond ADR 0049
- [ ] A Phase 3 entry in [journeys/](../journeys/README.md), alongside the UI work that
      implements it — not deferred to the end

## Related

- [formulations.md](../domain/formulations.md) · [application-rates.md](../domain/application-rates.md) ·
  [assemblies.md](../domain/assemblies.md)
- [ADR 0008](../decisions/0008-assembly-abstraction.md) · [ADR 0009](../decisions/0009-company-defined-rate-dimensions.md) ·
  [ADR 0049](../decisions/0049-assembly-group-overhead-scope.md) (supersedes
  [ADR 0048](../decisions/0048-configurable-hierarchical-overhead-allocation.md))
- [extensibility.md](../architecture/extensibility.md#progressive-capability-modes) — simple vs
  advanced overhead UI on one shared backend
- [Invariants 1 and 4](../architecture/invariants.md) — snapshot-on-issue and
  versioned-immutability, both load-bearing throughout this phase
- [job-costing.md](../domain/job-costing.md) — where this phase's output gets consumed physically
  (Phase 5)
- [material-resolution.md](../domain/material-resolution.md) — cross-phase requirement, sourcing,
  pack, cost, price and actual-consumption boundaries pending ADR decisions
