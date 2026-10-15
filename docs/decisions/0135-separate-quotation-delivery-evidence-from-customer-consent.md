[← Decisions](README.md)

# 0135 — Separate quotation delivery evidence from customer consent

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Phase 4 R8 documents/delivery/customer evidence; R5/R6/R9 integration
- **Authority:** owner approval of D1–D8 with clarifications after local `b3dae82`; documentation/architecture only, no application code authorisation.

## Context

R1–R7 separate issued commercial facts, actual agreement and operational work. Existing PO
Quick Print is useful presentation precedent, not a formal immutable quotation archive.
The owner approved the reviewed R8 matrix with explicit issue/rendering and channel boundaries.

## Decision

D6–D8 are accepted. Generation, dispatch intent, delivery attempts and outcomes are separate
records. Capture channel, recipient snapshot, artefact identity/hash, actor, dates and provider
identifiers. Queued, submitted, provider accepted, failed, unknown, actual receipt evidence and
acknowledgement have distinct meanings. Print/download proves only the recorded local action.
Failed delivery never undoes valid commercial issue.

Initial delivery is authenticated download/print and supported manual recording. Email follows
only with approved credentials/provider adapter and durable retry/reconciliation support. Unknown
provider result cannot become an automatic fresh send; exactly-once email is not assumed.

Receipt acknowledgement, document signature and commercial consent are typed distinct evidence.
Only an R6-authorised customer-decision Action establishes accepted business after checking exact
revision, scope, quantities, choices, terms, representative authority and evidence. Opening,
downloading or signing alone cannot accept a whole quotation. Preserve legitimate manual/email/PO
consent; no universal e-signature or portal prerequisite. Future signature/portal capabilities
require their own identity/security/evidence contracts.

Company/path policies may require exact document/delivery evidence for an acceptance method;
another legitimate authorised channel need not prove email or portal delivery. Use0079 original
official quotation number; artefacts/attempts have technical IDs. Freeze brand/number/date and
currency/tax presentation. English/LTR under0026 is sufficient initially; multilingual/RTL,
statutory language, retention and public portal capabilities require justified extensions.
Withdrawal, expiry, resend and replacement preserve original bytes and truthful current availability;
resend cannot reactivate an expired offer or create fresh consent.

## Consequences

- **Positive:** reproducible commercial meaning, preserved original evidence and truthful channel outcomes.
- **Negative:** storage/recovery, pagination and secure projection require real delivered adapters.
- **Requires:** [domain contracts](../domain/quotation-documents.md), [delivery evidence](../domain/quotation-delivery-evidence.md), independently approved R9 and separately authorised implementation.
- **State:** ACCEPTED architecture; SPECIFIED contracts; no R8 application capability IMPLEMENTED/VERIFIED by this record.

## Alternatives considered

**One sent flag** — conflates issue, transport submission and receipt.
**Automatic fresh send after timeout** — duplicates messages when provider already accepted.
**Any signature/view equals acceptance** — invents scope, authority and customer consent.
**Universal email/portal proof prerequisite** — rejects legitimate consent via other channels.
**Resend extends validity** — changes a commercial offer without governed successor authority.

## Research basis

Reuse [R8 D1–D8 comparison](../phases/phase-4-r8-documents-review.md), primary ERPNext print-format/
quotation and Dynamics template/PDF export documentation accessed2026-10-14. Both support professional
presentation and explicit sharing; builder/script versus Word-template choices differ. Our frozen
archive, commercial issue independence and R6 consent boundaries follow accepted authority and owner
approval, not vendor lifecycle defaults. [Existing printing](../architecture/appearance-and-printing.md),
0123/0127 and0079 are reused; no historical accepted ADR body is changed.
Security implementation also follows the [OWASP upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html),
accessed2026-10-15: type/size/name validation, private storage and authorised retrieval; this is a
layered project security requirement, not a claim that scanning alone proves a file safe.

## Related

- [Decision index](README.md) · [R8 slices](../phases/phase-4-r8-implementation-slices.md)
- [R5 issue](0123-issue-numbered-quotation-revisions-atomically.md)
- [R6 evidence](0127-record-customer-decision-evidence-and-atomic-business-claims.md)
