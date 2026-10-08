[← Decisions](README.md)

# 0100 — Distinguish independent quotation scopes from exclusive alternative groups

- **Status:** proposed — owner-reviewed R1-B3 business direction; not accepted/finalised.
- **Date:** 2026-10-08
- **Affects:** Phase 4 offer identity, alternative groups, acceptance and forecasting boundaries.
- **Gate:** research/design only; R1-B3 remains incomplete; no implementation/schema authorised.

## Context

One deal may include furniture and network installation, both independently acceptable. A premium
furniture offer competes with the standard offer; it is not an additional purchase by default.
Forcing each accepted scope into another opportunity fragments a genuine deal. Summing all
quotations, including alternatives and earlier revisions, inflates expected business.

Existing research is in [R1-B3](../phases/phase-4-r1-b3-offers-and-forecast.md). The owner reviewed
the scope/alternative direction below; forecasting is now under further investigation in the
[forecast research](../phases/phase-4-r1-b3-forecast-basis.md). No Sales capability exists yet.

## Decision — owner-reviewed proposed direction

One opportunity may have several accepted quotations when they represent independently
acceptable scopes. Acceptance belongs to an eligible **quotation revision**, not the opportunity.
Accepting one independent offer does not accept, reject or close other independent offers.
Opportunity outcome and job handover are separate R1-F/R6/R7 decisions; one accepted quote must
not automatically mark the whole opportunity won.

Quotations are independent unless explicitly assigned to an **exclusive alternative group**.
A group contains competing **quotation families**; each revision retains its family's group
relationship. Revisions are versions of the same offer, not additional independent offers.
An exclusive group normally permits acceptance of one competing family, including when two
users accept different offers concurrently. A second acceptance within the same family also
needs revision/accepted-scope controls; grouping alone cannot prevent duplicate commitment.

Example (PKR): furniture `500000.0000` accepted, installation `300000.0000` accepted, premium
furniture alternative `750000.0000` not selected. Accepted business is `800000.0000`, not
`1550000.0000`. The premium family and standard family are in one exclusive group; installation
is independent. Accepted value is commercial commitment, not invoiced/recognised revenue.

### Distinct responsibilities

- Opportunity: potential deal, owner, progress and forecast.
- Quotation family: one commercial offer across revisions.
- Quotation revision: version-specific scope/price/issue/acceptance evidence.
- Alternative group: explicit competing-offer relationship.
- CRM association: current opportunity organisation, with immutable issue-time history separately.
- Forecast: expected value under an explicit basis, not every linked quotation total.
- Acceptance: the customer's actual commitment under eligible offer evidence.

These meanings are not collapsed to save records. Their physical representation is not chosen.
Prefer an explicit extensible group relationship over pairwise `alternative_to_quotation_id`:
a three-way choice is one set, not asymmetric links or chains requiring transitive interpretation.
At most one competing family may supply a normal exclusive-group commitment. Exact cardinality,
revision eligibility and correction rules are review points below, not a completed schema.

### Group integrity and history

Validate company and customer/Party consistency across members and their deal association;
name/email similarity is not identity. R1-C settles recipient/prospect readiness and unbound
opportunity identity. R2/R9 must decide whether groups are opportunity-scoped, and how direct
quotes or later CRM relinking interact with membership. Do not assume a current CRM reassignment
moves an exclusive group or releases a commitment.

Record group membership changes with actor/time/old/new values and appropriate authority;
reason requirements are governed design work. Preserve the group relationship effective at
issue/acceptance separately from today's relationship. Never rewrite historical commercial
snapshots or silently move an accepted quotation between groups.

Grouping/regrouping after issue is **unresolved**. Any later permitted change needs a governed
transition with historical evidence, acceptance-conflict checks and review of frozen approval
facts; it is not an ordinary draft relationship correction under ADR 0099. Do not use temporary
ungrouping or CRM relinking to allow two competing acceptances. Until the governed rule is settled,
no post-issue regrouping or accepted-scope release is implied by this proposal.

Normal quotation UI remains simple; group controls appear only when relevant to offer choices
and actor authority. Independent-by-default does not certify scopes as non-overlapping: duplicate
copies or materially overlapping work need a visible warning/review policy in R2/R6. It also does
not prove independence of execution, such as installation requiring equipment supply.

## Architectural reconciliation

| Boundary | Consequence |
|---|---|
| Proposed ADR 0098 | Optional lead and configurable opportunity entry/issue remain. Alternative membership or acceptance cannot replace a required opportunity or erase a MANAGED exception. New revisions use effective issue controls. |
| Proposed ADR 0099 | Draft corrections and current CRM organisation remain separate from immutable issue linkage. Family grouping is not the same as CRM association; acceptance/group controls must survive later association changes. |
| Parties / ADR 0034 | Reuse Party identity and independent role accounts. Group consistency does not silently grant customer maintenance or change financial setup; R1-C remains open. |
| Invariants / ADRs 0070, 0085 | Issued/accepted evidence and relevant approval inputs stay reproducible. A group change that changes customer choices or governance cannot silently reuse approval for earlier facts. |
| Numbering / ADR 0079 | Group identity is not an official quotation number. Family/revision numbering is R2/R5 work through the existing engine; no second counter or renumbering on grouping. |
| Permissions / ADRs 0028, 0089 | Distinguish quotation editing, group management, acceptance, CRM association and forecast selection duties; exact vocabulary is R9. Company scope and visible record scope apply to all member lookups/changes, history and aggregates. Hidden competing members cannot make an exclusive group appear empty. |
| Jobs/outcome | Multiple accepted offers do not decide one-vs-many jobs, partial win, closure or cancellation. R6/R7 preserve commitment provenance and idempotent handover. |

### Concurrency and retries — required guarantees, physical design pending

Acceptance must atomically claim the eligible revision/family and its exclusive-group commitment
with customer decision evidence. Competing acceptances serialize on a stable group identity;
commit-time database invariants must reject conflicts even if both read "no accepted member".
Lock/version checks cover revision, group membership and relevant CRM/identity facts. Membership
edits and acceptance share a coordination protocol; an absence of a group row is not a locking
strategy. Independent acceptances remain independently valid without auto-closing other scopes.

Same completed request returns original commitment without duplication, subject to current access;
changed intent under one key is refused. Failure creates no accepted evidence/job/basis update.
A retry does not silently pick a newer revision, release the group, or mutate earlier commitment.
Stable lock order and append-only authoritative history follow domain-integrity rules. Exact
constraints/tables are R2/R6 implementation design, not fixed by this document.

## Consequences

- **Positive:** several real scopes can be won in one deal; explicit competing choices avoid
  accidental double commitment and false forecast addition.
- **Negative:** concurrency spans revision/family/group; independent-by-default still requires
  overlap awareness; post-issue regrouping and corrections need deliberate rules.
- **Requires:** visible grouping history and immutable historical context; exclusive-acceptance
  database proof; retry/race/rollback, company/scope and approval-evidence tests before delivery.

## Alternatives considered

**One opportunity per accepted quote** — rejected by owner: fragments related independent scopes.
**All offers exclusive by default** — rejected: requires unnecessary configuration for additive work.
**Pairwise alternative links** — not recommended: asymmetric/transitive ambiguity for several choices.
**Count revisions as separate offers** — rejected: versions are not new demand.
**Accept one quote, close every offer/deal** — rejected: remaining independent scope may still be sold.
**General dependency engine now** — deferred: equipment/installation prerequisites need R2/R6
business cases before choosing conditional acceptance or execution constraints.

## Research basis

Reuse [R1-B3 primary comparison](../phases/phase-4-r1-b3-offers-and-forecast.md#primary-source-comparison),
accessed 2026-10-08: Dynamics revisions and distinct deal value, Odoo multiple quote paths and
ERPNext item alternatives/ordered provenance. None supplies a universal alternative-family rule.
Independent-by-default groups and multiple accepted scopes are the owner's reviewed direction;
concurrency/history implications follow project invariants. [Forecast research](../phases/phase-4-r1-b3-forecast-basis.md)
distinguishes vendor behaviour from proposed combination selection; its recommendation is not accepted.

## Open questions — do not finalise implicitly

Grouping/regrouping after issue; group scope/direct quote membership; partial acceptance within a
quote; changing/cancelling accepted scope and releasing an exclusive claim; dependencies between
offers; partially won outcomes; one vs multiple jobs; acceptance/closure interactions; forecast
recalculation, eligibility, FX and as-of policy. Exact family/revision association and number shape
remain R2/R5. These questions remain in Q39 and their owning research topics.

## Related

- [R1-B3](../phases/phase-4-r1-b3-offers-and-forecast.md) · [Forecast research](../phases/phase-4-r1-b3-forecast-basis.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [ADR 0098](0098-hybrid-sales-opportunity-requirement.md) · [ADR 0099](0099-separate-quotation-issue-and-crm-association.md)
- [Sales pipeline](../domain/sales-pipeline.md) · [Domain integrity](../architecture/domain-integrity.md)
