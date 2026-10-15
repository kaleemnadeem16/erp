[← Phase index](README.md)

# R7 — Primary evidence, architecture inspection and integrity

**2026-10-14 · Supporting accepted H1–H8/0131–0132 and 0136; Q47 closed.**
Protocol proposals below are historical inspection evidence, not delivered/verified adapters.
Current [domain](../domain/sales-operational-handover.md) and [slices](phase-4-r7-implementation-slices.md) govern the handoff.
[Consolidated review](phase-4-r7-handover-research.md) is the single decision matrix. This evidence
record does not register an Action/permission or verify runtime behaviour.

## Bounded mature-ERP comparison

Primary sources accessed2026-10-14; existing R5/R6 research reused. Two relevant mature products
suffice to distinguish the options. Odoo current official service/backorder pages returned tool
errors; no architectural claim relies on those unavailable pages or third-party substitutes.

| Source | Observed pattern | What it supports / what we do not copy |
|---|---|---|
| [ERPNext Sales Order](https://docs.frappe.io/erpnext/sales-order) | Confirmed request feeds picking, delivery, project and billing; delivery and billing progress are separate. Updates/close/cancel/amend depend on downstream state. | Operational coordination has purpose. Our consent remains R6 authority; a vendor's mutable Order or required Customer/Item does not authorise rewriting accepted terms or fabricating masters. |
| [ERPNext Delivery Note](https://docs.frappe.io/erpnext/delivery-note) | One order can produce partial deliveries; submitting delivery affects stock and pending order quantities. | Batch delivery is distinct from commitment. We retain Inventory's movement/package boundary and do not copy editable delivery pricing as new customer consent. |
| [ERPNext Project](https://docs.frappe.io/erpnext/project) | Project tracks tasks/costing and may relate to a Sales Order. | Jobs/projects are suitable for applicable services/projects, not compulsory goods targets. Our source/initial/revised/actual baselines need explicit contracts beyond vendor links. |
| [ERPNext Stock Reservation](https://docs.frappe.io/erpnext/stock-reservation) | Reservation is an explicit stock-allocation workflow. | Availability is not a promised hold. This is evidence for separation, not permission to add our missing reservation engine. |
| [Dynamics Sales Order](https://learn.microsoft.com/en-us/dynamics365/sales/create-edit-order-sales) | Products/services can enter an Order from a quote or directly; quote conversion copies commercial context. Partly fulfilled/closed Order updates are restricted. | Preserve provenance and commitment/fulfilment limits. Do not import quote-wide conversion, automatic win, live target repricing or direct-order commercial entry. |

Convergence: carry source provenance, track fulfilment separately and constrain later changes.
Divergence: universal order-first packaging, target creation and editing rules vary. Recommended ERP
translation is accepted authority plus optional operational Order/Job adapters and common allocations,
not copying either product's document lifecycle or tax consequences.

## Actual repository boundary inspection

Inspected current shared HEAD6bc1b85 on2026-10-14, read-only. Rewritten prior planning commit identities
are treated as repository history, not repaired. No tests, code, migrations or implementation handoffs
were modified. Source paths below are evidence of implementation shape, not a claim of verified safety.

| Inspected evidence | Finding / consequence |
|---|---|
| [0014](../decisions/0014-modular-monolith.md), [code structure](../architecture/code-structure.md), [integrity standards](../architecture/domain-integrity.md) | One Laravel modular monolith; owning Actions handle writes. Required synchronous cross-module calls are allowed; state-transition reactions use events, synchronous when atomic effects are required. PostgreSQL transactions can span modules using the same connection. |
| [Sales README](../../backend/app/Modules/Sales/README.md), [CreateOpportunity](../../backend/app/Modules/Sales/Actions/CreateOpportunity.php) | Sales is CRM; creation uses DB::transaction and stable Lead lock/conversion identity. No quotation/acceptance/Order/Job handover implementation exists in this module. This existing conversion key is not the future R7 business identity contract. |
| [PostGoodsReceipt](../../backend/app/Modules/Purchasing/Actions/PostGoodsReceipt.php) | Purchasing locks receipt/order/lines, calls Inventory movement and package-evidence Actions inside its transaction, then finalises the receipt. Concrete evidence that required local cross-module writes can be synchronous; it is not an available Sales delivery adapter. |
| [PostInventoryMovement](../../backend/app/Modules/Inventory/Actions/PostInventoryMovement.php), [IssuePoster](../../backend/app/Modules/Inventory/Actions/Movements/IssuePoster.php) | Sole movement boundary with its own transaction; issue posting recognises source_type/source_id and takes stock locks. Internal IssuePoster must not be called externally. PackageWriteScope refuses unsupported scalar paths. Reuse owning boundary through a duly authorised target adapter, not arbitrary Sales stock writes. |
| [QueryStockAvailability](../../backend/app/Modules/Inventory/Actions/QueryStockAvailability.php) | Read-only scoped physical availability, no reservation/lock/posting promise. Readiness may report an observation but release/post must use current Inventory rules. |
| [Database config](../../backend/config/database.php), [queue config](../../backend/config/queue.php) | Configurable connections include PostgreSQL and isolated testing; source defaults alone do not prove active runtime connection. Queue configurations have after_commit false. An ordinary queued notification/listener is neither transactional target delivery nor a durable business outbox. |
| Module/file inventory and bounded search of backend/app for outbox/queued listeners/connection overrides | No Projects/Production modules or SalesOrder/Job/Delivery/Reservation/Handover target classes found; no business outbox located. Future same-connection Actions are feasible, not inspected because they do not exist. Re-audit target writers/connections when delivered. No external-system atomicity promised. |

No global event, queue or database configuration correction is authorised. Default queue behaviour
is a constraint for future adapters, not a defect fixed here. Never expose secrets/environment values
as proof. Actual transaction/rollback/concurrency verification belongs to the implementation agent.
The bounded source search found no module-specific connection override in backend/app; deployment
connection/connection-sharing must still be proven when a real adapter is implemented.

## Proposed local atomic protocol — feasibility, not implementation

A handover plan/component is stable shared business identity for an accepted source and deliberate
operational branch. A transport request key or newly generated UUID cannot certify a second branch.
Repeated attempt must resolve the existing plan; ambiguous same-scope intent requires review.
A stage receiver identity is (company, allocation component, path stage, target purpose), with one
live target per planned stage. A genuine parallel20/20 needs two explicit components and a total40
cap. Do not use quantity alone as uniqueness: two genuine20 assignments must remain possible.

1. At Request and Action require the applicable operational duty plus independent legitimate source/
   target read, company and effective record/warehouse/site scope. Downstream Actions require their
   own applicable authority; no acceptance/issue duty grants release or stock posting. Current source
   cost read remains independent from blind required evaluation.
2. Current-authorised exact receipt/business replay returns original target IDs under safe current
   projection before mutable readiness/generation checks. Same business intent with fresh key finds
   original result; changed payload under a key or incompatible business intent refuses.
3. Lock R5/R6 Sales coordination fence, commercial claim/accepted root/allocation heads in canonical
   order, then target/stage heads, followed by target-specific operational/Inventory locks only when
   actually required. All amendments, target cancellation, splits and fulfilment writers must join
   compatible generation/lock protocol; implementation must reconcile their existing order before
   enablement. No lock acquired in reverse via listener or target callback.
4. Re-read accepted current obligation, consumed/remaining stage capacities, customer/terms/source,
   baseline/readiness, policy and approval fingerprints. Hold/unsupported adapter blocks new release.
   Create missing anchors through unique-key/coordinator protection; no lock-on-absence shortcut.
5. Append allocation/baseline evidence and create required local target through owning Action or a
   synchronous required listener with an explicit registered handler/result. Same DB connection,
   outer transaction and no external side effects/early commits. Validate required target result;
   a missing listener cannot be a successful zero-work handover. Write target identity/claims/receipt
   together. Failure of any mandatory component rolls back all of that command, not half a target.
6. Each command states whether it releases one independent ready component or an explicitly atomic
   group. Mixed handover may show one released component and another pending; UI must not label all
   work released. An all-or-nothing group cannot commit a goods target if required installation fails.
7. Required state changes are not queued. Optional emails/exports can be after-commit reactions with
   delivery status/retry appropriate to R8; a sent notification is not proof of target/customer receipt.

Nested DB::transaction can participate in an outer transaction only when connection/commit semantics
match; source inspection does not prove a future target's participation. Prove failure-after-target,
receiver exception, missing handler and concurrent second-writer cases before declaring delivery safe.
No target is created merely by acceptance, and handover preparation never posts material or GL.

## Durable boundary if genuine delay/external delivery is later selected

Initial recommendation is local atomic support only. For a later remote/long-running adapter,
append durable intent plus **held operational capacity** and dispatch record atomically. Pending
held capacity is not yet a completed target or customer acceptance. Receiver enforces stable business
identity plus payload digest and returns durable target receipt; duplicate dispatch is expected.
Mark complete only on verified receiver result. afterCommit alone has a crash gap and is not an outbox.

Retry uses same intent/version, never creates a new purchase/branch. Failed/unknown target outcome
keeps capacity held until reconciliation proves target status. Cancellation races require fence/version
or tombstone recognised by receiver; do not free capacity while an in-flight message can still create
a target. Delivered external work may need compensation with owning-domain authority; no generic
rollback across systems. Mandatory externally pending effects prevent final claim release or successful
correction completion. No external/outbox engine is designed or authorised for implementation here.

## Downstream impact and correction ownership

| Owner | Authoritative change / refusal responsibility |
|---|---|
| Sales | Real customer agreement, exact agreed quantity/value/conditions, source lineage and separately governed commercial correction/claim release. New price/tax/fixed charge needs real consent and applicable authority; operational editor cannot grant it. |
| Operational target / Phase5 | Draft/hold/release, schedules, unconsumed reassignment, initial/revised technical baseline, work already committed or completed and subcontract/dependency impacts. Cancelling a target does not erase accepted business. |
| Inventory | Physical custody, reservations if supported, picks/dispatch/movements, package/batch/serial/QA constraints, returns and valuation. Releasing an assignment is not unreserving stock or posting a return.0040 backdated impacts retain multi-owner Sales/COGS obligations. |
| Finance | Billing/credit/payment exposure, statutory tax, realised FX, settlement and posted corrections. Commercial delta or quotation tax estimate is not an automatic credit note. |

Each impact result identifies source generation and either known supported disposition, verified NONE,
or UNKNOWN/UNSUPPORTED; absence of a module/port is never zero exposure. A narrow initial deployment
may supply a typed NONE only from a proven closed-world writer inventory with relevant writers,
imports and external paths disabled; adding a writer invalidates that proof before activation.
Do not invent posted history, CustomerAccount, reserve balance or zero financial liabilities.

For a bounded reduction, authoritative effects require exact consent, current source/correction duty,
approval where policy requires, and sufficient remaining compatible scope. Actual dispatch, delivered
quantity, work in progress, purchases/subcontracts, reservations, invoicing or deposits may constrain
reduction differently; no universal "delivered is the only floor". If original customer agreement
has already changed outside ERP but effects cannot be reconciled, preserve true occurrence/evidence
as pending governed recognition; do not assert the old agreement is the only real-world truth.

Initial ordinary operational cancellation/reassignment is limited to proven unconsumed work with no
incompatible downstream effects and leaves accepted obligations intact. Bounded customer-agreed
remaining reduction is recommended only with all mandatory local effects atomic/validated and no
unresolved mandatory remote effect. General increases/repricing, delivered returns, option/family
transfers and complete release remain disabled until their own contracts/adapters exist.

For full commercial claim release, verify explicit Sales authority/consent, exact original option/
exclusive anchors and current generation, no competing accepted conflict, settled target assignment/
work dispositions, Inventory holds/custody impacts and Finance liabilities/corrections. Append a
release event; never delete original claim. Reduction alone does not unbind the exclusive family,
restore original entitlement budgets or re-activate an old quote. Transfer must not pass through an
unlocked intermediate state; it is initially unsupported. New offer eligibility is separately R5.

## Concurrency examples and required future verification

| Race / retry | Proposed invariant / outcome |
|---|---|
| Same40 plan, two actors/new request keys | One stage target and one root allocation; matching loser returns existing result under current access. A new key does not create another40. |
| Two declared20 branches against40 | Both may succeed serially within cap. Competing20+30 cannot both win; loser refreshes, no silent shrink. Same quantity does not alone prove duplicate. |
| Order40 then Job40 then delivery40 | Same allocation/stage lineage. One40 commercial/root allocation; per-stage40 limits. No root consumption at every stage and no delivered count from Job creation. |
| Two shipments of20 then duplicate first receipt | Each physical source has its own stable fulfilment identity; repeated first event adds0, not20. Inventory movement identity alone does not prove customer delivery; milestone adapter decides. |
| Handover versus accepted reduction100→80 | Shared root/generation serialises; release based on stale100 refuses or reduction rechecks target effects. No current obligations below incompatible fulfilled/protected quantity without owning correction. |
| Unstarted target cancellation versus release/fulfilment | Target generation/fence decides first; cancellation releases only demonstrably unconsumed operational allocation. If release/actual wins, cancellation must re-evaluate/refuse, not erase actuals. |
| Cancel/reassign one20 branch; retry old handover | Old receipt returns cancelled target/status; never silently recreate. New explicit replacement intent may allocate released20 under current cap, preserving prior stage tombstone. |
| Commercial reduction versus competing family acceptance | Reduction does not release exclusive binding. Separate authorised claim-release transition needed; competition cannot win during unresolved downstream correction. |
| Remote create succeeds, response lost, then cancel | Receiver identity/tombstone and reconciliation mandatory; do not release pending capacity from timeout and dispatch a second target. Deferred remote adapter. |

## Related

- [Final H1–H8 matrix](phase-4-r7-handover-research.md) · [Mixed journey](phase-4-r7-mixed-journey.md)
- [Customer decisions](../domain/sales-customer-decisions.md) · [R5 contract](../domain/quotation-lifecycle.md)
- [Material resolution](../domain/material-resolution.md) · [Inventory README](../../backend/app/Modules/Inventory/README.md)
- [Question workflow](../architecture/implementation-question-reconciliation.md)
