[← Decisions](README.md)

# 0133 — Freeze customer quotation content and versioned presentation

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Phase 4 R8 documents/delivery/customer evidence; R5/R6/R9 integration
- **Authority:** owner approval of D1–D8 with clarifications after local `b3dae82`; documentation/architecture only, no application code authorisation.

## Context

R1–R7 separate issued commercial facts, actual agreement and operational work. Existing PO
Quick Print is useful presentation precedent, not a formal immutable quotation archive.
The owner approved the reviewed R8 matrix with explicit issue/rendering and channel boundaries.

## Decision

D1–D3 are accepted. Use professional company-branded, source-neutral, curated configurable
templates. Support recipient/company identity, official number/revision, dates/validity,
goods/services/Assembly/manual scope, sections, alternatives, quantities, prices, quote tax,
commercial totals and terms. Mandatory commercial information cannot be hidden by configuration.
Unrestricted scripting/arbitrary template execution is deferred.

Formal A4 PDF/print deliberately designs first and continuation pages: compact repeated headings,
page numbering, long-row continuation and protected final totals/terms. Avoid repeated large
recipient/letterhead blocks. Verify short and50+ Item quotations, multiline descriptions,
alternatives/optional services and multi-page terms, with readable typography/margins and no clipping,
split totals or repeated calculations. Values come from authoritative commercial calculations.

At official issue freeze allowlisted customer content, terms, template/branding/assets/locale
versions and render manifest. Commercial issue remains the authoritative R5 atomic transaction;
successful PDF generation is not a universal prerequisite. Rendering failure after issue leaves
the quotation issued, generation pending/failed, and official sending blocked until a verified
artefact exists. A governed prepared-artefact-before-issue path is an explicit policy, with exact
transactional fact checks, never the global default. Draft output is conspicuously DRAFT and
must not impersonate an officially issued actionable offer.

## Consequences

- **Positive:** reproducible commercial meaning, preserved original evidence and truthful channel outcomes.
- **Negative:** storage/recovery, pagination and secure projection require real delivered adapters.
- **Requires:** [domain contracts](../domain/quotation-documents.md), [delivery evidence](../domain/quotation-delivery-evidence.md), independently approved R9 and separately authorised implementation.
- **State:** ACCEPTED architecture; SPECIFIED contracts; no R8 application capability IMPLEMENTED/VERIFIED by this record.

## Alternatives considered

**Mirror live internal screen** — leaks protected data and changes historical presentation.
**Universal PDF-before-issue** — couples valid commercial issue to renderer availability.
**Arbitrary executable templates initially** — adds unnecessary security/governance burden.
**Browser Quick Print as archive** — lacks immutable quotation inputs and formal backend artefacts.

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
