[← Domain index](README.md)

# Quotation customer documents and artefacts

**2026-10-15 · ACCEPTED0133–0135 / SPECIFIED; not IMPLEMENTED or VERIFIED.**
Formal customer documents preserve the exact issued commercial meaning and original PDF bytes.
Existing PO/browser Quick Print is presentation precedent only. No renderer/storage backend is
claimed delivered. [Delivery evidence](quotation-delivery-evidence.md) governs communication separately.

## Where it sits

R3/R4 resolved commercial values → R5 frozen issue/customer projection/manifest → generation →
private verified original → authorised download or dispatch. R6 consent and R7 handover remain distinct.
Projection is source-neutral0109, using authoritative server totals and source measures. It contains
company/recipient, quote number/revision, issue/validity, offered scope/options and quantities, prices,
quote tax, currency, terms and contractual destinations/deadlines where applicable. Optional/choose-one
sections retain semantics: no summed mutually exclusive choices or extras included in a firm total.
Mandatory information is validated independently of the template. No invented legal identifiers,
addresses or universal statutory wording; Finance retains statutory determination ownership.

## Models — logical pseudo-DDL, not migrations

```
quotation_customer_document_snapshots                    -- Sales-owned commercial projection
  id, company_id, revision_id, issued_snapshot_id, projection_schema_version
  allowlisted_content, content_digest, exact_terms_evidence, source_generation
quotation_document_bindings                             -- Sales-owned real subject FKs
  id, company_id, issued_revision_id, customer_snapshot_id
  document_subject_binding_id, original_profile/purpose
  UNIQUE(company_id, issued_revision_id, original_profile/purpose)
```

Use [Documents common models](documents.md) for template/page versions, manifests, generation
intents/runs, blobs, artefacts/heads, receipts and attachment/file associations. Company owns brand
versions/assets. Render binding pins resolved template/page/brand/locale/assets and references the
Sales immutable snapshot. There are no separate quotation_render_manifests, quotation_generation_runs,
quotation_artefacts, quotation_artefact_heads or second Sales/private blob archive. Old logical R8
names described capabilities before0137; this explicit refinement allocates each model once.
The common original head is authoritative; Sales keeps a binding reference, not duplicate PDF bytes.


Every reference uses company-safe keys; technical identities/receipt keys unique within company
and operation. Database rejects multiple original heads and mutable used templates/snapshots/
artefacts. Foreign subject IDs use a validated typed owning adapter, not an unchecked polymorphic
string. Rendered customer money preserves decimal(19,4) and transaction currency. Protected base/FX
financial evidence stays in the internal issue snapshot, referenced for provenance, not exposed
in the customer projection. FinancialActivity registration required for new monetary tables.
No client total recomputation.
Storage objects and caches include company identity; identical hash in another company grants nothing.

## R5 atomic issue and prepared-artefact policy

QL3 issue locks current candidate/revision and shared Sales claims/numbering coordination; validates
pricing/tax/control/approval facts and mandatory customer content, allocates0079 official number,
and freezes issue snapshot, projection, template/assets/locale and render manifest together.
Issue receipt refers to committed immutable inputs; generation status is separate. Failure before
commercial commit rolls back issue/numbering as0079 policy requires. Failure later cannot unissue it.
No required network/render side effect occurs inside the default issue transaction.

Generation intent committed with issue is discovered durably by a worker/poller after commit;
afterCommit notification alone cannot be its only existence record. Default issued generation
PENDING/FAILED is visible to authorised staff; official send disabled until VERIFIED artefact.
Draft previews read draft generation and are clearly DRAFT, never contain a counterfeit official
number. Changing commercial content or a required disclosure after issue needs a governed successor.

An explicitly enabled PREPARED_REQUIRED policy must bind a verified prepared artefact to the exact
candidate, policy, template/assets and eventual official identity. An implementation must prove how
final number/date bytes are determined under0079 without reusing numbers or exposing a draft as
issued. If no integrated prepared-issue adapter can guarantee these equalities and atomic publication,
that policy stays UNSUPPORTED, never approximated with stale pre-rendered bytes. The default path
does not depend on that advanced adapter. Policy/manifest mismatch refuses issue; browser preview
or successful previous candidate render does not satisfy it.

## Generation, original identity and recovery protocol

PrepareManifest/RequestOriginalGeneration binds immutable issued inputs. Renderer never reads live
Company/Party/Item/prices/templates. Generation Actions accept company, actor/workflow identity,
revision/manifest digest, request key and generation fence; same intent returns same job/artefact,
changed key payload conflicts. Background worker is restricted to that authorised manifest and
approved purpose; it is not an all-company sales user. Record initiating actor and service identity.

1. Claim persisted generation intent with expiring lease/fence; no database transaction held while
   rendering. Use isolated restricted runtime, pinned local assets/fonts and no arbitrary network,
   executable templates, active PDF scripts or untrusted file execution. Bound time/memory/output.
2. Write temporary private company object with unique attempt identity; compute bytes/hash/size,
   validate PDF output and close it durably. Promote via immutable create-if-absent/object generation
   check; never overwrite existing official blob. No assumption of transaction across DB and storage.
3. In short database transaction lock revision/manifest/head and run fence, verify exact provenance
   and object availability/hash, then append artefact and install one original head plus exact receipt.
   A stale worker loses publication; same manifest concurrent attempts converge on one original.
   Official send checks registered VERIFIED head and blob health; a bare path is never usable output.
4. Crash before object completion: expired lease retries same intent. Complete object without DB
   registration: reconciler validates identity/hash/fence and safely registers or quarantines orphan.
   DB committed but response lost: exact replay returns original identity after current access checks.
   Database row with missing/corrupt blob: mark health unavailable, block send/download, restore exact
   bytes from verified recovery copy; never rerender and silently call it the recovered original.
5. Reconciliation records actor/service, manifest, prior/current state, evidence and outcome. Retain
   uncertain objects pending review; garbage collection deletes only proven unreferenced staging
   objects after configured grace, under fencing/lock rules and no legal/evidence hold. No automatic
   deletion of original/used attachments. Used artefact retention/purge requires its later approved
   policy; no invented statutory period. Backup/restore of metadata plus blobs must prove hash/link
   consistency before service declares artefacts available.

Later RepairRendition creates its own manifest/artefact linked to original, identifies rendering
change/reason and checks commercial semantic equality. Original downloads return original bytes;
rendition download is labelled with its own identity. Layout/accessibility repair does not rewrite
send history. Missing pinned assets/input means failed repair, not live-master replacement. Exact
byte reproducibility is a verified property of a delivered renderer, never assumed from data hashes.

## Template layout and verification

Initial curated A4 renderer: deliberate first-page letterhead/recipient/commercial metadata; compact
continuation header quote/revision/company, repeated table headings and page X of Y. Keep readable
font size, margins and footer clearance. Split oversized descriptive rows with labelled continuation,
preserving quantity/amount once; never duplicate commercial arithmetic across page fragments.
Keep totals block together and labelled option totals distinct. Terms may span pages; final totals/
terms ordering is explicit in template version. No clipping, missing lines or repeated grand total
computation. Verify physical PDF count, all text/rows and rendered page images, not only DOM height.
Fixtures: short one-page,50+ Items, very long single row, mixed source/UoM, alternatives, optional
service, multi-page terms, long address, missing optional logo and delayed font/image failures.
Font/image readiness is mandatory for correct pagination; unsupported assets fail generation cleanly.

## Authorisation, APIs and safe access

R9 chooses final read/template/generate/repair/download/attachment duty names/grants/scopes. Each
Request and Action checks company, operation, legitimate subject read and independent file/evidence
eligibility. A costing entitlement is not public customer content; renderer receives only allowlist.
Approvals keep their own0070/0085/0087 review projection and reason visibility. Customer attachment
manifest excludes internal consent files and includes exact validated versions selected at issue.

Upload Action validates allowed extension, detected type/signature and limits; generate opaque names,
quarantine until required scanning/content validation succeeds, reject traversal/active content and
malicious/unverifiable types. Archive immutable original evidence separately from sanitised viewing
copy; no scanning transformation silently overwrites proof. Download resolves scoped subject→blob,
rechecks access/health and streams safe media with attachment disposition/safe filename, no raw path
or public bucket. Authenticated access only initially; revocation affects future requests even for
known IDs. Protect logs/error traces, thumbnails, search/indexes, caches and headers. Implement CSRF,
throttling and bounded requests under existing API/security conventions.

Suggested API: scoped document status/manifest detail, generation/retry, labelled original/rendition
download, controlled attachment upload/status/access and curated template version operations. Resources
expose safe identity/status/retryability and current allowed actions; never storage keys, credentials,
internal expressions or protected inputs in hashes/errors. UI: template preview/config, DRAFT preview,
issued generation pending/failed/status, explicit repair and artefact history/download. Governed
recovery has its own restricted operator surface, not unrestricted browser file administration.

## Integrity and refusals

Use existing Sales canonical lock order before manifest/head locks, then blob/run publication locks;
never let workers lock Sales roots after downstream heads. Revalidate under lock. Receipt replay
requires current access and original intent before mutable state checks. Stable reasons distinguish
UNSUPPORTED policy, stale input, pending/failed generation, missing/corrupt/quarantined file, denied
access and original identity conflict; expose no hidden subject details. Direct database invalid
writes, fresh-key duplicate original, stale worker completion, tenant guessing, cross-worker crash
and backup/restore consistency require meaningful implementation verification before enablement.

## Accepted shared ownership —0137, Q50 closed

Sales owns BuildCustomerProjection and quotation required content/layout, QL3 issue/snapshot and
business access/audience/retention callbacks. Shared Documents Actions own pinned manifest, render,
archive, stream, recovery and rendition plumbing described above; Company owns authoritative brand.
Names such as RequestOriginalGeneration/RepairRendition are quotation-facing operations delegated to
common Actions, not an independent implementation. Follow [DF0–DF5](../phases/document-print-framework-slices.md)
and refined DOC0–DOC7; actual secure adapter/atomicity remains undelivered.0133–0135 unchanged.

## Related

- [0133](../decisions/0133-freeze-customer-quotation-content-and-versioned-presentation.md)
- [0134](../decisions/0134-archive-original-quotation-artefacts-with-private-recoverable-storage.md)
- [Delivery/evidence](quotation-delivery-evidence.md) · [R8 slices](../phases/phase-4-r8-implementation-slices.md)
- [R5 lifecycle](quotation-lifecycle.md) · [Printing](../architecture/appearance-and-printing.md)
