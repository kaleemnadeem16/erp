[← Phase index](README.md)

# R7 — Handover and operational commitment: initial research

**2026-10-14 · RESEARCH STARTED / PROPOSED / planning Q47. No R7 ADR or code authority.**
[Accepted R6](phase-4-r6-customer-decisions.md) and R1–R5 are inputs, not questions to reopen.
Question: how does one real accepted goods/service/project commitment become suitable operational
work without duplicate commercial value, fabricated baselines or unauthorised claim release?

## Bounded primary comparison

Sources accessed2026-10-14. Two mature systems distinguish realistic alternatives sufficiently for
this initial review; implementation-specific target adapters still require a writer/dependency audit.

| Primary source | Observed approach | Project implication / limit |
|---|---|---|
| [ERPNext Sales Order](https://docs.frappe.io/erpnext/sales-order) | Confirmed customer request carries quantity, terms and dates; downstream delivery, billing and Project are separate paths. Submission itself is not delivery or invoicing. | A commercial commitment can feed goods or project execution. Do not copy mandatory Customer/Item setup into accepted prospect/manual scope or equate Order submission with our already-recorded R6 consent. |
| [ERPNext Delivery Note](https://docs.frappe.io/erpnext/delivery-note) | An order of10 can be delivered5 then5 in two Delivery Notes. | Delivery batches consume existing commitment; they do not create two customer decisions. Stock posting is a downstream Inventory-owned operation, not handover itself. |
| [Dynamics Sales orders](https://learn.microsoft.com/en-us/dynamics365/sales/create-edit-order-sales) | Orders cover products/services; conversion copies quote products, price list and currency. Orders can also originate without a quote; closed/partly fulfilled orders have update restrictions. | Preserve commercial provenance and downstream constraints. Do not import whole-quote-only mapping, editable price semantics or direct-order origin as newly accepted ERP behaviour. |
| [ERPNext order changes](https://docs.frappe.io/erpnext/sales-order#update-hold-close-amend-or-cancel) | Supported updates are restricted by picked/delivered/billed/production quantities; hold, close remainder, cancel and amend differ. | Corrections need downstream impact checks. Our immutable accepted consent cannot be edited just because a vendor supports updating an Order. |

Convergence: operational documents carry forward commercial scope and track remaining work.
Divergence: target creation, allowed edits and direct-entry routes differ. **Our synthesis is proposed:**
use accepted allocation as commercial truth, a separately governed handover plan as mapping, and
source-appropriate operational adapters. No second acceptance at Order confirmation.
These docs are product comparisons, not statutory cancellation authority or legal tax guidance.

## H1–H8 owner review agenda — recommendations are not accepted

| Bundle | Already accepted | Options and recommendation | Example / trade-off / remaining work |
|---|---|---|---|
| H1 — Commercial/operational boundary | R6 records real agreement before targets; no mandatory Job/account/Assembly. | Always Job; mandatory visible Sales Order; or common commitment with optional operational Order/Job views. Recommend common accepted commitment as truth, with a Sales Order operational view when useful and Job only for applicable work. Owner must choose whether Order is a separate mandatory aggregate or optional projection/workflow. | Distributor100 goods needs picking/delivery, consultant10hours needs service work, installer needs Job. Optional Order avoids duplicate entry; more adapter discipline needed. Do not create a second commercial value ledger. |
| H2 — Cardinality and mapping | Exact accepted line/logical/source/choice lineage and capacities immutable. | One target only is simple; unconstrained many duplicates work; recommend explicit allocation of disjoint accepted quantities to one/many suitable targets, with parent flow links for Order→Job. | Accepted40 mapped to20+20 across sites remains40; Order40 then Job40 is a stage, not80 consumed. Aggregating several acceptances into one target needs compatible Party/currency/terms and per-source lineage; recommend defer initial consolidation. |
| H3 — Schedules versus purchases |100 committed with40/60 delivery stays100 once. | Treat batches as sales or separate schedules. Recommend schedule allocations under existing commitment, distinct from target-assignment and fulfilment consumption ledgers. | Two dates never restore pricing tiers or emit new acceptance. Changes to contractual dates/sites require consent/correction where commercial; routine slots may be operational. Inventory reservation remains independently governed. |
| H4 — Readiness and duty | Valid consent may precede execution; commercial identity/scope/tax facts cannot be operational placeholders. | Universal checklist versus path-specific readiness. Recommend versioned goods/service/project prerequisite sets, visible missing facts, separate preparation and release authority. | Goods may need destination/warehouse, service a contact/time, site work access/baseline. No dummy Job/site/account. Finance credit/account readiness applies at the actual authorised step, not retrospectively to consent. Owner selects minimal first paths. |
| H5 — Baseline handover |0109/0110/R5 freeze source-appropriate technical/cost certainty and controls. | Copy current master; quote snapshot only; or original accepted baseline plus separately authorised execution baseline. Recommend the latter. | Assembly estimate copied with components/measures/versions, not current recomputation; direct goods need no invented formulation. Current stock availability is not promised reservation. Fixed/setup and partial technical allocations need declared adapters; unsupported split refuses. Phase5 owns actual consumption. |
| H6 — Duplicate prevention | R6 business decision identity and accepted quantity caps; R5 receipts/locks. | Request key only versus stable handover business intent and disjoint allocation. Recommend stable accepted-source/path/stage intent plus receipts and locked consumption. | Two staff/new keys creating the same40 target return existing target, not two40s. Genuine20+20 splits have separately confirmed plan components and cap40. Need cross-module atomic commit or durable intent/outbox with idempotent target adapter; choose after code dependency audit. |
| H7 — Amendments/cancellation | Original consent immutable, no quote withdrawal cancels accepted business. | Mutate original; blanket cancel-all; or append commercial correction plus explicit downstream impact plan. Recommend append correction with consent/authority, remaining execution limits and Finance/Inventory/Phase5 ownership. | Customer reduces100 to80 after30 delivered: original100 persists, correction−20 only if legitimate; delivered30 not erased, remaining50. Cancellation may leave charges/tax/returns requiring separate authority. Initial general corrections can remain disabled. |
| H8 — Claim release | Accepted choice/exclusive binding cannot be toggled; normal competitor is blocked, not rejected. | Automatic target-cancel release versus separate commercial release. Recommend Sales owns accepted-business claim release, only after target/stock/Finance effects validated through explicit adapters. | Cancelling a duplicate empty Job releases only its operational allocation, not the customer's accepted40 or family claim. Even zero remaining quantity need not permit switching family/option: policy and correction authority must decide. Reopening offer is a separate R5 decision. |

## Proposed minimal path and progressive operation

Start with preview/readiness against immutable R6 acceptance and a deliberately narrow supported
target adapter. Preparation creates no sale, stock movement, invoice or automatic release authority.
A simple business could use one operational view/target per accepted intent; managed operation adds
explicit disjoint splits and readiness/review; advanced consolidation, multi-stage orchestration and
cross-target corrections require coherent adapters. These are proposed packaging choices, not newly
accepted mandatory SIMPLE/MANAGED/ADVANCED configuration or permission names.

Design mapping and separate ledgers now: commercial accepted/adjusted scope, operational assignment,
scheduled quantity, actual fulfilment. Do not sum their counts as additional revenue. A parent Order
with child Jobs requires stage-aware conservation, not a blanket sum of all descendants.
Preserve R4 issue/agreed FX as history; R9 forecast valuation and Finance invoice rates are separate.
Operational material substitutions/replanning need applicable controls and their own versioned
baseline; they cannot rewrite customer scope, quote cost or original source evidence.

## Next research steps and owner choices

1. Settle H1 aggregate/Order visibility and first goods/service/project paths, then H2 parent/child
   stage semantics and permitted splits. This resolves schema ownership before Action design.
2. Audit existing module interfaces/readiness/writers without editing implementation-owned code;
   define exact accepted-scope→target payload, transactional boundary and target receipt identity.
3. Specify H4/H5 path readiness and baseline matrix, including partial Assembly/fixed/setup exclusions.
4. Review H7/H8 with Inventory/Phase5/Finance ownership: hold, reduce, cancel, reassign and claim release
   are separate transitions. No R6 partial-family enablement until required contracts delivered.
5. Prepare consolidated business examples/alternatives for approval; only then write governing R7 ADRs.

R8 still owns delivery/signature/channel evidence; R9 owns duties/scope/reporting/forecast and grant
recipes. Current research approves none of them. Forwarded implementation questions retain exact
Origin: IMPLEMENTATION AGENT and coding-owned verification under the accepted coordination workflow.

## Related

- [R6 domain](../domain/sales-customer-decisions.md) · [R6 slices](phase-4-r6-implementation-slices.md)
- [Job costing boundary](../domain/job-costing.md) · [Material resolution](../domain/material-resolution.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md)
- [Q47](../OPEN-QUESTIONS.md#q47--r7-handover-targets-and-commitment-corrections)
