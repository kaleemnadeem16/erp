# 0164 — Order discount authority locks before quotation and approval records

- **Status:** proposed (owner direction 2026-11-12: acceptance only after independent concurrency review and shared Approvals review; neither complete)
- **Date:** 2026-11-06
- **Affects:** Sales quotation writers and authority configuration; opt-in Approvals transaction boundary

## Context

QuotationOperation already takes CompanyAccessFence before family/revision locks. Generic approval
decisions lock a request independently. Policy activation and authority revocation must not race a
quotation's use of their evidence. Taking an authority lock only inside routing or eligibility is
too late: those helpers can be reached with a revision or request already locked.

## Decision

Preserve the existing security fence as the outermost lock. For discount-authority operations the
order is security fence → company authority head (policy/reference/assignment generation) → family
and revisions → Company row (shared; Sales financial-policy fence, issue only) → Tax generation where
required → approval requests/steps → issue/numbering records.
One company authority head serialises policy/reference activation and assignment changes; this
intentionally conservative foundation does not introduce independent locks with an inverse order.

| Path | Required order and revalidation |
|---|---|
| Authority/reference/mode configuration | Security fence, authority head, affected version/event rows; append event and advance generation atomically |
| Draft create/edit/cancel/revise | Security fence, authority head, family/revision in existing access order, line evidence; invalidate approvals after content changes |
| Sales approval request/decision/withdraw | Security fence, authority head, family/revision, request/steps; compare live fingerprints inside the transaction |
| Generic engine decision for a quotation | Optional subject transaction hook wraps the whole operation before any request lock/preflight; same security/head/family/revision order |
| Issue/replacement | Security fence, authority head, family/revisions (ascending ids for a pair), Company row (shared), Tax fence, approval, issue/numbering; freeze exact evaluation with issue |
| Policy/authority mutation reached outside Sales | Database writer barrier before mutation, matching the security/head order; no raw SQL writer may bypass it |
| Purchasing/Estimating | Generic decision: access fence (shared) → document lock (PO header, then lines by id) → request/steps. No authority head is taken today (the 0182 head-first order is a target, not current code). Configuration writes reach these paths only through the statement barrier. No quotation-specific selection, eligibility, expiry or generation semantics |

The head is company-scoped, created under the security fence, and must exist before checking for
an absent policy/assignment. Inserts and activations therefore cannot race a cached absence.
Acquire each head once in an outer transaction; nested Sales/engine calls are re-entrant. Do not
acquire the security/head locks for the first time from a row-level AFTER trigger or from a
callback entered after the request lock. Protect raw configuration writes with statement-level
barriers; immutable rows reject mutation. A decision must compare fresh routing again inside its
final locked transaction, not only in preflight. Approval eligibility is judged live, including
employee/role/unit access and preparer/requester exclusions.

### Concrete engine boundary (2026-11-09, proposed for owner review)

`TransactionalApprovalSubject` wraps submission, decision and withdrawal before engine preflight
or a request lock; Sales takes `CompanyAccessFence::lockAll()`, the authority head, then the
revision and rehydrates the actor there. The final locked decision repeats evidence and expiry
checks. `QuotationOperation` takes the same head before its subject closure, including OFF mode:
absence and later activation must be serialised too. Readiness takes this order explicitly.

The statement barrier on shared policy, condition, step, setting, authority and delegation tables
uses the existing authority writer barrier. It conservatively takes security and authority heads
in company order before any affected row, including statements spanning consumer types. Only Sales
uses the resulting generation, strict selection and request-wide expiry; other consumers retain
their business selection semantics. The conservative contention is intentional and reviewable.
Row-level callbacks must never be the first acquisition point. Approval configuration writes may
invalidate open Sales evidence even when the changed consumer differs; this is a safe foundation
trade-off, rather than a claim of fine-grained dependency tracking.

A transaction holding the shared access row must not write a barrier table (that would be a lock
upgrade and can deadlock). Sales read and evaluate paths take `lockAll` exactly like writers.

Replays check current access before returning the original receipt; they do not generate new
evidence or rewrite old policy/reference versions. Bounded waits and whole-operation deadlock/
serialisation retries retain QuotationOperation's existing semantics. Existing two-session tests cover Sales decisions versus suspension, permission revocation,
employee-authority revocation and source edits. Two-session tests for approval-policy activation
versus a Sales decision, a Sales issue and a PO decision (both orderings) are being added in the
Phase 4 closure batch and are not yet claimed as existing.

## Consequences

- **Positive:** one published order, no unprotected absence and no generic-decision side door.
- **Negative:** configuration and affected quotation operations serialise within a company;
  the existing security fence is already conservative across companies.
- **Requires:** an explicit opt-in engine hook, complete writer inventory, database barriers and
  concurrency regression. Coordinator review includes shared Action/trigger reservations before
  integration; a proposed ADR is not permission to change unrelated consumer behaviour.

## Alternatives considered

**Lock from eligibility** — inversion when the engine already holds a request. **Only lock existing
policies** — inserts race absence. **Use a fingerprint without fencing writers** — leaves a check/
commit gap. **Lock all new tables independently** — more lock permutations without an established
throughput need. **Replace CompanyAccessFence** — expands scope and risks existing security races.

## Research basis

Local transaction design based on CompanyAccessFence, QuotationOperation, DecideApproval and
IssueQuotationReplacement re-inspected against develop at 528e40b1 (independent review 2026-11-12). Business rules remain in
[0163](0163-govern-quotation-discount-authority-with-independent-reference-evidence.md) and ADR 0139.

## Decision-time review addendum (2026-11-10)

Generic decisions take the company access fence before a review provider's document lock,
then the request/steps. The PO review provider locks its header and lines in id order before
any request lock. The final transaction reloads the actor and delegated principal and repeats
evidence/expiry checks. It commits a recorded invalidation or expiry before returning a refusal.
Sales retains its existing outer security/head/family/revision transaction hook.

## Acceptance condition (owner direction, 2026-11-12)

This ADR stays **proposed**. It may be accepted only after (a) an independent concurrency review of the lock order and the writer barrier, and (b) a shared Approvals review confirming the
optional subject-transaction hook and generic decision path. Acceptance additionally requires the
two-session policy-activation tests above to pass on one commit.

Status of the reviews: independent concurrency review (Reviewer C, 2026-11-12, develop 528e40b1):
no lock inversion found; the policy-activation two-session tests are outstanding. Shared Approvals
review: outstanding (Purchasing/Estimating approval suites not yet run for this purpose).

## Related

- [Domain specification](../domain/quotation-discount-authority.md)
- [Domain integrity](../architecture/domain-integrity.md)
- [0163 discount authority](0163-govern-quotation-discount-authority-with-independent-reference-evidence.md)
- [0158 concessions through Approvals](0158-approve-quotation-concessions-through-the-shared-approvals-engine.md)
- [0182 PO reapproval](0182-reapprove-unissued-purchase-orders-by-materiality-when-a-stricter-policy-takes-effect.md)
- [0185 company configuration](0185-configure-company-business-values-and-fix-technical-invariants.md)
- [Decision index](README.md)
