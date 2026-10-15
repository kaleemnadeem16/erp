[← Decisions](README.md)

# 0138 — Authorise Sales and document duties with explicit scope and grants

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Phase 4 R9 A1–A4; Sales, Documents/Company and owning source/approval/reporting adapters
- **Authority:** owner's A1–A10 approval with safeguards after local c25d5cb; architecture/documentation only, no application code/live permissions/grants authorised.

## Context

R1–R8 and Documents0137 have accepted business boundaries. The final R9 review reconciles duties,
scopes, limits and reporting without changing their historical accepted bodies. Actual delivery,
public enablement and real holder decisions remain distinct from architecture approval.

## Decision

Preserve all eight existing CRM permission strings/meanings. Adopt the reconciled duty catalogue
and operation/field/scope contracts in the linked authority matrix, with exact code/slice reconciliation
before registration. Preparation includes ordinary review submission; do not register a redundant
sales.quotation.submit. Quote issue, withdrawal/review, pricing adjustments, evidence record/accept/
correction, handover and delivery remain distinct. Documents owns shared configuration/recovery,
Company owns branding, Sales owns quotation-specific file/attachment/delivery eligibility. Ordinary
users require no second generic document-download duty. Future exceptions/contract departure/
claim-release/unsupported capabilities are reserved, not automatically registered or enabled.

Initially support company-wide Sales access only through explicit effective per-duty grants. No
ownership/assignment/Party/hierarchy/unrelated-permission access inference. Reuse RecordScope;
restricted own/assigned/unit modes refuse until every relevant API/Action/UI surface is complete.
Sensitive operations require both their duty and legitimate source/target eligibility. This is a
safe first delivery boundary, not permanent exclusion of restricted scope.

Protect commercial terms, cost/margin, approval reasons, confidential evidence/files server-side,
including history/search/sort/filter/export/download/errors/cache/workers. Authorised blind controls
may return safe PASS/WARN/FAIL without operands/thresholds. Customer output is exact allowlist.

Register new duties explicitly with safe deployment grant manifest and owner-reviewed holder inventory.
No live user/role grants authorised here. Preserve actual equivalent predecessors/holders/scopes
where proven; no CRM→new Sales/Documents/Finance expansion or admin/manager name inference. Verified
full-access mandates only under approved company/scope evidence. Sensitive acceptance/reconciliation/
correction/policy/discount-approval/template/recovery rights are excluded from ordinary automatic
growth. Transparent role presets may bundle explicit permissions but create no separate authority.

## Consequences

- **Positive:** explainable duty/authority/evidence and reporting with no duplicate business commitments.
- **Negative:** unsupported adapters, missing required evidence and unsafe rollout mappings refuse enablement.
- **Requires:** [duty matrix](../domain/sales-duty-matrix.md), [authority contract](../domain/sales-authority.md), [reporting contract](../domain/sales-reporting-and-forecast.md), [rollout specification](../phases/phase-4-r9-permission-rollout.md) and actual integrated verification.
- **State:** ACCEPTED architecture / SPECIFIED contracts; no new R9 feature IMPLEMENTED or VERIFIED by this record.

## Alternatives considered

One broad manage/admin right — collapses duties and expands sensitive authority.
Ownership/Party links as access — confuses responsibility with capability/scope.
Frontend hiding or generic file-read-all — leaves API/export/worker paths unsafe.
Register everything and let administrator sync infer holders — ignores safe scope/grant continuity.

## Research basis

Reuse [final R9 A1–A10 comparison and inspected contracts](../phases/phase-4-r9-authority-reporting-review.md),
primary ERPNext authorisation-rule and Dynamics forecast-permission evidence accessed2026-10-15,
plus accepted0101/0102/0106/0107/0114/0116/0070/0085/0087 and0137. Products separate conditional
authority and forecast access but differ on role/hierarchy rules; project capability/scope semantics
remain governing. Owner-approved refinements settle remaining choices, not vendor defaults.
Reused evidence answers these questions; no repetitive search or application test was needed to
record architecture approval. Exact repository vocabulary was inspected read-only before specification.

## Related

- [R9 slices](../phases/phase-4-r9-implementation-slices.md) · [Consolidated handoff](../phases/phase-4-implementation-handoff.md)
- [Decision index](README.md) · [Authorisation](../architecture/authorization.md)
