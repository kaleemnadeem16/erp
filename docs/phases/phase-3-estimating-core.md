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
- `overhead_policies` — company/category/assembly scope resolution, the five v1 drivers, version
  freeze-on-reference, mandatory-reason audit trail, onboarding default, Manufacturing/Estimating
  settings UI ([ADR 0048](../decisions/0048-configurable-hierarchical-overhead-allocation.md))
- `assemblies` / `assembly_versions` / `assembly_components` — all five component kinds
  (material/labour/plant/subcontract/overhead), cost roll-up, margin-vs-markup (explicit,
  never inferred), component builder UI
- A read-only **assembly cost preview**: pick an assembly, quantity, dimension values and
  optional customer; see the fully resolved breakdown (every component, which application rate
  was selected and whether it fell back to a default, the overhead policy that resolved and from
  which scope). This is the estimator-facing verification surface before Phase 4 wires assemblies
  into a real quotation line.
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
- **Work-centre/routing as a first-class entity.** Machine-hour overhead uses the existing
  `PLANT` item type's hourly rate for now
  ([ADR 0048](../decisions/0048-configurable-hierarchical-overhead-allocation.md)). A real
  work-centre/routing model, if ever needed, belongs with Phase 5.
- **Overhead-policy accumulation** (multiple simultaneous policies summing at one scope) — v1
  resolves to exactly one policy per scope. See ADR 0048's "Alternatives considered" for what
  would need deciding first.
- **Item-level overhead pinning**, separate from the assembly that produces the item — see ADR
  0048.

## Sequencing notes

Q8 (second customer profile, [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md#q8--second-customer-profile))
stays open and non-blocking through this phase. [ADR 0009](../decisions/0009-company-defined-rate-dimensions.md)'s
company-defined dimension model is the abstraction under test; while building each slice, check
that rate dimensions, resolution rules and terminology stay generic rather than assuming
paint/screeding. If implementation ever exposes a genuine structural limit — something that would
need a schema/API redesign for a plausible second industry — stop and raise that specific issue
rather than building around it silently. Do not invent an artificial second industry to validate
against; a real one, when it exists, is the actual test.

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
- [ ] **Formulations** — schema, customer-override resolution, cycle detection, recursive-CTE
      explosion with depth guard, cost-per-output-unit calculation, management UI (create/edit,
      version list, nested-formulation breakdown showing wastage compounding per level)
- [ ] **Overhead policies** — schema, three-level scope resolution, five v1 drivers, version
      freeze-on-reference, mandatory-reason audit (`.policy-history.view`-style permission via
      `PermissionRegistry`), onboarding default, Manufacturing/Estimating settings UI
- [ ] **Assemblies** — schema, all five component kinds, cost roll-up, margin/markup, rate
      override, multiplier/wastage handling, component-builder UI
- [ ] **Assembly cost preview** — resolution across all four layers (application rate →
      formulation → overhead policy → roll-up), read-only inspection UI surfacing which rate/
      policy was selected and any default-fallback warning
- [ ] Recursive-CTE and exclusion-constraint patterns applied consistently across formulation,
      assembly and overhead-policy version tables

Tick these in the same commit as the work.

## Acceptance criteria

- [x] A company can define its own rate dimensions and values with no core-code change, and
      define per-item application rates against them; resolution falls back correctly through
      exact-match → required-only → default, and fails loudly (not silently 1:1) when nothing
      matches
- [ ] A formulation can be created, versioned, and resolved for both a company default and a
      customer-specific override for the same output item; editing an in-use version is
      impossible, only superseding it with a new version is
- [ ] A nested formulation (a `SEMI_FINISHED` input with its own formulation) explodes correctly
      via the recursive CTE, a cycle is rejected at save time, and depth beyond the guard is
      rejected with a clear error
- [ ] An overhead policy resolves deterministically through assembly → nearest category ancestor
      → company → none, changing a policy requires a reason and produces an audit row, and an
      in-use policy version cannot be edited in place
- [ ] An assembly rolls up material, labour, plant, subcontract and overhead into a cost/unit and,
      given an explicit `margin_type`, a correct sell/unit — `MARGIN` and `MARKUP` produce
      verifiably different numbers for the same inputs
- [ ] The cost-preview screen shows a full breakdown for an assembly, including which application
      rate resolved (and whether that was a default fallback) and which overhead policy resolved
      (and from which scope)
- [ ] Every new screen meets the same keyboard/focus/WCAG bar Phase 2's audit established

## Docs to update on completion

- [ ] `docs/STATUS.md`
- [ ] [formulations.md](../domain/formulations.md), [application-rates.md](../domain/application-rates.md),
      [assemblies.md](../domain/assemblies.md) — mark implemented, correct any detail that
      changed during build
- [ ] New ADRs for any decision made during the phase beyond ADR 0048
- [ ] A Phase 3 entry in [journeys/](../journeys/README.md), alongside the UI work that
      implements it — not deferred to the end

## Related

- [formulations.md](../domain/formulations.md) · [application-rates.md](../domain/application-rates.md) ·
  [assemblies.md](../domain/assemblies.md)
- [ADR 0008](../decisions/0008-assembly-abstraction.md) · [ADR 0009](../decisions/0009-company-defined-rate-dimensions.md) ·
  [ADR 0048](../decisions/0048-configurable-hierarchical-overhead-allocation.md)
- [Invariants 1 and 4](../architecture/invariants.md) — snapshot-on-issue and
  versioned-immutability, both load-bearing throughout this phase
- [job-costing.md](../domain/job-costing.md) — where this phase's output gets consumed physically
  (Phase 5)
