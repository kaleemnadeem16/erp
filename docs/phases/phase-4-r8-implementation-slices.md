[← Phase index](README.md)

# R8 — Document, delivery and evidence implementation slices

**2026-10-15 · D1–D8 ACCEPTED0133–0135, Q48 closed; SPECIFIED, not IMPLEMENTED or VERIFIED.**
Every slice needs separate code authorisation. R9 duty/grant/scope architecture is accepted0138–0140; actual registration/holders remain undelivered;
no public R3–R8 route becomes authorised by this specification. Existing PO Quick Print is neither
immutable quotation archive nor formal backend PDF. Do not change implementation-owned delivery files.

## Ordered delivery

| Slice | Models/Actions/Requests/API/UI | Dependencies and boundary | Verification/demo evidence required |
|---|---|---|---|
| DOC0 — Projection, manifest and security foundation | Immutable customer snapshot, template/asset versions, generation/artefact/blob/attachment references and receipts; BuildCustomerProjection/PinRenderManifest, upload/quarantine/download policy ports | R2/R3/R4 typed scope/totals, QL0/QL3 freeze boundary, R9; no public writer until complete authority; company FKs/constraints/FinancialActivity where monetary | Raw invalid tenant/identity writes refused; protected cost/notes absent at all nested depths; mandatory disclosure cannot be configured away; disabled renderer/manual email fixtures |
| DOC1 — Issue integration and draft preview | QL3 atomically freezes customer facts/manifest and durable generation intent; explicit DRAFT preview and issued generation status; prepared-required policy refused unless actual integrated adapter exists |0123/0133 default issue independent of renderer; current source/control/numbering locks; approvals0070/0085 retained | Commercial rollback leaves no issued snapshot; render failure after valid issue leaves issued/pending and send blocked; same key one number; master/brand changes cannot alter pinned inputs; draft never masquerades as issued |
| DOC2 — Formal renderer and original archive | RequestOriginalGeneration/GenerateOriginal, fenced lease, isolated renderer, verified immutable PDF/head/receipt; status/retry/original download Resources | DOC0/1 secure assets/storage, one supported curated A4 version, same input/provenance; no current-master fallback | Short and50+ Items, long row and multi-page terms, options/services/source types; actual page count, row/text retention and visual page review; crash-after-upload/before-DB, stale worker, fresh-key duplicate converge |
| DOC3 — Recovery and labelled renditions | ReconcileDocumentStorage/RestoreOriginal/RepairRendition, protected recovery audits and history; original versus rendition UI | DOC2 immutable objects, backup+DB reconciliation, operator duty; semantic-equality check for repairs, no contractual edits | Corrupt/missing object blocks send/download; restore exact hash; orphan safely reconcile/quarantine; lost response replay; original survives renderer repair; missing pinned font refuses; no unproved byte-reproducibility claim |
| DOC4 — Authenticated print/download/manual delivery | Independent guarded files, RecordPrintInvocation/RecordManualQuotationDelivery Requests/Resources; delivery history, actual-date/recipient/evidence form and typed observation links | DOC2/3 secure file path + R9; no public links/provider callback/portal; manual unknown historical document stays factual pending evidence | Download/print not customer receipt, no-capability/tenant path guessing denied; pending generation cannot official-send; recipient data scope; append correction; idempotent manual evidence and denied personas |
| DOC5 — R6 method evidence bridge | RecordCustomerDocumentEvidence, method-policy version and exact revision/artefact/terms/scope links; R6 Action integration and acknowledgement/signature labelling | CA0/1/2 delivered for authoritative acceptance; R9; full R6 claims/consent checks remain; no automatic acceptance | Signature/view/ack alone no commitment; valid authorised manual PO independent of email; exact-artifact-required method refuses missing bytes; genuine late facts stay observations under CA5/R7 gates |
| DOC6 — Later email provider delivery | Durable dispatch/outbox, prepare/submit/reconcile Actions, scoped account credentials, per-recipient attempts/outcomes, authenticated callbacks and operator retry UI | DOC4/5 as needed, approved actual provider adapter/credentials and R9 workers; no generic notifications platform/Q33 settlement | Provider reply lost→UNKNOWN; duplicate/out-of-order event safe; no blind fresh resend; revoked send/expiry/withdrawal fence; external crash and in-flight truth; no exactly-once claim |
| DOC7 — Optional separately contracted extensions | Prepared-before-issue adapter, public links/portal tokens, actual e-signature workflow, multilingual/RTL or retention/purge modules | Each applicable new contract/owner requirement, security/identity/legal/locale policy, delivered adapters and separate code authority | Cannot expose placeholder implementation, fabricate signed identity, delete original without retention authority or enable unsupported prepared policy; explicit new acceptance criteria per approved extension |

DOC3 recovery is required before official rollout of DOC2 archive; code slices can be staged disabled
until coherent DOC0–DOC4 path is verified. DOC5 is required only for affected R6 evidence methods;
ordinary supported textual R6 evidence does not need an invented PDF prerequisite. DOC6/7 are later
capabilities, not initial document/manual-delivery completion. Portal/signature/localisation are not
implied implementation authorisations by the architecture approval.

## Per-slice delivery contract

- **Models/migrations:** translate logical pseudo-DDL in [documents](../domain/quotation-documents.md)
  and [delivery](../domain/quotation-delivery-evidence.md) to additive company-safe tables/FKs,
  unique identities, immutable evidence and explicit down/rollback limits after issued evidence.
  Isolated apply→rollback→reapply; truthful legacy baseline/import handling, no fabricated bytes,
  original send times or consent. Inventory every file and non-HTTP writer before feature activation.
- **Actions/Requests:** exact source/manifest/artefact/policy generations, approved workflow identity,
  company/scope and independently protected file/evidence duties at Request and Action. Request key
  and intent digest distinguish retry from new delivery. Owning R5/R6 Actions retain commercial
  facts; renderer/provider Actions cannot issue, accept, release stock or alter claims.
- **API/Resources/client:** register guarded routes/list/count/history/lookups/downloads/exports,
  OpenAPI and generated client in implementation commit; only safe projections/status/technical
  IDs and legitimate next actions. No internal storage paths, secret provider IDs usable as access
  tokens, costs or approval reasoning leaked via errors/hashes/filter/sort/attachments.
- **UI:** complete standard-sheet screen coverage for templates/preview, draft watermark, issued
  status/generation failure/retry, original/rendition downloads, manual delivery/evidence history,
  pending methods and denied/unsupported states; server string decimals and source quantities.
  Light/dark/phone and print page screenshots/PDF inspection belong to actual delivery checks.
- **Permissions:** final vocabulary waits R9. Register by module with explicit grants/migration/
  mandate treatment, scoped reads and sensitive-file gating; business operator is not renderer/
  recovery service administrator. Do not infer real holders from manager/admin names. Preserve
  record-scope restrictions through re-sync; setup alone never grants new business duties.
- **Security/storage:** private company namespaces, immutable versioned keys, validated upload/
  quarantine/safe filename, restricted renderer inputs/I/O, protected cache/logs, content checks,
  authenticated streaming and backup/restore proof. No arbitrary scripting, remote fetch, shared
  unscoped cache or public URL shortcut. Original evidence kept distinct from sanitised preview.
- **Concurrency/recovery:** real separate transactions/processes for duplicate original publication,
  expired lease/stale completion, storage/DB crash windows and dispatch/withdrawal races. Preserve
  exact receipts and business identity after retry; recheck current access before response. Database
  and object store are not one transaction; quarantine/recovery never hides unknown results.
- **Demos:** Sales module idempotent sources and narrow personas for short/long quotations,
  failed generation, later retry, changed masters, missing blob, repair rendition, manual receipt
  without consent, accepted manual PO and denied confidential attachment. Later provider uses
  deterministic sandbox/fake adapter scenarios, never sends real customer email during demos.
- **Completion:** implementation owner supplies API/client/schema/docs/journey/status, actual
  Action+HTTP+cross-company/protected-field/immutability/race/security checks, applicable migration,
  ratchets/client drift/build/browser/physical PDF verification under pre-push matrix. This planning
  session runs documentation checks only; no application tests or code are authorised.

## Exact R5/R6/R7 restrictions and rollout

QL3 issue stays atomic and authoritative; default generation can fail independently. Official send
needs verified original/rendition and current eligible purpose; prepared-required policy needs actual
integrated numbering/fact adapter or refuses. Existing approvals are not customer documents.
R6 owns authoritative consent through its Action and claim/quantity/entitlement locks. Evidence
upload and method-specific binary proof wait delivered secure file/method adapters and R9. Late
conflicts need CA5 plus relevant actual R7 correction/impact delivery, not a signed PDF shortcut.
R5 partial replacement/withdrawal and CA4 remaining-family reoffer remain blocked until necessary
R6/R7 contracts actually delivered/verified. R8 approval changes neither capacities nor availability.
R7 target adapters, bounded reductions and Inventory/Phase5/Finance effects remain undelivered/gated;
PDF/send/acknowledgement cannot substitute for handover receipt or actual fulfilment.

## Accepted DOC ownership and first-adapter dependency plan —0137

No parallel Sales renderer/archive. [Documents contract](../domain/documents.md) and
[DF0–DF5 sequence](document-print-framework-slices.md) are the common foundation; DOC0–DOC7 remains
the Sales delivery view, not another implementation of shared models. The table below allocates
the operations in the earlier delivery table explicitly; it supersedes its previously unassigned
infrastructure ownership without changing0133–0135 business guarantees.

| Slice | Shared Documents / Company | Sales / owning module |
|---|---|---|
| DOC0 | DF0/1 common template/page policy/manifests/intents/artefacts/blobs/receipts, attachment validation; Company brand/assets | Customer snapshot and quotation binding with real revision FKs; source-neutral allowlist/required facts/options; no second archive |
| DOC1 | DF2 BindDocumentManifest/durable generation, DRAFT preview/status primitives | QL3 snapshot/numbering/approvals/claims and exact pinned input integration; prepared-required policy cannot use stale preview |
| DOC2 | DF3 isolated renderer/A4+Letter pagination/original/run fence | Quotation A4 body/semantic row/total/option rules and actual customer fixture; Letter only compatible fixture initially |
| DOC3 | DF4 blob/DB reconciliation/restore/history/rendition plumbing | Repair consent/semantic-equality and retention/hold/incident authority; original remains shared identity |
| DOC4 | DF4/5 private protected streaming/print-invocation/upload/file association UI primitives | Current Sales subject/profile/download/internal attachment eligibility, manual delivery/recipient record and history |
| DOC5 | Common validated evidence-file association/immutable provenance | R6 evidence-method policy, authorised acceptance and claims; no shared consent engine |
| DOC6 | Existing artefact/file references; no new email platform in framework | Later Sales dispatch purpose/source eligibility/provider evidence/reconciliation, durable adapter under separate delivery contract |
| DOC7 | Future supported shared template/page/file extensions only | Prepared-before-issue/public portal/signature/language/retention semantics each separately governed; no universal framework lifecycle |

DF0/1 can stage disabled contracts with quotation/PO-shaped fixture; real DOC1 binding needs QL3.
DOC2 archive cannot be publicly enabled without DF4 recovery/file access. Coherent DOC0–DOC4 pilot
requires R9 approved/delivered permissions/grants plus actual Sales source adapter/Actions and safe
common storage; no live PO/Finance/Inventory adapter prerequisite is fabricated. DOC5 follows actual
R6 method needs. DOC6/7 remain later, not minimum pilot. Existing PO Quick Print is not refactored.

## R9 closure and coordinated delivery —2026-10-15

R9 A1–A10 accepted0138–0140/Q49 closed. [Canonical duty matrix](../domain/sales-duty-matrix.md),
[safe rollout](phase-4-r9-permission-rollout.md) and [R9 slices](phase-4-r9-implementation-slices.md)
resolve previously proposed vocabulary/grant architecture; historical candidate names above yield
to this reconciled catalogue, not duplicate guards. Actual guards/holder inventory/adapters/verification
remain required before affected public routes. [Consolidated handoff](phase-4-implementation-handoff.md)
defines small dependency batches; no implementation/live grant authorisation. Partial-family and
downstream gates stay until exact affected contracts actually delivered.

## Related

- [Accepted R8 review](phase-4-r8-documents-review.md) · [R9 initial matrix](phase-4-r9-authority-reporting-review.md)
- [R5 slices](phase-4-r5-implementation-slices.md) · [R6 slices](phase-4-r6-implementation-slices.md)
- [R7 slices](phase-4-r7-implementation-slices.md) · [Pre-push checks](../architecture/pre-push-checks.md)
