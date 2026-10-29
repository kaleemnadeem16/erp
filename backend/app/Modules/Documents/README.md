# Documents module

Shared print, artefact and private-file infrastructure ([ADR 0137](../../../../docs/decisions/0137-share-document-and-print-infrastructure-through-module-adapters.md);
contract: [documents.md](../../../../docs/domain/documents.md); slices: [document-print-framework-slices.md](../../../../docs/phases/document-print-framework-slices.md)).
**DF0 + DF1a + DF2: the public entry is disabled** (no routes, no permission vocabulary, no live duty, no generated-client change).

## Owns

- Tables: `document_subject_bindings` (one identity per registered type, audience, owner binding identity and source generation) and
  `document_operation_receipts` (operation key + intent digest → binding). Both are append-only (`documents_append_only_guard`).
- `Support\DocumentTypeAdapter`, `DocumentAccess`, `DocumentProvider` and `DocumentTypeRegistry`: the contract an owning module implements and registers
  from its own service provider. `ProjectionSchema`/`SectionSchema`/`FieldSpec`/`FieldType`: the allowlist an adapter declares for its typed projection.
- DF1a configuration: `document_template_versions`, `document_page_policy_versions` (both insert-only, so a used version cannot change; a change is a new
  version), `document_company_defaults` (the active choice per type, `'*'` = Company-wide; moves one generation at a time) and `document_default_activations`
  (append-only history the database ties to the default at commit). Curated and typed only: `DocumentLayouts` ids, `TemplateConfiguration`,
  `PagePolicyConfiguration`, closed `PageBandMode`; no CSS, HTML or script, no brand or asset columns.
- Type constraints: `LayoutConstraints` (formats, layouts, permitted/mandatory sections) override any default that does not fit. The default is **A4 only**,
  sections taken from the projection schema; an adapter opts into Letter by implementing `ConstrainsDocumentLayout`.
- Actions (internal): `VersionDocumentTemplate`, `VersionDocumentPagePolicy`, `ActivateDocumentConfiguration` (generation-locked; downgrade needs a reason and
  is refused while `DocumentConfigurationUsage` reports the version pinned; since DF2 that port is bound to `ManifestConfigurationUsage`, so a version any frozen manifest resolved to is pinned), `ResolveDocumentPolicy` (shipped →
  Company `'*'` → type → explicit; **brand is always `NOT_AVAILABLE`**, never a fallback; reads no user preference).
- `Actions\BindDocumentSubject`: internal, idempotent, fails closed (missing adapter, wrong version/audience, denied or throwing access, invalid projection).
- DF2 freeze and generation intents (issue #26): `document_manifests` (insert-only; per (binding, purpose `DRAFT_PREVIEW|ORIGINAL`, stable generation identity) the frozen
  typed payload and schema version, source generation, template and page policy **as resolved configuration** (a shipped default is pinned too) with their version ids
  when they come from a row, brand `NOT_AVAILABLE`, empty asset/font lists, renderer contract, version and fonts pinned from the renderer (below; `UNASSIGNED` only on rows frozen before DF3), English/LTR formatting, an input digest and a pinned
  digest, with the database clock as its time) and `document_generation_intents` (a mutable projection born PENDING at fence 0; the database guards its state machine
  and a monotone fencing generation; DF3 owns every transition after PENDING). `Actions\BindDocumentManifest` is the owner's snapshot event, called in the owner's own
  transaction: owner access (fail-closed, refusals logged as one structured line without content) → schema validation → resolution under shared locks that
  a configuration activation takes exclusively → manifest, `initial` intent and receipt. A fresh key for the same identity and the same frozen content resolves the
  ORIGINAL manifest whatever the configuration has become; different content for that identity is `manifest_conflict`. `RequestDocumentGeneration` (a retry intent, only
  after every earlier intent FAILED) and `ReadDocumentGenerationStatus` (pending/running/failed/verified/missing; no payload, key or owner identity) ask the owner
  every time. The Company default activation chain is now database-enforced (each activation continues the previous one; `is_downgrade` is derived). No route, grant,
  duty, renderer, byte or blob. An ORIGINAL is authorised as its own operation (`bind_manifest_original`) with the purpose, source generation, snapshot reference and
  stable generation identity in `DocumentOperationContext`, so an owner that allows drafts can refuse originals; Documents infers no business state (issued, accepted)
  from them. At most one ORIGINAL exists per binding (a changed document is a new source generation, hence a new binding), and an explicit template or page-policy
  choice that differs from the pinned one is `manifest_conflict` even under a fresh key. A manifest that pinned the `UNASSIGNED` renderer and no fonts
  (`DocumentManifest::isRenderable()` is false) must never be rendered by DF3 with whichever renderer is current, nor mutated: it is insert-only.

- DF3 renderer, stored originals and secure delivery: `Support\Render\PdfDocumentRenderer` (contract `document-pdf/1`, pure PHP, deterministic, standard Helvetica fonts, no
  active content or external reference) renders a manifest alone — its frozen payload, resolved template and page policy; a DRAFT_PREVIEW is marked on every page, an
  ORIGINAL never. `document_artefacts` (insert-only; one per generation intent, tied to a VERIFIED Files blob with the same hash and size, and accepted by the database
  only from the worker that holds the RUNNING intent at exactly that fence) and `document_artefact_heads` (an ORIGINAL head is written once and never moves; a DRAFT_PREVIEW
  head advances one generation at a time). `Actions\GenerateDocumentArtefact` is the worker: owner access → short claim (PENDING or expired lease → RUNNING, new fence,
  database-clock lease; a live lease is refused; VERIFIED replays) → render outside any transaction → store through Files (request, stage, verify) under the
  `documents.artefact` file owner → fenced publish of artefact, head, VERIFIED intent and receipt. A failure while holding the fence marks the intent FAILED with a stable
  code; a manifest that pinned no renderer is `manifest_not_renderable`, never rendered by whichever renderer is current. `StreamAuthorisedDocument` asks the owner
  (`download`), requires a VERIFIED blob and a matching hash and returns a `DocumentDownload` (attachment, generated name, `nosniff`, `no-store`, no key or path).
  `ReconcileDocumentArtefact` / `RestoreDocumentArtefact` mark a missing or corrupt object and bring it back **only from bytes with the recorded hash**; a regenerated
  file is never a recovery of an original. No route, grant or duty.

- Every history table also carries a `BEFORE TRUNCATE ... FOR EACH STATEMENT` trigger on the same guard (row triggers never fire on TRUNCATE, and the runtime role is a
  superuser, so revoking the privilege would not help). A new Documents history table adds both triggers in its own migration.

## Boundaries

- The owner supplies the projection, totals, access, audiences and retention; Documents validates shape and never recomputes money, quantities or consent.
  There is no universal line schema: each adapter declares its own sections.
- The owning module's own binding table holds the real FKs to a Documents binding and to its subject. Documents holds no subject FK.
- Registration is not permission. Documents grants nothing.
- Money/quantities are decimal strings, never floats.
- Not here yet: Company brand and assets (a separate Company-owned task), archive and retention, a delivery route and Sales' quotation binding (DF4), pilot (DF5).
  The quotation binding and Sales snapshot tables are Sales-owned.
- Purchasing and the existing PO Quick Print are untouched; the PO-shaped adapter lives under `tests/Support/Documents` and writes nothing.

## Adding a document type

Implement `DocumentTypeAdapter` (and `DocumentAccess`) in the owning module, expose it via a `DocumentProvider`, and call
`DocumentTypeRegistry::registerProvider()` from that module's service provider. Bind with `BindDocumentSubject` inside the owner's transaction.
