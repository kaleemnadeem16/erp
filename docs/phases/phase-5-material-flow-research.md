[← Phase index](README.md)

# Phase 5 — Material demand, custody, reservation, consumption and corrections

**2026-10-16 · Research-only / PROPOSED M1–M6; Q53 AWAITING_OWNER.**
Labour0146 and lifecycle0145 are accepted; these material recommendations are not. No material
contract/schema, Job stock adapter, grants, migrations or execution development authorised.
Q24 ownership/exception requirements and Q23 variable content remain unresolved in their own entries.

## Business question and preserved distinctions

An installer needs10 units, moves12 to site, uses9 and returns3. Demand10, custody12 and consumption9
are different facts. A reservation is a promise against eligible stock, not movement/consumption.
Actual stock cost comes from authoritative Inventory posting, never from the quote or advisory preview.
Commercial accepted scope, Job allocations, material plans and actual movements remain separate.

## First reconcile existing prerequisites and capabilities

| Evidence | Actual state / consequence |
|---|---|
| [ADR0052](../decisions/0052-progressive-inventory-package-tracking.md) | ACCEPTED three progressive modes; quantity/valuation ledger remains authoritative, packages add reconciled physical evidence |
| Grouped v1/0090–0094 | IMPLEMENTED per STATUS/code: activation, receipts/QA, issue/transfer, count, reversal. Not individual-container identity |
| [Issue/transfer contract](../domain/package-issue-operations.md) | Fresh predecessor/composition, exact posted allocation vector, atomic successor evidence. Grouped path refuses serial/SPECIFIC, shortages, held/QA-dependent history and backdating |
| QueryStockAvailability/Material Preview | IMPLEMENTED advisory observation/quantity-only proposals. No reservation; exact container/package proposal capability cannot be inferred |
| PostInventoryMovement/issuePosted | IMPLEMENTED sole posting boundary, actual allocations/valuation. PackageWriteScope prevents scalar bypass for tracked items |
|617fe3a precision/PX2 | Inventory representability work committed; current Purchasing PX3 WIP belongs to another agent. No integration verification claimed here |
| Container tracking | Accepted future mode, not delivered. Grouped loose balance cannot identify a particular remnant/vessel |
| Job reservation/consumption/correction adapters | Not delivered. Existing movement tests do not verify future Job locks/atomicity/permissions |
| Ownership | Current inspected stock model has company/item/location/batch/serial/source, no established customer-owned stock contract |
|0040 | Accepted multi-owner correction allocation; consumed-Job linkage still undelivered, not waived by Inventory completion |

Read-only inspection at b12acd8 with active Purchasing precision and Sales authority work. No runtime
tests run. Re-audit actual code and writer inventory at authorised implementation time.

The [roadmap](README.md#phase-5--job-execution) says CONTAINER_TRACKED, package-aware preview and
waste/residual bands are owed before material issue design. STATUS defers container tracking until
requested.0052 supports progressive modes but does not erase that scheduling requirement. Existing
grouped functionality is not evidence the whole prerequisite is met. Preserve both records pending
owner resolution; business research can proceed, technical material contract waits for M1.

## Primary comparison — accessed2026-10-16

Reuse prior R7 research for allocations/stock-availability separation. Fresh sources address custody,
reservation and ownership, not a re-investigation of settled commercial rules.

| ERP / primary sources | Convergence and differences |
|---|---|
| [ERPNext Stock Entry](https://docs.frappe.io/erpnext/stock-entry), [reservation](https://docs.frappe.io/erpnext/stock-reservation), [customer-provided items](https://docs.frappe.io/erpnext/customer-provided-items) | Distinguishes transfer, issue and manufacturing consumption; explicit reservation; separate customer-provided manufacturing path. Useful distinctions, not authority to require manufacturing for service work |
| [Dynamics consignment](https://learn.microsoft.com/en-us/dynamics365/supply-chain/inventory/consignment) | Physical receipt/owner and later ownership change are distinct; reservation can be ownership-aware. Corroborates custody is not title/cost; vendor consignment is not our customer-free-issue contract |

Convergence: requirement, reservation, physical location, ownership and consumption require distinct
facts. Divergence: production-led customer material versus ownership-change procurement packaging.
Translate under our Inventory/R7 ownership, not a universal BOM/order/production hierarchy. No source
establishes our missing adapter or says all stock on site is consumed. Odoo reservation index open
failed; no recommendation relies on unavailable full text. Existing source comparison suffices.

## M1 — Package prerequisites and smallest supported boundary

**Problem/example:** ordinary fixed packs can already issue in grouped form; a uniquely identified
partly used drum cannot be represented by that balance. **Options:** build all container features
before any material path; explicitly narrow the initial supported path; bypass tracked-state rules.

**Recommend:** ask owner to approve narrowing the sequencing prerequisite to supported QUANTITY_ONLY
and existing PACKAGE_BALANCE paths, preserving advanced obligations for container-dependent paths.
This is a proposed roadmap refinement, not an accomplished waiver. No bypass or downgrade of tracked
Items. Initial path would refuse container-dependent/variable-content requirements, unsupported
package operations and unsafe histories. Package-aware preview could follow if users select an
actual supported draw and posting revalidates evidence; no claim of exact preview recommendations.

**Trade-off:** smaller useful delivery but explicit restrictions. Keeping original broad gate is
valid if exact remnants/containers are essential first. **Defer:** keep gate unresolved; no detailed
material execution contract or code, while unrelated labour work continues.

## M2 — Demand and custody versus actual consumption

**Problem/example:** site delivery12 is not proof all12 were used. **Options:** charge every dispatch
as consumed; manage site custody then record use; direct consumption only where actual use is known.

**Recommend:** demand is a Job requirement (manual Item need or applicable pinned estimate adoption),
not another stock ledger. SIMPLE permits direct actual-use issue where that accurately describes
the event. When stock remains company-owned/on hand at site/vehicle/team, use supported Inventory
location transfer and later consumption. Returning unused custody stock is a transfer back, not
a second receipt/purchase or Job cost credit. No mandatory Assembly/production Work Order.

Custody quantities must reconcile across source/site; company stock in a vehicle/site cannot vanish
from on-hand just because assigned to a Job. Existing SIMPLE/STRUCTURED location rules constrain
this support; no unapproved per-Job virtual warehouse system. A target/location is not a new owner.
Plans can change under baseline/readiness authority; estimates/demand do not assert actual use.

**Trade-off:** site custody needs more recording; direct-use is simpler but inappropriate for staged
stock. **Defer:** support only known actual-use scenarios, not fake dispatch-as-consumption.

## M3 — Reservation promises and timing

**Problem/example:** two Jobs each see the same10 units. **Options:** advisory availability/recheck;
explicit reservation of eligible stock; reserve all accepted scope automatically.

**Recommend:** initial SIMPLE advisory availability and live posting checks, clearly 'not reserved'.
MANAGED explicit reservations are separate owning Inventory capability; not acceptance or preview.
No automatic reservation by release until an actual policy/adapter is accepted and delivered.
Real reservation must constrain every competing issue/transfer/count/QA/recalculation writer and
define partial reserve/fulfil/release/expiry/reassignment under locks; cannot live only in Job tables.
Reservation quantity and demand caps differ from commercial claims. Holding a Job does not silently
free reserved stock; cancellation/disposition must use supported owning effects, not a global toggle.

**Trade-off:** advisory first may lose stock to another Job; truthful shortage is preferable to false
promise. Managed reservations add priority/expiry/governance decisions. **Defer:** no reservation
claim; recheck stock and report shortages. Demand planning remains usable without guaranteed supply.

## M4 — Q24 ownership and exceptions

**Problem/example:** customer supplies tiles; company supplies adhesive. Customer tiles are physically
present but not automatically company-valued inventory or zero-cost purchased consumption.

| Case | Proposed initial treatment / dependency |
|---|---|
| Ordinary company-owned fixed-content stock | First supported material candidate, actual Inventory posting/valuation |
| Customer/free-issue | Explicit owner/custody/eligible-use/value treatment needed; separate Inventory ownership adapter, not zero-cost receipt workaround |
| Substitute Item | Equivalent technical substitution only under supported controls; changed customer specification needs Sales agreement |
| Unused company stock | Transfer back if still custody stock; previously consumed return needs an explicit correction/return contract |
| Recoverable scrap | New residual/condition/value facts, not automatic original-item stock or negative consumption |
| Reusable remnants | Grouped loose remainder only where aggregate suffices; exact vessel/remnant identity needs container capability |
| Returnable container | Container ownership/custody/return obligations distinct from contents; later adapter |
| Catch-weight/variable content | Q23 dual quantities; no fake fixed factor |

**Options:** every exception now; ordinary stock plus explicit unsupported paths; external material
only as non-stock note. **Recommend:** ordinary company stock first; preserve external-material facts
as clearly non-authoritative observations only where useful. No physical control/consumption claim
from a note. If customer material is essential, prioritise its real ownership adapter instead of
pretending it is included. Minimal scope does not permit ignoring known external custody obligations.

**Trade-off/defer:** smaller scope excludes some installers until ownership support exists. Q24
requires owner's exception priority, not blanket approval of this matrix. Do not close Q24 here.

## M5 — Actual valuation and0040 correction attribution

**Problem/example:**9 consumed units originally cost900.0000; authorised Inventory recalculation
later attributes90.0000 more to those same consumed units. Actual becomes990.0000, not another issue.

**Options:** keep original Job cost forever; recalculate Job from current prices; linked owner-driven
adjustment. **Recommend:** actual cost follows posted Inventory allocator/valuation evidence;
preserve move/source/layer or pool/valuation lineage and append authorised correction contribution.
No quote/QueryMaterialCost amount substituted for posting cost. Initial fully-backed supported paths
avoid introducing new negative-stock/provisional-cost exceptions; existing Inventory rules still govern.

0040 is an existing obligation, not a discretionary new valuation design: allocate approved delta
across remaining Inventory, consumed Jobs, Sales/COGS and other owners; Accounting represents it,
not another economic allocation. Preserve original/counterfactual figures, applicable historical FX,
approved package/version, period context and deterministic residuals. Local implemented participants
commit together; undelivered/asynchronous owners remain explicit durable obligations, never partial
internal success converted to a pending note. No closed-period reopen or closed-Job execution restart.

Do not enable a consumed-stock path while replay can silently misclassify it as remaining Inventory.
Either deliver its required Job correction participant/reconciliation under0040, or explicitly refuse
affected replay until a supported route exists, with visible obligation/owner. This is not permission
to weaken0040 or silently freeze unrelated Inventory scopes. Physical consumption errors/returns
need owning stock correction and economic reconciliation; valuation-only adjustments move no quantity.

**Trade-off/defer:** shared correction delivery is substantial but avoids false actual costs. Physical
use recording cannot be called fully integrated while its costing/correction link is missing.

## M6 — Authority, corrections and truthful closeout

**Problem/example:** a technician can report use but cannot necessarily post stock/cost. **Options:**
new broad Job material right; reuse independent source/Job/warehouse duties; duplicate Job inventory.
**Recommend:** Job demand/reporting authority distinct from Inventory posting, scope and valuation read.
Reuse RecordScope/owning Actions and approved review mechanisms; no new names/grants here. Capture
source actor, occurrence/recording times and move/Job lineage; Q16 still asks whether movement itself
also stamps actor, so no new endpoint may rely on an absent movement actor without source evidence.

Future contract must prove source/Job/stock/package/valuation/receipt atomicity on the real connection,
compatible Sales→Job→Inventory locks and business-idempotent retries. No direct internal poster call,
queued mandatory effect or scalar bypass. Actual consumption must survive hold/termination truthfully;
new ordinary use is blocked while governed historical reports/corrections remain visible.
Closeout reconciles custody/unused stock, supported reservations and remaining demand/obligations;
does not infer full financial cost or erase actuals. Materials cost coverage participates independently
in whole-Job completeness under0146. Source corrections adjust the same contribution once.

**Trade-off/defer:** more explicit integration than a Job-only table, but no duplicate stock authority.
No public material posting before real guard/adapter/writer/rollback/concurrency evidence exists.

## Examples and recommended sequence

- Trading100 delivered40/35/25: no mandatory Job; goods path and one commercial commitment.
- Installation need10, custody12, consumed9, unused3 returned: actual9; remaining requirement1 must
  be replanned/disposed, not hidden because12 were sent. Cost belongs to consumed9, custody remains stock.
- Painting: open a5L can, use3L, grouped2L remains loose; exact can identity is not claimed. Named
  returned container/remnant needs its own capability.
- Customer tiles/company adhesive: ownership/custody differs even when both support one installed area.
- Fabrication: issue/transfer/output/scrap/rework are specialised future production facts, not generic
  service activity completion or a reason to mandate manufacturing on all Jobs.

Owner review order: M1 prerequisite boundary; M2 actual-use/custody paths and Q24 priority (M4);
M3 reservation promise; M5 correction delivery gate; M6 source/authority evidence including Q16.
Recommend business approval before detailed technical contracts; smallest materials slice would use
ordinary company-owned supported stock, explicit actual-use issue, existing custody transfers where
needed, honest no-reservation status and real0040 attribution/correction safety. All remains proposed.

## Related

- [Q53](../OPEN-QUESTIONS.md#q53--phase-5-material-flow-and-package-prerequisites)
- [Q24](../OPEN-QUESTIONS.md#q24--phase-5-material-exceptions) · [Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages)
- [Phase5](phase-5-job-execution.md) · [Inventory](../../backend/app/Modules/Inventory/README.md)
- [Stock correction](../domain/stock-recalculation.md) · [Labour contract](../domain/job-labour-recognition.md)
