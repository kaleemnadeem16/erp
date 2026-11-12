# 0185 — Configure company business values; keep technical invariants fixed

- **Status:** proposed (owner direction 2026-11-12; needs owner acceptance; no code authorised)
- **Date:** 2026-11-12
- **Affects:** all modules' settings design; Purchasing FX ([0165](0165-reconcile-purchase-order-fx-with-the-governed-rate.md)), Sales discount authority ([0163](0163-govern-quotation-discount-authority-with-independent-reference-evidence.md)), Approvals ([0158](0158-approve-quotation-concessions-through-the-shared-approvals-engine.md), [0182](0182-reapprove-unissued-purchase-orders-by-materiality-when-a-stricter-policy-takes-effect.md))

## Context

Several recent decisions fixed business numbers in code (FX tolerance 0.5%, rate age 7 days) while others needed company choice (discount ceilings, approval thresholds, expiry). We need
one rule for what is configurable, so we neither hard-code business values nor build an option for every technical behaviour.

## Decision

1. **Company-specific business values get governed company-level configuration:** FX tolerance, governed-rate age, discount ceilings, approval thresholds and levels, policy expiry and
   similar. Governed means versioned, effective-dated, with change history, strict validation, the existing settings UI standard and a dedicated permission; transactions keep the
   evidence that applied when they were recorded.
2. **Technical and security invariants are mandatory and not configurable:** tenant isolation, permission enforcement, separation of duties (maker/checker), immutable audit evidence and
   lock-order safety. No setting, role or company size can switch them off. We do not add options for every technical rule.
3. A value is a business value when a reasonable company would choose differently and no one's security depends on the choice; otherwise it is an invariant.

## Product direction (recorded, not authorised)

- One secure backend for every company size; security never depends on company size.
- Starter roles are built only from existing ACTIVE duties. The STAGED, default-deny rollout is unchanged: no STAGED duty is granted by a starter role.
- Progressive administration is recorded for later: advanced roles, departments/scopes, delegated administration, a Data Health dashboard and an Audit Center.
- The milestone **Starter Roles and Progressive Administration** is **PROPOSED ONLY** and needs separate owner authorisation before any planning is scheduled or code written.

## Consequences

- **Positive:** a clear test for new settings; fewer hard-coded business numbers; no security erosion through options.
- **Negative:** each configurable value costs a versioned settings model, UI, permission and evidence; existing fixed defaults (0165) are technical debt until that work is authorised.
- **Requires:** new ADRs naming each business value they introduce and its initial default.

## Alternatives considered

**Configure everything** - invites unsafe combinations and an unreviewable matrix. **Configure nothing** - forces code changes for ordinary business differences and different company sizes.

## Research basis

Local product and governance decision from the owner; no external research.

## Related

- [Decision index](README.md)
