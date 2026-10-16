[← Domain index](README.md)

# External Job cost and Purchasing attribution

**2026-10-16 · ACCEPTED0149/Q54 resolved; bounded contract SPECIFIED, not implemented/verified.**
Use 0146's logical evidence/recognition/correction foundation with owning external-source adapters.
No second direct-expense ledger and no independent Job supplier-acceptance total.

## Where it sits and source ownership

| Owner | Authoritative facts |
|---|---|
| Purchasing | Supplier commitments/issued terms, reusable service acceptance, eligible cancellation/short-close |
| Job Execution | Technical evidence, supported expense recognition and Job attribution/closeout |
| Inventory | Physical stock/custody, consumption and posted valuation/0040 adjustments |
| Future Finance | Supplier invoices/matching, liabilities/payment, tax/FX/period/accounting effects |

Job technical confirmation can support Purchasing acceptance, not create another authoritative
acceptance. Neither supplier acceptance nor cost recognition fulfils a customer obligation by itself.
R7 allocation/commercial authority and0145 lifecycle remain unchanged.

## First SIMPLE scope and deferred capability

Candidate paths: company-base evidenced direct external incurred amount; allowed accountable
no-document declaration; fixed supplier service, defined units/stages, fixed/day/unit hire and simple
subcontract partial acceptance; shared Job portions and explicit company remainder; linked corrections.
One Job is the UI default. One combined action is possible only with all duties/policy/evidence.
No universal PO, worker identity, timesheet, HR or independent reviewer. Required procurement policy
still applies. External adapter delivery is mandatory; 0146 internal direct labour is not a bypass.

Defer foreign-cost posting without its own FX adapter, automated accrual/metering, advanced fleet/
maintenance, retention certificates, complex subcontract trees and manufacturing subcontracting.
Unsupported ownership/container exceptions retain Q23/Q24 gates. No invoice/AP/payment/GL delivery.

## Logical evidence — no migration schema

- Source/component: company, immutable economic identity and source revision, origin/evidence kind,
  supplier/payee facts as actually known, document/reference or declaration reason, incurred purpose/
  date/period, recorder/time and source/category/Job scope. Unknown identity remains explicit; no fake supplier.
- Direct declaration: accountable actor/authority, context, amount/currency, incurred basis, scope,
  reason documents unavailable, available counterparty facts and corroboration where policy requires.
  Internally declared evidence never masquerades as verified invoice/payment/final accounting expense.
- Supplier acceptance: stable agreement/allowed no-PO basis and performed portion, units OR defined
  deliverable/milestone, occurrence/charge period, Job technical evidence, acceptance actor/time/revision,
  accepted/disputed/rejected/remaining/terminated portions and separate valuation basis.
- Allocation: immutable source line/portion/revision, Job/activity or unallocated company scope,
  quantity/unit and amount/currency basis, deterministic remainder and successor correction lineage.
- Recognition: exact evidence/valuation revision, applicable approval decision, actor and receipt;
  original/effective cost links and correction reason/authority. Usage/performance is not monetary value.

## Behaviour and classification

Classify labour effort as LABOUR, supplier-owned defined outcome as SUBCONTRACT, external equipment
hire as PLANT/hire subtype. Stocked MATERIAL actuals flow only from Inventory consumption. Other
services require supported explicit category mapping; no arbitrary labour bucket. Mixed components
conserve source amount and never count one component under two categories. Reclassification corrects.

Direct evidence proves a supported incurred expense; it does not create a PO, acceptance where
required, invoice, payable/payment or tax treatment. Detect overlap with existing internal/aggregate
labour and service sources; ambiguous overlap refuses automatic second attribution, not evidence capture.

Purchasing service workflow: record performed/chargeable portion → validate technical/commercial
authority/evidence → accept or dispute/reject → recognise through shared foundation. Optional managed
review binds exact revision. Single eligible SIMPLE action commits all effects or none. Current goods
receipt posts Inventory and must not be used as a fictitious service receipt. Existing receipt-based
short-close/cancel writers need service/allocation dependency integration before enabling these paths.

Recognise incurred monetary contribution before invoice only with authoritative accepted performance
and reliable authorised pinned valuation. Agreement, invoice or payment alone cannot prove work.
Missing/uncertain valuation leaves recognised performance explicitly incomplete; no zero/default rate.
Retain certainty/basis and original rate/terms/unit/currency/FX evidence; never recost from current masters.
Company-base amounts retain supported currency/FX1 evidence; unsupported foreign observations stay
unposted, without invented conversion.0147 valuation/coverage/overall completeness remain independent.

Hire needs agreed charge basis/period and supported on/off-hire/minimum/idle facts; equipment presence
does not invent days and productive time is separate. Fixed service partial value needs agreed stages
or evidenced authorised allocation, not a manual percentage. Subcontract accepted/disputed/rejected/
remaining/terminated portions remain distinct; rework chargeability needs its own supported disposition.

## Conservation, later sources and corrections

Line/portion allocations plus company remainder conserve compatible source quantity and each currency's
amount. Commitment is exposure, not actual. Partial receipt/acceptance selects bounded portions, not the
whole line repeatedly. Issued terms/history persist; reallocation requires old/new scope authority.
Already-recognised attribution changes append corrections. Returns do not automatically restore order
capacity; owning Purchasing rules govern replacement/cancellation without erasing actuals.

Invoice reconciliation identifies economic components/portions: confirm, linked reversal/successor
replacement, or supported delta. All conserve one effective contribution and retain original evidence.
Recognised12000.0000 followed by invoice5000.0000 does not add5000.0000 or erase unmatched7000.0000.
Invoice13000.0000 permits linked1000.0000 adjustment only with evidence/authority or remains disputed.
Amount/date alone cannot identify overlap. Payments/deposits/GL are representations/settlement, not
extra Job expense. Stock invoice differences route acquisition costing/Inventory/0040, never direct
service cost. Finance controls later tax/FX/matching/period treatment; no ordinary Job reopening.

## Authority, transactions and closeout

Procure, record, technical confirm, supplier accept, adopt price, recognise, correct and read sensitive
costs are independent eligibility checks. Reuse R9/RecordScope/shared approvals with exact scope proof;
assignment confers none. Guard Request and Action and current authority before replay; protect old/new
Job and supplier scopes plus history/receipts/aggregates/exports. No permission/grant created here.

Prove compatible lock order against Purchasing header/line and Job/source/portion/correction writers,
same runtime connection and atomic mandatory effects/receipts. Recheck lifecycle, readiness, source/
approval revisions and allocation capacity under locks. Stable company-scoped economic identities and
tenant constraints protect absent anchors and duplicates. Exact keyed intent conflicts on change;
fresh key cannot duplicate economic contribution. Required unknown/unsupported impacts fail closed
under accepted coordination; no unsafe partial posting then note. Inventory0040 gates stocked paths;
service correction/reconciliation requires its own participants before activation. Future Finance
writers inventory/reconcile prior direct/aggregate/performance evidence before authoritative overlap.

0145 hold/termination blocks ordinary new execution, not truthful governed historical evidence or
supplier claims. Cancellation separately resolves supplier commitments and Sales customer obligations.
Closeout reconciles known disputed/unaccepted work, remaining commitments, hire/off-hire/custody and
required corrections. Supported owned valuation/invoice follow-up may remain only under0145's explicit
incomplete-status/owner/path rules; unknown effects cannot be waived. Closure is not Finance settlement.

## Acceptance scenarios — required, not executed tests

| ID | Expected result |
|---|---|
| EC01 | Evidenced no-PO8000.0000: one expense, no invoice/payment/tax implication |
| EC02 | No-document declaration retains required context/reason/counterparty uncertainty; no fabricated identity |
| EC03 | Required PO/acceptance missing: direct form cannot bypass policy |
| EC04 | Same component in labour and external evidence: reconcile once, no duplicate |
| EC05 | One accepted12000.0000 split7500.0000/4500.0000; commitment18000.0000 remains separate |
| EC06 | Supported performance/unpriced: retain performance, incomplete value, no zero |
| EC07 | Partial invoice5000.0000 against12000.0000: no extra actual or lost unmatched portion |
| EC08 | Invoice difference1000.0000: authorised linked adjustment once or visible dispute |
| EC09 | Order10/receive6/use4: only consumed4 enters material actuals through0148 |
| EC10 | Partial cancel/return/reallocate: source capacity/history conserved, no invented restored capacity |
| EC11 | Three chargeable/two productive hire-days: evidenced charge, no invented hours/presence claim |
| EC12 |60 accepted/10 disputed/30 remaining: no implied70 accepted or40 cancelled; rejected/terminated retained |
| EC13 | Hold/late report/cancel/close race: governed history and coherent owned obligations |
| EC14 | Missing duty/stale review/currently revoked retry: refuse atomically without price/scope disclosure |
| EC15 | Failed mandatory participant: effects/receipt roll back; unsupported route not activated |
| EC16 | Same/fresh-key duplicate or changed intent: one contribution or conflict, no double source |
| EC17 | New authoritative evidence reassesses0147 without overwriting prior assessment/closeout |

## Landmines

Service acceptance is not a stock receipt. Shared attribution is not another commitment. Progress
and invoices do not prove fulfilment. Finance arrival must reconcile existing actuals before adding any.

## Related

- [0149](../decisions/0149-attribute-external-job-costs-through-owning-sources-and-reconciliation.md)
- [Q54 research/history](../phases/phase-5-purchasing-external-resolution.md)
- [Purchasing](purchasing.md) · [Labour](job-labour-recognition.md) · [Coverage](job-cost-coverage.md)
- [Materials](job-material-attribution.md) · [Lifecycle](job-execution-lifecycle.md)
