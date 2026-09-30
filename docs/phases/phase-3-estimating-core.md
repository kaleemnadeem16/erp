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
- [x] **Assembly cost preview** — delivered as the Material Preview (ADR 0058, 2026-09-26 to 2026-09-30) — resolution across all four layers (application rate →
      formulation → overhead policy → roll-up), read-only inspection UI surfacing which rate/
      policy was selected and any default-fallback warning. Step 3 shipped a narrower per-version
      breakdown (material/labour/plant/subcontract/overhead, resolved tier/driver, cost/sell per
      unit) — see [assemblies.md#cost-roll-up](../domain/assemblies.md#cost-roll-up) — but not
      this item's ad-hoc quantity/dimension-value/customer selection or live application-rate
      default-fallback visibility; still open. [ADR 0058](../decisions/0058-phase-3-material-resolution-preview-contract.md)
      now provides the complete implementation contract: a read-only, ephemeral preview composing
      the existing resolvers, initially allowed to be explicitly approximate `QUANTITY_ONLY`, with
      no Phase 3 draft/adoption table. It may propose mixed package use from internal inventory but
      must not absorb Purchasing, Sales pricing or Phase 5 reservation/execution
      - [x] **Permission-registration foundation** — move the deployable vocabulary from one
        central array to module-owned contributors and register ADR 0057's eight stable Estimating
        capabilities. Effective permissions in `/user` and frontend gating remain a later vertical
        slice so generated-client/UI changes land together rather than drifting
      - [x] **Technical preview slice** — `POST /api/v1/assemblies/{assembly}/material-preview`
        (`estimating.material_preview.run`) resolves the winning application-rate row (not just its
        stored family anchor) against the supplied dimension/customer context, expands nested
        formulations into leaf material requirements, and returns per-unit cost/sell only under the
        separate `estimating.internal_cost.view`. `GET /api/v1/user` now exposes the caller's own
        effective permissions (`UserResource`) and the `/material-preview` screen gates on it
        client-side — the first screen in the app to do so. Read-only, proven with a no-side-effects
        test. Reachable at `/material-preview` (Estimating nav) — 2026-09-26
      - [x] **Inventory proposal slice** — permitted warehouse/location narrowing, consistent
        quantity-only availability, strategy, deterministic recommendation/alternatives,
        shortage/residual and every corresponding UI control/explanation. The `inventory` block
        is returned only under `estimating.inventory_availability.view` (its scope/strategy inputs
        are refused without it) and is `QUANTITY_ONLY`: usable stock per location/batch from
        `stock_layers` in one statement, QA hold attributed to its layer, unusable location types
        reported not counted, and a lexicographic ADR 0053 plan per preset with the other two as
        alternatives and unavailable criteria reported. Cost fields stay behind
        `estimating.internal_cost.view`. `/material-preview` gained warehouse/location narrowing
        (location only when the warehouse has a real choice) and the strategy under Advanced
        options. Deliberately deferred and recorded in
        [inventory-packages.md](../domain/inventory-packages.md#first-implementation-quantity_only-availability-and-allocation):
        stored item/category/company strategy settings, hand-built package mixes, package
        equivalents and the `PACKAGE_BALANCE`/`CONTAINER_TRACKED` modes — 2026-09-28
      - [x] **Variance and sensitive-field slice** — hypothetical planned adjustments and ADR 0054
        outcome, independent availability/cost field filtering, effective-permission exposure and
        frontend action/field gating. Decided 2026-09-29 to deliver the *configurable* feature, not a
        built-in default only: company-defined variance reasons, versioned company/category/item
        policies of typed bands (strongest effect wins), an evaluator, management APIs and screens,
        preview integration (planned quantity also drives the inventory proposal), the what-if UI on
        the Material Preview screen (done 2026-09-29) and demo data. Its
        deliberate limits are tracked under
        [Deferred and blocked work](#deferred-and-blocked-work) rather than left implicit
      - [x] **Completion slice** — the preview shows when it was calculated and "Check if still current"
        compares the evidence fingerprint; accessibility (axe, keyboard) and end-to-end specs for the what-if and
        freshness states; the [Phase 3 journey](../journeys/phase-3-material-planning-journey.md) — 2026-09-30
- [x] Exclusion-constraint pattern applied consistently across formulation, overhead-policy and
      assembly version tables. The recursive-CTE roll-up originally proposed here was
      deliberately not used for formulations — see formulations.md's "Nesting" — so this item
      tracked the exclusion constraint only, never a CTE requirement for every version table

Tick these in the same commit as the work.

## Reconciliation — 2026-10-01

Everything Phase 3 set out to deliver is built and verified. What remains open below is deliberately deferred, and each item
waits on something outside Phase 3:

| Open item | Why it is legitimately outside Phase 3 | Waits on |
|---|---|---|
| Persisted adoption, approval submission and history | A plan is only hypothetical until a quotation revision adopts it | Phase 4 |
| Variance reporting (ADR 0054 §8) | Needs adopted plans to report on | Phase 4 |
| Package-level proposals, package tracking, waste/residual bands, package-aware mixes | Need sealed/opened package state that does not exist | An Inventory package-tracking design ([ADR 0072](../decisions/0072-hand-built-stock-mix-in-base-quantity.md), [phases/README.md](README.md)) |
| Cost consequence of a hand-built mix | Not a variance-band question ([ADR 0072](../decisions/0072-hand-built-stock-mix-in-base-quantity.md)) | An owner decision |
| Department-limited availability | Needs organisational scope | [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope) |
| Approval notifications | Shared, ERP-wide capability | [Q33](../OPEN-QUESTIONS.md#q33--erp-wide-notifications-capability) |

Verified against the committed state at `18dd3f4` plus other work in progress in the tree: the full backend suite passes
(1247 tests), the frontend type-checks, and the browser specs for the material preview, stored preferences, variance settings and
approvals pass. The dev Postgres self-restart is a documented environment issue, not a product blocker
([environments.md](../architecture/environments.md)).

## Deferred and blocked work

Things this phase knowingly does not do, each with what it waits on, so none is forgotten. Tick an
item here in the same commit that delivers it; add new ones as they are found.

### Waiting on another feature

- [x] **`APPROVAL_REQUIRED` variance band** — delivered 2026-09-30 through the shared approval engine
      ([ADR 0070](../decisions/0070-shared-configurable-approval-engine.md), which resolves Q27). The effect is
      selectable only while approvals are switched on for material variance, a stored band fails closed to
      `BLOCKED` if they are switched off later, and the Material Preview names the approval a plan would need and
      marks it not adoptable. *Submitting* a plan for that approval still waits for adoption on a quotation
      revision (Phase 4), because a plan is hypothetical until then. See [approvals.md](../domain/approvals.md).
- [ ] **Waste/residual variance bands** — ADR 0054 lists expected package residual as a controlled
      measure, but residual needs sealed-pack and remnant state that only `PACKAGE_BALANCE` /
      `CONTAINER_TRACKED` tracking ([ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md))
      provides. Shown as "not available yet"; bands configured before it exists must keep working
      unchanged when it does. Waits on those Inventory modes and `item_packagings`.
- [ ] **Package-level proposals** — the `PACKAGE_BALANCE` / `CONTAINER_TRACKED` modes, package
      definitions, package equivalents of a quantity ("about two 5 L cans") and open-remnant / expiry /
      surplus criteria in the ADR 0053 presets. See
      [inventory-packages.md](../domain/inventory-packages.md#first-implementation-quantity_only-availability-and-allocation).
- [x] **Stored strategy settings** — delivered 2026-10-01
      ([ADR 0071](../decisions/0071-stored-material-selection-strategy-as-governed-history.md)): item → nearest category → company →
      built-in, each change a governed row with a reason, effective from the next preview only; Settings → Material selection and
      demo data (`MaterialSelectionDemoSeeder`).
- [x] **Hand-built stock mix, quantity-only** — delivered 2026-10-01
      ([ADR 0072](../decisions/0072-hand-built-stock-mix-in-base-quantity.md)): a quantity per location/batch in the item's
      stock unit, typed in any convertible unit, validated and priced by the server (an impossible mix is refused; a mix cannot
      draw more than the plan, so it cannot bypass the variance rules). The cost/waste *escalation* half of ADR 0054 §4 is
      deliberately not built: the variance bands measure planned-vs-standard quantity, not the cost of a draw against the
      proposal, so reusing them would distort them — a separate owner decision (see the ADR).
- [ ] **Package tracking and everything that needs it** — `item_packagings`, `PACKAGE_BALANCE` / `CONTAINER_TRACKED`, sealed/opened
      package state and remnants, package-aware proposals and equivalents, expiry/remnant/surplus criteria, waste/residual
      variance bands, per-unit precision. All extend the base-quantity mix; none needs it redesigned. Dependency and the
      proposed owner (an Inventory package-tracking design before Phase 5 material issue) are in
      [ADR 0072](../decisions/0072-hand-built-stock-mix-in-base-quantity.md#deferred-with-what-each-depends-on) and
      [phases/README.md](README.md). Variable-content packages: [Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages).
- [ ] **Department-limited availability** — company-wide only until
      [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope).
- [ ] **Persisted adoption, approval and history** (`material_plan.adjust`, `package_plan.adopt`,
      `material_variance.request/approve`, `material_resolution_history.view`) — registered but not
      actionable; they belong to a Phase 4 quotation revision, not a temporary Phase 3 draft.

### Open questions and gaps

- [x] **[Q32](../OPEN-QUESTIONS.md#q32--pickers-and-lookups-that-fail-to-load-read-as-empty)** —
      pickers and lookups that fail to load read as "No items found". Resolved 2026-09-29 by
      [ADR 0064](../decisions/0064-pickers-report-a-failed-lookup-and-offer-retry.md).
- [x] **Usable-stock location rule** — resolved 2026-09-29 by
      [ADR 0067](../decisions/0067-governed-per-location-usability-mark.md): the type default stays, and a
      STRUCTURED company can mark a location as counting or not, as a governed policy. Demo data: `StructuredLocationsDemoSeeder`
      switches the demo company to STRUCTURED and gives the Main Warehouse a hierarchy, one location of each
      type with its role, two governed marks and pigment stock placed to show every way a location counts.
- [ ] **Variance reporting** (ADR 0054 §8) — distinguishing one-off justified exceptions from repeated
      deviations that should trigger a standards review. Needs adopted history, so it follows Phase 4.

### Demo-data gaps

Stock workflows without later-state demo data (see
[environments.md](../architecture/environments.md#development-demo-data)):

- [x] Cutover negative-opening exception — added 2026-09-29 to the demo cutover in `StockDemoSeeder`: an AVCO
      shortage whose exception is approved by a second actor and a FIFO one left requested, so committing is refused
      until the demo login approves it. Only one non-failed cutover is allowed per company and a database that
      already holds the earlier demo cutover keeps it unchanged, so this appears on a fresh demo database.
- [x] A HIGH_RISK two-approver recalculation and a regeneration paused for review — `RecalculationLifecycleDemoSeeder`
      scenarios E and F (2026-09-30): the company's high-risk threshold is set to 10,000 once (if unset); E has its first
      approval and waits for an elevated second approver, F had three approvals invalidated by later stock movements and
      waits for a reviewer to resume it.
- [x] Serial (SPECIFIC) stock transfers — `demo-xfer-laser` (2026-09-30): three serial-numbered meters, one transferred
      to Karachi as that exact unit at its own cost.
- [x] Typed locations (quarantine, receiving, multi-location warehouses) — `StructuredLocationsDemoSeeder` (2026-09-29)
      switched the demo company to `STRUCTURED` mode with the Main Warehouse's hierarchy, typed locations and roles.
- [x] The older seeded recalculation's approval expires after the company TTL, so its "execute it live"
      step lapses — each `demo:seed` run now renews the supervisor's lapsed approval on a live request (a same-actor
      renewal, per ADR 0040), so re-seeding restores the step; a finished request is left alone.

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
- [x] The cost-preview screen shows a full breakdown for an assembly, including which application
      rate resolved (and whether that was a default fallback) and which overhead policy resolved
      (and from which scope) — the overhead half of this is done (see Step 3 above); the
      application-rate resolve/fallback half is not, since Step 3 didn't build ad-hoc
      quantity/dimension-value/customer selection
- [x] Every new screen meets the same keyboard/focus/WCAG bar Phase 2's audit established — axe checks (no serious/critical violations) cover the Material Preview inventory, what-if and error states, the Material Variance settings screen and the warehouse Locations panel; keyboard operation is covered for the what-if flow

## Docs to update on completion

- [x] `docs/STATUS.md`
- [x] [formulations.md](../domain/formulations.md), [application-rates.md](../domain/application-rates.md),
      [assemblies.md](../domain/assemblies.md) — mark implemented, correct any detail that
      changed during build
- [x] New ADRs for any decision made during the phase beyond ADR 0049 — 0050 to 0055, 0057 to 0059, 0061, 0064 and 0067
- [x] A Phase 3 entry in [journeys/](../journeys/README.md), alongside the UI work that
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
