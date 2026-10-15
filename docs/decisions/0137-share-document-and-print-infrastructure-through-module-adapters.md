[← Decisions](README.md)

# 0137 — Share document and print infrastructure through module adapters

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** ERP-wide Documents/Company infrastructure; Sales R8 DOC0–DOC7, R9 authority; future Purchasing/Inventory/Finance/operational adapters
- **Authority:** owner approval of Q50 F1–F6 following the framework proposal; architecture/documentation only, no application implementation or PO refactor authorised.

## Context

Several modules need professional output, pagination, private files and reliable original retrieval.
Sales R8 accepted0133–0135 guarantees apply without requiring an independent Sales renderer/archive.
Existing PO Quick Print has a useful typed projection and template boundary, but is not a formal
immutable backend archive. Centralising only HTML-to-PDF conversion leaves most infrastructure duplicated.

## Decision

Accept F1–F6: one Documents infrastructure module in the Laravel modular monolith, with reusable
React presentation/print primitives and explicit module-owned typed document adapters. No separate
PDF microservice and no universal issue/approval/posting/acceptance/count/delivery lifecycle.

Documents owns common rendering, curated versioned templates/page policies, manifests/generation
status, deterministic identities/idempotent receipts, private artefact/blob storage and hashes,
protected streaming, file-validation primitives and reconciliation/recovery. Company retains
authoritative identity/branding facts. Resolve global → Company → document-type → permitted
document choice, validate constraints and pin resolved versions/assets at the owner's snapshot event.
User preferences/current masters cannot mutate pinned official output.

Each module owns required content/authorised projection, snapshot timing, permitted purposes/layout,
access eligibility/audience, retention/holds and its business events. Shared infrastructure never
recalculates business totals or grants business access. Ordinary users need the owning adapter's
file/download eligibility, not a second generic download permission. No central file-read-all right
follows from template administration or technical recovery. R9 settles exact vocabulary/grants.

Shared A4/Letter page profiles, headers/footers/tables/pagination and long-content handling are
supported foundation contracts; quotations retain accepted initial A4. Required content/layout
constraints remain owner-controlled. Use a bounded isolated worker, no arbitrary scripts/templates,
external renderer access, host secrets or confidential operands in customer projection. Reprints
reuse original bytes. Layout repairs append separately labelled renditions under owner checks.
Storage/database reconciliation, secure exact-byte recovery and resource limits precede rollout;
DB and blob store are not claimed to share one transaction.

Refine DOC0–DOC7 ownership: common models/archive live in Documents; Sales retains quotation bindings,
customer content/QL3 issue and R6/delivery semantics. No duplicate shared and Sales PDF originals.
Start with one real quotation adapter plus a PO-shaped compatibility fixture. Existing PO Quick Print
is unchanged; do not require other modules' actual adapters before the quotation pilot.

Defer advanced designers, unrestricted template execution, portal/e-signatures, multilingual/RTL,
bulk printing, email platform and additional renderer backends to separately governed capabilities.
Existing0133–0135 bodies and business guarantees remain unchanged; this supplements infrastructure
ownership, not commercial issue or consent authority. R7 is0131–0132/0136;0130 is the backup decision.

## Consequences

- **Positive:** one renderer/security/recovery foundation, consistent output and no duplicate archive.
- **Negative:** adapters, current access checks, compatibility/version pinning and resource governance require delivery work.
- **Requires:** [Documents contract](../domain/documents.md), [framework slices](../phases/document-print-framework-slices.md), refined [R8 slices](../phases/phase-4-r8-implementation-slices.md) and separately approved R9 names/grants before public enablement.
- **State:** ACCEPTED architecture / SPECIFIED contracts; no Documents module/adapter IMPLEMENTED or VERIFIED by this record.

## Alternatives considered

Separate per-module infrastructure — duplicates pagination, security, storage and recovery.
Generic PDF service only — does not share templates, artefact identity, protected access or recovery.
Universal document lifecycle — overrides legitimate domain differences and becomes a second authority.
All-module migration now — unnecessary dependency/refactor; first real adapter plus compatibility fixture is sufficient.

## Research basis

Reuse [Q50 comparison, inspected paths and F1–F6](../architecture/document-print-framework-review.md),
primary Frappe printing/letterhead and Business Central dataset/layout/company-default documentation
accessed2026-10-15. Both separate common presentation/configuration from document-specific output;
their scripting and Word/RDLC models differ. Our narrow monolith adapter/private-artefact contract
translates those patterns under0010/0014/0081 and accepted R8, not vendor lifecycle/permission defaults.
Existing R8 upload/security/recovery research suffices; no implementation package is selected here.

## Related

- [0133](0133-freeze-customer-quotation-content-and-versioned-presentation.md) · [0134](0134-archive-original-quotation-artefacts-with-private-recoverable-storage.md) · [0135](0135-separate-quotation-delivery-evidence-from-customer-consent.md)
- [R9 final review](../phases/phase-4-r9-authority-reporting-review.md)
- [Company0081](0081-central-company-profile-and-company-wide-settings.md) · [Appearance/printing](../architecture/appearance-and-printing.md)
