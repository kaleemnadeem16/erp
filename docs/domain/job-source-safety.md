[← Inventory module](../../backend/app/Modules/Inventory/README.md)

# Job source safety — Inventory and Purchasing writer inventory

2026-11-13 implementation update (initial inventory 2026-11-12) · Source-safety task [#120](https://github.com/kaleemnadeem16/erp/issues/120),
for [#69](https://github.com/kaleemnadeem16/erp/issues/69) / #108 / #111.
Initial inventory inspected `develop` **f3ea6069** and simultaneous Sales work (later `cc4c8940`).
Implementation resumed at `b1cac392`, using frozen interface `9ab38ae7`. This source-owned
implementation and integration handoff does not certify the pilot. Ordinary posting remains
unchanged; #123 separately corrects rejected-exit replay identity. No permission, migration or
configuration is introduced by the source work.

## Finding and applicability contract

**The #122 contract is frozen and the source-family adapters are implemented.** Inventory
registers `INVENTORY_MATERIAL` / `INVENTORY_NO_JOB_BINDING.1`; Purchasing registers
`PURCHASING_SUPPLY` / `PURCHASING_NO_JOB_BINDING.1` through the neutral shared
`SourceFamilyApplicability` interface and `JobSourceFamilies` registry (`9ab38ae7`).
Each owns one finite `SUPPORTED_BINDINGS` list. Empty list → NOT_APPLICABLE for the current
company; a supported binding → APPLICABLE; foreign/invalid company → UNKNOWN. Generic stock,
recalculation, override, rejected-exit document and PO/receipt identities remain provenance,
never validated Job material/cost allocations. No supported Job binding exists today.

This family result proves only operational attribution exclusion. **Sales must separately
prove the customer-agreed scope excludes materials/subcontractors**; missing/unverified scope
remains UNKNOWN. Overall release may not infer no contractual obligations from a family answer.
The original FK-only proof in `cc4c8940` was withdrawn by the Sales/Jobs lead on #122; FK checks
are secondary evidence, not the sole authority. No source-type allowlist, JOB-name blacklist,
fake allocation writer, mutable source head or new stock/Job lock edge was introduced.

| Outcome | Evidence required for this pilot |
|---|---|
| NOT_APPLICABLE | Authoritative immutable accepted scope/conditions exclude that obligation; the complete owning writer set cannot attach it, including generic provenance/corrections/imports. Pin the reviewed contract version and source basis. |
| UNKNOWN | Scope does not establish exclusion, an authoritative binding is unsupported/unverified, the writer inventory changed, or a required source authority is missing. Refuse affected release/execution/closeout. |
| APPLICABLE | Accepted scope requires materials/supplier work or supported authoritative source effects exist. First pilot refuses; later integration must reconcile actual effects. |

Purchasing's **operational attachment** is structurally excluded for today's schema/writers:
no Job, accepted-line, issued-line, allocation or handover identifier exists on its business
records. This does not prove the customer contract has no procurement requirement. A PO for
the same catalogue item does not allocate that PO to an accepted service. Sales must establish
contractual inapplicability from the actual agreed scope, including any Assembly/resource basis
and conditions. SERVICE_ITEM, instructions, company settings, notes, an empty source query and
unknown estimated cost are insufficient. No guessed zero or compulsory timesheet is introduced.

No current Inventory reservation, Job demand, business material-consumption allocation or
Job-custody writer was found. `stock_layer_consumptions` allocates layers to stock movements;
it is not a Job consumption document. No Purchasing subcontract execution, hire-period,
supplier bill, external expense or Job-cost-allocation writer was found. Their absence proves
no delivered attachment workflow, not that a missing mandatory adapter can return N/A.

## Inventory writer matrix

Paths in this section are relative to `backend/app/Modules/Inventory/`; names denote PHP files.
Company-scoped stock FKs resolve item/location/UoM/batch/movement, not the generic source pair.
Generic provenance risk classifications below reflect the original investigation; the frozen contract excludes these fields from authoritative Job binding. All listed operational posting boundaries use transactions. Lower-level posters inherit the
caller's authority/transaction; they do not independently grant business authority.

| Actual writer / caller | Tables and source identity | Authority, actual lock/freshness protocol | Pilot exclusion / required action |
|---|---|---|---|
| `Actions/PostInventoryMovement` → `Actions/Movements/ReceiptPoster` | `stock_moves`, `stock_layers`, valuation entries/pools, shortage settlements; arbitrary caller source pair | Facade has no actor; owning caller authorises. First-use item → location → shortages as applicable → pool; atomic posting, source-key replay | Provenance only; no validated Job binding. Ordinary receipt/retry preserved. |
| Same facade `issue` / `issuePosted` / `issueRejectedPosted` → `Movements/IssuePoster` | Movement, layer balances/consumptions, pools, valuation, optional shortage; arbitrary pair | Location → layers `(received_at,id)` → override request/shortage if needed → pool; shortage cost may lock historical layer; source-key replay rechecked | Provenance only; actual rejected-exit caller uses fixed source and named receipt layer. |
| Same facade `transfer` / `transferPosted` → `Movements/TransferPoster` | Movement, destination layers, transfer details, pool/settlement evidence; arbitrary pair | Locations ascending id → source layers → pool pair ascending id; source-key replay rechecked | Provenance only; no Job allocation consumer. |
| Same facade `reverseReceipt` → `Movements/ReceiptPoster::reverse` | Movement with caller reversal pair; original found by caller original pair, original layer/pool changed | Location → original layer → pool; untouched full receipt required; source-key replay | Original and reversal source identities remain provenance; no Job binding. |
| `Actions/IssuePackagedStock`, `TransferPackagedStock` | Same arbitrary pair forwarded to scalar posting; package operations/draws/contributions/allocations | Fresh actor, package-state issue/transfer + view, warehouse scope; request advisory → location(s) → stock locks; pinned predecessor/watermark | Generic channel remains provenance; package posting/replay is preserved. |
| `Actions/IssueGroupedStock`, `TransferGroupedStock` | Fixed `MANUAL_PACKAGE_ISSUE` / `MANUAL_PACKAGE_TRANSFER` and generated own source id | Delegates to package Actions under their duties | Wrapper cannot attach lineage by construction; preserve normal stock work. |
| `Actions/RequestStockRecalculation` | `recalculation_requests`, impact versions; arbitrary pair from public Request | Fresh actor/access → company → permission `inventory.recalculation.request`, unrestricted warehouse scope; policy/horizon/impact preview; tracked-package refusal | Provenance only; public intake accepts string/integer source without allocating to a Job. |
| `Actions/ExecuteRecalculation` → `Actions/Recalculation/OutboundRecalculationExecutor`, `FifoReceiptReallocator` | **Direct** stock movement/layer/valuation writes; request source copied; replacement layers preserve transfer provenance | Fresh actor + execute duty → company → request → impact version → location(s)/replay component layers/pools; approved version/dependency freshness | Request provenance remains provenance through execution/retry/reallocation; no facade restriction needed. |
| `Actions/RequestNegativeStockOverride`, `DecideNegativeStockOverride`, `ReviewNegativeStockOverride` | Override requests/approvals/reviews; arbitrary source pair, later exact-matched to movement; shortage FK to movement | Fresh actor/access → company; separate request/approve/review duties, warehouse scope, policy/independent actor; immutable decisions | Public intake retains source provenance; the override is stock authority, not Job attribution. Cover legacy request use. |
| `Actions/PostPhysicalCount`, `PostGroupedCountLine` | Count/lines → fixed `PHYSICAL_COUNT_LINE`, actual line id; stock via facade | Post duty and independent actor; count → ordered lines → ordered locations → stock locks | No Job input; structurally excluded. |
| `Actions/CommitInventoryCutoverOpeningState` → `Actions/Cutover/OpeningStateItemPoster` | **Direct** stock/layer/pool writes; fixed `INVENTORY_CUTOVER_OPENING`, `_OPENING_SHORTAGE`, `_SYNTHETIC` → fact/exception/anchor | Fresh actor, cutover commit duty; cutover → batches → item; approved opening facts, idempotent batch status | No Job input; structurally excluded. |
| `Actions/{Create,Attach,Validate,Approve,Finalise}InventoryCutover`, negative-opening request/approval, policy-transition recorder | Cutover/facts/transitions/negative exceptions; real cutover/import FKs | Separate cutover duties, fresh actor/access; cutover/batch locks, independent approval, final reconciliation | No Job input; no extra pilot restriction. |
| `Actions/PostRejectedStockExit`, `PostRejectedExitLines`, `PostStockScrap` | Receipt line/layer → `rejected_stock_exits`, fixed movement source `SUPPLIER_RETURN` / `SCRAP_WRITE_OFF`; **core also accepts arbitrary nullable `document_type/document_id`** | Return/scrap duties + warehouse scope; outer document/lines → receipt line → layer → location | Document pair is provenance; #123 independently compares its immutable identity on replay. Layer-before-location order is preserved. |
| `Actions/PostPackageOpening`, `RecordPackageCheckpoint`, `ActivateGroupedPackageTracking`, receipt/QA/count composition and grouped reversal adapters | Package operations/states/contributions reference actual item/location/layers/receipt lines | Fresh actor and exact package/count/receipt duties; request advisory, item on activation, locations/layers; predecessor + stock watermark | No new business lineage input. Corrections retain actual source identity; generic callers remain separate risk. |
| Item/category/warehouse/location/package CRUD, settings/policy history | Reference data, policy records, offers/conversions; no incurred Job allocation | Existing manage/configure duties, transactions and tenant guards | No Job-specific restriction needed. Changing catalogue defaults cannot rewrite agreed historical scope. |
| `Actions/QueryStockAvailability`, `QueryMaterialCost` | Read-only quantity/cost evidence | Existing read/projection guards | Not a reservation or material-demand writer. |

Physical schema evidence: `2026_09_02_120000_create_stock_valuation_foundation.php` declares
movement/layer source strings and integer ids without source FK/allowlist. The source uniqueness
index is company-scoped. `2026_09_04_110000_create_stock_recalculation_workflow.php` constrains
source text to nonblank, not a registered source type. The negative-override workflow migration
`2026_09_03_110000` likewise supplies no lineage exclusion. `Models/StockMove`'s scoped-FK list
does not validate the source pair. `2026_10_09_100000_add_rejected_stock_exits.php` also adds
unconstrained nullable `document_type/document_id`; `PostRejectedStockExit` persists caller values
and its request hash omits that pair. Fixed return/scrap wrappers do not prove the core cannot
accept another document type. Ledger immutability preserves provenance after posting;
it does not validate its meaning at insertion.

## Purchasing writer matrix

Paths are relative to `backend/app/Modules/Purchasing/`. Every operational Action below uses
its own transaction and company-scoped records. `DecisionActor` obtains the shared company
access fence before reloading the active actor; **the earlier claim of no access fencing is
incorrect**. Access fencing does not replace a business-source generation/fence.

| Actual writer | Tables / FKs / possible lineage | Authority and actual lock protocol | Pilot treatment |
|---|---|---|---|
| `Actions/CreatePurchaseOrder`, `UpdatePurchaseOrder`, `BuildPurchaseOrderLineAttributes` | PO/lines → supplier/site/currency/item/UoM/package/offer; replacement PO and cutover provenance, no Job or accepted source | Create duty, fresh actor; update PO lock, draft/pending-only, invalidate approvals, replace/lock lines | Structurally cannot attach a Job obligation. `forWorkflow` relies on owning caller authority, same restricted schema. |
| `Actions/SubmitPurchaseOrder`, `IssuePurchaseOrder` | PO + approval evidence; commercial commitment, no Job binding | Submit/issue duty (or cutover commit for actual cutover); PO → approval requirement → settings → lines → numbering | Preserve normal purchasing; no extra Sales lock. |
| `Actions/CancelPurchaseOrder`, `ShortClosePurchaseOrderLine` | PO status, line short-closed quantity | Cancel/short-close duty; PO → line, effective receipt reconciliation | No Job attachment; Job stop must never imply supplier cancellation. |
| `Actions/CommitInventoryCutoverOpenPurchaseOrders`, `Imports/OpenPurchaseOrderImportTarget` | Staging → PO/lines, actual cutover/batch/row FKs; `source_system/source_record_id` are predecessor-system provenance | Cutover commit + unrestricted scope; cutover → import batches → create/issue PO; unique import identity and provenance guards | Cannot allocate to Job. Generic staged raw text is not domain attribution. |
| `Actions/CreateGoodsReceipt`, `UpdateGoodsReceipt`, `DraftGoodsReceiptLines` | Receipt → supplier; receipt line → actual PO line/item/location/UoM/currency | Receipt-create duty; create idempotency, update receipt lock/draft-only, composition blocks line replacement | No Job input. Shared catalogue/PO identities do not constitute allocation. |
| `Actions/RecordReceiptComposition` | Package observations → actual receipt line/package | Receipt-create duty; receipt → line, draft-only exact quantity | Physical evidence only. |
| `Actions/PostGoodsReceipt` | Receipt/lines, fixed Inventory source `GOODS_RECEIPT_LINE` = real receipt-line id | Receipt-post duty; receipt → lines by PO-line id → sorted PO headers → sorted PO lines → Inventory → numbering; cumulative quantity/tolerance revalidation | Cannot attach Job through this writer; Inventory's generic callers audited separately. |
| `Actions/ReverseGoodsReceipt` (preview read-only) | Receipt reversal, fixed `GOODS_RECEIPT_LINE_REVERSAL`, real receipt line; grouped Inventory reversal where needed | Reverse duty; receipt → lines → sorted PO headers → sorted PO lines → Inventory; QA/consumption refusal | No Job attachment; retain complete atomic reversal. |
| `Actions/PostQualityDisposition` | Immutable dispositions → receipt line + same-line parent disposition, optional move | QA duty; receipt-line lock serialises tree; idempotent payload; nested insert savepoint | `source_disposition_id` is QA ancestry, not Job lineage. |
| `Actions/{Create,Update,Cancel,Post}SupplierReturn` | Return/lines → warehouse/supplier/receipt line/item/location, posted rejected-exit FK | Exact create/cancel/post duties, warehouse scope; return → lines → Inventory rejected-exit → numbering | No Job input. Return expectation does not implement supplier credit/AP settlement. |
| `Actions/{Create,Update,Archive}ItemSupplier`, `MaintainSupplierOffer` | Sourcing reference data/terms → item/supplier/package/UoM/currency | Item-supplier manage and separate commercial access; offer → item-supplier relation, preferred sibling checks | Not an incurred Job obligation. |
| `Actions/ResolvePurchasingSettings`, `UpdatePurchasingSettings`, `ReconcilePurchaseOrderFx` | Company settings/history and PO FX evidence | Settings lock/transaction; settings-manage duty; FX invoked by guarded PO action | No Job source generation. Company settings cannot certify Job inapplicability. |

Schema evidence: migrations `2026_09_02_110000_create_purchase_orders.php`,
`2026_09_02_120100_create_goods_receipts.php`, `2026_09_02_130000_create_quality_dispositions_table.php`,
`2026_09_09_140000_link_open_purchase_orders_to_cutover.php`,
`2026_10_09_110000_create_supplier_returns_and_stock_scraps.php`,
`2026_10_03_170000_add_receipt_package_observations.php`,
`2026_10_02_180000_create_packages_and_supplier_offers.php` and
`2026_12_05_100000_add_fx_governance_evidence_to_purchase_orders.php` enumerate the keys above;
none adds a Job/Sales operational binding. Posted mutation guards are in `2026_09_08_140000`.

## Imports, workers and source effects

`Imports/Actions/{CreateImportProfile,StageImportBatch,ValidateImportBatch}` write only import
profiles/mappings/batches/rows. `ImportTargetRegistry` supplies validation schemas; there is no
generic domain commit callback. Inventory/Purchasing owning commit Actions above consume only
their finite cutover schemas. Arbitrary raw predecessor data cannot establish authoritative Job
attribution. No separate background Job-material/supplier writer was found in these modules.
Future Finance, labour recognition, supplier services and external attribution are outside this
inventory's certification; Jobs/Sales must keep unavailable mandatory families UNKNOWN.

## Actual lock orders, generations and concurrency handoff

Sales `HandoverAccess::lock`: fresh actor/access → `AuthorityFence` → acceptance → decision case
→ ordered scope claim heads → quotation revision requirement. `lockSource` takes authority,
acceptance, case and heads, but omits the revision requirement; its caller must establish the
appropriate source access separately. Inspected `ReleaseCoordinator` reads immutable
stage/baseline without `FOR UPDATE`, appends release evidence, then calls receiver. Do not report
a stage lock merely because the proposed plan lists one. Jobs `JobLifecycleOperation` calls
`verifier->enter()` before locking the Job; receipt replay can precede that Job lock. Numbering
locks are owned by the receiver/central counter, which this source task does not implement.

Inventory and Purchasing currently take no Sales authority/acceptance/claim or Job source-family
fence. Their higher-level Actions **do** use the company access fence via `DecisionActor`.
Package request locks are transaction advisory keys `package-checkpoint:{company}:{key}`;
package predecessors/stock watermarks and recalculation impact/approval versions guard their own
physical/economic evidence. None is a Job applicability generation. Purchasing has no Job source
head. Do not add location/pool locks to Sales release to compensate for unvalidated source identity.

Known path differences matter: ordinary issues lock location before layers; rejected exits can
lock receipt layer before location; receipt posting can lock first-use item before location;
recalculation takes company before connected replay resources. These are potential lock-order
inversions to analyse if paths are connected, not a newly reproduced deadlock. A global source
order cannot honestly be inferred from the intended architecture. No new lock edge is introduced
by this documentation.

If the pilot unconditionally excludes every supported lineage write at source boundaries, no
Inventory/Purchasing business row needs locking for those impossible writes. Prove that exclusion
first. If any permitted lineage attribution is introduced, agree a source-family fence before
physical Inventory/Purchasing locks and before Job head locks, and pin its generation through
release/start/complete/close. Never acquire Sales authority after a Job/stock lock. This is a
required joint contract review, not an implemented ordering.

Verification under the frozen boundary preserves scalar/package/override/recalculation
provenance before/after execution and retry; those writes must not register a validated Job
binding. There is no Job-attribution writer to refuse or mutable family generation to race.
Sales/Jobs independently verify missing authority/UNKNOWN and customer-agreed scope refusal.
The #123 fix additionally proves the existing receipt-line lock with two real sessions: a
loser misses early replay, waits for the uncommitted winner, then returns the same exit or
refuses changed document identity under the lock. No new lock edge is introduced.

A future mutable authoritative binding requires source-first/Job-first race tests in both
schedules, raw-SQL binding attacks and generation checks through actual COMMIT before its
activation; these undelivered binding tests are not claimed as current passes. Existing source,
company/scope/permission, package conservation and Purchasing correction regressions remain
required. Use wrappers with `ERP_AGENT=source-safety`.

## Bounded implementation decision and dependencies

The primary Sales/Jobs lead froze #122 at `9ab38ae7`; source-safety acknowledged the exact
interface and reserved files, and resumed #120. Source-owned files:

- `Inventory/Support/JobSource/InventoryJobSourceApplicability.php` and
  `Purchasing/Support/JobSource/PurchasingJobSourceApplicability.php` own their binding lists and
  family/version outcomes; registration hunks in their respective module providers.
- Inventory/Purchasing feature tests prove registration, foreign-company UNKNOWN and simulated
  future-binding APPLICABLE, plus normal scalar, transfer/reversal, package, recalculation
  intake/execution/retry, override intake and rejected-document provenance preservation.
- No ordinary Inventory/Purchasing writer changes for #120. #123 is a separate Action/test fix.

Sales owns `SourceApplicabilityProof`, immutable customer-agreed exclusion evidence and pinning
family contract versions through release/execution. Jobs owns refusal of UNKNOWN/APPLICABLE
and source-before-Job execution entry. Missing authority remains UNKNOWN in the shared registry.
Future authoritative bindings must enter the owning finite list, change the family outcome and
coordinate real source fences before activation. Current adapters add no data-dependent locks
or generations because no mutable authoritative Job binding exists. No new permission or grant.

Independent source/security review and full Sales/Jobs integration evidence remain required.
Actual pilot company/source, mandatory policies and activation are owner/coordinator decisions.

## Verification at this handoff

The original documentation links and whitespace checks passed in `3a3cfe98`, now present
on remote develop through shared-branch publication. This follow-up does not republish it.
The original source-provenance probe failed fixture setup, then corrected reruns were refused
at 97%/90% tmpfs use; those runs still establish no source-writer outcome.

Follow-up inspected develop `4f19d7f2` plus simultaneous uncommitted Sales/Jobs work. Test
capacity was 87%, allowing a separate rejected-exit identity investigation through the wrapper:

`ERP_AGENT=source-safety docker/backend/run-tests-tmpfs.sh tests/Feature/Modules/Inventory/RejectedExitDocumentIdentityProbeTest.php --filter=source_safety_document`

**Two failed tests, ten assertions, exit1**: both expected DomainInvariantException refusals
were absent when only document id or document type changed under the original request key.
Unchanged replay returned the original row, preserved move count and retained original document
identity. Initial posting's deferred constraints were forced. This confirms the idempotency
defect, not a source-attribution safety pass or a two-session concurrency result.

The defect is separately recorded as
[#123](https://github.com/kaleemnadeem16/erp/issues/123), with the focused reproducer and output.
The owner subsequently authorised the bounded #123 correction. It now preserves the hash
format and compares persisted document identity in both replay checks. Existing immutable
hashes need no backfill; null and omitted identity are equivalent. Permanent tests cover early
and under-lock retry/refusal, exact evidence preservation, and a real concurrent loser behind
an uncommitted winner. The investigation-only failing
test was removed from the shared suite; its source is preserved on #123 for the owning agent.

Only this agent's `erp_testing_source_safety` database was dropped after the investigation.
No other database was deleted and no shared infrastructure restarted. Coordinator was asked
to arrange safe sustained headroom through database owners. Environment capacity is not an
additional dependency on #120; that task remains blocked only on #122. No production code,
permission or migration is introduced; pilot certification still requires independent integration review.

### 2026-11-13 implementation verification

Through supported wrappers/private database: Inventory feature/unit plus Purchasing feature
regression **1,524 passed / 8,164 assertions**; write/read/scope/stock/permission/Action ratchets
**39 passed / 98 assertions**. Final source assessment/provenance run **9 passed / 39 assertions**
(including transfer/reversal added after module discovery), grouped/rejected provenance
**1 passed / 6 assertions**. #123 permanent identity cases **18 passed / 81 assertions** and
real two-session receipt-line races **3 passed / 26 assertions**. Pint and whole-project PHPStan
passed. Standard PHPStan cache was root-owned; the successful run used a private temporary
cache configuration, then removed only that configuration. No shared cache permissions changed.
One initial module command named a nonexistent Purchasing unit directory and exited before
running tests; the corrected regression command used only existing directories and passed.
No migration, API/client or UI change requires a schema cycle, codegen or browser run here.
Published-SHA CI and independent review evidence are recorded on #120/#123, not inferred from
these local results. Production configuration and permissions remain inactive.

## Related

- [Inventory](../../backend/app/Modules/Inventory/README.md) · [Purchasing](../../backend/app/Modules/Purchasing/README.md)
- [Imports](../../backend/app/Modules/Imports/README.md)
- [Job closeout](job-closeout-reconciliation.md) · [Job material attribution](job-material-attribution.md)
- [Batch 1 package](../phases/phase-5-batch1-authorisation-package.md)
- [Domain integrity](../architecture/domain-integrity.md) · [Agent coordination](../architecture/agent-coordination.md)
