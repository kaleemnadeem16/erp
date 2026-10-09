[← Decisions](README.md)

# 0099 — Separate quotation issue evidence from current CRM association

- **Status:** proposed — R1-B1/B2 owner-reviewed direction; not accepted or finalised.
- **Date:** 2026-10-08
- **Affects:** Phase 4, quotation relationship changes, CRM association, audit and approvals.
- **Scope:** business relationship rules only; no physical schema chosen; R1-B is not complete.

## Context

A draft may be created before the correct deal is known or linked to the wrong opportunity.
An issued direct offer may later become part of a tracked deal. A single editable opportunity
field cannot truthfully represent both the relationship at issue and today's CRM organisation.
The ERP has Parties, capabilities, audit, approvals and numbering foundations, but no implemented
quotation/opportunity lifecycle or Sales association/history model.

## Decision — owner-reviewed proposed direction

### R1-B1: draft relationship corrections

Allow explicit link, unlink and relink while the quotation is DRAFT. Validate company isolation,
Party/customer consistency, opportunity eligibility and actor permissions/scope. Record actor,
timestamp, old and new relationship. Ordinary corrections need no written reason unless an
applicable governance rule requires one. Relationship history must remain explainable.

Linking is not a copy/import operation: do not overwrite recipient, scope, prices, contacts,
dimensions, material plan or estimating evidence. If opportunity context participates in pricing,
approval routing/review or another dependency, revalidate those facts and mark affected work
stale/invalidate approval as appropriate. Do not blindly carry a prior approval onto changed
review facts, or force repricing for a relationship that has no pricing effect. The exact Sales
adapter/fingerprint inputs are R3/R5/R9 work.

### R1-B2: association after issue

Keep the issue-time opportunity identity or explicit absence immutable with the commercial
snapshot and policy/exception evidence. Maintain a separately governed current CRM association.
Authorised post-issue association changes require a reason and actor/time/old/new history, plus
the same company, identity and opportunity eligibility validation.

An association may not change issued amounts, scope, tax, FX, approvals, recipient or frozen
estimating evidence; it may not retroactively satisfy an opportunity requirement or erase a
MANAGED exception under proposed ADR 0098. Linking does not mark a deal won, change its forecast,
create a job, reserve stock or copy quotation totals into revenue. Such operations require their
own explicit business decisions and controls.

Example: QT-100 was issued on 1 October for `500000.0000` PKR without an opportunity. On
10 October an authorised person links it to OP-105 with a reason. Its issue snapshot still says
"no opportunity" and retains the issue policy/exception; current CRM says OP-105; relationship
history explains when and why. No document number or commercial revision is generated just to
organise CRM. The opportunity is not automatically worth an additional `500000.0000` PKR.

## Architectural reconciliation

| Boundary | Reconciliation / design question |
|---|---|
| Proposed ADR 0098 | One quotation workflow remains. FLEXIBLE accepts direct issue; MANAGED requires a link or its narrow authorised issue exception; STRICT requires a link at creation and issue. Draft corrections do not grant issue bypass; later association cannot cure historical non-compliance. |
| STRICT draft unlinking | R1-B1 permits ordinary draft corrections, including unlinking an existing draft. Recommended interpretation: temporary absence makes it not ready to issue; new creation remains guarded. Whether STRICT also requires atomic replacement instead of temporary unlinking is an explicit review point, not an assumed relaxation. |
| Party/customer boundary | Compare legal Party identity, not name/email or an unqualified lead label. A CustomerAccount refers to its Party; supplier/customer roles remain independent. R1-C must settle prospect recipients and what to do when an opportunity's identity is unknown; linking must not create financial setup silently. Same-party consistency does not imply that archived/ineligible accounts are usable. |
| Authorisation/visibility | Request and Action guard both association duty and scope of quotation plus old/new opportunities. Reuse a suitable ordinary quotation update duty for drafts if it fits; post-issue association is a separate governed duty, not commercial-edit authority. Exact vocabulary and no-longer-visible previous-link handling remain R9. Protect lookups, nested reads, history, counts and exports too. |
| Issue immutability | Keep governed association outside immutable issue evidence. A single mutable field used by both historical and current reports would conflict with invariants 1/14. Generic activity logs alone do not provide authoritative historical association evidence. |
| Approvals | ADRs 0070/0085 invalidate when relevant routing or frozen review facts change, even if totals do not. Draft link changes must consider this. Issued association does not rewrite the approval for original issue; any additional CRM governance is separate. |
| Revisions/numbering | Association history is not quotation revision history and does not allocate an official number. Revisions get their own issue evidence under effective policy. R2/R5 decide family/revision identity and inherited links, using ADR 0079 rather than the old sequences sketch. |
| Closure/handover | Association does not imply won/lost or customer acceptance. Accepted/job-linked, rejected, withdrawn and superseded record restrictions remain R5/R6/R7; do not assume the post-issue permission makes all such states editable. |

### Storage alternatives — no choice finalised

1. **Governed association stream**, with current association derived from the latest event.
   Strong historical reconstruction; latest/as-of queries need deliberate indexes.
2. **Separate current association plus append-only history**, updated atomically.
   Simple operational queries; consistency between projection/history must be enforced structurally.
3. **Mutable quotation opportunity field plus issue snapshot and generic activity log.**
   Fewer apparent concepts, but risks touching frozen rows and losing authoritative relationship
   history. Not recommended without a distinct governed projection and integrity guarantees.

Recommendation: separate association evidence from commercial issue; use an append-only event
pattern with a derived or transactionally maintained current projection. The project's immutable
policy/evidence histories provide patterns, not a requirement for new tables. R2/R5/R9 decide
whether the association attaches to a quotation family or each revision and its physical form.
Multiple simultaneous current opportunities for one quote is not assumed or approved.

### Integrity, retries and reporting

An association write must commit relationship and audit evidence together through one Action.
Lock/re-read quotation and relevant opportunity/identity state in a stable order; use an expected
current association/document version to refuse stale changes rather than silently win a race.
Concurrent issue vs draft correction must serialize: if issue wins, a stale draft edit cannot
become a post-issue update; if the draft correction wins, issue checks the new link and policy.
Opportunity identity/stage changes need corresponding lock/version validation; locking only the
quotation is insufficient. Exact locks/authority coordination are implementation-design work.

Company/actor-scoped idempotency binds intended old/new link, document/version and reason when
required. Same completed retry returns original evidence subject to current authority; changed
intent is refused. Failure produces no successful association/history. A no-op does not create
a false relationship change. Preserve history even after reassignment/archiving.

Reports must explicitly select issue-time, current or as-of CRM association. Today’s relinking
must not shift historical pipeline figures invisibly. Scope-sensitive totals must not leak hidden
quotes/opportunities. R1-B3 defines forecast semantics; R9 defines report grain, audience and
currency policy. No automatic recalculation on linking is accepted.

## Consequences

- **Positive:** correct ordinary draft errors; organise issued offers without rewriting history.
- **Negative:** two relationship meanings need clear UI/report labels and governed consistency.
- **Requires:** tenant/identity/scope tests, dependency invalidation, reasoned post-issue audit,
  stale/concurrent edit-vs-issue tests, retry/rollback tests and unchanged commercial snapshots.

## Alternatives considered

**Freeze every CRM link forever at issue** — rejected by owner direction: blocks legitimate
later deal tracking. **Edit the issue-time link** — rejected: rewrites compliance/history.
**Require a reason for every draft correction** — rejected unless another governance rule applies.
**Automatically copy opportunity facts or recalculate forecast** — rejected: hidden business changes.
**Automatically revise a quote for CRM linkage** — rejected: relationship organisation is not a
new commercial offer. **Treat link permission as commercial-edit or issue bypass** — rejected.

## Research basis

Reuse [R1's primary-source comparison](../phases/phase-4-r1-leads-and-relationships.md#primary-source-comparison),
[proposed ADR 0098](0098-hybrid-sales-opportunity-requirement.md) and the
[R1-B3 comparison](../phases/phase-4-r1-b3-offers-and-forecast.md) accessed 2026-10-08.
Vendor documents support multiple quote paths and distinct CRM revenue, but do not establish our
separate immutable issue/current-association contract. That separation follows owner direction
and project invariants. No vendor schema or post-issue mutability is copied.

## Still unresolved

STRICT temporary unlink interpretation; unknown/opportunity Party identity rules (R1-C);
closed opportunity eligibility and accepted/job-linked association restrictions (R5/R6/R7);
association scope per family/revision (R2); permissions/visibility and report as-of policy (R9).
R1-B3 independent accepted scopes and explicit exclusive groups now have owner-reviewed direction
in [accepted ADR 0100](0100-independent-quotation-scopes-and-alternative-groups.md). Post-issue
grouping/accepted-scope corrections remain open in R2/R6. Forecast business direction is now
accepted in ADRs 0101/0102; see the [bounded reconciliation](../phases/phase-4-r1-b3-reconciliation.md).
No whole R1-B completion claim.

## Related

- [R1](../phases/phase-4-r1-leads-and-relationships.md) · [R1-B3](../phases/phase-4-r1-b3-offers-and-forecast.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [Parties](../domain/parties.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [Domain integrity](../architecture/domain-integrity.md) · [Authorisation](../architecture/authorization.md)
- [ADR 0098](0098-hybrid-sales-opportunity-requirement.md) · [ADR 0079](0079-document-numbering-system-templates-over-a-variable-registry.md)
