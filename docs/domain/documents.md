[← Domain index](README.md)

# Documents — shared print, artefact and private file contract

**2026-10-15 · ACCEPTED0137 / SPECIFIED. DF0 foundation implemented (disabled entry; subject bindings and operation receipts only); the rest is not IMPLEMENTED or VERIFIED.**
Documents is shared infrastructure within Laravel, with shared React primitives and a bounded isolated
render worker in the same application deployment. It is not a separate PDF service or a universal
business lifecycle. Owners supply typed authorised projections and business policy through adapters.
The first real adapter is Sales Quotation; other types need not be implemented first.

## Ownership and adapter boundary

Documents owns templates/page policies, input manifests, generation/status/receipts, file validation,
blobs/artefacts/versions, protected stream mechanics and recovery/audit. Company owns authoritative
identity/contact/branding and governed brand asset references under0081. Module adapters own required
facts, measures/totals, allowed layout/content/purpose/audience, snapshot event, subject access and
retention/hold/disposition. They keep issue/approval/Finance posting/acceptance/delivery records.

Register a trusted type/schema/policy adapter in its module provider: e.g. sales.quotation.customer,
purchasing.purchase-order.supplier or inventory.count-sheet.blind. Registration is not permission,
an official number or adapter delivery. Reject unknown/incompatible type/version; no arbitrary class,
SQL, URL, executable template, Eloquent model or unchecked polymorphic subject supplied by a client.

Required adapter contract:

- Typed company/subject/source-generation/audience binding and minimum authorised data profile.
- Authorise operation for actor/service, subject, purpose and attachment association under current
  module duty/scope, including independent confidential evidence eligibility where appropriate.
- Supply allowed draft or immutable frozen projection and required-content validation; declare
  permitted layouts/page profiles, semantic repeat/keep-together rules and snapshot event.
- Supply policy/provenance versions, permissible original/rendition purposes, semantic-equivalence
  check and owning incident/retention/hold/release decisions. Missing callback fails closed.

Shared code validates shape, page/asset compatibility and file integrity, never recomputes monetary
totals, stock quantities or consent. Read a lawful frozen customer snapshot without inventing a
requirement to browse every current Item/Assembly; mutations still require their actual source access.

## Persistence — logical pseudo-DDL, one common archive

```
document_template_versions
  id, company_id, type_key, template_key, version, status; immutable once used
  schema/layout_version, permitted_sections, constraints, asset_manifest
document_page_policy_versions
  id, company_id, type_key nullable, version, format A4|LETTER, orientation
  physical_dimensions/margins/header_footer rules, compatibility, resource_policy_reference
document_company_defaults
  company_id, generation, active_template/page_policy references by allowed type
  Company brand_version/asset references; not duplicated Company identity fields
document_subject_bindings
  id, company_id, registered_type, audience/profile, source_generation, owner_binding_identity
  policy_version, provenance; owner-specific binding table supplies actual tenant-safe subject FK
document_manifests
  id, company_id, subject_binding_id, stable_generation_identity, purpose, input_digest
  frozen_payload/schema_version, source_snapshot_reference, resolved_template/page/brand/assets/fonts
  renderer_contract/version, locale/formatting, pinned_manifest_digest, initiator/recorded_at
document_generation_intents / document_generation_runs
  id, company_id, manifest_id, operation_identity, request_key/intent_digest
  state PENDING|RUNNING|FAILED|VERIFIED, lease_expiry, fencing_generation, error_code
document_blobs
  id, company_id, opaque_key/storage_generation, expected_hash, size, media_type
  health STAGED|VERIFIED|MISSING|QUARANTINED, reconciliation_evidence
document_artefacts
  id, company_id, binding/manifest/run/blob references, purpose, original_parent_id nullable
  immutable_identity, hash, size, provenance, verified_at
document_artefact_heads
  company_id, binding_id, original_purpose/profile, original_artefact_id nullable, generation
  UNIQUE(company_id, binding_id, original_purpose/profile)
document_attachment_versions / document_file_associations
  company_id, blob/version, typed_owner_binding, audience/access_profile, safe_name
  validation/quarantine evidence, snapshot_link, retention/hold references
document_operation_receipts / document_audit_events
  company_id, operation/key, intent_digest, original_result_identity, actor/service/time/evidence
```

Business references use composite company keys, not JSON-only tenancy validation. Owning binding
table has real FKs to both Documents binding and actual subject; registry+adapter validates source
type. Unique keys prevent duplicate original per intended binding/profile; genuine separate audience
documents need explicit owner-approved identity, not a fresh UUID. Used templates/manifests/artefacts/
receipts/audits are immutable. Mutable run/head/health projections preserve append-only evidence.
Bytes are outside PostgreSQL. FinancialActivity probes apply to any new monetary rows; domain
financial evidence remains owner-owned. Decimal values remain strings/decimal(19,4), never float.

## Company, template and page configuration workflow

Shipped global default → governed Company defaults/brand → compatible document-type template/page
policy → permitted explicit document choice. Resolve/version each component, then owner validates
required fields, legal/commercial terms and permitted purpose/format. Explicit invalid choice refuses;
type constraints override incompatible defaults. Company branding is read through Company contracts,
not copied live into an unrelated settings bag. Documents owns typed rendering policy configuration;
Company UI may compose/link its settings without transferring business policy ownership.

Prepare/validate/activate new version via owning guarded Action and reason/audit where governed.
Used versions/assets cannot be edited/removed in place. Template/page settings include known curated
layout ID, first/continuation header/footer, table rules, physical paper/margins, font/assets, optional
sections and mandatory-content constraints. No arbitrary CSS/HTML/script execution/configuration
language. Adapter describes supported editable fields; renderer resources cannot be relaxed by tenant
configuration. Downgrade checks active manifests/used assets; history remains readable or change refuses.

At owner snapshot event pin payload, source/policy generation, template/page/brand/assets/fonts,
renderer/schema and locale/date/currency formatting. Current defaults/user local preferences affect
future eligible renders, not original bytes. A common footer cannot mutate module-owned contract terms.
Sales quotation retains initial A4; Letter is shared capability for explicitly supported adapters or
fixtures. Supporting Letter does not silently extend0133. English/LTR stays initial supported locale.

## Generation, versioning and reprint workflow

Owner transaction freezes business facts and calls Documents owning Action synchronously to bind
resolved manifest plus durable generation intent, with proven same-connection participation/lock order.
Required manifest and business snapshot roll back together; default Sales issue need not render first.
Prepared-before-issue remains unsupported unless actual exact number/date/fact adapter exists.
Optional after-commit event may wake worker but cannot be sole durable intent. No source business
lifecycle is inferred from technical PENDING/RUNNING/VERIFIED statuses.

Worker claims persisted intent/lease/fence, renders pinned inputs outside long DB transaction, writes
staged private object, verifies output/hash/size and publishes artefact/head/receipt in short fenced
transaction. Locks: owning business coordinator/source before Documents binding/manifest/head/run;
worker publication never acquires downstream lock then calls owner in reverse. Fresh key for same
generation resolves original result; changed intent under key conflicts, current access before replay.
Duplicate/stale worker cannot publish another original. Actual lock/connection proof awaits delivery.

DraftPreview is conspicuously DRAFT, bound to draft generation and no counterfeit official number.
GenerateOriginal reads frozen inputs only. Reprint retrieves original bytes with current eligibility,
records invocation/download evidence; no PDF regeneration or proof of customer receipt. RepairRendition
creates separate manifest/identity linked to original after owner semantic-equivalence/authority check.
Contractual/accounting changes require owning successor/correction; no framework edit of original.
Document history distinguishes original, labelled rendition, technical failure and external business
status projection. Never promise byte-identical rerender without actual renderer verification.

## Storage reconciliation and secure recovery workflow

Object-store and database are not one transaction. Incomplete object/run is retryable under fence;
completed object with no committed artefact is reconciled from durable intent/hash/provenance or
quarantined. Committed artefact with missing/corrupt blob is unavailable; block official send/download,
alert and restore exact bytes from verified copy. Rerender is not recovery of missing original.
Lost response after commit replays receipt; stale lease completion cannot overwrite authoritative head.

Reconcile/Restore Actions carry scoped recovery authority, expected hash, reason and immutable audit;
technical operator receives metadata minimum, not a general right to read confidential content. Restore
metadata+blobs+assets together, verify tenant/owner links and hash consistency before marking usable.
Existing database backup practice is not proof future blobs are backed up. Preserve uncertain files;
delete only proven unreferenced staging objects after configured grace/fencing and no applicable hold.
Used original/attachment purge requires every owner reference/retention/hold disposition approved;
no universal retention period, purge engine or automatic release is introduced by0137.

## Protected downloads and attachment workflow

Module Request/Action obtains current subject+operation+audience eligibility via adapter, then shared
Documents Action revalidates company/binding/association/profile/health and streams safe bytes using
safe filename/media/attachment headers. IDs/path possession never authorise access. No redundant
generic download capability, public links/tokens or raw storage keys in Resources. Missing owner
adapter denies. Full financial original cannot be redacted and called original: quantity-only user
gets an explicitly permitted separate profile/rendition or refusal. Current access applies on every
download/history/search/status/replay; cache hit is not an authorisation decision.

Attachment association belongs to owner and audience. Reuse private blob store/validation/status,
but confidential R6 consent file is not a customer send attachment. Same blob linked to several
subjects exposes only current authorised association, no enumeration of other links. Validate bounded
type/extension/signature/size, opaque names, quarantine/scan/content checks, no executable/active files.
Preserve original proof separately from sanitised view copy. Protect errors/logs/cache/thumbnails/indexes.
Cache namespace includes company/type/subject generation/audience/projection schema/template/assets/
page policy; cross-company hash equality grants nothing. CSRF/throttle/current safe API conventions apply.

## Actions, Requests, APIs, UI and duty boundary

Specified Documents Actions: ResolveDocumentPolicy, Version/ActivateTemplateOrPagePolicy,
BindDocumentManifest, RequestDocumentGeneration, GenerateDocumentArtefact, PublishDocumentArtefact,
ReconcileDocumentStorage, RestoreDocumentArtefact, CreateLabelledRendition, ValidateAttachment,
AssociateFile and StreamAuthorisedDocument. Names specify contracts, not existing classes.
Company controls brand/profile changes; owners validate business projection/binding/purpose. Shared
Requests carry exact versions/expected state/idempotency and adapter operation context, never caller-
asserted authorisation. Both HTTP and Action enforce authority; services use explicit constrained identity.

Proposed shared APIs: template/page versions/configuration; scoped generation/status/history/recovery;
file-validation/status plus protected download through registered owner access binding. Module APIs
provide business commands and subject-safe view. Resources omit keys/secrets/protected operands and
report stable pending/failed/unsupported/stale/missing/quarantined states safely. Shared React status/
preview/download/history/config editors use same trusted layout pipeline for formal preview; no second
frontend formal paginator. Existing Quick Print remains unchanged until an authorised module adopts.

0137 accepts ownership;0138 now accepts reconciled exact duty catalogue. [R9 matrix](sales-duty-matrix.md) specifies shared template/page-policy admin,
technical recovery, Company-owned branding, and module-owned generate/download/repair/attachment
eligibility. Config administrator cannot issue or access files by implication; worker is restricted
to company/type/manifest/purpose and may never accept or post. Exact grant/migration/profile choices
are specified in R9 rollout contract; no new registry or real grants exist.

## Pagination, isolation, performance and acceptance evidence

One renderer implementation with A4/Letter profiles, reusable first/continuation/header/footer/table/
long-row primitives. Adapter defines columns, sections, repeat semantics and required totals/terms.
No clipping, dropped content, duplicate amount on fragmented rows or split protected totals. Verify
actual PDF pages/text/rows and rendered page images for short/50+ lines/oversized row/multi-page terms,
font/logo failure and Letter fixture. No browser-height-only or mock-only claim of formal correctness.

Bounded isolated worker/container in application deployment: restricted I/O/network/host mounts/no
secrets/untrusted JavaScript, only supplied trusted assets, CPU/memory/time/pages/assets/bytes caps,
bounded per-company concurrency/fairness. No external renderer API or arbitrary URL fetch. Queue/render
outside php-fpm/DB request locks; bounded queries, tenant/type/status/lease indexes, streamed blobs,
reprint reuse, bounded reconciliation batches. Respect1-vCPU dbvm and shared Redis namespaces.
Pin runtime dependencies/images for amd64+arm64, verify fonts/output on deployment architecture.
Observe queue age/duration/pages/failures/storage/health/access without payload logging; no unmeasured SLA.

Release evidence: real same-connection binding rollback, fresh-key original race/stale-worker fencing,
all storage crash windows/exact recovery, tenant/profile/attachment denial including cache paths,
protected-data absence, immutable used configuration, A4/Letter pagination and PO-shaped fixture.
No actual PO/Invoice/Inventory adapter is required for quotation first delivery. Future adapter must
prove its own snapshot/access/retention semantics; framework availability does not prove support.

## Related

- [0137](../decisions/0137-share-document-and-print-infrastructure-through-module-adapters.md)
- [Framework sequence](../phases/document-print-framework-slices.md) · [R8 slices](../phases/phase-4-r8-implementation-slices.md)
- [Quotation adapter](quotation-documents.md) · [Delivery](quotation-delivery-evidence.md)
- [Retained framework comparison](../architecture/document-print-framework-review.md) · [R9 final review](../phases/phase-4-r9-authority-reporting-review.md)
