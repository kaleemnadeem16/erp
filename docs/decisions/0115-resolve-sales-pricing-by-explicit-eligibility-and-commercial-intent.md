[← Decisions](README.md)

# 0115 — Resolve Sales pricing by explicit eligibility and commercial intent

- **Status:** accepted
- **Date:** 2026-10-12
- **Affects:** Phase 4 R3 P1/P2/P7/P8; Sales pricing, Party commercial eligibility and catalogue applicability
- **Authority:** owner-approved P1–P8 after local `c9c7875`; business architecture only, no code authorisation.
- **Relationship:** partially supersedes 0109's retained 0055 pricing provisions as specified below; all other 0109 contracts remain.

## Context

A distributor may also be a preferred partner; its own category discount can differ from group terms,
and its contract may deliberately differ from the lowest available price. Existing Item categories,
named packages and Party identity already provide the required masters. Duplicate hierarchies,
exclusive Party types and stock-only tier assumptions would constrain later selling.

## Decision

Use one company-scoped pricing eligibility/resolution contract across direct Items, service Items,
Assembly work and governed manual lines, preserving explicit agreement intent and deterministic
commercial selection. Customer financial accounts are not required for eligible prospect pricing.

### Audience and catalogue eligibility

Commercial classifications are optional, non-exclusive company-defined descriptions. Multiple flat,
effective-dated Party pricing-group memberships independently nominate eligible rules. A group's
label can fulfil the commercial description without a duplicate classification assignment. Labels
alone do not authorise pricing; no hardcoded distributor/dealer identity kind, duplicate Party master
or automatic mapping is introduced. A later explicit governed mapping may propose membership.

Reuse Inventory's category tree for Item/service applicability. Rules explicitly select exact category
or subtree; category membership alone applies no discount. Within an audience, exact Item outranks
nearest matching category, ancestors and company. Assembly uses Assembly → Assembly Group → company,
never its component categories; manual text acquires no invented Item/category/Assembly identity.

Named discount family/plan exclusions filter before ranking and deny wins in that family, even over
a more specific positive rule. Exclusions do not implicitly ban independent agreement terms or other
plans; universal prohibitions require named mandatory controls. New/moved Items follow expressly
configured current category applicability at resolution; relevant existing drafts become stale,
issued snapshots remain unchanged. Exact Item exclusions remain attached after category movement.

### Selection and conflict

Explicitly selected eligible agreement terms govern covered lines first. Preserve identity/version,
coverage, fixed versus reference-discount effect and permitted adjustments. Multiple eligible
agreements are discovered, not chosen by cheapest price; initially one applicable selected agreement
per line. Invalid selected terms refuse until explicitly repaired/reselected. Uncovered lines use
ordinary sources only where coverage permits. No ordinary override departs from fixed terms;
deselecting pricing context is not cancellation or commitment fulfilment.

Otherwise maintained source order is Party → pricing group → general → applicable source policy
→ permitted manual price, otherwise refuse. Rank within an audience by catalogue specificity, explicit
priority and applicable effective version. Context, currency, date, package/unit and tier are eligibility,
not lowest-price comparisons. Stable identity orders equivalent candidates/explanations only;
materially conflicting equal-rank candidates refuse, with activation preventing known conflicts.
Intentional dated supersession is explicit; overlapping unrelated rules cannot silently become versions.
Price-source selection and adjustment selection remain distinct; campaign effect is base replacement
or adjustment, never the same benefit twice. Context-specific general rules do not outrank Party terms.

### Quantity and context

Every tier declares its measure: offered named packages, pricing UoM/denominator or derived stock UoM,
plus explicit package eligibility and exact conversion evidence. Initial tiers are line-local all-units
breaks; separate lines, alternatives and quotation families do not automatically pool. The selected
rate declares an allowed adjustment plan or exclusive final-net terms. Never repeat an economic
benefit already embedded in that rate. Package increments, stock divisibility, tier threshold and
Sales MOQ/multiple are separate concepts; no silent rounding into tier eligibility or supplier MOQ reuse.
Marginal slabs, cross-line/category aggregation and historical cumulative tiers are deferred.

Company-wide applicability is default. Optional flat selling channel/location conditions are explicit
and validated, with no mandatory hierarchy or inferred warehouse/region membership. Context nominates
rules but grants no company/record access. Missing required selected context refuses. Dates, price-list
currency, FX, tax and rounding remain R4-owned; no unapproved cross-currency fallback is introduced.

### Explicit refinement and preserved authority

0109 retains 0055's progressive model and ranking. This decision replaces its group-pricing deferral
with real Party eligibility, extends applicability/order to the four selling sources and refines stable
ID tie-break into refusal for materially different equal-rank rules. 0109's optional estimates, measures,
choices, Party recipients, immutable issue and other retained pricing/formula/control provisions remain.
0055 is already superseded by 0109; its historical body is not rewritten. 0050/0080 conversions and
0110 cost certainty remain. These choices approve architecture, not migrations, endpoints or delivery.

## Consequences

- **Positive:** customer exceptions, overlapping memberships and packages work without a second taxonomy.
- **Negative:** conflict refusal requires intentional priority/version repair instead of arbitrary selection.
- **Requires:** company-safe references, membership/rule/basis provenance, exclusion explanation, conflict
  validation and source-specific tests; dependent drafts track category and eligibility changes.
- **Deferred:** R4 eligibility timing/FX; R5 adopted evidence/issue; R6 partial/option acceptance;
  R7 commitments/fulfilment; R8 documents; R9 duty/scope/reporting and context administration.

## Alternatives considered

Exclusive customer kind or required financial account — rejected: loses overlap and eligible prospects.
Duplicate category/group hierarchy — rejected: catalogue ancestry exists; Party audiences have different meaning.
Cheapest rule or arbitrary ID on conflicting terms — rejected: deterministic mechanics cannot replace intent.
Implicit stock-unit thresholds/pooling — rejected: packages and independent scope have explicit identity.
Enterprise geography/channel trees — deferred: flat optional conditions meet demonstrated requirements.

## Research basis

Reuse [expanded R3 comparison](../phases/phase-4-r3-expanded-research.md), accessed 2026-10-12,
and [approved P1–P8 review](../phases/phase-4-r3-business-review.md). ERPNext separates Item/group/
party/UoM applicability; Dynamics supports multiple affiliations and priority; Odoo supports product/
category quantity/date rules. They diverge on best-price, grouping and aggregation. We retain company,
Party/package identity and explicit selection rather than copying enterprise defaults.
Primary records: [ERPNext Pricing Rule](https://docs.frappe.io/erpnext/pricing-rule),
[Dynamics price management](https://learn.microsoft.com/en-us/dynamics365/commerce/price-management),
[Odoo official pricing source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/products_prices/prices/pricing.rst).

## Related

- [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md) · [0116](0116-govern-sales-discounts-and-final-net-controls.md) · [0117](0117-preserve-pricing-evidence-across-capability-transitions.md)
- [Pricing domain](../domain/sales-pricing.md) · [Resolution contract](../domain/sales-pricing-resolution.md)
- [R3 delivery handoff](../phases/phase-4-r3-implementation-slices.md)
