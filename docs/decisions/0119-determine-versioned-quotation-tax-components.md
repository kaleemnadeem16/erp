[← Decisions](README.md)

# 0119 — Determine versioned quotation tax components

- **Status:** accepted
- **Date:** 2026-10-12
- **Affects:** Phase 4 R4 F3–F5/F8, tax classification, composition and inclusive input.

## Context

Owner approval of F1–F8 requires simultaneous taxes as core architecture, not deferred research.
A header tax code, a generic percentage or commercial pricing group cannot explain mixed Items,
services, exemptions, compounds or withholding. R3 consumes canonical tax-exclusive net.

## Decision

Determine tax per line using declared source nature/classification, Party-based tax profile,
jurisdiction and transaction/tax-date context. Preserve TAXABLE, ZERO_RATED, EXEMPT,
NOT_APPLICABLE and UNKNOWN distinctly, including component-specific exemption evidence.
Items declared as services use service defaults; goods use goods defaults, Assemblies their
appropriate service/group policy, and manual lines declared nature/classification. Do not create
a financial customer account, infer statutory exemption from a pricing group, or treat missing
classification as no tax. This partially supersedes **only** 0056's blanket Item goods-default
shorthand; its progressive modes, recovery and Finance boundaries remain accepted.

Use immutable effective-dated rate/rule versions and versioned multi-component groups. Components
have stable identities, typed bases, explicit dependencies and deterministic valid ordering:
NET, TAX_AMOUNT(named components), NET_PLUS_COMPONENTS(named components), fixed-per-line,
fixed-per-document and per-declared-unit. Record whether dependencies consume raw or settled
amounts. Validate absent/duplicate/incompatible dependencies and cycles before activation/use.
An exempt predecessor contributes known zero; an unknown predecessor cannot be silently zero.
Statutory components, commercial charges and withholding have different roles and evidence.

Input intent is explicit NET_FIXED or GROSS_FIXED. Gross input names its included output components;
normalise in a separate tax adapter to R3 canonical tax-exclusive net. Mixed included/excluded
components and supported affine inversions retain intent, basis and extracted amounts. Unsupported
or ambiguous graphs, missing determinants and incompatible rounding refuse rather than guess.
Exemption need not reduce a GROSS_FIXED negotiated selling amount; NET_FIXED preserves net and
recalculates applicable tax. Any statutory deemed/pre-discount basis needs a supported explicit
adapter, not an implicit alteration of normal discounted-net pricing.

Withholding has a separate estimate of payment impact and declared base. It never changes
commercial net, collected output tax, gross or margin. Missing cumulative/context facts produce
UNKNOWN withholding/payment estimates, not invented certainty. Actual receipts, certificates,
settlements and accounting remain Finance-owned.

BASIC/LOCALISED/ADVANCED tax modes stay independent of SIMPLE/MANAGED/ADVANCED pricing.
All approved methods fit the common contract now; initial execution exposes only supported methods.
No jurisdiction law/rate/threshold is hardcoded. No engine is authorised by this decision.
Quotation commercial evidence/tax estimate is distinct from future invoice statutory determination;
later legal facts may differ with linked variance, never overwrite the quotation snapshot.

## Consequences

- **Positive:** Correct mixed taxes and explicit net/gross intent without expanding the Party master.
- **Negative:** Richer configuration and validation; unsupported combinations deliberately block use.
- **Requires:** Immutable component/profile evidence, fail-closed capabilities and separately governed
  exemptions. Future Finance designs legal determination/recovery/posting, not quote copying.

## Alternatives considered

**One percentage / positional previous-row references** — cannot express reliable named dependencies.
**Taxes, charges and withholding in one undifferentiated total** — confuses revenue and payment.
**Enable every method immediately** — architecture support does not establish execution or legal support.

## Research basis

Reuse [tax composition and numerical examples](../phases/phase-4-r4-tax-composition.md) and
[primary comparison](../phases/phase-4-r4-evidence-and-readiness.md), accessed 2026-10-12.
Odoo and ERPNext demonstrate grouped, inclusive and dependent components; ERPNext also separates
withholding context. Our named dependencies and distinct roles avoid positional/account-based
assumptions. No product documentation is treated as statutory authority.

## Related

- [0056](0056-progressive-tax-calculation-and-recovery.md) · [0025](0025-configurable-tax-engine.md)
- [Tax domain](../domain/tax.md) · [Financial contract](../domain/quotation-financial-calculation.md)
- [R4 slices](../phases/phase-4-r4-implementation-slices.md) · [R4 review](../phases/phase-4-r4-financial-review.md)
