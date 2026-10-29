# Files module

The **disabled** shared file storage kernel ([ADR 0153](../../../../docs/decisions/0153-own-shared-file-storage-in-a-dedicated-files-module.md); design:
[shared-file-foundation-design.md](../../../../docs/architecture/shared-file-foundation-design.md); issue #49, slice F0). Private storage, validated staging and verified
identity for stored objects — and nothing that serves, downloads, attaches or shares a file for a user: no route, no permission vocabulary, no duty, no grant, no
UI, no URL, no attachment table. Callable only from code and tests.

## Owns

- The private `files` disk (`config/filesystems.php`; `FILES_DISK_ROOT`, default `storage/app/files`): `visibility private`, `serve false`, `throw true`, no `url`.
  Local first (D2); an S3-compatible provider is a change to that one entry. Production wiring, encryption at rest and backup are **activation conditions** (D7, D12).
- Tables (`2026_10_29_100000_create_files_foundation`, company-scoped composite keys, bytes never in PostgreSQL, times from the database clock):
  `file_blobs` (immutable identity — company, disk, opaque random 40-hex key never derived from content or name, SHA-256, size, sniffed type; only `health` and
  `scan_state` move, by legal transitions, `REJECTED` final; **no unique hash**: nothing is deduplicated or shared across companies, D9), `file_blob_events`
  (append-only trail), `file_upload_intents` (durable, idempotent request; guarded state machine; `COMMITTED` is **refused by the database** until the attachment slice)
  and `file_operation_receipts`. Every table refuses TRUNCATE (CASCADE included); events and receipts also UPDATE/DELETE; blobs and intents refuse DELETE.
- Support contracts: `FileOwnerAdapter` / `FileOwnerRegistry` (the owning module registers its owner type and purposes from its own provider), `FileAccess` +
  `FileOperationContext` (the owner's decision per operation; a denial or a throw refuses and is logged without content by `FileAccessGate`), `FilePurpose` (closed
  media-type allowlist with extensions, byte ceiling, pixel ceiling, `externallySupplied`; SVG, HTML, scripts, executables and archives can never be listed),
  `FileValidator` (ceiling enforced while streaming, `finfo` sniff, extension and declared type must agree, no code-bearing extension in a dotted name, images must decode
  within the pixel ceiling), `FileScanner` with the default `NotConfiguredScanner` (reports `NOT_CONFIGURED`, never a pass).
- Actions (internal): `RequestFileUpload` (owner access, purpose limits, idempotent on the caller's key), `StageUploadedFile` (validates in a private temporary copy,
  stores under the opaque key, records the blob `STAGED` with evidence and the intent `STAGED` in one short transaction, removes the object if that fails; content refused
  after a full read is a `REJECTED` blob with no object; over the ceiling or empty aborts the intent; two stagers of one intent resolve to one blob) and
  `VerifyStagedFile` (recomputes hash and size against the identity; missing or changed object → `REJECTED`; infected → `QUARANTINED`; an internally authored purpose is
  `VERIFIED` once intact and not infected; a purpose whose files come from outside the company becomes `VERIFIED` only on a `CLEAN` scan, so with the default scanner it
  stays `STAGED`, i.e. nothing externally supplied can become downloadable, D3).
- Recovery primitives (used by owners such as Documents): `ReconcileFileBlob` (a VERIFIED blob whose object is missing becomes `MISSING`, one whose hash or size changed
  `QUARANTINED`; evidence recorded; nothing deleted) and `RestoreFileBlob` (a MISSING or QUARANTINED, non-infected blob is restored only from bytes with its recorded hash and
  size, with a reason). Both ask the owner (`reconcile`, `restore`). Results never serialise the storage disk or key, and database or storage failures reach callers only as
  fixed safe errors.

## Boundaries

- Registration is not permission. Files grants nothing; the owner decides, and a missing or throwing callback denies.
- Image sanitising (re-encoding to strip metadata) is **deferred and mandatory before the Company-logo pilot**: it needs an imaging extension the development image does
  not have. F0 validates and decodes dimensions only.
- A leftover staging object after a crash between the object write and the database commit is an orphan for the reconciliation slice (F2); F0 removes it on failure.
- Not here: attachments and versions and protected streaming (F1), recovery, quarantine release, holds and reconciliation (F2), Documents integration (F3), branding,
  quotas and rate limits (activation conditions, D11), retention classes.
