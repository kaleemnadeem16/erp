[← Phase index](README.md)

# Phase 5 — refined Purchasing and external-work resolution

**Historical research/proposal:** reconciled with owner approval2026-10-16 in accepted0149 and [bounded external contract](../domain/job-external-cost-attribution.md). Questions below preserve the prior review stage; Q54 is closed. No implementation delivered.


**2026-10-16 · PROPOSED / Q54 AWAITING_OWNER.** Business direction supports refinement only.
No new ADR, domain contract acceptance, application implementation, grant or migration authorised.

## Business examples before architecture

**Small call-out:** a specialist fixes a fault for PKR 8000.0000 without a PO. An evidenced incurred
expense can be recorded/recognised without inventing hours, a stock receipt or a payable. Later invoice
8000.0000 matches that contribution; payment settles a separate obligation, not another Job expense.

**Shared electrician:** agreement30000.0000; an accepted defined portion is12000.0000, attributable
7500.0000 to Job A and4500.0000 to Job B. Remaining unperformed commitment18000.0000 stays visible.
Invoice13000.0000 against that portion requires reconciliation of1000.0000, not another13000.0000 cost.
The difference may be disputed, a supported price adjustment or unrelated scope; evidence decides.

**Stock purchase:** order10, receive6, consume4. Only4 reach Job actuals through Inventory. The other2
remain stock and4 remain undelivered. Purchase attribution neither consumes nor reserves any quantity.

**Hire:** five agreed days at4000.0000/day; three evidenced chargeable days cost12000.0000 even if
only two were productive. A payment/deposit, delivery or work-site attendance alone proves neither
three chargeable days nor all five. Off-hire, idle charges and minimum terms require actual evidence.

**Stopped subcontract:** of100 specified units,60 accepted,10 disputed and30 unperformed. Preserve all
three portions and their obligations; do not present70 as accepted or40 as automatically cancelled.

## E1 — one attribution foundation, distinct source authority

Extend the logical 0146 source envelope/recognition/valuation/attribution/correction framework.
Do not create another direct-expense ledger for external work or a second monetary entry because
Job/activity/category projections exist. This is proposed adapter reuse, not a claim code exists.
0146's initial internal-labour boundary remains; external recording requires its own evidence/policy
adapter. No new accepted ADR interpretation silently activates an external source.

| Expense substance | Recommended classification |
|---|---|
| Labour supplied as labour effort under supported basis | LABOUR; external supplier origin retained, no universal named worker |
| Supplier accountable for defined output/deliverable | SUBCONTRACT; not automatically LABOUR because people performed it |
| External plant/equipment hire | PLANT/equipment-hire subtype; charge basis distinct from productive usage |
| Stocked goods | MATERIAL only through authoritative Inventory consumption |
| Other directly attributable service/expense | Explicit governed category mapping; no invented generic bucket or forced labour classification |

Mixed sources split identifiable economic components with conserved amounts; the same component
cannot occupy two categories. Reclassification is linked correction, not delete/re-enter. Where no
supported category/source adapter exists, retain evidence as pending and refuse actual attribution.

Direct incurred minimum evidence: company, supplier/payee identity at truthful available precision,
stable external document/component reference or authorised internal declaration, work/expense purpose,
Job/activity scope, occurrence date/period, evidenced incurred basis, amount/base currency and recorder.
An internal declaration states why external documentation is absent and the accountable basis; it is
not an unrestricted self-certified payment claim. Policy may require receipt/agreement/corroboration.
Duplicate/overlap checks include earlier aggregate labour and supplier-service records. Ambiguous
overlap remains unresolved; same date/amount alone neither proves identity nor creates uniqueness.

Recognise only the supported incurred contribution, under separate record/recognise duties and any
applicable shared approval. SIMPLE one action is allowed when all duties/evidence hold; no universal
independent approver, PO, timesheet, HR or fake quantity1. Procurement-required policy cannot be bypassed.
Recording an invoice image or payment receipt does not approve an invoice/tax/payment/accounting entry.

## E2 — Purchasing owns one reusable service acceptance

Recommend one Purchasing-owned supplier-performance source, used for ordinary services, subcontract
deliverables and hire. Job owns technical evidence/confirmation where appropriate; Purchasing binds
it to the supplier agreement and accepted portion. One authoritative acceptance references that
evidence; no parallel Job/subcontract/equipment acceptance totals. Technical acceptance does not
prove commercial price authority, and purchasing issue authority alone does not prove performance.

Minimum workflow: capture performed/chargeable portion → validate evidence/authority → confirm supported
acceptance (or reject/dispute) → recognise supported cost through the shared foundation. Managed review
uses shared approvals bound to exact revision; SIMPLE can combine eligible steps atomically. No second
mandatory approval or manual cost posting merely because an acceptance is recorded.

Acceptance evidence: supplier/agreement or allowed no-PO basis, stable source portion, service scope,
occurrence/period, accepted quantity/UoM OR defined deliverable/milestone, outcome/evidence references,
technical confirmer and accountable acceptance actor/time, disputed/rejected/remainder portions,
valuation basis/revision and supported Job allocations. Partial fixed-price performance needs agreed
stage values or evidenced allocation basis; manual progress percentage does not earn that price.
Reject/dispute are explicit facts, not deletion. Corrected acceptance appends dependent cost/allocation
effects. One supplier deliverable spanning Jobs is accepted once and then attributed, not accepted twice.

## E3 — performance before invoice, with independent valuation certainty

Supported authoritative performance + reliable pinned agreement/rate/direct-incurred evidence allows
pre-invoice monetary recognition. Preserve supplier terms, unit/basis, date, currency/base/FX and
certainty. A fully supported amount can be valued without being invoiced or financially settled.
Known estimated/provisional valuation is labelled with its basis; missing/unreliable valuation remains
explicitly incomplete (0110-style COSTED/PARTIAL/UNKNOWN), not zero or a guessed costed total.
Performance can be accepted with unpriced evidence; recognised quantity/performance and monetary
valuation remain independent. No promised fully valued actual without the required valuation evidence.

Authority must cover exact supplier/source/Job scope, performance confirmation, commercial basis
adoption and recognition. A supplier assertion alone is a claim awaiting authorised confirmation;
payment is not a performance confirmation. Applicable review must bind the relied-on revision.
Later rate/master changes never recalculate history automatically.0147 source valuation/category
coverage/overall completeness remain separate; new authoritative evidence reassesses coverage.

## E4 — shared Purchasing allocation without duplicate claims

Purchasing owns the immutable issued commitment and source remainder. Job allocation references
line/portion/version, target scope and quantity/amount basis; general-company/unallocated remainder
is explicit. A header link is a convenience projection, never authoritative shared allocation.
Conserve each source quantity in compatible exact units and each allocatable amount/currency separately;
do not sum unlike units/currencies or infer supplier commitment from labour hours/customer capacity.

Commitment allocation is planning exposure, not accepted performance or actual attribution. Each
partial receipt/acceptance selects its applicable portions explicitly; no automatic duplication of
the full line across Jobs. Example10units split6A/4B, receipt6 selects4A/2B: remaining commitment2A/2B;
material actual still follows eventual Inventory issues, not the receipt split. Reallocation of
unused commitment requires old/new scope authority and preserved issued history; reattributing an
already recognised cost uses correction, not moving an editable tag. No reservation is implied.

For amount-only stages use agreed portion values, not fictitious stock quantities. Deterministic
rounding conserves source amounts; reuse precision infrastructure only after proving its applicability,
not Sales pricing policy by assumption. Returns/reversal do not automatically recreate supplier order
capacity: apply owning Purchasing rules; replacement needs explicit eligible commitment. Cancelling
or short-closing one portion cannot erase accepted actuals or release another Job's allocated claim.

## E5 — equipment and subcontract boundaries

Hire evidence retains agreement, equipment/class identity as required, charge unit/period, on/off-hire
facts, supported minimum/idle terms and accountable confirmation. Productive usage is separate optional
operational evidence. No eight-hour day conversion or automatic calendar accrual. Delivery is custody/
availability evidence, not acceptance of every future charge. Owned-plant costing remains separate;
mixed operator/hire charges split only where evidenced, without billing the same labour twice.

Subcontract acceptance uses actual quantities/defined stages. Progress, supplier performance,
customer fulfilment and cost are distinct. Rejected work stays visible; chargeability of rework or
disputed work requires supported agreement/disposition. Do not auto-value every rejected portion at
zero or recognise every supplier claim. Stop/hold records do not extinguish cancellation fees/claims.
0145 holds/termination block new ordinary execution; legitimate late historical reports and necessary
cost evidence follow governed occurrence-context review, not fabricated dates or blanket bypass.
Purchasing separately cancels/short-closes eligible unperformed commitments. Sales owns customer
corrections; buying or cancelling supplier scope never changes R7 accepted customer capacity.

## E6 — later invoices, payments and Finance

Every later authoritative line must identify covered economic components and allocated portions:
confirm existing value, replace with linked reversal/successor, or append supported difference.
Only one effective contribution is counted. Partial invoice of5000.0000 against an already recognised
12000.0000 portion does not add5000.0000 or erase the other7000.0000. Preserve reconciliation state.
Invoice13000.0000 can produce an authorised1000.0000 adjustment once; dispute leaves unresolved
difference visible. Novel unrelated scope needs its own authority/source, not a forced match.

No automatic match merely by supplier/date/amount. Unknown overlap refuses automatic second
attribution; unrelated equal expenses remain possible. Credits/cancellations correct the relevant
source portion, not arbitrary Job totals. Stocked invoice differences route acquisition costing/
Inventory/0040 to consumed Jobs, never an additive direct service expense. Supplier invoice, AP,
payment, tax, recoverability, period treatment and GL representation remain Finance-owned; they are
not extra Job cost categories. Initial base-only support preserves unsupported foreign evidence
without invented conversion. Operational closeout neither finalises supplier bills nor settles Finance.

## E7 — current implementation verification and delivery gates

Inspected working tree2026-10-16 (HEAD1592e1e plus unrelated WIP; source review, no runtime tests):

- Purchasing README/Actions own PO and physical GoodsReceipt, not reusable service acceptance.
- PostGoodsReceipt locks receipt/PO headers/lines, uses stock-UoM cumulative receipts/tolerances,
  calls PostInventoryMovement and package evidence, then can close fully received orders.
  DraftGoodsReceiptLines resolves PO Item/UoM/location and stock conversion. It is not a service path.
- ShortClosePurchaseOrderLine calculates remainder from posted GoodsReceipt stock quantities;
  CancelPurchaseOrder changes eligible header status/invalidation. Neither inventories future
  service acceptances, split Job claims, hire exposure or Finance dependencies. Do not reuse unchanged
  and claim those obligations reconciled. Necessary owning extensions are activation gates, not fixes here.
- No supplier-service acceptance/Job-cost/Finance integration was found in those boundaries.
  Existing approval/record-scope infrastructure is reusable, not proof these new surfaces are secured.

Before activation deliver source/category adapters, reusable service acceptance where applicable,
guarded allocation/recognition/correction and all affected cancel/short-close/return/import/replay writers.
Guard Request AND Action; independent record, technical confirm, supplier accept, price adoption,
recognise/correct and sensitive read eligibility; no assignment-derived access or new grants here.
Protect both old/new Job scopes, supplier and commercial fields, history/receipt/aggregate/export paths.

Require same-connection atomic effects/receipts and compatible deterministic locks across Purchasing
header/line, Job, source portions, recognition/correction owners; exact lock order must be proven
against existing writers before delivery. Stable tenant-safe identities/constraints cover absent
anchors; version checks protect quantity/amount capacity. Current-authorised exact-intent retries
return one receipt; changed intent conflicts; a fresh key cannot duplicate the economic contribution.
Required effects fail closed under accepted coordination, no unsafe partial posting then a note.
0148/0040 gate every stocked-consumption route; service correction/reconciliation needs its own
owning participants, not a physical stock issue. Future Finance activation must inventory prior
direct/aggregate/performance sources and deliver matching/corrections before emitting authoritative
overlapping Job costs. Full Finance is not a universal prerequisite for bounded operational recording.

Closeout requires owning reconciliation/disposition of known unaccepted/disputed work, remaining
commitments, hire/off-hire/custody and required corrections. Known invoice follow-up can remain only
with supported owner/path and honest incomplete status under0145; unknown impacts cannot be waived.

## E8 — proposed SIMPLE first scope and alternatives

One company-base evidenced direct external expense; one simple fixed service or fixed/day hire;
defined partial quantity/stage acceptance; shared Job portions with explicit unallocated remainder;
one linked correction and overlap-safe later-source interface. UI may default to one Job and combine
eligible actions. No universal PO, worker identity, timesheet or independent reviewer.
Acceptance and split allocation are candidates requiring delivery, not existing functionality.

Defer automatic accrual, complex hire calendars/maintenance/telematics, retention/certification suites,
framework call-offs, unplanned cost-plus escalation, foreign-cost posting and unsupported third-party
materials/containers. Invoice/AP/GL/payment implementation stays Phase6. Required exceptions refuse
their affected route. A no-PO expense cannot simulate missing inventory consumption or bypass policy.

Alternatives: invoice-only costing avoids pre-invoice valuation work but hides evidenced incurred
cost; commitment-as-actual overstates it. Separate ledgers/acceptance systems simplify one screen but
duplicate corrections. Universal independent review adds burden without proving performance. Recommend
shared foundation, source-specific authority/evidence and optional managed review instead.

## Research synthesis and remaining owner choices

Reuse [prior primary comparison](phase-5-purchasing-external-research.md). Fresh evidence accessed2026-10-16:
[SAP service entry sheets](https://help.sap.com/docs/SAP_S4HANA_ON-PREMISE/af9ef57f504840d2b81be8667206d485/4ac8acf820ad41a8a5841420085ba68d.html)
records performed services against POs and submission for approval (official indexed extract; page
body unavailable). [Dynamics vendor invoicing](https://learn.microsoft.com/en-us/dynamics365/project-operations/pro/subcontracting/vendor-invoice-overview)
matches earlier approved project costs and uses reversal/replacement on invoice confirmation.
Common pattern is distinct performance evidence and reconciled later monetary authority. SAP's
PO/approval packaging and Dynamics' integrated Finance/replacement mechanism differ; neither dictates
mandatory POs, approval stages or a Finance dependency here. Reuse evidence/lineage, not vendor schemas.

Owner review requested: confirm E1–E8 resolution, especially (a) accountable declaration as a permitted
no-PO evidence basis where policy allows, (b) Purchasing-owned reusable acceptance with Job technical
evidence, and (c) fixed/day hire and defined partial subcontract stages in the first supported scope.
Recommend all three with the stated gates. Alternative is to defer no-document declarations or hire/
subcontract adapters initially; ordinary evidenced supported expenses can proceed separately. No
request to choose database/API mechanics or reopen settled Q52/Q53/allocation/commercial decisions.

## Testable acceptance/failure scenarios — proposed, not executed tests

| ID | Expected result |
|---|---|
| EX01 | No-PO8000.0000 evidenced expense: one recognised source; no invoice/payment/tax/GL implied |
| EX02 | Missing incurred evidence or mandatory procurement policy: refuse recognition/bypass |
| EX03 | Same expense captured under0146 and external adapter: reconcile, no second cost |
| EX04 | Accept12000.0000 across7500.0000/4500.0000: conserved amount, remainder18000.0000 |
| EX05 | Unpriced accepted performance: retain outcome/quantity, incomplete value, no zero |
| EX06 | Partial invoice5000.0000 against12000.0000: no extra cost or lost7000.0000 |
| EX07 | Invoice13000.0000: linked authorised1000.0000 difference once or visible dispute |
| EX08 | Order10/receive6/use4: only Inventory-consumed4 is Job material cost |
| EX09 | Split receipt/cancellation/return: no duplicated capacity; no automatic restored commitment |
| EX10 | Hire3 chargeable/2 productive days:12000.0000 charge, no invented hours/double labour |
| EX11 | Subcontract60 accepted/10 disputed/30 remaining: no implied70 accepted or40 cancelled |
| EX12 | Hold/terminate/late evidence: preserve actuals and supplier claims, governed context review |
| EX13 | Accept/cancel/correct/close race: coherent obligations or refusal; rollback all required effects |
| EX14 | Same/fresh-key duplicate or changed intent: one effect or conflict; current authority rechecked |
| EX15 | Limited scope/price-denied actor: no bypass/disclosure through combined action/history/export |
| EX16 | Missing correction/Finance overlap adapter: affected activation/posting fails closed |
| EX17 | New invoice contradicts coverage: reassess0147 while preserving original assessment/history |
| EX18 | Known unresolved hire/dispute/correction: no unsupported reason-only closeout |

## Related

- [Phase5](phase-5-job-execution.md) · [Prior assessment](phase-5-purchasing-external-research.md)
- [Purchasing](../domain/purchasing.md) · [0146 labour](../domain/job-labour-recognition.md)
- [0147 coverage](../domain/job-cost-coverage.md) · [0148 materials](../domain/job-material-attribution.md)
- [Lifecycle](../domain/job-execution-lifecycle.md) · [R7](../domain/sales-operational-handover.md)
