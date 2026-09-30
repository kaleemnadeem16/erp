[← Decisions](README.md)

# 0074 — Authorise recalculation economic-record reads by workflow duty

- **Status:** accepted
- **Date:** 2026-09-30
- **Affects:** Recalculation request list/detail, impact-version evidence, SPA workflow controls

## Context

ADR 0073 protects generic stock valuation reads but leaves workflow records for review. Recalculation request list/detail endpoints currently admit any authenticated user and include historical input costs, original and counterfactual inventory values, valuation adjustments, and `movement_impact` replay evidence. A recalculation decision cannot be responsibly made from quantities alone: its purpose is to approve a particular economic correction. A requester also supplies the receipt's pinned historical cost and needs to inspect the computed result.

## Decision

- Treat a recalculation request and its impact versions as one economic review record. List and show require **any one** of `inventory.recalculation.request`, `.approve`, `.approve-high-risk`, `.execute`, `.review-regeneration`, or `inventory.valuation.view`. These are capabilities, not role names. An unrelated authenticated company user receives 403; existing company scoping still prevents cross-company lookup.
- A workflow-duty holder sees the complete economic record needed for that duty. Do not omit the impact adjustment from an approver or force a second generic valuation grant before they can judge it. `inventory.valuation.view` permits read-only audit of the record but confers no create, decision, execution or regeneration authority.
- Mutation responses remain governed by their existing Action permissions and workflow checks. This change does not loosen maker/checker separation, risk-tier requirements, expiry or posting. The SPA admits valuation-only readers to list/detail, hides New without request authority, hides decision/execute/resume controls without their respective capabilities, and blocks a bookmarked New URL for a read-only user.
- Record scope remains company-wide pending the organisational/assignment decision in Q26. This decision does not imply general stock-valuation visibility for recalculation-duty holders: the stock ledger and overview still require `inventory.valuation.view` for monetary projection.

## Alternatives considered

**Require `inventory.valuation.view` in addition to every workflow permission** — rejected: an approver could hold approval authority but not see what they are approving, and each configured role would require an easily missed companion grant.

**Return quantity-only recalculation documents to unrelated users** — rejected: the request is an economic exception rather than an operational stock movement; exposing risk and decision state without an actual workflow duty has no established need.

**Hide values only in the SPA** — rejected: direct API list/show still disclose the entire impact and replay structure.

## Research basis

No new market comparison was needed. [ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md) already decides that approval targets an exact, immutable economic impact, while [ADR 0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md) records the current primary-source comparison for separating stock operations from valuation reporting. This decision applies those established rules to the previously unauthorised recalculation read endpoints; it does not introduce a new costing or approval method.

## Related

- [Stock recalculation](../domain/stock-recalculation.md)
- [Authorisation retrofit](../architecture/authorization-retrofit.md)
- [ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md)
- [ADR 0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md)
