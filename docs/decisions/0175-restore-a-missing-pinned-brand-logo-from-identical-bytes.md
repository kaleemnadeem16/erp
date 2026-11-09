[← Decisions](README.md)

# 0175 — Restore a missing pinned brand logo from identical bytes; logo budgets and brand read hardening

- **Status:** accepted (hardening of 0162; no new product behaviour for end users)
- **Date:** 2026-11-09
- **Affects:** Company (`RestoreCompanyBrandLogo`, `CompanyBrandFileOwner`, branding resource and logo route), Files (`ImageSanitiser`), Documents (`PdfImage`, `RequestDocumentGeneration`), Sales (`RequestQuotationDocument`, customer projection)
- **Builds on:** 0162 (company branding), 0153 (Files), 0137 (Documents), 0160 (delivery corrections)

## Context

An independent review of the issuing, documents and branding area (issue #95) found that a frozen document can only ever print the exact logo it pinned, yet nothing could bring that
file back if its stored object was lost: a new upload makes a different blob, which a frozen document must never use. It also found that image size limits were applied after a
file had been inflated, that the sanitiser copied some structure unchecked, and several read-side and idempotency gaps.

## Decision

- **Restoration, not substitution.** A missing or damaged logo object is put back by `RestoreCompanyBrandLogo`: it takes a verified copy of the original (already sanitised) file and
  restores it under the SAME blob through the Files restore path, which accepts only bytes whose SHA-256 and size equal the recorded ones. It never creates a version, never points a
  brand at a newer logo and never touches a healthy file (it reconciles first and reports `intact`). It needs the branding duty (asked in the Action and by the file owner for the
  `reconcile` and `restore` operations), a reason, and leaves a Files `RESTORED` event plus a `company_changes` row. It is an internal Action with no route, like restoring a document's
  file; the operator supplies the bytes from a backup. Retention and backup of the Files disk remain an operations responsibility.
- **Budgets before decompression.** Pixel count (1,000,000), longest side (4,096) and the decoded PNG size (8 MiB) are judged from the declared header before any data is inflated or
  copied; inflation is capped at the declared size; PNG scanline filter bytes and JPEG frame/scan structure are validated; the JPEG Adobe marker is rebuilt from one validated value
  instead of copied. The PDF image code re-checks the same budgets from the header.
- **Brand reads.** The reason behind a brand change is returned only to those who may manage the brand (the field stays, `null` for everyone else). An unreadable current logo is the
  same plain 404 as no logo.
- **Generation keys.** An idempotency key for generating a quotation document is recorded for every accepted request, including one answered from an already generated document, so
  reusing it for a different quotation is a 409 (`DELIVERY_KEY_REUSED`) instead of an unrelated result.
- **Customer document date.** The issue date is shown in the zone pinned at issue, not the company's current zone.

## Alternatives rejected

- Substituting the newest logo, or regenerating the document: breaks "a frozen document never changes".
- A user-facing upload route for restoration: a new surface (authorisation, OpenAPI, screens) for a rare operator task; the Action is reachable by tooling when a screen is justified.
- Keeping the budgets only in the controller or the Files purpose: the Files check ran after the full decode path, which is the finding.

## Related

[0162](0162-company-branding-versions-with-a-sanitised-logo-pinned-into-documents.md), [0153](0153-own-shared-file-storage-in-a-dedicated-files-module.md), [Documents domain](../domain/documents.md)
