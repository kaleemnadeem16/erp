[← Decisions](README.md)

# 0110 — Version cost evidence without changing legacy estimate responses

- **Status:** proposed — C0 technical contract; owner approval and implementation authorisation pending
- **Date:** 2026-10-09
- **Affects:** Inventory material-cost query, Estimating cost propagation, cost APIs and consumers
- **Complements:** accepted 0077 and 0109; supersedes neither

## Context

Legacy material and resource costing can emit zero when evidence is unavailable. Existing tests
intentionally assert that behaviour, and current API consumers expect non-null numeric strings.
Adding a status beside those totals lets an unaware consumer continue treating an incomplete number
as complete. Correctness and compatibility therefore need distinct evidence and legacy contracts.

## Decision

**Proposed:** add a separately versioned cost-evidence value and opt-in evidence endpoints; preserve
existing numeric endpoints and their supported totals/failures until explicit consumer migration.
New financial/control consumers must use evidence, never infer completeness from a legacy number.

### Certainty and numeric meaning

- `COSTED`: all applicable required cost inputs are supported by valid evidence. A proven zero
  rate/cost is valid; non-zero is not a test of completeness.
- `PARTIAL`: some required inputs/quantity are resolved, others are not. Expose the known contribution
  separately; do not extrapolate it into a complete unit/line total.
- `UNKNOWN`: no applicable required cost can be established, or the requested scope/method/date
  cannot be supported. Unknown unit/complete cost is null, never zero.
- Complete `unit_cost`/`total_cost` are present only for COSTED results; partial known values have
  explicit `known_cost` semantics. Quantity coverage is per material/UoM, not a sum across unlike inputs.
- Missing stock evidence and missing resource rate carry distinct diagnostics. Absence of overhead
  policy is an intentional zero, not evidence that unknown material/labour is complete.
- Parent formulations/Assemblies retain unresolved descendants and dependency reasons. Do not calculate
  complete overhead from an incomplete driver or complete selling guidance from incomplete base cost.

### Evidence and compatibility

A versioned result owns status, diagnostics, amounts/currency, requested/resolved scope and method,
requested/effective valuation time, observation time, quantity coverage and copied source evidence.
Material strategies remain behind Inventory's read-only query; resource/manual/overhead evidence
remains Estimating-owned. Reuse valid arithmetic and technical conversions; no stock posting changes.

New evidence endpoints are separate additions under the existing API namespace, with
`schema_version: 1`; they do not rename current `cost-breakdown` or `material-preview` fields.
The exact request/response and rollout proposal is the [C0 contract](../phases/phase-4-c0-cost-evidence-contract.md).
Preserve the legacy zero-fallback tests; add separate evidence tests instead of changing their
expectations without authorisation. No transparent fallback from failed evidence reads to old totals.

An evidence result is a point-in-time calculation, not adopted/issued authority. COSTED measures
completeness, not commercial approval or future trust. R5 controls adoption/staleness; R3 decides
where complete cost is required. Ordinary maintained-price Item/service/manual selling does not
universally require material costing.

### Initial temporal and scope support

Current pools and layer remaining quantities are mutable; filtering receipt dates cannot reconstruct
historical stock valuation. Initial C1 proposes current snapshot valuation only: unsupported historical
or future requests return UNKNOWN with an explicit temporal reason, not today's cost under another date.
Retained published/adopted evidence is read from its saved values. Historical replay support requires
separate justified work; do not invoke posting/recalculation writers from a cost query.

Use ADR 0077's chosen-stock → document → governed company default → unknown scope rule. Preserve its
AVCO scope distinctions, FIFO hypothetical allocation and SPECIFIC identification. Initial query work
can support explicit scope and built-in COMPANY; configurable default history is a separately identified
C1 slice requiring an additive migration only when authorised. Do not call that limited slice complete
delivery of every ADR 0077 obligation.

### Permission and rollout boundaries

Guard new evidence entry points in Form Request and Action; reuse cost, recipe and stock visibility
capabilities with field-safe projection. Do not expose hidden source quantities/locations or fingerprints
through a cost-only response. New Inventory query authority must be supplied by guarded domain adapters,
with tenant/warehouse scope enforced again at the query boundary; no authenticated-only API.

C1 query and C2 propagation/API/consumer adoption are independently reviewed slices. Legacy APIs remain
available as guidance during transition; migrated UI must explicitly show incomplete results. Adoption
of the new contract, not just backend availability, resolves the affected consumer's ambiguity.

## Consequences

- **Positive:** unavailable evidence and genuine zero remain distinct without silently changing old APIs.
- **Positive:** new consumers cannot mistake a known subtotal for a complete estimate.
- **Negative:** legacy and evidence contracts coexist temporarily; their differences need explicit tests,
  documented consumers and a future deliberate retirement decision.
- **Requires:** owner approval of C0/API/temporal/coverage rules before C1/C2 code; OpenAPI/client generation
  and targeted consumer migration with permission, decimal, company and scope regression coverage.
- **Not authorised:** code, migrations/backfills, a broad estimator rewrite or R3 business decisions.

## Alternatives considered

**Change current zero totals to null:** breaks current numeric types and expected failures; rejected
without an explicit compatibility/retirement decision.

**Add only status to the existing response:** old callers can ignore it; expanding FIFO/scoped support
also changes existing failure semantics. Rejected as the sole correctness mechanism.

**Global API v2:** isolates semantics but duplicates unaffected API contracts and tooling. Separate
versioned evidence resources under v1 provide the necessary boundary with less unrelated change.

**Keep legacy numbers as authoritative:** rejected; missing evidence must not pass a trusted financial
or cost-dependent control. Compatibility is not authority to rely on incomplete costs.

## Research basis

This is a bounded technical rollout proposal for accepted 0077/0109, not a new ERP costing model.
Reuses [R2 compatibility audit](../phases/phase-4-r2-compatibility-audit.md),
[existing cost-scope research](0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md),
[API conventions](../architecture/api-conventions.md) and actual resolver/API/test/consumer inspection
on 2026-10-09. No new market comparison or R3 research is needed to distinguish these rollout choices.

## Related

- [C0 contract](../phases/phase-4-c0-cost-evidence-contract.md) · [C1/C2 slices](../phases/phase-4-c1-c2-cost-evidence-slices.md)
- [0077 material-cost contract](0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md)
- [0109 commercial sources](0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [Compatibility plan](../phases/phase-4-r2-compatibility-plan.md) · [Phase 4 plan](../phases/phase-4-sales-quotations-and-handover.md)
