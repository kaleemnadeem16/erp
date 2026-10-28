[← Decisions](README.md)

# 0153 — Own shared file storage in a dedicated Files module

- **Status:** proposed — awaiting owner acceptance; F0 implementation must not start before it is accepted
- **Date:** 2026-10-27
- **Affects:** new Files module; Documents infrastructure (DF3/DF4 storage half); Company branding (#23); future Purchasing, Parties, Inventory and Jobs attachments; [backup and restore](../architecture/backup-and-restore.md)
- **Supersedes:** [0137](0137-share-document-and-print-infrastructure-through-module-adapters.md) **only** for shared blob/storage ownership (see "What changes"); every other 0137 decision stands
- **Authority:** owner decisions D1–D12 of 2026-10-27 on [#47](https://github.com/kaleemnadeem16/erp/issues/47), which approved the [shared file foundation design](../architecture/shared-file-foundation-design.md) as a design proposal only. Documentation/architecture only: no code, migration, route, grant or permission is authorised by this record.

## Context

[0137](0137-share-document-and-print-infrastructure-through-module-adapters.md) gave the Documents module the "private artefact/blob storage and hashes, protected streaming, file-validation primitives and reconciliation/recovery". That was right while the only consumer was the official document archive. The owner now wants one reusable file foundation for Company logos and branding assets, Purchasing and supplier attachments, Party documents, Inventory item specifications, Job photographs and evidence, and generated official documents. [0081](0081-central-company-profile-and-company-wide-settings.md) deferred branding and logos "until a file-storage and branding architecture exists"; the [design](../architecture/shared-file-foundation-design.md) is that architecture. Keeping blob storage inside Documents would make every attachment feature depend on the print framework and blur ordinary files with immutable official originals.

## Decision

Introduce a dedicated **Files** module in the Laravel modular monolith. Responsibilities are split three ways.

| Owner | Owns | Does not own |
| --- | --- | --- |
| **Files** | Private storage; uploads and validation; immutable blob identities; versions; owner associations; secure (protected) delivery; integrity and hashes; audit evidence; quarantine, reconciliation and exact-byte recovery; holds. | Business meaning, access eligibility, retention decisions, document generation. Files grants no access by itself. |
| **Documents** | Official document generation, curated templates and page policies, manifests and generation status, immutable originals and labelled renditions, document-specific archive rules. | A second blob store, upload validation or storage recovery: it stores originals **through** Files. |
| **Business modules** | Their attachment purposes, record associations, access eligibility and audience, retention decisions and business events, registered through a typed file-owner adapter. | Storage mechanics. |

Two classes share the one blob store but never merge: an **ordinary attachment** (owner-associated, versioned, logically removable by the owner under its own rules) and an **official original or rendition** (created only by the Documents publish Action, never removable or replaceable, restored byte-exact, never shown as an attachment). Existing permission infrastructure, company-scoped composite references, append-only/TRUNCATE guards and the Documents adapter patterns are reused; no parallel permission system and no duplicate storage implementation.

### What changes in 0137

Only this clause moves from Documents to Files: "private artefact/blob storage and hashes, protected streaming, file-validation primitives and reconciliation/recovery" and the corresponding `document_blobs` table, which becomes `file_blobs` in Files. `document_artefacts` and heads in Documents reference `file_blobs` by company-scoped key instead.

### What does not change (preserved 0137, 0133–0135 and R8/R9 guarantees)

- Documents remains the one Documents infrastructure module: common rendering, versioned templates/page policies, manifests, deterministic identities and idempotent receipts; no separate PDF service; no universal issue/approval/posting lifecycle.
- Modules own required content, projection, snapshot timing, permitted purposes, access eligibility/audience, retention/holds and business events. Ordinary users need the owning adapter's file/download eligibility, not a second generic download permission; no central file-read-all right follows from template administration or technical recovery.
- Originals are generated from pinned facts only, private and company-scoped, never replaced; reprints reuse original bytes; repairs append separately labelled renditions; missing or corrupt originals are incidents, not regenerated. Database and object store are not claimed to share one transaction. Reconciliation and exact-byte recovery precede rollout.
- No public links, share tokens or raw storage keys; authenticated application download only ([0134](0134-archive-original-quotation-artefacts-with-private-recoverable-storage.md)). Company retains authoritative identity and branding facts and is read through a typed Company contract.
- No universal retention period, purge engine or automatic release.
- The sequence DF0/DF1a (integrated), DF2, DF3, DF4 and the Sales quotation pilot are unchanged except that the DF3/DF4 storage half is built on Files (F3 below). "DF3 original archive cannot be publicly enabled without DF4 recovery/access" still holds.

## Decision package D2–D12

Approval of a direction is not permission to activate anything. **Implementation requirements** are what the code must contain to be accepted. **Activation conditions** must hold, with evidence, before the capability is reachable by users or holds production data. Slice names refer to the delivery order below.

| # | Decision (owner, 2026-10-27) | Implementation requirement | Activation condition |
| --- | --- | --- | --- |
| D2 | Private local storage behind Laravel's configurable filesystem abstraction. | A dedicated `files` disk: private visibility, `throw` enabled, not the `public` disk, not a `serve`d disk, no `Storage::url()`/temporary URLs, no storage key or path in any Resource. Provider change is configuration only (F0). | A production volume outside the web root with restricted permissions is provisioned and recorded; covered by D12. |
| D3 | Scanning interface in the foundation; externally supplied documents need a successful malware scan before becoming downloadable. | A `FileScanner` port and a recorded scan state (`NOT_CONFIGURED`, `PENDING`, `CLEAN`, `INFECTED`, `ERROR`) with a default that scans nothing and says so (F0); purposes flagged as externally supplied are not streamable unless `CLEAN`, fail-closed (F1). | A real scanner is configured and tested before any externally supplied purpose (supplier, customer or other third-party documents) is enabled. Internally authored purposes such as a Company logo are not blocked by this condition. |
| D4 | PNG/JPEG logos initially; no SVG, HTML or executable uploads. | Closed per-purpose media-type allowlist; server-side sniff must agree with extension and declared type; SVG, HTML, scripts, executables, macro formats and archives rejected (F0). Image sanitising (metadata/active content) is settled in the approved F0/F1 scope; it needs an imaging extension that the development image does not have today. | The branding purpose is enabled only after its image-sanitising approach is implemented and tested. |
| D5 | Configurable per-purpose limits, initially 2 MB logos, 10 MB photographs, 20 MB documents. | Limits live in purpose configuration, are enforced while streaming (not only after upload), and are tested at the boundary (F0). | PHP and proxy limits on the deployed stack are verified to be at least the largest enabled purpose limit, and the application limit remains the authoritative one. |
| D6 | Company branding/logo is the first real consumer. | Nothing in F0–F2 builds branding. A Company owner adapter for `company.brand.logo` is its own authorised slice after F2. | Logo upload is activated only after F2 and the D12 sign-off. [#23](https://github.com/kaleemnadeem16/erp/issues/23) stays the deferred metadata-only proposal; its `assets: UNSUPPORTED` state changes only by separate authorisation after that. |
| D7 | Encrypted storage and backups; no application-level encryption unless justified. | None in application code; no key management is introduced. Any later application-level encryption needs a written justification and a new decision. | Operations confirm the volume and backup destination are encrypted at rest (recorded with D12). |
| D8 | Retention categories and legal hold; no universal purge schedule. | A `retention_class` label on attachment versions and a hold record with place/release evidence (F1/F2); holds block removal disposition and purge. No retention period, purge engine or scheduled deletion. Only proven-unreferenced staging objects past grace and fencing and with no hold may be deleted (F2). | Each consumer purpose has owner-approved retention categories before it activates. |
| D9 | No deduplication initially. | One blob per upload; no unique constraint on the hash; no cross-company object sharing (F0). | None. Same-company deduplication would need a new decision. |
| D10 | Official documents may reference only explicitly authorised, pinned file versions. | An attachment version pinned into a manifest is referenced by version identity and hash; Documents never reads a live attachment; the owning adapter must authorise each file (F3). Pinned versions cannot be removed or edited. | Activated per document type with its owner; nothing is pinned by default. |
| D11 | Conservative company storage quotas and actor upload-rate limits before public activation. | Quota and rate-limit seams with configuration, plus tests that refusal is clean and leaves no staged object (F1). | Conservative per-company storage quotas and per-actor upload rates are configured and tested before any user-reachable upload route is enabled. |
| D12 | Verified database-plus-file backup and restore before production uploads, with designated operations sign-off. | A documented procedure to back up and restore database and files together and a verification step that re-checks every `VERIFIED` blob's hash and tenant/owner link before marking restored data usable (F2); [backup-and-restore.md](../architecture/backup-and-restore.md) updated by the slice that stores files. | A restore rehearsal is completed and signed off by the designated operations owner before any production upload. Database-only backup is explicitly insufficient. |

### Delivery order (owner-fixed)

F0 disabled storage kernel → F1 governed attachments and protected downloads → F2 recovery, quarantine, reconciliation and holds (before any production uploads or official archive activation) → Company branding real logo uploads → F3 integration with official Documents generation and archives → other modules (Purchasing, Parties, Inventory, Jobs). Each slice is separately authorised and ships disabled until its activation conditions hold. F0 additionally needs the owner's approval of its exact scope on [#49](https://github.com/kaleemnadeem16/erp/issues/49).

## Consequences

- **Positive:** one storage, validation, recovery and audit implementation for every file use; attachments no longer depend on the print framework; the official-original guarantees stay intact because Documents alone can create originals; DF3/DF4 gain a smaller foundation that is testable earlier; unblocks the 0081 branding deferral through a proper path.
- **Negative:** a new module and a new owner-adapter contract; DF3/DF4 plans and [documents.md](../domain/documents.md) storage tables need a follow-up edit to reference `file_blobs` (not done by this record); one more ADR to read next to 0137; the foundation carries cost before the first user-visible benefit.
- **Requires:** owner acceptance of this ADR; the F0 scope approved on #49; the design's security requirements and the activation conditions above evidenced per slice; a status-line change on 0137 and an update to its README row when this is accepted (left to the integrator, since this task edits no other ADR); the Files section of the status ledger written by the integrator.
- **State:** PROPOSED architecture. Nothing is IMPLEMENTED or VERIFIED.

## Alternatives considered

**Keep blob storage inside Documents (0137 as written)** — fewer modules, but every module's attachments would depend on the print framework and ordinary files would blur with official originals.
**A Company-specific logo upload** — rejected by the owner: a temporary, non-reusable path that would be replaced.
**Per-module storage** — duplicates validation, quarantine, recovery and audit in every module.
**Public disk or signed temporary URLs** — contradict 0134 (no public links or tokens) and make access depend on possession of a URL.
**Content-addressed deduplicated storage (Odoo/Frappe style)** — saves space but leaks cross-company existence and complicates per-company recovery and purge (D9).
**Application-level encryption now** — adds key management before any file exists; disk and backup encryption suffice initially (D7).

## Research basis

Reuses the [shared file foundation design](../architecture/shared-file-foundation-design.md) (research and design, #47), which compares primary documentation and source for Odoo (`ir_attachment.py`), ERPNext/Frappe (`file.py` and attachments documentation), Dynamics 365 Business Central (external file storage for document attachments) and Dataverse (file and image columns), accessed 2026-10-27, with the OWASP File Upload cheat sheet basis from [0134](0134-archive-original-quotation-artefacts-with-private-recoverable-storage.md). Common pattern: files attach to an owning record and are secured through it, stored outside the database, identified by a hash, and removed lazily. Differences adopted or refused are listed in the design (no public/token sharing, no cross-tenant deduplication, reconciliation and a backup proof instead of leaving backup to the operator). The earlier [Q50 comparison](../architecture/document-print-framework-review.md) behind 0137 is unchanged.

## Related

- [0137](0137-share-document-and-print-infrastructure-through-module-adapters.md) · [0134](0134-archive-original-quotation-artefacts-with-private-recoverable-storage.md) · [0081](0081-central-company-profile-and-company-wide-settings.md)
- [Shared file foundation design](../architecture/shared-file-foundation-design.md) · [Documents contract](../domain/documents.md) · [framework slices](../phases/document-print-framework-slices.md) · [backup and restore](../architecture/backup-and-restore.md)
