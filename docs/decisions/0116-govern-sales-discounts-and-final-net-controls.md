[← Decisions](README.md)

# 0116 — Govern Sales discounts and final-net controls

- **Status:** accepted
- **Date:** 2026-10-12
- **Affects:** Phase 4 R3 P3/P4; SIMPLE discounts, pricing adjustments, authority and commercial controls
- **Authority:** owner-approved P1–P8 after `c9c7875`, including explicit replacement confirmation.
- **Relationship:** partially supersedes 0109's retained 0055 SIMPLE discount exposure; preserves all other contracts.

## Context

Every routine 10% discount need not be exceptional negotiation, but a seller must not accidentally
combine customer, campaign and tier benefits. Replacing automatic 20% with discretionary 10% increases
price. Cost completeness, minimum price and margin represent separate constraints on final terms.

## Decision

Allow bounded routine percentage, fixed per-priced-unit and fixed per-line discounts in SIMPLE.
Keep ordinary discretionary discounts, configured automatic adjustments, exceptional reasoned
final-net overrides and contractual departures distinct; none implies another duty or approval.

Default one discount adjustment slot selects one eligible automatic rule under 0115. Discretionary
entry replaces that slot only after displaying existing automatic and proposed discretionary results,
including the price increase/decrease, and obtaining explicit confirmation. No silent increase from
replacing a larger automatic benefit. In-limit routine discount records actor/time; it need not be
an exceptional reasoned override. Above effective limits needs configured independent approval or
refusal. Final override retains calculated/final values and reason, replaces net and runs no duplicate
adjustment afterwards. Ordinary override or discount approval cannot depart from fixed agreement terms.

Only explicitly configured, authorised named ordered plans combine distinct benefits, subject to
agreement and rate allowances. Running-net compounding is explicit; percentages are not accidentally
added. Per-unit effect uses declared pricing denominator; per-line applies once. Same economic benefit
identity cannot occur twice, including inside a tier/base and an adjustment. Fixed/exclusive final-net
terms admit no additional discount. Invalid percent, incompatible basis or negative net refuses,
not silently clipped. Free-goods/reward quantity and retrospective rebate settlement stay deferred.

### Final-net control and authority

Evaluate advisory/mandatory controls against final net commercial price with compatible quantity,
currency and cost evidence; revalidate at R4's eventual rounding boundary. Floor and minimum margin
are independent checks, never auto-clamping that changes intended terms. Authority tests both the
specific discretionary input and aggregate reduction against a governed reference; changing a manual
base cannot reset that reference. Unevaluable required authority cannot silently pass.

Cost-derived pricing and cost-consuming operands require complete applicable cost. Maintained/manual
pricing may proceed with partial/unknown cost only where no applicable mandatory control needs the
missing evidence. Known subtotal is not complete cost; genuine zero with evidence is valid, and
percentage margin at zero selling price is undefined. Advisory failure warns, advisory unknown states
unevaluable, mandatory failure/unevaluable prevents issue. Cost-blind authorised evaluation must not
expose protected cost/margin operands, thresholds or candidate terms through Resources/errors/exports.

Support a separately authorised, reasoned and evidence-bound commercial-risk exception contract.
**No general bypass is enabled in initial delivery.** Actual exception permissions, maker/checker,
approval scope/lifecycle and validity remain R5/R9 gates. Future permission can allow only a named
exceptionable control on its exact facts; it cannot manufacture cost, enable incomplete cost-derived
arithmetic, alter a contract or bypass tenant/scope constraints. Discount entry and exception approval
remain separate. No ordinary seller/editor/manager role implicitly receives sensitive exception duty.

### Explicit refinement and boundary

This decision extends 0055's SIMPLE exposure, retained by 0109, to bounded routine discounts; its
MANAGED automatic rules, controlled exceptions, typed effect separation, cost/price/tax boundaries
and evidence provisions remain. 0115 governs ranking/exclusions; 0110 still owns cost resolution.
R3 approves these semantics, not permission names, code delivery or R4–R9 business transitions.

## Consequences

- **Positive:** ordinary selling stays simple and benefits/price increases remain visible and deliberate.
- **Negative:** configuration must declare combination/reference/control bases; approval can block sale.
- **Requires:** explicit replacement confirmation tied to current facts, aggregate limit evaluation,
  reasoned exception evidence, independent controls and protected server projections.
- **Deferred:** R4 conversion/rounding/allocation; R5 exact approval and issue; R6 scope/discount allocation;
  R8 customer presentation; R9 effective duties, limits, grant rollout and risk permissions.

## Alternatives considered

Every reduction as final override — rejected: conflates ordinary discounts with exceptional negotiation.
Automatically append/replace discounts — rejected: can double benefit or silently increase the price.
Always choose biggest discount/add percentages — rejected: a policy decision, not deterministic intent.
Unknown cost as zero or mandatory unknown as pass — rejected: false financial confidence.
Clamp to floor/general manager bypass — rejected: silently changes terms or removes independent authority.

## Research basis

Reuse [expanded evidence](../phases/phase-4-r3-expanded-research.md) and
[worked example](../phases/phase-4-r3-worked-example.md), accessed/reviewed 2026-10-12.
ERPNext and Dynamics distinguish competition from optional compounding; Odoo distinguishes fixed,
discount and formula effects/presentation. Control/exception and replacement confirmation are explicit
project decisions, not universal vendor defaults. Primary sources:
[ERPNext Pricing Rule](https://docs.frappe.io/erpnext/pricing-rule),
[Dynamics concurrency](https://learn.microsoft.com/en-us/dynamics365/supply-chain/unified-pricing-management/upm-concurrence-within-codes).

## Related

- [0115](0115-resolve-sales-pricing-by-explicit-eligibility-and-commercial-intent.md) · [0117](0117-preserve-pricing-evidence-across-capability-transitions.md)
- [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md) · [0110](0110-version-cost-evidence-without-changing-legacy-estimates.md)
- [Pricing domain](../domain/sales-pricing.md) · [R3 delivery handoff](../phases/phase-4-r3-implementation-slices.md)
