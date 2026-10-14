[← Phase index](README.md)

# R7 — Final H1–H8 operational handover decision review

**2026-10-14 · RESEARCH COMPLETE FOR OWNER REVIEW / PROPOSED / planning Q47 OPEN.**
This replaces the initial H1–H8 agenda at443f176 (the shared history now contains6bc1b85).
R6 remains ACCEPTED/SPECIFIED in0125–0127; no R7 choice, ADR, permission vocabulary or code is
accepted here. [Evidence and architecture inspection](phase-4-r7-evidence-and-integrity.md) and
[mixed business journey](phase-4-r7-mixed-journey.md) complete this consolidated review.

## Recommendation requiring owner approval

Keep R6 accepted business as commercial authority. Use a small shared allocation/lineage foundation
for suitable operational targets; do not add another universal commercial commitment ledger.
When enabled, **Sales Order is an optional managed operational aggregate**, with genuine picking,
delivery/backorder coordination and amendment state. A read-only order summary is a projection;
it cannot replace that aggregate once users release or change operational work. Job is another
source-appropriate aggregate, never mandatory for goods or simple services. A company can choose
a concise path without a separately entered Sales Order, only when its direct adapter safely owns
all required fulfilment controls. No direct path may bypass enabled mandatory fulfilment policy.

A managed Order's purpose is to coordinate released demand, pick/delivery instructions, backordered
remainder, dependencies and permitted operational amendments. Proposed states distinguish preparation,
released/held work, partial/full fulfilment and operational closure; none is another customer acceptance.
An Order cannot independently edit accepted prices/quantities. A lightweight business should not enter
the same agreement twice: prefill from accepted allocations, and disclose any extra managed step only
when its operational controls apply. Any official new target number uses central0079; a projection
needs no artificial new official document. Disabling Order workflow cannot abandon active demand,
reserved/picked work or its audit history; keep old work available or refuse downgrade until reconciled.

## Authoritative H1–H8 matrix — every recommendation remains proposed

| Bundle / existing authority | Exact choice, options and trade-offs | Practical example | Recommendation and rationale | Foundation now / safe deferral / dependencies |
|---|---|---|---|---|
| **H1 — Accepted business, Order and Job.**0104/0109/0125 separate consent from target/readiness. | A mandatory Order for every acceptance standardises processing but adds redundant steps for simple services. Direct source-appropriate targets are concise but need common capacity controls. A universal operational commitment aggregate standardises state but risks a second commercial ledger and a premature generic engine. Decide Order's role and required-path policy. | Stock distributor uses Order→pick/delivery/backorder; consultant may need one service assignment; installation needs Job. None repeats consent. | Accepted commitment stays authoritative; common allocation identities/caps only. Optional managed **Order aggregate** owns operational fulfilment coordination, not a second sale. Simple summaries are projections; enable Order only with real supported workflow. Direct paths remain subject to configured required Order policy. | Preserve accepted-source references, target type/stage and policy version now. No universal Order/Job/account/Assembly. Design real Order state now; implement picking/backorders only with Inventory adapters. Defer independent direct-order commercial entry and a universal target engine. R9 duties; Phase5 execution. |
| **H2 — Mapping/multiplicity.**0100 independent logical intent;0125/0127 quantities/options/root capacities. | One target only is easy but fails mixed/site work. Arbitrary many duplicates capacity. Choose explicit disjoint allocations and stage edges; decide initial consolidation scope. | Accepted40→two20 delivery assignments is a parallel split. Order40→production Job40→delivery40 are stages, never120 accepted. Goods100 and installation1 have different measures. | Support one acceptance with one/multiple source-appropriate target allocations when each adapter is delivered. Within-acceptance compatible lines may share a target with per-line lineage. Separate parallel branches consume their source capacity; stages inherit an existing allocation and cannot consume root anew. | Company/Party/source/measure, immutable allocation IDs, parent-stage links and path graph now. Direct Item→goods path; service→service work; Assembly→technical Job; manual→explicit supported descriptor adapter. No fake Item/Assembly. Defer cross-acceptance consolidation, target transfer and arbitrary split Assembly/fixed setup. R6 adapters and Phase5 target rules. |
| **H3 — Schedules/backorders.**0115 tier basis;0125 full100 commitment distinct from40 purchase. | New acceptance per delivery misstates price/quantity; schedule revisions preserve commercial scope. Decide what can change without new consent. |100 firm with40/60 deliveries remains100; first dispatch30 leaves70, including10 short on first batch. Only40 purchased cannot schedule100. | Schedules are versioned operational plans bounded by accepted allocation. Backorder carries undelivered authorised demand, not a new purchase or a second target claim. Routine slots within agreed constraints may change with operational authority; contractual location/deadline changes need customer agreement and commercial correction. | Store promise versus internal plan, destination scope, dependency and separate fulfilment links. Shipment, customer delivery and installation completion are distinct milestones with one designated completion rule per obligation. Defer transport optimisation/ATP/automated replenishment. R8 evidence, Inventory/Phase5 actuals, Finance billing. |
| **H4 — Readiness/duties.**0104/0125 acceptance independent from execution;0127 independent sensitive duties. | Universal checklist fabricates prerequisites; per-path checks add clarity but require adapters. Separate preparation, approval, release and amendment authority. | Goods lack warehouse/destination; service lacks responsible team/instructions; installation lacks site baseline. Consent already valid remains accepted. | Versioned path-specific readiness; preparation can remain blocked, release must revalidate required facts. Approvals only where governed policy requires them, not universal maker/checker. Operation preparation/release/amendment and downstream Inventory duties do not grant acceptance, price exception or claim release. | Readiness result lists missing/stale/unsupported facts, responsible domain and next action. At Request/Action enforce company, independent source/target read and effective duty/warehouse/site scope. R9 chooses names/grants; no role inference. Finance account/credit gates only at their applicable step. R8 output/evidence; Phase5 execution. |
| **H5 — Baselines.**0109/0110/0120/R5 immutable issued source/cost/financial evidence; material-resolution standard/planned/actual separation. | Current-master recopy destroys provenance; frozen quote-only prevents legitimate replanning; original accepted plus separately versioned execution baseline is richer. Decide permitted initial replanning/splits. | Original Assembly versions/materials frozen; different execution material mix requires own control and baseline. Goods need stock identity, not invented formulation. Setup60 plus variable100 is not proratable to128 for80. | Keep five layers: issue, acceptance/allocation, initial operational baseline, authorised execution changes, actuals. Carry full provenance; execution-only change cannot alter contract. Fixed/setup assigned once to a declared cost scope; no generic linear split. UNKNOWN/PARTIAL cost stays explicit. | Snapshot technical/version/measure/material plan, adoption/control evidence, certainty and initial baseline link. Revalidate execution use with applicable rules; never reuse source approval for changed facts. Stock query is availability, not reservation. Defer unsupported partial Assembly, substitutions/cross-job setup allocation and reservation until owning adapters/control rules delivered. |
| **H6 — Business identity/atomicity.**0014 modular monolith;0127 decision identity;R5 coordinator/receipts. | Key-only misses new-key duplicates. Synchronous local delivery is atomic but needs integrated lock order; asynchronous handoff needs durable intent, held capacity and idempotent receiver. Choose default after actual inspection. | Two employees hand over accepted40 using fresh keys: one target. Disjoint20+20 allowed;20+30 against40 cannot both commit. | Stable verified business handover plan/component, independent of employee/key/generation; one root allocation per planned parallel branch, stage-specific receiver identity. Default required local target creation to one PostgreSQL transaction using owning Actions/synchronous required events. Durable handoff only when genuine delay/external boundary requires it. | [Inspected code and race matrix](phase-4-r7-evidence-and-integrity.md) supports feasibility, not a delivered adapter. Lock shared root/claims before targets and integrate every release/cancel/fulfil writer; unique keys and database caps. Missing target port refuses. Defer external connectors/outbox implementation; if selected later, cannot mark target complete from dispatch alone. |
| **H7 — Amendments/cancellations.**0125/0127 immutable accepted facts;R5 withdrawal affects unaccepted scope only. | Mutable accepted quantity loses history; cancel-all erases delivered work. Choose staged governed amendment with source and downstream checks. | Original100, actual30, customer requests80. Request alone changes nothing. Agreed correction−20 produces current80, leaves actual30 and remaining50; original100 stays. | Sales owns agreement/correction evidence. Operations owns schedules/assignments and permissible remaining work; Inventory owns reservation/movements/returns; Finance owns invoices/credits/payment/statutory tax. Initially require hold, unstarted-target cancellation/reassignment and bounded agreed remaining reduction for fully supported paths; refuse execution if any required adapter missing. | Design correction intent/approval/consent and impact generations now. Authoritative commercial correction + mandatory local effects atomic; external effects coordinated under gated plan. Factual earlier agreement may be pending recognition, never invented later consent. Defer general variation editor, increases/repricing, delivered reversals/returns and complex multi-target changes until downstream contracts delivered. |
| **H8 — Claims/release.**0100/0125 option/family bindings;0127 no automatic reverse/reopen. | Automatic Job/Order cancellation release is unsafe; indefinite all-claims freeze prevents safe correction. Decide separate release duties and what reduction actually frees. | Cancel empty duplicate Job: operational assignment may be released, accepted40 and competing-family block stay. Full commercial cancellation may still have sunk costs/invoice obligations. | Sales separately governs commercial quantity adjustment and any explicit option/exclusive release after downstream checks. Reduction does not automatically restore old offered capacity/benefits, release family binding or reopen an offer. Retain historical claims and linked corrective events. | Distinguish assignment capacity, current commercial obligation, original acceptance consumption and exclusive binding. No claim transfer/mixed-family switch initially. Bounded reduction may leave family bound. Defer automatic full release/transfer until explicit consent, target/Inventory/Finance dispositions and R9 authority delivered. R5 new-offer eligibility remains independent. |

## Mapping and readiness by source — recommended, not enabled

| Source | Suitable initial mapping | Preparation versus release requirements / limits |
|---|---|---|
| Direct stocked Item/package | Goods fulfilment demand, optionally managed by Order, then picking/delivery | Preparation needs accepted source and destination intent; release needs actual scoped fulfilment location/destination, exact package/UoM/stock identity, current restrictions and delivered Inventory path. Reservation only if an applicable supported policy requires it. Stock shortage may permit planned backorder; it does not authorise unsupported negative-stock issue. |
| Service Item | Service assignment/task; Job only when technical/cost tracking warrants it | Clear agreed service scope, relevant instructions, responsible person/team, supported schedule and completion basis. Missing execution-only team/slot may block release but not valid acceptance. No stock move for a purely non-stock service. |
| Full Assembly/project work | Job with source-appropriate versioned technical/material/cost baseline | Site/contact, dependencies, accepted complete scope, applicable preparation/safety constraints and controls; source-specific cost completeness when needed. No arbitrary fractional Assembly or setup split. A Project groups work; its existence is not customer consent or execution release. |
| Manual commercial scope | Explicitly supported descriptor-based goods/service/task adapter | It may map to a service assignment without invented Item/Assembly. Physical stock work requires a separately validated real Inventory Item/measure mapping and commercial confirmation if identity/specification changes. Unsupported mapping remains pending; no generic automatic Job as fallback. |
| Mixed/independent scopes | Per-line suitable targets plus explicit dependencies | Same acceptance need not mean same work path; independent accepted scopes can share a compatible target with separate lineage. Required linked scopes cannot be declared independent to bypass missing installation readiness. |

Combining sources into one target requires same company and contracting Party, compatible destination/
service context, one coherent currency/terms presentation where the target carries commercial money,
source-specific measures and no hidden incompatible dependency/option. Preserve every acceptance line
and component allocation; never merge away evidence. Recommend within-acceptance grouping initially;
cross-acceptance consolidation is representable but disabled pending explicit compatibility rules.

Readiness records source/target generation, prerequisite rule version and evidence, not a forever-valid
boolean. Approval, when required, covers exact baseline/release facts; stale facts need re-evaluation
under0070/0085. Simple operation can have no extra reviewer, but always needs legitimate release duty.
Preparation, approval, release, operational amendment/hold, commercial correction and Inventory posting
are distinct duties which a properly authorised person may hold together; no mandatory separation
beyond governed policy. R9 must authorise registry/grants/effective source-and-target scope and protect
costs/evidence/hidden dependencies. A missing or denied dependency is not permission to show it as absent.

## First support versus deferred capability

Architecture now: typed accepted-scope→allocation→stage→target lineage, versioned readiness/baselines,
independent ledgers, business identity and coordinated mutations. Do not make generic targets own
technical semantics belonging to Inventory, service execution or Jobs.

Minimum safe operational delivery after owner approval and separate coding authority:

1. Preview/readiness and one accepted-intent allocation plan; no target, stock or finance side effect.
2. A supported goods fulfilment path with real Order/delivery demand state, or a supported service/
   full-Assembly target with its own baseline. Source type alone never proves adapter support.
3. Supported disjoint delivery schedules/assignments; same accepted scope is not re-sold. Mixed scope
   routes to suitable targets only when all required dependencies are delivered; otherwise show
   partially ready/pending target, never pretend the entire handover succeeded.
4. Operational hold and cancellation/reassignment of unstarted unconsumed allocation; plus bounded
   remaining commercial reduction only on paths with complete downstream impact checks. Unsupported
   correction remains captured/pending with applicable separately authorised hold; no silent release.

Cross-acceptance consolidation, arbitrary multi-stage graphs, accepted increases/repricing, cross-family
transfers, advanced late conflicts, delivered returns and complex partial technical splitting stay off.
They must be representable without enabling them. No R6/R7 partial-family gate is relaxed by this review.

## True conflict check against accepted authority

No new business proposal requires changing R1–R6 if kept within these boundaries.0109 already allows
non-Assembly scope;0104/0125 forbid universal readiness gates;0124/0125 require R6/R7 approved **and
delivered** before partial-family operations. Operational target creation does not bypass those gates.

0014 says module transition reactions use events; [code structure](../architecture/code-structure.md)
explicitly permits calling an owning Action for a required synchronous write. The recommended local
handover uses that distinction, or a synchronous required listener with an explicit result contract;
no direct cross-module table mutation or queued mandatory reaction. Its illustrative JobConfirmed
fan-out is not authority to reserve stock or post GL at acceptance. No ADR body needs silent editing.
A mandatory Job, mutable accepted quantity, auto-price recalculation, cross-company target or automatic
claim release would conflict with accepted authority and is rejected, not proposed as an exception.

## Exact review request and gates

Owner may approve/amend H1–H8 as a bundle. Explicit selections still needed: optional managed Order
aggregate versus universal Order; initial source/target and split support; no cross-acceptance
consolidation initially; path readiness/approval separation; two-baseline execution model; local atomic
default with bounded durable alternative; minimum supported reduction workflow; conservative separate
claim release. The recommendations above are **not accepted by completion of research**.

- **R5/R6:** issue/consent/allocations and all affected claims writers integrated. Remaining reoffer,
  partial Assembly and late reconciliation require their exact delivered adapters; schemas alone fail.
- **R8:** customer document/channel/delivery/withdrawal evidence, signatures and protected files.
  Physical dispatch is not proof of customer receipt; portal work is not a handover prerequisite.
- **R9:** independent prepare/approve/release/amend/hold/correct/reconcile duties, source/target/warehouse/
  site scope and sensitive projections; grants and registry before public surfaces. Reporting uses
  designated accepted/remaining/fulfilled sources;0101/0102 forecast trust/explicit valuation retained.
- **Phase5/Inventory:** actual service/installation/production, technical replan, stock reservation,
  movement/returns and costing; no generic PostInventoryMovement bypass for package-aware modes.
- **Finance:** account/credit conditions at applicable step, billing, credits, statutory tax, payments,
  realised FX and Sales/COGS correction ownership under0040. Quote estimates are not legal postings.

## Related

- [Evidence, architecture inspection and races](phase-4-r7-evidence-and-integrity.md)
- [Integrated mixed journey](phase-4-r7-mixed-journey.md)
- [Accepted R6](phase-4-r6-customer-decisions.md) · [R6 slices](phase-4-r6-implementation-slices.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Q47](../OPEN-QUESTIONS.md#q47--r7-handover-targets-and-commitment-corrections)
