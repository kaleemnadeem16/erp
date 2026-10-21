# Documents module

Shared print, artefact and private-file infrastructure ([ADR 0137](../../../../docs/decisions/0137-share-document-and-print-infrastructure-through-module-adapters.md);
contract: [documents.md](../../../../docs/domain/documents.md); slices: [document-print-framework-slices.md](../../../../docs/phases/document-print-framework-slices.md)).
**DF0 only: the public entry is disabled** (no routes, no permission vocabulary, no live duty, no generated-client change).

## Owns

- Tables: `document_subject_bindings` (one identity per registered type, audience, owner binding identity and source generation) and
  `document_operation_receipts` (operation key + intent digest → binding). Both are append-only (`documents_append_only_guard`).
- `Support\DocumentTypeAdapter`, `DocumentAccess`, `DocumentProvider` and `DocumentTypeRegistry`: the contract an owning module implements and registers
  from its own service provider. `ProjectionSchema`/`SectionSchema`/`FieldSpec`/`FieldType`: the allowlist an adapter declares for its typed projection.
- `Actions\BindDocumentSubject`: internal, idempotent, fails closed (missing adapter, wrong version/audience, denied or throwing access, invalid projection).

## Boundaries

- The owner supplies the projection, totals, access, audiences and retention; Documents validates shape and never recomputes money, quantities or consent.
  There is no universal line schema: each adapter declares its own sections.
- The owning module's own binding table holds the real FKs to a Documents binding and to its subject. Documents holds no subject FK.
- Registration is not permission. Documents grants nothing.
- Money/quantities are decimal strings, never floats.
- Not here yet: templates, page policies, Company brand (DF1), manifests, freeze and intents (DF2), renderer (DF3), recovery and files (DF4), pilot (DF5).
  The quotation binding and Sales snapshot tables are Sales-owned.
- Purchasing and the existing PO Quick Print are untouched; the PO-shaped adapter lives under `tests/Support/Documents` and writes nothing.

## Adding a document type

Implement `DocumentTypeAdapter` (and `DocumentAccess`) in the owning module, expose it via a `DocumentProvider`, and call
`DocumentTypeRegistry::registerProvider()` from that module's service provider. Bind with `BindDocumentSubject` inside the owner's transaction.
