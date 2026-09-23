# 0048 — Configurable, hierarchical overhead allocation for assemblies

- **Status:** accepted
- **Date:** 2026-09-23
- **Affects:** Phase 3, Estimating, company/category/assembly settings

## Context

[assemblies.md](../domain/assemblies.md) names `OVERHEAD` as one of five component kinds in the
cost roll-up but explicitly flags it as "the least well-defined component kind," undecided.
Different manufacturing businesses drive overhead differently — a furniture maker by labour hour,
a CNC shop by machine hour, a stone processor per square metre, a small manufacturer by a flat
percentage of direct cost — and this is a general-purpose ERP, so no single fixed basis is
correct for every company, or even for every assembly within one company.

Overhead also differs from the other four component kinds in one important way: it isn't sourced
from a purchased or worked quantity (material, labour hours, plant hours, a subcontract price).
It's a policy choice about how to spread indirect cost, and that choice needs to be configurable
without a schema change, resolvable deterministically, changeable over time without corrupting
historical figures, and simple enough at company signup that nobody is forced to design
manufacturing costing before they have any assemblies to cost.

Two existing mechanisms already solve adjacent problems and set the pattern to follow rather than
reinvent:

- [Invariant 1](../architecture/invariants.md#1-snapshot-on-issue) already guarantees that once a
  quotation or job is issued, the fully resolved cost breakdown — including whatever overhead
  amount was resolved at that moment — is copied into the document. A later change to the
  overhead policy therefore already cannot silently change an issued document's figures; this ADR
  does not need to invent that protection.
- `categories.parent_id` is already walked to resolve GL account mapping and the default
  valuation method ([items.md](../domain/items.md#gl-mapping)), nearest-ancestor-wins, no forced
  default at the root. The same walk is the natural resolution path for overhead.
- Phase 3 (estimating) does not post to inventory valuation, COGS or the GL — it only produces
  costed assemblies and quotations. The lifecycle stages this decision must actually cover are
  "no policy configured" → "category/assembly policies exist" → "an estimate has resolved and
  possibly been issued." "Already posted to the GL" is a Phase 5/6 concern
  ([ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md) already governs
  correcting posted facts without silently rewriting history); this ADR anticipates that boundary
  but does not need to build for it.

## Decision

Add a versioned `overhead_policies` table, resolved per assembly at cost roll-up time, walking a
fixed three-level scope hierarchy with deterministic, non-accumulating resolution.

### Scope hierarchy

```
Assembly's own policy
  ↓ (none?)
Nearest ancestor in the output item's category chain with a policy
  ↓ (none?)
Company default policy
  ↓ (none?)
No overhead applied — not an error, overhead/unit = 0
```

Exactly one scope wins. `ASSEMBLY` and `CATEGORY` (nearest-ancestor, same walk as GL-account
resolution) and `COMPANY` are the only scope kinds in v1. Item-level pinning (a policy on the
output item rather than the assembly that produces it) is deliberately excluded: overhead
reflects *how something is produced*, and the same output item can be produced by more than one
assembly with different cost drivers, which would make an item-level policy ambiguous against an
assembly-level one. The assembly already represents the production method; that's the right unit
to pin a policy to.

`scope_type` is a plain enum column (`COMPANY | CATEGORY | ASSEMBLY`), and `scope_id` an
untyped-at-the-database-level polymorphic reference resolved by `scope_type` at the application
layer (Eloquent `morphTo`). Adding a future scope kind (`WORK_CENTRE`, `OPERATION`, or item-level)
is an additive enum value plus one more resolution step inserted at the right point in the walk —
not a redesign.

### No accumulation in v1

A scope resolves to **at most one active overhead policy**, never a sum of several. The five
component kinds (material, labour, plant, subcontract, overhead) still each contribute
independently to `cost/unit` as [assemblies.md](../domain/assemblies.md#cost-roll-up) already
defines — this decision is only about the overhead component itself, not about collapsing the
other four into it.

```
Company:            10% of direct cost
Furniture category:  $8 / labour hour
Chair assembly:      12% of direct cost

Chair resolves to → 12% of direct cost (its own policy; category and company are not added)
A plain furniture assembly with no policy of its own → $8/labour hour (category; company is
  not added on top)
```

This keeps resolution and audit trail simple: "this assembly's overhead rule is X," one row, one
number, one reason for the last change — not an accumulation whose components, order and combining
rule would all need independent design.

`unique(['company_id', 'scope_type', 'scope_id'])` where the policy is active enforces this at
the schema level, so multi-policy accumulation is a genuinely new decision if a real case ever
needs it — not a default this schema quietly permits — and relaxing that constraint plus adding
an explicit combination rule (replace vs. add, calculation-basis ordering, per-component effective
dating) is exactly the shape a future ADR would need to add. Nothing in the v1 schema forecloses
it.

### Drivers

v1 ships five: `PCT_MATERIAL`, `PCT_LABOUR`, `PCT_DIRECT_COST`, `PER_LABOUR_HOUR`,
`PER_WORK_UNIT`. Two from the initial candidate list are deliberately folded into existing
concepts rather than built as separate drivers:

- **Machine/work-centre-hour** — no work-centre or routing entity exists anywhere in the domain
  model yet (confirmed absent from every domain/architecture doc). `PLANT` is already an item
  type with its own hourly rate ([items.md](../domain/items.md#types)); a CNC shop's
  machine-hour overhead is a `PER_WORK_UNIT`-driven `PLANT` component for now. A real
  work-centre/routing concept, if the domain ever needs one, belongs with Phase 5 job execution,
  not invented early to serve this decision alone.
- **Generic UOM-based allocation (kg, m², batch)** — redundant with `PER_WORK_UNIT`: an
  assembly's `work_uom_id` is already company-defined per
  [application-rates.md](../domain/application-rates.md)'s worked examples (paint per sqm,
  screeding per sqm at a given thickness), so "per work unit" already covers this.

### Versioning and lifecycle

`overhead_policies` follows the same shape as `formulation_versions`/`assembly_versions`:
`status` (`DRAFT | ACTIVE | SUPERSEDED`), `effective_from`/`effective_to`, and an exclusion
constraint preventing overlapping effective periods for the same scope
([database.md](../architecture/database.md#exclusion-constraints-for-version-periods) already
documents this pattern for the sibling tables). A policy is immutable once any resolved cost
roll-up that used it has been snapshotted into an issued document — changing the rule creates a
new version; it does not rewrite the old one. Each change carries a mandatory `reason`, matching
the `PurchasingPolicyChange`/`InventoryPolicyChange` precedent from Phase 2 company settings, and
is visible through the same kind of separate `.policy-history.view` permission those use — who
changed a costing policy and why is worth gating independently of who may set it.

### Onboarding default

Company signup initialises one `COMPANY`-scope policy from a short choice — no overhead, a flat
percentage of direct cost, or "configure later" — mirroring
[ADR 0024](0024-onboarding-base-currency-and-fiscal-year.md)'s base-currency/fiscal-year pattern.
It is an ordinary editable default, not an irreversible setup decision; category- and
assembly-level detail is configured later, in Manufacturing/Estimating settings and on the
assembly itself, once real assemblies exist to configure it against.

## Consequences

- **Positive:** one deterministic resolution path, easy to explain in the UI ("this assembly's
  overhead is X, inherited from Y" / "set directly on this assembly"); reuses three already-proven
  patterns (category-ancestry walk, version-and-snapshot, policy-change audit) instead of adding a
  fourth; signup stays a one-field choice.
- **Negative:** a company that genuinely needs two independent overhead charges to stack (a
  general allocation plus a specific supervision charge, say) cannot express that in v1 — it must
  approximate with one combined rate, or model the second charge as a separate `SUBCONTRACT`/
  `LABOUR` component instead of overhead, until accumulation is designed.
- **Requires:** the exclusion-constraint pattern for effective periods must be applied to this
  table the same way it already is for formulation/assembly versions; the
  `.policy-history.view`-style permission must be registered through the shared
  `PermissionRegistry` ([ADR 0028](0028-dynamic-namespaced-permissions.md)) like the Phase 2
  policy permissions were.

## Alternatives considered

**A single fixed basis (e.g. percentage of direct cost only)** — rejected outright per the
`OVERHEAD` landmine in [assemblies.md](../domain/assemblies.md#overhead-allocation) and the
explicit product requirement: this is a general-purpose ERP and different manufacturing
industries drive overhead differently within the same install.

**Accumulating multiple simultaneous policies per scope from the start** — rejected for v1.
Real demand for it hasn't surfaced yet, and building it now pays for inheritance-vs-addition
rules, calculation-basis ordering and per-component effective dating before any company has
asked for it. The schema is deliberately left able to grow into this later without a destructive
redesign (see "No accumulation in v1" above).

**Item-level policy scope** — rejected for v1. The same output item can be produced by more than
one assembly/process with different cost drivers, so pinning overhead to the item rather than
the assembly would create ambiguity the assembly-level scope doesn't have.

**A new WORK_CENTRE/OPERATION entity to carry machine-hour rates** — rejected for v1. No routing
concept exists in the domain model yet; `PLANT` items already carry an hourly rate and cover the
CNC/machine-hour example without inventing new master data ahead of a real need.

**Model overhead like `PurchasingPolicyChange`/`InventoryPolicyChange` (a singleton settings row
plus an audit log)** — rejected. Those govern one company-wide toggle each; `overhead_policies`
is inherently multi-row (one per scope, potentially many categories and assemblies), which is
structurally closer to the versioned-and-snapshotted shape formulations and assemblies already
use than to a singleton setting. The mandatory-reason/audit-permission *convention* from those two
is kept; the singleton-plus-log *shape* is not.

## Related

- [assemblies.md](../domain/assemblies.md) — overhead's place in the cost roll-up
- [formulations.md](../domain/formulations.md) — the version/snapshot pattern this follows
- [items.md](../domain/items.md#gl-mapping) — the category-ancestry walk this reuses
- [Invariant 1](../architecture/invariants.md#1-snapshot-on-issue) · [Invariant 4](../architecture/invariants.md#4-versioned-records-are-immutable-once-used)
- [ADR 0009](0009-company-defined-rate-dimensions.md) — the sibling "company configures, core
  code doesn't special-case" decision this mirrors
- [ADR 0024](0024-onboarding-base-currency-and-fiscal-year.md) — onboarding-default precedent
- [ADR 0028](0028-dynamic-namespaced-permissions.md) — permission registration
- [ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md) — the
  correction-not-silent-rewrite principle this anticipates for Phase 5/6
- [ADR 0047](0047-govern-inventory-structure-and-default-valuation-policies.md) — the
  policy-change audit convention this reuses
