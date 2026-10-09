[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1-C2 — Financial customer readiness at business events

- **Status:** initial research/recommendation for owner discussion; no accepted C2 decision.
- **Access/review date:** 2026-10-09.
- **Question:** when must CustomerAccount exist, and when must separate financial/commercial
  clearance authorise the next operation? Account existence is not the same as clearance.
- **Gate:** documentation only. Phase 4 owns offer/acceptance/handover; payment processing,
  invoices/posting and Phase 5 execution remain outside its implementation scope.

## What we have today

Reuse the [C1 verified foundation](phase-4-r1-c1-prospect-quotations.md#what-exists-today), rechecked
against `CreateCustomerAccount`, `StoreCustomerAccountRequest`, `AccountTermsAccess`,
`AccountMappingAccess`, `ArchiveParty` and `PartiesServiceProvider` on this date:

- Party identity is company-scoped; customer and supplier roles are independent. Account creation
  locks the same Party and requires account maintenance, terms management and mapping management.
  Payment terms and receivable mapping remain non-null; customer code is also required.
- Credit limit is optional configuration, not an implemented exposure/credit approval engine.
  `credit_limit` references in Parties concern storage, access and validation; the module README
  defers enforcement to Phase 6. Neither a null limit nor an account row is proof of clearance.
- Mapping existence/company are guarded, but ASSET account-type enforcement remains a Phase 6
  deferral. Tax setup is reserved; non-null customer tax codes are currently refused. Consequently
  today's account creation cannot certify every future posting requirement.
- Soft-delete archive is distinct from ARCHIVED status. Party archive rejects non-deleted roles;
  no public restoration workflow or generic restricted-customer/credit-hold state is implemented.
- Shared Approvals, Numbering, audit, company financial-activity locks and scope/field guards exist.
  No customer acceptance Action, domain job confirmation, advance-payment processing, invoice or
  customer ledger is implemented. Future monetary modules register their financial-activity probes.
- [Job costing](../domain/job-costing.md) and [Receivables](../domain/receivables.md) describe planned
  jobs, contract baselines, customer receipts and invoice allocation. Their customer keys are
  historical design context, not evidence that the mandatory handover gate has been accepted.
  Accepted ADR 0011 fixes accounting principles; it does not select this onboarding event.

[ADR 0103](../decisions/0103-issue-quotations-to-party-backed-prospects.md) now permits eligible
Party-backed issue without CustomerAccount. It does not settle acceptance, jobs or payments.

## What we are discussing and why

Recording that a buyer accepted an offer is different from authorising our business to spend money,
start work, extend credit or receive/post a payment. Blocking acceptance entry because Finance is
still working hides a real commercial event; allowing work solely because acceptance exists can
create exposure that the business has not approved. We need visible facts and separate gates.

A configured account answers who carries customer financial activity and its defaults. Readiness
also asks whether the specific act is permitted now: correct mappings/currency/tax, current account
and Party eligibility, current commercial terms, credit/deposit requirements and actor authority.
Do not call every incomplete financial step a failed customer acceptance or treat Finance refusal
as automatic cancellation of an existing commercial commitment.

## Practical example

ABC Industries accepts the issued PKR `500000.0000` offer on Friday. Finance has not yet configured
its customer role. Recommend recording the acceptance against the issued revision and Party, with
financial setup visibly pending and no automatic job release or payment collection.

Sales can prepare a handover package. For the proposed initial confirmation gate, Finance creates
CustomerAccount on the same Party and the required commercial/credit/deposit checks pass before
confirmed handover. If a 30% deposit is required, PKR `150000.0000` collection/financial processing
also needs the account and its transaction-specific readiness. Deposit evidence must come from
Finance/payment records, not a salesperson ticking a paid checkbox. Readiness is checked again
when the actual next action occurs; onboarding alone does not authorise execution.

If Finance delays/rejects setup, preserve acceptance and show the blocker with owner/reason and
next action. Disposition, customer communication or cancellation is a governed R5/R6/R7 decision;
no automatic job or fabricated account. This example is proposed business direction, not runtime.

## Primary-source comparison

Three products; source access 2026-10-09. Their confirmed orders combine responsibilities that our
acceptance evidence and job handover may deliberately separate.

| Product / primary sources | Observed behaviour | Project consequence / limitation |
|---|---|---|
| **ERPNext** — [Sales Order](https://docs.frappe.io/erpnext/sales-order), [Credit Limit](https://docs.frappe.io/erpnext/credit-limit), [Payment Entry](https://docs.frappe.io/erpnext/payment-entry) | Confirmed orders require a Customer; configured credit limits can block order/invoice submission. Payment Entry records customer advances and can leave allocation for later, while creating ledger entries | Supports account/credit checks before exposure and financial processing, not an invoice-only gate. A submitted order is not proof a real-world acceptance must be hidden before onboarding. Do not copy its zero-credit-limit fallback semantics |
| **Dynamics 365** — [prospect-to-cash](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/data-entities/prospects-in-prospect-to-cash-use), [sales-order credit holds](https://learn.microsoft.com/en-us/dynamics365/finance/accounts-receivable/cm-sales-order-credit-holds) | Integrated prospect quotation can convert to customer at create-order; credit blocking rules separately hold orders and govern release | Account creation and credit release are distinct. Do not equate automatic conversion with our protected financial setup or import a manager-role bypass; our raw customer acceptance remains a separate evidence event |
| **Odoo 19** — [quotation source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/sales_quotations/create_quotations.rst), [down-payment source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/invoicing/down_payment.rst) | Confirmed quotations become sales orders. Down-payment workflow creates an invoice from the confirmed order, then payment registration; down-payment invoices differ from instalment payment terms | Advances can bring accounting requirements before delivery/final billing. Shared contact identity differs from our CustomerAccount; manuals do not prove our desired readiness gate or universal native credit-release checks |

Convergence: confirmed operational commerce and money processing need customer-related controls;
advances may precede delivery. Divergence: conversion timing, credit enforcement, invoicing of
advances and how much the order combines. No source establishes a universal gate for every industry
or proves that merely recording external acceptance must create an account. Recommend project-specific
separation; no legal/accounting posting treatment is selected here. Phase 6 must verify current
accounting/tax requirements when designing advances, receipts and postings.

## Alternatives and consequences

| Option | Advantage | Limitation |
|---|---|---|
| A — mandatory at acceptance | Easy downstream guarantee | Can hide/delay genuine acceptance; encourages false completion or dummy fields; account still does not prove credit clearance |
| B — mandatory at confirmed handover | Controls entry into operational commitment; acceptance remains visible | Can delay jobs even where a company would allow work; an earlier payment act still needs a separate gate |
| C — mandatory only at first financial transaction | Minimal early onboarding | Too late if work/dispatch or credit exposure occurs first; “request payment” and actual processing must be distinguished |
| D — checks specific to each business operation | Distinguishes evidence, work authority and money; accommodates credit and prepayment companies | Needs explicit boundaries and shared checks; unrestricted early configuration could become an unnecessary workflow engine |

**Recommend D, with a small initial rule set using B as the normal confirmed-handover boundary.**
This is a recommendation, not an accepted C2 rule. No arbitrary configurable gate engine initially.

| Business act | Recommended initial gate |
|---|---|
| Draft/issue | Accepted ADR 0103 recipient rules and ordinary commercial checks; no financial account solely for issue |
| Record genuine customer acceptance | Valid issued offer/eligible identity and authoritative acceptance evidence; no CustomerAccount solely to record it. Show readiness separately; R6 decides authoritative acceptance vs inbound evidence needing review |
| Prepare handover / draft job planning | May prepare without account; no release of material, labour, credit or payment authority implied. Exact draft-job capability belongs to R7 |
| Confirm job/handover authorising downstream work | Require active eligible CustomerAccount plus operational and applicable commercial/financial clearance. Account alone is insufficient; no execution authority inferred from Phase 4 handover |
| Non-financial advance request/pro-forma communication | May describe agreed deposit before account if genuinely non-posting; channel/legal/content requirements remain R4/R8/Phase 6. A request that creates an invoice or initiates payment processing is a financial act instead |
| Initiate collection, record/allocate customer advance, issue invoice, extend customer credit or post customer financial activity | Require CustomerAccount and readiness appropriate to the act, even if this precedes job confirmation. Exact postings, tax, exposure and payment policy are Phase 6 decisions |

Later governed company policy may impose earlier credit/deposit approval before confirmed handover
or work release, and distinguish cash/prepaid/credit transactions. Whether to allow confirmed
handover/work before account for selected businesses needs a later explicit decision, not a hidden
bypass in this proposal. Reuse Approvals where needed, with exact duties/subjects defined in R9.
Financial requirements at money-processing events must not be made optional by a Sales preset.

## Required scenarios and safeguards

- **Acceptance before onboarding:** preserve the accepted scope/event; account pending is not
  zero accepted business. R6 determines validity/authority and R9 reports accepted, readiness and
  blocked handover separately. No optimistic job release from an acceptance notification.
- **Finance delay/refusal:** preserve facts/reasons and issued terms. Hold the dependent act;
  remediation or commercial disposition requires its own authority/evidence, never silent rejection.
- **Already a supplier:** attach CustomerAccount to the same Party. Supplier payable mapping is
  not a customer receivable mapping or credit approval; roles and sensitive fields remain independent.
- **Advance payment:** establish financial readiness before intended collection/processing. An
  unsolicited bank receipt cannot be erased; Phase 6 must design controlled unidentified-receipt/
  reconciliation handling. Do not invent an unconfigured customer ledger, fake account or netting rule.
- **Cash customer:** no credit facility does not remove customer identity/mapping or receipt/tax
  controls. Current payment terms allow zero days; null credit limit means no limit enforced in
  configuration, not a permission to give credit. Do not invent special bypass accounts or share
  anonymous customer identity for these Party-specific offers.
- **Company requires credit before work:** show/guard clearance separately from account existence;
  recheck when exposure is authorised. Stored credit limits do not implement utilisation or approval.
- **Archived/restricted after issue:** preserve issue/acceptance evidence and inspect current
  eligibility at future acts. Restriction types are not implemented. Recording incoming evidence
  can differ from confirming new acceptance; archive/refusal/restore/correction rules remain C4/R5/R6.
- **Several accepted quotations:** one CustomerAccount per company/Party, not per quote or job.
  Clearance/exposure may span all scopes; an approval for one quote is not blanket authority for
  another. Partial/overlap/group and job multiplicity remain R2/R6/R7.

## Technical implications

Preserve ADRs 0034/0084/0081, independent roles and account field validation. Reuse existing guarded
Party Actions; no auto-conversion, Sales-created dummy terms/mappings or new Finance permission
implied. Operation authority, scope, account existence and readiness evidence are distinct checks.
Successful checks at an earlier stage are not permanent permission to act later.

Use current tenant/Party/account links, snapshot applicable commercial/policy/approval evidence,
and coordinate concurrent acceptance, account setup/archive, policy/credit changes and handover.
Request plus Action guards, stable locks/version checks, atomic writes and idempotent retries
remain required; failed readiness cannot create successful handover or payment evidence. Do not
select schemas/flags or a scheduler here. Financial fields stay protected in readiness responses:
a salesperson may need a blocker/action explanation without viewing sensitive mappings/credit.

## Remaining decisions and smallest next question

**First discuss:** may valid customer acceptance be recorded before CustomerAccount exists, with
financial readiness separately pending and no automatic authority for work or payments? Recommend yes.

Then discuss the proposed default: account required at confirmed handover or an earlier financial
act, versus allowing account-free confirmed work under selected company policy. These are separate
business choices; accepting the first must not imply acceptance of the second.

R7 defines draft planning vs confirmed handover vs execution release; Phase 5 owns actual work.
R6 decides acceptance validity, inbound evidence and commercial disposition; C3/C4 own identities,
archive/restoration and duplicates; R4/Phase 6 own tax/cash/advances/posting and credit exposure;
R9 owns capabilities, policy ownership, holds/release, reporting and approval evidence. Exact payer
vs contracting Party and third-party payments also remain open. No C2 decision/ADR is accepted.
Stop after these recommendations; do not implement or begin C3/C4.

## Related

- [R1-C1](phase-4-r1-c1-prospect-quotations.md) · [ADR 0103](../decisions/0103-issue-quotations-to-party-backed-prospects.md)
- [R1](phase-4-r1-leads-and-relationships.md) · [Phase 4](phase-4-sales-quotations-and-handover.md)
- [Parties](../domain/parties.md) · [Job costing](../domain/job-costing.md) · [Receivables](../domain/receivables.md)
- [ADR 0011](../decisions/0011-double-entry-open-item-ar.md) · [ADR 0084](../decisions/0084-separate-financial-master-data-fields.md)
- [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries) · [Research rules](../architecture/research-and-design.md)
