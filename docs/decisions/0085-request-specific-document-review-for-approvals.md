[← Decisions](README.md)

# 0085 — Give approvers request-specific document review

- **Status:** accepted
- **Date:** 2026-10-06
- **Affects:** Approvals; PO document evidence; resolves the document-review part of Q35

## Context

An approver needs to inspect what is being approved. The existing total/FX exception does not
provide the PO's lines, quantities, terms or pricing breakdown. The owner accepted the researched
recommendation on 2026-10-06: complete commercial PO review by default, with optional technical
review steps for larger companies. Ceiling readership and confidential decision reasons remain
separate unresolved parts of Q35; Q26 organisation scope is unchanged.

## Decision

Provide read-only review of the submitted document version through the approval request.
A default COMMERCIAL PO step shows supplier, items/specifications, quantities/units, delivery,
operational terms, pricing basis, prices, discounts, taxes, totals and pinned currency evidence.
An optional OPERATIONAL step shows operational evidence without structured monetary fields.
The company's governed policy selects the review purpose; neither role names nor company size
are hard-coded. A spending/authority-limit step must be COMMERCIAL. Keep a small set of
subject-defined profiles; arbitrary field configuration and line-level routing are deferred.

- Access is confined to this company's request/version. It grants no permanent module permission,
  document-edit authority, unrelated PO access, supplier credit/control-account access or margin data.
- Use an explicit subject review provider and whitelist, not raw models or an elevated PO Resource.
  The shared engine owns access and snapshots; the PO adapter lives in Approvals for this audit
  slice so the parallel Purchasing checkout is not modified. Future consumers register providers.
- Freeze review data, fingerprint and each request step's profile at submission. Changes to the
  relevant document content invalidate approval, including changes that preserve its total.
  Saved human labels/terms remain historical when referenced master data later changes.
- Current eligible approvers/delegates get their step's profile. Recorded deciders/principals retain
  that recorded profile for the historical snapshot. Normal subject financial readers may read the
  commercial snapshot only when they can already see the request. No snapshot grants request access
  by itself; request involvement/history guards and company scope remain authoritative.
- OPERATIONAL approval does not grant the total/FX exception. Independent subject financial
  authority still permits monetary evidence. Existing confidential decision reasons remain an
  explicitly open risk until their separate owner decision; do not pretend field projection can
  scrub arbitrary text. PO order notes are placed in commercial evidence, not technical evidence.
- Missing required review evidence prevents deciding, with a visible setup problem. Non-consumers
  keep their existing routing/amount behaviour until their own review contract is implemented.
- Prepare existing pending PO snapshots at migration time only where their current routing still
  matches submission, under company scope and document locks. Stale pending requests need fresh
  submission. Do not reconstruct old completed snapshots or widen old decisions' evidence.
  Existing completed approval validity remains governed by its original facts hash; a legacy
  review reports unavailable. Rollback does not delete stored historical review evidence.
- The review UI must let a person inspect evidence before deciding; failed evidence loading must
  disable decision controls. Existing default policies/users require no new permission assignment.
  Print uses only projected evidence; no attachment/export subsystem is introduced here. Future
  such surfaces must reuse the same projection and honour attachment confidentiality.

## Consequences

- **Positive:** A small business has one useful complete review; a larger business separates
  technical and commercial responsibilities using the same engine and stable capabilities.
- **Negative:** Snapshots require storage and a subject-specific evidence contract. Operational
  review is not a blanket guarantee that all free text is confidential; reason policy remains open.
- **Requires:** Allowed/denied/delegated/company/history body tests, unchanged-total invalidation,
  pending-request rollout tests, UI evidence/decision checks and API client regeneration.

## Alternatives considered

**Total-only approval** — rejected: it does not let the approver check what they are authorising.
**Permanent full-module financial grants to every approver** — rejected: request participation
is narrower than unrestricted commercial access.
**Full commercial evidence for every specialist step** — retained as the simple default but not
mandatory: larger companies may need operational-only review.
**Live document reads as historical evidence** — rejected: later edits rewrite what was reviewed.
**An arbitrary field/line-access designer now** — deferred: two subject-defined review purposes
cover this slice without inventing organisation scope or a second approval engine.

## Research basis

Reused ADR 0070 and the Q35 document-review research refreshed 2026-10-06 under
[research-and-design.md](../architecture/research-and-design.md). Primary sources:

- [Business Central official application source](https://github.com/microsoft/BCApps/blob/main/src/Layers/W1/BaseApp/System/Workflow/RequeststoApprove.Page.al):
  the caller-scoped approval inbox includes amounts/comments and Open Record for the document.
- [Dynamics Supply Chain requisition workflows](https://learn.microsoft.com/en-us/dynamics365/supply-chain/procurement/purchase-requisitions-workflow):
  procurement, management and expenditure review can use whole documents or individual lines.
  This is requisition evidence, not a claim that its PO field security is identical.
- [Oracle Fusion PO approval](https://docs.oracle.com/en/cloud/saas/procurement/25d/oaprc/how-purchase-order-approval-is-processed.html):
  approvers inspect document details/comments/attachments; stages distinguish business duties.

The products converge on document/evidence review and responsibility-based routing; they differ
in direct record opening versus richer staged/line review. They do not prescribe our profiles or
prove approval bypasses ordinary security. Request snapshots and projections translate the pattern
into this modular, company-scoped ERP; the owner chose the commercial default and optional specialist view.

## Related

- [ADR 0070](0070-shared-configurable-approval-engine.md), [ADR 0069](0069-separate-purchasing-commercial-visibility-from-receiving.md)
- [Approvals](../domain/approvals.md), [Authorisation](../architecture/authorization.md)
- [Access audit](../architecture/access-security-audit.md), [Open questions](../OPEN-QUESTIONS.md)
