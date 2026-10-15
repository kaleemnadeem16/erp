[← Domain index](README.md)

# Sales authority, limits and execution contract

**2026-10-15 · ACCEPTED0138/0139; SPECIFIED, not new application delivery/verification.**
The [canonical duty matrix](sales-duty-matrix.md) is the vocabulary/operation/scope/field/holder source.
No real grants are authorised. Reuse RecordScope, ApprovalSubject/ApprovalReviewRegistry and module
Actions; exact technical implementation remains code-owner responsibility under separate authority.

## Evaluation order and protected operations

1. Current actor/company and appropriate exact operation duty; resolve that duty's effective scope.
2. Legitimate subject/Party/source/target eligibility and minimum workflow projection. No ownership,
   hierarchy or unrelated broad capability inference; sensitive duty cannot confer independent read.
3. Current source/state/generation/policy, supported adapter and applicable evidence. Check exact
   completed receipt with current access before mutable readiness checks; changed intent conflicts.
4. Evaluate specific input and aggregate final effect against governed reference, all independent
   constraints, current approval/authority and stored-actor separation. Safe blind projection only.
5. Under canonical owner locks revalidate source/authority/approval fingerprints and append business
   event, audit and exact receipt atomically. No source mutation before actual guard outcome.

Unsupported restricted Sales mode refuses consistently across list/detail/count/history/export/
lookup/Request/Action/UI; never a browser-only “my deals” filter. Future scoped mode integrates all
surfaces before exposure. Existing CRM duties/0106/0114 and approved case/target/warehouse scope
remain independent. Site/warehouse controls apply only to paths actually requiring them.

## Versioned authority persistence — logical pseudo-DDL

```
sales_authority_policy_versions
  company_id, id, version, status/effective interval, priority, matching_facts_schema
  reference_rule, input_basis/limit_rules, aggregate_basis/limit_rules, currency_conversion_policy
sales_authority_evaluations
  company_id, id, subject/type/generation, policy_version, actor/principal/delegation_evidence
  reference_fingerprint, specific_input, aggregate_final_reduction, exact_basis/quantity evidence
  transaction_currency/amounts, pinned_base_rate/date/source/base_amounts, protected_result_evidence
  result, reason_codes, approval_subject/fingerprint nullable, evaluated_at
sales_authority_events / operation receipts
  company_id, subject/operation identity, actor/service, occurrence/recording evidence
  before/after reference, authority version, reason, request_key/intent_digest/result
```

Composite company/source FKs; immutable used versions/evaluations/events, decimal(19,4)/pinned base
amounts on money rows and FinancialActivity registration. Existing shared approval_authorities,
requests/decisions/delegations remain authoritative for approval eligibility—no copied second
approval engine/ceiling ledger. Evaluations record relied evidence, not evergreen authorisation.

## Amount and discount rules

Select highest-priority applicable versioned authority rule set; equal-priority ambiguity refuses.
Do not choose cheapest/loosest policy or sum grants. Evaluate every independent mandatory control
after policy selection: specific discretionary percent/fixed-unit/fixed-line input, aggregate final
reduction against governed economic reference, final-net floor/margin/contract/material constraints.
Reference captures pre-discretionary governed basis with original automatic benefit comparison;
editing manual base, splitting lines or new revision cannot reset that authority reference.

Example reference100.0000, automatic10% gives90.0000; replacing slot with discretionary20% gives
80.0000. Specific20% and aggregate20% are checked; not just incremental10%. Retain explicit confirmation
if replacing larger automatic benefit increases price. Negative net/invalid percent/incompatible
measure refuses, no clamping. Percentage and absolute ceilings both apply where configured. Fixed
per-priced-unit uses declared denominator; per-line once, linked benefit identity cannot duplicate.

Company-base absolute-limit comparison freezes rate/date/source/actual-age/route and base amount
under the applicable accepted R4 policy. No future rate, zero/one missing substitution or unsupported
route. Missing required reference/FX/cost refuses; real evidenced zero is not missing. Margin at
zero selling price remains undefined. Exact rounding/conservation follows FR0/FR4 once delivered.
Company actual numeric limits are governed configuration, not universal defaults. Actor/principal/
delegate ceilings are not added;0070 delegation uses principal eligibility without extra authority.

Above-limit review uses supported shared approval subject and applicable exact capability/current
ceiling. No eligible policy/approver means refusal. Keep NONE/POLICY/REQUIRED semantics; matching policy
and configured maker/checker enforced from stored actor/principal/decider facts. Same-total source/
terms/reference/authority changes invalidate review. Consuming Action revalidates exact fingerprint
at issue/release; approval itself does not transition business state. No general risk/fixed-contract
departure, invented complete cost or sensitive threshold disclosure from a review result.

## Action/Request/API/UI and audit

Specified Actions: ResolveSalesAuthority, EvaluateQuotationAdjustment, SubmitQuotationReview,
ConsumeQuotationApproval through existing engine, plus each owning PR/QL/CA/HA command. Requests
carry actor context, exact input/source/generation/policy/fingerprint/key; no client-supplied pass.
Resources expose safe result/reason and allowed next action, omit confidential operands/bands/
reasons unless independent capability. UI displays specific+aggregate effect, authority refusal or
review path, never asks user to bypass an unknown result. Policy settings preserve used versions.

HTTP Request and Action both guard; import/console/worker uses same owning Action with explicit
identity. Preserve human initiator; service uses current bounded company/subject/purpose/intent
authority, never employee impersonation. Committed original archival work may use independently
authorised system workflow; revoked human privileges cannot authorise a new send/acceptance.
External in-flight outcomes remain truthful after revocation; current result projection on retries.
Append audit occurrence+precision/timezone and server recording time, source hashes/versions, original
result IDs and reason where governed. No secret/provider credentials/protected operands in ordinary logs.

## Races/refusals and rollout boundary

Coordinate Sales source/claims roots before authority/approval/target heads; reconcile actual existing
source lock orders before enabling cross-module writer. Concurrent policy/limit/grant/source change
cannot let a cached preview pass issue. Unique anchors plus locks cover absent rows. Denied profile,
unsupported scope/adapter, ambiguous policy, stale evidence, unknown basis and key conflict return
stable safe reasons. Concurrency tests use separate real transactions, not only HTTP preflight.

Schema and approved names do not enable public routes. [Rollout specification](../phases/phase-4-r9-permission-rollout.md)
requires explicit registration/holder inventory/grant proof and staging checks. Preserve CRM/C2
compatibility; don't retrofit unrelated surfaces or change existing maintenance/read exceptions.

## Related

- [Duty matrix](sales-duty-matrix.md) · [Reporting/forecast](sales-reporting-and-forecast.md)
- [0139](../decisions/0139-bind-sales-authority-limits-approvals-and-execution-to-exact-evidence.md)
- [Approvals](approvals.md) · [R9 slices](../phases/phase-4-r9-implementation-slices.md)
