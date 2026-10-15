[← Architecture index](README.md)

# Shared Document & Print Framework — owner decision proposal

**2026-10-15 · F1–F6 ACCEPTED in0137; planning Q50 CLOSED.**
The proposal body below is retained historical research. Current authority is
[0137](../decisions/0137-share-document-and-print-infrastructure-through-module-adapters.md);
[Documents contract](../domain/documents.md) and [framework sequence](../phases/document-print-framework-slices.md)
are SPECIFIED, not IMPLEMENTED/VERIFIED. No PO refactor or application code authorised.
Recommend one shared Documents infrastructure module in the Laravel modular monolith, with typed
module-owned document-type adapters and shared React presentation primitives. Approve the boundary
first, then refine R8 implementation ownership and R9 duties before a coding handoff. This proposal
authorises no implementation, PO refactor or universal business-document lifecycle.

## Three alternatives

| Choice | Benefit | Cost/risk | Assessment |
|---|---|---|---|
| Separate infrastructure per module | Local freedom and quick first implementation | Duplicate renderer patching, inconsistent branding/pagination, several file-security/recovery systems and incompatible archives | Reject; real common infrastructure already identified |
| Central generic PDF service only | One renderer/runtime, small API | Each module still builds templates, private files, recovery, generation identities and downloads; accepting raw HTML/URLs creates a security boundary problem | Useful internal renderer component, insufficient ERP architecture |
| Shared Document & Print Framework with adapters | One rendering/template/artefact/security foundation; domain-specific facts and events remain owned | Requires a narrow typed contract and clear configuration/access boundaries | Recommend; shared code/module plus isolated rendering worker, no new network microservice required |

## Existing implementation and governing authority

Read-only inspection of local ee4b04b, clean develop initially3 ahead/7 behind recorded origin/develop:

- [Quick Print hook](../../frontend/packages/core-ui/src/components/print/use-quick-print.ts) explicitly
  separates browser print of loaded data from formal backend documents, which it says are not built.
- [PO typed model](../../frontend/packages/core-ui/src/features/purchase-orders/purchase-order-print-model.ts)
  separates permission-projected data from rendering, retains decimal strings and optional commercial
  fields. It uses current supplied master resources and printedAt, not an immutable issued archive.
- [PO renderer](../../frontend/packages/core-ui/src/features/purchase-orders/purchase-order-print.tsx)
  has a template registry, compact continuation headers, repeated headings and measured rows after
  font/logo loading. Page-height arithmetic is A4-specific; it moves whole rows and is not evidence
  of arbitrary oversized-row continuation or Letter support. Existing browser checks are historical
  delivery evidence; none were run in this planning review.
- [Print preferences](../../frontend/packages/core-ui/src/stores/print-settings-store.ts) are browser-local;
  existing PO format/selector and all Quick Print call sites remain untouched. Documentation shorthand
  paths in appearance-and-printing are under core-ui, not apps/main/features for this PO renderer.
- [Filesystem configuration](../../backend/config/filesystems.php) has private/public/local/S3 driver
  configuration. Bounded backend/app search found no Storage::/temporaryUrl/streamDownload consumer;
  configuration is not a tenant-authorised document/file/artefact service. No deployed configuration,
  database state, blob durability or storage endpoint security was verified.

Reuse0010/0014 minimal shared seams and owning Actions,0079 numbering,0081 Company facts/settings,
0028/0089/0096 authority/scope, and [R8 documents](../domain/quotation-documents.md)/
[delivery](../domain/quotation-delivery-evidence.md). Accepted0133–0135 fix Sales behaviour, not an
independent Sales renderer requirement. Their bodies stay unchanged. A later approved cross-cutting
ADR would establish infrastructure ownership and supplement the slices; any changed business rule
would need an explicit separately reviewed refinement. Quote A4 remains0133's accepted initial format.

## Primary evidence and applicability

Questions: do mature ERPs separate shared rendering/branding from document datasets/layouts, and
how can configuration be reused without live changes rewriting archived issued output?
Sources accessed2026-10-15; existing R8 security/recovery evidence reused.

| Primary documentation | Observed distinction | Project consequence |
|---|---|---|
| [Frappe Print Settings](https://docs.frappe.io/framework/user/en/printing/print-settings) and [Letter Head](https://docs.frappe.io/framework/user/en/printing/letter-head) | Common print/page/renderer settings and reusable branding, with document-specific selections; page and bulk limits exist | Shared settings/branding are useful. Do not import their draft/cancel permissions, arbitrary HTML/Jinja access or live selection as historical archive policy. |
| [Business Central layouts](https://learn.microsoft.com/en-gb/dynamics365/business-central/ui-manage-report-layouts) and [per-company selection](https://learn.microsoft.com/en-us/dynamics365/business-central/ui-set-report-layout) | Report dataset/body layout distinct from shared themes/header-footer and company default selection | Separate typed domain content, common layout parts and company/type defaults. Its Word/RDLC options do not mandate our engine or prove byte reproducibility. |

Convergence: shared output facilities and branding with document-specific bodies/defaults. Divergence:
Frappe print-format system versus Business Central dataset/composite-layout model. Translate this
into a narrow adapter framework; do not copy a vendor permission model, scripting environment or
universal lifecycle. Odoo direct report-documentation opening failed; no claim relies on snippets.

## Responsibilities and ownership

| Owner | Owns | Must not decide |
|---|---|---|
| Shared Documents module | Type registration/compatibility, curated template versions and page primitives, render manifests/runs/receipts, artefacts/blobs/hash verification, isolation, protected stream/upload mechanics, reconciliation/recovery, safe status/audit | Issue/post/approve/accept/withdraw, accounting totals, inventory quantities, customer consent, statutory retention, domain entitlement |
| Company module | Authoritative company identity/contact/profile, versioned common branding/logo asset references and governed shared defaults through Company contracts/registry per0081 | Quotation terms, invoice tax policy or module approval rules; duplicate Company facts in print settings |
| Owning module/type adapter | Required content, typed authorised projection, source/snapshot generation, audience/profile, layout body/constraints, business snapshot event, permitted purposes/formats, access decision, retention/hold/disposition policy, delivery/evidence integration | Independent renderer/storage/retry/download infrastructure or bypassing framework file safeguards |
| Shared React presentation | Template/page-setting editors, preview/status/history/download affordances and reusable sections/tables/header-footer primitives | Authoritative totals, consent, mandatory-field omission, granting permissions |
| Operations | Renderer runtime patching, resource limits, queue/storage health and verified recovery procedures | Business entitlement through host/service credentials or restoring one tenant's files into another |

Documents owns rendering/template policy data in typed versioned tables; Company owns brand identity
and cross-company-profile defaults, referencing those approved versions rather than duplicating facts.
Company settings UI may link/compose Documents-owned configuration. No generic key/value settings bag
or second Company profile. Shared branding/default persistence resolves0081's explicit deferral only
after owner approval; it is not an already implemented endpoint.

## Adapter and technical contract — proposed, not a universal schema

Register stable type IDs such as sales.quotation.customer, purchasing.purchase-order.supplier,
finance.invoice.customer and inventory.count-sheet.blind. Adapters live with their owning module;
Documents registry discovers explicit contracts, never class names/SQL/HTML supplied by callers.
Type ID is not permission or commercial number. Absence of adapter means unsupported, not fallback.

Minimum adapter contract: type/schema/layout version; company+typed subject/version; allowed purpose;
authorise(actor, action, subject, audience) with scope/profile; buildDraftProjection or provideFrozenProjection;
required-content validation; approved layout/page/branding policy; snapshot provenance; retention/hold
policy reference; permissible repair/semantic-equivalence check; business callback/event contract.
Payloads are bounded immutable value objects/decimal strings with domain-specific sections, never raw
Eloquent models, arbitrary URLs or a universal line ledger. Module validates financial/quantity facts;
framework validates payload/layout compatibility and cannot recalculate them.

Proposed shared persisted concepts: template/brand/page policy versions, registered subject bindings,
frozen payload/manifest, generation intents/runs, private blobs, artefacts/versions/receipts and file
associations/audit. Module retains actual commercial issue/acceptance/approval/delivery records and
holds a tenant-safe artefact/binding reference. No universal document status table or universal event
named Issued that replaces domain facts. PREVIEW/ORIGINAL/REPRINT/RENDITION describe output purposes,
not quotation/PO/invoice lifecycle states. A reprint streams original bytes and logs access; a changed
layout appends labelled rendition subject to owner checks, never silently replaces original.

Owner Action validates source and freezes domain snapshot plus resolved render binding/manifest in
its transaction using synchronous owning Documents Action on same database connection. Required
atomicity must be proven with real Actions/locks. Optional events announce changes; durable generation
intent exists independently of a queued notification. Renderer runs outside long DB transactions.
Module callbacks participate in established lock order; framework cannot lock downstream then re-enter
an owning Action in reverse. Missing supported atomic binding blocks that integration, not an unrelated
module workflow. Default Sales issue independence from generation stays0133; other types choose their
own approved snapshot/artefact prerequisite, not inherit Sales policy automatically.

## Configuration resolution and historical pinning

For a new permitted render: shipped global defaults → governed company defaults/branding → compatible
company document-type template/page policy → explicit authorised per-document choice within allowed
constraints. Resolve each component with explicit precedence/version and final required-content/
format validation. A type restriction wins over an incompatible default; reject explicit invalid
selection rather than silently substitute. A paper default cannot make an A4-only type use Letter.

Pin the resolved layout/schema/renderer, brand/assets/fonts, page size/orientation/margins, locale/
date/currency formatting, required text and module source generation at the owning snapshot event.
Historical original generation/download uses these versions/bytes exclusively, not today's defaults.
User browser preference may affect draft/quick preview only within allowed policy; it cannot silently
alter official branding or already pinned facts. Commercial/legal terms remain module-owned content,
not an innocent globally editable footer. Common footer decoration cannot hide mandatory information.
Changing company defaults affects future eligible documents; it neither rewrites old originals nor
republishes invoices/offers. Keep used template/assets readable or refuse unsafe removal/downgrade.

Shared A4 and Letter page profiles use physical dimensions/margins, common first/continuation/footer
slots and table/long-row/keep-together primitives. Adapter chooses body/columns/section ordering and
semantic repeat rules. No duplicate calculations on row fragments or repeated total computation.
Formal preview is the backend-generated output (or same trusted layout pipeline), not an independently
reimplemented React paginator. Existing Quick Print remains a separate explicit convenience track;
reuse tested visual patterns where possible without promising browser/backend pixel parity.

## Concrete module examples — future adapters, not newly approved business rules

| Type | Module-supplied data/policy | Framework reuse and boundary |
|---|---|---|
| Sales Quotation | Frozen0133 customer allowlist, options/terms/totals, issue/revision validity;0134 original;0135 delivery and R6 consent | Shared engine/archive/recovery; render failure does not undo issue; quotation remains A4 initially. Framework Letter support is not approval to change0133. |
| Purchase Order | Purchasing supplier-facing typed lines/totals/destination and optional quantity-only profile; approved PO lifecycle/snapshot event | Future adapter reuses template/page/store/download. Existing PO Quick Print not upgraded/relabelled as immutable; formal supplier/terms snapshot gaps need Purchasing contract before adoption. No retroactive historical PDF invention. |
| Invoice | Finance posted immutable amounts/tax/number and jurisdiction-specific required text/correction/retention policy | Same engine/original retrieval; printing never posts GL, recomputes tax or edits invoice. Finance determines posting/snapshot requirements and legally required copy/rendition labels, no universal quotation validity. |
| Inventory count sheet | Inventory-approved blind or quantity-visible scope, warehouse/package/UoM, count-session/version and as-of evidence | Same page/print facilities; blind sheet cannot reveal expected stock/valuation. Printing neither freezes stock nor posts count. Working sheet may be regenerable as-of output; immutable official snapshot only when Inventory policy requires it. |

Sales Orders and Jobs later supply demand/operational baseline profiles through the same contract;
they need no second renderer and printing cannot imply delivery, customer acceptance or actual work.
Do not require Finance/Inventory adapters to exist before the quotation pilot can run safely.

## Proposed DOC0–DOC7 responsibility split — no accepted slice rewrite yet

| R8 slice | Reusable framework infrastructure | Quotation/owning module responsibility |
|---|---|---|
| DOC0 | Versioned templates/page/brand bindings, manifests/runs/artefacts/blobs/receipts, secure file primitives | Quotation customer DTO, required content/options, issued/source links, projection scopes and financial probes where data is owned |
| DOC1 | Freeze resolved render binding/durable generation intent, DRAFT preview support | QL3 issue/numbering/approval/claims transaction and exact source facts; prepared-required adapter policy |
| DOC2 | Common isolated renderer, physical-page pagination, immutable original publication and worker fencing | Quotation layout/body/format constraints and specific fixtures/totals; A4 pilot |
| DOC3 | Storage/DB reconciliation, exact-byte restore, labelled rendition lineage and health checks | Quotation semantic equality and repair authority; owner retention/holds, business incident disposition |
| DOC4 | Protected stream/print invocation/upload/status/history mechanics | Sales module download/evidence scopes, recipient profile and manual delivery records; no shared receipt-as-consent rule |
| DOC5 | Secure evidence-file association/provenance | R6 method-policy/evidence/consent Action and claims; no acceptance engine in Documents |
| DOC6 | Reuse artefact IDs/hashes and protected attachment manifests; generic dispatch primitives only if separately approved | Sales dispatch purpose/eligibility, recipient/content and unknown-result reconciliation; email adapter may be shared later under its own contract, not absorbed into printing now |
| DOC7 | Later governed template tooling/page profiles/storage extensions as concretely needed | Prepared-issue, portal identity/signatures, statutory language/retention and module semantic contracts remain separately gated |

Logical R8 pseudo-DDL names currently use quotation prefixes; after approval convert common concepts
to one shared physical model with Sales binding references, not parallel shared+Sales blob archives.
No migrations exist to rename now. Cross-cutting ownership supplements0133–0135; their guarantees
remain. The proposed foundation is a dependency only for relevant DOC shared work, not an excuse to
hold all CRM/R9/Finance research or demand every ERP document adapter in the first delivery.

## Security and R9 duty refinement

Keep business operations module-owned. Proposed shared duties cover governed common template/page
policy administration, branding administration through Company, and technical artefact recovery/
reconciliation. Final names/grants require R9 review. Module permission such as permitted quotation
download authorises framework operation through adapter; do not also require redundant documents.view
or documents.download for every ordinary user. No central file-read-all permission for operators.
Repair separates technical generation from owning module's authority to publish a semantic rendition.

Central stream/upload mechanisms enforce tenant+subject+purpose+audience binding and call owning
access adapter at Request/Action/worker entry. Parent read alone does not grant confidential attachment;
module evidence/file profile may demand independent duty. One blob with several associations grants
access only through a currently authorised association; cannot enumerate other linked subjects.
Full financial original cannot be dynamically redacted and still called original: quantity-only user
gets a separately identified permitted projection/rendition where policy allows, or refusal. Cache keys
include tenant/type/source generation/audience/projection schema/template/assets/page policy; cached
bytes never bypass current authorisation. Missing/deactivated type access adapter refuses safely.

Private immutable keys, tenant-safe FKs, validated/quarantined uploads, safe names and authenticated
streaming as0134; no public path/token shortcut. No raw template execution, renderer network access,
local-file reads, untrusted JavaScript or host secrets; trusted renderer gets only bounded authorised
payload/assets in isolated runtime. Type/layout cannot request fields outside allowlist. Protect logs,
status/errors/thumbnails/search and cross-tenant dedup. Worker identity is limited to approved intent,
not global business authority. Repair/recovery administration cannot grant customer evidence access.

R9 can continue limits, grant principles, partial-win and forecast research independently. Defer final
document/template/attachment strings and their rollout manifests until shared ownership is decided;
revise proposed sales.document.template.manage rather than adding a duplicate shared global right.
Source/delivery/acceptance capabilities remain Sales-owned. Shared settings authority is not issue/
approval/Finance authority; sensitive exclusions/mandates and limited-scope preservation still apply.

## Minimum foundation, operations and deferred capability

Minimum useful shared delivery: one supported trusted renderer implementation; registry/typed adapter
contract; curated page/header/footer/table primitives with A4/Letter profiles; versioned company brand/
template/default resolution; authorised draft/frozen input manifests; durable generation with status,
leases/receipts; private original/hash/rendition identities; protected file associations/downloads;
tested reconciliation/recovery. Deliver with quotation adapter first, a PO-shaped contract fixture
to catch Sales-only assumptions, and no mandatory live PO migration. Add real other adapters when
their owners authorise required semantics. No premature universal design studio or all-module rollout.

Bound rendering away from request php-fpm/dbvm: small dedicated worker/container process using same
deployment/codebase, explicit CPU/memory/time/page/asset/job limits, bounded concurrency and per-company
fairness/rate limits. Avoid browser process per table row or holding DB locks over render/upload.
Resolve source with bounded queries/no N+1; store bulk PDF bytes outside PostgreSQL; index tenant/type/
subject/status/lease/retry lookups and reconcile in bounded batches on the1-vCPU dbvm constraint.
Reprint verified originals without regeneration. Cache approved template assets safely; stream files
without loading entire archives. Observe queue age, duration/page count, failure/unknown recovery,
storage growth and denied access without logging commercial payloads. Resource caps and pagination
failures must refuse visibly, not silently truncate documents. No actual throughput/SLA is asserted.

Share R8 storage recovery protocol: upload staged immutable object, verify hash, transactionally bind
artefact/head under fence, then recover crash orphans; DB/storage are not one transaction. Missing bytes
block official send/access pending exact restore. Delete only proven unreferenced staging objects after
grace; retained originals/attachments need every applicable owner reference/hold cleared under approved
policy. Backups cover metadata plus blobs/assets and restore consistency; an operations backup ADR is
not automatic proof this future store is protected. Maintain pinned multi-architecture runtime images
and prove engine/font/layout behaviour on deployed ARM64; no library/engine chosen or installed here.

Deferred: arbitrary designer/scripts, multiple renderer backends, public links/portal/e-signatures,
email/notification platform, multilingual/RTL, statutory PDF/A/digital seals, retention/purge engine,
bulk merged exports, network/raw printers/labels and remote rendering service. Retention hooks and
resource limits exist first; unsupported legal/policy requirements prevent enabling the affected type.
No universal “never delete any document” or “every print becomes official immutable evidence”.

## Owner decisions requested

Approve/amend: (F1) option3/shared monolith module and per-type adapters; (F2) Company versus Documents
configuration ownership and immutable resolved bindings; (F3) common file/access engine with domain
entitlements, no duplicate ordinary download duty; (F4) quoted DOC ownership split and quotation-first
pilot without PO refactor; (F5) A4/Letter framework profiles with quote A4 unchanged; (F6) minimum/
deferred boundaries and constrained workers/recovery. After approval record a cross-cutting ADR using
then-free number and refine slices/domain physical ownership/R9 duties. No number reserved here.

Resolved 2026-10-15: develop was rebased onto origin/develop and the colliding R7 ADR renumbered 0130 → 0136.

## Related

- [Appearance/printing](appearance-and-printing.md) · [Authorisation](authorization.md) · [Company0081](../decisions/0081-central-company-profile-and-company-wide-settings.md)
- [R8 slices](../phases/phase-4-r8-implementation-slices.md) · [R9 review](../phases/phase-4-r9-authority-reporting-review.md)
- [Questions](../OPEN-QUESTIONS.md) · [Shared code boundaries](code-structure.md)
