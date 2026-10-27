[← Architecture](README.md)

# Shared file and upload foundation — research and design (task #47)

**Status: PROPOSAL, research and design only.** Nothing here is implemented, authorised or verified. No code, migration, route, grant,
ADR or STATUS change accompanies it. Table, Action and contract names are proposals that name contracts, not existing classes. The
owner decisions in section 11 gate any implementation slice.

Requested by the owner on 2026-10-27: a reusable ERP file/upload foundation, not a temporary Company-specific logo upload, for
Company logos and branding assets, Purchasing and supplier attachments, Party documents, Inventory/Item specifications, Job
photographs and evidence, and generated official document archives.

## 1. What the accepted record already says

| Source | Binding statement | Consequence here |
| --- | --- | --- |
| [ADR 0081](../decisions/0081-central-company-profile-and-company-wide-settings.md) | Branding/logo "deferred by decision" until a file-storage and branding architecture exists. | This document is that architecture proposal. [#23](https://github.com/kaleemnadeem16/erp/issues/23) stays deferred and is not expanded. |
| [ADR 0137](../decisions/0137-share-document-and-print-infrastructure-through-module-adapters.md) | Shared Documents infrastructure owns private artefact/blob storage, hashes, protected streaming, file-validation primitives and reconciliation/recovery; Company retains authoritative branding facts; owners keep access decisions; no purge engine or universal retention period. | Reused almost whole. One open point: it places blob storage inside Documents, while the owner now wants non-document consumers (D1 below). |
| [ADR 0134](../decisions/0134-archive-original-quotation-artefacts-with-private-recoverable-storage.md) | Originals are private, company-scoped, hash-preserved, never replaced; renditions are appended; restore exact bytes; no public links; OWASP upload guidance. | Defines the *official original* class. Unchanged. |
| [documents.md](../domain/documents.md) (storage, protected downloads, attachments) | `document_blobs`, `document_artefacts`, `document_attachment_versions / document_file_associations`; "Reuse private blob store/validation/status" for attachments but keep a confidential file distinct from a customer send attachment; same blob linked to several subjects exposes only the current authorised association. | The attachment/association split below is this contract generalised. |
| [DF2–DF4](../phases/document-print-framework-slices.md) | DF3 stages blob then verified artefact; DF4 adds reconciliation, protected exact-byte restore, association/access/upload/quarantine/history; "DF3 original archive cannot be publicly enabled without DF4 recovery/access". | The storage kernel is the shared prerequisite of DF3/DF4 and is smaller than either. |
| [backup-and-restore.md](backup-and-restore.md) | Database dumps only; "Uploaded files, if the application starts storing any" are *not* backed up. | Hard gate: no production enablement before file backup and a restore proof. |
| Current code | `backend/config/filesystems.php` has `local` (`storage/app/private`, `serve => true`), `public` (`/storage` symlink) and `s3` disks, default `FILESYSTEM_DISK=local`. No module stores or reads user-uploaded files today (no `Storage::` or uploaded-file handling in `backend/app`). `php-dev.ini` caps uploads at 20M. Documents has `DocumentAccess`, `DocumentOperationContext`, `DocumentTypeRegistry` (adapter registry) and the `documents_append_only_guard` trigger function with TRUNCATE guards ([#25](https://github.com/kaleemnadeem16/erp/issues/25)). | Reuse the registry/adapter/guard patterns. Do not use the `public` disk or `local`'s `serve` route (signed temporary URLs) for ERP files; add a dedicated private disk at implementation. |

## 2. Primary-source comparison

Sources were read on 2026-10-27. Where a vendor page could not be fetched, that is stated.

| | Odoo | ERPNext / Frappe | Business Central | Dynamics 365 / Dataverse |
| --- | --- | --- | --- | --- |
| Association | `ir.attachment` with `res_model`/`res_id` to the record. | `File` doctype with `attached_to_doctype`/`attached_to_name`; empty for standalone. | Document attachment on a record (FactBox); stays with the record as it is processed into later documents. | Notes/`annotation` and `activitymimeattachment` rows; file/image columns on a table. |
| Storage | Filestore on disk, path `sha[:2]/sha`; database storage optional (`ir_attachment.location`). | Files under `/private/files` or `/files`. | Database by default; optional external Azure Blob, Azure File Share or SharePoint. | Dataverse file storage; BYOK organisations use relational storage with a 128 MB cap. |
| Identity | SHA-1 `checksum`; identical content shares one stored file; collision check on write. | `content_hash`; used to detect identical uploads and reuse a blob. | External file gets a unique ID appended to its name; title stays the original name. | Row id; file columns carry size/name metadata. |
| Access | Via access to the linked record (`check` maps create/unlink to write); `public` flag and `access_token` bypass for sharing. | `is_private` files are served only to users who can read the parent document; public files are readable by anyone with the URL. | Record permissions; the external store's own access control is the customer's job. | Table privileges; blocked extensions/MIME list per organisation. |
| Limits and types | HTML/XML-like (including SVG) forced to `text/plain` unless the user may edit views; images resized. | `allowed_file_extensions`, `max_file_size`, per-doctype `max_attachments`, EXIF stripping for JPEG. | Supported preview types listed; no scanning described. | File column default 32 MB, up to 10 GB, client cap 128 MB; image column 30 MB, fixed formats; org `MaxUploadFileSize`. |
| Deletion and protection | Delete marks the file for garbage collection; autovacuum removes unreferenced files later. | Delete refused on submitted/protected documents; an "Attachment Removed" comment is written. | Optional "delete external file" toggle; if off, the file stays orphaned. | Standard row delete. |
| Backup/recovery | Filestore must be backed up with the database (operator duty). | Operator duty. | "Microsoft doesn't back up or manage those files"; Copy Company copies metadata only; a Migrate Files action repairs paths. | Platform backup of Dataverse storage. |

Sources: Odoo `ir_attachment.py` (18.0, [raw source](https://raw.githubusercontent.com/odoo/odoo/18.0/odoo/addons/base/models/ir_attachment.py)); Frappe `file.py` ([raw source](https://raw.githubusercontent.com/frappe/frappe/develop/frappe/core/doctype/file/file.py)) and the Frappe [attachments documentation](https://docs.frappe.io/framework-git/desk/attachments) (reached through search; the page itself returned 404 to the fetch tool); Business Central [External file storage for document attachments](https://learn.microsoft.com/en-us/dynamics365/business-central/across-store-document-attachments-externally) and [Add attachments, links, and notes on records](https://learn.microsoft.com/en-us/dynamics365/business-central/ui-how-add-link-to-record) (second page known from search results only); Dataverse [file and image overview](https://learn.microsoft.com/en-us/power-apps/developer/data-platform/files-images-overview) and [file attributes](https://learn.microsoft.com/en-us/power-apps/developer/data-platform/file-attributes) (limits known from search results; these pages were not opened in full). The earlier [OWASP File Upload cheat sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html) basis from ADR 0134 still applies.

### What we adopt and what we refuse

- **Adopt:** association to an owning record with access decided through that owner (all four); private by default; hash on the stored object; logical removal with deferred garbage collection after a grace period (Odoo); refusing removal where the record is protected (Frappe); a unique opaque stored name distinct from the display name (Business Central); a per-purpose type/size policy (Frappe, Dataverse); an extensible storage provider behind one contract (Business Central).
- **Refuse:** a `public` flag or `access_token` sharing (Odoo, Frappe public files): ADR 0134 forbids public links and tokens until a governed contract exists. Content-addressed sharing of one object across tenants (Odoo, Frappe dedupe): equal hashes would leak that another company holds the same file, and a shared object complicates per-company recovery and purge. Delete that leaves an orphan with no reconciliation (Business Central toggle off). Treating backup as someone else's problem: each vendor says so, and our [backup doc](backup-and-restore.md) currently proves nothing for files.
- **Lesson on SVG:** Odoo needs special handling because SVG/HTML carry active content. We exclude SVG in the first slice (D4).

## 3. Recommended architecture

One storage kernel, two classes of use on top of it.

```
 Owning modules (Company, Purchasing, Parties, Inventory, Jobs, Sales)        Documents
   register a FileOwnerAdapter: purposes, limits, audiences, access,          renders official originals and
   retention class, subject validation                                         renditions; owns manifests/artefacts
              │ attach / list / stream                                                │ publish artefact
              ▼                                                                        ▼
 ┌─────────────────────────────  Files module (storage kernel)  ───────────────────────────────┐
 │ upload intents · validation · staging · blobs (immutable) · evidence events · quarantine ·  │
 │ attachments + versions (ordinary, owner-associated) · protected streaming · holds ·         │
 │ reconciliation and exact-byte restore · operation receipts                                  │
 └──────────────────────────────────────────────┬───────────────────────────────────────────────┘
                                                 ▼
                              dedicated private Laravel disk (local volume first, S3-compatible later)
```

**Two classes, one blob store.**

1. **Ordinary attachment** (supplier quote PDF, item datasheet, Job photograph, Company logo): owner-associated, versioned, logically removable by the owner under its own rules, never an official original.
2. **Official original / rendition** (issued quotation PDF): created only by Documents' publish Action, referenced by `document_artefacts`, never removable or replaceable, restored byte-exact. It reuses `file_blobs` but never appears as an attachment and has no remove path. This preserves ADR 0134 and 0137 and the documents.md rule that a confidential file is not a customer send attachment.

**Ownership (recommended, requires D1).** A new `Files` module owns the kernel and the attachment tables. Documents keeps manifests, artefacts, heads, generation and the official-original rules, and consumes `file_blobs` instead of defining a second `document_blobs`. Reasons: Company branding, Purchasing and Job evidence must not depend on the Documents module to store a photograph; DF3/DF4 gain a smaller, earlier-testable foundation; one reconciliation/recovery implementation serves both classes. The cost is that ADR 0137's sentence placing blob storage in Documents needs a superseding ADR (the owner decides; this task edits no ADR). The alternative (keep it in Documents and let other modules call `Documents` for plain attachments) works but makes every module's file feature depend on the print framework and blurs "official original" with "ordinary file".

**Owners decide access; the kernel decides nothing about business meaning.** Same split as `DocumentAccess` today: registration is not permission, a missing or throwing callback denies, `Files` grants nothing.

## 4. Components to reuse

| Existing piece | Reuse |
| --- | --- |
| `DocumentTypeRegistry` / `DocumentTypeAdapter` / `DocumentAccess` / `DocumentOperationContext` | Template for `FileOwnerRegistry` / `FileOwnerAdapter` / `FileAccess` / `FileOperationContext` (key format, duplicate and invalid-key refusal, deny on missing or throwing callback). |
| `documents_append_only_guard()` and the row + TRUNCATE guard pattern ([#25](https://github.com/kaleemnadeem16/erp/issues/25)) | Same guard on every `Files` history table; new history tables must carry both triggers. |
| `document_operation_receipts` pattern (key, intent digest, original result) | `file_operation_receipts` for idempotent commit/remove/restore. |
| Company-scoped composite foreign keys; `BelongsToCompany`; ratchets (`CompanyScopedForeignKey`, `CompanyScopedUniqueIndex`, `RecordScopeCoverage`, `PermissionCheckRegistration`, `WriteRouteGuard`, `ModuleRegistration`) | Every table and route is covered by the existing tests, not new machinery. |
| Company change history (`CompanyChange` style, `activity_log`) | Audit evidence conventions for governed changes. |
| `ActionAuthorization::require` | Authority enforced in Action and HTTP. |
| Laravel Filesystem (Flysystem) and `finfo` | Storage provider contract and server-side type sniffing; no new dependency is required for the kernel. |
| `backup-and-restore.md` destinations hook (`deploy/backup/destinations.d`) | Extend to the file volume (section 8). |

Not reused: the `public` disk, `storage:link`, `Storage::url()`/`temporaryUrl()`, Quick Print browser rendering.

## 5. Proposed tables

All tables carry `company_id`, use composite company keys for every business reference (`(company_id, id)` unique + composite FK), `bigint` ids matching the repo, `timestampTz` where the repo does, and the append-only guard where marked. No bytes in PostgreSQL. No monetary columns, so no FinancialActivity probe.

**`file_blobs`** — one immutable stored object.
`id`, `company_id`, `storage_disk`, `storage_key` (opaque, random, never derived from content or name; unique), `sha256` (char 64, hex), `size_bytes`, `detected_media_type`, `declared_media_type`, `extension_class`, `health` (`STAGED|VERIFIED|MISSING|QUARANTINED|REJECTED`), `scan_state` (`NOT_CONFIGURED|PENDING|CLEAN|INFECTED|ERROR`), `created_by` nullable (service uploads have none), `created_at`, `verified_at`. Bytes, hash, size and key are immutable by trigger; only `health`, `scan_state`, `verified_at` may change, and every change writes an event. No unique constraint on `sha256` (no cross-company or cross-row dedupe in the first slices, D9). Index `(company_id, health)` for reconciliation.

**`file_blob_events`** — append-only evidence (guarded).
`id`, `company_id`, `blob_id`, `event` (`STAGED|VERIFIED|SCAN_RESULT|QUARANTINED|RELEASED|MISSING_DETECTED|RESTORED|RECONCILED|PURGED_STAGING`), `from_health`, `to_health`, `expected_sha256`, `observed_sha256`, `actor_id` nullable, `service` nullable, `reason`, `evidence` jsonb (bounded, no file contents or names), `occurred_at`.

**`file_upload_intents`** — durable two-step upload.
`id`, `company_id`, `owner_type_key`, `owner_binding_identity`, `purpose_key`, `actor_id`, `idempotency_key`, `intent_digest`, `declared_name_hash` (not the name), `expected_size`, `expected_sha256` nullable, `state` (`PENDING|STAGED|COMMITTED|EXPIRED|ABORTED`), `blob_id` nullable, `expires_at`, `created_at`. Unique `(company_id, idempotency_key)`. Mutable state projection; each transition also writes a blob event.

**`file_attachments`** — the logical slot an owner points at (head only).
`id`, `company_id`, `owner_type_key`, `owner_binding_identity` (the owner's tenant-safe subject reference, as in `document_subject_bindings`), `purpose_key`, `audience`, `current_version_id` nullable, `state` (`ACTIVE|REMOVED`), `generation`, `created_at`. Unique on `(company_id, owner_type_key, owner_binding_identity, purpose_key, slot_key)` for single-slot purposes (a purpose may allow many files, using an owner-chosen `slot_key`).

**`file_attachment_versions`** — append-only (guarded).
`id`, `company_id`, `attachment_id`, `version_no`, `blob_id` (composite FK to `file_blobs`), `safe_name` (sanitised display name, never a path), `title` nullable, `access_profile`, `retention_class`, `snapshot_link` nullable (a pinned manifest or business version that relied on this exact version), `uploaded_by`, `change_reason`, `created_at`. Unique `(attachment_id, version_no)`. Removal is a new head state, not a delete: versions and blobs remain until an approved disposition.

**`file_holds`** — retention/legal hold references (slice F4, optional earlier).
`id`, `company_id`, `blob_id` or `attachment_id`, `reason`, `placed_by`, `placed_at`, `released_by`, `released_at`. Append-only except release columns, or two rows (place/release).

**`file_operation_receipts`** — append-only (guarded) idempotency, same shape as `document_operation_receipts`.

Documents later adds nothing to `Files`: `document_artefacts.blob_id` is a composite FK to `file_blobs`.

## 6. Proposed contracts and Actions

All Actions run in a short transaction, take the company from `CompanyContext` (never request input), call `ActionAuthorization::require` for the capability, and write evidence. HTTP calls the same Action and enforces the same permission.

**Owner-facing contract (what a module implements and calls)**

```php
interface FileOwnerAdapter {
    public function ownerTypeKey(): string;                 // e.g. 'purchasing.supplier-document'
    /** @return list<FilePurpose> */ public function purposes(): array;
    public function resolveSubject(FileOperationContext $c): ?OwnerSubject;   // tenant-safe existence + state
}
final readonly class FilePurpose {
    public string $key;                  // 'company.brand.logo', 'job.photo', ...
    public FileClass $class;             // ATTACHMENT only; ORIGINAL is reserved to Documents
    public array $mediaTypes;            // closed allowlist, sniff-verified
    public int $maxBytes; public int $maxFiles; public array $audiences;
    public string $retentionClass; public bool $versioned; public bool $imageReencode;
}
interface FileAccess { public function authorise(FileOperationContext $c): bool; }  // upload|view|remove|replace
```

Context carries `companyId`, `actorId` (or a named service), `operation`, `ownerTypeKey`, `purposeKey`, `audience`, `ownerBindingIdentity`; built by `Files` from trusted state. Missing owner, missing callback or an exception denies.

**Files Actions**

| Action | Purpose |
| --- | --- |
| `RequestFileUpload` | Creates an intent after the owner's `upload` authorisation and purpose limits; returns an opaque intent id and the server-fixed limits. |
| `StageUploadedFile` | Streams bytes to a staging key with a hard size ceiling, computes SHA-256, sniffs type, applies the purpose allowlist and extension/signature agreement, re-encodes images if the purpose says so, records `STAGED`. Rejects (`REJECTED`) with a stable error code, never echoing content. |
| `CommitFileAttachment` | Fenced, idempotent: promotes the staged blob to `VERIFIED` (or `QUARANTINED` if scanning is configured and not clean), creates or advances the attachment and its version. May join an owner's transaction when the owner needs "attachment and business change commit together". |
| `RemoveFileAttachment` | Owner-authorised logical removal with reason; refused when a version is pinned by a snapshot link, a hold, or a protected owner state. Never deletes bytes. |
| `ListFileAttachments` | Returns only associations the caller is currently authorised to see; no enumeration of other subjects that share a blob. |
| `StreamAuthorisedFile` | Re-checks company, association, owner access and blob health on every call; streams with `Content-Disposition: attachment` (inline only for the closed image list with `X-Content-Type-Options: nosniff` and a restrictive CSP), `Cache-Control: private, no-store`, safe filename. No URLs, tokens or storage keys in any Resource. |
| `QuarantineFile` / `ReleaseQuarantine` | Scoped recovery/security authority, reason and event; quarantined bytes are not streamable. |
| `ReconcileFileStorage` | Compares objects and rows: finds staged-but-uncommitted objects past grace and fence, committed rows with a missing or hash-mismatched object (`MISSING`, alert, owner view shows "unavailable"), objects with no row (preserved and reported, never auto-deleted). Deletes only proven-unreferenced staging objects past grace and with no hold. |
| `RestoreFileBlob` | Restores exact bytes from a verified copy against the expected hash, with reason and event. Re-rendering is never recovery. |
| `PlaceFileHold` / `ReleaseFileHold` | Records holds; a hold blocks removal disposition and purge. |

**Documents side (unchanged contracts, new dependency):** `PublishDocumentArtefact` writes through the kernel's staging/verify/commit, creates `document_artefacts` pointing at `file_blobs`, and has no `Remove` path. Documents' `StreamAuthorisedDocument` calls the same streaming primitive after the Documents-owner adapter authorises.

**Company branding (consumer example, not part of #23's scope):** purpose `company.brand.logo`, one slot, PNG/JPEG only, re-encoded, small cap. A future brand version stores `file_attachment_version_id` plus `sha256` and `size`. After F1 the `ReadCompanyBrand` contract can report the logo as `PINNED` instead of `UNSUPPORTED`; until then #23 correctly states "assets unsupported".

## 7. Security requirements (acceptance criteria for any slice)

1. Dedicated private disk (`files`), `visibility private`, `throw true`, never `public`, never the `local` disk's `serve` route, no `Storage::url()` or temporary URLs. Storage root outside the web root and outside `storage/logs`.
2. Opaque random storage keys; the client name is display metadata only, sanitised (strip path, control characters, directory separators, double extensions; bounded length), never used to build a path.
3. Server-side validation per purpose: closed media-type allowlist, `finfo` sniff must agree with extension and declared type, byte ceiling enforced while streaming (PHP/nginx limits must be at least the largest purpose ceiling, and the app limit is the truth), no executables, scripts, HTML, SVG or Office macro formats, no archives in the first slices, decompression-bomb and image-dimension limits, images re-encoded to strip active content and metadata.
4. Malware scanning: an interface with a `NOT_CONFIGURED` state that is visible and recorded. When a scanner is configured, scanning is fail-closed (`PENDING`/`ERROR` blobs are not streamable). Whether scanning is mandatory before first production use is D3.
5. Access: every upload, list, stream, replay and status call re-checks current owner access; a path, id, hash or cache hit is never authority; there is no generic "download any file" permission. File/config administrators and recovery operators see metadata, not bytes, unless the owner's duty grants it.
6. No public links, share tokens or signed URLs; authenticated application download only (ADR 0134).
7. Tenant safety: composite company FKs, company in every cache key, no cross-company object sharing or dedupe, hash equality grants nothing.
8. Evidence: append-only events and versions with row and TRUNCATE guards; actor or named service on every change; no file content, names or storage keys in logs, exceptions, activity log, cache, queue payloads or error responses.
9. Idempotency: upload commit, removal and restore carry keys and intent digests; a changed intent under a used key conflicts; lost-response replay returns the original result after a current access check.
10. Abuse controls: route throttling, per-company and per-actor quotas (D11), bounded intents with expiry, CSRF and existing API conventions ([api-conventions.md](api-conventions.md)).
11. Encryption: disk-level at rest as an operations requirement; application-level envelope encryption is D7. `APP_KEY` handling stays as documented in backup-and-restore.
12. Retention and purge: no universal retention period or purge engine (ADR 0137). Records carry a `retention_class`; ordinary removal is logical; physical purge only of proven-unreferenced staging objects after grace and fencing, and of used attachments only when every owner reference, retention rule and hold has an approved disposition.

## 8. Backup, recovery and operations

- Gate: before any slice stores a real file in production, the file volume (or bucket) is covered by the backup destination hook, and a **restore proof** exists: restore database and files together, then verify every `VERIFIED` blob's hash and tenant/owner links before marking usable. Database restore alone is explicitly insufficient (documents.md: "Existing database backup practice is not proof future blobs are backed up").
- Add file metrics to the existing Prometheus textfile pattern: staged-uncommitted count, `MISSING`/`QUARANTINED` counts, last reconciliation time, last file backup success.
- Missing or corrupt bytes are an incident: block streaming, alert, restore from the verified copy; never regenerate or silently drop the row.
- Local volume first is acceptable for a single-host deployment; an S3-compatible provider later is a disk change behind the same contract (Business Central's external-storage model is the precedent), not a redesign. Copying a company/environment copies rows and bytes together (the Business Central Copy Company gap is the cautionary example).

## 9. Trade-offs

| Choice | Chosen | Alternative and why not |
| --- | --- | --- |
| Where the kernel lives | New `Files` module; Documents consumes it (needs D1). | Inside Documents per the current ADR 0137 wording: fewer moving parts now, but every attachment feature depends on the print framework and official/ordinary files blur. |
| Dedupe | None; one blob per upload. | Content-addressed sharing (Odoo/Frappe): saves space but leaks cross-tenant existence and complicates recovery and purge. Revisit within one company only if storage cost demands (D9). |
| Two-step intent upload | Yes (durable intent, then bytes, then commit). | Single multipart POST attached directly: simpler, but leaves no recoverable record of a half-finished upload and cannot join an owner transaction cleanly. Small images could use a thin one-call wrapper over the same Actions. |
| Association | Owner-supplied binding identity + registry, as Documents does. | Polymorphic `model_type/model_id` (Laravel morph, Odoo `res_model/res_id`): no real FK and weak tenant proof; the repo's composite-key discipline rules it out. |
| Removal | Logical, versions retained. | Hard delete (Dataverse default): loses evidence and breaks pinned snapshots. |
| SVG / HTML | Excluded first. | Sanitised SVG logo: possible later behind D4, needs a proven sanitiser and tests. |
| Size model | Per-purpose ceilings, streaming enforcement. | One global limit: too loose for logos, too tight for Job video if ever wanted. Chunked/resumable upload deferred. |
| Scanning | Pluggable, visible state. | Mandatory ClamAV now: adds an operations dependency before any consumer exists. |

## 10. Smallest implementation slices

Each slice is separately authorised, ships disabled (no route or no registered owner) until its gate holds, and follows the repo's evidence rules (focused tests, Pint, PHPStan, ratchets, migrate-cycle, codegen `--check`).

| Slice | Content | Depends on | Gate to enable |
| --- | --- | --- | --- |
| **F0 Storage kernel (disabled)** | `files` private disk config; `file_blobs`, `file_blob_events`, `file_upload_intents`, `file_operation_receipts` with guards; `FileOwnerRegistry`/adapter/access interfaces; validation library (allowlist, sniff, size, name sanitising, image re-encode); `RequestFileUpload`, `StageUploadedFile`, verify step. No route, no owner, no attachment tables. | D1, D2, D4, D5 | None to merge; nothing user-reachable. |
| **F1 Attachments and protected streaming** | `file_attachments`, `file_attachment_versions`; `CommitFileAttachment`, `RemoveFileAttachment`, `ListFileAttachments`, `StreamAuthorisedFile`; one pilot owner behind its own capability. | F0, D6, D3 | Backup + restore proof (section 8) for the pilot. |
| **F2 Reconciliation, quarantine, holds, restore** | `ReconcileFileStorage`, `RestoreFileBlob`, `QuarantineFile`/`ReleaseQuarantine`, `file_holds`; metrics; crash-window tests. | F1 | Required before any official original (F3) and before broad rollout. Largely coincides with DF4's file half. |
| **F3 Documents integration** | `document_artefacts` and heads reference `file_blobs`; DF3 staged-blob to verified-artefact uses the kernel; originals have no remove path. | F2, DF2, DF3 | DF3/DF4 gates as already specified. |
| **F4+ Module pilots** | Company logo through #23's Company contract; Purchasing supplier attachments; Party documents; Inventory item specifications; Job photographs/evidence (needs J1 receiver and likely larger caps, mobile capture, thumbnails). Each is the owner's separate slice with its own R9-style duties and grants. | F1 (F2 for anything relied on as evidence) | Per owner. |

The recommended pilot owner (D6) is the Company logo: one audience, one small image-only purpose, no customer-confidential content, and it resolves the standing ADR 0081 deferral. Purchasing supplier attachments is the alternative if the owner prefers a PDF/document pilot.

## 11. Owner decisions needed

| # | Decision | Recommendation |
| --- | --- | --- |
| D1 | New `Files` module owns the storage kernel and attachments, Documents consumes it, with a superseding ADR for the 0137 blob-ownership sentence; or keep ownership in Documents. | New `Files` module. |
| D2 | Initial storage: local private volume on the host, or an S3-compatible bucket from the start. | Local volume with the backup hook, behind the same disk contract. |
| D3 | Is malware scanning mandatory before the first production file? | Required before customer-facing or externally sourced files; interface and visible state from F0, scanner at F1 gate for supplier/customer documents. |
| D4 | Allow sanitised SVG logos? | No in F0–F2; PNG/JPEG only. |
| D5 | Per-purpose size ceilings and allowed types (logo, PDF, image, spreadsheet). | Logo 2 MB; documents 20 MB (matches current PHP limit); photos 10 MB; no archives or video initially. |
| D6 | Pilot consumer for F1. | Company logo (then #23 consumes it). |
| D7 | Application-level encryption of stored objects in addition to disk encryption. | Disk-level now; revisit with D2 if a bucket is chosen. |
| D8 | Minimum retention classes and who may place a hold. | Class label and hold table only; no periods or purge engine (0137). |
| D9 | Same-company dedupe later. | None now. |
| D10 | May an ordinary attachment ever be pinned into an official document manifest. | No by default; an owner must name it, and then it is pinned by version and hash. |
| D11 | Per-company storage quota and per-actor upload rate. | Set at F1 from a measured pilot. |
| D12 | Backup destination for files and who owns the restore proof. | Same destination as the database dumps; the integrator signs the proof before F1 is enabled. |

## 12. Explicit non-goals of this design

No implementation, migration, route, grant, ADR edit or STATUS edit. No change to #23 beyond noting that its `assets: UNSUPPORTED` state is replaced only after F1 and a separate authorisation. No claim that any branded print, attachment or archive capability exists. No malware product, search/indexing, thumbnails, previews, e-mail attachment sending, portal/public sharing or document management (folders, tags, OCR) is proposed.
