[← Decisions](README.md)

# 0120 — Settle quotation rounding and financial evidence

- **Status:** accepted
- **Date:** 2026-10-12
- **Affects:** Phase 4 R4 F6–F8, precision, allocations, final-net controls and later documents.

## Context

Owner-approved F6 requires operative rounding, not presentation-only rounding. Four-place storage
is not a currency's settlement quantum. Independently converted component amounts may differ from
header conversion. Source inspection also found MoneyCast using default currency minor-unit context.

## Decision

Retain decimal(19,4) money storage under 0012, higher-precision rates/conversions and rational or
appropriate decimal intermediate calculations. Use a versioned rounding policy: default commercial
unit price four places; settle final commercial line net to currency quantum (default HALF_UP);
document net is the sum of settled lines. No additional hidden document-net rounding.
Support explicit component rounding and eligible aggregate tax rounding with compatible component,
rate, jurisdiction and disposition grouping. Dependency raw/settled stage is explicit.

Allocate rounded totals/residuals deterministically in currency quanta, conserving totals and
eligibility/caps. Largest remainder uses stable logical-line identity for ties. Store allocation
weights, raw shares, quantum, settled shares and policy/tie evidence. Original allocations support
later partial consumption; exact acceptance/credit/refund decisions remain R6/Finance gates.

Retain separately (a) computed base conversions of each monetary fact and (b) allocated contributions
and reconciliation residuals. Never overwrite a computed base amount merely to balance a sum.
With three 0.0100 lines at FX0.333000000, computed bases sum0.0099; header conversion is0.0100.
The separately allocated contribution may be0.0034/0.0033/0.0033 with residual0.0001.
No GL residual posting is authorised.

Gross-fixed extraction retains a named rounding/inclusion profile and any permitted extraction
residual; incompatible net/gross/legal rounding refuses. Recheck final controls after settlement.
Commercial quantities and package/stock conversions must be representable without silently
changing offered scope; technical measures retain their declared adapter precision. A nine-place
rounded conversion factor is evidence of its precision, not proof of an exact division.

Margin and minimum-price controls consume settled tax-exclusive net, pinned comparable company-base
amounts, compatible quantities and complete applicable cost under 0110. Missing cost/FX is not zero
or a mandatory PASS; output tax and withholding are not selling revenue. Advisory versus mandatory
and risk exception boundaries remain 0116/R5/R9; no general bypass is created.

Preserve immutable commercial, component, FX, policy, allocation and cost-certainty evidence.
Quotation estimate, future invoice legal tax and payment/settlement FX remain separate linked facts.
Revisions, partial acceptance, credits and refunds must preserve original evidence and explicit
new determinations; no blind live recalculation or automatic reuse of stale statutory assumptions.

This explicitly refines historical presentation-only rounding language for Sales calculation;
it does not globally change existing Purchasing/accounting arithmetic. MoneyCast's minor-unit
context is a **required scoped implementation investigation** with load/store/arithmetic evidence,
not approval for an unscoped global cast change. No application tests were run to assert a failure.

## Consequences

- **Positive:** Reproducible totals, visible residuals and controls on actual commercial amounts.
- **Negative:** More evidence fields and explicit policy compatibility checks.
- **Requires:** Scoped monetary adapters, conservation/regression checks and R5–R9 integration.
  Acceptance is architecture only; implementation and verification remain separately authorised.

## Alternatives considered

**Presentation-only rounding** — cannot define a payable or agreed settled amount.
**Overwrite line base conversions to balance** — falsifies conversion evidence.
**Use gross for margin / absent cost zero** — falsely passes financial controls.

## Research basis

Reuse [R4 examples](../phases/phase-4-r4-tax-composition.md) and
[technical/vendor reconciliation](../phases/phase-4-r4-evidence-and-readiness.md), accessed 2026-10-12.
Dynamics Finance shows line/document tax rounding differences; Business Central separates unit and
amount precision; pinned Brick Money documentation distinguishes minor-unit/custom/rational contexts.
Our storage invariant and separately allocated base evidence preserve this project's dual amounts.

## Related

- [0012](0012-multi-currency-dual-amounts.md) · [0110](0110-version-cost-evidence-without-changing-legacy-estimates.md)
- [0116](0116-govern-sales-discounts-and-final-net-controls.md)
- [Financial contract](../domain/quotation-financial-calculation.md) · [Currencies](../domain/multi-currency.md)
- [R4 slices](../phases/phase-4-r4-implementation-slices.md) · [R4 review](../phases/phase-4-r4-financial-review.md)
