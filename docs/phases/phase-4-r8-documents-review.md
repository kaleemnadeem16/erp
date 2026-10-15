[← Phase index](README.md)

# R8 — Quotation documents, presentation, delivery and customer evidence

**2026-10-15 · D1–D8 ACCEPTED with owner clarifications in0133–0135; planning Q48 CLOSED.**
The matrix/recommendation body below is the preserved historical review from2026-10-14.
Current authority: [0133](../decisions/0133-freeze-customer-quotation-content-and-versioned-presentation.md),
[0134](../decisions/0134-archive-original-quotation-artefacts-with-private-recoverable-storage.md),
[0135](../decisions/0135-separate-quotation-delivery-evidence-from-customer-consent.md).
[Document](../domain/quotation-documents.md)/[delivery](../domain/quotation-delivery-evidence.md)
contracts and [DOC0–DOC7](phase-4-r8-implementation-slices.md) SPECIFIED; no application delivery/verification.
Owner clarifies default atomic commercial issue independent of rendering, verified artefact before
official send, deliberate compact continuation pages and later email/portal/localisation boundaries.
R7 recorded and specified first. R8 recommendations below need owner decision; no accepted R8
ADR or code authority. Recommended order: settle D1–D8, record approved authority, then specify
document/delivery slices alongside R9; application work requires separate authorisation.

## Existing authority and observed boundary

Reuse0109 scope/options,0115–0120 pricing/tax/FX,0122–0124 issue/revisions,0125–0127 consent/evidence,
0131–0132 and 0136 handover,0079 numbering and0070/0085 approval review. Never expose internal approval
documents as customer quotations or substitute delivery for acceptance. Company facts come from
Company; required customer-facing facts differ from execution readiness. English/LTR remains0026;
multilingual/RTL investment needs a demonstrated requirement and separately approved extension.

[Appearance and printing](../architecture/appearance-and-printing.md) describes current browser-local
Quick Print preferences and PO A4 pagination. Read-only inspection at7f2dc45 confirms this is the
reusable presentation basis, not a delivered formal quotation archive/backend PDF/email service.
The PO adapter reads current master details; copying that boundary would violate immutable quotation
issue. Reuse layout/pagination patterns, server totals and projection separation, not live-data history.
No shared printing/authorization/notification configuration is changed. Q33 stays an ERP-wide question;
this bounded delivery proposal does not settle a general notification centre.

## Primary comparison — questions, convergence and divergence

Question: how do mature ERPs separate professional document composition from export/send, and what
additional immutable evidence and consent boundaries does our accepted architecture require?
Sources accessed2026-10-14; two accessible mature systems suffice. No vendor pattern establishes
legal receipt/signature effect or our issue transaction semantics.

| Primary source | Observed behaviour | ERP-specific implication |
|---|---|---|
| [ERPNext Quotation](https://docs.frappe.io/erpnext/quotation) | Offers quantities, prices, taxes, validity, delivery expectations and terms; recommends checking the print format/letterhead before sharing. A printed heading does not change document type. | Distinguish scope and presentation; heading cannot turn quotation into invoice. Our Item/manual/Assembly neutrality and immutable issued facts remain governing. |
| [ERPNext Print Format](https://docs.frappe.io/erpnext/print-format) | Default/custom formats and selected field visibility; builder or scripted customisation. | Curated templates can scale to configurable presentation, but hidden markup is not a security boundary. Never pass full protected domain records to arbitrary templates. |
| [Dynamics PDF export](https://learn.microsoft.com/en-us/dynamics365/sales/create-quote-pdf) | Template preview, download, email and storage are separate choices; administrator enables generation. | Separate creation from communication and storage; our freeze/version/hash and provider outcome rules must be specified independently. Do not infer byte reproducibility from vendor export. |

Convergence: configurable professional presentation and explicit sharing actions. Divergence:
ERPNext builder/scripts versus Dynamics Word templates and separate save destinations. Our initial
curated versioned renderer avoids arbitrary scripts and external document-management dependency.
Odoo PDF-builder/signature pages returned tool errors on direct opening; search snippets were not
used as verified authority. Security proposal is a project requirement, not a claim of vendor security.

## Consolidated business-first decision matrix — recommendations remain proposed

| Bundle | Alternatives and trade-offs | Business example | Recommendation | Delivery boundaries/dependencies |
|---|---|---|---|---|
| **D1 — Customer scope and professional template** | Mirror internal screen (cheap, leaks detail); curated customer templates (controlled); unrestricted designer (flexible, higher governance/security cost). | Goods plus installation proposal shows independent sections and one choose-one alternative group; optional extras do not inflate a firm total. | Start one professional versioned A4 template with allowed sections/attachments: company/recipient identity, central quote number/revision, issue/validity dates, scope/specification, offered/priced measures, prices, component totals/currency, payment/delivery constraints and applicable terms. Required commercial facts cannot be hidden by template choice. | Source-neutral0109; frozen R3/R4 totals. No fabricated address/tax ID/contact/signature. Configurable legal fields require jurisdiction-specific owner requirements, not a universal statutory checklist. Defer arbitrary designer. |
| **D2 — Multi-page layout and choices** | Single long browser page (easy, unreliable breaks); measured A4 (existing pattern); formal renderer (stable artefact, new infrastructure). |55 lines, long service description, alternatives and three pages of terms need continuation headers and readable totals. | Formal PDF/print with repeated quote/revision/header/column identifiers, page X of Y, deliberate section breaks, row continuation for oversized content, and protected closing totals/terms. Optional/alternative prices labelled separately; only a declared valid selection's total is firm, never sum mutually exclusive choices. | Reuse PO patterns but verify actual PDF page counts/rows/fonts/logo/table breaks and print margins. Do not assume one row always fits a page; no client arithmetic or dropped terms. Accessibility/searchable text expected; PDF/A only if later required. |
| **D3 — Draft versus immutable issued content** | Render current masters every time (simple, changes history); freeze data only (preserves meaning, bytes may differ); freeze data/template/assets plus original bytes (stronger archive, storage cost). | New logo/terms or master price tomorrow must not change yesterday's sent offer. | Freeze allowlisted customer content, contractual terms, template/branding/assets/locale versions and render manifest at issue; retain original official bytes/hash before first official send. Draft output clearly DRAFT, non-actionable. Changed contractual content needs R5 successor/reoffer, not regeneration. | Preserve0123 atomic commercial issue. Recommend issue pins render inputs; generation may be separately pending/failed and blocks official send, without automatically unissuing successful issue. Optional company policy can require prepared artefact before issue, with explicit retry/failure contract. This exact selection needs owner approval. |
| **D4 — Reproduction and regeneration** | Promise byte-identical rerender (strict, brittle to engine changes); content equivalence plus preserved original (practical); silently replace file (cheap, loses evidence). | Renderer patched after issue: downloading original remains exact; a repair render is a labelled rendition. | Download archived original as original. Manifest records content/template/assets/font/renderer versions and hashes. Retry same generation identity safely; regenerated rendition appends its own provenance and semantic comparison, never replaces original/send evidence. State byte reproducibility only when actually verified. | Missing original is an incident/pending state, not current-master fallback. Noncontractual accessibility/layout repair may append rendition after review; any contractual correction uses successor. Storage/database failure reconciliation, recovery and retention policy must be specified before delivery. |
| **D5 — Safe commercial projection and files** | CSS-hide internal fields (unsafe); server allowlist (controlled); privileged internal PDF reused externally (leaks). Public storage (easy) versus private authorised access (required). | Customer needs offered totals and technical datasheet, not margins, stock valuation, approval notes, internal FX or hidden competing offers. | Explicit server customer projection shared by PDF/print/email/portal; mandatory disclosure never expands protected internals. Private company-scoped immutable artefacts/evidence, protected IDs and current authorized downloads; audience-tagged customer attachment manifest separate from internal evidence. | R9 approves read/send/manage-template/evidence duties/scopes/grants. Upload size/type/signature validation, quarantine/scan, safe filenames, no executable/active content, isolated renderer with no arbitrary remote fetch/scripts, tenant-scoped cache/storage and protected logs. Initial access through authenticated application; defer external public links/portal tokens until separately governed. |
| **D6 — Channels, attempts and retry evidence** | Sent boolean (cheap, misleading); append attempts/outcomes per recipient (auditable); full messaging platform (expensive). | PDF generated; email bounces; employee prints and posts it manually. Quote can remain issued while delivery failed. | Record delivery intent plus artefact hash, recipient/address snapshot, channel, actor, occurrence/recording time, attempt/provider identity and outcome. Distinguish queued/submitted/provider accepted, failed, unknown, receipt evidence and acknowledgement. Download/print invocation proves local action only. Manual send records actual evidence/source, not fabricated provider receipt. | Initial archived download/print + manual channel recording; email only with approved adapter/credentials and durable dispatch/retry protocol. Local intent/outbox and append provider results; timeout unknown needs reconciliation, not fresh blind send. Delivery idempotency limits repeats but cannot promise exactly-once email. ERP-wide notifications/Q33 and portal separate. |
| **D7 — Customer acknowledgement/signature/acceptance** | Every view/signature auto-accepts (simple, unsafe); typed evidence linked to R6 (accurate); portal-only consent (excludes manual business). | Customer signs only receipt of quote; later PO chooses40 of an explicitly divisible100 offer. | Acknowledgement, document signature and commercial consent remain distinct typed evidence. Signature counts as acceptance only when verified authority, exact document/scope/choices/quantity/terms and required R6 eligibility are satisfied through R6 Actions. Preserve occurrence versus recording time and disputed evidence. | No legal-effect claims or universal signature requirement. No bitmap signature alone creates commitment. Manual verified consent stays legitimate under0127. Portal signature capture/identity/challenge/security and jurisdiction-specific requirements deferred separately; neither portal nor signature is a universal R7 prerequisite. |
| **D8 — Delivery-dependent acceptance, branding/numbering/localisation** | Universal delivery prerequisite (blocks genuine manual consent); no delivery policy (misses governed channels); explicit path policy (more work, accurate). Live user print brand (easy, inconsistent) versus frozen company template (stable). | Verified PO from an externally shared exact quotation differs from a portal acceptance that must bind served revision. | Company/path policy may require exact artefact/delivery evidence for that acceptance method; lack of one email attempt need not negate genuine verified consent through another legitimate channel. Pin policy and evidence; observations/reconciliation when ambiguous, no universal delivered=consented. Use0079 official quote identity; renditions/attempts get technical IDs, no new sale number. Freeze company brand, date/number formats and currency/tax labels; English/LTR initial. | A resend does not extend validity or revive withdrawn/replaced offer. Dynamic current-status cover/banner separated from historical original bytes. Tax wording follows R4 estimates; Finance statutory determination stays separate. New languages/RTL, legal retention, jurisdiction text and public portal need explicit approved contracts. |

## Proposed generation and delivery boundary

R5 issue atomically pins commercial snapshot, customer projection version and render manifest reference.
Rendering reads only immutable inputs, writes immutable private blob, verifies hash, then registers
artifact identity/status. Blob/database crash gaps need deterministic reconciliation; no email references
unregistered/unverified bytes. Never silently reread today's Party/Company/templates to complete retry.
No architecture acceptance is inferred by this protocol proposal.

One official issue can have multiple separately evidenced deliveries of the same original artefact.
Current eligible source/duty/recipient policy is checked at authorised send and retry. Withdrawal or
replacement prevents unintended new offer send; an explicitly historical resend needs appropriate
authority and a separate cover explaining current state, not edited original PDF. Race between pending
delivery and withdrawal needs a dispatch fence/version and truthful already-dispatched outcome; cannot
recall an external message by database rollback. Communication of withdrawal is evidence, not reversal
of authentic earlier consent. R6 ordinary/late decision rules continue to govern actual agreement time.

## Example and owner choices

Q1 issues goods100 at10.0000 and installation1 at200.0000, net1200.0000, quote tax estimate120.0000,
gross1320.0000. Optional maintenance is labelled outside that firm total; choose-one alternatives
have separate declared selection totals. Internal setup60.0000 and margin rules never enter customer
projection. Rendering fails: Q1 remains issued under recommended D3, artefact pending, no successful
send. Retry creates original PDF A1 from pinned inputs. Email attempt E1 bounces; E2 manual delivery
records actual channel evidence; receipt acknowledgement alone creates no acceptance. A verified PO
accepts exact Q1 through R6. Order/Job creation follows R7 independently. Later reduced agreement is
separate evidence, not overwrite or automatic regeneration of Q1.

Owner review requested for D1–D8, especially D3 issue-versus-render gate, D4 original/rendition policy,
D5 initial authenticated file access, D6 staged manual/email delivery, and D8 path-specific prerequisites.
R9 final permission names/scopes/grants and company retention/legal/localisation policy remain to be
settled; no real grants or universal legal minimum is invented. No accepted R8 ADR will be written
until owner approval. If substantive uncertainty remains, refine this matrix before recording authority.

## Proposed delivery order after approval — not implementation-ready slices yet

1. Customer-safe versioned projection and template/branding manifest; isolated artifact/evidence model.
2. Pinned-input formal rendering, archival bytes/hash, multi-page QA and regeneration/recovery contract.
3. Authenticated scoped download/print, manual delivery and recipient/attempt history.
4. Separately supported email dispatch/provider retry/reconciliation; no full notification platform.
5. R6 method-specific document/delivery evidence integration and acknowledgements; portal separately gated.

For each approved slice later specify Actions/Requests, immutable input generations, tenant constraints,
R9 guards/grants, API/UI/client, security/storage/recovery, deterministic race tests and layout demos.
R7 has no adapter delivery from this research. Finance/Phase5 and affected R5/R6 gates remain unchanged.

## Related

- [Phase 4](phase-4-sales-quotations-and-handover.md) · [R7 slices](phase-4-r7-implementation-slices.md)
- [Printing basis](../architecture/appearance-and-printing.md) · [Authorisation](../architecture/authorization.md)
- [R5 domain](../domain/quotation-lifecycle.md) · [R6 domain](../domain/sales-customer-decisions.md)
- [R4 financial projection](../domain/quotation-financial-calculation.md) · [Numbering](../architecture/document-numbering.md)
- [Question register](../OPEN-QUESTIONS.md) · [Research rules](../architecture/research-and-design.md)
