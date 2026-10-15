[← Domain index](README.md)

# Sales customer decisions

**2026-10-14 · ACCEPTED architecture, SPECIFIED contracts; NOT IMPLEMENTED or VERIFIED.**
Governing ADRs: [0125](../decisions/0125-govern-customer-acceptance-and-explicit-partial-consent.md)
(C1/C2/C3/C6/C8), [0126](../decisions/0126-conserve-acceptance-entitlements-and-allocation-evidence.md)
(C4), [0127](../decisions/0127-record-customer-decision-evidence-and-atomic-business-claims.md)
(C5/C7). This complements [R5](quotation-lifecycle.md); its historical win/job sketch is not authority.

## Model and invariants

Each distinct consent component records one authoritative acceptance; a later genuine partial purchase
uses another verified linked consent component in the shared case, not reuse of an exhausted component.
The immutable acceptance is a commercial commitment against exact issued scope and consent, not a
mutable flag on a quotation, an Order/Job, stock reservation, invoice or accounting revenue.
FULL_ONLY applies per independently acceptable logical scope; optional independent scope need not
be accepted together. DIVISIBLE is offered explicitly, only for a delivered supported adapter.
Package, offered, priced, stock and technical quantities retain pinned conversions, increments and
minimums. An independent Item and a fractional bespoke Assembly are not equivalent capabilities.

Whole acceptance consumes the selected scope in full; later independent scope may still be accepted.
Unchanged firm offers use original agreed evidence, not live pricing/FX/cost. Explicit continuing
conditions and current lawful scope/claim eligibility still apply. Smaller purchase without explicit
partial-price entitlement is a request requiring new issue and fresh consent, not an acceptance.
No acceptance variation editor or document-discount input. Historical true agreement follows0127.

## Offered entitlement and company evidence policy

R5 issue must freeze per-scope acceptance policy: FULL_ONLY or supported DIVISIBLE, minimum/
increment and measure conversions, price/benefit entitlement, choice/dependency conditions and the
exact tax/allocation support. Absence of explicit partial entitlement is not permission to split.
Unimplemented adapters cannot be activated by configuration. An already issued offer is never made
fractionally divisible by changing company defaults; a changed offer uses R5 reoffer and consent.

Company evidence policy is versioned, company-scoped and records permitted source kinds and required
verification facts for those sources. Source policy supports legitimate verbal/PO/email practice;
it cannot waive consent, identity, scope or claims, create a universal second approver, or grant a
recording duty. Pin the policy/version applied to each observation/acceptance; policy changes do not
rewrite accepted evidence. Policy configuration needs its own R9 duty and Request/Action guards;
no default unrestricted late recognition. Initial safe configuration may support only implemented
manual source kinds; portal source is enabled only with its R8 adapter. Governed changes/corrections
carry reason, actor and time. A source kind alone is never proof of customer authority.

## Persistence contract — annotated pseudo-DDL

All business tables carry company_id, tenant-safe composite foreign keys and created_at. IDs below
are stable identities, not external PO strings. Types/physical names are implementation choices;
these invariants are not. Money decimal(19,4), pinned currency/rate and base amount on monetary rows;
quantity precision/conversion follows accepted source measure contracts, never binary float.

```text
customer_evidence_policy_versions
  id, company_id, version, source_requirements, actor_id, reason, recorded_at
  UNIQUE(company_id, version); immutable used versions, separate current-policy pointer
customer_decision_cases
  id, company_id, party_id, intake_identity, generation
  UNIQUE(company_id, intake_identity); UNIQUE(company_id, id, party_id)
customer_decision_components
  id, company_id, case_id, intent_identity, original_scope_anchor, generation
  UNIQUE(company_id, case_id, intent_identity)
customer_decision_evidence                  -- append-only version chain
  id, company_id, case_id, predecessor_id?, source_kind, external_reference?, source_version?
  customer_representative_snapshot, authority_verification_basis, recorder_id
  occurred_at_or_interval, occurrence_precision, source_timezone, recorded_at
  protected_object_id?, content_digest?, factual_description, observation_state
  UNIQUE(company_id, predecessor_id) WHERE predecessor_id IS NOT NULL
customer_acceptances                        -- append-only authoritative fact
  id, company_id, decision_component_id, issued_revision_id, consent_evidence_id
  consent_terms_digest, scope_selection_digest, policy_version_id, decision_sequence
  actor_id, decided_at, recorded_at, occurrence_evidence_id
  UNIQUE(company_id, decision_component_id) -- one authoritative consent component
customer_acceptance_lines
  id, company_id, acceptance_id, issued_scope_id, original_scope_anchor
  selected_option_id?, source_snapshot_id, quantity, uom_id, conversion_snapshot
  agreed_net, output_tax, gross, currency_id, fx_evidence_id, base_amounts
  CHECK(quantity > 0); immutable agreement and component snapshots
customer_scope_claim_heads                  -- authoritative locked counters/projection
  company_id, anchor_kind, anchor_id, generation, capacity, consumed, selected_identity?
  UNIQUE(company_id, anchor_kind, anchor_id); CHECK(0 <= consumed AND consumed <= capacity)
customer_claim_events                       -- append-only, no DELETE release
  company_id, acceptance_id, claim_head_id, sequence, quantity_delta, predecessor_id?
  UNIQUE(company_id, claim_head_id, sequence); tenant-safe links
customer_entitlement_consumptions
  company_id, acceptance_line_id, original_component_id, budget_lineage_id, serial_sequence
  policy_version_id, cumulative_quantity, cumulative_entitlement, consumed_amount
  currency_id, fx_evidence_id, computed_base_amount, allocated_base_contribution
  UNIQUE(company_id, budget_lineage_id, serial_sequence, original_component_id)
customer_decision_receipts
  company_id, actor_id, action, request_key, intent_digest, result_identity
  UNIQUE(company_id, actor_id, action, request_key)
```

Evidence observations and dispositions are new records/events, not updates of original evidence.
Use typed links or checked discriminator/FKs; a polymorphic string without enforced tenant/source
integrity is insufficient. Issued scope must belong to revision/family/Party and original lineage.
Enforce one selected option/family per claim anchor with database uniqueness and locked state.
Use append-only guards for evidence, acceptance, components, events and receipts. Mutable heads are
rebuildable projections; Actions plus constrained counters and coordinated writes enforce cross-row
conservation. A CHECK alone cannot enforce sums across rows: restricted mutation paths/deferred
constraint validation must reject uncoordinated inserts or updates in the implementation.

Budgets distinguish historical allocation, entitled consumption and new commercial/assessed nodes.
For explicitly proportionate entitlements use pinned E(cumulative q) minus prior consumed E(q).
Check each cap, consented rounded amount and deterministic residual order; no independent slice
rounding. Do not make fixed tax proportional by default. Finance legal assessment remains separate.

## Action contracts

| Action | Inputs and authoritative result | Refusal / evidence |
|---|---|---|
| OpenCustomerDecisionCase | Party, verified shared intake identity/reference context, request key; returns shared case | Ambiguous duplicate identity stays pending for review; no auto-merge or purchase by UUID |
| AppendCustomerDecisionEvidence | Case/version expected head, source, representative/authority, occurrence precision/zone, protected evidence, key | Append exact factual source/version; unverified/late/contradictory observation never consumes capacity |
| PreviewCustomerAcceptance | Issued revision/scopes/quantities, case component, evidence, expected generations | Read-only exact eligibility/amounts/claims/readiness; opaque short-lived actor-bound token, no cost hash exposure |
| RecordCustomerAcceptance | Same intent, verified consent, opaque reviewed token, generations, request key | Recheck under locks; append commitment, lines, all claims/allocations and receipt atomically; no automatic target |
| AssessAcceptanceReadiness | Accepted identity, requested operational path | Read-only missing execution/Finance prerequisites; no price or consent mutation and no universal Job/account |
| ReconcileLateCustomerDecision | Pending case plus historical proof and current conflict/target plan | Disabled until dedicated R9 duty, evidence policy and relevant R7 correction adapters approved/delivered |
| CorrectAcceptedBusiness | Linked original facts, correction intent/reason/consent where applicable | R7 bounded reduction accepted0132, still disabled until relevant delivered impact/correction adapters and R9; claim-release execution deferred; never PATCH original acceptance |

No independent "mark won" writer bypasses accepted facts. Commercial dispositions of unaccepted
scope use R5 availability events; accepted cancellation is not withdrawal or observation rejection.
Case merge/split after authoritative acceptance is not a routine edit: refuse until a governed
identity correction contract exists. Routine pre-acceptance reference resolution records reason/proof.

## Transaction and retry protocol

At Request and Action enforce current company, the applicable duty **and independent legitimate
Party/quotation/case read eligibility**, effective record scope and protected fields. Source read
never grants cost, margin, other-family detail or evidence attachment access. Unsupported limited
Sales scope fails closed per0106; ownership is not access. R9 gates all public endpoints and grants.

After current authentication/authorisation and scoped lookup, exact receipt/business-result replay
precedes mutable availability/token checks. Matching original intent returns original IDs reprojected
under today's field permissions. Changed payload under one key conflicts. Same consent component
under a fresh key/employee returns existing matching business result; incompatible intent conflicts.
No newly exposed historical sensitive response is cached in a privileged receipt.

Use R5 coordinator: company Sales fence, canonical decision/claim anchors in stable ID order,
exclusive groups, families, issued scopes/heads, required policy/source/approval validation barriers.
Final implementation must reconcile this order with R5 (never introduce inverse acquisition).
Create missing anchors under unique-key/coordinator protection; never lock only existing rows.
All acceptance, replacement, withdrawal, expiry-eligibility, recipient/regrouping and relevant policy
writers share fence/generation validation. Re-read capacities, consent, offered entitlement, current
permissions and declared conditions after locks. Decide time from server at final eligibility check;
a request begun before expiry but decided after expiry is late. Commit may finish later.

Atomically consume current-offer **and root-lineage** capacity, choice/exclusive claims and applicable
budgets. No auto-shrink40/70 to fit100. First winner binds; incompatible loser receives safe conflict,
never hidden competing IDs/quantities. No automatic accepted-business correction/release. Serial
allocation order follows authoritative claims, not later backsorted occurrence timestamps.

## API, Resource and UI contract

Proposed aggregate routes (not registered) under `/api/v1/customer-decisions`: POST cases,
POST `/{case}/evidence`, POST `/{case}/acceptance-preview`, POST `/{case}/acceptances`;
GET `/{case}` and GET `/api/v1/customer-acceptances/{acceptance}` plus `/readiness`.
R9 must bind duties before exposure. Sensitive reconciliation/correction routes are absent initially.
Writes require request_key, expected_generation/head and exact typed scope/evidence references;
server derives authoritative totals. Validation rejects client-assigned actor, recorded_at, budget,
accepted status and edited totals. Limits/attachment handling follow API conventions and R8 contracts.

Resources separate observation status, authoritative commitment, availability/claim-block state,
readiness and dispositions; amounts have currency/base/FX provenance. Hide cost/margin and restricted
sources/claims. Documents/evidence downloads repeat current authorisation; no public storage URLs.
Reason codes: DECISION_IDENTITY_UNRESOLVED, CONSENT_UNVERIFIED, DECISION_INTENT_CONFLICT,
STALE_EVIDENCE, OFFER_NOT_ACTIONABLE, PARTIAL_ACCEPTANCE_UNSUPPORTED, PARTIAL_PRICE_REOFFER_REQUIRED,
CLAIM_CONFLICT, ALLOCATION_UNSUPPORTED, ACCEPTANCE_RECONCILIATION_REQUIRED, DEPENDENCY_NOT_DELIVERED,
REQUEST_KEY_REUSED. Use canonical equivalent where already registered; do not duplicate vocabulary.
Follow canonical API mapping: request-layer duty denial403, cross-company/out-of-record-scope IDs404,
unsupported limited surfaces403; business/input/unsupported/stale refusals422 with actionable codes,
actual current-state database races409 where mapped. Retain canonical request-key conflict handling.
Direct Action guards remain mandatory even where existing DomainInvariantException maps to422;
do not broaden this slice into a global exception-handler rewrite. Metadata must not leak existence.

UI: shared sheets, server-scoped pickers, source/quantity and exact agreement summary, explicit
representative/verification/occurrence fields, evidence preview and confirm. Show remaining/blocked/
unoffered independently and pending operational checklist after success. Smaller unsupported purchase
leads to reoffer request; no silent price adjustment. Loading, empty, validation, stale/conflict,
forbidden, pending, replay and success states; retain safe entered intent on refresh, require re-review
and fresh consent if terms/rounded quantum changes. Keyboard/phone/light/dark coverage required.
No portal, signature delivery or claim-release control is implied by this screen contract.

## R8 evidence reconciliation — accepted2026-10-15

0135 and [delivery/evidence contract](quotation-delivery-evidence.md) distinguish acknowledgement/signature from
commercial consent. Only R6 Action establishes accepted business with exact revision/scope/terms/
authority and claims. Company/path method may require exact document/delivery; no universal email,
portal or PDF receipt prerequisite displaces legitimate verified manual/PO consent. Binary upload/
access and affected method integration still need actual secure R8 adapters and R9 grants/scope.
Late/conflicting recognition retains CA5/R7 delivered-impact gates. Architecture is not verification.

## Related

- [R7 accepted contract and actual-delivery gates](../phases/phase-4-r7-implementation-slices.md) — R7 authority is accepted0130–0132; necessary adapters remain undelivered, so affected partial-family/correction gates remain.

- [R6 implementation slices](../phases/phase-4-r6-implementation-slices.md)
- [Accepted matrix and numerical examples](../phases/phase-4-r6-customer-decisions.md)
- [Evidence and claim races](../phases/phase-4-r6-evidence-and-claims.md)
- [R7 research](../phases/phase-4-r7-handover-research.md)
- [Authorisation](../architecture/authorization.md) · [Domain integrity](../architecture/domain-integrity.md)
