# 0185 — Configure company business values; keep technical invariants fixed

- **Status:** proposed (owner direction 2026-11-12; amended in place 2026-11-12 for owner decision D4 and [0188](0188-record-phase-4-closure-owner-decisions-second-response-2026-11-12.md); needs owner acceptance; no code authorised)
- **Date:** 2026-11-12
- **Affects:** all modules' settings design; Purchasing FX ([0165](0165-reconcile-purchase-order-fx-with-the-governed-rate.md)), Sales discount authority ([0163](0163-govern-quotation-discount-authority-with-independent-reference-evidence.md)), Approvals ([0158](0158-approve-quotation-concessions-through-the-shared-approvals-engine.md), [0182](0182-reapprove-unissued-purchase-orders-by-materiality-when-a-stricter-policy-takes-effect.md))

## Context

Several recent decisions fixed business numbers in code (FX tolerance 0.5%, rate age 7 days) while others needed company choice (discount ceilings, approval thresholds, expiry). We need
one rule for what is configurable, so we neither hard-code business values nor build an option for every technical behaviour.

## Decision

1. **Company-specific business values get governed company-level configuration:** FX tolerance, governed-rate age, discount ceilings, approval thresholds and levels, policy expiry and
   similar. Governed means versioned, effective-dated, with change history, strict validation, the existing settings UI standard and a dedicated permission; transactions keep the
   evidence that applied when they were recorded.
2. **Technical and security invariants are mandatory and not configurable:** tenant isolation, permission enforcement, immutable audit evidence, lock-order safety and
   **mandatory maker/checker separation for INTERNAL approvals and authorisations** (quotation concessions, discount authority, Purchasing, Estimating, Approvals engine, negative-stock and similar).
   No setting, role or company size can switch them off or weaken them; a configurable workflow option (for example a self-review or submitter-may-approve flag) never bypasses a
   maker/checker rule where independent approval is mandatory.
3. **External customer acceptance is distinct from internal approval (owner decision D4).** Recording a customer's externally originated written acceptance is not an internal approval.
   Verified consent and immutable evidence are required in every configuration. By default the same authorised employee may record the customer's written evidence and register the
   acceptance. A company MAY optionally require independent verification of that acceptance: configurable per company, default OFF, enforced server-side when ON. That optional policy is
   **design-only and inactive** (see [the design](../phases/phase-4-acceptance-verification-policy-design.md), issue #114) and needs separate owner authorisation. Nothing in it weakens verified
   consent, immutable evidence or the internal-approval separation rules above.
4. **Policy effective time (single definition, matching the code).** An approval policy takes effect when its activation commits, with `effective_from` set to the activation day (the previous active version of the same subject type and code is superseded that day). A Sales discount-authority policy or price-reference version takes effect on its stated `effective_from` calendar date in the company time zone, which may be today but never in the past (backdating is refused), and a successor must start after the version it replaces. There is no reason-based exception for past dates; 0165 and 0182 follow this definition.
5. **Definitions (aligned).** A *business value* is a choice a reasonable company would make differently where the choice does not remove a mandatory protection for internal approvals,
   tenant isolation, permissions or audit evidence (FX tolerance, discount ceilings, thresholds, expiry, and the optional external-acceptance verification above). An *invariant* is any rule
   whose removal would let one person defeat an internal control or cross a tenant, permission or evidence boundary. Where a case is arguable, the owner classifies it explicitly (as D4 did) rather than deriving it.

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
