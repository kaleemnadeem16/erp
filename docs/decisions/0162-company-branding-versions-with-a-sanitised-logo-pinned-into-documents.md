[← Decisions](README.md)

# 0162 — Company branding: immutable versions with a sanitised logo, pinned into documents

- **Status:** accepted (owner-approved answers of 2026-11-06 to issue #23: PNG and JPEG only, 2 MB, capability `company.branding.manage` STAGED and granted to nobody, a Branding section on the Company screen, the logo used on quotation documents with a fallback when absent)
- **Date:** 2026-11-06
- **Affects:** Company (`company_brand_versions`, branding Actions, route, screen), Files (image sanitiser, trusted read), Documents (manifest pin, renderer), demo data
- **Builds on:** 0081 (Company registry), 0137 (Documents; Company keeps brand facts, pinned at the owner's snapshot), 0153 (Files), 0160 (delivery corrections stay as they are)

## Context

Issue #23 was deferred until a shared file foundation existed; Files F0 (0153) now does. Files recorded that image sanitising was mandatory before a Company-logo pilot, and the development
image has no imaging extension (no GD, no Imagick).

## Decision

- **Versions.** `company_brand_versions` is append-only (row triggers, TRUNCATE guard, model guard): number, optional display name (120), tagline (160), optional logo reference, reason, author, database clock.
  The current brand is the highest version; a version with nothing set is how a brand is cleared and reads as "no brand". Every real change is also one `company_changes` row (section `BRANDING`).
  An unchanged save writes nothing, so a retry never makes a second version. Decision (not in the issue): colours, contact selection and logo placement are not built; text and logo only.
- **Logo.** PNG or JPEG, at most 2 MB and 1,000,000 pixels, uploaded as multipart. Company **rebuilds the file** in pure PHP (`ImageSanitiser`): PNG keeps only header, palette, transparency and pixel data; JPEG keeps
  tables, frame, scans and the Adobe colour marker; EXIF, XMP, ICC, text, comments and unknown structure are dropped. Interlaced and 16-bit PNG, CMYK and lossless JPEG are refused with a reason. The sanitised bytes
  (never the original) go through Files (request, stage, verify) under owner `company.brand-asset`, purpose `company.brand-logo`, so only a VERIFIED object can be referenced; the version stores blob id, hash, type and size.
- **Duty.** `company.branding.manage` is STAGED in `CompanyDutyManifest`: not in the permission vocabulary (so no role editor or member grant can hand it out), excluded from automatic grants when active,
  refused by `CompanyAccess` in the request and the Action for everyone. Activation is a reviewed change to the manifest plus an explicit grant. Tests and the demo seeder switch it on in the container only, for one run.
- **Reads.** Any signed-in member reads the current brand and logo (they are printed on documents handed out); the logo route is private, `no-store`, `nosniff`, sandboxed.
- **Documents.** Documents reads Company only through the typed contract `ReadCompanyBrand` / `ReadCompanyBrandLogo`. At the owner's freeze the manifest pins `brand_state PINNED`, the brand text and version (`brand`)
  and the logo reference with its hash (`asset_manifest`); the pinned digest covers them. A company with no brand freezes as `NOT_AVAILABLE`, `brand` NULL, no assets: exactly as before, same digest, same bytes.
  A replay of an identity returns the original freeze whatever the brand has become. At render the logo is read through the contract and hash-checked; a pinned logo that cannot be read back fails the generation
  (`brand_asset_unavailable`), never a quietly unbranded original. The renderer contract and version are unchanged (`document-pdf/1` 1.0.0): older manifests stay renderable and unbranded output is byte-identical;
  a branded manifest only adds a taller first-page header (logo scaled to fit 36 x 130 pt, name, tagline; long text is cut to the line) and an image XObject (JPEG as DCTDecode, PNG as its own Flate data, alpha as a soft mask).
- **Correction rules (0160)** are untouched: branding never changes a frozen document, a delivery record or a correction.

## Alternatives rejected

- Store logo bytes or base64 in Company: forbidden by the owner's 2026-10-21 decision; Files exists.
- A Company default brand injected at render time: it would change old documents and break "pinned at the freeze".
- Require Imagick or GD: not in the image, and a dependency change is a separate decision; pure PHP on two formats is small and tested.
- Accept SVG: active content, forbidden by Files.
- Mutable current-brand row with an audit trail: weaker than immutable versions that a manifest can reference.
- Activation chain with generation locks for brand versions (the issue-comment proposal): no concurrency need beyond the company row lock that numbers versions.

## Consequences

Migrations `2026_12_02_100000_create_company_brand_versions` and `2026_12_02_110000_allow_pinned_brand_in_document_manifests` (new nullable `document_manifests.brand`, relaxed shape check; rollback refuses
once a manifest pinned a brand or history records branding). New routes `GET/POST /company/branding`, `GET /company/branding/logo`; generated client updated. Not built: colours, contact selection, a brand history screen,
image sizes beyond 1 megapixel, scanner-based malware checks (the purpose is internally authored and sanitised; Files' default scanner reports NOT_CONFIGURED), other document types' use of the brand beyond what the shared renderer draws.

## Related

[documents.md](../domain/documents.md) · [0081](0081-central-company-profile-and-company-wide-settings.md) · [0137](0137-share-document-and-print-infrastructure-through-module-adapters.md) · [0153](0153-own-shared-file-storage-in-a-dedicated-files-module.md) · [0160](0160-correct-manual-quotation-deliveries-by-appending-reasoned-records.md)
