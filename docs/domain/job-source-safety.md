[← Inventory module](../../backend/app/Modules/Inventory/README.md)

# Job source safety — Inventory and Purchasing writer inventory

2026-11-12 · Source-safety task [#120](https://github.com/kaleemnadeem16/erp/issues/120),
for [#69](https://github.com/kaleemnadeem16/erp/issues/69) / #108 / #111.
Inspected committed `develop` **f3ea6069** and the simultaneous, uncommitted Sales release
implementation (subsequently committed as `cc4c8940`, rechecked at `70952b17`). This is an investigation and integration handoff, not pilot certification.
No Inventory/Purchasing production behaviour, permission, migration or configuration changed.

## Finding and applicability contract

**Inventory applicability remains UNKNOWN.** Absence of a `job_id` column or Job FK does
not establish absence of a source relationship: Inventory stores caller-selected
`source_type` / `source_id` in authoritative movement, layer, recalculation and override evidence.
Some of these inputs are public. A fabricated discriminator is not a valid Job integration,
but the existing writers neither validate nor refuse that purported provenance. No current
consumer was found that recognises those pairs as a valid Job cost/fulfilment allocation.
Do not claim that this investigation found an implemented Job-material adapter.

Sales `SourceApplicabilityProof::evaluate()` in `cc4c8940` examines only incoming FKs;
`JobLineageIsolationTest` also checks column names. Both miss this existing channel. They
cannot certify all source families NOT_APPLICABLE. The initial finding F2 posted on #69/#111
therefore needs this qualification. The source owner notified #69 immediately on discovery.

| Outcome | Evidence required for this pilot |
|---|---|
| NOT_APPLICABLE | Authoritative immutable accepted scope/conditions exclude that obligation; the complete owning writer set cannot attach it, including generic provenance/corrections/imports. Pin the reviewed contract version and source basis. |
| UNKNOWN | Scope does not establish exclusion, a writer accepts unsupported lineage, the writer inventory changed, or required source adapter/protection is missing. Refuse affected release/execution/closeout. |
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
All listed operational posting boundaries use transactions. Lower-level posters inherit the
caller's authority/transaction; they do not independently grant business authority.

| Actual writer / caller | Tables and source identity | Authority, actual lock/freshness protocol | Pilot exclusion / required action |
|---|---|---|---|
| `Actions/PostInventoryMovement` → `Actions/Movements/ReceiptPoster` | `stock_moves`, `stock_layers`, valuation entries/pools, shortage settlements; arbitrary caller source pair | Facade has no actor; owning caller authorises. First-use item → location → shortages as applicable → pool; atomic posting, source-key replay | UNKNOWN: source identity unconstrained. Cover receipt and retry before accepting provenance. |
| Same facade `issue` / `issuePosted` / `issueRejectedPosted` → `Movements/IssuePoster` | Movement, layer balances/consumptions, pools, valuation, optional shortage; arbitrary pair | Location → layers `(received_at,id)` → override request/shortage if needed → pool; shortage cost may lock historical layer; source-key replay rechecked | UNKNOWN at generic boundary. Actual rejected-exit caller uses fixed source and named receipt layer. |
| Same facade `transfer` / `transferPosted` → `Movements/TransferPoster` | Movement, destination layers, transfer details, pool/settlement evidence; arbitrary pair | Locations ascending id → source layers → pool pair ascending id; source-key replay rechecked | UNKNOWN: no Job namespace/refusal. |
| Same facade `reverseReceipt` → `Movements/ReceiptPoster::reverse` | Movement with caller reversal pair; original found by caller original pair, original layer/pool changed | Location → original layer → pool; untouched full receipt required; source-key replay | Must cover original and reversal identity, not only new receipt/issue. |
| `Actions/IssuePackagedStock`, `TransferPackagedStock` | Same arbitrary pair forwarded to scalar posting; package operations/draws/contributions/allocations | Fresh actor, package-state issue/transfer + view, warehouse scope; request advisory → location(s) → stock locks; pinned predecessor/watermark | Generic channel remains. Restriction must also precede replay/attachment semantics. |
| `Actions/IssueGroupedStock`, `TransferGroupedStock` | Fixed `MANUAL_PACKAGE_ISSUE` / `MANUAL_PACKAGE_TRANSFER` and generated own source id | Delegates to package Actions under their duties | Wrapper cannot attach lineage by construction; preserve normal stock work. |
| `Actions/RequestStockRecalculation` | `recalculation_requests`, impact versions; arbitrary pair from public Request | Fresh actor/access → company → permission `inventory.recalculation.request`, unrestricted warehouse scope; policy/horizon/impact preview; tracked-package refusal | UNKNOWN. Public `Http/Requests/Recalculation/StoreRecalculationRequestRequest` accepts arbitrary string/integer source. |
| `Actions/ExecuteRecalculation` → `Actions/Recalculation/OutboundRecalculationExecutor`, `FifoReceiptReallocator` | **Direct** stock movement/layer/valuation writes; request source copied; replacement layers preserve transfer provenance | Fresh actor + execute duty → company → request → impact version → location(s)/replay component layers/pools; approved version/dependency freshness | Facade-only restriction misses these. Cover existing requests at execute/revalidation as well as new request intake. |
| `Actions/RequestNegativeStockOverride`, `DecideNegativeStockOverride`, `ReviewNegativeStockOverride` | Override requests/approvals/reviews; arbitrary source pair, later exact-matched to movement; shortage FK to movement | Fresh actor/access → company; separate request/approve/review duties, warehouse scope, policy/independent actor; immutable decisions | UNKNOWN at intake. Public `Http/Requests/NegativeStockOverride/StoreNegativeStockOverrideRequestRequest` accepts arbitrary source. Cover legacy request use. |
| `Actions/PostPhysicalCount`, `PostGroupedCountLine` | Count/lines → fixed `PHYSICAL_COUNT_LINE`, actual line id; stock via facade | Post duty and independent actor; count → ordered lines → ordered locations → stock locks | No Job input; structurally excluded. |
| `Actions/CommitInventoryCutoverOpeningState` → `Actions/Cutover/OpeningStateItemPoster` | **Direct** stock/layer/pool writes; fixed `INVENTORY_CUTOVER_OPENING`, `_OPENING_SHORTAGE`, `_SYNTHETIC` → fact/exception/anchor | Fresh actor, cutover commit duty; cutover → batches → item; approved opening facts, idempotent batch status | No Job input; structurally excluded. |
| `Actions/{Create,Attach,Validate,Approve,Finalise}InventoryCutover`, negative-opening request/approval, policy-transition recorder | Cutover/facts/transitions/negative exceptions; real cutover/import FKs | Separate cutover duties, fresh actor/access; cutover/batch locks, independent approval, final reconciliation | No Job input; no extra pilot restriction. |
| `Actions/PostRejectedStockExit`, `PostRejectedExitLines`, `PostStockScrap` | Receipt line/layer → `rejected_stock_exits`, fixed movement source `SUPPLIER_RETURN` / `SCRAP_WRITE_OFF`; **core also accepts arbitrary nullable `document_type/document_id`** | Return/scrap duties + warehouse scope; outer document/lines → receipt line → layer → location | Outer return/scrap wrappers use fixed actual line types; generic core document pair needs review/restriction. Layer-before-location differs from ordinary posting. |
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

Required real PostgreSQL tests after that contract is agreed:

- Two sessions: release pending versus unsupported scalar/package/override/recalculation write;
  reject before effects both before release commit and afterwards, preserving unrelated stock work.
- Execute an already-persisted recalculation request, reverse original and replacement identities,
  and attempt raw SQL insertion; facade-only or HTTP-only rejection is insufficient.
- If a mutable allowed source family exists, source-first/Job-first competing operations must
  serialise or refuse stale generations; test both schedules and actual outer COMMIT.
- Retain existing source replay, warehouse/company isolation, permissions, package conservation,
  Purchasing receipt/reversal and correction regressions. Run wrappers with `ERP_AGENT=source-safety`.

## Bounded implementation decision and dependencies

No production restriction is implemented yet. The assignment requires explicit Sales/Jobs lead
coordination of exact files/interface/lock order before integration-sensitive changes. #69/#120
carry that request. Candidate source-owned files are the movement facade, package issue/transfer,
recalculation request/execute, negative-override intake, rejected-exit document identity and their focused tests; a database guard
would require a reserved additive migration. No reservation or protocol acknowledgement is assumed.

A blacklist of `JOB` strings alone is not exhaustive evidence: arbitrary aliases remain possible.
An exhaustive source-type registry/allowlist could cover them, but changes existing generic stock
caller semantics and needs review. Another possible contract is explicit non-attribution for generic
provenance plus an enforced finite Job-attribution boundary; it must account for existing authoritative
source semantics rather than silently declaring stored identities meaningless. The source owner does
not independently choose this cross-module business contract or rewrite Sales' readiness implementation.

Agent A must correct/qualify FK-only proof and provide genuine immutable no-material/no-supplier scope
basis. Agent B must retain unavailable/UNKNOWN refusal, source-before-Job entry and no operational
activation pending source certification. Coordinator must confirm the finite source identity contract,
file/migration reservations and independent reviewer. Actual pilot company/accepted service selection
and any mandatory costing/evidence policy remain with the owner; this investigation invents none.

## Verification at this handoff

Local link resolution and `git diff --check` passed for the owned documentation. A temporary
negative runtime probe expected a DomainInvariantException for `SERVICE_JOB` source provenance.
Its initial run failed fixture setup (`items.category_id` missing), so it proves no writer outcome.
The fixture was corrected, but reruns were refused by the supported wrapper at 97% and 90%
test-server RAM-disk use (exit70). Only this agent's private database was dropped. The temporary
probe was removed; there is no shipped failing test or claimed successful negative test.
No PostgreSQL concurrency test, module regression or production-code check is claimed; no production
code changed. Runtime protection/testing resumes after the shared capacity and contract dependencies.

## Related

- [Inventory](../../backend/app/Modules/Inventory/README.md) · [Purchasing](../../backend/app/Modules/Purchasing/README.md)
- [Imports](../../backend/app/Modules/Imports/README.md)
- [Job closeout](job-closeout-reconciliation.md) · [Job material attribution](job-material-attribution.md)
- [Batch 1 package](../phases/phase-5-batch1-authorisation-package.md)
- [Domain integrity](../architecture/domain-integrity.md) · [Agent coordination](../architecture/agent-coordination.md)
