[← Decisions](README.md)

# 0117 — Preserve pricing evidence across capability transitions

- **Status:** accepted
- **Date:** 2026-10-12
- **Affects:** Phase 4 R3 P5/P6; progressive pricing, stale review, historical and customer projections
- **Authority:** owner-approved P1–P8 after `c9c7875`; architecture acceptance only.
- **Relationship:** refines 0109's retained 0055 mode and draft/evidence contracts; all other 0109 provisions retained.

## Context

A company needs simple daily selling before structured agreements/formulas. Turning off configuration
must not silently remove effects from dependent drafts or reinterpret an issued offer. A current
calculation is not issue, acceptance, forecast or future proof of cost.

## Decision

Use coherent SIMPLE/MANAGED/ADVANCED capability dependencies and shared pricing/evidence contracts
across Item, service Item, Assembly work and governed manual lines. Domain support, company enablement,
management UI, disclosure and authority are separate; no unexposed active rule or half-enabled mode.

SIMPLE supports maintained and authorised manual bases, cost-plus/target-margin where complete cost
allows, bounded routine discounts and controlled final exceptions. MANAGED adds groups/categories,
tiers, dated lists, individual terms, agreements, simple promotions, controls and explicitly named
ordered plans. ADVANCED adds allowlisted typed/versioned formulas and governed complex conditions/
conditional stacking. No arbitrary PHP/JavaScript/SQL or company-size rule is introduced.

Activation validates configuration, capability prerequisites, supported writers and authority.
Downgrade requires impact review and reconciliation of dependent drafts, active configurations,
agreements and approvals. Reprice/resolve or withdraw drafts only through their R5-approved lifecycle;
retire incompatible future configuration without erasing histories. Unresolved dependencies refuse
the switch. Mode change cannot cancel obligations, bypass company controls or rewrite issued figures.
Actual enabled delivery must cover its agreed API/UI workflow; optional screens/advanced execution
may follow in separate explicitly labelled slices without pretending the feature is available.

### Evidence and staleness

Track relevant source/version, memberships/category applicability and exclusions, quantity/package/
UoM conversions, price/adjustment/benefit/control/authority dependencies and applicable cost/context.
Changed relevant facts mark draft evidence stale; explicit before/after review supports deliberate
reprice or valid no-change confirmation against current eligible facts. Retained manual final net
still revalidates dependencies and controls. No automatic save/read repricing or stale-rule bypass.
R5 owns exact adoption/locks/approval invalidation/issue transitions and supported freshness rules.

Issue preserves immutable fully resolved commercial and applicable estimate/cost/currency/FX/rounding
facts, selected/rejected rule provenance, calculated/final price, benefit allocation/reference and
reasoned authority/approval evidence. Current masters are never historical calculation authority.
Separate chosen customer-visible commercial discount explanations from sensitive internal candidate
prices, cost/margin evidence, formula operands and rules. Server projections apply permission and
scope on every API/UI/history/print/export surface; evaluation need not grant protected reads.

Partial/option acceptance and remaining scope require explicit R6/R9 integration; allocations and
logical scope identity must support that future work without deciding it now. Pricing does not publish
expected-value forecasts, count accepted scope twice, accept work, create jobs or reserve/post stock.
Current Opportunity expected amount remains an unreviewed assumption under 0101/0102.

### Preserved and refined contracts

0055 already governs progressive modes, typed formulas, stale review and snapshots through 0109.
This decision settles safe capability/downgrade and source-neutral dependency/projection requirements;
0115/0116 make the explicit applicability/ranking/SIMPLE refinements. Other 0109 quotation/recipient/
measure/estimate/choice contracts and 0110 cost certainty remain. No R4–R9 implementation is authorised.

## Consequences

- **Positive:** companies grow without changing historical meaning or leaking internal price construction.
- **Negative:** downgrade requires a useful impact review and can refuse pending reconciliation.
- **Requires:** typed dependency fingerprints, immutable evidence, field-safe projections, writer/mode
  enforcement and complete enabled operational screens; architecture seams precede optional UI.
- **Deferred:** R4 dates/FX/tax/rounding; R5 persistence/lifecycle/approval; R6 acceptance; R7 fulfilment;
  R8 document/delivery; R9 duties/scopes/reporting/forecast reconciliation.

## Alternatives considered

Hide controls on downgrade — rejected: leaves secret authoritative behaviour.
Auto-convert/delete dependent drafts/configuration — rejected: loses commercial intent/history.
Auto-reprice or freeze every draft forever — rejected: respectively changes terms or ignores eligibility.
Expose all formula/candidate evidence to quote readers — rejected: document read is not sensitive-field authority.
Deliver every management screen at once — rejected: complete approved slices can progressively expose shared foundations.

## Research basis

Reuse [expanded R3 record](../phases/phase-4-r3-expanded-research.md), accessed 2026-10-12,
[approved review](../phases/phase-4-r3-business-review.md), 0109/0110 and
[capability delivery](../architecture/capability-delivery-contract.md). Odoo shows optional pricing
activation and different commercial/formula presentation; ERPNext/Dynamics show structured eligibility
and combination complexity. Their defaults do not establish safe downgrade or our snapshot/projection
rules; these follow existing ERP invariants and explicit owner approval.
[Odoo official pricing source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/products_prices/prices/pricing.rst).

## Related

- [0115](0115-resolve-sales-pricing-by-explicit-eligibility-and-commercial-intent.md) · [0116](0116-govern-sales-discounts-and-final-net-controls.md)
- [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md) · [Pricing domain](../domain/sales-pricing.md)
- [Resolution contract](../domain/sales-pricing-resolution.md) · [R3 delivery handoff](../phases/phase-4-r3-implementation-slices.md)
