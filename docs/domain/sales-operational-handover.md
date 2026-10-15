[← Domain index](README.md)

# Sales operational handover

**2026-10-14 · ACCEPTED architecture (0131–0132 and 0136), SPECIFIED contracts; not IMPLEMENTED or VERIFIED.**
Accepted customer business feeds explicit operational allocations and stage-specific owning targets.
These coordinate goods/services/projects without becoming a second commercial value ledger.
No public surface or application implementation is authorised by this document.

## Where it sits

Issued quotation → R6 agreement → disjoint allocation → operational stage/target → owning actuals.
Order→Job→Delivery is a stage chain, not three sales. Quotation, agreement, initial baseline,
successor baselines and actuals remain five separate evidence layers.

## Schema — logical pseudo-DDL, not migrations

```
handover_plans
  id, company_id, acceptance_case_id, contracting_party_id
  business_identity, intent_fingerprint, generation, path_policy_version
  requested_grouping                       -- independent component or required atomic group
handover_allocations
  id, company_id, plan_id, accepted_component_id, original_scope_anchor
  source_kind, source_id, measure_id, quantity decimal
  current_cap, generation                  -- derived from effective agreement; never fresh sale
handover_stages
  id, company_id, allocation_id, stage_key, target_kind, target_purpose
  predecessor_stage_id nullable            -- no predecessor for initial stage
  target_id nullable                       -- null means pending, never target successfully created
  generation, state, current_baseline_id nullable
handover_baselines
  id, company_id, stage_id, version, predecessor_id nullable
  agreement_reference, source_versions, material_plan, measure/conversion_evidence
  cost_certainty, cost_scope, setup_allocation, controls, authority, fingerprint
handover_readiness_evidence
  id, company_id, stage_id, source/target_generation, prerequisite_rule_version
  missing/stale/unsupported_results, owning_domain, evidence_fingerprint
operational_schedule_versions
  id, company_id, allocation_id, version, contractual_constraints, internal_slots
  destination, quantity, dependencies, authority, supersedes_id nullable
handover_receipts
  id, company_id, request_key, intent_fingerprint, business_identity, original_result_ids
accepted_business_corrections / commercial_claim_releases
  id, company_id, original_acceptance_id, business_case/component_identity
  consent, authority, reason, agreed_delta, impact_generations, applied_effects, recorded_at
```

All references have tenant-safe composite foreign keys; business identities/keys unique within
company and appropriate purpose. Append-only baseline/schedule/correction evidence; mutable heads
carry generations, not replacement history. Quantities retain source precision/increments, never
add unlike goods/service measures. Monetary evidence, if stored, uses decimal(19,4), currencies,
pinned FX/base amounts and FinancialActivity registration. Target tables belong to their owning
module; Sales cannot mutate them directly. This logical shape permits implementation naming choices.

## Source-to-target adapter contract

| Source | Owning target and release facts | Initial boundary |
|---|---|---|
| Stock Item/package | Goods demand, optional managed Order then pick/delivery; real stock/package/UoM identity, eligible location, destination and current restrictions | Only delivered Inventory-aware adapter; availability never reservation; no unsupported negative-stock path |
| Service Item | Assignment/task, or Job when execution/cost tracking needs it; service scope, instructions, team, schedule/completion basis | No stock movement or mandatory Assembly/site; team can remain missing at acceptance |
| Full Assembly/project | Technical Job; pinned versions/material/control/cost evidence, applicable site/dependencies | Full supported scope first; no generic fractional technical/setup split |
| Manual scope | Explicit descriptor-based service/work adapter | Real Item mapping required for physical stock; changed identity/specification needs agreement; no automatic generic Job fallback |
| Mixed scope | Per-line targets plus dependencies and grouping contract | Within-acceptance compatible grouping only; independent components can release separately, required atomic group cannot partially succeed |

Each adapter declares supported source/path/version, measures/divisibility, target owner, prerequisites,
baseline transformation, completion event, target business identity, transaction/lock participation,
impact/cancel dispositions and writer inventory. Unknown adapter reports UNSUPPORTED, never a successful
empty target. Missing/denied dependency is not reported as absent. Public support requires the actual
Action, migration, grants and verification; a registered type or schema does not establish support.

## Behaviour and managed Order lifecycle

Specified operational states: PREPARING → RELEASED → PART_FULFILLED → FULFILLED → CLOSED.
HOLD records an independent blocking condition with reason and resume generation; it never erases
progress. Cancellation applies only to eligible unused work with owning-domain checks. Closing an
Order with remaining demand needs explicit disposition; it cannot pretend fulfilment occurred or
cancel accepted business. State names are implementation vocabulary, not newly granted duties.
Order owns pick/delivery instructions, scheduled demand, backorders and dependencies. Commercial
prices/quantities are references/projections of effective agreement; no independent Order sale editor.
Operational disablement preserves active/history access or refuses downgrade until safe reconciliation.

Preparation may report pending prerequisites. Configured approval binds exact baseline and release
facts; release rechecks facts, approvals and enabled policy. Internal slot changes within contractual
constraints are operational. Commercial destination/scope/deadline changes require new appropriate
agreement. Schedules, assignment and designated obligation completion are distinct facts. Dispatch,
customer delivery and installation completion cannot all be counted as completion of one obligation.

For each accepted component: active outstanding assigned quantity + completed allocated quantity
must fit the effective supported cap; disjoint branches sum once. Stages share root allocation.
Schedule outstanding demand fits its unfulfilled allocation; backorders retain undelivered demand,
never another acceptance. Cancel/reassign appends disposition before eligible capacity is reused.
Neither reassignment nor a new target ID resets fixed/setup scope, benefit budgets or family binding.

Original issued and accepted evidence stays immutable. Operational successors preserve versions,
source controls, certainty and exact authority. Setup60.0000 plus variable100.0000 for coverage100
can become supported planned60.0000+80.0000 for80, not128.0000; actual costs remain actual.
An execution substitution changing customer specification needs commercial agreement as well.

## Authorisation, Actions and API/UI boundaries

Distinct duties: source/target reads; prepare; configured review; release; schedule/hold/amend;
owning Inventory posting; Sales accepted correction; Sales claim release. R9 approves final names,
effective record/warehouse/site scope, registry/grants and holder assignments. No role-name inference.
Both Request and Action guard duty/company/scope plus independent legitimate source/target read.
Current cost read is independent from blind mandatory control evaluation; protect baselines, hidden
dependencies, receipts, counts, histories and downloads. Unsupported limited scope refuses safely.

Specified Actions (not existing classes): PreviewHandover, PrepareHandoverPlan, ReviewHandover via
shared approval engine when configured, ReleaseHandoverComponent, ReviseOperationalSchedule,
HoldOperationalTarget, CancelUnusedOperationalAllocation, PreviewAcceptedReduction,
ApplyAcceptedReduction. Claim-release execution remains deferred, with capture/read of pending intent
only when separately authorised. Corresponding Requests carry exact source/target/impact generations,
baseline/approval fingerprints and request key for retryable mutations. Previews never hold capacity.

Resources distinguish commercial accepted/effective/remaining from allocated/scheduled/fulfilled,
readiness and pending targets. Safe original receipt IDs are returned with current permitted status.
Suggested API operations are plan list/detail/preview/prepare, component release, schedule revision,
hold/cancel and reduction preview/apply; final routes/OpenAPI are implementation-owned. No PATCH
acceptance or generic target-table API. UI uses standard sheets, explicit confirmation and missing-fact
next actions; independent mixed outcomes cannot be labelled complete. Currency/quantity strings stay
server authoritative. UI never recomputes commercial totals or derives consent from fulfilment.

## Integrity and refusals

Check current authority before exact receipt/business replay, before mutable readiness checks;
changed key payload conflicts. Fresh keys resolve the same business intent/result. Genuine parallel
components need explicit disjoint lineage; ambiguous overlap requires review.
Lock Sales coordination fence and claim/accepted roots/allocation heads in canonical order, then
stages/targets and owning downstream locks. Integrate cancellation, schedule, fulfilment, correction,
replacement and all conflicting writers; re-audit real downstream lock order before enablement.
Unique-anchor insertion plus coordinator protection covers absent rows. Re-read caps, generations,
readiness and approvals under locks; required local owning Actions use the same transaction/connection,
no early commit or external mandatory side effect. Baseline, target, claims and receipt commit together.

Database enforces company references, identity uniqueness, immutable evidence and supported capacity
constraints including cross-row writer participation. Proposed stable refusal meanings: unsupported
adapter, insufficient allocation, stale generation/readiness/impact, held target, approval required,
intent conflict and unknown downstream impact. Implement reason_code names consistently with existing
API conventions, redact sensitive details and do not convert uniqueness races to HTTP500.

Bounded reduction: original100, delivered30, agreed80 → effective80 and remaining50 only after all
relevant impact ports affirm safe effects. Ports return SUPPORTED disposition, verified NONE, or
UNKNOWN/UNSUPPORTED with generation and owner. Reservations, production, subcontracting, invoices,
deposits and actual work may forbid this example. No module is not proof of NONE. Sales consent and
mandatory local effects apply atomically; pending external evidence is not a completed correction.
General returns/increases/repricing/full cancellation/family transfer remain disabled. Eligible unused
operational capacity release never automatically releases commercial/exclusive claims.

## Landmines and delivery gates

A fresh UUID is not a new business branch. One stage chain does not multiply quantities.
Stock observation is not reservation; target creation is not actual delivery or invoicing.
Target cancellation leaves commercial liability and exclusive claims intact. A queue dispatch cannot
prove durable target receipt; external protocols require separate approval. Implementation checks must
prove rollback after real target creation and concurrent writers, not mock-only happy paths.

R8 governs customer documents/evidence/files; R9 gates every public surface. Inventory/Phase5 own
reservation, production, work and actuals; Finance owns invoicing/payment/credits/statutory effects.
Required R5/R6 partial-family operations remain blocked until actual supported integrated adapters
are delivered and verified under separate code authority; architecture acceptance alone is insufficient.

## Related

- [0136](../decisions/0136-govern-source-appropriate-operational-handover.md)
- [0131](../decisions/0131-preserve-operational-baselines-and-handover-identity.md)
- [0132](../decisions/0132-govern-bounded-accepted-business-reductions-and-claim-release.md)
- [R7 delivery sequence and gates](../phases/phase-4-r7-implementation-slices.md)
- [Customer decisions](sales-customer-decisions.md) · [Quotation lifecycle](quotation-lifecycle.md)
- [Mixed journey](../phases/phase-4-r7-mixed-journey.md)
