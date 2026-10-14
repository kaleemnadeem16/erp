# 0127 — Record customer decision evidence and atomic business claims

- **Status:** accepted
- **Date:** 2026-10-14
- **Affects:** Phase 4 Sales customer decisions; R5/R7–R9 integration

## Context

Owner approval of the final C1–C8 review under planning Q46 settles customer acceptance architecture.
Issued quotation, customer agreement and operational targets must retain distinct immutable evidence.
This records business architecture only; it authorises no application implementation.

## Decision

C5/C7 are accepted. Company-governed legitimate sources include PO, signatures, emails, portal
confirmations and appropriately recorded verbal instructions. Distinguish customer representative
identity/authority, employee recording authority, seller approval and operational readiness.
Record actual occurrence with its precision/time-zone evidence separately from server recording time.
Ordinary acceptance requires verified consent and actionable scope at the authoritative decision instant.

Late, disputed or contradictory evidence may be a pending observation. Recognition requires separate
governed reconciliation of historical eligibility, current competing claims and operational commitments.
No unrestricted backdating, reopening, automatic reversal or invented later consent. Unsupported cases
remain pending; unverified observation alone creates no acceptance, claim reservation or operational hold.

A stable company/Party customer-decision case spans employees, transport keys, document versions and
quotation revisions. Linked consent components distinguish genuine new purchases/releases from repeated
recording or amendments. No-reference ambiguity requires shared intake and duplicate review; a UUID,
matching amount or evidence file hash alone cannot prove new business or a duplicate.

Coordinate decision identity, idempotency receipts, current-offer and original logical-scope capacities,
option selection, exclusive claims and entitlement consumption in one transaction under R5's writer
protocol. Every conflicting writer participates, including regrouping, withdrawal and replacement;
absence of a claim row is not a lock. Same business decision with fresh keys cannot purchase twice.
Preserve separately authorised duties for recording, late reconciliation, accepted-business correction,
quotation issue and handover. R9 must settle names, grants, read eligibility and effective record scope;
ordinary editors/managers do not acquire sensitive duties by implication. Guard Request and Action,
company and independent legitimate source access, with safe projections and currently authorised retries.
R8 delivery evidence and R7 claim-release/commitment correction remain separately governed.

## Consequences

- **Positive:** real customer commitments remain attributable without invented terms or duplicate sales.
- **Negative:** supported acceptance requires explicit evidence and coordinated claims; ambiguous or
  unsupported cases stay pending/refused rather than being guessed into a successful transaction.
- **Requires:** the [domain contract](../domain/sales-customer-decisions.md), staged
  [delivery specification](../phases/phase-4-r6-implementation-slices.md), separately approved R7/R9
  dependencies and code authorisation before delivery. Documentation is not implementation verification.

## Alternatives considered

Transport idempotency alone — fresh keys/employees still duplicate one purchase.
Reject all late evidence or allow free backdating — loses real history or bypasses current claims.
Automatic release on Job cancellation — operations cannot silently erase commercial commitments.
One generic quotation-manage duty — collapses independently sensitive business responsibilities.

## Research basis

Reuse the [final C1–C8 comparison and accepted foundation](../phases/phase-4-r6-customer-decisions.md),
[allocation examples](../phases/phase-4-r6-allocation-and-journey.md) and
[evidence/claims research](../phases/phase-4-r6-evidence-and-claims.md), completed2026-10-14.
The linked R5 primary comparison (Dynamics frozen quote/order conversion and ERPNext remaining-quantity/
alternative mapping, accessed2026-10-12) supports separate offer, decision and target identities.
It does not supply our consent, entitlement or statutory rules; those follow the explicit owner approval.
No settled R1–R5 choice is reopened.

## Related

- [Decision index](README.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md)
- [Quotation lifecycle](../domain/quotation-lifecycle.md)
