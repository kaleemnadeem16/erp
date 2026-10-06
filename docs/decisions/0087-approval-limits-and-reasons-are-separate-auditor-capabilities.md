[← Decisions](README.md)

# 0087 — Approval limits and written reasons are separate auditor capabilities

- **Status:** accepted
- **Date:** 2026-10-06
- **Affects:** Approvals (authority list, request detail, history, inbox), Access Administration roles, demo data; resolves the remainder of Q35

## Context

[ADR 0070](0070-shared-configurable-approval-engine.md) holds each person's spending limit as data, and [ADR 0085](0085-request-specific-document-review-for-approvals.md) decided what an approver may inspect of the document. Two things were still readable by everyone who may read the approval history (`approvals.history.view`):

- **Everyone's spending ceilings.** Knowing exactly how much a named colleague may approve is governance information, useful for a bad actor planning to stay under a limit, and no part of auditing who decided what.
- **Free text written on decisions.** A reason is unstructured, so it can say what the structured fields withhold: "unit price 80,000 is too high" discloses a price to a technical-only reviewer or a history reader the figure is otherwise hidden from. Removing numbers from text is not a control, because a reason can be sensitive without containing one.

The owner decided on 2026-10-06, accepting the recommended option on each of three questions.

## Decision

- **Ceilings.** Listing everyone's limits needs `approvals.authority.manage` (the people who set them) or a new `approvals.authority.view` (an auditor an administrator names; read only, it cannot change a limit). `approvals.history.view` no longer reveals them. Everyone still reads their own through `approval-authorities/mine`.
- **Reasons.** A person-written reason — the reason on a decision, and the closing reason when a person rejected or withdrew the request — is shown only to:
  - anyone entitled to the request's evidence under the rule [ADR 0085](0085-request-specific-document-review-for-approvals.md) and the A5 work already apply (`ApprovalEvidenceAccess`: the document module's own field policy, the person asked to decide, someone who has decided);
  - holders of a new `approvals.reason.view`;
  - its author: a decider (and the principal they acted for) reads their decision's reason and a rejection's closing reason; the submitter reads their own withdrawal.
  Everyone else who may open the request still sees the outcome, who decided and when. The response says the text is withheld (`reason_visible`, `closing_reason_visible`) rather than returning an empty value that looks like "nothing was written", and the screen shows "reason not shown to you".
- **System-written reasons stay visible.** "The approval expired before it was decided", "The order was cancelled" and similar quote no figures.
- **Rollout.** One additive, idempotent migration gives both new capabilities to every role and person who holds `approvals.history.view` today, per company, so nobody loses a read on release. It grants nothing to anyone who did not hold history, and no change authority. The owner can then revoke either per person in Access Administration; the access-inventory command shows who holds what.
- **Demo data.** Two company roles built through the same Actions an administrator uses: "Approvals auditor" (history plus both new capabilities) and "Approvals history reader" (history only), each given to a persona, so the difference shows on the demo's rejected order, whose reason mentions a price.

## Consequences

- **Positive:** reading history reveals outcomes, identities and times; limits and reasons are separate, individually grantable and revocable, and need no new engine or record scope.
- **Positive:** the A5 principle ("approval authority is not a licence to read commercial data") now covers the free text, closing a path that structured projection could not.
- **Negative:** a submitter who is not entitled to the document's evidence does not read why their request was **rejected** (they did not write it). They see the outcome and who decided; the approver, or an administrator granting evidence access, tells them. The submitter of a PO normally holds commercial visibility and does read it.
- **Negative:** until an administrator reviews them, every current history reader keeps both reads (the backfill preserves access by design). Narrowing is a deliberate, per-person step.
- **Requires:** `permission:register` and the administrator sync on release, as for any new capability; staging verification with real members.

## Alternatives considered

**Keep ceilings and reasons with history readers** — rejected: it makes the history capability a licence to read governance and commercial text it was never meant to carry.

**Restrict ceilings to managers only, without an auditor capability** — rejected: auditors would need the right to change limits to read them.

**Scrub amounts out of reason text** — rejected: unreliable, and a reason can disclose a margin, a supplier's name or a dispute without a digit.

**Never show reasons except to their author and auditors** — rejected: a reviewer entitled to the document's figures needs the earlier reasoning to decide on the same evidence.

**No automatic grants (least privilege from the start)** — rejected for release safety: an auditor could be locked out on release day. The access inventory makes the review easy afterwards.

## Research basis

No new market comparison was needed. [ADR 0070](0070-shared-configurable-approval-engine.md) and [ADR 0085](0085-request-specific-document-review-for-approvals.md) already hold the primary-source comparison of mature approval systems (Business Central, Dynamics Supply Chain, Oracle Fusion Procurement, NetSuite approval limits) and the field-level-security precedents (Dynamics field and data-entity security, Odoo field groups). Those sources support server-side field enforcement and separate limits from document evidence; none says who may read a limit or a comment, so this is a project decision, recorded here, made by the owner. [ADR 0084](0084-separate-financial-master-data-fields.md) is the precedent for separate read capabilities with an additive backfill.

## Related

- [Approvals](../domain/approvals.md), [approvals journey](../journeys/approvals-journey.md)
- [Access and sensitive-data audit](../architecture/access-security-audit.md) (A5)
- [Open questions](../OPEN-QUESTIONS.md#q35--approval-evidence-exception-and-authority-ceiling-visibility)
