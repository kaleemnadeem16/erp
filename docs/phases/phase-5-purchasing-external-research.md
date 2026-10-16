[← Phase index](README.md)

# Phase 5 — Job-linked Purchasing and external work assessment

**2026-10-16 · RESEARCH PROPOSAL for owner review, not an accepted contract or implementation.**
Question: how should Job-linked purchasing, external labour/equipment and subcontract work preserve
commitments, accepted performance and one effective actual cost through partial delivery and later Finance?

## Business problems and examples first

**Bought materials:** order 10 fittings for a Job, receive 6, consume 4. The order is a supplier
commitment, 6 is physical receipt and 4 is Job consumption. Charging all three would count the same
purchase repeatedly. The other 2 remain stock; 4 undelivered remain a supplier obligation unless
Purchasing validly short-closes/cancels it. A Job link alone neither reserves nor consumes stock.

**External electrician:** agree 30000.0000 for two deliverables; one accepted portion is evidenced
at 12000.0000. Report that incurred portion separately from the unperformed commitment. A later
invoice for that same 12000.0000 confirms/reconciles it, not another expense. An invoice for 13000.0000
needs classification of the 1000.0000 difference and dispute/authority, not silent overwriting.

**Hired equipment:** order 5 hire-days at 4000.0000/day. Equipment delivered is not proof that every
hire-day is incurred or every day productively used. Three evidenced chargeable days are 12000.0000;
the remainder depends on off-hire/cancellation/minimum-charge terms. Internal plant usage and this
supplier hire charge must not both represent the same economic cost. Deposits/advances are not usage.

**Stopped subcontract:** accept 60 of 100 units, stop remaining work. Preserve accepted performance,
claims/disputes and supplier obligations. Job termination does not cancel a supplier PO or customer
obligation. Completion needs supported fulfilment evidence, not the amount invoiced or paid.

## Existing project boundaries reused

- [Purchasing](../domain/purchasing.md)/0035/0043 own supplier commitments, partial physical receipts,
  QA and short-close; Finance later owns supplier invoices, matching, AP/payment and period treatment.
- [Acquisition costing](../domain/acquisition-costing.md)/0040 own stock cost adjustments. Stocked
  material reaches Job cost through Inventory consumption, not an additive supplier-invoice route.
- 0146 owns flexible labour evidence and later source reconciliation. External supplier work is
  not the narrow internal direct-labour shortcut; no universal worker/timesheet/HR dependency.
- 0145 governs operational completion/closeout; 0147 coverage and 0148 material gates remain separate.
  R7/R9 protect allocation, source authority and restricted reads.0137 governs future documents.
- Existing PO/receipt delivery is not evidence of a delivered Job-link, service acceptance, equipment
  hire or Finance adapter. Historical job-costing schema/GL sketches are not a final current contract.

## Primary comparison — accessed 2026-10-16

| Source | Relevant distinction and limitation |
|---|---|
| [Business Central project supplies](https://learn.microsoft.com/en-us/dynamics365/business-central/projects-how-manage-project-supplies) | Separates outstanding orders and received-not-invoiced views; supports project-specific purchases. Its receipt/write-off and later project-ledger timing are product-specific, not our automatic-consumption rule |
| [ERPNext purchase cycle](https://docs.frappe.io/erpnext/purchase-cycle-ledger-impact) | PO commitment, receipt, invoice and payment have distinct effects. Useful source ownership; its accounting implementation does not supply our missing Finance adapters |
| [Odoo project profitability](https://www.odoo.com/documentation/19.0/applications/services/project/project_management/project_profitability.html) | Search-indexed official documentation distinguishes expected/to-bill/billed and project-linked purchases/bills. Full-page fetch timed out; secondary corroboration only, not sole basis for a consequential recommendation |

Convergence: retain linked stages rather than treating an order, performance and invoice as interchangeable.
Divergence: products differ in the event that creates project ledger cost and in integrated accounting
assumptions. Recommendation for this ERP: keep operational recognition distinct from final supplier
valuation, preserve one economic contribution, and research explicit reconciliation before choosing
tables or posting triggers. Do not copy immediate write-off of all project purchases.

## Meaningful options and recommendations — proposed, not accepted

| Decision | Alternatives / trade-off | Recommendation |
|---|---|---|
| P1 Job attribution | Header-only link is simple but cannot handle shared orders; line/portion allocation needs conservation | Support line/portion Job/activity attribution, including unallocated/general-company remainder; immutable issued history and guarded correction |
| P2 Incurred external actual | Invoice-only delays known cost; PO-as-actual overstates it; evidenced performance adds a small acceptance step | Recognise supported performed/chargeable portion with explicit valuation certainty before Finance, then reconcile invoice later |
| P3 SIMPLE route | Mandatory PO burdens every small purchase; unrestricted direct entry bypasses procurement policy | Keep controlled source-evidenced direct incurred route where policy permits, and PO-backed route where required; external-source adapter required for either |
| P4 Equipment | Productive usage only misses contractual hire cost; blanket calendar accrual invents terms | Capture charge basis/period and evidence, separate from productive usage; start with simple fixed/day hire, defer automatic accrual/telematics/asset fleet |
| P5 Partial subcontract | Invoice or manual percentage as fulfilment is weak; full certification suite is heavy | Evidence-based accepted quantities or defined milestones, with disputes/remainder ownership; no generic production WO |
| P6 Later Finance | Add invoice costs duplicates actuals; overwrite loses history | Explicit confirm/replace/delta allocation to the same economic component; unmatched/ambiguous overlap is visible and refuses automatic duplicate attribution |

These recommendations do not authorise general contract increases, repricing or changes to accepted
Sales capacity. An operational overrun, authorised supplier variation and customer scope change are
distinct. Buying more never establishes permission to charge the customer more.

## Proposed small scope and source ownership

Start design with one company-base supplier service/fixed hire, one or partially allocated Job scope,
one evidence-backed partial acceptance and one append-only correction. Retain source currency evidence
without inventing FX. No compulsory named worker or time quantity for a fixed deliverable.

Purchasing owns agreements/issued commitments and cancellation/short-close. The proposed service
acceptance owner records performance, accountable accepter and commercial basis without posting fake
stock receipts. Job Execution consumes supported accepted attribution; Finance later owns invoice
matching, liabilities/payment/tax/period policy. Final service-acceptance module ownership requires
review: prefer a reusable Purchasing-owned supplier-performance source over an isolated Job receipt.

Commitment, accepted quantity, recognised usage, provisional/unpriced valuation, billed amount and
paid amount remain distinct. Reports may show remaining commitment alongside actuals, but must not
sum overlapping stages as actual cost or promise a final forecast. Remaining exposure must state its
valuation/cancellation basis; no naive order-total-minus-invoices rule across changed terms/currencies.

Shared invoices/orders require bounded allocations and deterministic rounding that conserve source
quantity/amount across Jobs and unallocated scope. Equal dates/amounts are not source identity.
Reclassification cannot duplicate stock acquisition and direct service expense or erase old history.

## Dependencies, safeguards and deferred design

- Inventory/0148/0040 for stocked paths; service paths need their own correction participant and
  source reconciliation, not a blanket application of physical stock corrections.
- Exact policy/authority for procurement, acceptance, recognition, sensitive prices and both old/new
  Job scopes; assignment alone grants none. No new permissions or real-holder grants in research.
- Current-authorised retries, stable economic identities, partial allocation limits, compatible
  locks/transactions and versioned evidence across accept/correct/import/close writers.
- Job holds stop new ordinary work but do not erase legitimate supplier claims or prohibit governed
  recording of historical work. Late evidence requires occurrence/context review under0145.
- Closeout reconciles outstanding supplier performance, custody/off-hire, remaining commitments,
  disputes and applicable correction obligations; known follow-up needs an owner and supported path.
  Operational closeout never implies supplier final invoice/payment or accounting settlement.
- Finance integration must preserve document/base currency/FX and taxes with their owning policy.
  Do not finalise WIP/COGS, recoverability, retention, accrual journals or closed-period treatment here.
- Defer framework call-offs, complex rate escalation, retention/certificates, supplier-supplied
  materials ownership, automated equipment metering and full subcontract management pending need.

## Testable business questions for the next contract

1. Order10/receive6/use4: one material actual for4; receipt/PO/invoice cannot add another4 cost.
2. Accept12000.0000/invoice12000.0000: one effective contribution; invoice retry cannot duplicate it.
3. Partial invoice or correction across two Jobs: conserved source allocation and original history.
4. Unpriced accepted performance: visible incurred evidence/incomplete valuation, no fake zero.
5. Hire delivered but no chargeable-basis evidence: no invented days or automatic complete cost.
6. Stop/cancel/short-close races: customer, supplier and stock obligations remain independently owned.
7. Missing recognition/cost/scope duty: combined action refuses atomically, no hidden-price disclosure.
8. New authoritative invoice contradicts coverage: reassess0147 and reconcile prior source, no second actual.
9. Required downstream adapter unavailable: fail closed; no partial authoritative posting.

## Genuine owner decisions requested

Review P1–P6 direction, especially whether SIMPLE external direct-incurred entry is needed initially,
whether performance-based provisional recognition is preferred to invoice-only timing, and whether
Purchasing should own reusable service acceptance. Confirm fixed/day hire and simple deliverable
subcontracts as first candidates versus initially deferring either. These are business choices;
physical schema, API/grants and detailed recognition/correction contracts follow later review.

No new ADR for this workstream, implementation, migrations or grants. Q52/Q53 closure does not
approve P1–P6. This assessment is the next owner-review boundary; stop before technical design.

## Related

- [Phase 5 plan](phase-5-job-execution.md)
- [Purchasing](../domain/purchasing.md) · [Acquisition costing](../domain/acquisition-costing.md)
- [Labour](../domain/job-labour-recognition.md) · [Coverage](../domain/job-cost-coverage.md)
- [Materials](../domain/job-material-attribution.md) · [Lifecycle](../domain/job-execution-lifecycle.md)
