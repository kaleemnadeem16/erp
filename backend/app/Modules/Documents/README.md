# Documents module

Shared print, artefact and private-file infrastructure ([ADR 0137](../../../../docs/decisions/0137-share-document-and-print-infrastructure-through-module-adapters.md);
contract: [documents.md](../../../../docs/domain/documents.md); slices: [document-print-framework-slices.md](../../../../docs/phases/document-print-framework-slices.md)).
**DF0 + DF1a: the public entry is disabled** (no routes, no permission vocabulary, no live duty, no generated-client change).

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
  is refused while `DocumentConfigurationUsage` reports the version pinned, bound to `NoPinnedConfiguration` until DF2), `ResolveDocumentPolicy` (shipped →
  Company `'*'` → type → explicit; **brand is always `NOT_AVAILABLE`**, never a fallback; reads no user preference).
- `Actions\BindDocumentSubject`: internal, idempotent, fails closed (missing adapter, wrong version/audience, denied or throwing access, invalid projection).

## Boundaries

- The owner supplies the projection, totals, access, audiences and retention; Documents validates shape and never recomputes money, quantities or consent.
  There is no universal line schema: each adapter declares its own sections.
- The owning module's own binding table holds the real FKs to a Documents binding and to its subject. Documents holds no subject FK.
- Registration is not permission. Documents grants nothing.
- Money/quantities are decimal strings, never floats.
- Not here yet: Company brand and assets (a separate Company-owned task), manifests, freeze and intents (DF2), renderer (DF3), recovery and files (DF4), pilot (DF5).
  The quotation binding and Sales snapshot tables are Sales-owned.
- Purchasing and the existing PO Quick Print are untouched; the PO-shaped adapter lives under `tests/Support/Documents` and writes nothing.

## Adding a document type

Implement `DocumentTypeAdapter` (and `DocumentAccess`) in the owning module, expose it via a `DocumentProvider`, and call
`DocumentTypeRegistry::registerProvider()` from that module's service provider. Bind with `BindDocumentSubject` inside the owner's transaction.
