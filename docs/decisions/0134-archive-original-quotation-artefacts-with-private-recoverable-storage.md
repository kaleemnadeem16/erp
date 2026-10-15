[← Decisions](README.md)

# 0134 — Archive original quotation artefacts with private recoverable storage

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Phase 4 R8 documents/delivery/customer evidence; R5/R6/R9 integration
- **Authority:** owner approval of D1–D8 with clarifications after local `b3dae82`; documentation/architecture only, no application code authorisation.

## Context

R1–R7 separate issued commercial facts, actual agreement and operational work. Existing PO
Quick Print is useful presentation precedent, not a formal immutable quotation archive.
The owner approved the reviewed R8 matrix with explicit issue/rendering and channel boundaries.

## Decision

D4–D5 are accepted. Preserve the original official PDF bytes, hash, immutable artefact identity
and generation provenance. Generate original only from pinned issued facts; no live Company,
Party, Item, pricing or template lookup. Generation retries are idempotent. Later layout/renderer
repair appends a separately identified rendition; it never replaces original bytes or prior
delivery evidence. Contractual change requires governed successor/revision. Do not promise
byte-identical rerender without verification. Specify storage/database reconciliation and recovery
before implementation; missing/corrupt original is an incident, not permission to manufacture it.

Use an explicit server allowlist projection shared by PDF, print, download, email and future
portal. Exclude protected costs/margins/stock valuation, approval notes, confidential competing
terms, internal pricing calculations and restricted evidence. Frontend hiding, template visibility
and internal file path possession confer no permission.

Artefacts are private and company-scoped, with independently authorised file access. Require
validated attachments, secure upload/download, safe filenames, restricted renderer execution,
tenant-safe storage/cache and protected logs. Initially authenticated application download is
supported; public links and portal tokens need later governed contracts. R9 finalises duties,
effective scope and grants before public enablement.

## Consequences

- **Positive:** reproducible commercial meaning, preserved original evidence and truthful channel outcomes.
- **Negative:** storage/recovery, pagination and secure projection require real delivered adapters.
- **Requires:** [domain contracts](../domain/quotation-documents.md), [delivery evidence](../domain/quotation-delivery-evidence.md), independently approved R9 and separately authorised implementation.
- **State:** ACCEPTED architecture; SPECIFIED contracts; no R8 application capability IMPLEMENTED/VERIFIED by this record.

## Alternatives considered

**Data-only archive with live rerender** — cannot prove original customer bytes.
**Overwrite original after repair** — destroys provenance and transmission evidence.
**CSS-hidden costs / public storage paths** — bypasses server projection and file authority.
**Promise byte-identical reproduction from snapshots** — ignores renderer/font/metadata variability.

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
