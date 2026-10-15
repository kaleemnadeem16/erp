[← Phase index](README.md)

# Phase 5 labour — evidence and architectural verification

**2026-10-15 · Supporting PROPOSED L1–L6/Q51; no new accepted decision.**
Owner requirement: "The ERP must not force a company to fabricate worker identities, hours,
rates or other evidence it does not maintain." General research direction only was approved.
The revised bundle replaces worker/hour-only recommendations;0145 is unchanged.

## Questions and reuse boundary

Can completion, direct amounts, fixed units and timesheets share attribution while external
commitments/Finance retain ownership? What evidence prevents fabricated hours and duplicated cost?
Reuse prior resource/time/approval comparison for named usage. Fresh comparison addresses direct
expense sources, purchase commitments versus incurred cost, and project source reconciliation.

## Primary comparison — accessed2026-10-15

| ERP / primary page | Evidence | Convergence/difference and application |
|---|---|---|
| [Business Central project supplies](https://learn.microsoft.com/en-us/dynamics365/business-central/projects-how-manage-project-supplies) | Project-related services/expenses use purchases; direct one-off expenses can use project G/L journals. Outstanding orders and received-not-invoiced amounts are distinct | Multiple expense sources beyond timesheets; accounting journal machinery need not become our SIMPLE UI |
| [Business Central usage](https://learn.microsoft.com/en-us/dynamics365/business-central/projects-how-record-job-usage) | Usage and purchase documents are alternative source workflows; journals link planning/posted quantities | Common project accounting does not require every source to be worker-hours; our explicit lifecycle differs |
| [ERPNext Project](https://docs.frappe.io/erpnext/project) | Separately exposes timesheet costing, expense claims, purchase costs and consumed materials | Sources converge on project visibility but retain distinct entry documents; not permission to sum overlapping expenses blindly |
| [ERPNext expense claims](https://docs.frappe.io/erpnext/project-expense-claims) | Project-linked employee expense-claim workflow | Evidence that non-time project expense exists; employee-claim prerequisite is not universal to our direct declarations |
| [Odoo19 Timesheets](https://www.odoo.com/documentation/19.0/applications/services/timesheets.html) (retained prior research) | Employee-linked detailed time/validation | Optional detailed method, not justification to impose Employee on every cost source |

Fresh evidence from two mature ERPs suffices for non-time source distinction; retained Odoo time
research is not re-run ceremonially. Odoo profitability page open failed; no new decision depends
on its unavailable full text. Business Central pages returned an authorisation banner but actual
article content was readable; only inspected content supports findings. Vendor date/settings differ;
neither product proves a safe instance-specific Job adapter or our cost-completeness contract.
We do not copy automatic ERPNext start-from-timesheet, invoice semantics, accounting timing,
payroll rules or vendor project margin formulas. Our0145 start/hold/closure remains authoritative.

Convergence: project costs can originate in time, expenses and purchase/financial sources, not a
universal hourly record. Difference: BC journal/posting and ERPNext employee claims/invoice costs
have different ownership/recognition. Translation: one economic attribution boundary, typed evidence,
explicit source reconciliation and separate owning-domain adapters. Team-day precision/accountability
is our business requirement, not a vendor-backed promise of an existing generic team module.

## Repository inspection at9ac893d

Read-only code inspection, not runtime verification. Concurrent Sales authority/rollout files belong
to another agent; their dirty state is not delivered authority. No code or their handoff edited.

| Existing contract/code | Classification / reuse and limit |
|---|---|
| [0145](../decisions/0145-separate-job-lifecycle-execution-and-operational-closeout.md), [lifecycle](../domain/job-execution-lifecycle.md) | ACCEPTED architecture/SPECIFIED contract; required known records and supported incomplete-valuation follow-up, not universal timesheets |
| [R7](../domain/sales-operational-handover.md), [R9](../domain/sales-duty-matrix.md) | ACCEPTED allocation/authority; no new Job adapters proven. New Sales authority WIP is not verified Job capability |
| [ResourceRate](../../backend/app/Modules/Estimating/Models/ResourceRate.php), [resolver](../../backend/app/Modules/Estimating/Actions/ResolveResourceRate.php) | IMPLEMENTED class-rate/UoM/effective-date facts; latest<=date returns row/null, no Job adoption/expiry/actual policy |
| [DeleteResourceRate](../../backend/app/Modules/Estimating/Actions/DeleteResourceRate.php) | Inspected authorised delete lacks in-use retention check. Actual copied evidence must survive deletion; no unsolicited source change |
| [Items](../domain/items.md#resource-rates) | LABOUR/PLANT classes, rate6/base currency; non-stock, not workers/AVCO. Multiple rate units already documented |
| [UoM converter](../../backend/app/Support/UomConversionService.php) | IMPLEMENTED same-dimension conversion rounds to9 places. Cannot prove exact whole-second conversion or workday compatibility; no global fix authorised |
| [Precision](../domain/precision.md),0141–0144 | ACCEPTED rates6/money4/FX9 and exactness boundaries; worker-day semantics not automatically TIME-day |
| [Material cost](../domain/material-cost-resolution.md),0110 | ACCEPTED certainty/coverage; IMPLEMENTED stock read model, not labour valuation. Reuse semantics, no false Inventory reservation/cost source |
| [Sales FX](../../backend/app/Modules/Sales/Support/Fx/ResolveQuotationFx.php), [financial contract](../domain/quotation-financial-calculation.md) | Internal FX/arithmetic is IMPLEMENTED; quote-specific policy is not Job authority; no new Job currency adapter verified |
| [Accounting README](../../backend/app/Modules/Accounting/README.md), [GL](../domain/general-ledger.md) | Master accounts/period foundations exist; journal posting/supplier payroll/payment integration remain Phase6. Operational cost is not balanced financial entry |
| [Acquisition costing](../domain/acquisition-costing.md) | Documented estimate/actual/adjustment source conservation; useful analogy, not a labour invoice adapter |
| [Stock corrections](../domain/stock-recalculation.md),0040 | ACCEPTED append-only correction/economic-owner obligations; multi-owner Job correction package not delivered |
| [Historical costing](../domain/job-costing.md) | Historical schema is not authority for mandatory employee/hour actuals |

No complete Job/labour source, Worker master, direct-expense recognition, reservation or supplier
matching implementation was identified. Existing helpers are not VERIFIED future integrations.
This documentation is checked for links/numbering/whitespace, not application/runtime behaviour.

## Lifecycle reconciliation and remaining gates

Completion-only can satisfy0145 if optional actuals are not invented as requirements and supported
fulfilment evidence exists. Known pending/disputed/required actuals and unknown downstream impacts
still block appropriate operations. No recorded labour means unassessed coverage, not known zero.
Known valuation obligations need actual supported follow-up/owner before incomplete-cost closure.
If a future adapter requires named/quantity evidence, lighter entry cannot bypass it. No conflict
with accepted ADRs found; if detailed contract later exposes one, return it through owner, never
edit accepted ADR bodies. Retain Q51 AWAITING_OWNER; no implementation-originated question was
forwarded, and its original context remains in OPEN-QUESTIONS.

## Related

- [Revised L1–L6 proposal](phase-5-labour-resource-research.md) · [Phase5 plan](phase-5-job-execution.md)
- [Q51](../OPEN-QUESTIONS.md#q51--phase-5-labour-resource-usage-and-recognition)
- [Question reconciliation](../architecture/implementation-question-reconciliation.md)
