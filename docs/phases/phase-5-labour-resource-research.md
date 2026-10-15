[← Phase index](README.md)

# Phase 5 — Labour, resource usage, progress and actual-cost recognition

**2026-10-15 · PROPOSED research bundle; Q51 AWAITING_OWNER. Origin: PLANNING RESEARCH.**
Lifecycle0145 is ACCEPTED; none of L1–L6 below is approved, implemented or verified. No payroll,
attendance, invoicing, general equipment system or application implementation is authorised.

## Business question and example

Who did the work, how much occurred, who confirms it, what cost evidence applies, and what
does it fulfil? One fixed-scope service visit estimated at two hours takes three actual hours.
Those hours must become attributable usage/cost without three hours implying 150% fulfilment,
changing the accepted price or hiding work while review/rates are pending.

## Existing foundation — reuse, not implicit actual-rate authority

| Evidence inspected at local f09b2a9,2026-10-15 | State / implication |
|---|---|
| Inventory Item LABOUR/PLANT | IMPLEMENTED resource classes, not employee/worker identities; not stock valuation sources |
| Estimating ResourceRate / ResolveResourceRate | IMPLEMENTED dated class rates, decimal(19,6), base currency; latest effective_from <= date, missing returns null |
| DeleteResourceRate | IMPLEMENTED authorised deletion; inspected Action has no in-use retention check. Future actual evidence cannot depend only on its live row |
| Assembly resource quantities/UoM checks | IMPLEMENTED estimating roll-up; technical plan, not proof of actual hours/cost |
| QueryMaterialCost /0110 | ACCEPTED certainty and IMPLEMENTED material read model; advisory stock costing, not a labour valuation port |
| Sales FinancialResult/kernel and FR1 | IMPLEMENTED internal arithmetic/certainty/FX helpers, no Job integration; existing non-negative result shapes cannot blindly represent signed corrections |
| User/company membership / RecordScope / Approvals | IMPLEMENTED identity/access/review foundations; a login is not an employee record or operational entitlement |
| Job/timesheet/employee/recognition ledger | No such owning implementation identified; no runtime verification in this research |

Source paths: [ResourceRate](../../backend/app/Modules/Estimating/Models/ResourceRate.php),
[resolver](../../backend/app/Modules/Estimating/Actions/ResolveResourceRate.php),
[delete Action](../../backend/app/Modules/Estimating/Actions/DeleteResourceRate.php),
[resource contract](../domain/items.md#resource-rates), [cost certainty](../domain/material-cost-resolution.md).
Do not modify these owners' current code or claim generic Employee functionality from User/Party.

## Primary comparison — accessed2026-10-15

| Product / primary source | Relevant evidence and project translation |
|---|---|
| [ERPNext Timesheets](https://docs.frappe.io/erpnext/timesheets), [Activity Cost](https://docs.frappe.io/erpnext/activity-cost) | Employee/time logs link tasks/projects; costing and billing rates differ; employee/activity rates inform cost. Supports worker/class distinction; editable rates are not our immutable actual-evidence policy |
| [Business Central time sheets](https://learn.microsoft.com/en-us/dynamics365/business-central/projects-how-use-time-sheets), [setup](https://learn.microsoft.com/en-us/dynamics365/business-central/projects-setup-projects) | Resource usage supports submission/review then journal posting; partial posting leaves remainder. Supports separating recording, review and recognition; we need not copy manual journals or accounting dependency |
| [Odoo19 Timesheets](https://www.odoo.com/documentation/19.0/applications/services/timesheets.html) | Employee-linked time capture/validation and task/project association. Supports explicit worker identity; we need not require an HR suite or copy broad all-timesheet edit permissions |

Convergence: identify worker/resource, time, task/project and cost basis independently of selling.
Differences: employee versus broader resource packaging, rate specificity, review/posting boundaries.
These establish alternatives, not approved project policy. Existing ERPNext project progress research
already distinguishes manual/task/weighted progress;0145 settles that these alone are not fulfilment.
No reliance on third-party modules, payroll law or unavailable Odoo profitability/resource-setup pages.

## L1 — Worker identity versus resource class

**Problem/example:** a technician without a login performs three hours of electrician work.
We need truthful identity without requiring a payroll rollout or a shared generic login.

**Options:** User-only (cheap, excludes non-users/misstates credentials); Employee/HR-first
(rich but broad dependency); minimal company-scoped worker identity distinct from LABOUR class
(extra narrow master, supports on-behalf entry).

**Recommend:** minimal named worker identity, optionally linked to existing User/Party identity
where appropriate without fabricating customer/supplier accounts. LABOUR Item remains class of
work and costing context. Do not create a second general Party directory or infer permissions
from a link. New entries require eligible worker/work date; deactivation preserves history and
controlled late entries. Initial employee/contractor distinction is descriptive, not payroll or
subcontract accounting. Crew plans may be generic; ordinary actual person-hours require attribution.
Generic crew totals are not named-worker evidence; explicit crew actuals remain deferred.

**Trade-off:** modest extra setup buys stable non-login identity; named detail costs more than
anonymous crew entry. Exact canonical worker ownership/link uniqueness needs approved L1 then
contract design. **Defer:** no safe first-worker assignment/on-behalf actuals; classes can still plan.

## L2 — Recording, review and recognition

**Problem/example:** three hours recorded Friday await review Monday; hiding them understates
exposure, but recognising every report trusts errors and work reported during holds.

**Options:** recognise on recording (fast, weak review); manual review plus separate manual posting
(controlled, lag/admin); recorded view plus recognition once applicable review is satisfied.

**Recommend:** third option. Show recorded quantities and separately labelled provisional cost
where supported. Recognised usage follows accepted review/authority; recognition and unified
cost attribution commit once, no second mandatory manual posting button. Cost can remain UNKNOWN
for recognised quantity; pending/rejected/exceptional reports remain visible, not ordinary recognised
actuals. Never add provisional and recognised views of the same source together.

SIMPLE ordinary usage: explicit submission/confirmation by an authorised recorder, no universal
independent reviewer; auto-recognition only when no review is required and all guards pass.
MANAGED uses existing NONE/POLICY/REQUIRED approval machinery, exact source version/fingerprint.
Hold/closed corrections remain sensitive reviewed paths; ordinary auto-recognition cannot bypass
0145. Review confirms usage, not price, payroll or customer consent. A worker with entry duty alone
cannot become a recogniser by assignment. Recognised unknown valuation carries a tracked obligation.

**Trade-off:** avoids duplicated posting steps, but default self-confirmation is a real business
choice; managed review adds lag, disclosed in pending exposure. **Defer:** recording may be researched,
but no authoritative labour actual/closeout source can be specified.

## L3 — Actual rate basis and pinned evidence

**Problem/example:** the quote used PKR500.000000/hour; actual three hours use an authorised
class cost PKR600.000000/hour. Cost is PKR1800.0000, not the estimate PKR1000.0000 or selling price.

**Options:** automatic current estimating rate (easy, conflates purpose/history); dedicated actual
class/person rates (clear but duplicates a rate catalogue if indiscriminate); governed actual-rate
adoption using existing class-rate facts plus immutable actual evidence.

**Recommend:** class-based operational cost first, explicitly labelled class-costed rather than
payroll-final. An authorised adoption pins applicable class rate, work-date basis and validity
for actual costing. Reuse ResolveResourceRate as candidate evidence, not automatic authority.
Copy rate/UoM/currency/effective/source facts and adoption authority into actual-rate evidence;
later estimating edits/deletion cannot change it. No silent fallback from absent actual adoption
to estimate, selling rate, last unrelated rate or zero. Class initially; person overrides later
only if required by owner, with explicit precedence/conflict rules before enablement.

Provisional costing uses the candidate evidence available when reported and labels its basis.
Recognition uses the rate applicable to occurrence date, preserving any difference through explicit
evidence; later master changes do not silently recost. New backdated actual-rate adoption creates
controlled valuation adjustments, not history rewrite. Split work crossing rate-effective boundaries.
Keep rate precision6, money4 and exact arithmetic/named rounding under0141; labour duration scale
is L4, not stock4 by analogy. Start with company-base costs, no FX requirement invented; broader
currency inputs need approved pinned FX and later Finance constraints.

**Trade-off:** adoption is one explicit authority boundary, not a parallel arbitrary rate engine.
Class costs are useful but do not claim person-specific wages/overhead/payroll settlement.
**Defer:** quantity tracking can proceed; complete labour valuation cannot.

## L4 — Usage measures, progress and fulfilment

**Problem/example:** two people work three hours each on one visit; usage is six person-hours,
not three elapsed hours or six visits. Installation survey/install/test also share one obligation.

**Options:** percentages/hours drive completion (simple, false fulfilment); manual status plus
separate outcome evidence (simple, accountable); full measurement/milestone engine (heavy).

**Recommend:** named activity usage in explicit UoM, actual work date and manual duration first;
optional interval evidence for historical holds, no stopwatch/attendance system required.
Recommend canonical whole seconds with exact rational hour conversion for costing; manual
hours/minutes must resolve exactly to that supported duration. One minute is not silently
rounded to0.016667 hours merely because resource rates have six places. Sub-second capture
is deferred. Preserve submitted duration/basis; no billing-increment rounding or stock-scale
truncation. Final entry bounds and arithmetic adapters need specification after owner review.
Per-person quantities add; equipment hours/crew elapsed time do not silently combine with them.
Require enough occurrence context to distinguish hold/pre-hold or rate-date boundaries; ambiguous
daily entries require clarification rather than guessed timestamp/authority.

SIMPLE uses activity checklist/outcome evidence and an authorised fulfilment confirmation defined
by supported service adapter. Optional progress estimate is informational; 100% or hours alone
cannot complete. No automatic weighted roll-up across unlike units. Person-time overlap, where
intervals are supplied, must be detected/refused or resolved through an explicit allocation, never
silently double-booked; duration-only capture cannot claim overlap validation/capacity management.

**Trade-off:** modest capture burden preserves truth; full capacity, crew/scanner and manufactured
output measurement remain later. **Defer:** lifecycle remains accepted, but actual duration and
first completion evidence inputs remain unspecified.

## L5 — Corrections, late work and backdating

**Problem/example:** a recognised entry should be2.5 hours, not3; a missing rate arrives after
operational closeout. Those are quantity correction and valuation correction, not restart.

**Options:** edit history (cheap, loses evidence); blanket reject late facts (hides work); linked
corrections with explicit effective/recorded times and dependent impact reconciliation.

**Recommend:** linked correction. Draft edits are versioned; submitted changes invalidate relied-on
review. Recognised quantity corrections append reversal/replacement or exact linked deltas under
one stable source chain; rate-only adjustments have no new usage quantity. Original and successor
remain inspectable. Resubmission/retry/reviewer races cannot duplicate hours/costs.
Operational occurrence date differs from ERP recording/recognition and later Finance posting date.
Historical assignment/hold/rate/adoption context must be evidenced, not inferred from current masters.
Unknown context goes to review. Closed/held exceptions use0145 paths; an affected completion/allocation
requires owning reconciliation. Unsafe changes refuse applied effects while preserving reported facts.

No arbitrary fiscal posting floor or payroll correction rules invented. Missing accounting integration
cannot assert a closed-period adjustment is safe; supported future source-cost adjustment path/owner
must actually exist before incomplete-cost closeout is enabled. Do not freeze all ordinary work for
an unrelated unsupported correction. **Trade-off:** more lineage, reliable audit. **Defer:** recognition
cannot safely ship without a bounded correction policy.

## L6 — Authority and one contribution to unified actuals

**Problem/example:** a supervisor logs on behalf of a technician; another reviews it; a later
recognition event must not add the same three hours/cost again.

**Options:** broad Job-manage right (simple, unsafe); operation-specific eligibility over shared
guards and review engine (more specification); new parallel approval/cost subsystem (duplication).

**Recommend:** operation-specific eligibility for own/on-behalf recording, submission/recognition,
review, rate adoption, sensitive corrections and independent cost read. Reuse RecordScope and shared
Approvals with exact subject; do not grant by role name or worker/Job assignment. Exact names and
rollout are specified only after approval. No blanket cost disclosure from labour-entry rights.
Explicit own-worker validation may narrow submission but cannot replace tenant/Job/activity scope.

Stable source identity/revision/recognition event contributes once to Job actuals; activity totals
are projections, not extra costs. Status movement consumes/replaces a provisional view, never adds
it again. Correction quantities/value reconcile the same chain. Payroll/invoices must not later
add the same labour cost without a separately reconciled replacement/adjustment contract.
This conservation requirement is already lifecycle authority; exact recognition mechanism remains
proposed. Money/rate visibility and valuation certainty are independent from usage recognition.
**Trade-off:** explicit authority inventory, no duplicate system. **Defer:** no safe public labour surface.

## Practical view and boundaries

Example (proposed L2/L3): plan2h; recorded3h pending review → provisional exposure1800.0000 where
rate supported, recognised0h. After review → recognised3h/1800.0000, pending0h, not total3600.0000.
For two recognised entries,3h at600 plus2h unpriced:5h recognised, known1800.0000, PARTIAL with
complete total absent and2h valuation obligation. Known zero requires explicit supported evidence.
A genuine correction of3h→2.5h at600 yields net1500.0000 and net2.5h, preserving original and delta.
Cost recognition is operational costing, not payroll expense, invoice, customer acceptance or GL.

No material/reservation, equipment lifecycle, subcontract accounting or unified all-source schema
is designed here. Preserve Q24/package discrepancy,0040 source-cost correction lineage and0137
shared printed documents. Reuse six-place rates; do not extend non-negative FinancialResult to
signed adjustments by accident. No production tests, migrations, grants or code changes.

## Owner decisions and next step

Review L1 narrow worker identity, L2 recognition/review default, L3 class-cost adoption and later
person-rate boundary, L4 duration/progress capture, L5 correction policy and L6 operation authority.
Approval of lifecycle0145 does not approve these. After owner answers: bounded domain contract,
exact duty/scope/review matrix, precision/recognition-source protocol and acceptance scenarios.
No accepted labour ADR or implementation-ready test suite is created before that review.

## Related

- [Phase 5 plan](phase-5-job-execution.md) · [Q51](../OPEN-QUESTIONS.md#q51--phase-5-labour-resource-usage-and-recognition)
- [Lifecycle](../domain/job-execution-lifecycle.md) · [Historical costing](../domain/job-costing.md)
- [Items/rates](../domain/items.md#resource-rates) · [Approvals](../domain/approvals.md)
- [Precision](../domain/precision.md) · [R9](../domain/sales-duty-matrix.md)
