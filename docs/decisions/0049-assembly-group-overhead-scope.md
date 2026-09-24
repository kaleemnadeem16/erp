# 0049 — Scope overhead through Assembly Group, not item category; compute it, don't store it

- **Status:** accepted
- **Date:** 2026-09-24
- **Affects:** Phase 3, Estimating, assembly/company settings *(supersedes [0048](0048-configurable-hierarchical-overhead-allocation.md))*

## Context

A genericity review of the Assembly domain, done deliberately before writing any Assembly or
overhead-policy code, found two errors in [ADR 0048](0048-configurable-hierarchical-overhead-allocation.md)
that trace to the same root cause: its `CATEGORY` scope tier and `assemblies.md`'s `OVERHEAD`
component kind were both modelled by analogy to how *formulations* work, without checking that
analogy against how *assemblies* actually differ.

**The category walk has nothing to resolve from.** ADR 0048 said overhead's middle scope tier
walks "the output item's category chain, same as GL-account resolution." But
[ADR 0008](0008-assembly-abstraction.md) deliberately gave assemblies no `output_item_id` — an
assembly is a sellable unit of *work*, not a manufacturing BOM that produces a stocked item (the
"Manufacturing BOM only" alternative was explicitly rejected there for exactly this reason).
Formulations have an output item and a category to walk from; assemblies don't. The clause was
correct for formulations and inapplicable to assemblies, the one thing that actually carries
`OVERHEAD`.

**Two sources of truth for the same number.** Independently, `assemblies.md`'s schema still lists
`OVERHEAD` as a peer of `MATERIAL`/`LABOUR`/`PLANT`/`SUBCONTRACT` in `assembly_components.kind` —
implying a user manually adds an overhead row with its own `rate_override`, at the same time ADR
0048 was building an auto-resolving policy engine for the same figure. Nothing said whether a
manual row would add to, replace, or coexist with a resolved policy, or which one gets
snapshotted — precisely the ambiguity ADR 0048's own "no accumulation" section (correctly) refused
to allow for stacked policies but never addressed here.

**The paint/coating example was one validation case, not the target.** The review confirmed the
schema itself carries no industry-specific columns anywhere (see `assemblies.md`,
`formulations.md`, `application-rates.md`) — only demo data does. But the fix for the two errors
above still needs to work for furniture, fabrication, machinery, installation, maintenance,
food/process and packaging equally, not just paint.

**Product direction, stated explicitly for this decision:** build the mature backend capability
where a requirement is credible, and expose complexity in the UI progressively rather than
reducing the backend for small companies. This repo already has a name and a working precedent
for that pattern — [extensibility.md](../architecture/extensibility.md)'s "Progressive capability
modes" section, whose first implementation is
[ADR 0041](0041-progressive-inventory-location-capabilities.md) (inventory location mode). This
decision is the second.

## Decision

### Assembly Group replaces the category walk

A new, flat, company-scoped, **optional** classification of assemblies:

```
assembly_groups
  id, company_id
  code, name
  status            ACTIVE | ARCHIVED

  unique(['company_id', 'code'])

assemblies
  ...
  assembly_group_id   NULLABLE → assembly_groups   ← optional
```

Generic by construction: `code`/`name` are company-entered strings, exactly like
`rate_dimensions` ([ADR 0009](0009-company-defined-rate-dimensions.md)). Example values —
Machining, Welding, Installation, Finishing, Maintenance — are demo/company data only, never
hardcoded, the same discipline already applied to rate dimensions.

No `parent_id`. A genuinely deep grouping hierarchy has no validated requirement yet, and one flat
level is what was asked for: "a reusable group of similar assemblies," not a tree. A `parent_id`
column is addable later without breaking this model — the resolver would gain one more ancestor
step, the same additive shape ADR 0048 already used for describing how a future scope kind gets
added.

### Revised scope hierarchy

```
Assembly's own policy
  ↓ (none?)
Its assembly_group's policy, if the assembly has one
  ↓ (none?)
Company default policy
  ↓ (none?)
No overhead applied — not an error, overhead/unit = 0
```

`scope_type` becomes `COMPANY | ASSEMBLY_GROUP | ASSEMBLY` (was `COMPANY | CATEGORY | ASSEMBLY`).
Because Assembly Group is flat, resolution at that tier is a direct lookup, not an ancestor walk —
simpler than ADR 0048's original design, not just corrected. Everything else about resolution is
unchanged: exactly one scope wins, `scope_id` stays a `morphTo`-style reference resolved by
`scope_type`, and adding a future scope kind (e.g. a work-centre, if one is ever built) is still
an additive enum value plus one more resolution step.

### OVERHEAD is computed, not a stored component

`assembly_components.kind` drops to `MATERIAL | LABOUR | PLANT | SUBCONTRACT`. There is no
user-created `OVERHEAD` row. The cost roll-up still produces five figures — material, labour,
plant, subcontract, and overhead — but the fifth comes from resolving this policy at roll-up time
and is added to the breakdown as its own labelled line, the same way a nested formulation's
`cost_per_output_unit` is a computed figure that still shows up as a normal breakdown line (see
[formulations.md](../domain/formulations.md#cost-of-one-output-unit)). It is included normally in
whatever gets snapshotted at issue time ([Invariant 1](../architecture/invariants.md#1-snapshot-on-issue));
historical integrity has never required a *stored row*, only a *captured value*.

An assembly needing exceptional overhead treatment gets an `ASSEMBLY`-scoped `overhead_policies`
row — not a manual component with `rate_override`. That is what gives this one clear source of
truth: there is exactly one place overhead comes from, so there is never a question of whether a
manual entry adds to, replaces, or conflicts with a resolved policy.

`docs/phases/phase-3-estimating-core.md`'s Assemblies task still says "all five component kinds"
in the stored-row sense; that wording is corrected in the same change as this ADR.

### Progressive UI exposure, not a smaller backend

The full scope hierarchy, all five drivers, versioning and the audit trail from ADR 0048 remain
exactly as decided — nothing here shrinks the backend capability. A small company's settings
screen may show only "Default company overhead," never Assembly Groups or assembly-level
overrides, without the backend knowing or caring that those are hidden. An advanced company's
screen exposes the rest of the same engine. Per
[extensibility.md](../architecture/extensibility.md#progressive-capability-modes): the database
supports the superset, Actions validate what's actually submitted, and the UI reveals only what's
relevant — no mode flag is *required* for this, because Assembly Group and assembly-level policies
are optional relationships that cost nothing when unused (zero rows, zero UI, zero company
awareness). *Which* mechanism eventually controls advanced-UI visibility — a company setting, a
permission, a subscription tier, deployment configuration — is deliberately not decided here; it's
a packaging question separate from this domain decision, exactly as ADR 0041 kept "which companies
get structured locations" separate from the location model itself.

One invariant regardless of how visibility ends up controlled, worth stating plainly since it
isn't obvious: **a UI/feature-visibility toggle must never itself be a historical fact.** Turning
an advanced view off after a company has configured real Assembly Group or assembly-level policies
must not invalidate, hide the effect of, or silently alter those already-effective policies —
visibility and costing execution are different concerns, and only the latter needs the
[ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md)-style
correction-not-silent-rewrite discipline.

### Unchanged from ADR 0048

No accumulation in v1 (one resolved policy per scope, same reasoning and same
`unique(['company_id', 'scope_type', 'scope_id'])` constraint); the five drivers
(`PCT_MATERIAL`/`PCT_LABOUR`/`PCT_DIRECT_COST`/`PER_LABOUR_HOUR`/`PER_WORK_UNIT`) and why
machine-hour and generic-UOM drivers were folded into existing concepts instead; the
versioned/frozen lifecycle and exclusion-constraint pattern; the mandatory-reason audit trail and
`.policy-history.view`-style permission; the onboarding-default pattern mirroring
[ADR 0024](0024-onboarding-base-currency-and-fiscal-year.md).

## Consequences

- **Positive:** resolution is simpler than ADR 0048's original design (flat lookup beats an
  ancestor walk); exactly one source of truth for overhead, eliminating the
  manual-row-vs-policy ambiguity before any code existed to have the bug; Assembly Group is
  genuinely industry-neutral and doesn't misuse Inventory's item-category tree for a concept it
  was never meant to serve; the mature capability exists in the backend from day one, so a later
  "advanced costing" UI release never needs a schema change or migration to appear.
- **Negative:** a company using only the company default carries an `assembly_groups` table and an
  `assembly_group_id` column it will likely never populate. Accepted — both are optional and
  invisible in a simple UI, the same cost `formulations.customer_id` already accepts for its own
  optional override mechanism.
- **Requires:** `assembly_groups` and `assemblies.assembly_group_id` must exist before
  `overhead_policies` is implemented — see the reordered task sequence in
  [phase-3-estimating-core.md](../phases/phase-3-estimating-core.md) — so the full v1 resolver is
  built once, not built against a placeholder scope and revisited. `assemblies.md`'s schema and
  cost-roll-up sections need updating to drop `OVERHEAD` from `assembly_components.kind` and
  describe it as computed.

## Alternatives considered

**Reuse Inventory's item-category tree** — rejected. Assemblies have no output item to hang a
category off; forcing one would mean giving every assembly a proxy item purely to borrow its
category, which is worse than a dedicated concept built for what's actually being grouped.

**A deep, hierarchical Assembly Group tree (`parent_id`) now** — rejected for v1, not rejected
outright. No validated requirement for multi-level grouping exists yet, and "a reusable group of
similar assemblies" was asked for, not a tree. A `parent_id` column is addable later without
replacing this model.

**Keep `OVERHEAD` as a manual `assembly_components` row alongside the policy engine** — rejected.
Recreates exactly the ambiguity ADR 0048 was written to avoid for stacked policies, just at a
different layer (manual row vs. resolved policy instead of policy vs. policy).

**Build an explicit company-level "costing mode" setting now, mirroring
`inventory_location_mode`** — deferred, not rejected. The packaging mechanism for advanced-UI
visibility isn't decided and doesn't need to be to ship the backend capability: Assembly Group and
assembly-level overrides already cost a simple company nothing as optional, empty relationships.

## Related

- [ADR 0048](0048-configurable-hierarchical-overhead-allocation.md) — superseded by this ADR;
  everything under "Unchanged from ADR 0048" above still applies
- [ADR 0008](0008-assembly-abstraction.md) — why assemblies have no output item, the root fact
  this decision corrects for
- [ADR 0009](0009-company-defined-rate-dimensions.md) — the same "company-defined, core never
  special-cases" pattern Assembly Group follows
- [ADR 0041](0041-progressive-inventory-location-capabilities.md) — the first implementation of
  progressive capability modes; this is the second
- [extensibility.md](../architecture/extensibility.md#progressive-capability-modes) — the general
  pattern this decision follows, extended in the same change to state the
  feature-visibility-is-not-a-historical-fact invariant generally
- [assemblies.md](../domain/assemblies.md) — overhead's place in the cost roll-up, updated in the
  same change
- [formulations.md](../domain/formulations.md) — the version/snapshot pattern this still follows
- [ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md) — the
  correction-not-silent-rewrite principle extended here to feature-visibility toggles
