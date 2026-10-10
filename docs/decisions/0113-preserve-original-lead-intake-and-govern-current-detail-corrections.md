[← Decisions](README.md)

# 0113 — Preserve original Lead intake and govern current-detail corrections

- **Status:** accepted — owner-approved C, 2026-10-10
- **Date:** 2026-10-10
- **Affects:** Sales enquiry evidence, Lead detail corrections, legacy baselines and privacy

## Context

The CRM foundation edits NEW enquiries in place, including after revival. Its receipt event does
not hold all accepted enquiry details. Lead inherits an audit trait configured for fillable fields,
but declares guarded fields with no fillable list. Inherited generic audit therefore does not
establish the required original enquiry or field-change history. ADR 0105's preserved-intake
intent needs a durable explicit contract.

## Decision

Preserve immutable accepted-at-intake details; independently maintain corrected current Lead
details and reasoned field-level old/new evidence with actor and timestamp. Governed corrections
remain possible after qualification, disqualification or revival without changing lifecycle status
merely to edit a value. Lead remains enquiry evidence, not another authoritative Party/customer master.

Record the accepted normalised business inputs and their receipt/provenance context, not a secret
or arbitrary raw request dump. Commit evidence and corrected projection atomically; preserve
original and corrections through every lifecycle decision. Party/contact identity and financial
roles remain governed by their own operations. Current edits do not rewrite derived Opportunities,
issued documents, conversions or original identity evidence.

For preexisting rows, preserve actually available evidence and explicitly label a later-captured
baseline as such. Never fabricate original submission history, backdate capture or substitute a
current master value for the received input. Where original fields cannot be proved, show that
limitation. Existing generic audit is supplementary, not the authoritative source by assumption.

Use suitable existing Lead-management authority plus relevant scope for ordinary enquiry
correction; protect original/current/history consistently. This makes 0105/0108 preservation
operationally precise without changing Party authority, creating new lifecycle states or introducing
an application-wide audit replacement. Implementation schema/rollout follows the linked contract.

## Consequences

- **Positive:** current details remain useful and the enquiry history stays explainable.
- **Negative:** evidence stores personal details and needs the same scoped projections as current data;
  some legacy originals are irrecoverable.
- **Requires:** immutable baseline/intake evidence, reasoned correction history, database consistency,
  retry/stale/rollback tests and accurate additive per-company rollout.

## Alternatives considered

**Trust the current generic audit trait** — rejected: it does not capture this model's required fields.
**Freeze all current fields** — rejected: makes ordinary corrections impractical.
**Replace details after revival** — rejected: revival cannot erase received evidence.
**Copy current data as historic original** — rejected: invents submission history.
**Second Party/customer master** — rejected: enquiry and authoritative identity remain distinct.

## Research basis

Reuse [CRM policy comparison](../phases/phase-4-crm-policy-review.md#bounded-primary-comparison--accessed-2026-10-10),
accessed 2026-10-10. Dataverse supports configured field-change audit; inspected ERPNext v15 Lead
schema does not itself declare track_changes. Vendor tracking is not proof of immutable original
intake. Existing project Action/audit source inspection and domain-integrity standards distinguish
supplementary logging from authoritative evidence; the owner approved explicit preservation.

## Related

- [Implementation specification](../phases/phase-4-crm-correction-specification.md)
- [CRM correction domain](../domain/crm-corrections.md) · [Policy review](../phases/phase-4-crm-policy-review.md)
- [0105](0105-support-generic-party-identity-and-conversion.md) · [0108](0108-expose-a-progressive-basic-crm.md)
