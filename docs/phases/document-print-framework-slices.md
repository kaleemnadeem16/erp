[← Phase index](README.md)

# Documents foundation and first quotation adapter sequence

**2026-10-15 · ACCEPTED0137 architecture / SPECIFIED; no application IMPLEMENTED or VERIFIED.**
Every slice needs separate code authorisation. R9 final names/scope/grants await owner approval.
Initial deliverable is one coherent Documents foundation plus real Sales quotation adapter and
PO-shaped compatibility fixture. No actual PO refactor or universal adapter rollout is authorised.

| Order | Shared Documents / Company delivery | Sales adapter dependency | Required verification |
|---|---|---|---|
| DF0 — Registry and typed binding | Documents owning provider/type/access contracts, tenant-safe common model/constraints/receipt/immutability foundation; disabled public entry | Quotation binding skeleton and required allowlist/schema; pure PO-shaped typed fixture without Purchasing writes | Missing adapter refuses; raw cross-company binding/FK/direct invalid writes; no Sales-only universal line schema, no duplicate archive |
| DF1 — Configuration and layouts | Company-owned branding version/assets, Documents-owned typed template/page defaults, resolution/activation, A4+Letter/page primitives | Source-neutral quotation body/mandatory content/options; quotation restricts A4; Company facts through owning contract | Version/change/downgrade rules; incompatible format rejected; user local preference cannot mutate official output; Letter fixture plus quotation A4 |
| DF2 — Freeze and generation intents | BindDocumentManifest + durable intent/head/receipt participates in owner DB transaction; guarded DRAFT preview/status | DOC0/1 + real QL0–QL3/R3/R4 source and issue contracts; exact final number/date/input versions | Failure after binding rolls back required snapshot; issue render independence; future prepared-required unsupported until its actual adapter |
| DF3 — Renderer and archive | Isolated bounded worker, lease/fence, pinned local assets, staged blob→verified artefact/original; shared status/retry/download primitives | DOC2 real quotation adapter; R9 rights for first public route, safe projection only | Real two-worker fresh-key race, stale fence, lost response, pages/text/visual validation; no current masters/side effects |
| DF4 — Recovery and secure files | DB/object reconciliation, protected exact-byte restore, association/access/upload/quarantine/history and labelled-rendition infrastructure | DOC3/4 owner repair/access/retention callbacks, manual delivery separately Sales | Crash windows/orphans/corruption/recovery; tenant/profile/current access/cache/attachment denial, config admin no file access, backup+blob restore proof |
| DF5 — Coherent pilot rollout | Guarded API/client/shared UI, safe registration/grants, idempotent fixtures and operator runbook; all required DF paths enabled together | DOC4 quotation print/download/manual records; DOC5 only affected R6 methods; no Finance/Inventory/Job implementation dependency fabricated | Short/50+ quotation/long row/options/terms plus PO-shaped fixture; unsupported modes disabled; default issue+failed generation+retry+original retrieve+manual evidence journey |

DF0/1 can be delivered disabled without implemented quotation issue; DF2 production binding needs
real QL3 integration. DF3 original archive cannot be publicly enabled without DF4 recovery/access.
DF5 requires authorised R9/read grants and actual adapted Sales source Actions. Architecture and
foundation scaffolding cannot be reported as delivered quotation document workflow.

## DOC ownership and model allocation

DOC0–DOC3 reuse shared document_template_versions/page policies/manifests/intents/runs/blobs/
artefacts/heads/receipts/file associations from [Documents](../domain/documents.md). Sales owns
quotation_customer_document_snapshots and quotation_document_bindings to real issued revision.
Binding/manifest references supply provenance, not a second original/blob/render-run table.
Company branding remains Company-owned; Documents print policy UI does not duplicate identity.
DOC4 module access/manual evidence, DOC5 R6 consent, DOC6 email delivery and DOC7 future contractual
capabilities stay with their owners and reuse shared bytes. No generic email platform is in DF0–DF5.

## Per-slice delivery contract

- **Actions/Requests/API:** both guards, exact version/fence/key inputs, current adapter access and
  service identity; safe Resources/OpenAPI/generated client together in actual implementation.
  Shared technical commands never accept client-provided permission verdicts. List/count/history/
  lookup/search/preview/stream/cache replay all enforce same company/profile eligibility.
- **Migrations:** additive common tables and owner binding FKs, company keys/unique identities,
  immutable evidence, real down with retained-evidence limitations; isolated apply/rollback/reapply.
  No fake historical originals/consent; legacy current PO Quick Print unaffected. FinancialActivity
  integration for whichever new tables actually store money; no generic privileged migration grants.
- **UI:** shared configuration/version editor, formal preview/status/retry/original-rendition history,
  protected files and restricted recovery interface, standard states/sheets/light/dark/phone;
  literal original bytes returned after master/default changes, no duplicate frontend paginator.
- **Permissions/operations:** R9 registry/explicit grants/migration/mandates and independent owner
  access before public surface; company scope preserved through re-sync. Technical recovery no
  general business read, config no issue rights. Runtime caps/patching/secret isolation, bounded
  queues/indexes/reconciliation and metadata+blob backup/restore included before official rollout.
- **Demos/verification:** idempotent narrow personas for quotation originals/pending/failed/repair/
  missing file/denied internal attachment; pure PO-shaped compatibility fixture avoids live PO
  semantics. Real HTTP/Action/SQL/worker/transaction races and file security plus actual PDF visual
  checks. No real email, production test/seeding or altered existing demo credentials.
- **Completion:** code owner reports actual applicable pre-push ratchets/backend/migration/client/
  build/browser/PDF checks and publishes owning docs/journey/status alongside delivery. Planning
  here performs docs checks only, not those application verifications or module README creation.

## Deferred dependencies

Actual PO, Invoice, Inventory, Sales Order/Job adapters need separately approved owner snapshot/
projection/retention/workflow contracts; not blockers to the quotation-first pilot. Email/provider
durable retry, portal/signatures, advanced designers, bulk/network printing, multilingual/RTL and
additional engines remain DOC6/7 later capabilities. R5/R6 partial-family and R7 downstream impact
gates unchanged. Renderer package/runtime choice needs measured authorised implementation spike;
no library installed, engine performance or byte-reproduction claimed verified by these specifications.

## Related

- [0137](../decisions/0137-share-document-and-print-infrastructure-through-module-adapters.md)
- [Documents contract](../domain/documents.md) · [R8 DOC0–DOC7](phase-4-r8-implementation-slices.md)
- [R9 final matrix](phase-4-r9-authority-reporting-review.md) · [Pre-push checks](../architecture/pre-push-checks.md)
