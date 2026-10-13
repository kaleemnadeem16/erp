[← Decisions](README.md)

# 0122 — Govern quotation candidates, adoption and revalidation

- **Status:** accepted
- **Date:** 2026-10-13
- **Affects:** Phase 4 R5 L1/L2, Sales quotations, shared governance and downstream boundaries.
- **Authority:** owner approval of L1–L6 after local `6d5bc0c`; architecture only, no code authorisation.

## Context

Accepted R1–R4 reserve quotation lifecycle seams. The reviewed L1–L6 research and bounded R6/R7
reconciliation distinguish quoted offers, customer decisions and operational commitments. These
remaining semantics must be explicit before implementation; existing CRM/C2 delivery stays separate.

## Decision

Separate editable inputs, non-authoritative calculation previews, immutable review candidates and
adopted evidence. Save never makes a preview approved. Routine explicit Issue may confirm the exact
reviewed result and atomically adopt applicable evidence where no special approval/adoption checkpoint
applies. Controlled Assembly/material plans retain their source-specific adoption/variance/approval
sequence under 0054/0058/0072/0076. Direct Items, services and manual lines have appropriate source
and cost-certainty evidence, without artificial material plans, Assemblies or customer accounts.

Track relevant dependency freshness separately from canonical reviewed facts. Fingerprints cover
scope/quantity/conversions, legal Party/context, pricing/discount/agreement, applicable cost certainty,
material evidence, selected options, FX/tax/rounding/financial controls and approval routing. Equal total
is insufficient. Relevant changes invalidate affected review; deliberate revalidation may preserve an
existing approval only when both review and routing evidence are unchanged, current eligibility and
approval validity pass, and no new mandatory prerequisite is missing. Append revalidation evidence;
never rewrite/rebind/revive original approval or transfer it to a successor candidate.

Reuse shared engine0070 and request-specific review0085; quote adapters own subject facts/projection.
General NONE/POLICY/REQUIRED modes do not disable mandatory source controls or exceptional duties.
Do not impose universal maker/checker. Issue duty is not discount/risk/contract exception authority;
no general risk bypass, contract departure or multi-line document discount input is authorised.
Preserve0110 complete/PARTIAL/UNKNOWN cost and 0115–0120 pricing/financial semantics.

## Consequences

- **Positive:** Explainable source-aware quotation lifecycle with immutable historical evidence.
- **Negative:** Explicit evidence/version checks can refuse stale or unsupported operations.
- **Requires:** Typed Sales adapters, company/record/sensitive-field guards, database integrity,
  idempotent transactions and verified authorised delivery. R6–R9 dependent functions stay gated.
- **Boundary:** Acceptance is architecture, not implementation or runtime verification.

## Alternatives considered

**Automatic adoption on save** — hides unreviewed economics and source requirements.
**Separate approval/adoption for every quote** — adds unnecessary SIMPLE steps.
**Equal-total approval reuse** — loses changed risk, provenance, Party and technical facts.

## Research basis

Reuse [R5 evidence](../phases/phase-4-r5-quotation-lifecycle.md) and
[owner-reviewed L1–L6](../phases/phase-4-r5-business-review.md), primary sources accessed 2026-10-12:
Dynamics quotation activation/revisions versus ERPNext submission/remaining mapping, and local
shared-engine/numbering inspection. Common pattern is frozen offer with distinct later decisions;
products differ on draft refresh, revision closure and order conversion. Preserve accepted deliberate
refresh/source neutrality; vendor behaviour is not law or approval for our pending R6/R7 policies.

## Related

- [Lifecycle contract](../domain/quotation-lifecycle.md) · [R5 slices](../phases/phase-4-r5-implementation-slices.md)
- [R6/R7 reconciliation](../phases/phase-4-r5-acceptance-and-handover-boundaries.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md)
- [0070](0070-shared-configurable-approval-engine.md) · [0085](0085-request-specific-document-review-for-approvals.md)
- [0079](0079-document-numbering-system-templates-over-a-variable-registry.md) · [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [R3](../phases/phase-4-r3-business-review.md) · [R4](../phases/phase-4-r4-financial-review.md)
