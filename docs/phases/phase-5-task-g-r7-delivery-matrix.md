[← R7 batch proposal](phase-5-task-g-r7-batch-proposal.md)

# Task G — owned files/tables, ordering and implementation evidence

2026-10-27 · #52 · PROPOSAL ONLY. Names below are exact technical candidates for review, not
existing files/tables unless explicitly marked existing. Read with the
[dependency/activation proposal](phase-5-task-g-r7-batch-proposal.md). No code authority or live grant.

## Recommended first Agent A batch: G-A0 / CA0a

Three disabled non-monetary intake tables only, using logical names from accepted R6. This is a
bounded subset of CA0, not complete acceptance/claims foundation or a release-capable source.

| Proposed table | Annotated column/integrity scope |
|---|---|
| customer_decision_cases | company/id/party/intake_identity/generation and recorder/time; UNIQUE(company,id), UNIQUE(company,intake_identity), company Party/user FKs; immutable identity. Intake identity is contextual, never PO text alone or authority to purchase |
| customer_decision_components | company/id/case/intent_identity/original_scope_anchor and family; UNIQUE(company,id), UNIQUE(company,case,id), UNIQUE(company,case,intent_identity); same-company/case and actual quotation_logical_scopes(company,family,id) keys |
| customer_decision_evidence | company/id/case/predecessor, source_kind/reference/version, actual representative/authority-verification observations, factual description, occurrence instant/date/interval with precision/timezone, recorder/recorded_at; same-case predecessor FK; unique successor per non-null predecessor; append-only |

All business rows have company_id, named composite keys/FKs and indexed company/case chronology.
Evidence/components refuse UPDATE/DELETE and statement-level TRUNCATE/CASCADE. Case identity cannot
be rewritten/deleted after evidence; generation remains disabled metadata until CA1 delivers actual
head/event concurrency semantics. Do not claim CA0a alone enforces live command/claim conservation.
Observation does not prove verified consent; unknown occurrence/representative stays truthful.
No issued-revision FK pointing at a draft as if authoritative, accepted flag/amount, claim counter,
entitlement, protected-object string/blob, attachment, target ID, receipt implementation or writer.
Existing logical scope FK is context only, never proof that draft scope was officially offered.

| Exact proposed owned file | CA0a content |
|---|---|
| backend/database/migrations/2026_10_27_140000_create_customer_decision_intake_foundation.php | Three tables, company/parent/predecessor keys, bounds, row+TRUNCATE guards and data-safe down |
| backend/app/Modules/Sales/Models/CustomerDecisionCase.php | Scoped storage shape only |
| backend/app/Modules/Sales/Models/CustomerDecisionComponent.php | Scoped immutable storage shape only |
| backend/app/Modules/Sales/Models/CustomerDecisionEvidence.php | Scoped immutable storage shape and truthful occurrence casts only |
| backend/tests/Feature/Modules/Sales/CustomerDecisionIntakeFoundationTest.php | Raw SQL tenancy/predecessor/immutability/shape attacks; no authoritative acceptance fixture |
| backend/tests/Feature/Modules/Sales/CustomerDecisionIntakeMigrationTest.php | Empty cycle, populated refusal, unchanged existing Sales/Jobs/Documents guards |
| backend/app/Modules/Sales/README.md | Exact disabled subset/limits and remaining CA/HA gates |

No Sales provider change, permission constants/registration, Action/Request/route, API/UI, seeder,
Finance probe or Jobs file in CA0a. No monetary columns, so no new FinancialActivity probe yet;
the first later monetary acceptance/baseline table must register its probe in that same batch.
Any new table/path needs owner scope review rather than treating CA0 as unlimited implementation.

## Later table and file ownership — exact candidates, still GATED

Prefixes: Sales paths below are under backend/app/Modules/Sales; Jobs paths under
backend/app/Modules/Jobs. Comma-separated basenames denote only the explicitly listed .php files,
not permission to modify an entire folder. Each later code task must freeze its finite list.

| Owner/batch | Tables and required relationship evidence | Proposed file boundaries |
|---|---|---|
| Sales G-A1 issue/CA0 remainder | Existing quotation_families/revisions/logical_scopes and customer snapshot/binding tables preserved; proposed quotation_issues, quotation_issued_scopes, quotation_issue_receipts with actual issued-source keys; customer_evidence_policy_versions, customer_scope_claim_heads | Models/QuotationIssue.php, QuotationIssuedScope.php, QuotationIssueReceipt.php, CustomerEvidencePolicyVersion.php, CustomerScopeClaimHead.php; Actions/IssueQuotation.php; Support/CustomerDecisions/CustomerEvidencePolicy.php; own additive issue/claim migrations and focused tests; real issue binds existing Documents adapter |
| Sales G-A2 ordinary acceptance | customer_acceptances, customer_acceptance_lines, customer_claim_events, customer_entitlement_consumptions, customer_decision_receipts; real issued scope/revision/case/consent/policy/Party/claim/budget parent FKs | Models/CustomerAcceptance.php, CustomerAcceptanceLine.php, CustomerClaimEvent.php, CustomerEntitlementConsumption.php, CustomerDecisionReceipt.php; Actions/OpenCustomerDecisionCase.php, AppendCustomerDecisionEvidence.php, PreviewCustomerAcceptance.php, RecordCustomerAcceptance.php; Support/CustomerDecisions/CustomerDecisionOperation.php, ServiceItemAcceptanceSource.php; own migrations/tests |
| Sales G-A3 HA foundation/preparation | handover_plans, handover_allocations, handover_stages, handover_baselines, handover_readiness_evidence, handover_receipts; company+parent keys, genuine accepted-component root, immutable canonical baseline/conditions/version | Models/HandoverPlan.php, HandoverAllocation.php, HandoverStage.php, HandoverBaseline.php, HandoverReadinessEvidence.php, HandoverReceipt.php; Actions/PreviewHandover.php, PrepareHandoverPlan.php; Support/Handover/HandoverOperation.php, ServiceItemHandoverSource.php; own migrations/tests |
| Sales G-A4 release/verifier | Same HA tables; exact-intent request receipt separate from stage business identity; generation/root cap/one-result integrity, no Sales FK into Jobs | Actions/ReleaseHandoverComponent.php; Support/Handover/ReleasedServiceSourceVerifier.php, CanonicalReleasedService.php, ReleasedServiceVerificationResult.php, HandoverTransactionContext.php; Models/HandoverReceipt.php as required; own provider wiring/tests, no Jobs writes |
| Jobs G-J1 integration schema | Existing service_jobs/job_handover_origins/job_baselines/job_events; origin gains real Sales acceptance/component/allocation/stage/source-baseline FKs; job_baseline_components and job_activities with same-Job keys/default uniqueness/existence guard | Own additive integration migration; Models/JobHandoverOrigin.php, JobBaseline.php, JobBaselineComponent.php, JobActivity.php; Support/ReleasedServiceInput.php and ReleasedServiceValidator.php evolve from existing shapes only after actual Sales keys; tests for all company/parent relations |
| Jobs G-J1 receiver | Same owned target tables only; origin result is durable receipt, not another caller-key ledger | Actions/ReceiveReleasedService.php; Support/ReceiveReleasedServiceResult.php, Numbering/ServiceJobNumbering.php; JobsServiceProvider.php for reviewed internal wiring; actual JobAccess/manifest from separately authorised access work; no Request/receiver route |
| Shared/access and optional adapters | Existing registry/scope/Numbering/Approvals/Documents; no new global engine assumed | Only separately assigned owner tasks; Sales/Jobs cannot edit shared scope/mandates/registry or source modules unilaterally. Source-key additions are Sales-owned; downstream FKs are Jobs-owned |

These QL issue names are proposed, not a claim of an existing QL3 physical contract. Sales reviews
actual schema/source semantics before code; Jobs references their real committed keys, not guesses.
Exact condition columns/storage also require joint source review; a version string without canonical
conditions is not enough. No independent Job copy of confidential commercial money/cost baseline.
Sales may retain private canonical commercial evidence; Jobs receives permitted operational fields.

Later Request/Resource/OpenAPI/client/frontend files are excluded from these disabled schema/paired
internal contract proposals. A separately authorised public delivery task supplies its exact files,
guard ratchets, safe profiles and generated client together. No generic scaffold route or whole-folder
authorisation. Existing quotation/CRM paths and created-by attribution remain untouched by CA0a;
unrelated QA/ownership design is not inferred into this planner's assignment.

## Migration ordering, collision and rollback plan

1. Fresh develop/fetched-ref inspection found newest migration
   2026_10_22_140000_create_tax_party_profiles. Existing Jobs foundation is 2026_10_22_090000;
   pending/earlier Documents truncate guard 2026_10_22_100000 is already on this develop.
   Task D proposes a separate 2026_10_26_090000 lifecycle foundation; it is not implemented here.
2. **Candidate only:** 2026_10_27_140000_create_customer_decision_intake_foundation for CA0a,
   after those known names. No migration file created or global reservation claimed. Coordinator
   confirms all owners' pending/unpushed reservations and publishes the slot before code authority;
   implementer re-fetches/rechecks. Choose a later stamp if any pending work orders after/conflicts.
3. Remaining issue/CA tables follow their actual prerequisites; install real issued-scope/claim
   keys before acceptance FKs; install accepted component keys before HA FKs. Each batch reserves
   a later unused timestamp at assignment; no speculative creation of missing upstream tables.
4. Jobs source-FK/activity/component migration follows all actual Sales referenced keys and any
   applicable F1/access integration. Add source parent-consistent keys before Jobs FKs. Do not
   alter old migrations or put nullable/unverified source strings in live rows as a bypass.
5. Existing SP0b-looking fixture/history requires preflight before attaching real source FKs.
   Unsupported legacy rows stop rollout for separately reviewed reconciliation, never fabricated
   consent/deleted evidence. No circular source/target FKs. Populated new evidence makes down
   refuse before any object drops; empty down reverses only owned objects in FK order.

Company-scoped money uses decimal(19,4), pinned transaction/base/currency/FX and FinancialActivity;
source quantities preserve their real precision/conversion. Cross-row sums/caps require constrained
heads and coordinated/deferred validation, not CHECK claiming to scan other rows. Append-only
row AND statement TRUNCATE guards are versioned/owned, not borrowed destructive Documents functions.
Upgrades, down refusal and races run on disposable private databases with actual COMMIT timing.

## Required future tests and review evidence — not executed here

| ID | Required result |
|---|---|
| RG01 | CA0a wrong-company/parent/predecessor/Party/scope raw writes refuse; ambiguous PO/intake remains observation, never acceptance; original fields immutable |
| RG02 | New evidence UPDATE/DELETE/TRUNCATE/CASCADE refuse; empty migration cycle succeeds, populated down refuses before changes; unrelated guards/rows preserved |
| RG03 | Actual QL issue freezes terms/entitlements/conditions/financial/control provenance; master changes do not alter original; no fake ISSUED fixture used as end-to-end proof |
| RG04 | Full unchanged ordinary consent creates acceptance/claims once, no target; observation/acknowledgement/document/draft or unsupported source refuses authoritative acceptance |
| RG05 | Two sessions compete for family/choice/component/root with fresh keys/actors → one conserved result or refusal; unsupported partial/replacement/late path cannot bypass claims |
| RG06 | Accepted-but-not-ready plan remains pending, no Job/reservation; canonical missing/denied source dependency is unknown, not NONE |
| RG07 | EVERY Jobs→Sales source FK is company-scoped plus required parent binding; raw mismatches fail even when individual IDs exist; no Sales→Jobs FK |
| RG08 | Same stage/purpose with changed business UUID cannot create second target; same/fresh-key exact replay resolves original permitted result/number |
| RG09 | Real outer transaction fails after target, origin, baseline, default activity, event or caller receipt → all source/target effects roll back on same runtime backend connection |
| RG10 | Verifier absent/non-positive, input/canonical mismatch, different connection/no coordinator/false lock token → zero effects, no fallback conditions/measure |
| RG11 | Release races approval/hold/reduction/replacement/import/worker and absent-anchor insertion → valid serial outcome or stale refusal under actual reviewed fences |
| RG12 | Independent Sales release AND Jobs receive duty/ALL scopes; revoked/limited replay and cross-company IDs safely deny; no administrator/mandate expansion |
| RG13 | Numbering rollback follows genuine GAPLESS/ALLOW_GAPS policy; replay allocates no number; no code/series before source lock order |
| RG14 | SERVICE_ITEM fixed deliverable keeps operational measure/quantity absent, genuine default activity and pinned condition basis; no hours/stock/Assembly fake dependency |
| RG15 | Material/Purchasing-required branch absent or mandatory correction failure → affected path disabled/whole atomic refusal; proven excluded paths can proceed, not blanket adapters |
| RG16 | Safe counts/history/receipt/projection/file context has no cost/hash/hidden-source leak; later lifecycle/closeout sign-off not claimed by receive tests |

CA0a runs its focused Sales storage/migration tests, company FK/unique and permission/write/read/
scope ratchets proving no new entry, Pint/PHPStan and isolated migration cycle. Later Action/HTTP/
Resource/client/UI changes require the full applicable pre-push matrix and real source fixture-
independent journeys; CI/coordinator owns full backend regression and integrated browser proof.
Reports name exact dependency/source/head SHAs, independent-session barriers and injection points.
Mock verifier/target tests alone cannot satisfy RG03–16. Each batch needs Sales/Jobs/shared security
review, affected source-owner approval and human owner exact-commit authority before activation.

## Related

- [Dependency/activation proposal](phase-5-task-g-r7-batch-proposal.md)
- [Task A delivery tests](phase-5-r7-job-receiver-verification.md) · [Task E](phase-5-task-e-source-integration.md)
- [CA contract](../domain/sales-customer-decisions.md) · [HA contract](../domain/sales-operational-handover.md)
- [Pre-push checks](../architecture/pre-push-checks.md) · [Coordination](../architecture/agent-coordination.md)
