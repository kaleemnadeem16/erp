# 0058 — Define a read-only, explainable Phase 3 material-resolution preview

- **Status:** accepted
- **Date:** 2026-09-26
- **Affects:** Phase 3 Assembly preview, Estimating, Inventory availability, Phase 4 quotation adoption

## Context

Phase 3 can resolve application rates, explode formulations, roll up Assembly components and
costs, resolve overhead and convert item-specific quantities. ADRs 0051–0054 define internal-stock
package proposals and variance control; ADR 0057 defines the permissions. What remains is one
implementation contract joining those decisions without pulling Purchasing, Sales, reservation or
execution into an estimating screen.

For example, a user may ask what 100 m² of an Assembly requires for a particular customer and
surface. The system must explain which application rate and formulation resolved, calculate each
leaf material requirement, allow a hypothetical planned quantity, show what current internal
inventory could cover, report shortage/residual and expose permitted cost evidence. Running that
calculation must not itself change a quotation, reserve a package or post stock.

Phase 3 also has no quotation/revision aggregate yet. Persisting a supposedly “adopted” plan in a
new temporary Phase 3 document would create a second draft model that Phase 4 would immediately
have to replace.

## Decision

Phase 3 implements one read-only, ephemeral Material Resolution preview over a selected Assembly
and hypothetical work context. It composes the existing domain resolvers and returns a complete,
field-filtered explanation. It performs no business-data write.

Phase 4 will reuse the same resolver inside quotation-revision commands that adjust/adopt a plan,
evaluate ADR 0054 controls and persist immutable decision evidence. Phase 5 independently
revalidates and reserves/allocates physical stock.

### Request contract

The application-level request supplies:

```text
assembly_id
assembly_version_id       nullable; otherwise the effective ACTIVE version
work_quantity + work_uom
customer_id               nullable; formulation/rate context, not price resolution
dimension_value_ids       application-rate context
planned_adjustments[]     optional hypothetical item quantities, with UoM and reason text
inventory_scope           optional narrowing warehouse/location filters
selection_strategy        optional permitted what-if override
package_selection[]       optional hypothetical alternative to evaluate
```

The authenticated company and actor come from server context. A requested inventory scope may
only narrow the actor's permitted scope, never widen it. The server captures calculation time,
availability as-of time, effective versions and policy versions; the client cannot assert them.

The request uses decimal strings and item/UoM quantity precision. Zero/negative work quantities,
unknown dimension values, cross-company references and incompatible conversions fail validation.

### Resolution pipeline

The resolver executes in this order and preserves provenance at every step:

```text
Assembly + work context
  → effective Assembly version
  → application-rate resolution (including exact/required/default-fallback result)
  → formulation/version expansion to leaf material requirements
  → Assembly labour/plant/subcontract and overhead roll-up
  → item-specific/global UoM conversion and leaf-item aggregation
  → standard quantities
  → explicit hypothetical planned adjustments
  → ADR 0054 variance evaluation
  → permitted-scope inventory availability
  → tracking-mode-aware package proposal and alternatives
  → internal-cost evidence
```

Repeated leaf occurrences of the same item are aggregated for inventory planning only after their
individual Assembly/formulation contribution paths have been retained for explanation. Technical
standard, planned quantity, package quantity presented and expected residual remain separate
facts.

The preview fails loudly when technical resolution is impossible: no applicable Assembly/version
or application rate, formulation cycle/depth failure, invalid quantity precision, incompatible or
missing required conversion, or an unsupported mandatory cost context. No usable inventory is
not a technical failure; it produces zero coverage and an explicit shortage. Missing evidence is
never converted to zero.

### Response contract

The response contains:

- resolved input context, calculation time and effective Assembly version;
- every application rate, formulation version and overhead policy used, including scope and
  default-fallback warnings;
- per leaf item: source contribution paths, standard and planned quantities/UoMs and adjustment;
- hypothetical ADR 0054 outcome (`AUDIT_ONLY`, `REASON_REQUIRED`, `APPROVAL_REQUIRED` or
  `BLOCKED`) and matched typed bands;
- when authorised: effective inventory scope, availability as-of time, tracking mode, strategy and
  version, recommendation, useful alternatives, coverage, shortage, expected residual and why the
  recommendation won;
- when authorised and available: unit/extended internal-cost evidence, planned cost and
  package-selection cost/waste consequences;
- non-material and overhead cost breakdown for the requested work quantity when cost is visible;
- limitations, skipped ranking criteria, missing evidence and an explicit “advisory—not reserved”
  warning;
- a deterministic evidence fingerprint for stale-result comparison, not a reservation token.

Availability and cost are independently omitted/masked under ADR 0057. Redaction is not reported
as zero or shortage. The resolver may use internal cost to apply the configured
`LOWEST_INTERNAL_COST` strategy without returning protected amounts; the explanation identifies
the strategy but does not expose comparative values.

Useful alternatives are deterministic and capped. Selection uses ADR 0053's lexicographic presets
and stable identifiers, never an opaque weighted score. Implementations bound combination search
and report when tracking quality or search limits reduce precision rather than allowing
combinatorial work to overload the shared database.

### Progressive package precision

The first executable slice may operate in `QUANTITY_ONLY` because package-balance/container state
does not yet exist. Item quantity units can describe theoretical fixed package equivalents, but an
aggregate balance cannot prove sealed packs or remnants. The response therefore labels such mixes
approximate and cannot expose invented container identity.

`PACKAGE_BALANCE` and `CONTAINER_TRACKED` progressively improve the same response when their
Inventory state is implemented. They do not change the preview endpoint or create another
proposal model.

### Consistency, concurrency and persistence

Availability queries use one consistent database snapshot and expose its as-of time and scope.
The preview takes no stock lock, creates no reservation and may become stale immediately.

Preview inputs, choices and ADR 0054 results are hypothetical; Phase 3 does not audit every run or
store an adopted plan. The permissions for adjust/adopt/request/approve/history from ADR 0057 are
registered as the stable future transaction boundary but become actionable only when a real draft
quotation revision owns those facts.

When Phase 4 adopts a plan, the command reruns/revalidates the resolver against current master,
policy and availability evidence, compares the preview fingerprint and requires re-review if a
material fact changed. It stores the generated recommendation separately from the user's final
selection and the immutable ADR 0054 evaluation/decision facts. Quotation issue snapshots the
fully resolved material and cost evidence. It does not store a live pointer as historical truth.

### Explicit exclusions

The Phase 3 preview does not:

- select a supplier, use supplier offers, claim supplier stock or create a purchase order;
- reserve, allocate, issue, return or scrap inventory;
- infer sealed/open packages or containers from aggregate quantity;
- determine customer selling price, discount, margin or tax;
- persist a draft/adopted material plan or approval;
- bypass missing technical/cost evidence with a zero or silent 1:1 conversion.

## Consequences

- **Positive:** The remaining Phase 3 screen can be implemented as one composition of accepted
  resolvers with a precise API and security boundary.
- **Positive:** Users see standard, planned, proposed and actual quantities as different facts.
- **Positive:** The same resolver grows from approximate aggregate quantity to exact container
  selection without changing downstream quotation semantics.
- **Positive:** Phase 4 receives a clean adoption boundary instead of inheriting a temporary
  Phase 3 draft table.
- **Negative:** Phase 3 users can explore adjustments and selections but cannot save them as a
  business plan until quotations exist.
- **Negative:** Quantity-only proposals cannot truthfully identify sealed packs or remnants and
  must display prominent precision warnings.
- **Requires:** Permission registration/field filtering, consistent-snapshot availability reads,
  bounded deterministic selection, allowed/denied tests and no-write tests.
- **Requires:** Phase 4 revalidation and snapshots; Phase 5 reservation remains separate work.

## Alternatives considered

**Create a Phase 3 estimate/draft aggregate solely to save selections** — rejected. It duplicates
the quotation revision that owns commercial adoption in Phase 4.

**Let the preview reserve packages to keep its answer current** — rejected. A what-if calculation
must not create operational commitments; reservation requires a real document owner and Phase 5
concurrency rules.

**Include supplier replenishment for shortages** — rejected by ADR 0051. Shortage is the hand-off
to Purchasing, not permission for Estimating to invent supplier availability.

**Return all data and hide cost/availability in React** — rejected. It leaks sensitive values to
the browser and contradicts ADR 0057.

**Treat quantity-only package combinations as physical truth** — rejected. Aggregate stock cannot
reconstruct sealed/open/container state.

**Persist the preview result and reuse it without revalidation** — rejected. Inventory, rates,
versions and policies can change; the fingerprint detects staleness but confers no stock claim.

## Related

- [ADR index](README.md)
- [ADRs 0051–0054](README.md) — package boundary, precision, selection and variance controls
- [ADR 0057](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md)
- [Material resolution](../domain/material-resolution.md)
- [Inventory packages](../domain/inventory-packages.md)
- [Phase 3 Estimating](../phases/phase-3-estimating-core.md)
