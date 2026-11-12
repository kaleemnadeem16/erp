[← Phases](README.md)

# Phase 4 - optional acceptance-verification policy (design, D4 reconciliation)

**Status: PROPOSED. Design only. Needs separate owner authorisation before any planning is scheduled or code written.** Nothing here is implemented, activated or granted.
Source: owner decision D4 in [ADR 0187](../decisions/0187-record-phase-4-closure-decisions-d1-d16.md).

## 1. What exists today

- `CustomerDecisionEvidence` rows carry `recorder_id` (who appended the customer message/evidence). `customer_acceptances` rows carry `actor_id` (who registered acceptance) and the pinned
  `customer_evidence_policy_versions` version (fixed initial policy v1, no editor or activation API).
- `RecordCustomerAcceptance` / `AcceptanceEligibility` check, inside the transaction: fresh ACTIVE member with `sales.customer-decision.accept` (via `DecisionActor`), independent
  `sales.customer-decision.view` and quotation-family view; the latest written evidence with a named representative and a verified-authority basis; offer availability and expiry; claim and
  generation freshness. See [full acceptance](../domain/sales-full-acceptance.md).
- **The server does not compare the evidence recorder with the accepting actor.** The same authorised employee may record the evidence and register acceptance, and so may the issuer.
  This was a provisional default ("overlap is permitted but never forced"; [brief](phase-4-customer-acceptance-and-handover-design-brief.md#assumed-defaults-slice-2-owner-review-pending)).
  The two duties (`...evidence.record`, `...accept`) are separate, as ADR 0127 requires ("separately authorised duties for recording ... "), but separate duties do not force separate people.
- Both duties are STAGED and ungranted; the whole feature is behind the off-in-production quotation UI gate.

## 2. D4 restated

Verified consent plus immutable evidence are always required. Acceptance authority is independent from the permission to record evidence. By default the same authorised employee may record
and register genuine written customer acceptance. A company may optionally require independent acceptance verification (default OFF, server-enforced when ON). No configuration may bypass
mandatory maker/checker for internal approvals. This matches today's behaviour when OFF; the policy adds a stricter mode and does not change the default.

## 3. Conflict with ADR 0185 and proposed reconciliation

[ADR 0185](../decisions/0185-configure-company-business-values-and-fix-technical-invariants.md) (still proposed) lists "separation of duties (maker/checker)" among invariants that "no setting,
role or company size can switch them off" and defines a business value as one where "no one's security depends on the choice". Read literally, an on/off setting for recorder-versus-acceptor
separation could be argued to contradict it. The owner's D4 distinguishes two things the 0185 wording does not:

1. **Internal approval maker/checker** (quotation concession, Purchasing, Estimating, discount authority; ADR 0070/0158/0163/0182): the checker approves a colleague's internal act. Mandatory, never configurable.
2. **Recording a customer's external, written acceptance**: the employee transcribes a fact that originates outside the company, protected by immutable evidence, verified authority, named
   representative and audit identity. Whether a second employee must verify is a company risk preference (fraud exposure versus staffing), so it is a business value.

Proposed reconciliation text for 0185 (to be applied by a new superseding or amending ADR, not by editing accepted text; 0185 is proposed so the owner may instead amend it in place):

> Separation of duties (maker/checker) as an invariant means separation applied to **internal approvals and authorisations**. Verification of a customer's externally originated written
> acceptance is not an internal approval. A company may configure whether independent verification is required; verified consent and immutable evidence are required in every configuration.

Also note: the 0185 test (A value is a business value when no one's security depends on the choice) is only arguable here; the doc therefore records the classification as an explicit owner decision (D4), not a derivation.

## 4. Proposed policy

| Aspect | Proposal |
|---|---|
| Name | `customer_acceptance_independent_verification` (setting); code `INDEPENDENT_VERIFICATION_REQUIRED`; values `OFF` (default), `ON` |
| Default | OFF; absence of a row means OFF; a company never changes silently when the code is deployed |
| ON rule | The accepting actor must not be the `recorder_id` of the evidence row being accepted (and, proposal for owner decision, not the person who issued the quotation is NOT required). Checked server-side inside the existing transaction after the locks, on both Preview and Record; refusal code `ACCEPTANCE_VERIFICATION_REQUIRED` (422), non-disclosing about who recorded |
| Who may change | A new dedicated, sensitive, STAGED duty (proposed `sales.customer-decision.policy.manage`); not implied by `accept` or the evidence duty; the changer is audited; changing a policy is itself not allowed to be approved by the same person who requested it where a second Admin exists is OUT of scope (single-actor change, fully audited) |
| Versioning | Append-only versions: version, value, `effective_from` (company local date/time, UTC stored), actor, reason, recorded_at; no UPDATE/DELETE. Turning ON may take effect on the next transaction; turning OFF takes effect at its effective instant |
| Evidence on each acceptance | The acceptance pins the policy version in force (extending the existing pinned `policy_version_id` approach or a new nullable column on a forward migration), and records recorder and verifier identity, so the audit shows whether verification was required when it was recorded |
| Already-recorded acceptances | Untouched; turning ON is never retroactive and never invalidates history |
| Interaction with maker/checker | Independent of and additive to every internal approval rule. The setting cannot appear in, weaken or substitute for the Approvals engine, `sales.quotation.approve` eligibility, discount authority or any internal decision. A test must prove that OFF does not alter any approval refusal |
| Interaction with 0185 | Classified as a business value under the reconciliation in section 3; the setting follows 0185 governance: versioned, effective-dated, strict validation, settings-UI standard, dedicated permission |
| Disable semantics | Retains history; restores no permission; per the 0163/0185 pattern |
| Tenancy | Company-scoped table, company-composite FKs, tenant-isolation tests |

Open owner choices: (a) whether ON also forbids the quotation issuer from accepting (this design proposes not); (b) whether a limited emergency override exists (this design proposes none, to stay
default-deny); (c) setting UI placement.

## 5. Smallest implementation slices (none authorised)

1. **S0 - Wording only:** record the section 3 reconciliation in an ADR (docs).
2. **S1 - Storage:** additive migration for the versioned policy table (empty, immutable, guards), plus nullable pinned version and recorder/verifier fields on acceptance; fresh and already-migrated database proof (see D5 approach). Defaults OFF; no behaviour change.
3. **S2 - Server rule:** read the effective version inside `AcceptanceEligibility` (Preview and Record), refuse when ON and actor equals the evidence recorder; replay rules unchanged (an existing identical acceptance still replays first, current authority still rechecked).
4. **S3 - Change Action and duty:** `SetAcceptanceVerificationPolicy` with the new STAGED duty, fence, idempotency, audit; no grants, no activation.
5. **S4 - UI:** settings screen and a clear refusal message on the accept panel; 320/390px, light/dark, keyboard per D13.

## 6. Tests required

- **Default OFF:** same employee records evidence then accepts: succeeds exactly as today (regression); no policy row means OFF.
- **ON:** same employee refused on Preview and Record with the stable code; a different authorised employee succeeds; refusal is non-disclosing; no row, claim, receipt or generation changes on refusal.
- **Authority:** suspended/revoked actor mid-transaction, limited decision scope, foreign company and hidden source remain refused; replay (same key, fresh key, another authorised person) still repeats current authority.
- **Race:** concurrent policy change versus acceptance (serialised under the existing fence); concurrent double acceptance unchanged.
- **Versioning:** effective-date boundaries in the company time zone; append-only and no retroactivity; acceptance pins the correct version; turning OFF restores nothing.
- **Isolation of invariants:** policy OFF or ON never changes any internal approval, discount-authority or maker/checker behaviour (explicit negative tests).
- **Permission:** the change duty is independent; `accept`/evidence holders cannot change it; manifest and STAGED coverage tests; no grant recipe added.
- **Migration:** fresh and already-migrated apply, rollback, re-apply; immutability guards.
- **UI:** gate, refusal wording, keyboard, 320/390px, light/dark, screen-reader basics for the accept panel.

## 7. Not decided here

Activation, holder recipe and production rollout (D15/D16); partial-quantity acceptance, corrections and handover release (other slices and Phase 5).
