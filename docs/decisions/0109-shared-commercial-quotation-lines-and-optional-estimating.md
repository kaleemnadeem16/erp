[← Decisions](README.md)

# 0109 — Support shared commercial quotation lines with optional estimating

- **Status:** accepted
- **Date:** 2026-10-09
- **Affects:** Phase 4 R2, Sales pricing source contract, Estimating adoption and customer documents
- **Supersedes:** [0008](0008-assembly-abstraction.md) and [0055](0055-progressive-sales-pricing-and-controlled-formulas.md); retained provisions and changed scope are explicit below.
- **Owner review:** U1–U6 approved after research `4ca8b44`; acceptance is bounded business direction, not authorisation to implement or settle R3–R9.

## Context

Requiring every sellable scope to have an Assembly gives plain product/service selling unnecessary
estimate maintenance. Assemblies remain necessary for versioned derived requirements and advanced
work costing. The owner approves both capabilities coexisting: quote two cartons directly from an
Item, while an Assembly still estimates 250 m² of work with its materials, labour, plant and overhead.
The repository assessment finds no implemented quotation engine that must be converted.

## Decision

Sales has one shared commercial quotation-line contract supporting direct Items, service Items,
Assembly-backed work and controlled manual lines. Assembly is an optional estimating source,
not the universal identity of what may be sold. All entry paths share applicable pricing, cost,
tax, authorisation, approval, issue and acceptance controls.

### U1 — Identity, sources and reuse

- Preserve quotation family, revision and immutable issued-offer identity. A revision is not
  another independent deal; ADRs 0099/0100 govern CRM association and family competition.
- Preserve logical scope identity and copy/reuse provenance. Repeated use of an Item/Assembly
  does not imply duplicate work; explicit same-intent/overlap review is distinct from source identity.
- Controlled manual scope is a commercial line, not permission to invent cost, ignore tax,
  bypass approvals or post inventory/accounting facts.
- Do not force an artificial Assembly, Item or CustomerAccount merely to prepare ordinary scope.
  No physical table layout, source discriminator or new permission vocabulary is selected here.

### U2 — Measures and conversion evidence

Distinguish offered quantity, selling UoM, price basis, derived stock quantity and estimating
quantity. Reuse applicable global conversion, item-specific conversion and fixed package content,
with one authoritative basis per conversion. Preserve the actual issue-time factors and units;
never silently round the requested scope to satisfy an increment.

ADR 0050's technical composition boundary remains: formulation and Assembly component quantities
use global dimension-compatible units, not item alternate-unit/package factors. Package conversion
is a downstream commercial/sourcing concern, not a change to a recipe or application rate.
Exact quotation precision, bounds and adapter handling remain explicit implementation gates;
six-digit preview input and nine-digit conversion precision are not silently equated.

### U3 — Presentation

Support ordered lines, optional flat sections and derived subtotals. Customer-facing commercial
scope is distinct from internal costing detail. Explanatory children and a priced parent must not
both contribute the same charge. Sections are not automatically indivisible acceptance units.
Customer projection must omit sensitive internals at the server/export boundary.

### U4 — Optional and alternative scope

Preserve explicit base scope, optional add-ons and choose-one alternatives, their actual offered
quantities and included/excluded evidence. Optional scope is excluded from the base until explicitly
selected; a choose-one group contributes its explicit baseline once. Within-quote choices are
distinct from ADR 0100's competition between quotation families.

Issue freezes offered choices and baseline. Subsequent selections use separate authoritative
acceptance evidence under R6, never an edit to an issued quotation. Complex conditional combinations,
partial scope, overlapping offers and prerequisite enforcement remain R6/R7 design gates.
Forecast selection continues to use eligible issued-revision evidence under 0101/0102: no automatic
addition of unselected options or competing alternatives; accepted work cannot be counted again.
Detailed selected-baseline reconciliation remains R6/R9 before that path is delivered.

### U5 — Optional estimate and cost evidence

Preserve pinned Assembly/formulation/context and governed material-plan evidence where applicable,
including standard/planned requirements, variance/stock-choice controls and actual cost evidence.
Complete, partial and unknown cost must remain distinguishable. Missing evidence is not legitimate
zero cost. No silent substitution of newer estimate versions, rates, cost or commercial pricing.

Direct stocked-Item costing must use ADR 0077's generic material-cost contract and Inventory-owned
valuation evidence, not a bespoke Sales AVCO lookup or an inventory posting. Service/manual scope
need applicable authorised cost evidence where a pricing/control path requires it; Inventory stock
cost is not automatically their cost source. R3 determines those paths; R5 determines adoption,
staleness, issue eligibility and approval invalidation. Absence of an Assembly/material plan does
not impose irrelevant estimating approvals on an ordinary Item line.

### U6 — Recipient and locations

Reuse eligible contracting Party identity, relevant contacts and billing/execution contexts.
Prospects do not require CustomerAccount. Preserve immutable issued recipient/location facts;
a contact, site or billing address does not implicitly become another debtor or contracting Party.
R1 readiness and authority boundaries remain unchanged. Do not fabricate an account to resolve
customer-specific formulations; a prospect without an applicable account cannot inherit another
customer's override. Quote-local location evidence and future context adapters must preserve scope
and provenance; exact APIs and storage remain future Sales work.

### Explicit supersession and retained contracts

**0008 changes:** its universal “sellable unit is an Assembly” requirement, compulsory one-component
Assembly route for products/services, and rejection of direct product selling cease to govern Sales.
Its valid estimating contracts are retained here: industry-neutral work measures, optional component
layers, versioned Assemblies, material/labour/plant/subcontract costing and derived overhead, and
fully resolved evidence on issue when an estimate is used. Assembly need not have an output Item.
This does not supersede ADR 0049's overhead/group contract or existing Estimating APIs.

**0055 changes:** its universal Assembly-backed pricing assumption, compulsory product Assembly,
and universal Assembly/Assembly Group applicability cease to govern all lines. Those scopes remain
applicable to Assembly-backed work; new Item/manual applicability and precedence need R3 review.
No Item-category pricing hierarchy or service/manual fallback is invented by this decision.

**0055 retained by this decision:** its capability modes, base effects, applicable existing
resolution principles, controlled formula contract, snapshot/override/authorisation rules and
cost-versus-price-versus-tax boundaries remain governing provisions, read from the historical
record with the source generalisation above. Specifically:

- SIMPLE initially; MANAGED adds effective-dated lists/customer/tier/agreement and margin controls;
  ADVANCED adds a versioned, ordered, typed, restricted formula pipeline. Pricing mode is independent
  from CRM and tax modes.
- Maintained price, cost plus markup and target margin are separate effects. Markup is not margin;
  absence of a valid price is an error, never implicit zero.
- Specificity, priority, effective dates and stable identity provide deterministic explanations;
  never silently choose the lowest candidate. Existing Assembly scopes apply only where relevant.
- Variables have declared meaning/type/UoM/scope; allowlisted calculation steps, validated ranges,
  no arbitrary scripts/SQL, no incompatible units or division by zero; retain intermediate evidence.
- Maintained prices do not drift with cost. Cost-derived drafts require explicit staleness handling;
  manual final-price exceptions preserve calculated price, authority/reason and applicable approvals.
- Issue copies rule/formula versions, inputs/results, calculated/final prices and currency/FX/rounding
  evidence. Tax remains separate and follows net-price resolution. Purchasing offers are not Sales prices.
- The existing Assembly margin calculation remains valid estimate-derived guidance, not the final
  Sales resolver or a maintained price. Do not apply a Sales markup again to an already marked-up
  guidance figure as if it were base cost.

The old ADR bodies remain historical; only their status links change. This replacement does not
accept new R3–R9 rules or authorise replacement of working Estimating Actions.

## Consequences

- **Positive:** ordinary Items/services and advanced estimated work coexist without artificial
  catalogue maintenance or separate quotation engines.
- **Positive:** scope, estimate, presentation, selection and recipient evidence remain distinct.
- **Negative:** source adapters must explain applicable quantities, cost certainty and pricing
  context; scope-neutral does not mean every source has the same requirements.
- **Requires:** the [compatibility plan](../phases/phase-4-r2-compatibility-plan.md), source-aware
  regression coverage and explicit API rollout before any changed cost-evidence behaviour ships.
- **Requires:** company isolation, sensitive-read/write capabilities at all boundaries, shared
  approvals/numbering, atomic idempotent append-only evidence and existing inventory integrity.
- **Not authorised:** implementation, migrations/backfills, broad refactoring or R3 research.

## Alternatives considered

**Universal Assembly, including hidden one-component wrappers:** rejected for ordinary selling;
conceals maintenance rather than removing it. Still valid when the business genuinely uses an estimate.

**Product-only replacement of Estimating:** rejected; loses derived work requirements, formulation
and context evidence and valid Phase 3 functionality.

**Separate engines for each line type:** rejected; duplicates pricing, permissions and lifecycle controls.

**Rewrite accepted ADR history or immediately convert existing records:** rejected; old decisions
remain traceable and there are no existing quotation records requiring conversion.

## Research basis

Reuses [R2 research](../phases/phase-4-r2-quotation-structure.md),
[primary comparison](../phases/phase-4-r2-erp-comparison.md) (Odoo 19, ERPNext, Dynamics 365;
accessed 2026-10-09), accepted units/estimating/R1 research and the
[verified impact assessment](../phases/phase-4-r2-compatibility-audit.md).
Common pattern: commercial scope can differ from internal requirements. Products differ in optional
quantity/selection and revision handling; we preserve our immutable evidence and R1 offer contracts.
Bounded existing research is sufficient; this compatibility audit does not repeat market research.

## Related

- [0008 history](0008-assembly-abstraction.md) · [0055 history](0055-progressive-sales-pricing-and-controlled-formulas.md)
- [R2 review](../phases/phase-4-r2-quotation-structure.md) · [Compatibility plan](../phases/phase-4-r2-compatibility-plan.md)
- [Assemblies](../domain/assemblies.md) · [Sales pricing](../domain/sales-pricing.md)
- [Sales pipeline](../domain/sales-pipeline.md) · [R1 foundation](../phases/phase-4-r1-decision-matrix.md)
