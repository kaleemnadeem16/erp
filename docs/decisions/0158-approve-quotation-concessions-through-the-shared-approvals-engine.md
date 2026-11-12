# 0158 — Approve quotation concessions through the shared Approvals engine

- **Status:** proposed (owner acceptance pending; implemented on `develop` under the owner's task authorisation)
- **Date:** 2026-11-05
- **Affects:** Sales (quotation draft, issue), Approvals (eligibility hook), [sales-pricing](../domain/sales-pricing.md), [approvals](../domain/approvals.md)

## Context

[0154](0154-record-draft-concessions-tax-declarations-and-list-values-as-versioned-per-row-facts.md) records, per line and per document discount, that a concession "needs approval" (an
override always; a discount unless a published reduction limit proves it within limit) and blocks issue with `APPROVAL_REQUIRED` because no approval could be given. The shared
[Approvals engine](0070-shared-configurable-approval-engine.md) now exists (Purchasing, Estimating). The docs did not say how a quotation uses it, who may decide, or what an
approval is bound to, so the safest default-deny choices were made and are recorded here.

## Decision

1. **The subject is one draft revision.** `QUOTATION_REVISION` is registered with the engine by Sales. The request is made from the *recorded* need; it routes on the kinds of concession
   the draft carries (`concessions`: DISCOUNT, OVERRIDE, DISCOUNT+OVERRIDE) and its currency. No amount is routed (no base-currency evidence for a concession exists), so a step that
   uses an authority ceiling can never be satisfied: thresholds are not guessed.
2. **Approval is bound to the exact draft.** The engine's hashed facts carry the draft's input generation and a content fingerprint (`QAF.1`: every pricing, concession, recipient,
   currency, rounding and FX-evidence field of the stored draft). Any edit ends a pending or granted approval (history is kept) and a request whose content hash no longer matches the
   draft is simply ignored by readiness and issue (the standing is derived, never stored). A rejected draft cannot be re-requested unchanged.
3. **Fail closed when unconfigured.** The engine reads "no mode row" as NONE and returns no request; for a quotation whose need is recorded that must not become a pass. Requesting
   is refused (`APPROVAL_NOT_CONFIGURED`) unless the company has set the subject type to POLICY or REQUIRED *and* an active policy applies. Readiness and issue never consult the mode: a
   recorded need is cleared only by an approval for exactly this draft.
4. **Who may decide.** The engine's rule (capability `sales.quotation.approve`, policy step, authority ceiling) is unchanged, and a new optional engine hook
   (`RecordScopedApprovalSubject`) lets the owning module add its record policy to the single eligibility every route shares - the inbox, the generic decision route and Sales' own
   Action. Sales answers: the quotation-family policy for `sales.quotation.approve` with the independent `sales.quotation.view` (OWN or ALL; a hidden record is a non-disclosing 404), and
   never the person who created the draft, entered a concession on it or requested the approval, whatever the policy's own submitter setting says. `sales.quotation.approve` is a new STAGED
   sensitive duty (it moved from SPECIFIED); nothing activates or grants it.
5. **No delegation until the owner decides.** A delegate is never eligible for a quotation approval (the hook refuses `delegated`).
6. **Consumed at issue, frozen afterwards.** The issue assessor lifts `APPROVAL_REQUIRED` only for an approval standing for the exact draft (renaming a waiting or rejected one to
   `APPROVAL_PENDING` / `APPROVAL_REJECTED`); every other blocker, including a failed mandatory control, is independent. `IssueQuotation` locks that request, and the append-only issue
   records `approval_request_id` and the approved content fingerprint beside the already frozen line reductions. The customer document carries no approval word.
7. **Operations** (`RequestQuotationApproval`, `DecideQuotationApproval`, `WithdrawQuotationApproval`) run through the existing governed operation: company access fence, revision lock,
   `expected_generation`, idempotency key with exact-intent replay. A decision, a rejection reason and a withdrawal reason are the engine's append-only facts.

## Consequences

- **Positive:** one definition of "may decide" for every route; a changed draft can never ride on an earlier approval; the issued record proves which approval authorised its reductions;
  the preparer can never self-approve; nothing is activated.
- **Negative:** thresholds by discount size are not possible yet (needs a base-currency concession measure and an owner decision); approvals do not expire unless a policy step sets a
  deadline; delegation is refused; a limit published after a draft was saved is judged live at readiness but the stored flag is only refreshed on the next save.
- **Requires:** an administrator to set the subject type's mode, an active policy and authorities; the holder recipe for `sales.quotation.approve` is an owner decision.

## Owner direction 2026-11-12 (proposal)

- Retained: approval is bound to the exact draft revision (hash of facts and content fingerprint) and maker/checker protection (the preparer, concession entrant or requester never decides).
- Later semantics from [0163](0163-govern-quotation-discount-authority-with-independent-reference-evidence.md) (governed discount authority) apply to quotation approvals only for a company that
  has an approved company-level activation of that policy: the **14-calendar-day default expiry, capped by the quotation's validity**, belongs to that governed policy and is not a property of
  this ADR's engine contract. It is not in force by default, and nothing here activates it.
- **Company-configurable expiry is planned work, not delivered behaviour** (owner clarification D11, 2026-11-12): the 14-day default and the cap are what 0163 specifies; a company-chosen
  period needs its own governed configuration under [0185](0185-configure-company-business-values-and-fix-technical-invariants.md) once authorised.
- Retained: maker/checker protection and exact-draft binding. Purchasing and Estimating approval semantics are not altered; no unrelated behaviour of the shared Approvals engine changes.
  Where this note and 0163 differ, 0163 governs; the statement above that approvals do not expire unless a policy step sets a deadline remains true until a company's 0163 activation applies.

## Alternatives considered

**Store approval state on the revision** - a second source of truth that can drift from the engine's append-only decisions. **Route the discount amount** - needs base-currency conversion
evidence and the owner's thresholds. **Honour the engine's allow-submitter option** - would let a preparer approve their own concession. **Treat mode NONE as "no approval"** - the
recorded need would pass unexamined.

## Research basis

Local technical decision refining accepted 0070, 0116 and 0154; no new external research.
