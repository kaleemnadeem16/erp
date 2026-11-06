# 0164 — Order discount authority locks before quotation and approval records

- **Status:** proposed (coordinator review required before integration)
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
and revisions → Tax generation where required → approval requests/steps → issue/numbering records.
One company authority head serialises policy/reference activation and assignment changes; this
intentionally conservative foundation does not introduce independent locks with an inverse order.

| Path | Required order and revalidation |
|---|---|
| Authority/reference/mode configuration | Security fence, authority head, affected version/event rows; append event and advance generation atomically |
| Draft create/edit/cancel/revise | Security fence, authority head, family/revision in existing access order, line evidence; invalidate approvals after content changes |
| Sales approval request/decision/withdraw | Security fence, authority head, family/revision, request/steps; compare live fingerprints inside the transaction |
| Generic engine decision for a quotation | Optional subject transaction hook wraps the whole operation before any request lock/preflight; same security/head/family/revision order |
| Issue/replacement | Security fence, authority head, family/revisions (ascending ids for a pair), Tax fence, approval, issue/numbering; freeze exact evaluation with issue |
| Policy/authority mutation reached outside Sales | Database writer barrier before mutation, matching the security/head order; no raw SQL writer may bypass it |
| Purchasing/Estimating | No quotation-specific selection, eligibility, expiry or generation semantics; retain existing consumer contracts |

The head is company-scoped, created under the security fence, and must exist before checking for
an absent policy/assignment. Inserts and activations therefore cannot race a cached absence.
Acquire each head once in an outer transaction; nested Sales/engine calls are re-entrant. Do not
acquire the security/head locks for the first time from a row-level AFTER trigger or from a
callback entered after the request lock. Protect raw configuration writes with statement-level
barriers; immutable rows reject mutation. A decision must compare fresh routing again inside its
final locked transaction, not only in preflight. Approval eligibility is judged live, including
employee/role/unit access and preparer/requester exclusions.

Replays check current access before returning the original receipt; they do not generate new
evidence or rewrite old policy/reference versions. Bounded waits and whole-operation deadlock/
serialisation retries retain QuotationOperation's existing semantics. Tests use separate real
connections/processes to demonstrate both orderings of policy change versus decision/issue.

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
IssueQuotationReplacement inspected on local develop at 46858b98. Business rules remain in
[0163](0163-govern-quotation-discount-authority-with-independent-reference-evidence.md) and ADR 0139.

## Related

- [Domain specification](../domain/quotation-discount-authority.md)
- [Domain integrity](../architecture/domain-integrity.md)
- [Decision index](README.md)
